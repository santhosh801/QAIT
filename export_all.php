<?php
// export_all.php
// Usage examples:
// export_all.php?export=1                      -> export all operators (all docs) based on optional filters
// export_all.php?doc=edu_10th_file&filter=pending -> export only the 10th doc for all operators in 'pending' filter
// Optional params: search, bank, filter (pending/accepted/not working/working), page (ignored)
// This will generate either a ZIP (multiple files) or single PDF download if only one file in result.

set_time_limit(0);
ini_set('memory_limit','1024M');

// DB
$mysqli = new mysqli("localhost", "root", "", "qmit_system");
if ($mysqli->connect_error) { http_response_code(500); echo "DB connect error"; exit; }

// allowed doc keys map (same as before)
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

function safeName($s){
    $s = trim((string)$s);
    $s = preg_replace('/[^\w\s\-\.]/u', '', $s);
    $s = preg_replace('/\s+/', '_', $s);
    return substr($s,0,180);
}
function join_paths() {
    $paths = array(); foreach (func_get_args() as $arg) if ($arg !== '') $paths[] = rtrim($arg, '/\\'); return join(DIRECTORY_SEPARATOR, $paths);
}
// include the same conversion helpers as in export_operator.php
// For brevity we re-define a small convertToPdf that checks Imagick first, otherwise uses simple GD fallback
function imageToPdf_imagick($src, $dst) {
    try {
        $im = new Imagick();
        $im->readImage($src);
        $im->setImageFormat('pdf');
        $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $im->setImageBackgroundColor('white');
        $im->writeImages($dst, true);
        $im->clear(); $im->destroy();
        return file_exists($dst);
    } catch (Exception $e) { error_log("Imagick export_all failed: ".$e->getMessage()); return false; }
}
function imageToPdf_fpdi_gd_simple($src, $dst) {
    // Very minimal: convert to JPEG using GD and then reuse a simple single-image PDF generator by invoking imagemagick if unavailable.
    if (!file_exists($src)) return false;
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg','jpeg','png'])) {
        $img = null;
        if (in_array($ext, ['jpg','jpeg']) && function_exists('imagecreatefromjpeg')) $img = @imagecreatefromjpeg($src);
        elseif ($ext==='png' && function_exists('imagecreatefrompng')) $img = @imagecreatefrompng($src);
        else $img = @imagecreatefromstring(file_get_contents($src));
        if ($img) {
            $tmp = tempnam(sys_get_temp_dir(), 'jpg_') . '.jpg';
            imagejpeg($img, $tmp, 90);
            imagedestroy($img);
            // very small PDF wrapper: use Imagick if available to convert tmp to PDF, else attempt a system 'convert' if available
            if (class_exists('Imagick')) {
                $ok = imageToPdf_imagick($tmp, $dst);
                @unlink($tmp);
                return $ok;
            } else {
                // fallback: try to output a very basic PDF (not robust) by embedding JPEG stream
                $data = file_get_contents($tmp);
                @unlink($tmp);
                if ($data === false) return false;
                // Minimal embedding as in previous file (not ideal, but often works)
                $w = imagesx(@imagecreatefromstring($data));
                $h = imagesy(@imagecreatefromstring($data));
                if (!$w || !$h) { file_put_contents($dst, $data); return false; }
                // We'll attempt same trick: embed JPEG as DCTDecode inside PDF (best-effort)
                $obj1 = "1 0 obj\n<< /Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($data) . ">>\nstream\n" . $data . "\nendstream\nendobj\n";
                $contents = "q\n{$w} 0 0 {$h} 0 0 cm\n/Im0 Do\nQ\n";
                $obj2 = "2 0 obj\n<< /Length " . strlen($contents) . " >>\nstream\n" . $contents . "\nendstream\nendobj\n";
                $page = "3 0 obj\n<< /Type /Page /Resources << /XObject << /Im0 1 0 R >> /ProcSet [/PDF /ImageC] >> /Contents 2 0 R /MediaBox [0 0 {$w} {$h}] >>\nendobj\n";
                $pages = "4 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
                $catalog = "5 0 obj\n<< /Type /Catalog /Pages 4 0 R >>\nendobj\n";
                $pdf = "%PDF-1.3\n" . $obj1 . $obj2 . $page . $pages . $catalog;
                // xref is omitted for brevity; many readers accept this
                file_put_contents($dst, $pdf);
                return file_exists($dst);
            }
        }
    }
    return false;
}

function convertToPdf($src, $dst) {
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        return copy($src, $dst) ? $dst : false;
    }
    if (class_exists('Imagick')) {
        if (imageToPdf_imagick($src, $dst)) return $dst;
    }
    if (function_exists('imagecreatefromstring')) {
        if (imageToPdf_fpdi_gd_simple($src, $dst)) return $dst;
    }
    return false;
}

// Build WHERE clause based on provided params (mirror em_verfi.php)
$whereClauses = [];
$search = isset($_GET['search']) ? $mysqli->real_escape_string($_GET['search']) : '';
$filter = isset($_GET['filter']) ? $mysqli->real_escape_string($_GET['filter']) : '';
$bank = isset($_GET['bank']) ? $mysqli->real_escape_string($_GET['bank']) : '';

if ($search !== '') {
    $s = $mysqli->real_escape_string($search);
    $whereClauses[] = "(operator_full_name LIKE '%$s%' OR email LIKE '%$s%' OR operator_id LIKE '%$s%')";
}
if ($filter !== '') {
    if (in_array($filter, ['pending','accepted','rejected'])) {
        $whereClauses[] = "status = '" . $mysqli->real_escape_string($filter) . "'";
    } elseif (in_array($filter, ['working','not working'])) {
        $whereClauses[] = "work_status = '" . $mysqli->real_escape_string($filter) . "'";
    }
}
if ($bank !== '') {
    $whereClauses[] = "bank = '" . $mysqli->real_escape_string($bank) . "'";
}
$where = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// doc param (specific doc key)
$doc = isset($_GET['doc']) ? $_GET['doc'] : ''; // e.g. 'edu_10th_file'
// Validate doc key if provided
if ($doc && !array_key_exists($doc, $DOC_KEYS)) {
    http_response_code(400);
    echo "Invalid doc key";
    exit;
}

// Query operators (no pagination; exports operate on full filtered set)
$sql = "SELECT * FROM operatordoc $where ORDER BY created_at DESC";
$res = $mysqli->query($sql);
if (!$res) { http_response_code(500); echo "Query error"; exit; }

// Prepare work folder
$tmpBase = sys_get_temp_dir();
$uniq = uniqid('export_all_', true);
$workDir = join_paths($tmpBase, $uniq);
@mkdir($workDir, 0700, true);

$collectedFiles = []; // list of ['path'=>$path, 'name'=>$name]

// iterate operators
while ($row = $res->fetch_assoc()) {
    $opId = (int)$row['id'];
    $opName = safeName($row['operator_full_name'] ?? ('operator_'.$opId));
    $branch = safeName($row['branch_name'] ?? 'branch');
    $folderLabel = "{$opName}_{$branch}_Documents";

    if ($doc) {
        // only that doc per operator
        $val = $row[$doc] ?? '';
        if (!$val) continue;
        $src = $val;
        // attempt to resolve local path as before
        if (preg_match('#^https?://#i', $src)) {
            if (strpos($src, $_SERVER['HTTP_HOST'] ?? '') !== false) {
                $parsed = parse_url($src);
                $src = $_SERVER['DOCUMENT_ROOT'] . ($parsed['path'] ?? '');
            } else continue;
        } else {
            if (!file_exists($src)) {
                $candidate = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . ltrim($src, '/\\');
                if (file_exists($candidate)) $src = $candidate;
            }
        }
        if (!file_exists($src)) continue;

        $label = $DOC_KEYS[$doc];
        $outName = "{$opName}_{$branch}_{$label}.pdf";
        $outPath = join_paths($workDir, $outName);
        if (convertToPdf($src, $outPath)) $collectedFiles[] = ['path'=>$outPath, 'name'=>$outName];
    } else {
        // export all docs for operator: create folder and convert each doc
        $opFolder = join_paths($workDir, $folderLabel);
        @mkdir($opFolder, 0700, true);
        foreach ($DOC_KEYS as $k=>$label) {
            $val = $row[$k] ?? '';
            if (!$val) continue;
            $src = $val;
            if (preg_match('#^https?://#i', $src)) {
                if (strpos($src, $_SERVER['HTTP_HOST'] ?? '') !== false) {
                    $parsed = parse_url($src);
                    $src = $_SERVER['DOCUMENT_ROOT'] . ($parsed['path'] ?? '');
                } else continue;
            } else {
                if (!file_exists($src)) {
                    $candidate = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . ltrim($src, '/\\');
                    if (file_exists($candidate)) $src = $candidate;
                }
            }
            if (!file_exists($src)) continue;
            $outName = "{$opName}_{$branch}_{$label}.pdf";
            $outPath = join_paths($opFolder, $outName);
            if (convertToPdf($src, $outPath)) {
                $collectedFiles[] = ['path'=>$outPath, 'name'=>$folderLabel . '/' . $outName];
            }
        }
    }
}

// Nothing found?
if (empty($collectedFiles)) {
    // cleanup
    function _rrmdir($dir){ if(!is_dir($dir)) return; foreach(scandir($dir) as $f){ if($f=='.'||$f=='..') continue; $p=$dir.DIRECTORY_SEPARATOR.$f; if(is_dir($p)) _rrmdir($p); else @unlink($p);} @rmdir($dir); }
    _rrmdir($workDir);
    http_response_code(404); echo "No documents found for chosen filter/selection."; exit;
}

// If exactly 1 file and doc param present, send it directly
if (count($collectedFiles)===1 && $doc) {
    $file = $collectedFiles[0];
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($file['path']));
    header('Content-Disposition: attachment; filename="' . basename($file['name']) . '"');
    readfile($file['path']);
    // cleanup
    foreach ($collectedFiles as $cf) @unlink($cf['path']);
    _rrmdir($workDir);
    exit;
}

// Otherwise create a ZIP
$zipPath = join_paths(sys_get_temp_dir(), uniqid('export_all_zip_') . '.zip');
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE)!==TRUE) {
    _rrmdir($workDir);
    http_response_code(500); echo "Could not create zip"; exit;
}

// add each file into ZIP using provided name
foreach ($collectedFiles as $cf) {
    $zip->addFile($cf['path'], $cf['name']);
}
$zip->close();

// stream
$dlname = 'operators_export_' . date('Ymd_His') . '.zip';
header('Content-Type: application/zip');
header('Content-Length: ' . filesize($zipPath));
header('Content-Disposition: attachment; filename="'.$dlname.'"');
readfile($zipPath);

// cleanup
@unlink($zipPath);
foreach ($collectedFiles as $cf) @unlink($cf['path']);
_rrmdir($workDir);
exit;
