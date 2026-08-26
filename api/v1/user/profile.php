<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('GET', 'POST');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = current_user();

    $profile = user_payload($user);
    $profile['address']  = $user['address']  ?? null;
    $profile['city']     = $user['city']     ?? null;
    $profile['state']    = $user['state']    ?? null;
    $profile['zip_code'] = $user['zip_code'] ?? null;
    $profile['phone']    = $user['phone']    ?? null;

    if ($user['user_type'] === 'provider') {
        $pdo  = db();
        $stmt = $pdo->prepare(
            'SELECT company_name, logo_url, service_radius, description
               FROM providers
              WHERE user_id = ?
              LIMIT 1'
        );
        $stmt->execute([$user['id']]);
        $provider = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($provider) {
            $profile['company_name']    = $provider['company_name'];
            $profile['logo_url']        = $provider['logo_url'];
            $profile['service_radius']  = $provider['service_radius'];
            $profile['description']     = $provider['description'];
        }
    }

    ok(['user' => $profile]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = current_user();
    $pdo  = db();

    $first_name = inp('first_name');
    $last_name  = inp('last_name');
    $phone      = inp('phone');
    $address    = inp('address');
    $city       = inp('city');
    $state      = inp('state');
    $zip_code   = inp('zip_code');

    $userFields = [];
    $userParams = [];

    if ($first_name !== null) { $userFields[] = 'first_name = ?'; $userParams[] = $first_name; }
    if ($last_name  !== null) { $userFields[] = 'last_name = ?';  $userParams[] = $last_name;  }
    if ($phone      !== null) { $userFields[] = 'phone = ?';      $userParams[] = $phone;      }
    if ($address    !== null) { $userFields[] = 'address = ?';    $userParams[] = $address;    }
    if ($city       !== null) { $userFields[] = 'city = ?';       $userParams[] = $city;       }
    if ($state      !== null) { $userFields[] = 'state = ?';      $userParams[] = $state;      }
    if ($zip_code   !== null) { $userFields[] = 'zip_code = ?';   $userParams[] = $zip_code;   }

    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['avatar'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            fail('Avatar upload failed with error code ' . $file['error']);
        }

        $allowedMime = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $allowedMime, true)) {
            fail('Avatar must be a jpg, jpeg, png, or gif image.');
        }

        $maxBytes = 5 * 1024 * 1024;
        if ($file['size'] > $maxBytes) {
            fail('Avatar must not exceed 5MB.');
        }

        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'profile_' . $user['id'] . '_' . time() . '.' . strtolower($ext);
        $uploadDir = dirname(__DIR__, 1) . '/../../uploads/profile/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $dest = $uploadDir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            fail('Failed to save avatar.');
        }

        $userFields[] = 'profile_image = ?';
        $userParams[] = 'uploads/profile/' . $filename;
    }

    if (!empty($userFields)) {
        $userParams[] = $user['id'];
        $sql = 'UPDATE users SET ' . implode(', ', $userFields) . ' WHERE id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($userParams);
    }

    if ($user['user_type'] === 'provider') {
        $company_name   = inp('company_name');
        $description    = inp('description');
        $service_radius = inp('service_radius');

        $provFields = [];
        $provParams = [];

        if ($company_name   !== null) { $provFields[] = 'company_name = ?';   $provParams[] = $company_name;   }
        if ($description    !== null) { $provFields[] = 'description = ?';     $provParams[] = $description;    }
        if ($service_radius !== null) { $provFields[] = 'service_radius = ?';  $provParams[] = $service_radius; }

        if (!empty($provFields)) {
            $provParams[] = $user['id'];
            $sql = 'UPDATE providers SET ' . implode(', ', $provFields) . ' WHERE user_id = ?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($provParams);
        }
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$user['id']]);
    $updatedUser = $stmt->fetch(PDO::FETCH_ASSOC);

    ok(['user' => user_payload($updatedUser)]);
}
