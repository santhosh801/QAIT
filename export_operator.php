<?php
// export_operator.php
// Export docs for a single operator by id.
// - No Imagick/GD conversions (keep original file type).
// - If doc=txt -> serve/rename as .xls (Excel-friendly).
// - If >1 file -> bundle ZIP (requires PHP zip extension).
// - Supports optional ?doc=key or ?doc=all (default).

set_time_limit(0);
ini_set('memory_limit','1024M');

if (!isset($_GET['id'])) {
    http_response_code(400); echo "Missing id"; exit;
}
$id = (int)$_GET['id'];

$mysqli = new mysqli("localhost", "root", "", "qmit_system");
if ($mysqli->connect_error) {
    http_response_code(500); echo "DB connect error"; exit;
}

$DOC_KEYS = [
  'aadhar_file' => 'Aadhaar',
  'pan_file' => 'PAN',
  'voter_file' => 'VoterID',
  'ration_file' => 'RationCard',
  'consent_file' => 'Consent',
  'gps_selfie_file' => 'GPS_Selfie',
  'police_verification_file' => 'PoliceVerification',
  'permanent_address_proof_file' => 'PermanentAddressProof',
  'parent_aadhar_file' => 'ParentAadhaar',
  'nseit_cert_file' => 'NSEIT_Cert',
  'self_declaration_file' => 'SelfDeclaration',
  'non_disclosure_file' => 'NDA',
  'edu_10th_file' => '10th',
  'edu_12th_file' => '12th',
  'edu_college_file' => 'CollegeCert'
];

// optional doc param
$doc = $_GET['doc'] ?? 'all';
if ($doc && $doc !== 'all' && !array_key_exists($doc, $DOC_KEYS)) {
    http_response_code(400); echo "Invalid doc key"; exit;
}

$stmt = $mysqli->prepare("SELECT * FROM operatordoc WHERE id=? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();
if (!$row) { http_response_code(404); echo "Operator not found"; exit; }

function safeName($s) {
    $s = trim((string)$s);
    $s = preg_replace('/[^\w\s\-\.]/u', '', $s);
    $s = preg_replace('/\s+/', '_', $s);
    return substr($s, 0, 180);
}
function join_paths() {
    $a=[]; foreach(func_get_args() as $arg){ if($arg!=='') $a[]=rtrim($arg,'/\\'); }
    return join(DIRECTORY_SEPARATOR,$a);
}
function rrmdir($d){
    if(!is_dir($d)) return;
    foreach(scandir($d) as $f){
        if($f==='.'||$f==='..') continue;
        $p=$d.DIRECTORY_SEPARATOR.$f;
        if(is_dir($p)) rrmdir($p); else @unlink($p);
    }
    @rmdir($d);
}

$opName = safeName($row['operator_full_name'] ?? ("operator_$id"));
$branch = safeName($row['branch_name'] ?? 'branch');
$folder = "{$opName}_{$branch}_Documents";

$tmpBase = sys_get_temp_dir();
$workDir = join_paths($tmpBase, uniqid('export_op_', true));
@mkdir($workDir, 0700, true);
$opFolder = join_paths($workDir, $folder);
@mkdir($opFolder, 0700, true);

$collected = [];

// Which docs to export
$keysToProcess = ($doc==='all') ? array_keys($DOC_KEYS) : [$doc];

foreach ($keysToProcess as $key) {
    $label = $DOC_KEYS[$key];
    $val = $row[$key] ?? '';
    if (!$val) continue;

    $src = $val;
    if (!file_exists($src)) {
        $cand = $_SERVER['DOCUMENT_ROOT'].DIRECTORY_SEPARATOR.ltrim($src,'/\\');
        if (file_exists($cand)) $src=$cand; else continue;
    }

    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    $outName = "{$opName}_{$branch}_{$label}.".(($ext==='txt')?'xls':$ext);
    $outPath = join_paths($opFolder, $outName);
    copy($src, $outPath);
    $collected[] = ['path'=>$outPath, 'name'=>$folder.'/'.$outName];
}

if (empty($collected)) {
    rrmdir($workDir);
    http_response_code(404); echo "No documents found for operator"; exit;
}

// Single file? → direct download
if (count($collected)===1) {
    $f=$collected[0];
    header('Content-Type: application/octet-stream');
    header('Content-Length: '.filesize($f['path']));
    header('Content-Disposition: attachment; filename="'.basename($f['name']).'"');
    readfile($f['path']);
    unlink($f['path']); rrmdir($workDir); exit;
}

// Otherwise → ZIP bundle
if (!class_exists('ZipArchive')) {
    rrmdir($workDir);
    http_response_code(500);
    echo "Server error: PHP Zip extension not available. Enable 'zip' in php.ini and restart Apache.";
    exit;
}

$zipPath = join_paths(sys_get_temp_dir(), uniqid('opzip_').'.zip');
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE)!==TRUE) {
    rrmdir($workDir); http_response_code(500); echo "Could not create zip"; exit;
}
foreach ($collected as $f) {
    $zip->addFile($f['path'], $f['name']);
}
$zip->close();

header('Content-Type: application/zip');
header('Content-Length: '.filesize($zipPath));
header('Content-Disposition: attachment; filename="'.$folder.'.zip"');
readfile($zipPath);

@unlink($zipPath);
foreach ($collected as $f) @unlink($f['path']);
rrmdir($workDir);
exit;
