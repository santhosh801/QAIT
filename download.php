<?php
/**
 * download.php
 * Secure file download endpoint (full modified version)
 *
 * Usage: download.php?id=<operator_row_id>&doc_key=<whitelisted_doc_key>
 *
 * Behavior:
 * - Requires employee session ($_SESSION['employee_email'])
 * - Whitelists document keys to avoid arbitrary file access
 * - Fetches operator_full_name, operator_id, branch_name and the requested file path
 * - Maps doc_key to a standardized label used in the downloaded filename
 * - Streams local files or proxies remote files via cURL (if available)
 *
 * NOTE: Adjust DB credentials and table/column names if your schema differs.
 */

session_start();

/* ---------------------------
   1) AUTHENTICATION
   --------------------------- */
if (!isset($_SESSION['employee_email'])) {
    header('HTTP/1.1 403 Forbidden');
    echo "Forbidden";
    exit;
}

/* ---------------------------
   2) INPUT VALIDATION
   --------------------------- */
if (!isset($_GET['id']) || !isset($_GET['doc_key'])) {
    header('HTTP/1.1 400 Bad Request');
    echo "Missing parameters";
    exit;
}

$id = (int) $_GET['id'];
$doc_key = preg_replace('/[^a-z0-9_]/i', '', $_GET['doc_key']); // allow only safe chars

/* ---------------------------
   3) WHITELIST / LABEL MAP
   --------------------------- */
$allowed = [
    'aadhar_file','pan_file','voter_file','ration_file','gps_selfie_file',
    'consent_file','police_verification_file','edu_10th_file','edu_12th_file','edu_college_file'
];

if (!in_array($doc_key, $allowed, true)) {
    header('HTTP/1.1 400 Bad Request');
    echo "Invalid document key";
    exit;
}

// Map DB doc_key => fixed label for filename
$docLabelMap = [
    'aadhar_file'             => 'aadhardoc',
    'pan_file'                => 'pandoc',
    'voter_file'              => 'voterdoc',
    'ration_file'             => 'rationdoc',
    'gps_selfie_file'         => 'gpsselfiedoc',
    'consent_file'            => 'consentdoc',
    'police_verification_file'=> 'policeverdoc',
    'edu_10th_file'           => 'edu10thdoc',
    'edu_12th_file'           => 'edu12thdoc',
    'edu_college_file'        => 'educollegedoc'
];

$label = isset($docLabelMap[$doc_key]) ? $docLabelMap[$doc_key] : $doc_key;

/* ---------------------------
   4) DB CONNECTION (adjust creds)
   --------------------------- */
$mysqli = new mysqli("localhost", "root", "", "qmit_system");
if ($mysqli->connect_error) {
    header('HTTP/1.1 500 Internal Server Error');
    echo "DB error";
    exit;
}

/* ---------------------------
   5) FETCH OPERATOR ROW (branch + id + file url)
   --------------------------- */
// Make sure the column names exist in your table. If branch is in another table, use JOIN.
$query = "SELECT operator_full_name, operator_id, branch_name, {$doc_key} AS file_url FROM operatordoc WHERE id = ? LIMIT 1";
$stmt = $mysqli->prepare($query);
if (!$stmt) {
    header('HTTP/1.1 500 Internal Server Error');
    echo "DB prepare failed";
    exit;
}
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$row || empty($row['file_url'])) {
    header('HTTP/1.1 404 Not Found');
    echo "File not found";
    exit;
}

$fileUrl = $row['file_url'];
$operatorFullName = $row['operator_full_name'] ?? 'operator';
$operatorId = $row['operator_id'] ?? $id;
$branchName = $row['branch_name'] ?? 'branch';

/* ---------------------------
   6) FILENAME SANITIZATION UTIL
   --------------------------- */
function safe_filename_component($s) {
    $s = trim((string)$s);
    // replace spaces with underscores
    $s = preg_replace('/\s+/', '_', $s);
    // allow letters, numbers, underscore, dash and dot (unicode letters allowed)
    $s = preg_replace('/[^\p{L}\p{N}\._\-]/u', '', $s);
    // collapse consecutive underscores/dashes
    $s = preg_replace('/[_\-]{2,}/', '_', $s);
    // limit length
    if (mb_strlen($s) > 80) $s = mb_substr($s, 0, 80);
    return $s ?: 'val';
}

/* ---------------------------
   7) BUILD FINAL FILENAME
   Format: <OperatorName>_<BranchName>_<Label>_<OperatorID>.<ext>
   --------------------------- */
$origPath = parse_url($fileUrl, PHP_URL_PATH);
$origFilename = $origPath ? basename($origPath) : '';
$origFilename = $origFilename ?: 'document';
$ext = pathinfo($origFilename, PATHINFO_EXTENSION);
$ext = $ext ? strtolower($ext) : '';

$safeName = safe_filename_component($operatorFullName);
$safeBranch = safe_filename_component($branchName);
$safeLabel = safe_filename_component($label);

$finalBase = "{$safeName}_{$safeBranch}_{$safeLabel}";
$finalName = $finalBase . ($operatorId ? "_{$operatorId}" : "");
$finalNameWithExt = $ext ? ($finalName . '.' . $ext) : $finalName;

/* ---------------------------
   8) RESOLVE LOCAL PATH OR REMOTE
   --------------------------- */
$localPath = null;
$parsed = parse_url($fileUrl);
if (!isset($parsed['scheme'])) {
    // try common locations
    $candidate = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . ltrim($fileUrl, '/');
    if (is_file($candidate)) $localPath = $candidate;
    $candidate2 = __DIR__ . '/' . ltrim($fileUrl, '/');
    if (!$localPath && is_file($candidate2)) $localPath = $candidate2;
} else {
    // remote URL (http/https)
    $localPath = null;
}

/* ---------------------------
   9) STREAM LOCAL FILE (preferred)
   --------------------------- */
if ($localPath && is_file($localPath) && is_readable($localPath)) {
    $fsize = filesize($localPath);
    $ctype = mime_content_type($localPath) ?: 'application/octet-stream';

    // security: avoid content sniffing and force download
    header('X-Content-Type-Options: nosniff');
    header('Content-Description: File Transfer');
    header('Content-Type: ' . $ctype);
    header('Content-Disposition: attachment; filename="' . $finalNameWithExt . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . $fsize);

    flush();
    $chunkSize = 8192;
    $handle = fopen($localPath, 'rb');
    if ($handle === false) {
        header('HTTP/1.1 500 Internal Server Error');
        echo "Cannot open file";
        exit;
    }
    while (!feof($handle)) {
        echo fread($handle, $chunkSize);
        flush();
    }
    fclose($handle);
    exit;
}

/* ---------------------------
   10) PROXY REMOTE FILE (if URL)
   --------------------------- */
if (isset($parsed['scheme']) && in_array($parsed['scheme'], ['http','https'])) {
    // Prefer curl streaming
    if (function_exists('curl_init')) {
        // First attempt to fetch headers (to detect content-type/length)
        $ch = curl_init($fileUrl);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $h = curl_exec($ch);
        $ctype = 'application/octet-stream';
        $fsize = null;
        if ($h !== false) {
            if (preg_match('/Content-Type:\s*([^\s;]+)/i', $h, $m)) $ctype = trim($m[1]);
            if (preg_match('/Content-Length:\s*(\d+)/i', $h, $m2)) $fsize = intval($m2[1]);
        }
        curl_close($ch);

        // Stream actual body
        $ch = curl_init($fileUrl);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0); // allow long downloads
        // safer: disable CURLOPT_BUFFERSIZE change if server doesn't like it
        // set headers for client
        header('X-Content-Type-Options: nosniff');
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $ctype);
        header('Content-Disposition: attachment; filename="' . $finalNameWithExt . '"');
        header('Pragma: public');
        header('Expires: 0');
        if ($fsize !== null) header('Content-Length: ' . $fsize);

        // stream callback
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
            echo $data;
            flush();
            return strlen($data);
        });

        curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err || $httpCode >= 400) {
            header('HTTP/1.1 502 Bad Gateway');
            echo "Unable to fetch remote file";
        }
        exit;
    } else {
        // fallback: redirect (browser will take remote filename — not ideal)
        header('Location: ' . $fileUrl);
        exit;
    }
}

/* ---------------------------
   11) IF REACH HERE: NOT FOUND
   --------------------------- */
header('HTTP/1.1 404 Not Found');
echo "File not available";
exit;
?>
