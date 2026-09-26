<?php
// provider-setup.php - Provider profile completion & verification
$appRoot = dirname(__DIR__);
chdir($appRoot);
session_start();
require_once 'config/config.php';
require_once 'config/database.php';
require_once appPath('includes/provider_verification_helper.php');

// Check if user is logged in and is a provider
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'provider') {
    header("Location: " . appUrl('login.php'));
    exit();
}

$database = new Database();
$db = $database->getConnection();
ensureProviderVerificationWorkflow($db);
syncProviderVerificationStatus($db);
$user_id = $_SESSION['user_id'];

function getCaviteCityPostalMap() {
    return [
        'Alfonso' => '4123',
        'Amadeo' => '4119',
        'Bacoor' => '4102',
        'Carmona' => '4116',
        'Cavite City' => '4100',
        'Dasmarinas' => '4114',
        'General Emilio Aguinaldo' => '4124',
        'General Mariano Alvarez' => '4117',
        'General Trias' => '4107',
        'Imus' => '4103',
        'Indang' => '4122',
        'Kawit' => '4104',
        'Magallanes' => '4113',
        'Maragondon' => '4112',
        'Mendez' => '4121',
        'Naic' => '4110',
        'Noveleta' => '4105',
        'Rosario' => '4106',
        'Silang' => '4118',
        'Tagaytay' => '4120',
        'Tanza' => '4108',
        'Ternate' => '4111',
        'Trece Martires' => '4109'
    ];
}

function normalizeCaviteCityName($cityName) {
    $cityName = trim((string)$cityName);
    $aliases = [
        'Bacoor City' => 'Bacoor',
        'Carmona City' => 'Carmona',
        'Dasmarinas City' => 'Dasmarinas',
        'General Trias City' => 'General Trias',
        'Imus City' => 'Imus',
        'Tagaytay City' => 'Tagaytay',
        'Trece Martires City' => 'Trece Martires'
    ];

    return $aliases[$cityName] ?? $cityName;
}

function ensureProviderDocumentColumns(PDO $db) {
    $columns = [
        'business_registration_file' => "ALTER TABLE providers ADD COLUMN business_registration_file VARCHAR(255) NULL AFTER business_registration_number",
        'license_file' => "ALTER TABLE providers ADD COLUMN license_file VARCHAR(255) NULL AFTER license_number"
    ];

    foreach ($columns as $columnName => $alterSql) {
        $stmt = $db->prepare("SHOW COLUMNS FROM providers LIKE :column_name");
        $stmt->bindParam(':column_name', $columnName);
        $stmt->execute();
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $db->exec($alterSql);
        }
    }
}

function canRunShellCommands() {
    if (!function_exists('shell_exec')) {
        return false;
    }

    $disabled = ini_get('disable_functions');
    if (!$disabled) {
        return true;
    }

    $disabledFunctions = array_map('trim', explode(',', $disabled));
    return !in_array('shell_exec', $disabledFunctions, true);
}

function commandExists($commandName) {
    if (!canRunShellCommands()) {
        return false;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $output = @shell_exec('where ' . $commandName . ' 2>nul');
    } else {
        $output = @shell_exec('command -v ' . escapeshellarg($commandName) . ' 2>/dev/null');
    }

    return is_string($output) && trim($output) !== '';
}

function extractDocumentText($filePath, $mimeType) {
    $text = '';

    if ($mimeType === 'application/pdf' && commandExists('pdftotext')) {
        $tempTextFile = tempnam(sys_get_temp_dir(), 'pestify_pdf_');
        if ($tempTextFile !== false) {
            $command = 'pdftotext -layout ' . escapeshellarg($filePath) . ' ' . escapeshellarg($tempTextFile);
            @shell_exec($command);
            if (is_file($tempTextFile)) {
                $content = @file_get_contents($tempTextFile);
                if (is_string($content)) {
                    $text .= $content;
                }
                @unlink($tempTextFile);
            }
        }
    } elseif (strpos($mimeType, 'image/') === 0 && commandExists('tesseract')) {
        $command = 'tesseract ' . escapeshellarg($filePath) . ' stdout -l eng';
        $ocrOutput = @shell_exec($command);
        if (is_string($ocrOutput)) {
            $text .= $ocrOutput;
        }
    }

    // Fallback: attempt to read visible ASCII text from file bytes.
    if (trim($text) === '') {
        $raw = @file_get_contents($filePath, false, null, 0, 2 * 1024 * 1024);
        if (is_string($raw)) {
            $text .= preg_replace('/[^A-Za-z0-9 ]+/', ' ', $raw);
        }
    }

    return $text;
}

function hasOcrEngineForMime($mimeType) {
    if ($mimeType === 'application/pdf') {
        return commandExists('pdftotext');
    }
    if (strpos($mimeType, 'image/') === 0) {
        return commandExists('tesseract');
    }
    return false;
}

function normalizeLocationText($text) {
    $normalized = strtolower((string)$text);
    $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized);
    return trim((string)$normalized);
}

function passesCaviteLocationCheck($filePath, $mimeType, $selectedCity, $zipCode, $businessAddress, &$reason) {
    $text = extractDocumentText($filePath, $mimeType);
    $normalizedDoc = normalizeLocationText($text);
    $normalizedCityName = normalizeCaviteCityName($selectedCity);
    $normalizedCity = normalizeLocationText($normalizedCityName);
    $normalizedZip = preg_replace('/\D+/', '', (string)$zipCode);
    $normalizedBusinessAddress = normalizeLocationText($businessAddress);
    $ocrAvailable = hasOcrEngineForMime($mimeType);
    $formHasCavite = strpos($normalizedBusinessAddress, 'cavite') !== false;
    $formHasCity = $normalizedCity !== '' && strpos($normalizedBusinessAddress, $normalizedCity) !== false;
    $formHasZip = $normalizedZip !== '' && strpos(preg_replace('/\D+/', '', (string)$businessAddress), $normalizedZip) !== false;
    $caviteCityPostalMap = getCaviteCityPostalMap();
    $hasValidSelectedCaviteCity = isset($caviteCityPostalMap[$normalizedCityName]);
    $expectedZip = $hasValidSelectedCaviteCity ? preg_replace('/\D+/', '', (string)$caviteCityPostalMap[$normalizedCityName]) : '';
    $formZipMatchesSelectedCity = $expectedZip !== '' && $normalizedZip !== '' && $normalizedZip === $expectedZip;
    $strongFormLocationMatch = $hasValidSelectedCaviteCity && $formZipMatchesSelectedCity;

    if (!$ocrAvailable) {
        if ($strongFormLocationMatch || ($formHasCavite && ($formHasCity || $formHasZip))) {
            return true;
        }

        $reason = "Server OCR is unavailable and the selected Cavite city/ZIP details could not be validated.";
        return false;
    }

    if ($normalizedDoc === '') {
        if ($strongFormLocationMatch || ($formHasCavite && ($formHasCity || $formHasZip))) {
            return true;
        }
        $reason = "The system couldn't read enough text. Upload a clearer document showing your Cavite address.";
        return false;
    }

    $hasCavite = strpos($normalizedDoc, 'cavite') !== false;
    $hasCity = $normalizedCity !== '' && strpos($normalizedDoc, $normalizedCity) !== false;
    $hasZip = $normalizedZip !== '' && strpos($normalizedDoc, $normalizedZip) !== false;

    if (!$hasCavite) {
        $reason = "Document address does not appear to include Cavite.";
        return false;
    }

    if (!$hasCity && !$hasZip) {
        $reason = "Document must include your selected city or ZIP code in Cavite.";
        return false;
    }

    return true;
}

function uploadProviderDocument($fieldName, $providerId, $label, $existingPath, $city, $zipCode, $businessAddress, &$error) {
    global $appRoot;

    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return $existingPath;
    }

    $file = $_FILES[$fieldName];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $existingPath;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $error = $label . " upload failed. Please try again.";
        return false;
    }

    $maxFileSize = 5 * 1024 * 1024; // 5MB
    if (($file['size'] ?? 0) > $maxFileSize) {
        $error = $label . " must be 5MB or smaller.";
        return false;
    }

    $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
    if (!in_array($extension, $allowedExtensions, true)) {
        $error = $label . " must be a PDF or image file (JPG, JPEG, PNG).";
        return false;
    }

    $allowedMimeByExtension = [
        'pdf' => ['application/pdf', 'application/x-pdf'],
        'jpg' => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png' => ['image/png']
    ];

    $detectedMime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detectedMime = (string)finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }
    }

    $acceptedMimes = $allowedMimeByExtension[$extension] ?? [];
    if ($detectedMime !== '' && !in_array($detectedMime, $acceptedMimes, true)) {
        $error = $label . " appears invalid (file type mismatch).";
        return false;
    }

    if ($extension === 'pdf') {
        $header = @file_get_contents($file['tmp_name'], false, null, 0, 4);
        if ($header !== '%PDF') {
            $error = $label . " is not a valid PDF document.";
            return false;
        }
    } elseif (@getimagesize($file['tmp_name']) === false) {
        $error = $label . " is not a valid image file.";
        return false;
    }

    if ($detectedMime === '') {
        $detectedMime = $extension === 'pdf' ? 'application/pdf' : (($extension === 'png') ? 'image/png' : 'image/jpeg');
    }

    $locationCheckReason = '';
    if (!passesCaviteLocationCheck($file['tmp_name'], $detectedMime, $city, $zipCode, $businessAddress, $locationCheckReason)) {
        $error = $label . " failed Cavite verification. " . $locationCheckReason;
        return false;
    }

    $baseDir = $appRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'provider-docs' . DIRECTORY_SEPARATOR . 'provider_' . (int)$providerId;
    if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true)) {
        $error = "Unable to create upload folder for " . $label . ".";
        return false;
    }

    $newFileName = strtolower(str_replace(' ', '_', $fieldName)) . '_' . (int)$providerId . '_' . date('YmdHis') . '_' . mt_rand(1000, 9999) . '.' . $extension;
    $targetPath = $baseDir . DIRECTORY_SEPARATOR . $newFileName;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        $error = "Failed to save " . $label . ". Please retry.";
        return false;
    }

    $relativePath = 'uploads/provider-docs/provider_' . (int)$providerId . '/' . $newFileName;

    if (!empty($existingPath)) {
        $oldAbsolute = $appRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $existingPath);
        $oldRealPath = realpath($oldAbsolute);
        $docsBasePath = realpath($appRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'provider-docs');
        if ($oldRealPath !== false && $docsBasePath !== false && strpos($oldRealPath, $docsBasePath) === 0 && is_file($oldRealPath)) {
            @unlink($oldRealPath);
        }
    }

    return $relativePath;
}

ensureProviderDocumentColumns($db);

// Get provider information
$query = "SELECT p.*, u.email, u.first_name, u.last_name
          FROM providers p 
          JOIN users u ON p.user_id = u.id 
          WHERE p.user_id = :user_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $user_id);
$stmt->execute();
$provider = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$provider) {
    session_destroy();
    header("Location: " . appUrl('login.php'));
    exit();
}

$provider_id = $provider['id'];
$caviteCityPostalMap = getCaviteCityPostalMap();
$caviteCities = array_keys($caviteCityPostalMap);
$existingBusinessFile = $provider['business_registration_file'] ?? '';
$existingLicenseFile = $provider['license_file'] ?? '';
$normalizedExistingCity = normalizeCaviteCityName($provider['city'] ?? '');
$hasValidCaviteCity = isset($caviteCityPostalMap[$normalizedExistingCity]);
$hasCaviteState = strcasecmp(trim((string)($provider['state'] ?? '')), 'Cavite') === 0;

// Check if profile is already complete - if so redirect to dashboard
$profile_complete = !empty($existingBusinessFile) &&
                   !empty($existingLicenseFile) &&
                   !empty($provider['address']) && 
                   $hasValidCaviteCity &&
                   $hasCaviteState;

if ($profile_complete && (($provider['status'] ?? '') === 'active')) {
    header("Location: providers-dashboard.php");
    exit();
}

$error = '';
$success = '';
$approval_notice = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $company_name = isset($_POST['company_name']) ? trim($_POST['company_name']) : $provider['company_name'];
    $description = isset($_POST['edit_description_enabled'])
        ? trim((string)($_POST['description'] ?? ''))
        : trim((string)($provider['description'] ?? ''));
    $address = trim($_POST['address'] ?? '');
    $city = normalizeCaviteCityName($_POST['city'] ?? '');
    $state = 'Cavite';
    $zip_code = trim($_POST['zip_code'] ?? '');
    $service_radius = intval($_POST['service_radius'] ?? 50);
    $business_registration_file = $existingBusinessFile;
    $license_file = $existingLicenseFile;
    
    // Validate required fields
    if (empty($company_name)) {
        $error = "Company name is required!";
    } elseif (empty($address)) {
        $error = "Address is required!";
    } elseif (empty($city)) {
        $error = "City/Municipality is required!";
    } elseif (!isset($caviteCityPostalMap[$city])) {
        $error = "Please select a valid Cavite city/municipality.";
    } else {
        $zip_code = $caviteCityPostalMap[$city];

        $uploadError = '';
        $uploadedBusinessFile = uploadProviderDocument(
            'business_registration_file',
            $provider_id,
            'Business Registration Document',
            $existingBusinessFile,
            $city,
            $zip_code,
            $address,
            $uploadError
        );

        if ($uploadedBusinessFile === false) {
            $error = $uploadError;
        } else {
            $business_registration_file = $uploadedBusinessFile;
        }

        if (empty($error)) {
            $uploadedLicenseFile = uploadProviderDocument(
                'license_file',
                $provider_id,
                'Professional License Document',
                $existingLicenseFile,
                $city,
                $zip_code,
                $address,
                $uploadError
            );

            if ($uploadedLicenseFile === false) {
                $error = $uploadError;
            } else {
                $license_file = $uploadedLicenseFile;
            }
        }

        if (empty($error) && empty($business_registration_file)) {
            $error = "Please attach your Business Registration Document.";
        }

        if (empty($error) && empty($license_file)) {
            $error = "Please attach your Professional License Document.";
        }
    }

    if (empty($error)) {
        $nextProviderStatus = (($provider['status'] ?? '') === 'active') ? 'active' : 'pending';

        // Update provider information
        $query = "UPDATE providers SET 
                  company_name = :company_name,
                  business_registration_file = :business_registration_file,
                  license_file = :license_file,
                  description = :description,
                  address = :address,
                  city = :city,
                  state = :state,
                  zip_code = :zip_code,
                  service_radius = :service_radius,
                  status = :status,
                  verification_status = :verification_status,
                  verification_submitted_at = CASE WHEN :verification_status = 'pending' THEN NOW() ELSE verification_submitted_at END,
                  verification_notes = CASE WHEN :verification_status = 'pending' THEN NULL ELSE verification_notes END,
                  verification_date = CASE WHEN :verification_status = 'pending' THEN NULL ELSE verification_date END,
                  verification_reviewed_by = CASE WHEN :verification_status = 'pending' THEN NULL ELSE verification_reviewed_by END,
                  verification_reviewed_by_name = CASE WHEN :verification_status = 'pending' THEN NULL ELSE verification_reviewed_by_name END,
                  updated_at = NOW()
                  WHERE id = :provider_id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':company_name', $company_name);
        $stmt->bindParam(':business_registration_file', $business_registration_file);
        $stmt->bindParam(':license_file', $license_file);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':address', $address);
        $stmt->bindParam(':city', $city);
        $stmt->bindParam(':state', $state);
        $stmt->bindParam(':zip_code', $zip_code);
        $stmt->bindParam(':service_radius', $service_radius, PDO::PARAM_INT);
        $stmt->bindParam(':status', $nextProviderStatus);
        $verificationStatus = $nextProviderStatus === 'active' ? 'approved' : 'pending';
        $stmt->bindParam(':verification_status', $verificationStatus);
        $stmt->bindParam(':provider_id', $provider_id, PDO::PARAM_INT);
        
        try {
            if ($stmt->execute()) {
                if ($nextProviderStatus === 'active') {
                    header("Location: providers-dashboard.php");
                    exit();
                }
                header("Location: provider-setup.php?review_submitted=1");
                exit();
            } else {
                $error = "Failed to save profile. Please try again.";
            }
        } catch (Exception $e) {
            $error = "Unable to complete setup right now. Please check fields and try again.";
        }
    }
}

$form_company_name = $_POST['company_name'] ?? ($provider['company_name'] ?? '');
$form_description = $_POST['description'] ?? ($provider['description'] ?? '');
$form_address = $_POST['address'] ?? ($provider['address'] ?? '');
$form_city = normalizeCaviteCityName($_POST['city'] ?? ($provider['city'] ?? ''));
$form_state = 'Cavite';
$form_zip = $_POST['zip_code'] ?? ($provider['zip_code'] ?? ($caviteCityPostalMap[$form_city] ?? ''));
$form_service_radius = (int)($_POST['service_radius'] ?? ($provider['service_radius'] ?? 50));

if (isset($business_registration_file) && is_string($business_registration_file)) {
    $existingBusinessFile = $business_registration_file;
}
if (isset($license_file) && is_string($license_file)) {
    $existingLicenseFile = $license_file;
}

$reviewSubmitted = isset($_GET['review_submitted']) && $_GET['review_submitted'] === '1';
$providerStatus = strtolower(trim((string)($provider['status'] ?? 'pending')));
$descriptionEditingEnabled = isset($_POST['edit_description_enabled']) || trim((string)$form_description) === '';

if ($reviewSubmitted && $providerStatus !== 'active') {
    $success = 'Profile saved. Your business is now waiting for super admin verification before it becomes visible to seekers.';
}

if ($profile_complete && $providerStatus === 'pending' && $success === '') {
    $approval_notice = 'Your business profile is complete. A super admin still needs to verify your supporting documents before your company can accept live bookings.';
} elseif ($providerStatus === 'rejected') {
    $approval_notice = 'Your previous verification was rejected. Update your company details or documents below, then submit again for review.';
    $reviewMeta = [];
    if (!empty($provider['verification_reviewed_by_name'])) {
        $reviewMeta[] = 'Reviewer: ' . $provider['verification_reviewed_by_name'];
    }
    if (!empty($provider['verification_date']) && $provider['verification_date'] !== '0000-00-00 00:00:00') {
        $reviewMeta[] = 'Reviewed on ' . date('F d, Y g:i A', strtotime($provider['verification_date']));
    }
    if (!empty($provider['verification_notes'])) {
        $approval_notice .= ' Notes: ' . preg_replace('/\s+/', ' ', trim((string)$provider['verification_notes']));
    }
    if (!empty($reviewMeta)) {
        $approval_notice .= ' ' . implode(' Â· ', $reviewMeta);
    }
} elseif ($providerStatus === 'active' && !empty($provider['verification_date'])) {
    $approval_notice = 'Your business has been verified for live bookings.';
    if (!empty($provider['verification_reviewed_by_name'])) {
        $approval_notice .= ' Approved by ' . $provider['verification_reviewed_by_name'] . '.';
    }
    $approval_notice .= ' Verified on ' . date('F d, Y g:i A', strtotime($provider['verification_date'])) . '.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Your Profile - Pestify Provider</title>
<link rel="stylesheet" href="<?= appUrl('assets/css/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .setup-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            max-width: 700px;
            width: 100%;
            padding: 50px;
        }

        .setup-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .setup-header h1 {
            font-size: 32px;
            color: #2c3e50;
            margin-bottom: 10px;
        }

        .setup-header p {
            font-size: 16px;
            color: #7f8c8d;
            margin-bottom: 15px;
        }

        .progress-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }

        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
        }

        .progress-step .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e8f5e9;
            color: #2e7d32;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-bottom: 8px;
        }

        .progress-step .step-number.completed {
            background: #2e7d32;
            color: white;
        }

        .progress-step .step-name {
            font-size: 12px;
            color: #666;
            text-align: center;
        }

        .progress-connector {
            flex: 1;
            height: 2px;
            background: #e0e0e0;
            margin: 18px 5px 0;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-group label .required {
            color: #e74c3c;
            margin-left: 3px;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #ecf0f1;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-hint {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-size: 14px;
        }

        .alert-error {
            background: #fcedec;
            border-left: 4px solid #e74c3c;
            color: #c0392b;
        }

        .alert-success {
            background: #eafaf1;
            border-left: 4px solid #27ae60;
            color: #1e8449;
        }

        .alert i {
            margin-right: 8px;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 40px;
        }

        .btn {
            flex: 1;
            padding: 14px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }

        .btn-secondary {
            background: #ecf0f1;
            color: #2c3e50;
        }

        .btn-secondary:hover {
            background: #bdc3c7;
        }

        .security-info {
            background: #fffbea;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            border-left: 4px solid #f39c12;
        }

        .security-info h4 {
            color: #d68910;
            margin-bottom: 8px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .security-info p {
            color: #7f8c8d;
            font-size: 13px;
            line-height: 1.5;
        }

        .field-group-title {
            font-weight: 600;
            color: #2c3e50;
            margin-top: 30px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #ecf0f1;
            font-size: 15px;
        }

        .file-input-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            border: 0;
        }

        .upload-card {
            border: 2px dashed #d8e2ef;
            background: #f8fbff;
            border-radius: 12px;
            padding: 14px;
        }

        .upload-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .upload-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #377fdb, #2a66b1);
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            border-radius: 8px;
            padding: 10px 14px;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .upload-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(52, 152, 219, 0.25);
        }

        .upload-file-name {
            font-size: 13px;
            color: #2c3e50;
            background: #ffffff;
            border: 1px solid #dbe6f3;
            border-radius: 8px;
            padding: 9px 12px;
            flex: 1;
            min-width: 180px;
        }

        .upload-current-link {
            font-size: 12px;
            color: #2a66b1;
            text-decoration: none;
            font-weight: 600;
        }

        .upload-current-link:hover {
            text-decoration: underline;
        }

        .upload-card.upload-card-error {
            border-color: #e74c3c;
            background: #fff5f5;
        }

        .field-error {
            border-color: #e74c3c !important;
            box-shadow: 0 0 0 3px rgba(231, 76, 60, 0.15) !important;
        }

        .form-notification {
            display: none;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 16px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid transparent;
        }

        .form-notification.show {
            display: flex;
        }

        .form-notification.error {
            background: #fff1f0;
            border-color: #ffc7c2;
            color: #b42318;
        }

        .submit-confirm-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
        }

        .submit-confirm-overlay.show {
            display: flex;
        }

        .submit-confirm-modal {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.25);
        }

        .submit-confirm-title {
            margin: 0 0 10px;
            color: #1f2d3d;
            font-size: 18px;
            font-weight: 700;
        }

        .submit-confirm-text {
            color: #546270;
            font-size: 14px;
            line-height: 1.5;
            margin-bottom: 18px;
        }

        .submit-confirm-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .btn-cancel-submit,
        .btn-proceed-submit {
            border: none;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-cancel-submit {
            background: #ecf0f1;
            color: #2c3e50;
        }

        .btn-proceed-submit {
            background: #2a66b1;
            color: #fff;
        }

        @media (max-width: 768px) {
            .setup-container {
                padding: 30px;
            }

            .setup-header h1 {
                font-size: 24px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .progress-indicator {
                flex-wrap: wrap;
                gap: 15px;
            }

            .progress-connector {
                display: none;
            }

            .form-actions {
                flex-direction: column;
            }

            .upload-actions {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
    <?php include appPath('includes/login_success_alert.php'); ?>
    <div class="setup-container">
        <div class="setup-header">
            <h1><i class="fas fa-building" style="color: #3498db;"></i> Complete Your Profile</h1>
            <p>Please provide the required information to start accepting requests</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="security-info">
            <h4><i class="fas fa-shield-alt"></i> Information Security</h4>
            <p>Your business information is securely stored and only shared with customers who request your services. Official verification documents are kept confidential.</p>
        </div>

        <div id="formNotification" class="form-notification" role="alert" aria-live="polite"></div>

        <form method="POST" id="setupForm" enctype="multipart/form-data" novalidate>
            <!-- Company Information -->
            <div class="field-group-title">
                <i class="fas fa-building"></i> Company Information
            </div>

            <div class="form-group">
                <label for="company_name">
                    Company Name <span class="required">*</span>
                </label>
                <input type="text" id="company_name" name="company_name" 
                       value="<?php echo htmlspecialchars($form_company_name); ?>" 
                       required>
            </div>

            <div class="form-group">
                <label for="description">
                    Business Description
                </label>
                <label style="display:flex;align-items:center;gap:10px;margin:10px 0 12px;color:#374151;font-size:14px;font-weight:600;">
                    <input type="checkbox" id="edit_description_enabled" name="edit_description_enabled" value="1" <?php echo $descriptionEditingEnabled ? 'checked' : ''; ?>>
                    Enable description editing
                </label>
                <textarea id="description" name="description" 
                         placeholder="Describe your pest control services, expertise, and specialties..."
                         <?php echo $descriptionEditingEnabled ? '' : 'disabled'; ?>><?php echo htmlspecialchars($form_description); ?></textarea>
                <div class="form-hint">
                    <i class="fas fa-info-circle"></i> Turn this on when you want to update the company description shown to seekers.
                </div>
            </div>

            <!-- Verification Documents -->
            <div class="field-group-title">
                <i class="fas fa-certificate"></i> Verification Documents
            </div>

            <?php if (!empty($error) && (empty($existingBusinessFile) || empty($existingLicenseFile))): ?>
            <div class="security-note" style="background:#fff7ed;border:1px solid #fdba74;color:#9a3412;">
                <h4><i class="fas fa-triangle-exclamation"></i> Re-attach your files before submitting again</h4>
                <p>For security, browsers clear a selected file after a failed submit. Please click "Attach File" again below and re-pick your document(s), even if you already selected one before.</p>
            </div>
            <?php endif; ?>

            <?php if (!empty($approval_notice)): ?>
            <div class="security-note" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;">
                <h4><i class="fas fa-user-shield"></i> Super Admin Review</h4>
                <p><?php echo htmlspecialchars($approval_notice); ?></p>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="business_registration_file">
                    Business Registration Document <span class="required">*</span>
                </label>
                <div class="upload-card" id="business_upload_card">
                    <input type="file" class="file-input-hidden" id="business_registration_file" name="business_registration_file" accept=".pdf,.jpg,.jpeg,.png" <?php echo empty($existingBusinessFile) ? 'required' : ''; ?>>
                    <div class="upload-actions">
                        <label for="business_registration_file" class="upload-btn">
                            <i class="fas fa-cloud-upload-alt"></i> Attach File
                        </label>
                        <div class="upload-file-name" id="business_registration_file_name">
                            <?php echo !empty($existingBusinessFile) ? htmlspecialchars(basename($existingBusinessFile)) : 'No file selected'; ?>
                        </div>
                    </div>
                    <?php if (!empty($existingBusinessFile)): ?>
                        <a class="upload-current-link" href="<?php echo htmlspecialchars($existingBusinessFile); ?>" target="_blank" rel="noopener">
                            <i class="fas fa-file-alt"></i> View current uploaded document
                        </a>
                    <?php endif; ?>
                </div>
                <div class="form-hint">
                    <i class="fas fa-info-circle"></i> Accepted: PDF, JPG, JPEG, PNG (max 5MB). Document must show a readable Cavite address.
                </div>
                <div class="form-hint">
                    <i class="fas fa-map-location-dot"></i> Auto-check requires "Cavite" and your selected city or ZIP to be readable in the file.
                </div>
            </div>

            <div class="form-group">
                <label for="license_file">
                    Professional License Document <span class="required">*</span>
                </label>
                <div class="upload-card" id="license_upload_card">
                    <input type="file" class="file-input-hidden" id="license_file" name="license_file" accept=".pdf,.jpg,.jpeg,.png" <?php echo empty($existingLicenseFile) ? 'required' : ''; ?>>
                    <div class="upload-actions">
                        <label for="license_file" class="upload-btn">
                            <i class="fas fa-cloud-upload-alt"></i> Attach File
                        </label>
                        <div class="upload-file-name" id="license_file_name">
                            <?php echo !empty($existingLicenseFile) ? htmlspecialchars(basename($existingLicenseFile)) : 'No file selected'; ?>
                        </div>
                    </div>
                    <?php if (!empty($existingLicenseFile)): ?>
                        <a class="upload-current-link" href="<?php echo htmlspecialchars($existingLicenseFile); ?>" target="_blank" rel="noopener">
                            <i class="fas fa-file-alt"></i> View current uploaded document
                        </a>
                    <?php endif; ?>
                </div>
                <div class="form-hint">
                    <i class="fas fa-info-circle"></i> Accepted: PDF, JPG, JPEG, PNG (max 5MB). Document must show a readable Cavite address.
                </div>
                <div class="form-hint">
                    <i class="fas fa-map-location-dot"></i> Auto-check requires "Cavite" and your selected city or ZIP to be readable in the file.
                </div>
            </div>

            <!-- Address Information -->
            <div class="field-group-title">
                <i class="fas fa-map-marker-alt"></i> Service Area & Address
            </div>

            <div class="form-group">
                <label for="address">
                    Business Address <span class="required">*</span>
                </label>
                <input type="text" id="address" name="address" 
                       value="<?php echo htmlspecialchars($form_address); ?>" 
                       placeholder="Street address"
                       required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="province">
                        Province <span class="required">*</span>
                    </label>
                    <select id="province" name="state" required>
                        <option value="Cavite" selected>Cavite</option>
                    </select>
                    <div class="form-hint">
                        <i class="fas fa-info-circle"></i> Service area is currently limited to Cavite only
                    </div>
                </div>
                <div class="form-group">
                    <label for="city">
                        City/Municipality <span class="required">*</span>
                    </label>
                    <select id="city" name="city" required>
                        <option value="">-- Select City/Municipality --</option>
                        <?php foreach ($caviteCities as $caviteCity): ?>
                            <option value="<?php echo htmlspecialchars($caviteCity); ?>" <?php echo ($form_city === $caviteCity) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($caviteCity); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="zip_code">
                    ZIP/Postal Code
                </label>
                <input type="text" id="zip_code" name="zip_code" 
                       value="<?php echo htmlspecialchars($form_zip); ?>" 
                       placeholder="ZIP/Postal code"
                       readonly>
            </div>

            <div class="form-group">
                <label for="service_radius">
                    Service Coverage Radius (km)
                </label>
                <select id="service_radius" name="service_radius">
                    <option value="5" <?php echo ($form_service_radius === 5) ? 'selected' : ''; ?>>5 km</option>
                    <option value="10" <?php echo ($form_service_radius === 10) ? 'selected' : ''; ?>>10 km</option>
                    <option value="25" <?php echo ($form_service_radius === 25) ? 'selected' : ''; ?>>25 km</option>
                    <option value="50" <?php echo ($form_service_radius === 50) ? 'selected' : ''; ?>>50 km</option>
                    <option value="75" <?php echo ($form_service_radius === 75) ? 'selected' : ''; ?>>75 km</option>
                    <option value="100" <?php echo ($form_service_radius === 100) ? 'selected' : ''; ?>>100 km</option>
                </select>
                <div class="form-hint">
                    <i class="fas fa-info-circle"></i> Maximum distance you can service from your location
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="form-actions">
                <button type="submit" class="btn btn-primary" name="submit">
                    <i class="fas fa-check"></i> Complete Profile & Continue
                </button>
            </div>
        </form>

        <div style="text-align: center; margin-top: 25px; padding-top: 25px; border-top: 1px solid #ecf0f1;">
            <p style="color: #7f8c8d; font-size: 13px;">
                <i class="fas fa-lock"></i> Your information is secure and encrypted
            </p>
        </div>
    </div>

    <div id="submitConfirmOverlay" class="submit-confirm-overlay">
        <div class="submit-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="submitConfirmTitle">
            <h3 id="submitConfirmTitle" class="submit-confirm-title">Submit Profile Setup?</h3>
            <p class="submit-confirm-text">Your documents and address will be checked by the system. Do you want to proceed?</p>
            <div class="submit-confirm-actions">
                <button type="button" id="cancelSubmitBtn" class="btn-cancel-submit">Cancel</button>
                <button type="button" id="proceedSubmitBtn" class="btn-proceed-submit">Proceed</button>
            </div>
        </div>
    </div>

    <script>
        const caviteCityToZip = <?php echo json_encode($caviteCityPostalMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const hasExistingBusinessDoc = <?php echo !empty($existingBusinessFile) ? 'true' : 'false'; ?>;
        const hasExistingLicenseDoc = <?php echo !empty($existingLicenseFile) ? 'true' : 'false'; ?>;

        function syncZipCodeByCity() {
            const citySelect = document.getElementById('city');
            const zipInput = document.getElementById('zip_code');
            if (!citySelect || !zipInput) {
                return;
            }

            const selectedCity = citySelect.value;
            zipInput.value = caviteCityToZip[selectedCity] || '';
        }

        function bindFileNamePreview(inputId, outputId) {
            const input = document.getElementById(inputId);
            const output = document.getElementById(outputId);
            if (!input || !output) {
                return;
            }

            input.addEventListener('change', function() {
                const fileName = (input.files && input.files[0]) ? input.files[0].name : 'No file selected';
                output.textContent = fileName;
            });
        }

        function showFormNotification(message) {
            const notification = document.getElementById('formNotification');
            if (!notification) {
                return;
            }

            notification.className = 'form-notification show error';
            notification.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + message;
            notification.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function clearFormNotification() {
            const notification = document.getElementById('formNotification');
            if (!notification) {
                return;
            }
            notification.className = 'form-notification';
            notification.innerHTML = '';
        }

        function clearFieldHighlights() {
            ['company_name', 'address', 'city'].forEach(function(id) {
                const field = document.getElementById(id);
                if (field) {
                    field.classList.remove('field-error');
                }
            });

            ['business_upload_card', 'license_upload_card'].forEach(function(id) {
                const card = document.getElementById(id);
                if (card) {
                    card.classList.remove('upload-card-error');
                }
            });
        }

        function showMissingField(fieldId, message, isUploadCard) {
            clearFieldHighlights();
            clearFormNotification();

            if (isUploadCard) {
                const uploadCard = document.getElementById(fieldId);
                if (uploadCard) {
                    uploadCard.classList.add('upload-card-error');
                    uploadCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            } else {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.classList.add('field-error');
                    field.focus();
                }
            }

            showFormNotification(message);
            return false;
        }

        function validateSetupForm() {
            const companyName = (document.getElementById('company_name')?.value || '').trim();
            const address = (document.getElementById('address')?.value || '').trim();
            const city = (document.getElementById('city')?.value || '').trim();
            const businessInput = document.getElementById('business_registration_file');
            const licenseInput = document.getElementById('license_file');
            const hasNewBusinessDoc = !!(businessInput && businessInput.files && businessInput.files.length > 0);
            const hasNewLicenseDoc = !!(licenseInput && licenseInput.files && licenseInput.files.length > 0);

            if (!companyName) {
                return showMissingField('company_name', 'Fill up this field first: Company Name.', false);
            }
            if (!hasExistingBusinessDoc && !hasNewBusinessDoc) {
                return showMissingField('business_upload_card', 'Fill up this field first: Attach Business Registration Document photo/file.', true);
            }
            if (!hasExistingLicenseDoc && !hasNewLicenseDoc) {
                return showMissingField('license_upload_card', 'Fill up this field first: Attach Professional License Document photo/file.', true);
            }
            if (!address) {
                return showMissingField('address', 'Fill up this field first: Business Address.', false);
            }
            if (!city) {
                return showMissingField('city', 'Fill up this field first: City/Municipality.', false);
            }

            clearFieldHighlights();
            clearFormNotification();
            return true;
        }

        function showSubmitConfirm() {
            const overlay = document.getElementById('submitConfirmOverlay');
            if (!overlay) {
                return;
            }
            overlay.classList.add('show');
        }

        function hideSubmitConfirm() {
            const overlay = document.getElementById('submitConfirmOverlay');
            if (!overlay) {
                return;
            }
            overlay.classList.remove('show');
        }

        window.addEventListener('load', function() {
            const provinceSelect = document.getElementById('province');
            const citySelect = document.getElementById('city');
            const setupForm = document.getElementById('setupForm');
            const proceedSubmitBtn = document.getElementById('proceedSubmitBtn');
            const cancelSubmitBtn = document.getElementById('cancelSubmitBtn');
            const submitConfirmOverlay = document.getElementById('submitConfirmOverlay');
            let submitConfirmed = false;

            if (provinceSelect) {
                provinceSelect.value = 'Cavite';
            }

            if (citySelect) {
                citySelect.addEventListener('change', syncZipCodeByCity);
                syncZipCodeByCity();
            }

            bindFileNamePreview('business_registration_file', 'business_registration_file_name');
            bindFileNamePreview('license_file', 'license_file_name');

            const editDescriptionCheckbox = document.getElementById('edit_description_enabled');
            const descriptionInput = document.getElementById('description');
            if (editDescriptionCheckbox && descriptionInput) {
                const syncDescriptionState = function() {
                    descriptionInput.disabled = !editDescriptionCheckbox.checked;
                };
                syncDescriptionState();
                editDescriptionCheckbox.addEventListener('change', syncDescriptionState);
            }

            if (setupForm) {
                setupForm.addEventListener('submit', function(e) {
                    if (!validateSetupForm()) {
                        e.preventDefault();
                        submitConfirmed = false;
                        return;
                    }

                    if (!submitConfirmed) {
                        e.preventDefault();
                        showSubmitConfirm();
                    }
                });
            }

            if (cancelSubmitBtn) {
                cancelSubmitBtn.addEventListener('click', function() {
                    submitConfirmed = false;
                    hideSubmitConfirm();
                });
            }

            if (proceedSubmitBtn && setupForm) {
                proceedSubmitBtn.addEventListener('click', function() {
                    submitConfirmed = true;
                    hideSubmitConfirm();
                    setupForm.submit();
                });
            }

            if (submitConfirmOverlay) {
                submitConfirmOverlay.addEventListener('click', function(e) {
                    if (e.target === submitConfirmOverlay) {
                        submitConfirmed = false;
                        hideSubmitConfirm();
                    }
                });
            }
        });
    </script>
    <?php include appPath('includes/provider-guide.php'); ?>
</body>
</html>

