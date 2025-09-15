<?php
// export_operator.php
// Usage: export_operator.php?id=123
// Exports files for a single operator (id) into a ZIP.
// Converts images to PDF; renames files as: OperatorName_BranchName_DocLabel.pdf

set_time_limit(0);
ini_set('memory_limit', '1024M');

if (!isset($_GET['id'])) {
    http_response_code(400); echo "Missing id"; exit;
}
$id = (int)$_GET['id'];

// --- DB (match your connection) ---
$mysqli = new mysqli("localhost", "root", "", "qmit_system");
if ($mysqli->connect_error) { http_response_code(500); echo "DB connect error"; exit; }

// --- doc keys + friendly labels (adjust if you need more/different keys) ---
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

// fetch operator row
$stmt = $mysqli->prepare("SELECT * FROM operatordoc WHERE id = ? LIMIT 1");
$stmt->bind_param('i',$id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row) { http_response_code(404); echo "Operator not found"; exit; }

// Helper: sanitize string for filename
function safeName($s){
    $s = trim((string)$s);
    // replace spaces, slashes, special chars
    $s = preg_replace('/[^\w\s\-\.]/u', '', $s);
    $s = preg_replace('/\s+/', '_', $s);
    return substr($s,0,180);
}

// Helper: safe path join
function join_paths() {
    $paths = array();
    foreach (func_get_args() as $arg) {
        if ($arg !== '') $paths[] = rtrim($arg, '/\\');
    }
    return join(DIRECTORY_SEPARATOR, $paths);
}

// conversion helpers
function imageToPdf_imagick($src, $dst) {
    try {
        $im = new Imagick();
        // read image(s)
        $im->readImage($src);
        // Set format to PDF
        $im->setImageFormat('pdf');
        // flatten and set quality if needed
        $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $im->setImageBackgroundColor('white');
        $im->writeImages($dst, true);
        $im->clear(); $im->destroy();
        return file_exists($dst);
    } catch (Exception $e) {
        error_log("Imagick conversion failed: " . $e->getMessage());
        return false;
    }
}

function imageToPdf_fpdi_gd($src, $dst) {
    // Fallback minimal: use GD to ensure image exists, then use basic PDF with image.
    // We'll use a tiny FPDF generator implemented inline to avoid external deps.
    if (!file_exists($src)) return false;
    $info = getimagesize($src);
    if (!$info) return false;
    $w = $info[0]; $h = $info[1];
    // We'll use the very small FPDF implementation below:
    // Create a simple PDF with a full-page image.
    // NOTE: This is a very simple approach and may not handle CMYK/tiff.
    $imgExt = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    // Convert non-JPEG/PNG to PNG via GD if possible
    if (!in_array($imgExt, ['jpg','jpeg','png'])) {
        if (function_exists('imagecreatefromstring')) {
            $data = file_get_contents($src);
            $im = @imagecreatefromstring($data);
            if ($im) {
                $tmp = tempnam(sys_get_temp_dir(), 'pdfimg_') . '.png';
                imagepng($im, $tmp);
                imagedestroy($im);
                $src = $tmp;
                $imgExt = 'png';
            }
        }
    }
    // Basic FPDF file creation - write a very simple PDF and embed image as a JPEG/PNG stream.
    // We'll use a tiny libraryless generator approach for single-image PDFs.
    $pdf = "%PDF-1.3\n";
    $objects = [];
    $xrefs = [];
    $pos = strlen($pdf);

    // create image stream
    $imgdata = file_get_contents($src);
    if ($imgdata === false) return false;
    $filter = '';
    $colorspace = '';
    $bits = 8;
    if (in_array($imgExt, ['jpg','jpeg'])) {
        // embed as DCTDecode
        $filter = '/Filter /DCTDecode';
        $imgdict = "<< /Type /XObject /Subtype /Image /Width {$w} /Height {$h} /ColorSpace /DeviceRGB /BitsPerComponent {$bits} {$filter} /Length " . strlen($imgdata) . " >>\nstream\n";
        $objects[] = [$imgdict, $imgdata . "\nendstream\n"];
    } else {
        // PNG -> attempt to convert to RGB raw? Simpler: embed PNG as stream and let PDF viewer handle it (not standard)
        // Instead, fallback: convert PNG to JPEG via GD if available
        if (function_exists('imagecreatefrompng') && function_exists('imagejpeg')) {
            $im = imagecreatefrompng($src);
            $tmpjpg = tempnam(sys_get_temp_dir(), 'pdfimg_') . '.jpg';
            imagejpeg($im, $tmpjpg, 90);
            imagedestroy($im);
            $imgdata = file_get_contents($tmpjpg);
            unlink($tmpjpg);
            $filter = '/Filter /DCTDecode';
            $objects[] = ["<< /Type /XObject /Subtype /Image /Width {$w} /Height {$h} /ColorSpace /DeviceRGB /BitsPerComponent {$bits} {$filter} /Length " . strlen($imgdata) . " >>\nstream\n", $imgdata . "\nendstream\n"];
        } else {
            return false;
        }
    }

    // object 1: image
    $imgIndex = 1;
    $objnum = $imgIndex;
    $xrefs[$objnum] = $pos;
    $pdf .= ($objnum) . " 0 obj\n" . $objects[$imgIndex-1][0] . $objects[$imgIndex-1][1] . "endobj\n";
    $pos = strlen($pdf);

    // xobject name / resources and page
    $imgRef = "{$objnum} 0 R";
    $pageObjNum = $imgIndex + 1;
    $xrefs[$pageObjNum] = $pos;
    // page content stream: place image to fill page (A4). Compute scale.
    // We'll place the image at 0,0 and size proportionally to width A4 595x842
    $a4w = 595;
    $a4h = 842;
    $scaleW = $a4w;
    $scaleH = $a4w * ($h / $w);
    if ($scaleH > $a4h) {
        $scaleH = $a4h;
        $scaleW = $a4h * ($w / $h);
    }
    $tx = 0;
    $ty = $a4h - $scaleH;
    $contents = "q\n{$scaleW} 0 0 {$scaleH} {$tx} {$ty} cm\n/Im0 Do\nQ\n";
    $contentsStream = "<<" . "/Length " . strlen($contents) . ">>\nstream\n" . $contents . "\nendstream\n";

    // resources object
    $resObjNum = $pageObjNum + 1;
    $xrefs[$resObjNum] = $pos + strlen($contentsStream);
    $res = "<< /XObject << /Im0 {$imgRef} >> /ProcSet [/PDF /ImageC] >>\n";
    // page object
    $pdf .= ($pageObjNum) . " 0 obj\n<< /Type /Page /Parent 3 0 R /Resources " . ($resObjNum) . " 0 R /MediaBox [0 0 {$a4w} {$a4h}] /Contents " . ($pageObjNum+2) . " 0 R >>\nendobj\n";
    // resources
    $pdf .= ($resObjNum) . " 0 obj\n" . $res . "endobj\n";
    // contents
    $contentObjNum = $pageObjNum+2;
    $xrefs[$contentObjNum] = strlen($pdf);
    $pdf .= $contentObjNum . " 0 obj\n" . $contentsStream . "endobj\n";

    // page tree and catalog
    $pagesObjNum = $contentObjNum+1;
    $xrefs[$pagesObjNum] = strlen($pdf);
    $pdf .= $pagesObjNum . " 0 obj\n<< /Type /Pages /Kids [" . $pageObjNum . " 0 R] /Count 1 >>\nendobj\n";

    $catalogObjNum = $pagesObjNum+1;
    $xrefs[$catalogObjNum] = strlen($pdf);
    $pdf .= $catalogObjNum . " 0 obj\n<< /Type /Catalog /Pages " . $pagesObjNum . " 0 R >>\nendobj\n";

    // xref
    $xrefPos = strlen($pdf);
    $pdf .= "xref\n0 " . ($catalogObjNum+1) . "\n0000000000 65535 f \n";
    for ($i=1; $i<=$catalogObjNum; $i++) {
        $off = isset($xrefs[$i]) ? str_pad($xrefs[$i],10,'0',STR_PAD_LEFT) : str_repeat('0',10);
        $pdf .= $off . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . ($catalogObjNum+1) . " /Root " . $catalogObjNum . " 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";
    file_put_contents($dst, $pdf);
    return file_exists($dst);
}

// Converts an arbitrary file to PDF and returns path to PDF or false
function convertToPdf($srcPath, $targetPdfPath) {
    // If already PDF, just copy
    $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        return copy($srcPath, $targetPdfPath) ? $targetPdfPath : false;
    }

    // Imagick preferred
    if (class_exists('Imagick')) {
        if (imageToPdf_imagick($srcPath, $targetPdfPath)) return $targetPdfPath;
    }

    // Fallback
    if (function_exists('imagecreatefromstring')) {
        if (imageToPdf_fpdi_gd($srcPath, $targetPdfPath)) return $targetPdfPath;
    }

    return false;
}

// Build the export folder
$opName = safeName($row['operator_full_name'] ?? ('operator_'.$id));
$branchName = safeName($row['branch_name'] ?? 'branch');
$folderName = "{$opName}_{$branchName}_Documents";

$tmpBase = sys_get_temp_dir();
$uniq = uniqid('export_op_', true);
$workDir = join_paths($tmpBase, $uniq);
@mkdir($workDir, 0700, true);

// create folder inside workDir
$opFolder = join_paths($workDir, $folderName);
@mkdir($opFolder, 0700, true);

// collect files and convert
$addedAny = false;
foreach ($DOC_KEYS as $docKey => $label) {
    $val = $row[$docKey] ?? '';
    if (!$val) continue;
    // val may be a relative path or URL. Prefer server path if relative.
    $src = $val;
    // if URL-like, attempt to map to local path if domain matches; otherwise skip
    if (preg_match('#^https?://#i', $src)) {
        // attempt to map to local path if it's same host and points to local file folder
        // otherwise skip: better to not fetch remote files here.
        // For now, we'll try to see if path contains '/uploads/' -> infer local file
        if (strpos($src, $_SERVER['HTTP_HOST'] ?? '') !== false) {
            // strip protocol & host
            $parsed = parse_url($src);
            $src = $_SERVER['DOCUMENT_ROOT'] . ($parsed['path'] ?? '');
        } else {
            // skip remote files – you could implement curl download here if needed
            continue;
        }
    } else {
        // Path may be relative to script root
        if (!file_exists($src)) {
            // try relative to document root
            $candidate = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . ltrim($src, '/\\');
            if (file_exists($candidate)) $src = $candidate;
        }
    }

    if (!file_exists($src)) continue;

    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    $targetName = "{$opName}_{$branchName}_{$label}.pdf";
    $targetPath = join_paths($opFolder, $targetName);

    $ok = convertToPdf($src, $targetPath);
    if ($ok) { $addedAny = true; }
}

// If nothing added, return error
if (!$addedAny) {
    // cleanup
    // (remove workDir)
    function rrmdir($dir){ if (!is_dir($dir)) return; foreach(scandir($dir) as $f){ if ($f==='.'||$f==='..') continue; $p=$dir.DIRECTORY_SEPARATOR.$f; if(is_dir($p)) rrmdir($p); else @unlink($p);} @rmdir($dir); }
    rrmdir($workDir);
    http_response_code(404);
    echo "No documents found for operator.";
    exit;
}

// Prepare ZIP
$zipPath = join_paths(sys_get_temp_dir(), uniqid('opzip_') . '.zip');
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE)!==TRUE) {
    rrmdir($workDir);
    http_response_code(500); echo "Could not create zip"; exit;
}

// add folder with its files
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($opFolder));
foreach ($files as $file) {
    if (!$file->isFile()) continue;
    $filePath = $file->getRealPath();
    $localPath = $folderName . '/' . ltrim(str_replace($opFolder, '', $filePath), '/\\');
    $zip->addFile($filePath, $localPath);
}
$zip->close();

// Stream ZIP to client
$dlName = "{$folderName}.zip";
header('Content-Type: application/zip');
header('Content-Length: ' . filesize($zipPath));
header('Content-Disposition: attachment; filename="'.$dlName.'"');
readfile($zipPath);

// cleanup
@unlink($zipPath);
function rrmdir_public($dir){ if (!is_dir($dir)) return; foreach(scandir($dir) as $f){ if ($f==='.'||$f==='..') continue; $p=$dir.DIRECTORY_SEPARATOR.$f; if(is_dir($p)) rrmdir_public($p); else @unlink($p);} @rmdir($dir); }
rrmdir_public($workDir);
exit;
