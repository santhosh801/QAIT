<?php
// export_operator.php (hardened)
// - ?id= required
// - ?doc=key or ?doc=all
ini_set('display_errors',0); ini_set('display_startup_errors',0); ini_set('log_errors',1); error_reporting(E_ALL);
ob_start();
register_shutdown_function(function(){ $buf = ob_get_contents(); if ($buf!=='') error_log("export_operator: stray output: ".substr($buf,0,1000)); @ob_end_clean(); });
set_exception_handler(function($e){ error_log("export_operator exception: ".$e->getMessage()); while(ob_get_level()) @ob_end_clean(); http_response_code(500); echo "Internal server error"; exit; });

set_time_limit(0); ini_set('memory_limit','1024M');

if (!isset($_GET['id'])){ http_response_code(400); echo "Missing id"; exit; }
$id = (int)$_GET['id'];

$mysqli = new mysqli("localhost","root","","qmit_system");
if ($mysqli->connect_error) { http_response_code(500); echo "DB connect error"; exit; }

$DOC_KEYS = [
  'aadhar_file'=>'Aadhaar','pan_file'=>'PAN','voter_file'=>'VoterID',
  'ration_file'=>'RationCard','consent_file'=>'Consent','gps_selfie_file'=>'GPS_Selfie',
  'police_verification_file'=>'PoliceVerification','permanent_address_proof_file'=>'PermanentAddressProof',
  'parent_aadhar_file'=>'ParentAadhaar','nseit_cert_file'=>'NSEIT_Cert','self_declaration_file'=>'SelfDeclaration',
  'non_disclosure_file'=>'NDA','edu_10th_file'=>'10th','edu_12th_file'=>'12th','edu_college_file'=>'CollegeCert'
];

$doc = $_GET['doc'] ?? 'all';
if ($doc && $doc!=='all' && !array_key_exists($doc,$DOC_KEYS)){ http_response_code(400); echo "Invalid doc key"; exit; }

$stmt = $mysqli->prepare("SELECT * FROM operatordoc WHERE id=? LIMIT 1");
if ($stmt === false){ http_response_code(500); echo "DB prepare error"; exit; }
$stmt->bind_param('i',$id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();
if (!$row){ http_response_code(404); echo "Operator not found"; exit; }

function safeName($s){ $s = trim((string)$s); $s = preg_replace('/[^\w\s\-\.]/u','',$s); $s = preg_replace('/\s+/','_',$s); return substr($s,0,180); }
function join_paths(){ $a=[]; foreach(func_get_args() as $arg){ if($arg!=='') $a[]=rtrim($arg,'/\\'); } return join(DIRECTORY_SEPARATOR,$a); }
function rrmdir($d){ if(!is_dir($d)) return; foreach(scandir($d) as $f){ if($f==='.'||$f==='..') continue; $p=$d.DIRECTORY_SEPARATOR.$f; if(is_dir($p)) rrmdir($p); else @unlink($p);} @rmdir($d); }

$opName = safeName($row['operator_full_name'] ?? ("operator_$id"));
$branch = safeName($row['branch_name'] ?? 'branch');
$folder = "{$opName}_{$branch}_Documents";

$tmpBase = sys_get_temp_dir();
$workDir = join_paths($tmpBase, uniqid('export_op_', true));
@mkdir($workDir,0700,true);
$opFolder = join_paths($workDir,$folder);
@mkdir($opFolder,0700,true);

$collected=[];
$keysToProcess = ($doc==='all') ? array_keys($DOC_KEYS) : [$doc];

foreach ($keysToProcess as $key){
    if (!isset($DOC_KEYS[$key])) continue;
    $label = $DOC_KEYS[$key];
    $val = $row[$key] ?? '';
    if (!$val) continue;
    $src = $val;
    if (!file_exists($src)){
        $cand = $_SERVER['DOCUMENT_ROOT'].DIRECTORY_SEPARATOR.ltrim($src,'/\\');
        if (file_exists($cand)) $src = $cand; else continue;
    }
    $ext = strtolower(pathinfo($src,PATHINFO_EXTENSION));
    $outName = "{$opName}_{$branch}_{$label}.".(($ext==='txt')?'xls':$ext);
    $outPath = join_paths($opFolder,$outName);
    copy($src,$outPath);
    $collected[]=['path'=>$outPath,'name'=>$folder.'/'.$outName];
}

if (empty($collected)){ rrmdir($workDir); http_response_code(404); echo "No documents found for operator"; exit; }

if (count($collected)===1){
    $f=$collected[0];
    clearstatcache(true,$f['path']);
    if (!file_exists($f['path'])){ rrmdir($workDir); http_response_code(500); echo "Server error: file missing"; exit; }
    while(ob_get_level()) ob_end_clean();
    header('Content-Type: application/octet-stream');
    header('Content-Length: '.filesize($f['path']));
    header('Content-Disposition: attachment; filename="'.basename($f['name']).'"');
    header('Content-Transfer-Encoding: binary');
    $fp = fopen($f['path'],'rb'); fpassthru($fp); fclose($fp);
    @unlink($f['path']); rrmdir($workDir); exit;
}

// zip multiple files
if (!class_exists('ZipArchive')){ rrmdir($workDir); http_response_code(500); echo "Enable PHP Zip extension."; exit; }
$zipPath = join_paths(sys_get_temp_dir(), uniqid('opzip_').'.zip');
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE)!==TRUE){ rrmdir($workDir); http_response_code(500); echo "Could not create zip"; exit; }
foreach ($collected as $f) if (file_exists($f['path'])) $zip->addFile($f['path'],$f['name']);
$zip->close();

// validate zip
$za = new ZipArchive();
if ($za->open($zipPath) !== TRUE){ rrmdir($workDir); http_response_code(500); echo "Server error: invalid zip created"; exit; }
for($i=0;$i<$za->numFiles;$i++) error_log("export_operator: zip contains ".$za->getNameIndex($i));
$za->close();

clearstatcache(true,$zipPath);
while(ob_get_level()) ob_end_clean();
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
