<?php
// export_operator.php - single operator exporter with per-file PDF conversion
// Save as UTF-8 WITHOUT BOM

declare(strict_types=1);
ini_set('display_errors','0');
ini_set('display_startup_errors','0');
ini_set('log_errors','1');
error_reporting(E_ALL);

ob_start();
register_shutdown_function(function(){ $buf = ob_get_contents(); if ($buf!=='') error_log("export_operator: stray output: ".substr($buf,0,1000)); @ob_end_clean(); });
set_exception_handler(function($e){ error_log("export_operator exception: ".$e->getMessage()); while(ob_get_level()) @ob_end_clean(); http_response_code(500); echo "Internal server error"; exit; });

set_time_limit(0); ini_set('memory_limit','1024M');

if (!isset($_GET['id'])){ http_response_code(400); echo "Missing id"; exit; }
$id = (int)$_GET['id'];

// default: convert image files to PDF. set pdf=0 to disable conversion.
$pdfMode = ( !isset($_GET['pdf']) ) ? true : ( (string)$_GET['pdf'] === '1' );

// support selecting a single doc via ?doc=
$DOC_KEYS = [
  'aadhar_file'=>'Aadhaar','pan_file'=>'PAN','voter_file'=>'VoterID',
  'ration_file'=>'RationCard','consent_file'=>'Consent','gps_selfie_file'=>'GPS_Selfie',
  'police_verification_file'=>'PoliceVerification','permanent_address_proof_file'=>'PermanentAddressProof',
  'parent_aadhar_file'=>'ParentAadhaar','nseit_cert_file'=>'NSEIT_Cert','self_declaration_file'=>'SelfDeclaration',
  'non_disclosure_file'=>'NDA','edu_10th_file'=>'10th','edu_12th_file'=>'12th','edu_college_file'=>'CollegeCert'
];

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
    else { http_response_code(400); echo "Invalid doc key"; exit; }
}

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
    $cand = file_exists($src) ? $src : ($_SERVER['DOCUMENT_ROOT'].DIRECTORY_SEPARATOR.ltrim($src,'/\\'));
    if (!$cand || !file_exists($cand)) return false;
    $real = realpath($cand);
    if (!$real) return false;
    foreach ($GLOBALS['ALLOWED_BASES'] as $base){
        $b = realpath($base);
        if ($b && strpos($real, $b) === 0) return $real;
        if ($base === $real || strpos($real, $base) === 0) return $real;
    }
    error_log("resolveSrc: refused path outside allowed bases: {$real}");
    return false;
}

function imagick_supports_pdf(): bool {
    if (!extension_loaded('imagick') || !class_exists('Imagick')) return false;
    try {
        $formats = Imagick::queryFormats();
        return is_array($formats) && (in_array('PDF', $formats) || in_array('PDF', array_map('strtoupper',$formats)));
    } catch(Exception $e){ error_log("imagick_supports_pdf: ".$e->getMessage()); return false; }
}

$mysqli = new mysqli("localhost","root","","qmit_system");
if ($mysqli->connect_error) { http_response_code(500); echo "DB connect error"; exit; }

$stmt = $mysqli->prepare("SELECT * FROM operatordoc WHERE id=? LIMIT 1");
if ($stmt === false){ http_response_code(500); echo "DB prepare error"; exit; }
$stmt->bind_param('i',$id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();
if (!$row){ http_response_code(404); echo "Operator not found"; exit; }

$opName = safeName($row['operator_full_name'] ?? ("operator_$id"));
$branch = safeName($row['branch_name'] ?? 'branch');

// select which keys to process
$keysToProcess = ($docMode === 'all') ? array_keys($DOC_KEYS) : [$docMode];
$folderSuffix = ($docMode === 'all') ? 'AllDocuments' : $DOC_KEYS[$docMode];
$folder = "{$opName}_{$branch}_{$folderSuffix}";

$tmpBase = sys_get_temp_dir();
$workDir = join_paths($tmpBase, uniqid('export_op_', true));
@mkdir($workDir,0700,true);
$opFolder = join_paths($workDir,$folder);
@mkdir($opFolder,0700,true);

$collected=[];
$imageExts = ['jpg','jpeg','png','gif','tiff','tif','bmp','webp']; $pdfExts = ['pdf'];

$pdfCapable = imagick_supports_pdf();
if ($pdfMode && !$pdfCapable){
    error_log("export_operator: PDF conversion requested but Imagick/PDF support missing.");
    $pdfMode = false;
}

foreach ($keysToProcess as $key){
    if (!isset($DOC_KEYS[$key])) continue;
    $label = $DOC_KEYS[$key];
    $val = $row[$key] ?? '';
    if (!$val) continue;
    $src = resolveSrc($val);
    if (!$src){ error_log("export_operator: unresolved src for id {$id} key {$key}"); continue; }
    if (!is_readable($src)){ error_log("export_operator: unreadable $src"); continue; }
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    $baseOutName = "{$opName}_{$branch}_{$label}";
    $outPath = join_paths($opFolder,$baseOutName . '.' . ( ($ext==='txt') ? 'xls' : $ext ));
    if (!@copy($src, $outPath)){ error_log("export_operator: failed to copy {$src} to {$outPath}"); continue; }

    if ($pdfMode && in_array($ext, $imageExts) && $pdfCapable) {
        try {
            $im = new Imagick($outPath);
            if (method_exists($im,'autoOrient')) $im->autoOrient();
            $im->setImageColorspace(Imagick::COLORSPACE_RGB);
            $im->setImageFormat('pdf');
            $pdfOut = join_paths($opFolder, $baseOutName . '.pdf');
            $im->writeImages($pdfOut, true);
            $im->clear(); $im->destroy();
            @unlink($outPath);
            $collected[]=['path'=>$pdfOut,'name'=>$folder.'/'.basename($pdfOut),'label'=>$label];
            continue;
        } catch(Exception $e){
            error_log("export_operator: imagick convert failed for {$outPath}: ".$e->getMessage());
        }
    }

    $collected[]=['path'=>$outPath,'name'=>$folder.'/'.basename($outPath),'label'=>$label];
}

if (empty($collected)){ rrmdir($workDir); http_response_code(404); echo "No documents found for operator"; exit; }

if (count($collected)===1){
    $f = $collected[0];
    clearstatcache(true,$f['path']);
    if (!file_exists($f['path'])){ rrmdir($workDir); http_response_code(500); echo "Server error: file missing"; exit; }
    while(ob_get_level()) @ob_end_clean();
    header('X-Content-Type-Options: nosniff');
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $f['path']) ?: 'application/octet-stream';
    finfo_close($finfo);
    header('Content-Type: '.$mime);
    header('Content-Length: '.filesize($f['path']));
    header('Content-Disposition: attachment; filename="'.basename($f['path']).'"; filename*=UTF-8\'\''.rawurlencode(basename($f['path'])));
    header('Content-Transfer-Encoding: binary');
    $fp = fopen($f['path'],'rb'); fpassthru($fp); fclose($fp);
    @unlink($f['path']); rrmdir($workDir); exit;
}

if (!class_exists('ZipArchive')){ rrmdir($workDir); http_response_code(500); echo "Enable PHP Zip extension."; exit; }
$zipPath = join_paths(sys_get_temp_dir(), uniqid('opzip_').'.zip');
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE)!==TRUE){ rrmdir($workDir); http_response_code(500); echo "Could not create zip"; exit; }
foreach ($collected as $f) if (file_exists($f['path'])) $zip->addFile($f['path'],$f['name']);
$zip->close();

// Exports a single operator's single document (or all docs). Very simple stub.
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$doc = isset($_GET['doc']) ? $_GET['doc'] : '';
if (!$id || !$doc) {
    header('HTTP/1.1 400 Bad Request'); echo "Missing id or doc param"; exit;
}
require_once 'db_connect.php'; // optional shared DB file
$mysqli = new mysqli("localhost","root","","qmit_system");
$res = $mysqli->query("SELECT * FROM operatordoc WHERE id = " . intval($id));
$row = $res ? $res->fetch_assoc() : null;
if (!$row) { header('HTTP/1.1 404 Not Found'); echo "Operator not found"; exit; }
if ($doc === 'all') {
    // For demo, return a CSV for this operator
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename=operator_'.$id.'_all.csv');
    $out = fopen('php://output','w');
    fputcsv($out, array_keys($row));
    fputcsv($out, array_values($row));
    fclose($out);
    exit;
}
$path = $row[$doc] ?? '';
if (!$path || !file_exists($path)) { header('HTTP/1.1 404 Not Found'); echo "Document not found"; exit; }
$mime = mime_content_type($path);
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="'.basename($path).'"');
readfile($path);


$za = new ZipArchive();
if ($za->open($zipPath) !== TRUE){ rrmdir($workDir); http_response_code(500); echo "Server error: invalid zip created"; exit; }
$za->close();

while(ob_get_level()) @ob_end_clean();
header('Content-Type: application/zip');
header('Content-Description: File Transfer');
header('Content-Disposition: attachment; filename="'.$folder.'.zip"');
header('Content-Transfer-Encoding: binary');
header('Content-Length: '.filesize($zipPath));
$fh=fopen($zipPath,'rb'); fpassthru($fh); fclose($fh);
@unlink($zipPath);
foreach($collected as $f) @unlink($f['path']);
rrmdir($workDir);
exit;
