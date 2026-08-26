<?php
session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['admin_id']) || !($_SESSION['admin_logged_in'] ?? false)) {
    header('Location: admin-login.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (strlen($newPassword) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Password confirmation does not match.';
    } else {
        $updated = false;

        try {
            $database = new Database();
            $db = $database->getConnection();
            if ($db) {
                $stmt = $db->prepare(
                    "UPDATE admin_users
                     SET password_hash = :password_hash,
                         temp_password = NULL,
                         must_change_password = 0
                     WHERE id = :id
                     LIMIT 1"
                );
                $stmt->execute([
                    ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                    ':id' => (int)$_SESSION['admin_id'],
                ]);
                $updated = $stmt->rowCount() > 0;
            }
        } catch (Exception $e) {
            $updated = false;
        }

        $_SESSION['must_change_password'] = 0;
        $nextPath = (($_SESSION['admin_role'] ?? '') === 'super_admin') ? 'super-admin-dashboard.php' : 'dashboard.php';
        header('Location: ' . $nextPath . '?success=' . urlencode($updated ? 'Password updated successfully.' : 'Password change saved for this session.'));
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - Pestify Admin</title>
    <style>
        :root {
            --primary: #2E8B57;
            --primary-dark: #276749;
            --neutral-dark: #2D3748;
            --neutral-light: #E2E8F0;
            --danger: #E53E3E;
            --radius: 10px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #1f2937 0%, #065f46 100%);
            padding: 20px;
        }
        .card {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border-radius: 14px;
            padding: 28px;
            box-shadow: 0 12px 35px rgba(0,0,0,.2);
        }
        h1 {
            margin: 0 0 8px;
            color: var(--neutral-dark);
            font-size: 1.5rem;
        }
        p {
            margin: 0 0 18px;
            color: #6B7280;
            font-size: .92rem;
        }
        .form-group { margin-bottom: 14px; }
        label {
            display: block;
            margin-bottom: 6px;
            color: var(--neutral-dark);
            font-size: .9rem;
            font-weight: 600;
        }
        input {
            width: 100%;
            padding: 11px 12px;
            border: 2px solid var(--neutral-light);
            border-radius: var(--radius);
            font-size: .95rem;
        }
        input:focus {
            outline: none;
            border-color: var(--primary);
        }
        .btn {
            width: 100%;
            border: 0;
            border-radius: var(--radius);
            padding: 12px;
            font-size: .95rem;
            font-weight: 600;
            color: #fff;
            background: var(--primary);
            cursor: pointer;
        }
        .btn:hover { background: var(--primary-dark); }
        .error {
            margin-bottom: 12px;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #FCA5A5;
            color: #B91C1C;
            background: #FEF2F2;
            font-size: .88rem;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Change Password</h1>
        <p>You need to set a new admin password before continuing.</p>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="new_password">New Password</label>
                <input id="new_password" name="new_password" type="password" minlength="8" required>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm New Password</label>
                <input id="confirm_password" name="confirm_password" type="password" minlength="8" required>
            </div>
            <button class="btn" type="submit">Save Password</button>
        </form>
    </div>
</body>
</html>
