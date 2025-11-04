<?php
// OperatorBulkUploadDocumentsText.php
// ✅ Final unified version — directly updates `operatordoc` (no op_docs)
// Supports: single_operator | bulk_text | bulk_docs
// Safe ZIP handling, automatic keyword matching, MySQLi integration

date_default_timezone_set('Asia/Kolkata');

// ---------- CONFIG ----------
$BASE = __DIR__;
$UPLOAD_ROOT = $BASE . '/uploads';
$TMP_DIR = $UPLOAD_ROOT . '/tmp';
$BULK_DIR = $UPLOAD_ROOT . '/bulk';
$BULK_DOCS_DIR = $UPLOAD_ROOT . '/bulk_docs';
$OPERATORS_DIR = $UPLOAD_ROOT . '/operators';
$WEB_BASE = 'uploads/operators/';

// ---------- DATABASE ----------
$mysqli = new mysqli("localhost", "root", "", "qmit_system");
if ($mysqli->connect_error) {
    die(json_encode(['success' => false, 'message' => 'DB connection failed: ' . $mysqli->connect_error]));
}
$mysqli->set_charset("utf8mb4");

// ---------- UTILITIES ----------
function json_response($success, $msg, $extra = [])
{
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $msg], $extra));
    exit;
}

function safe_name($s)
{
    return preg_replace('/[^A-Za-z0-9_\-]/', '_', trim($s));
}

function ensure_dir($path)
{
    if (!is_dir($path)) mkdir($path, 0755, true);
}

function ensure_operator_row($mysqli, $opid)
{
    $stmt = $mysqli->prepare("INSERT IGNORE INTO operatordoc (operator_id, created_at) VALUES (?, NOW())");
    $stmt->bind_param("s", $opid);
    $stmt->execute();
    $stmt->close();
}

/* ---------- NEW: CSV hardeners (dates + numbers) ---------- */
function norm_date(string $s): ?string
{
    $s = trim($s);
    if ($s === '' || $s === '0000-00-00') return null;

    // Excel serial (days since 1899-12-30)
    if (is_numeric($s) && (float)$s > 59) {
        $base = new DateTime('1899-12-30');
        $base->modify('+' . (int)$s . ' days');
        return $base->format('Y-m-d');
    }

    $fmts = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'j-m-Y', 'j/m/Y', 'd M Y', 'M d, Y'];
    foreach ($fmts as $f) {
        $dt = DateTime::createFromFormat($f, $s);
        if ($dt && $dt->format($f) === $s) return $dt->format('Y-m-d');
    }
    $ts = strtotime($s);
    return $ts ? date('Y-m-d', $ts) : null;
}
function digits_only(?string $s): string
{
    return preg_replace('/\D+/', '', (string)$s ?? '');
}
function upper(?string $s): string
{
    return strtoupper(trim((string)$s ?? ''));
}

// Keyword map (matches filenames)
function map_filename_to_field($filename)
{
    $f = strtolower($filename);
    $map = [
        'aadhar' => 'aadhar_file',
        'aadhaar' => 'aadhar_file',
        'adhar' => 'aadhar_file',
        'uid' => 'aadhar_file',
        'parent' => 'parent_aadhar_file',
        'father' => 'parent_aadhar_file',
        'mother' => 'parent_aadhar_file',
        'selfdeclaration' => 'self_declaration_file',
        'declaration' => 'self_declaration_file',
        'pan' => 'pan_file',
        'pancard' => 'pan_file',
        'pannumber' => 'pan_file',
        'voter' => 'voter_file',
        'election' => 'voter_file',
        'ration' => 'ration_file',
        'familycard' => 'ration_file',
        'consent' => 'consent_file',
        'agreement' => 'consent_file',
        'approval' => 'consent_file',
        'gps' => 'gps_selfie_file',
        'selfie' => 'gps_selfie_file',
        'geotag' => 'gps_selfie_file',
        'location' => 'gps_selfie_file',
        'police' => 'police_verification_file',
        'verification' => 'police_verification_file',
        'background' => 'police_verification_file',
        'address' => 'permanent_address_proof_file',
        'residence' => 'permanent_address_proof_file',
        'proof' => 'permanent_address_proof_file',
        'permanent' => 'permanent_address_proof_file',
        'nseit' => 'nseit_cert_file',
        'nseitcert' => 'nseit_cert_file',

        'nda' => 'non_disclosure_file',
        'non_disclosure' => 'non_disclosure_file',
        'confidential' => 'non_disclosure_file',
        '10th' => 'edu_10th_file',
        'sslc' => 'edu_10th_file',
        'matric' => 'edu_10th_file',
        '12th' => 'edu_12th_file',
        'hsc' => 'edu_12th_file',
        'plus2' => 'edu_12th_file',
        'college' => 'edu_college_file',
        'degree' => 'edu_college_file',
        'graduation' => 'edu_college_file',
        'btech' => 'edu_college_file'
    ];
    foreach ($map as $kw => $col) {
        if (strpos($f, $kw) !== false) return $col;
    }
    return null;
}

// ---------- CORE UPDATE ----------
function update_operatordoc($mysqli, $operator_id, $mappedFiles)
{
    if (empty($mappedFiles)) return false;
    ensure_operator_row($mysqli, $operator_id);

    $sets = [];
    $params = [];
    $types = '';

    foreach ($mappedFiles as $col => $path) {
        $sets[] = "`$col` = ?";
        $params[] = $path;
        $types .= 's';
    }
    $params[] = $operator_id;
    $types .= 's';

    $sql = "UPDATE operatordoc SET " . implode(', ', $sets) . " WHERE operator_id = ?";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param($types, ...$params);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// ---------- MAIN LOGIC ----------
$caseType = $_POST['caseType'] ?? ($_REQUEST['caseType'] ?? '');
// ---------- TEMPLATE DOWNLOAD (full header version for one operator) ----------
function serve_download_template_and_exit()
{
    $headers = [
        "id",
        "operator_id",
        "operator_full_name",
        "email",
        "branch_name",
        "joining_date",
        "operator_contact_no",
        "father_name",
        "dob",
        "gender",
        "aadhar_number",
        "pan_number",
        "voter_id_no",
        "ration_card",
        "nseit_number",
        "aadhar_file",
        "pan_file",
        "voter_file",
        "ration_file",
        "consent_file",
        "gps_selfie_file",
        "permanent_address_proof_file",
        "nseit_cert_file",
        "self_declaration_file",
        "non_disclosure_file",
        "police_verification_file",
        "parent_aadhar_file",
        "alt_contact_relation",
        "alt_contact_number",
        "edu_10th_file",
        "edu_12th_file",
        "edu_college_file",
        "created_at",
        "nseit_date",
        "current_hno_street",
        "current_village_town",
        "current_pincode",
        "current_postoffice",
        "current_district",
        "current_state",
        "permanent_hno_street",
        "permanent_village_town",
        "permanent_pincode",
        "permanent_postoffice",
        "permanent_district",
        "permanent_state",
        "bank_name",
        "status",
        "work_status",
        "review_notes",
        "rejection_summary",
        "last_modified_at"
    ];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="operator_full_template.csv"');
    $out = fopen('php://output', 'w');
    // BOM for Excel
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    fclose($out);
    exit;
}

// If requested via GET param, serve template and stop further processing
if (isset($_GET['download_template']) && ($_GET['download_template'] == '1' || $_GET['download_template'] === 'true')) {
    serve_download_template_and_exit();
}

switch ($caseType) {

    // --- SINGLE OPERATOR UPLOAD ---
    case 'single_operator':
        $operator_id = safe_name($_POST['operator_id'] ?? '');
        if (!$operator_id) json_response(false, 'Missing operator_id');

        $opDir = "$OPERATORS_DIR/$operator_id/docs";
        ensure_dir($opDir);

        $files = $_FILES['operator_docs'] ?? null;
        $mappedFiles = [];
        $count = 0;

        if ($files && is_array($files['name'])) {
            foreach ($files['name'] as $i => $name) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;

                $origName = basename($name);
                $ext = pathinfo($origName, PATHINFO_EXTENSION);
                $stem = pathinfo($origName, PATHINFO_FILENAME);
                $safeStem = preg_replace('/[^A-Za-z0-9_-]/', '_', $stem);
                $ts = time();

                // unique filename to bust browser cache and avoid collisions
                $unique = $safeStem . '_' . $ts . ($ext ? '.' . $ext : '');
                $dest = "$opDir/$unique";
                ensure_dir(dirname($dest));

                // move upload
                if (!move_uploaded_file($files['tmp_name'][$i], $dest)) {
                    error_log("move_uploaded_file failed for $origName");
                    continue;
                }
                $count++;

                // map to DB column if keyword present
                $col = map_filename_to_field($origName);
                if ($col) {
                    // store the web-path (consistent with WEB_BASE)
                    $mappedFiles[$col] = $WEB_BASE . "$operator_id/docs/$unique";
                }
            }
        }

        if (update_operatordoc($mysqli, $operator_id, $mappedFiles)) {
            json_response(true, "Uploaded $count docs for $operator_id", ['mapped' => $mappedFiles]);
        } else {
            json_response(true, "Files saved but DB update failed", ['mapped' => $mappedFiles]);
        }
        break;
        

    // --- BULK TEXT (CSV) ---
    case 'bulk_text':
        $file = $_FILES['bulk_excel'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) json_response(false, 'Missing bulk_excel');

        ensure_dir($BULK_DIR);
        $dest = "$BULK_DIR/" . time() . "_" . basename($file['name']);
        move_uploaded_file($file['tmp_name'], $dest);

        $rows = 0;
        $updated = 0;
        $skipped_no_id = 0;

        if (($handle = fopen($dest, 'r')) !== false) {
            // read header row (non-empty)
            $header = fgetcsv($handle);
            if ($header === false) {
                fclose($handle);
                json_response(false, 'CSV header missing or empty');
            }

            // normalize headers: trim + lowercase + keep original names for mapping keys
            $cols = array_map(function ($h) {
                return strtolower(trim($h));
            }, $header);

            // Allowed non-file DB columns to update
            $updatable = [
                'operator_full_name',
                'email',
                'branch_name',
                'joining_date',
                'operator_contact_no',
                'father_name',
                'dob',
                'gender',
                'aadhar_number',
                'pan_number',
                'voter_id_no',
                'ration_card',
                'nseit_number',
                'alt_contact_relation',
                'alt_contact_number',
                'created_at',
                'nseit_date',
                'current_hno_street',
                'current_village_town',
                'current_pincode',
                'current_postoffice',
                'current_district',
                'current_state',
                'permanent_hno_street',
                'permanent_village_town',
                'permanent_pincode',
                'permanent_postoffice',
                'permanent_district',
                'permanent_state',
                'bank_name',
                'status',
                'work_status',
                'review_notes',
                'rejection_summary',
                'last_modified_at'
            ];

            while (($row = fgetcsv($handle)) !== false) {
                $rows++;
                // build assoc: normalized header => value
                $assoc = [];
                foreach ($cols as $i => $col) {
                    $assoc[$col] = isset($row[$i]) ? trim($row[$i]) : '';
                }

                // Friendly header mapping (accept user-friendly template variants)
                // operator_name -> operator_full_name ; phone -> operator_contact_no
                if (isset($assoc['operator_name']) && !isset($assoc['operator_full_name'])) {
                    $assoc['operator_full_name'] = $assoc['operator_name'];
                }
                if (isset($assoc['phone']) && !isset($assoc['operator_contact_no'])) {
                    $assoc['operator_contact_no'] = $assoc['phone'];
                }

                // Primary key for this update
                $operator_id_raw = $assoc['operator_id'] ?? '';
                $operator_id = safe_name($operator_id_raw);

                if (!$operator_id) {
                    $skipped_no_id++;
                    continue;
                }

                // ensure row exists
                ensure_operator_row($mysqli, $operator_id);

                /* ---------- NEW: sanitize problematic fields before building SETs ---------- */

                // Dates → ISO or NULL
                if (array_key_exists('joining_date', $assoc))   $assoc['joining_date']   = norm_date($assoc['joining_date']) ?? null;
                if (array_key_exists('dob', $assoc))             $assoc['dob']            = norm_date($assoc['dob']) ?? null;
                if (array_key_exists('nseit_date', $assoc))      $assoc['nseit_date']     = norm_date($assoc['nseit_date']) ?? null;
                if (array_key_exists('created_at', $assoc))      $assoc['created_at']     = norm_date($assoc['created_at']) ?? null;
                if (array_key_exists('last_modified_at', $assoc)) $assoc['last_modified_at'] = norm_date($assoc['last_modified_at']) ?? null;

                // Numbers → digits only (Excel E+ kill)
                if (array_key_exists('operator_contact_no', $assoc)) $assoc['operator_contact_no'] = digits_only($assoc['operator_contact_no']);
                if (array_key_exists('alt_contact_number', $assoc))   $assoc['alt_contact_number']  = digits_only($assoc['alt_contact_number']);
                if (array_key_exists('aadhar_number', $assoc)) {
                    $aad = preg_replace('/[^0-9]/', '', (string)$assoc['aadhar_number']);
                    // restore lost leading zeros if Aadhaar looks too short (Excel-trimmed)
                    if (strlen($aad) !== 12) {
                        $aad = str_pad(substr($aad, 0, 12), 12, '0', STR_PAD_LEFT);
                    }

                    $assoc['aadhar_number'] = $aad;
                }

                if (array_key_exists('current_pincode', $assoc))      $assoc['current_pincode']     = digits_only($assoc['current_pincode']);
                if (array_key_exists('permanent_pincode', $assoc))    $assoc['permanent_pincode']   = digits_only($assoc['permanent_pincode']);

                // IDs/Text normalization
                if (array_key_exists('pan_number', $assoc))     $assoc['pan_number']     = upper($assoc['pan_number']);
                if (array_key_exists('voter_id_no', $assoc))    $assoc['voter_id_no']    = upper($assoc['voter_id_no']);
                if (array_key_exists('ration_card', $assoc))    $assoc['ration_card']    = upper($assoc['ration_card']);
                if (array_key_exists('bank_name', $assoc))      $assoc['bank_name']      = trim($assoc['bank_name']);

                // Build dynamic update from $updatable fields present in CSV
                $sets = [];
                $params = [];
                $types = '';
                foreach ($updatable as $col) {
                    if (array_key_exists($col, $assoc)) {
                        // skip only real empty strings; allow NULL (for dates) and non-empty values
                        if ($assoc[$col] === '') continue;
                        $sets[]   = "`$col` = ?";
                        $params[] = $assoc[$col];   // may be string or NULL
                        $types   .= 's';
                    }
                }

                if (!empty($sets)) {
                    $params[] = $operator_id;
                    $types .= 's';
                    $sql = "UPDATE operatordoc SET " . implode(', ', $sets) . " WHERE operator_id = ?";
                    $stmt = $mysqli->prepare($sql);
                    if ($stmt) {
                        // PHP mysqli will pass actual NULL if param is null (type 's' is okay)
                        $stmt->bind_param($types, ...$params);
                        $stmt->execute();
                        $stmt->close();
                        $updated++;
                    }
                }
            }

            fclose($handle);
        }

        json_response(true, "Processed rows: $rows, updated: $updated, skipped(no operator_id): $skipped_no_id", ['file' => $dest]);
        break;

    // --- BULK DOCS (ZIP of operator folders) ---
    case 'bulk_docs':
        $zip = $_FILES['bulk_docs'] ?? null;
        if (!$zip || $zip['error'] !== UPLOAD_ERR_OK) json_response(false, 'No bulk_docs ZIP');

        $ext = strtolower(pathinfo($zip['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') json_response(false, 'Only ZIP allowed');

        $ts = time();
        ensure_dir($BULK_DOCS_DIR);
        $zipPath = "$BULK_DOCS_DIR/bulk_$ts.zip";
        move_uploaded_file($zip['tmp_name'], $zipPath);

        $extractDir = "$BULK_DOCS_DIR/extract_$ts";
        ensure_dir($extractDir);

        $za = new ZipArchive();
        if ($za->open($zipPath) !== true) json_response(false, 'Unable to open ZIP');
        $za->extractTo($extractDir);
        $za->close();

        $operators = scandir($extractDir);
        $summary = [];
        foreach ($operators as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            $opid = safe_name($dir);
            $src = "$extractDir/$dir";
            if (!is_dir($src)) continue;

            $dest = "$OPERATORS_DIR/$opid/docs";
            ensure_dir($dest);

            $mappedFiles = [];
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $origName = basename($file);
                    $ext = pathinfo($origName, PATHINFO_EXTENSION);
                    $stem = pathinfo($origName, PATHINFO_FILENAME);
                    $safeStem = preg_replace('/[^A-Za-z0-9_-]/', '_', $stem);
                    $ts = time();

                    // unique filename to avoid collisions and ensure UI refresh
                    $unique = $safeStem . '_' . $ts . ($ext ? '.' . $ext : '');
                    $target = "$dest/$unique";
                    ensure_dir(dirname($target));

                    if (!copy($file->getPathname(), $target)) {
                        error_log("copy failed for {$file->getPathname()} to $target");
                        continue;
                    }

                    $col = map_filename_to_field($origName);
                    if ($col) {
                        $mappedFiles[$col] = $WEB_BASE . "$opid/docs/$unique";
                    }
                }
            }

            update_operatordoc($mysqli, $opid, $mappedFiles);
            $summary[$opid] = $mappedFiles;
        }

        json_response(true, 'Bulk upload completed', ['summary' => $summary]);
        break;

    default:
        json_response(false, 'Invalid caseType');
}
