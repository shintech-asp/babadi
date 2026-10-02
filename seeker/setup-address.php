<?php
chdir(dirname(__DIR__));
// setup-address.php - First-time address setup for seekers
session_start();
require_once 'config/config.php';
require_once 'config/database.php';

// Check if user is logged in and is a seeker
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'seeker') {
    header("Location: " . appUrl('login.php'));
    exit();
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

// Check if address is already completed
$query = "SELECT address_completed FROM users WHERE id = :user_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $user_id);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user['address_completed'] == 1) {
    // Address already completed, redirect to home
    header("Location: index.php");
    exit();
}

$error = '';
$success = '';

$caviteCities = [
    'Alfonso',
    'Amadeo',
    'Bacoor',
    'Carmona',
    'Cavite City',
    'Dasmarinas',
    'General Emilio Aguinaldo',
    'General Mariano Alvarez',
    'General Trias',
    'Imus',
    'Indang',
    'Kawit',
    'Magallanes',
    'Maragondon',
    'Mendez',
    'Naic',
    'Noveleta',
    'Rosario',
    'Silang',
    'Tagaytay City',
    'Tanza',
    'Ternate',
    'Trece Martires City'
];

$normalizeLocationToken = static function (string $value): string {
    $v = strtolower(trim($value));
    $v = preg_replace('/\s+/', ' ', $v);
    $v = preg_replace('/[^a-z0-9 ]+/', '', $v);
    return $v;
};

$caviteCityLookup = [];
foreach ($caviteCities as $cityName) {
    $caviteCityLookup[$normalizeLocationToken($cityName)] = true;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $address = sanitize($_POST['address']);
    $city = sanitize($_POST['city']);
    $state = sanitize($_POST['state']);
    $zip_code = sanitize($_POST['zip_code']);
    $latitude = sanitize($_POST['latitude'] ?? '');
    $longitude = sanitize($_POST['longitude'] ?? '');
    $geo_verified = ($_POST['geo_verified'] ?? '0') === '1';
    
    // Validate required fields
    //
    // The geo_verified/latitude/longitude hard-requirement was removed —
    // this page never actually persists lat/lng anywhere (the UPDATE below
    // only ever touched address/city/state/zip_code), so requiring a
    // successful Nominatim reverse-geocode call just to flip a client-side
    // flag was a pure UX gate with no data benefit, and a single point of
    // failure: GPS permission denial, a slow/down/rate-limited external API,
    // or a detected city string not exactly matching the hardcoded Cavite
    // list all left the user with zero way to proceed. The real Cavite-only
    // business rule is already fully enforced below via $caviteCityLookup,
    // which is independent of the client and can't be bypassed by skipping
    // geolocation — the <select> only ever offers valid Cavite cities to
    // begin with. Geolocation/map-pin is now pure auto-fill convenience.
    if (empty($address) || empty($city)) {
        $error = "Address and City are required!";
    } elseif (!isset($caviteCityLookup[$normalizeLocationToken($city)])) {
        $error = "Only Cavite addresses are allowed. Please choose a valid city/municipality in Cavite.";
    } elseif ($state !== '' && $normalizeLocationToken($state) !== 'cavite') {
        $error = "State/Province must be Cavite.";
    } else {
        $state = 'Cavite';
        // Update user address
        $query = "UPDATE users SET 
                  address = :address,
                  city = :city,
                  state = :state,
                  zip_code = :zip_code,
                  address_completed = 1,
                  updated_at = NOW()
                  WHERE id = :user_id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':address', $address);
        $stmt->bindParam(':city', $city);
        $stmt->bindParam(':state', $state);
        $stmt->bindParam(':zip_code', $zip_code);
        $stmt->bindParam(':user_id', $user_id);
        
        if ($stmt->execute()) {
            $_SESSION['address_completed'] = true;
            header("Location: index.php?welcome=1");
            exit();
        } else {
            $error = "Failed to save address. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Your Profile - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .setup-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 600px;
            width: 100%;
            padding: 50px 40px;
        }
        
        .setup-header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .setup-header .icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 36px;
            color: white;
        }
        
        .setup-header h1 {
            font-size: 32px;
            color: #333;
            margin-bottom: 10px;
        }
        
        .setup-header p {
            color: #666;
            font-size: 16px;
            line-height: 1.6;
        }
        
        .welcome-message {
            background: #f0f7ff;
            border-left: 4px solid #2c5aa0;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        
        .welcome-message h3 {
            color: #2c5aa0;
            margin-bottom: 8px;
            font-size: 18px;
        }
        
        .welcome-message p {
            color: #555;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-group label .required {
            color: #dc3545;
        }
        
        .form-control {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e1e8ed;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .btn-submit {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 16px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .btn-submit:active {
            transform: translateY(0);
        }
        
        .btn-submit:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
        }
        
        .alert i {
            font-size: 18px;
        }
        
        .alert-error {
            background: #fee;
            border: 1px solid #fcc;
            color: #c00;
        }
        
        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .help-text {
            font-size: 13px;
            color: #666;
            margin-top: 5px;
        }

        .btn-map {
            background: #1e3a8a;
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-map:hover {
            background: #1e40af;
        }

        .map-wrap {
            margin-top: 12px;
            border: 2px solid #e1e8ed;
            border-radius: 10px;
            overflow: hidden;
            display: none;
            background: #fff;
        }

        .map-wrap.active {
            display: block;
        }

        #mapPicker {
            width: 100%;
            height: 320px;
        }

        .map-help {
            padding: 10px 12px;
            font-size: 12px;
            color: #555;
            border-top: 1px solid #e5e7eb;
        }
        
        .skip-link {
            text-align: center;
            margin-top: 20px;
        }
        
        .skip-link a {
            color: #666;
            text-decoration: none;
            font-size: 14px;
        }
        
        .skip-link a:hover {
            color: #333;
            text-decoration: underline;
        }
        
        @media (max-width: 576px) {
            .setup-container {
                padding: 40px 25px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
            
            .setup-header h1 {
                font-size: 26px;
            }
        }
    </style>
</head>
<body>
    <?php include appPath('includes/login_success_alert.php'); ?>
    <div class="setup-container">
        <div class="setup-header">
            <div class="icon">
                <i class="fas fa-map-marker-alt"></i>
            </div>
            <h1>Welcome, <?php echo htmlspecialchars($_SESSION['first_name']); ?>!</h1>
            <p>Let's complete your profile to get started</p>
        </div>
        
        <div class="welcome-message">
            <h3><i class="fas fa-info-circle"></i> Why we need your address</h3>
            <p>Your address helps us connect you with nearby pest control providers and provide accurate service estimates. This information is kept secure and only shared with providers when you request a service.</p>
        </div>
        
        <?php if($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $error; ?></span>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" id="setupForm">
            <div class="form-group">
                <label>Street Address <span class="required">*</span></label>
                <textarea name="address" required class="form-control"
                          id="addressInput"
                          placeholder="Enter your address, e.g. 123 Rizal St., Brgy. San Jose"><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
                <div class="help-text">Type your address directly, or tap "Use My Current Location" / "Open Map Picker" to auto-fill it.</div>
                <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;">
                    <button type="button" class="btn-submit" id="geoBtn" style="width:auto;padding:12px 16px;font-size:14px;">
                        <i class="fas fa-location-crosshairs"></i>
                        <span>Use My Current Location</span>
                    </button>
                    <button type="button" class="btn-map" id="mapBtn">
                        <i class="fas fa-map"></i>
                        <span>Open Map Picker</span>
                    </button>
                    <div id="geoStatus" class="help-text" style="margin-top:0;align-self:center;"></div>
                </div>
                <div class="map-wrap" id="mapWrap">
                    <div id="mapPicker"></div>
                    <div class="map-help">
                        Tap on the map or drag the marker to your exact location in Cavite. Postal code will auto-fill when available.
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>City <span class="required">*</span></label>
                    <select name="city" id="cityInput" required class="form-control">
                        <option value="">Select Cavite city/municipality</option>
                        <?php foreach ($caviteCities as $cityName): ?>
                            <option value="<?php echo htmlspecialchars($cityName); ?>" <?php echo (isset($_POST['city']) && $_POST['city'] === $cityName) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cityName); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>State/Province</label>
                    <input type="text" name="state" class="form-control" 
                           id="stateInput"
                           readonly
                           value="<?php echo isset($_POST['state']) && $_POST['state'] !== '' ? htmlspecialchars($_POST['state']) : 'Cavite'; ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label>Zip/Postal Code</label>
                <input type="text" name="zip_code" class="form-control" id="zipCodeInput"
                       placeholder="Enter your zip code"
                       value="<?php echo isset($_POST['zip_code']) ? htmlspecialchars($_POST['zip_code']) : ''; ?>">
            </div>

            <input type="hidden" name="latitude" id="latitudeInput" value="<?php echo isset($_POST['latitude']) ? htmlspecialchars($_POST['latitude']) : ''; ?>">
            <input type="hidden" name="longitude" id="longitudeInput" value="<?php echo isset($_POST['longitude']) ? htmlspecialchars($_POST['longitude']) : ''; ?>">
            <input type="hidden" name="geo_verified" id="geoVerifiedInput" value="<?php echo isset($_POST['geo_verified']) ? htmlspecialchars($_POST['geo_verified']) : '0'; ?>">
            
            <button type="submit" class="btn-submit" id="submitBtn">
                <i class="fas fa-check-circle"></i>
                <span>Complete Setup</span>
            </button>
        </form>
        
        <div class="skip-link">
            <a href="<?php echo appUrl('logout.php'); ?>"><i class="fas fa-sign-out-alt"></i> Logout and do this later</a>
        </div>
    </div>
    
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        (function () {
            const caviteCities = <?php echo json_encode($caviteCities); ?>;
            const caviteSet = new Set(caviteCities.map(v => normalizeText(v)));
            const defaultCaviteCenter = [14.2819, 120.8700];

            const form = document.getElementById('setupForm');
            const geoBtn = document.getElementById('geoBtn');
            const mapBtn = document.getElementById('mapBtn');
            const mapWrap = document.getElementById('mapWrap');
            const geoStatus = document.getElementById('geoStatus');
            const addressInput = document.getElementById('addressInput');
            const cityInput = document.getElementById('cityInput');
            const stateInput = document.getElementById('stateInput');
            const zipCodeInput = document.getElementById('zipCodeInput');
            const latitudeInput = document.getElementById('latitudeInput');
            const longitudeInput = document.getElementById('longitudeInput');
            const geoVerifiedInput = document.getElementById('geoVerifiedInput');
            const submitBtn = document.getElementById('submitBtn');

            let mapInstance = null;
            let mapMarker = null;

            function normalizeText(value) {
                return (value || '')
                    .toString()
                    .trim()
                    .toLowerCase()
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '')
                    .replace(/\s+/g, ' ');
            }

            function setGeoStatus(message, type) {
                geoStatus.textContent = message;
                geoStatus.style.color = type === 'ok' ? '#0f766e' : '#b42318';
            }

            async function reverseGeocode(lat, lon) {
                const url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat='
                    + encodeURIComponent(lat)
                    + '&lon='
                    + encodeURIComponent(lon)
                    + '&addressdetails=1';
                const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (!response.ok) {
                    throw new Error('Geocoding request failed.');
                }
                return response.json();
            }

            function extractCity(addr) {
                return addr.city || addr.town || addr.municipality || addr.village || addr.county || '';
            }

            function isCaviteAddress(addr) {
                const state = normalizeText(addr.state || '');
                const county = normalizeText(addr.county || '');
                const region = normalizeText(addr.region || '');
                return state.includes('cavite') || county.includes('cavite') || region.includes('cavite');
            }

            async function applyLocation(lat, lon, sourceText) {
                try {
                    const geo = await reverseGeocode(lat, lon);
                    const addr = geo.address || {};
                    const detectedCityRaw = extractCity(addr);
                    const detectedCity = normalizeText(detectedCityRaw);

                    latitudeInput.value = String(lat);
                    longitudeInput.value = String(lon);

                    if (!isCaviteAddress(addr)) {
                        geoVerifiedInput.value = '0';
                        setGeoStatus('Selected location is outside Cavite. Only Cavite addresses are allowed.', 'error');
                        return false;
                    }

                    if (!caviteSet.has(detectedCity)) {
                        geoVerifiedInput.value = '0';
                        setGeoStatus('Detected city is not in the Cavite list. Please move the pin to a valid Cavite location.', 'error');
                        return false;
                    }

                    const displayAddress = geo.display_name || '';
                    if (displayAddress) {
                        addressInput.value = displayAddress;
                    }

                    const matchedCity = caviteCities.find(c => normalizeText(c) === detectedCity);
                    if (matchedCity) {
                        cityInput.value = matchedCity;
                    }

                    if (addr.postcode) {
                        zipCodeInput.value = addr.postcode;
                    }

                    stateInput.value = 'Cavite';
                    geoVerifiedInput.value = '1';
                    setGeoStatus(sourceText + ' verified in Cavite. Address and postal code updated.', 'ok');
                    return true;
                } catch (err) {
                    geoVerifiedInput.value = '0';
                    setGeoStatus('Unable to validate location. Please try again.', 'error');
                    return false;
                }
            }

            function ensureMap() {
                if (mapInstance) {
                    setTimeout(function () { mapInstance.invalidateSize(); }, 50);
                    return;
                }

                const initialLat = parseFloat(latitudeInput.value) || defaultCaviteCenter[0];
                const initialLon = parseFloat(longitudeInput.value) || defaultCaviteCenter[1];
                const initialZoom = (latitudeInput.value && longitudeInput.value) ? 16 : 11;

                mapInstance = L.map('mapPicker').setView([initialLat, initialLon], initialZoom);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap contributors'
                }).addTo(mapInstance);

                mapMarker = L.marker([initialLat, initialLon], { draggable: true }).addTo(mapInstance);

                mapInstance.on('click', async function (e) {
                    const lat = e.latlng.lat;
                    const lon = e.latlng.lng;
                    mapMarker.setLatLng([lat, lon]);
                    await applyLocation(lat, lon, 'Map selection');
                });

                mapMarker.on('dragend', async function () {
                    const pos = mapMarker.getLatLng();
                    await applyLocation(pos.lat, pos.lng, 'Map pin');
                });

                setTimeout(function () { mapInstance.invalidateSize(); }, 50);
            }

            mapBtn.addEventListener('click', function () {
                mapWrap.classList.toggle('active');
                if (mapWrap.classList.contains('active')) {
                    ensureMap();
                    mapBtn.innerHTML = '<i class="fas fa-map"></i><span>Hide Map Picker</span>';
                } else {
                    mapBtn.innerHTML = '<i class="fas fa-map"></i><span>Open Map Picker</span>';
                }
            });

            geoBtn.addEventListener('click', function () {
                if (!navigator.geolocation) {
                    setGeoStatus('Geolocation is not supported in this browser.', 'error');
                    return;
                }

                geoBtn.disabled = true;
                geoBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Detecting...</span>';
                setGeoStatus('Detecting your current location...', 'ok');

                navigator.geolocation.getCurrentPosition(async function (position) {
                    try {
                        const lat = position.coords.latitude;
                        const lon = position.coords.longitude;

                        if (!mapWrap.classList.contains('active')) {
                            mapWrap.classList.add('active');
                            mapBtn.innerHTML = '<i class="fas fa-map"></i><span>Hide Map Picker</span>';
                        }
                        ensureMap();
                        mapInstance.setView([lat, lon], 16);
                        mapMarker.setLatLng([lat, lon]);

                        await applyLocation(lat, lon, 'Current location');
                    } finally {
                        geoBtn.disabled = false;
                        geoBtn.innerHTML = '<i class="fas fa-location-crosshairs"></i><span>Use My Current Location</span>';
                    }
                }, function () {
                    geoVerifiedInput.value = '0';
                    setGeoStatus('Location access denied or unavailable.', 'error');
                    geoBtn.disabled = false;
                    geoBtn.innerHTML = '<i class="fas fa-location-crosshairs"></i><span>Use My Current Location</span>';
                }, {
                    enableHighAccuracy: true,
                    timeout: 15000,
                    maximumAge: 0
                });
            });

            form.addEventListener('submit', function (e) {
                // Geolocation/map-pin is optional auto-fill convenience, not
                // a submit requirement — the City dropdown is already
                // restricted to valid Cavite municipalities, and the server
                // independently re-validates it regardless of whether
                // geolocation was used at all. Address and City still go
                // through normal HTML5 `required` validation.
                const btnContent = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Saving...</span>';
                submitBtn.disabled = true;

                setTimeout(function () {
                    if (submitBtn.disabled) {
                        submitBtn.innerHTML = btnContent;
                        submitBtn.disabled = false;
                    }
                }, 5000);
            });
        })();
    </script>
</body>
</html>
