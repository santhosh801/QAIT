<?php
// export_all.php  (robust, corrected: ini_set values are strings)
// - Must be saved as UTF-8 WITHOUT BOM
// - Use ?debug=1 to see JSON of collected files (safe for verification)

declare(strict_types=1);

// --- Basic safety: capture any stray output and never send it during binary streaming
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// capture all output (so nothing is sent to browser accidentally)
while (ob_get_level()) ob_end_clean();
ob_start();

// shutdown handler to ensure cleanup
register_shutdown_function(function(){
    $buf = ob_get_contents();
    if ($buf !== '') {
        // log any stray output (trim to avoid huge logs)
        error_log("export_all: unexpected output captured before streaming: " . substr($buf,0,200));
    }
    @ob_end_clean();
});

set_exception_handler(function($e){
    error_log("export_all: uncaught exception: ".$e->getMessage());
    while (ob_get_level()) @ob_end_clean();
    http_response_code(500);
    echo "Internal Server Error";
    exit;
});

// --- configuration
set_time_limit(0);
ini_set('memory_limit','1024M');
date_default_timezone_set('Asia/Kolkata');

$debug = (isset($_GET['debug']) && $_GET['debug']=='1');

$DOC_KEYS = [
  'aadhar_file'=>'Aadhaar','pan_file'=>'PAN','voter_file'=>'VoterID',
  'ration_file'=>'RationCard','consent_file'=>'Consent','gps_selfie_file'=>'GPS_Selfie',
  'police_verification_file'=>'PoliceVerification','permanent_address_proof_file'=>'PermanentAddressProof',
  'parent_aadhar_file'=>'ParentAadhaar','nseit_cert_file'=>'NSEIT_Cert','self_declaration_file'=>'SelfDeclaration',
  'non_disclosure_file'=>'NDA','edu_10th_file'=>'10th','edu_12th_file'=>'12th','edu_college_file'=>'CollegeCert'
];

function safeName($s){ $s = trim((string)$s); $s = preg_replace('/[^\w\s\-\.]/u','',$s); $s = preg_replace('/\s+/','_',$s); return substr($s,0,180); }
function join_paths(){ $a=[]; foreach(func_get_args() as $arg){ if($arg!=='') $a[]=rtrim($arg,'/\\'); } return join(DIRECTORY_SEPARATOR,$a); }
function rrmdir($d){ if(!is_dir($d)) return; foreach(scandir($d) as $f){ if($f==='.'||$f==='..') continue; $p=$d.DIRECTORY_SEPARATOR.$f; if(is_dir($p)) rrmdir($p); else @unlink($p);} @rmdir($d); }
function resolveSrc($src){ if (file_exists($src)) return $src; $cand = $_SERVER['DOCUMENT_ROOT'].DIRECTORY_SEPARATOR.ltrim($src,'/\\'); return file_exists($cand)?$cand:false; }
function normalize_keyish($s){ return preg_replace('/[^a-z0-9]/','',strtolower((string)$s)); }

// --- params
$branch = $_GET['branch'] ?? null;
$status = $_GET['status'] ?? null;
$from   = $_GET['from'] ?? null;
$to     = $_GET['to'] ?? null;
$pdfMode = (isset($_GET['pdf']) && $_GET['pdf']=='1');
$bundleMode = $_GET['bundle'] ?? 'zip';
$limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 0;
$docParam = $_GET['doc'] ?? null;
$docMode = 'all';
if ($docParam !== null && strtolower($docParam) !== 'all') {
    $norm = normalize_keyish($docParam);
    $lookup = [];
    foreach ($DOC_KEYS as $k=>$lab){ $lookup[normalize_keyish($k)]=$k; $lookup[normalize_keyish($lab)]=$k; }
    if (isset($lookup[$norm])) $docMode = $lookup[$norm];
    else {
        if ($debug) { // show helpful message in debug mode
            header('Content-Type: application/json');
            echo json_encode(['error'=>'Invalid doc key/label','valid_keys'=>array_keys($DOC_KEYS)], JSON_PRETTY_PRINT);
            @ob_end_clean(); exit;
        }
        http_response_code(400); error_log("export_all: invalid doc param {$docParam}"); exit;
    }
}

// --- DB
$mysqli = new mysqli("localhost","root","","qmit_system");
if ($mysqli->connect_error) {
    error_log("export_all: db connect error: ".$mysqli->connect_error);
    if ($debug){ header('Content-Type: application/json'); echo json_encode(['error'=>'DB connect error']); @ob_end_clean(); exit; }
    http_response_code(500); exit;
}

// --- build SQL
$where=[]; $params=[]; $types='';
if ($branch){ $where[]="branch_name = ?"; $params[]=$branch; $types.='s'; }
if ($status){ $where[]="status = ?"; $params[]=$status; $types.='s'; }
if ($from){ $where[]="created_at >= ?"; $params[]=$from . " 00:00:00"; $types.='s'; }
if ($to){ $where[]="created_at <= ?"; $params[]=$to . " 23:59:59"; $types.='s'; }

$sql = "SELECT * FROM operatordoc" . (!empty($where) ? " WHERE " . implode(' AND ', $where) : "") . " ORDER BY id ASC" . ($limit>0 ? " LIMIT ".$limit : "");
$stmt = $mysqli->prepare($sql);
if ($stmt === false) {
    error_log("export_all: db prepare failed: ".$mysqli->error);
    if ($debug){ header('Content-Type: application/json'); echo json_encode(['error'=>'DB prepare error','sql'=>$sql]); @ob_end_clean(); exit; }
    http_response_code(500); exit;
}
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($rows)){
    if ($debug){ header('Content-Type: application/json'); echo json_encode(['collected'=>[]], JSON_PRETTY_PRINT); @ob_end_clean(); exit; }
    http_response_code(404); exit;
}

// --- work dir
$tmpBase = sys_get_temp_dir();
$workDir = join_paths($tmpBase, uniqid('export_all_', true));
@mkdir($workDir, 0700, true);

$collectedForZip = []; $operatorGeneratedPdfs = [];
$imageExts = ['jpg','jpeg','png','gif','tiff','tif','bmp','webp']; $pdfExts = ['pdf'];

// set imagick limits if available
if (extension_loaded('imagick') && class_exists('Imagick')) {
    try {
        @Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 512);
        @Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, 1024);
        @Imagick::setResourceLimit(Imagick::RESOURCETYPE_DISK, 2048);
        error_log("Imagick extension loaded OK");
    } catch(Exception $e){ error_log("imagick:setResourceLimit:".$e->getMessage()); }
} else {
    error_log("Imagick extension not loaded or class not found.");
}

// --- collect files per operator
foreach ($rows as $row) {
    $id=(int)$row['id'];
    $opName=safeName($row['operator_full_name'] ?? "operator_{$id}");
    $branchName=safeName($row['branch_name'] ?? 'branch');
    $folder="{$opName}_{$branchName}_Documents";
    $opDir = join_paths($workDir, $folder);
    @mkdir($opDir,0700,true);

    $collectedThisOp=[];
    $keysToLoop = ($docMode === 'all') ? array_keys($DOC_KEYS) : [$docMode];
    foreach ($keysToLoop as $key){
        if (!isset($DOC_KEYS[$key])) continue;
        $label = $DOC_KEYS[$key];
        $val = $row[$key] ?? '';
        if (!$val) continue;
        $src = resolveSrc($val);
        if (!$src) continue;
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        $outName = "{$opName}_{$branchName}_{$label}.".(($ext==='txt')?'xls':$ext);
        $outPath = join_paths($opDir, $outName);
        if (@copy($src, $outPath)) {
            $collectedThisOp[]=['path'=>$outPath,'name'=>$folder.'/'.$outName,'orig'=>$src];
        } else {
            error_log("export_all: failed to copy {$src} to {$outPath}");
        }
    }
    if (empty($collectedThisOp)){ @rmdir($opDir); continue; }

    // optional PDF conversion
    if ($pdfMode && class_exists('Imagick')) {
        try {
            $im = new Imagick();
            $im->setResolution(150,150);
            $pagesAdded=0;
            foreach($collectedThisOp as $i=>$f){
                $ext=strtolower(pathinfo($f['path'],PATHINFO_EXTENSION));
                if (in_array($ext,$imageExts)){
                    $page = new Imagick($f['path']);
                    if (method_exists($page,'autoOrient')) $page->autoOrient();
                    $page->setImageFormat('pdf');
                    $im->addImage($page);
                    $page->clear(); $page->destroy();
                    $pagesAdded++;
                } elseif (in_array($ext,$pdfExts)){
                    $pdf = new Imagick();
                    $pdf->setResolution(150,150);
                    $pdf->readImage($f['path']);
                    foreach ($pdf as $p){ $p->setImageFormat('pdf'); $im->addImage($p); $pagesAdded++; }
                    $pdf->clear(); $pdf->destroy();
                }
            }
            if ($pagesAdded>0){
                $im->resetIterator();
                $opPdfPath = join_paths($opDir, "{$opName}_{$branchName}.pdf");
                $im->setImageFormat('pdf');
                $im->writeImages($opPdfPath, true);
                $operatorGeneratedPdfs[] = ['path'=>$opPdfPath,'name'=>$folder.'/'.basename($opPdfPath)];
                foreach ($collectedThisOp as $k=>$entry){
                    $ext=strtolower(pathinfo($entry['path'],PATHINFO_EXTENSION));
                    if (in_array($ext,array_merge($imageExts,$pdfExts))) $collectedThisOp[$k]['skip']=true;
                }
            }
            $im->clear(); $im->destroy();
        } catch(Exception $e){ error_log("imagick convert op {$id}: ".$e->getMessage()); }
    }

    foreach($collectedThisOp as $entry) if (!isset($entry['skip'])) $collectedForZip[]=$entry;
}

// append created PDFs
foreach ($operatorGeneratedPdfs as $p) $collectedForZip[]=$p;

if (empty($collectedForZip)){ rrmdir($workDir); http_response_code(404); @ob_end_clean(); echo "No files found"; exit; }

// debug: return JSON of files
if ($debug){
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['collected'=>array_map(function($f){return ['name'=>$f['name'],'size'=>@filesize($f['path'])];}, $collectedForZip)], JSON_PRETTY_PRINT);
    rrmdir($workDir); exit;
}

// single file direct stream
if (count($collectedForZip)===1 && $bundleMode !== 'singlezip'){
    $f = $collectedForZip[0];
    clearstatcache(true,$f['path']);
    if (!file_exists($f['path'])){ rrmdir($workDir); http_response_code(500); @ob_end_clean(); exit; }
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/octet-stream');
    header('Content-Length: '.filesize($f['path']));
    header('Content-Disposition: attachment; filename="'.basename($f['name']).'"');
    header('Content-Transfer-Encoding: binary');
    $fp = fopen($f['path'],'rb'); fpassthru($fp); fclose($fp);
    rrmdir($workDir); exit;
}

// create zip
if (!class_exists('ZipArchive')){ rrmdir($workDir); http_response_code(500); @ob_end_clean(); echo "Enable PHP Zip extension."; exit; }

$zipPath = join_paths(sys_get_temp_dir(), uniqid('export_all_').'.zip');
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE)!==TRUE){ rrmdir($workDir); http_response_code(500); @ob_end_clean(); echo "Could not create zip"; exit; }
foreach ($collectedForZip as $f) if (file_exists($f['path'])) $zip->addFile($f['path'], $f['name']);
$zip->close();

// verify zip
$za = new ZipArchive();
if ($za->open($zipPath) !== TRUE){
    error_log("export_all: created zip is invalid: {$zipPath}");
    rrmdir($workDir);
    http_response_code(500);
    @ob_end_clean();
    echo "Server error: invalid zip created. Check server logs.";
    exit;
}
$za->close();

// stream zip (no stray output)
clearstatcache(true,$zipPath);
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/zip');
header('Content-Description: File Transfer');
header('Content-Disposition: attachment; filename="Operators_Export_'.date('Ymd_His').'.zip"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: '.filesize($zipPath));
$fh = fopen($zipPath,'rb'); fpassthru($fh); fclose($fh);
@unlink($zipPath);
rrmdir($workDir);
exit;
