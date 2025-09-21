<?php
// download_doc.php
// Streams a single operator document as PDF if possible (Imagick), or original file otherwise.
// GET: id=INT, doc or doc_key = column name (e.g., aadhar_file)
//
// This version auto-detects common "uploads" roots (XAMPP / production) and allows
// any file beneath those. Paths are normalized for Windows (case-insensitive, backslash-safe).

ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE);
session_start();

header('X-Download-Endpoint: v3');

function http_fail($code, $msg) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>false,'message'=>$msg]);
    exit;
}

if (!isset($_GET['id'])) http_fail(400,'Missing parameter: id');
$opId = (int)$_GET['id'];
if ($opId <= 0) http_fail(400,'Invalid id');

$docKey = '';
if (!empty($_GET['doc'])) $docKey = (string)$_GET['doc'];
if (!empty($_GET['doc_key'])) $docKey = (string)$_GET['doc_key'];
$docKey = preg_replace('/[^a-z0-9_\-]/i','', $docKey);
if ($docKey === '') http_fail(400,'Missing parameter: doc/doc_key');

// Allowed keys - adjust if your schema has more/less
$ALLOWED_KEYS = [
  'aadhar_file','pan_file','voter_file','ration_file','consent_file','gps_selfie_file',
  'police_verification_file','permanent_address_proof_file','parent_aadhar_file',
  'nseit_cert_file','self_declaration_file','non_disclosure_file','edu_10th_file',
  'edu_12th_file','edu_college_file','agreement_file','bank_passbook_file','photo_file'
];
if (!in_array($docKey, $ALLOWED_KEYS, true)) {
    http_fail(400, 'Invalid document key');
}

// DB connect
$mysqli = new mysqli('localhost','root','','qmit_system');
if ($mysqli->connect_error) http_fail(500, 'DB connect error: '.$mysqli->connect_error);
$mysqli->set_charset('utf8mb4');

// Helper: column exists
function column_exists($mysqli, $col) {
    $col = $mysqli->real_escape_string($col);
    $sql = "SHOW COLUMNS FROM `operatordoc` LIKE '$col'";
    if (!$res = $mysqli->query($sql)) return false;
    $ok = ($res->num_rows > 0);
    $res->close();
    return $ok;
}
if (!column_exists($mysqli, $docKey)) {
    http_fail(400, "Column '$docKey' not found in operatordoc");
}

// Build select
$selectParts = ["`$docKey` AS stored_path", "operator_full_name"];
$hasBranch = column_exists($mysqli, 'branch');
if ($hasBranch) $selectParts[] = "branch";

$sql = "SELECT ".implode(", ", $selectParts)." FROM operatordoc WHERE id = ? LIMIT 1";
$stmt = $mysqli->prepare($sql);
if (!$stmt) http_fail(500, 'DB prepare failed: '.$mysqli->error);
$stmt->bind_param('i', $opId);
if (!$stmt->execute()) { $err = $stmt->error ?: $mysqli->error; $stmt->close(); http_fail(500, 'DB execute failed: '.$err); }
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) { $stmt->close(); http_fail(404, 'Operator not found'); }
$row = $res->fetch_assoc();
$stmt->close();

$stored = trim((string)($row['stored_path'] ?? ''));
$opname_raw = trim((string)($row['operator_full_name'] ?? 'operator'));
$branch_raw = $hasBranch ? trim((string)($row['branch'] ?? '')) : '';

if ($stored === '') http_fail(404, 'No file uploaded for this document');

// Resolve candidate paths
$stored_rel = ltrim($stored, "/\\");
$candidates = [
    __DIR__ . '/' . $stored_rel,
    $_SERVER['DOCUMENT_ROOT'] . '/' . $stored_rel,
    __DIR__ . '/uploads/operatordoc/' . basename($stored_rel),
    __DIR__ . '/uploads/' . $stored_rel,
    $_SERVER['DOCUMENT_ROOT'] . '/QAIT/uploads/' . basename($stored_rel),
    $_SERVER['DOCUMENT_ROOT'] . '/QAIT/uploads/' . $stored_rel,
    $_SERVER['DOCUMENT_ROOT'] . '/uploads/' . $stored_rel,
    // last-resort: try basename under uploads
    __DIR__ . '/uploads/' . basename($stored_rel),
];

$local_path = null;
foreach ($candidates as $p) {
    if (is_file($p)) { $local_path = $p; break; }
}
if ($local_path === null) {
    // try raw stored value if absolute path was stored in DB
    if (is_file($stored)) $local_path = $stored;
}
if ($local_path === null) http_fail(404, 'Stored file not found on disk (checked candidates)');

// Normalize paths for comparison (lowercase + forward slashes)
function norm_path($p) {
    $r = realpath($p);
    if ($r === false) return false;
    $r = str_replace('\\','/',$r);
    $r = preg_replace('#/+#','/',$r);
    return strtolower($r);
}
$real = norm_path($local_path);
if ($real === false) http_fail(404, 'Failed to resolve file realpath');

// Build list of allowed roots (auto-detect common upload roots)
$possible_roots = [];

// prefer QAIT/uploads if present
if (!empty($_SERVER['DOCUMENT_ROOT'])) {
    $possible_roots[] = norm_path($_SERVER['DOCUMENT_ROOT'] . '/QAIT/uploads/operatordoc');
    $possible_roots[] = norm_path($_SERVER['DOCUMENT_ROOT'] . '/QAIT/uploads');
    $possible_roots[] = norm_path($_SERVER['DOCUMENT_ROOT'] . '/uploads/operatordoc');
    $possible_roots[] = norm_path($_SERVER['DOCUMENT_ROOT'] . '/uploads');
}

// relative to script
$possible_roots[] = norm_path(__DIR__ . '/uploads/operatordoc');
$possible_roots[] = norm_path(__DIR__ . '/uploads');
$possible_roots[] = norm_path(__DIR__ . '/../uploads');
$possible_roots[] = norm_path(__DIR__ . '/..'); // allow one level up (be careful in prod)

// filter false values and dedupe
$allowed_roots = [];
foreach ($possible_roots as $r) {
    if ($r && !in_array($r, $allowed_roots, true)) $allowed_roots[] = $r;
}

// Final security check: allow if real path starts with any allowed root.
// Also allow if the stored path itself contains '/uploads/' (looser fallback)
$ok = false;
foreach ($allowed_roots as $root) {
    if (!$root) continue;
    if (strpos($real, $root) === 0) { $ok = true; break; }
}
// Looser fallback: if stored_rel contains 'uploads/' consider allowed (for legacy paths)
if (!$ok && stripos($stored_rel, 'uploads/') !== false) $ok = true;

if (!$ok) {
    // helpful debug in JSON to let you fix server paths if needed
    http_fail(403, 'Access to this file is not allowed (real: '.$real.', allowed_roots: '.json_encode($allowed_roots).')');
}

// Build clean download name: Operator[_Branch]_DocKey
function san_name($s) {
    $s = (string)$s;
    $s = preg_replace('/[^\pL\pN\-_]+/u', '_', $s);
    $s = preg_replace('/__+/', '_', $s);
    $s = trim($s, '_-');
    return strtolower($s ?: 'file');
}
$opname = san_name($opname_raw);
$branch = $branch_raw ? '_'.san_name($branch_raw) : '';
$doc_label = san_name($docKey);
$downloadBase = $opname.$branch.'_'.$doc_label;

// Determine mime/ext
$ext = strtolower(pathinfo($local_path, PATHINFO_EXTENSION));
$mime = function_exists('mime_content_type') ? (mime_content_type($local_path) ?: 'application/octet-stream') : 'application/octet-stream';

// If already PDF → stream directly
if ($ext === 'pdf') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.$downloadBase.'.pdf"');
    header('Content-Length: '.filesize($local_path));
    $fp = fopen($local_path, 'rb');
    if ($fp) { while (!feof($fp)) { echo fread($fp, 8192); flush(); } fclose($fp); }
    exit;
}

// If Imagick available and file is an image → convert → PDF
$image_mimes = ['image/jpeg','image/png','image/jpg','image/gif','image/webp','image/tiff'];
if (class_exists('Imagick') && in_array($mime, $image_mimes, true)) {
    try {
        $im = new Imagick();
        $im->readImage($local_path);
        foreach ($im as $frame) {
            if ($frame->getImageAlphaChannel()) {
                $frame = $frame->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            }
        }
        $im->setImageFormat('pdf');
        $pdf_blob = $im->getImagesBlob();
        $im->clear();
        $im->destroy();

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.$downloadBase.'.pdf"');
        header('Content-Length: '.strlen($pdf_blob));
        echo $pdf_blob;
        exit;
    } catch (Exception $e) {
        // fallthrough to fallback
    }
}

// Fallback: stream original file
$orig = basename($local_path);
header('Content-Type: '.$mime);
header('Content-Disposition: attachment; filename="'.$downloadBase.'_'.$orig.'"');
header('Content-Length: '.filesize($local_path));
$fp = fopen($local_path, 'rb');
if ($fp) { while (!feof($fp)) { echo fread($fp, 8192); flush(); } fclose($fp); }
exit;
