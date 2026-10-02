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
            'SELECT company_name, logo_url, service_radius, description, portfolio_images, portfolio_videos
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
            $profile['portfolio_images'] = json_decode($provider['portfolio_images'] ?? '[]', true) ?: [];
            $profile['portfolio_videos'] = json_decode($provider['portfolio_videos'] ?? '[]', true) ?: [];
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

        // Portfolio gallery — showcase photos/video of the provider's own
        // business, distinct from per-service images/videos (see Phase 4's
        // services.images/videos). New photos append (cap 8 total); a new
        // video replaces the existing one. Mirrors the web's profile.php
        // upload_portfolio handler.

        // Remove a single portfolio photo by index — mirrors the web's
        // ?remove_portfolio_image= GET link.
        $removeIdx = inp('remove_portfolio_image_index');
        if ($removeIdx !== null && ctype_digit((string)$removeIdx)) {
            $pstmt = $pdo->prepare('SELECT portfolio_images FROM providers WHERE user_id = ? LIMIT 1');
            $pstmt->execute([$user['id']]);
            $currentImages = json_decode($pstmt->fetchColumn() ?: '[]', true);
            if (is_array($currentImages) && isset($currentImages[(int)$removeIdx])) {
                unset($currentImages[(int)$removeIdx]);
                $currentImages = array_values($currentImages);
                $provFields[] = 'portfolio_images = ?';
                $provParams[] = !empty($currentImages) ? json_encode($currentImages) : null;
            }
        }

        if (!empty($_FILES['portfolio_images']['name'][0] ?? '')) {
            $pstmt = $pdo->prepare('SELECT portfolio_images FROM providers WHERE user_id = ? LIMIT 1');
            $pstmt->execute([$user['id']]);
            $existingPortfolioImages = json_decode($pstmt->fetchColumn() ?: '[]', true);
            if (!is_array($existingPortfolioImages)) $existingPortfolioImages = [];

            $portfolioUploadDir = dirname(__DIR__, 3) . '/uploads/portfolio/';
            if (!is_dir($portfolioUploadDir)) {
                mkdir($portfolioUploadDir, 0755, true);
            }

            $imgCount = count($_FILES['portfolio_images']['name']);
            if (count($existingPortfolioImages) + $imgCount > 8) {
                fail('You can have up to 8 portfolio photos total.');
            }

            $allowedMime = ['image/jpeg', 'image/jpg', 'image/png'];
            $allowedExt  = ['jpg', 'jpeg', 'png'];
            for ($i = 0; $i < $imgCount; $i++) {
                if (($_FILES['portfolio_images']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                if ($_FILES['portfolio_images']['error'][$i] !== UPLOAD_ERR_OK) {
                    fail('Photo upload failed.');
                }
                if ($_FILES['portfolio_images']['size'][$i] > 5 * 1024 * 1024) {
                    fail('Each photo must be 5MB or smaller.');
                }
                $ext = strtolower(pathinfo($_FILES['portfolio_images']['name'][$i], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt, true)) {
                    fail('Only jpg, jpeg, and png photos are allowed.');
                }
                $mime = mime_content_type($_FILES['portfolio_images']['tmp_name'][$i]);
                if (!in_array($mime, $allowedMime, true)) {
                    fail('Invalid photo file type detected.');
                }
                $filename = uniqid('portfolio_', true) . '.' . $ext;
                if (!move_uploaded_file($_FILES['portfolio_images']['tmp_name'][$i], $portfolioUploadDir . $filename)) {
                    fail('Failed to save uploaded photo.');
                }
                $existingPortfolioImages[] = 'uploads/portfolio/' . $filename;
            }

            $provFields[] = 'portfolio_images = ?';
            $provParams[] = json_encode($existingPortfolioImages);
        }

        // Single field name 'portfolio_video', used identically by the web
        // form and the Flutter app's MultipartFile (both send one plain
        // file, no array brackets).
        $portfolioVideoFile = !empty($_FILES['portfolio_video']['name']) ? $_FILES['portfolio_video'] : null;
        if ($portfolioVideoFile !== null && $portfolioVideoFile['error'] === UPLOAD_ERR_OK) {
            if ($portfolioVideoFile['size'] > 20 * 1024 * 1024) {
                fail('Video must be 20MB or smaller.');
            }
            $vExt = strtolower(pathinfo($portfolioVideoFile['name'], PATHINFO_EXTENSION));
            if (!in_array($vExt, ['mp4', 'mov'], true)) {
                fail('Only mp4 and mov videos are allowed.');
            }
            $portfolioUploadDir = dirname(__DIR__, 3) . '/uploads/portfolio/';
            if (!is_dir($portfolioUploadDir)) {
                mkdir($portfolioUploadDir, 0755, true);
            }
            $vFilename = uniqid('portfolio_', true) . '.' . $vExt;
            if (!move_uploaded_file($portfolioVideoFile['tmp_name'], $portfolioUploadDir . $vFilename)) {
                fail('Failed to save uploaded video.');
            }
            $provFields[] = 'portfolio_videos = ?';
            $provParams[] = json_encode(['uploads/portfolio/' . $vFilename]);
        }

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
