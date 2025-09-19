<?php
// export_all.php - bulk exporter with per-file PDF conversion, bank filter and naming conventions
// Save as UTF-8 WITHOUT BOM

declare(strict_types=1);
ini_set('display_errors','0');
ini_set('display_startup_errors','0');
ini_set('log_errors','1');
error_reporting(E_ALL);

while (ob_get_level()) ob_end_clean();
ob_start();

register_shutdown_function(function(){
    $buf = ob_get_contents();
    if ($buf !== '') error_log("export_all: unexpected output captured: ".substr($buf,0,200));
    @ob_end_clean();
});

set_exception_handler(function($e){
    error_log("export_all: uncaught exception: ".$e->getMessage());
    while(ob_get_level()) @ob_end_clean();
    http_response_code(500);
    echo "Internal Server Error";
    exit;
});

set_time_limit(0);
ini_set('memory_limit','1024M');
date_default_timezone_set('Asia/Kolkata');

$debug = (isset($_GET['debug']) && $_GET['debug']=='1');
// default: convert image files to pdf. Use pdf=0 to disable.
$pdfMode = ( !isset($_GET['pdf']) ) ? true : ( (string)$_GET['pdf'] === '1' );

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 0;
$bundleMode = $_GET['bundle'] ?? 'zip';
$bank = $_GET['bank'] ?? null; // optional bank filter human-readable

$DOC_KEYS = [
  'aadhar_file'=>'Aadhaar','pan_file'=>'PAN','voter_file'=>'VoterID',
  'ration_file'=>'RationCard','consent_file'=>'Consent','gps_selfie_file'=>'GPS_Selfie',
  'police_verification_file'=>'PoliceVerification','permanent_address_proof_file'=>'PermanentAddressProof',
  'parent_aadhar_file'=>'ParentAadhaar','nseit_cert_file'=>'NSEIT_Cert','self_declaration_file'=>'SelfDeclaration',
  'non_disclosure_file'=>'NDA','edu_10th_file'=>'10th','edu_12th_file'=>'12th','edu_college_file'=>'CollegeCert'
];

// ---------- Config: allowed base directories for file resolution (adjust to your deploy)
$ALLOWED_BASES = [
    realpath(__DIR__ . '/uploads') ?: (__DIR__ . '/uploads'),
    realpath($_SERVER['DOCUMENT_ROOT'].'/uploads') ?: ($_SERVER['DOCUMENT_ROOT'].'/uploads'),
    // explicit path for your Windows XAMPP deployment:
    'C:/xampp/htdocs/QAIT/uploads'
];

function safeName($s){ $s = trim((string)$s); $s = preg_replace('/[^\w\s\-\.]/u','',$s); $s = preg_replace('/\s+/','_',$s); return substr($s,0,180); }
function join_paths(){ $a=[]; foreach(func_get_args() as $arg){ if($arg!=='') $a[]=rtrim($arg,'/\\'); } return join(DIRECTORY_SEPARATOR,$a); }
function rrmdir($d){ if(!is_dir($d)) return; foreach(scandir($d) as $f){ if($f==='.'||$f==='..') continue; $p=$d.DIRECTORY_SEPARATOR.$f; if(is_dir($p)) rrmdir($p); else @unlink($p);} @rmdir($d); }

function resolveSrc($src){
    $src = (string)$src;
    if ($src === '') return false;
    // candidate as-is or relative to doc root
    $cand = file_exists($src) ? $src : ($_SERVER['DOCUMENT_ROOT'].DIRECTORY_SEPARATOR.ltrim($src,'/\\'));
    if (!$cand || !file_exists($cand)) return false;
    $real = realpath($cand);
    if (!$real) return false;
    foreach ($GLOBALS['ALLOWED_BASES'] as $base){
        $b = realpath($base);
        if ($b && strpos($real, $b) === 0) return $real;
        // also allow direct literal matches (for explicit path strings)
        if ($base === $real || strpos($real, $base) === 0) return $real;
    }
    error_log("resolveSrc: refused path outside allowed bases: {$real}");
    return false;
}

function imagick_supports_pdf(): bool {
    if (!extension_loaded('imagick') || !class_exists('Imagick')) return false;
    try {
        $formats = Imagick::queryFormats();
        $hasPdf = is_array($formats) && (in_array('PDF', $formats) || in_array('PDF', array_map('strtoupper',$formats)));
        return $hasPdf;
    } catch(Exception $e){ error_log("imagick_supports_pdf: ".$e->getMessage()); return false; }
}

$mysqli = new mysqli("localhost","root","","qmit_system");
if ($mysqli->connect_error) {
    error_log("export_all: DB connect error: ".$mysqli->connect_error);
    if ($debug){ header('Content-Type: application/json'); echo json_encode(['error'=>'DB connect']); @ob_end_clean(); exit; }
    http_response_code(500); exit;
}

// helper to detect column presence (for bank filter)
function column_exists($mysqli, $table, $col){
    $tbl = $mysqli->real_escape_string($table);
    $c = $mysqli->real_escape_string($col);
    $res = $mysqli->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$c}'");
    if ($res === false) return false;
    return (bool)$res->fetch_assoc();
}

// filters
$branch = $_GET['branch'] ?? null;
$status = $_GET['status'] ?? null;
$from   = $_GET['from'] ?? null;
$to     = $_GET['to'] ?? null;

$where=[]; $params=[]; $types='';
if ($branch){ $where[]="branch_name = ?"; $params[]=$branch; $types.='s'; }
if ($status){ $where[]="status = ?"; $params[]=$status; $types.='s'; }
if ($from){ $where[]="created_at >= ?"; $params[]=$from . " 00:00:00"; $types.='s'; }
if ($to){ $where[]="created_at <= ?"; $params[]=$to . " 23:59:59"; $types.='s'; }

// bank filter mapping (try bank_name then bank)
if ($bank) {
    if (column_exists($mysqli,'operatordoc','bank_name')) {
        $where[] = "bank_name = ?"; $params[] = $bank; $types .= 's';
    } elseif (column_exists($mysqli,'operatordoc','bank')) {
        $where[] = "bank = ?"; $params[] = $bank; $types .= 's';
    } else {
        error_log("export_all: bank filter requested but no bank column found. Ignoring bank filter.");
    }
}

// handle doc query param
$docParam = $_GET['doc'] ?? null;
$docMode = 'all';
if ($docParam !== null && strtolower($docParam) !== 'all') {
    $norm = preg_replace('/[^a-z0-9]/','',strtolower((string)$docParam));
    $lookup = [];
    foreach ($DOC_KEYS as $k=>$lab){
        $lookup[preg_replace('/[^a-z0-9]/','',strtolower($k))] = $k;
        $lookup[preg_replace('/[^a-z0-9]/','',strtolower($lab))] = $k;
    }
    if (isset($lookup[$norm])) $docMode = $lookup[$norm];
    else {
        if ($debug){ while(ob_get_level()) ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['error'=>'Invalid doc key/label','valid_keys'=>array_merge(array_keys($DOC_KEYS), array_values($DOC_KEYS))], JSON_PRETTY_PRINT); rrmdir(sys_get_temp_dir()); exit; }
        http_response_code(400); error_log("export_all: invalid doc param {$docParam}"); exit;
    }
}

$sql = "SELECT * FROM operatordoc" . (!empty($where) ? " WHERE " . implode(' AND ', $where) : "") . " ORDER BY id ASC" . ($limit>0 ? " LIMIT ".$limit : "");
$stmt = $mysqli->prepare($sql);
if ($stmt === false) {
    error_log("export_all: db prepare failed: ".$mysqli->error);
    if ($debug){ header('Content-Type: application/json'); echo json_encode(['error'=>'DB prepare','sql'=>$sql]); @ob_end_clean(); exit; }
    http_response_code(500); exit;
}
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($rows)){
    if ($debug){ while(ob_get_level()) ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['collected'=>[]], JSON_PRETTY_PRINT); @ob_end_clean(); exit; }
    http_response_code(404); exit;
}

$tmpBase = sys_get_temp_dir();
$workDir = join_paths($tmpBase, uniqid('export_all_', true));
@mkdir($workDir, 0700, true);

$collectedForZip = [];
$imageExts = ['jpg','jpeg','png','gif','tiff','tif','bmp','webp']; $pdfExts = ['pdf'];

$pdfCapable = imagick_supports_pdf();
if ($pdfMode && !$pdfCapable){
    error_log("export_all: PDF conversion requested but Imagick/PDF support missing. Falling back to originals.");
    $pdfMode = false;
}

$bankSafe = $bank ? safeName($bank) : '';

// iterate rows
foreach ($rows as $row) {
    $id=(int)$row['id'];
    $opName=safeName($row['operator_full_name'] ?? "operator_{$id}");
    $branchName=safeName($row['branch_name'] ?? 'branch');

    if ($docMode !== 'all') {
        // single-doc export: group all operators' single doc into one folder: [BankSafe_]Label
        $label = $DOC_KEYS[$docMode];
        $groupFolder = ($bankSafe ? ($bankSafe . '_') : '') . $label;
        $groupDir = join_paths($workDir, $groupFolder);
        @mkdir($groupDir,0700,true);

        $val = $row[$docMode] ?? '';
        if (!$val) continue;
        $src = resolveSrc($val);
        if (!$src || !is_readable($src)) { error_log("export_all: unresolved/unreadable for op {$id} doc {$docMode}"); continue; }

        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        $baseOutName = "{$opName}_{$branchName}_{$label}";
        $tempOut = join_paths($groupDir, $baseOutName . '.' . ( ($ext==='txt') ? 'xls' : $ext ));
        if (!@copy($src, $tempOut)) { error_log("export_all: failed copy {$src} to {$tempOut}"); continue; }

        if ($pdfMode && in_array($ext, $imageExts) && $pdfCapable){
            try {
                $im = new Imagick($tempOut);
                if (method_exists($im,'autoOrient')) $im->autoOrient();
                $im->setImageColorspace(Imagick::COLORSPACE_RGB);
                $im->setImageFormat('pdf');
                $pdfOut = join_paths($groupDir, $baseOutName . '.pdf');
                $im->writeImages($pdfOut, true);
                $im->clear(); $im->destroy();
                @unlink($tempOut);
                $collectedForZip[] = ['path'=>$pdfOut,'name'=>$groupFolder.'/'.basename($pdfOut)];
                continue;
            } catch (Exception $e){
                error_log("export_all: imagick convert failed for {$tempOut}: ".$e->getMessage());
            }
        }

        $collectedForZip[] = ['path'=>$tempOut,'name'=>$groupFolder.'/'.basename($tempOut)];
        continue;
    }

    // docMode == 'all'
    $folder = "{$opName}_{$branchName}_AllDocuments";
    $opDir = join_paths($workDir, $folder);
    @mkdir($opDir,0700,true);

    foreach (array_keys($DOC_KEYS) as $key) {
        $label = $DOC_KEYS[$key];
        $val = $row[$key] ?? '';
        if (!$val) continue;
        $src = resolveSrc($val);
        if (!$src || !is_readable($src)) { error_log("export_all: unresolved/unreadable for op {$id} key {$key}"); continue; }

        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        $baseOutName = "{$opName}_{$branchName}_{$label}";
        $tempOut = join_paths($opDir, $baseOutName . '.' . ( ($ext==='txt') ? 'xls' : $ext ));
        if (!@copy($src, $tempOut)) { error_log("export_all: failed copy {$src} to {$tempOut}"); continue; }

        if ($pdfMode && in_array($ext, $imageExts) && $pdfCapable) {
            try {
                $im = new Imagick($tempOut);
                if (method_exists($im,'autoOrient')) $im->autoOrient();
                $im->setImageColorspace(Imagick::COLORSPACE_RGB);
                $im->setImageFormat('pdf');
                $pdfOut = join_paths($opDir, $baseOutName . '.pdf');
                $im->writeImages($pdfOut, true);
                $im->clear(); $im->destroy();
                @unlink($tempOut);
                $collectedForZip[] = ['path'=>$pdfOut,'name'=>$folder.'/'.basename($pdfOut)];
                continue;
            } catch (Exception $e){
                error_log("export_all: imagick convert failed for {$tempOut}: ".$e->getMessage());
            }
        }

        $collectedForZip[] = ['path'=>$tempOut,'name'=>$folder.'/'.basename($tempOut)];
    }
}

if (empty($collectedForZip)){ rrmdir($workDir); http_response_code(404); @ob_end_clean(); echo "No files found"; exit; }

if ($debug){
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['collected'=>array_map(function($f){ return ['name'=>$f['name'],'size'=>@filesize($f['path'])]; }, $collectedForZip)], JSON_PRETTY_PRINT);
    rrmdir($workDir); exit;
}

if (count($collectedForZip)===1 && $bundleMode!=='singlezip'){
    $f = $collectedForZip[0];
    clearstatcache(true,$f['path']);
    if (!file_exists($f['path'])){ rrmdir($workDir); http_response_code(500); @ob_end_clean(); exit; }
    while (ob_get_level()) ob_end_clean();
    header('X-Content-Type-Options: nosniff');
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $f['path']) ?: 'application/octet-stream';
    finfo_close($finfo);
    header('Content-Type: '.$mime);
    header('Content-Length: '.filesize($f['path']));
    header('Content-Disposition: attachment; filename="'.basename($f['path']).'"; filename*=UTF-8\'\''.rawurlencode(basename($f['path'])));
    header('Content-Transfer-Encoding: binary');
    $fp = fopen($f['path'],'rb'); fpassthru($fp); fclose($fp);
    rrmdir($workDir); exit;
}

if (!class_exists('ZipArchive')){ rrmdir($workDir); http_response_code(500); @ob_end_clean(); echo "Enable PHP Zip extension."; exit; }

$zipSuffix = date('Ymd_His');
$zipBaseName = ($bankSafe ? ($bankSafe.'_') : '') . 'Operators_Export_' . $zipSuffix;
$zipPath = join_paths(sys_get_temp_dir(), uniqid('export_all_').'.zip');
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE)!==TRUE){ rrmdir($workDir); http_response_code(500); @ob_end_clean(); echo "Could not create zip"; exit; }
foreach ($collectedForZip as $f) if (file_exists($f['path'])) $zip->addFile($f['path'], $f['name']);
$zip->close();

$za = new ZipArchive();
if ($za->open($zipPath) !== TRUE){
    error_log("export_all: created zip invalid: {$zipPath}");
    rrmdir($workDir);
    http_response_code(500);
    @ob_end_clean();
    echo "Server error: invalid zip created. Check server logs.";
    exit;
}
$za->close();

clearstatcache(true,$zipPath);
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/zip');
header('Content-Description: File Transfer');
header('Content-Disposition: attachment; filename="'.$zipBaseName.'.zip"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: '.filesize($zipPath));
$fh = fopen($zipPath,'rb'); fpassthru($fh); fclose($fh);
@unlink($zipPath);
rrmdir($workDir);
exit;
