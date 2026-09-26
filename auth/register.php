<?php
chdir(dirname(__DIR__));
// register.php
session_start();
require_once 'config/config.php';
require_once 'config/database.php';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: " . appUrl('dashboard.php'));
    exit();
}

$error = '';
$success = '';
$geo_latitude_old = htmlspecialchars((string)($_POST['geo_latitude'] ?? ''), ENT_QUOTES, 'UTF-8');
$geo_longitude_old = htmlspecialchars((string)($_POST['geo_longitude'] ?? ''), ENT_QUOTES, 'UTF-8');
$geo_in_cavite_old = (int)($_POST['geo_in_cavite'] ?? 0);
$geo_status_class = 'geo-status-pending';
$geo_status_text = 'Use your current location to verify you are inside Cavite.';

if ($geo_latitude_old !== '' && $geo_longitude_old !== '') {
    if ($geo_in_cavite_old === 1) {
        $geo_status_class = 'geo-status-success';
        $geo_status_text = 'Location verified. You are within Cavite.';
    } else {
        $geo_status_class = 'geo-status-error';
        $geo_status_text = 'Out of range. Provider registration is only available within Cavite.';
    }
}

// Password validation function
function validatePassword($password) {
    if (strlen($password) < 8) {
        return "Password must be at least 8 characters long.";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return "Password must contain at least one uppercase letter.";
    }
    if (!preg_match('/[0-9]/', $password)) {
        return "Password must contain at least one number.";
    }
    if (!preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $password)) {
        return "Password must contain at least one special character (!@#$).";
    }
    return true;
}

function getCavitePolygon() {
    return [
        [14.0534, 120.5648],
        [14.1022, 120.6246],
        [14.1375, 120.6842],
        [14.1718, 120.7374],
        [14.2205, 120.7588],
        [14.2769, 120.7861],
        [14.3369, 120.8190],
        [14.4002, 120.8587],
        [14.4458, 120.9198],
        [14.4799, 120.9643],
        [14.5080, 121.0142],
        [14.4874, 121.0719],
        [14.4409, 121.0740],
        [14.3838, 121.0583],
        [14.3232, 121.0329],
        [14.2728, 121.0092],
        [14.2219, 120.9837],
        [14.1718, 120.9598],
        [14.1299, 120.9361],
        [14.0922, 120.9063],
        [14.0736, 120.8616],
        [14.0598, 120.7992],
        [14.0517, 120.7308],
        [14.0470, 120.6540],
        [14.0534, 120.5648]
    ];
}

function isInsidePolygon($lat, $lng, $polygon) {
    $inside = false;
    $j = count($polygon) - 1;

    for ($i = 0; $i < count($polygon); $j = $i++) {
        $yi = (float)$polygon[$i][0];
        $xi = (float)$polygon[$i][1];
        $yj = (float)$polygon[$j][0];
        $xj = (float)$polygon[$j][1];

        $intersects = (($yi > $lat) !== ($yj > $lat))
            && ($lng < (($xj - $xi) * ($lat - $yi)) / ($yj - $yi) + $xi);

        if ($intersects) {
            $inside = !$inside;
        }
    }

    return $inside;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $database = new Database();
    $db = $database->getConnection();
    
    $email = sanitize($_POST['email']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    $user_type = sanitize($_POST['user_type']);
    $first_name = sanitize($_POST['first_name']);
    $last_name = sanitize($_POST['last_name']);
    $phone = sanitize($_POST['phone']);
    
    // Validate all required fields
    if (empty($first_name) || empty($last_name) || empty($email) || empty($password) || empty($confirm_password) || empty($phone)) {
        $error = "All fields marked with * are required!";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address!";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match!";
    } else {
        $passwordValidation = validatePassword($password);
        if ($passwordValidation !== true) {
            $error = $passwordValidation;
        } else {
            // Check if email exists
            $query = "SELECT id FROM users WHERE email = :email";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                            $error = "Email already registered! <a href='" . appUrl('login.php') . "'>Login here</a>";
            } else {
                // Insert user
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $query = "INSERT INTO users (email, password, user_type, first_name, last_name, phone, status, created_at) VALUES (:email, :password, :user_type, :first_name, :last_name, :phone, 'active', NOW())";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':password', $hashed_password);
                $stmt->bindParam(':user_type', $user_type);
                $stmt->bindParam(':first_name', $first_name);
                $stmt->bindParam(':last_name', $last_name);
                $stmt->bindParam(':phone', $phone);
                
                if ($stmt->execute()) {
                    $user_id = $db->lastInsertId();
                    
                    if ($user_type == 'provider') {
                        $company_name = sanitize($_POST['company_name'] ?? '');
                        $address = sanitize($_POST['address'] ?? '');
                        $city = sanitize($_POST['city'] ?? '');
                        $state = sanitize($_POST['state'] ?? '');
                        $description = sanitize($_POST['description'] ?? '');
                        $geo_latitude_raw = trim((string)($_POST['geo_latitude'] ?? ''));
                        $geo_longitude_raw = trim((string)($_POST['geo_longitude'] ?? ''));
                        $geo_latitude = is_numeric($geo_latitude_raw) ? (float)$geo_latitude_raw : null;
                        $geo_longitude = is_numeric($geo_longitude_raw) ? (float)$geo_longitude_raw : null;
                        $geo_in_cavite = (int)($_POST['geo_in_cavite'] ?? 0);
                        $server_in_cavite = ($geo_latitude !== null && $geo_longitude !== null)
                            ? isInsidePolygon($geo_latitude, $geo_longitude, getCavitePolygon())
                            : false;
                        
                        if (empty($company_name) || empty($address) || empty($city) || empty($state)) {
                            $error = "All provider fields marked with * are required!";
                        } elseif ($geo_in_cavite !== 1 || !$server_in_cavite || $geo_latitude === null || $geo_longitude === null) {
                            $error = "Provider registration is only allowed within Cavite. Please use geolocation and make sure you are in range.";
                        } else {
                            $query = "INSERT INTO providers (user_id, company_name, address, city, state, description, latitude, longitude, office_lat, office_lng, created_at) VALUES (:user_id, :company_name, :address, :city, :state, :description, :lat, :lng, :lat, :lng, NOW())";
                            $stmt = $db->prepare($query);
                            $stmt->bindParam(':user_id', $user_id);
                            $stmt->bindParam(':company_name', $company_name);
                            $stmt->bindParam(':address', $address);
                            $stmt->bindParam(':city', $city);
                            $stmt->bindParam(':state', $state);
                            $stmt->bindParam(':description', $description);
                            $stmt->bindParam(':lat', $geo_latitude);
                            $stmt->bindParam(':lng', $geo_longitude);

                            if (!$stmt->execute()) {
                                $error = "Failed to save provider details. Please contact support.";
                            }
                        }
                    }

                    if (!empty($error) && $user_type == 'provider') {
                        $deleteQuery = "DELETE FROM users WHERE id = :id";
                        $deleteStmt = $db->prepare($deleteQuery);
                        $deleteStmt->bindParam(':id', $user_id);
                        $deleteStmt->execute();
                    }
                    
                    if (empty($error)) {
                        // Generate 6-digit OTP
                        $otp = rand(100000, 999999);
                        $token_expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                        
                        // Save OTP and expiration to database
                        $updateQuery = "UPDATE users SET 
                                        otp_code = :otp,
                                        otp_expires = :otp_expires,
                                        last_otp_sent = NOW()
                                        WHERE id = :id";
                        $updateStmt = $db->prepare($updateQuery);
                        $updateStmt->bindParam(':otp', $otp);
                        $updateStmt->bindParam(':otp_expires', $token_expires);
                        $updateStmt->bindParam(':id', $user_id);
                        $updateStmt->execute();
                        
                        // Send OTP email
                        require_once 'config/send_email.php';
                        $emailSender = new EmailSender();
                        $full_name = $first_name . ' ' . $last_name;
                        
                        $emailSent = $emailSender->sendOTPEmail($email, $full_name, $otp);
                        
                        if ($emailSent) {
                            // Store verification data in session
                            $_SESSION['verification_email'] = $email;
                            $_SESSION['verification_otp'] = $otp;
                            $_SESSION['user_id_temp'] = $user_id;
                            
                            $success = "Registration successful! A verification code has been sent to <strong>" . htmlspecialchars($email) . "</strong>. Please check your inbox and spam folder.";
                            header("refresh:3;url=" . appUrl('verify.php'));
                        } else {
                            // Email delivery failed (e.g. SMTP outage) — keep the account
                            // instead of deleting it. verify.php only requires
                            // verification_email in session to render, and its own
                            // resend_otp action independently retries the send, so
                            // routing here lets the seeker retry without re-registering
                            // from scratch and losing their chosen password.
                            $_SESSION['verification_email'] = $email;
                            $_SESSION['user_id_temp'] = $user_id;

                            $success = "Registration successful! We couldn't send the verification email right now, but your account was created. Click \"Resend Code\" on the next page to try again.";
                            header("refresh:3;url=" . appUrl('verify.php'));
                        }
                    }
                } else {
                    $error = "Registration failed. Please try again.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo appUrl('assets/css/style.css'); ?>">
    <style>
        .register-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        
        .register-wrapper {
            display: flex;
            gap: 60px;
            align-items: flex-start;
        }
        
        .register-left {
            flex: 1;
            padding: 40px 0;
        }
        
        .register-right {
            flex: 1;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            padding: 40px;
        }
        
        .register-hero h1 {
            font-size: 42px;
            color: #333;
            margin-bottom: 20px;
            line-height: 1.2;
        }
        
        .register-hero p {
            font-size: 18px;
            color: #666;
            margin-bottom: 30px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-top: 40px;
        }
        
        .stat-item {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #2c5aa0;
            display: block;
        }
        
        .stat-label {
            font-size: 14px;
            color: #666;
        }
        
        .register-form h2 {
            color: #333;
            margin-bottom: 30px;
            font-size: 28px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #2c5aa0;
            box-shadow: 0 0 0 3px rgba(44, 90, 160, 0.1);
        }
        
        .btn-register {
            background: #2c5aa0;
            color: white;
            border: none;
            padding: 14px;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: background 0.3s;
            margin-top: 20px;
        }
        
        .btn-register:hover {
            background: #1e4070;
        }
        
        .user-type-selector {
            display: flex;
            gap: 15px;
            margin: 15px 0 25px 0;
        }
        
        .user-type-option {
            flex: 1;
            border: 2px solid #ddd;
            border-radius: 8px;
            padding: 20px 15px;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }
        
        .user-type-option:hover {
            border-color: #2c5aa0;
            background: #f8fafc;
        }
        
        .user-type-option.selected {
            border-color: #2c5aa0;
            background: #f0f7ff;
        }
        
        .user-type-icon {
            font-size: 32px;
            margin-bottom: 10px;
            display: block;
        }
        
        .user-type-option input[type="radio"] {
            display: none;
        }
        
        .user-type-label {
            font-weight: 600;
            color: #333;
            display: block;
            margin-bottom: 5px;
        }
        
        .user-type-desc {
            font-size: 13px;
            color: #666;
            line-height: 1.4;
        }
        
        .provider-fields {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #2c5aa0;
        }

        .geo-check-wrap {
            background: #eef6ff;
            border: 1px solid #cfe2ff;
            border-radius: 8px;
            padding: 12px;
        }

        .btn-geo {
            background: #1e4070;
            color: #fff;
            border: none;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-geo:hover {
            background: #163156;
        }

        .btn-geo:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .geo-status {
            margin-top: 10px;
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 14px;
            line-height: 1.4;
        }

        .geo-status-pending {
            background: #fff8e1;
            border: 1px solid #ffe58f;
            color: #7a5d00;
        }

        .geo-status-success {
            background: #e8f9ef;
            border: 1px solid #b7ebc6;
            color: #1f7a3f;
        }

        .geo-status-error {
            background: #fff1f0;
            border: 1px solid #ffccc7;
            color: #b42318;
        }

        .geo-note {
            margin-top: 8px;
            color: #3b5b7f;
            font-size: 12px;
        }
        
        .password-validation {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #28a745;
        }
        
        .password-validation h4 {
            margin-top: 0;
            margin-bottom: 15px;
            color: #333;
            font-size: 16px;
        }
        
        .validation-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .validation-list li {
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .validation-icon {
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 12px;
        }
        
        .validation-valid {
            background: #28a745;
            color: white;
        }
        
        .validation-invalid {
            background: #dc3545;
            color: white;
        }
        
        .validation-pending {
            background: #6c757d;
            color: white;
        }
        
        .password-match {
            margin-top: 5px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .match-good {
            color: #28a745;
            font-weight: 500;
        }
        
        .match-bad {
            color: #dc3545;
            font-weight: 500;
        }
        
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 25px;
        }
        
        .alert-error {
            background: #fee;
            border: 1px solid #fcc;
            color: #c00;
        }
        
        .alert-success {
            background: #efe;
            border: 1px solid #cfc;
            color: #090;
        }

        .form-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
        }

        .form-modal {
            width: 100%;
            max-width: 480px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.25);
            overflow: hidden;
        }

        .form-modal-header {
            padding: 16px 20px;
            background: #f5f9ff;
            border-bottom: 1px solid #d8e5ff;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .form-modal-title {
            margin: 0;
            color: #1e4070;
            font-size: 18px;
            font-weight: 700;
        }

        .form-modal-close {
            border: none;
            background: transparent;
            color: #355b8c;
            font-size: 24px;
            line-height: 1;
            cursor: pointer;
        }

        .form-modal-body {
            padding: 18px 20px 8px;
        }

        .form-modal-list {
            margin: 0;
            padding-left: 20px;
            color: #3a3a3a;
        }

        .form-modal-list li {
            margin-bottom: 10px;
            line-height: 1.45;
        }

        .form-modal-footer {
            padding: 16px 20px 20px;
            display: flex;
            justify-content: flex-end;
        }

        .form-modal-ok {
            background: #1e4070;
            border: none;
            color: #fff;
            padding: 10px 18px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }

        .form-modal-ok:hover {
            background: #163156;
        }
        
        .login-link {
            text-align: center;
            margin-top: 25px;
            color: #666;
        }
        
        .login-link a {
            color: #2c5aa0;
            text-decoration: none;
            font-weight: 500;
        }
        
        .login-link a:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 992px) {
            .register-wrapper {
                flex-direction: column;
                gap: 40px;
            }
            
            .register-left, .register-right {
                width: 100%;
            }
        }
        
        @media (max-width: 576px) {
            .register-right {
                padding: 30px 20px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .user-type-selector {
                flex-direction: column;
            }
        }
    </style>
    <script>
        var CAVITE_POLYGON = [
            [14.0534, 120.5648],
            [14.1022, 120.6246],
            [14.1375, 120.6842],
            [14.1718, 120.7374],
            [14.2205, 120.7588],
            [14.2769, 120.7861],
            [14.3369, 120.8190],
            [14.4002, 120.8587],
            [14.4458, 120.9198],
            [14.4799, 120.9643],
            [14.5080, 121.0142],
            [14.4874, 121.0719],
            [14.4409, 121.0740],
            [14.3838, 121.0583],
            [14.3232, 121.0329],
            [14.2728, 121.0092],
            [14.2219, 120.9837],
            [14.1718, 120.9598],
            [14.1299, 120.9361],
            [14.0922, 120.9063],
            [14.0736, 120.8616],
            [14.0598, 120.7992],
            [14.0517, 120.7308],
            [14.0470, 120.6540],
            [14.0534, 120.5648]
        ];

        function showValidationModal(message) {
            var overlay = document.getElementById('form-validation-modal');
            var list = document.getElementById('form-validation-list');
            if (!overlay || !list) return;

            list.innerHTML = '';
            message
                .split('\n')
                .map(function(line) { return line.trim(); })
                .filter(function(line) { return line !== ''; })
                .forEach(function(line) {
                    var li = document.createElement('li');
                    li.textContent = line;
                    list.appendChild(li);
                });

            overlay.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeValidationModal() {
            var overlay = document.getElementById('form-validation-modal');
            if (!overlay) return;
            overlay.style.display = 'none';
            document.body.style.overflow = '';
        }

        function setGeoStatus(message, type) {
            var geoStatus = document.getElementById('geo-status');
            if (!geoStatus) return;
            geoStatus.className = 'geo-status geo-status-' + type;
            geoStatus.textContent = message;
        }

        function firstNonEmpty(values) {
            for (var i = 0; i < values.length; i++) {
                if (values[i] && values[i].toString().trim() !== '') {
                    return values[i].toString().trim();
                }
            }
            return '';
        }

        function joinAddressParts(parts) {
            return parts
                .map(function(part) { return (part || '').toString().trim(); })
                .filter(function(part) { return part !== ''; })
                .join(', ');
        }

        async function autoFillProviderAddress(latitude, longitude) {
            var addressField = document.getElementById('provider_address');
            var cityField = document.getElementById('provider_city');
            var provinceField = document.getElementById('provider_state');

            if (!addressField || !cityField || !provinceField) {
                return { success: false, message: 'Address fields not found.' };
            }

            var url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&lat='
                + encodeURIComponent(latitude) + '&lon=' + encodeURIComponent(longitude);

            try {
                var response = await fetch(url, {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' }
                });

                if (!response.ok) {
                    throw new Error('Failed reverse geocoding request.');
                }

                var data = await response.json();
                var a = data.address || {};
                var city = firstNonEmpty([a.city, a.town, a.municipality, a.village, a.county]);
                var province = firstNonEmpty([a.state, a.province, a.region, 'Cavite']);
                var street = joinAddressParts([a.house_number, a.road]);
                var area = firstNonEmpty([a.suburb, a.neighbourhood, a.quarter, a.village, a.hamlet]);
                var displayAddress = joinAddressParts([street, area, city, province]);

                if (displayAddress === '') {
                    displayAddress = firstNonEmpty([data.display_name]);
                }

                if (displayAddress !== '') {
                    addressField.value = displayAddress;
                }

                if (city !== '') {
                    cityField.value = city;
                }

                provinceField.value = province || 'Cavite';

                return { success: true };
            } catch (error) {
                if (!provinceField.value.trim()) {
                    provinceField.value = 'Cavite';
                }
                return { success: false, message: 'Location verified, but auto-fill failed. Please complete address details manually.' };
            }
        }

        function resetGeoFields() {
            var geoLat = document.getElementById('geo_latitude');
            var geoLng = document.getElementById('geo_longitude');
            var geoInCavite = document.getElementById('geo_in_cavite');

            if (geoLat) geoLat.value = '';
            if (geoLng) geoLng.value = '';
            if (geoInCavite) geoInCavite.value = '0';
        }

        function isPointInsidePolygon(lat, lng, polygon) {
            var inside = false;
            for (var i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
                var yi = polygon[i][0];
                var xi = polygon[i][1];
                var yj = polygon[j][0];
                var xj = polygon[j][1];

                var intersects = ((yi > lat) !== (yj > lat)) &&
                    (lng < ((xj - xi) * (lat - yi)) / (yj - yi) + xi);

                if (intersects) inside = !inside;
            }
            return inside;
        }

        function verifyProviderLocation() {
            var geoButton = document.getElementById('btn-geo');
            var geoLat = document.getElementById('geo_latitude');
            var geoLng = document.getElementById('geo_longitude');
            var geoInCavite = document.getElementById('geo_in_cavite');

            if (!geoButton || !geoLat || !geoLng || !geoInCavite) {
                return;
            }

            if (!navigator.geolocation) {
                resetGeoFields();
                setGeoStatus('Geolocation is not supported by this browser.', 'error');
                return;
            }

            geoButton.disabled = true;
            geoButton.textContent = 'Checking location...';
            setGeoStatus('Getting your location. Please allow the browser permission prompt.', 'pending');

            navigator.geolocation.getCurrentPosition(
                async function(position) {
                    var latitude = Number(position.coords.latitude.toFixed(6));
                    var longitude = Number(position.coords.longitude.toFixed(6));
                    var inCavite = isPointInsidePolygon(latitude, longitude, CAVITE_POLYGON);

                    geoLat.value = latitude.toString();
                    geoLng.value = longitude.toString();
                    geoInCavite.value = inCavite ? '1' : '0';

                    if (inCavite) {
                        var fillResult = await autoFillProviderAddress(latitude, longitude);
                        if (fillResult.success) {
                            setGeoStatus('Location verified. Address, city, and province were auto-filled.', 'success');
                        } else {
                            setGeoStatus(fillResult.message, 'pending');
                        }
                    } else {
                        setGeoStatus('Out of range. Provider registration is only available within Cavite.', 'error');
                    }

                    geoButton.disabled = false;
                    geoButton.textContent = 'Use My Current Location';
                },
                function(error) {
                    resetGeoFields();

                    var message = 'Unable to get your location. Please try again.';
                    if (error.code === error.PERMISSION_DENIED) {
                        message = 'Location permission denied. Please allow location access and try again.';
                    } else if (error.code === error.POSITION_UNAVAILABLE) {
                        message = 'Location unavailable. Please turn on GPS/location services and try again.';
                    } else if (error.code === error.TIMEOUT) {
                        message = 'Location request timed out. Please try again.';
                    }

                    setGeoStatus(message, 'error');
                    geoButton.disabled = false;
                    geoButton.textContent = 'Use My Current Location';
                },
                {
                    enableHighAccuracy: true,
                    timeout: 12000,
                    maximumAge: 0
                }
            );
        }

        function toggleProviderFields() {
            var userType = document.querySelector('input[name="user_type"]:checked').value;
            var providerFields = document.getElementById('provider-fields');
            var providerInputs = providerFields.querySelectorAll('input[required], textarea[required]');
            
            providerFields.style.display = userType === 'provider' ? 'block' : 'none';
            
            document.querySelectorAll('.user-type-option').forEach(function(option) {
                option.classList.remove('selected');
            });
            document.querySelector('.user-type-option[data-value="' + userType + '"]').classList.add('selected');
            
            if (userType === 'provider') {
                providerInputs.forEach(function(input) {
                    input.required = true;
                });
            } else {
                providerInputs.forEach(function(input) {
                    input.required = false;
                });
                resetGeoFields();
                setGeoStatus('Use your current location to verify you are inside Cavite.', 'pending');
            }
        }
        
        function validatePasswordStrength() {
            var password = document.getElementById('password').value;
            var requirements = {
                length: { met: password.length >= 8, element: document.getElementById('req-length') },
                uppercase: { met: /[A-Z]/.test(password), element: document.getElementById('req-uppercase') },
                number: { met: /[0-9]/.test(password), element: document.getElementById('req-number') },
                special: { met: /[!@#$%^&*()\-_=+{};:,<.>]/.test(password), element: document.getElementById('req-special') }
            };
            
            for (var key in requirements) {
                var requirement = requirements[key];
                var icon = requirement.element.querySelector('.validation-icon');
                
                if (password.length === 0) {
                    icon.className = 'validation-icon validation-pending';
                    icon.textContent = '?';
                } else if (requirement.met) {
                    icon.className = 'validation-icon validation-valid';
                    icon.textContent = '?';
                } else {
                    icon.className = 'validation-icon validation-invalid';
                    icon.textContent = '?';
                }
            }
            
            return requirements.length.met && requirements.uppercase.met && 
                   requirements.number.met && requirements.special.met;
        }
        
        function validateConfirmPassword() {
            var password = document.getElementById('password').value;
            var confirmPassword = document.getElementById('confirm_password').value;
            var matchElement = document.getElementById('password-match');
            
            if (confirmPassword.length === 0) {
                matchElement.innerHTML = '';
                return false;
            }
            
            if (password === confirmPassword) {
                matchElement.innerHTML = '<span class="match-good">? Passwords match</span>';
                return true;
            } else {
                matchElement.innerHTML = '<span class="match-bad">? Passwords do not match</span>';
                return false;
            }
        }
        
        function validateForm() {
            var isValid = true;
            var errorMessage = '';
            
            if (!validatePasswordStrength()) {
                errorMessage = 'Please ensure your password meets all requirements.';
                document.getElementById('password').focus();
                isValid = false;
            }
            
            if (!validateConfirmPassword()) {
                if (!isValid) errorMessage += '\n';
                errorMessage += 'Passwords do not match.';
                if (isValid) document.getElementById('confirm_password').focus();
                isValid = false;
            }
            
            var userType = document.querySelector('input[name="user_type"]:checked').value;
            if (userType === 'provider') {
                var companyName = document.getElementsByName('company_name')[0].value;
                var address = document.getElementsByName('address')[0].value;
                var city = document.getElementsByName('city')[0].value;
                var state = document.getElementsByName('state')[0].value;
                
                if (!companyName || !address || !city || !state) {
                    if (!isValid) errorMessage += '\n';
                    errorMessage += 'Please fill in all required provider fields.';
                    isValid = false;
                }

                var geoLatitude = document.getElementById('geo_latitude').value;
                var geoLongitude = document.getElementById('geo_longitude').value;
                var geoInCavite = document.getElementById('geo_in_cavite').value;

                if (!geoLatitude || !geoLongitude || geoInCavite !== '1') {
                    if (!isValid) errorMessage += '\n';
                    errorMessage += 'Provider registration is only allowed within Cavite. Click "Use My Current Location" and verify first.';
                    isValid = false;
                }
            }
            
            if (errorMessage) {
                showValidationModal(errorMessage);
            }
            
            return isValid;
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('password').addEventListener('input', validatePasswordStrength);
            document.getElementById('password').addEventListener('blur', validatePasswordStrength);
            document.getElementById('confirm_password').addEventListener('input', validateConfirmPassword);
            document.getElementById('confirm_password').addEventListener('blur', validateConfirmPassword);
            
            toggleProviderFields();
            
            document.querySelectorAll('.user-type-option').forEach(function(option) {
                option.addEventListener('click', function() {
                    var radio = this.querySelector('input[type="radio"]');
                    radio.checked = true;
                    toggleProviderFields();
                });
            });

            var geoButton = document.getElementById('btn-geo');
            if (geoButton) {
                geoButton.addEventListener('click', verifyProviderLocation);
            }

            var closeButtons = document.querySelectorAll('[data-close-form-modal]');
            closeButtons.forEach(function(btn) {
                btn.addEventListener('click', closeValidationModal);
            });

            var overlay = document.getElementById('form-validation-modal');
            if (overlay) {
                overlay.addEventListener('click', function(e) {
                    if (e.target === overlay) {
                        closeValidationModal();
                    }
                });
            }

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeValidationModal();
                }
            });
        });
    </script>
</head>
<body>
    <?php include appPath('includes/header.php'); ?>
    
    <div class="register-container">
        <div class="register-wrapper">
            <div class="register-left">
                <div class="register-hero">
                    <h1>Join Our Pest Control Community</h1>
                    <p>Whether you need pest control services or want to offer your expertise, join thousands of satisfied users who trust our platform.</p>
                </div>
                
                <div class="stats-grid">
                    <div class="stat-item">
                        <span class="stat-number">500+</span>
                        <span class="stat-label">Trusted Providers</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number">10,000+</span>
                        <span class="stat-label">Happy Customers</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number">24/7</span>
                        <span class="stat-label">Emergency Service</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number">4.8?</span>
                        <span class="stat-label">Average Rating</span>
                    </div>
                </div>
            </div>
            
            <div class="register-right">
                <div class="register-form">
                    <h2>Create Your Account</h2>
                    
                    <?php if($error): ?>
                        <div class="alert alert-error"><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <?php if($success): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                    <?php endif; ?>
                    
                    <form method="POST" action="" onsubmit="return validateForm()">
                        <div class="form-group">
                            <label>First Name: *</label>
                            <input type="text" name="first_name" required class="form-control" 
                                   placeholder="Enter your first name"
                                   value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Last Name: *</label>
                            <input type="text" name="last_name" required class="form-control"
                                   placeholder="Enter your last name"
                                   value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Email: *</label>
                            <input type="email" name="email" required class="form-control"
                                   placeholder="Enter your email address"
                                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Phone: *</label>
                            <input type="text" name="phone" required class="form-control"
                                   placeholder="Enter your phone number"
                                   value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>I am a: *</label>
                            <div class="user-type-selector">
                                <div class="user-type-option <?php echo (!isset($_POST['user_type']) || (isset($_POST['user_type']) && $_POST['user_type'] == 'seeker')) ? 'selected' : ''; ?>" data-value="seeker">
                                    <span class="user-type-icon">??</span>
                                    <input type="radio" name="user_type" value="seeker" 
                                           <?php echo (!isset($_POST['user_type']) || (isset($_POST['user_type']) && $_POST['user_type'] == 'seeker')) ? 'checked' : ''; ?>>
                                    <span class="user-type-label">Service Seeker</span>
                                    <span class="user-type-desc">Looking for pest control services</span>
                                </div>
                                
                                <div class="user-type-option <?php echo (isset($_POST['user_type']) && $_POST['user_type'] == 'provider') ? 'selected' : ''; ?>" data-value="provider">
                                    <span class="user-type-icon">??</span>
                                    <input type="radio" name="user_type" value="provider"
                                           <?php echo (isset($_POST['user_type']) && $_POST['user_type'] == 'provider') ? 'checked' : ''; ?>>
                                    <span class="user-type-label">Service Provider</span>
                                    <span class="user-type-desc">Offering pest control services</span>
                                </div>
                            </div>
                        </div>
                        
                        <div id="provider-fields" class="provider-fields" style="display: <?php echo (isset($_POST['user_type']) && $_POST['user_type'] == 'provider') ? 'block' : 'none'; ?>;">
                            <h4 style="margin-top: 0;">Business Information</h4>
                            <div class="form-group">
                                <label>Company Name: *</label>
                                <input type="text" name="company_name" class="form-control"
                                       placeholder="Enter your company name"
                                       value="<?php echo isset($_POST['company_name']) ? htmlspecialchars($_POST['company_name']) : ''; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Address: *</label>
                                <textarea name="address" id="provider_address" class="form-control" rows="2" placeholder="Enter your business address"><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label>City: *</label>
                                <input type="text" name="city" id="provider_city" class="form-control"
                                       placeholder="Enter your city"
                                       value="<?php echo isset($_POST['city']) ? htmlspecialchars($_POST['city']) : ''; ?>">
                            </div>

                            <div class="form-group">
                                <label>Province: *</label>
                                <input type="text" name="state" id="provider_state" class="form-control"
                                       placeholder="Enter your province"
                                       value="<?php echo isset($_POST['state']) ? htmlspecialchars($_POST['state']) : ''; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Company Description:</label>
                                <textarea name="description" class="form-control" rows="3" placeholder="Describe your services"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                            </div>

                            <div class="form-group">
                                <label>Location Verification (Cavite only): *</label>
                                <input type="hidden" name="geo_latitude" id="geo_latitude" value="<?php echo $geo_latitude_old; ?>">
                                <input type="hidden" name="geo_longitude" id="geo_longitude" value="<?php echo $geo_longitude_old; ?>">
                                <input type="hidden" name="geo_in_cavite" id="geo_in_cavite" value="<?php echo $geo_in_cavite_old === 1 ? '1' : '0'; ?>">
                                <div class="geo-check-wrap">
                                    <button type="button" class="btn-geo" id="btn-geo">Use My Current Location</button>
                                    <div id="geo-status" class="geo-status <?php echo $geo_status_class; ?>"><?php echo htmlspecialchars($geo_status_text); ?></div>
                                    <div class="geo-note">Only locations within Cavite can register as a service provider.</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Password: *</label>
                            <input type="password" name="password" id="password" required class="form-control" 
                                   placeholder="Create a strong password">
                        </div>
                        
                        <div class="form-group">
                            <label>Confirm Password: *</label>
                            <input type="password" name="confirm_password" id="confirm_password" required class="form-control"
                                   placeholder="Re-enter your password">
                            <div id="password-match" class="password-match"></div>
                        </div>
                        
                        <div class="password-validation">
                            <h4>Password Requirements:</h4>
                            <ul class="validation-list">
                                <li id="req-length">
                                    <span class="validation-icon validation-pending">?</span>
                                    <span class="validation-text">At least 8 characters long</span>
                                </li>
                                <li id="req-uppercase">
                                    <span class="validation-icon validation-pending">?</span>
                                    <span class="validation-text">At least one uppercase letter (A-Z)</span>
                                </li>
                                <li id="req-number">
                                    <span class="validation-icon validation-pending">?</span>
                                    <span class="validation-text">At least one number (0-9)</span>
                                </li>
                                <li id="req-special">
                                    <span class="validation-icon validation-pending">?</span>
                                    <span class="validation-text">At least one special character (!@#$%^&*)</span>
                                </li>
                            </ul>
                        </div>
                        
                        <button type="submit" class="btn-register">Create Account</button>
                    </form>
                    
                    <div class="login-link">
                        <p>Already have an account? <a href="<?php echo appUrl('login.php'); ?>">Login here</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="form-modal-overlay" id="form-validation-modal" aria-hidden="true">
        <div class="form-modal" role="dialog" aria-modal="true" aria-labelledby="form-validation-title">
            <div class="form-modal-header">
                <h3 class="form-modal-title" id="form-validation-title">Please Check Your Input</h3>
                <button type="button" class="form-modal-close" data-close-form-modal aria-label="Close">&times;</button>
            </div>
            <div class="form-modal-body">
                <ul class="form-modal-list" id="form-validation-list"></ul>
            </div>
            <div class="form-modal-footer">
                <button type="button" class="form-modal-ok" data-close-form-modal>OK</button>
            </div>
        </div>
    </div>
    
    <?php include appPath('includes/footer.php'); ?>
</body>
</html>
