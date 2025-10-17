<?php
// download_resubmission.php
// Convert requested resubmission docs to PDF (when possible with Imagick),
// place them in a ZIP and stream to browser. Assumes session auth.

session_start();
if (!isset($_SESSION['employee_email'])) {
    http_response_code(401); echo "Unauthorized"; exit;
}

$mysqli = new mysqli('localhost','root','','qmit_system');
if ($mysqli->connect_errno) { http_response_code(500); echo "DB connect failed"; exit; }
$mysqli->set_charset('utf8mb4');

$request_id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
if ($request_id <= 0 && $token === '') { http_response_code(400); echo "Missing request_id or token"; exit; }

if ($request_id > 0) {
    $stmt = $mysqli->prepare("SELECT * FROM resubmission_requests WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $request_id);
} else {
    $stmt = $mysqli->prepare("SELECT * FROM resubmission_requests WHERE token = ? LIMIT 1");
    $stmt->bind_param('s', $token);
}
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$req) { http_response_code(404); echo "Resubmission request not found"; exit; }

// expiry check
if (!empty($req['expires_at'])) {
    try {
        $now = new DateTime();
        $exp = new DateTime($req['expires_at']);
        if ($now > $exp) { http_response_code(410); echo "Link expired"; exit; }
    } catch (Exception $e) {}
}

// fetch operator
$opId = (int)$req['operator_id'];
$stmt = $mysqli->prepare("SELECT * FROM operatordoc WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $opId);
$stmt->execute();
$opRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$opRow) { http_response_code(404); echo "Operator not found"; exit; }

// requested docs
$docs = [];
if (!empty($req['docs_json'])) {
    $tmp = json_decode($req['docs_json'], true);
    if (is_array($tmp)) $docs = $tmp;
}
if (empty($docs) && !empty($opRow['rejection_summary'])) {
    foreach (preg_split('/\r\n|\r|\n/', $opRow['rejection_summary']) as $l) {
        $l = trim($l);
        if ($l !== '') $docs[] = $l;
    }
}
if (empty($docs)) { http_response_code(404); echo "No resubmission documents recorded"; exit; }

// label mapping for friendly labels
$label_map = [
  'aadhar_file'=>'Aadhaar','pan_file'=>'PAN','voter_file'=>'VoterID','ration_file'=>'RationCard',
  'consent_file'=>'Consent','gps_selfie_file'=>'GPS_Selfie','police_verification_file'=>'PoliceVerification',
  'permanent_address_proof_file'=>'AddressProof','parent_aadhar_file'=>"ParentAadhaar",'nseit_cert_file'=>'NSEIT',
  'self_declaration_file'=>'SelfDeclaration','non_disclosure_file'=>'NDA','edu_10th_file'=>'10th',
  'edu_12th_file'=>'12th','edu_college_file'=>'College','agreement_file'=>'Agreement',
  'bank_passbook_file'=>'BankPassbook','photo_file'=>'Photo'
];

// sanitizer
function sanit($s){
    $s = (string)$s;
    $s = preg_replace('/[^A-Za-z0-9_\-]/','_', trim($s));
    $s = preg_replace('/_+/', '_', $s);
    return $s ?: 'unknown';
}

$branch = sanit($opRow['branch_name'] ?? 'branch');
$opname = sanit($opRow['operator_full_name'] ?: $opRow['operator_id']);

// create temporary zip
$tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'resub_' . time() . '_' . bin2hex(random_bytes(6)) . '.zip';
$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE) !== true) {
    http_response_code(500); echo "Failed to create temporary zip"; exit;
}

$added = 0;
$useImagick = class_exists('Imagick');
$tmpCreatedFiles = []; // cleanup list

foreach ($docs as $docKey) {
    if (!isset($opRow[$docKey]) || empty($opRow[$docKey])) continue;
    $filePath = $opRow[$docKey]; // e.g. uploads/operatordoc/...
    $real = realpath(__DIR__ . DIRECTORY_SEPARATOR . $filePath);
    if (!$real || !file_exists($real)) continue;

    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    $label = $label_map[$docKey] ?? $docKey;
    $baseName = "{$branch}_{$opname}_{$label}_resub";
    $targetName = $baseName . '.pdf'; // we want PDF filename inside zip

    // If file is already PDF -> add directly
    if ($ext === 'pdf') {
        $zip->addFile($real, $targetName);
        $added++;
        continue;
    }

    // Try converting anything via Imagick to PDF (images and other readable formats)
    if ($useImagick) {
        try {
            $im = new Imagick();
            // Read the file (Imagick will attempt to parse many formats)
            $im->readImage($real);

            // set PDF properties: RGB & single-page per original frames/pages
            $im->setImageColorspace(Imagick::COLORSPACE_RGB);
            // Flatten multi-layered images to single page if needed
            if ($im->getNumberImages() > 1) {
                $im = $im->coalesceImages();
            }

            // write to a temp PDF
            $tmpPdf = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $baseName . '_' . bin2hex(random_bytes(5)) . '.pdf';
            // ensure format
            $im->setImageFormat('pdf');
            // write
            if ($im->writeImages($tmpPdf, true) === false) {
                // fallback - will try later to add original
                $im->clear(); $im->destroy();
                throw new Exception('Imagick writeImages failed');
            }
            $im->clear(); $im->destroy();

            // add pdf to zip
            $zip->addFile($tmpPdf, $targetName);
            $tmpCreatedFiles[] = $tmpPdf;
            $added++;
            continue;
        } catch (Exception $e) {
            // conversion failed; fall through to add original as fallback
        }
    }

    // Fallback: add original file but with .pdf name (not converted) if conversion not possible.
    // Safer: add original with its original ext (so user can open), but you requested PDF-named files.
    // We'll add the original and also include a small filename note: keep original ext appended.
    $origExt = $ext ?: 'bin';
    $fallbackName = $baseName . "_orig." . $origExt;
    $zip->addFile($real, $fallbackName);
    $added++;
}

// close zip
$zip->close();

if ($added === 0) {
    @unlink($tmpZip);
    http_response_code(404); echo "No files found for requested docs"; exit;
}

// Stream ZIP
$downloadName = "{$branch}_{$opname}_resub_" . date('Ymd_His') . ".zip";
if (!file_exists($tmpZip)) { http_response_code(500); echo "Temp zip missing"; exit; }

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Pragma: public');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
readfile($tmpZip);

// cleanup
@unlink($tmpZip);
foreach ($tmpCreatedFiles as $f) { @unlink($f); }
exit;
