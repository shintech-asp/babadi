<?php
// Shared "new employee welcome" email — extracted from
// provider-portal/employees.php so the mobile employees CRUD endpoint
// (api/v1/portal/hr/employees/store.php) sends the exact same email
// instead of maintaining a second copy that could drift (see CLAUDE.md's
// Recent Work Log for the bug class this avoids).

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!function_exists('sendEmployeeWelcomeEmail')) {
    function sendEmployeeWelcomeEmail($to_email, $to_name, $employee_id, $temp_password, $company) {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = SMTP_PORT;

            $mail->setFrom(NOREPLY_EMAIL, $company . ' Portal');
            $mail->addAddress($to_email, $to_name);
            $mail->isHTML(true);
            $mail->Subject = 'Welcome to ' . $company . ' — Your Portal Account';

            $login_url = SITE_URL . '/provider-portal/login.php';
            $mail->Body = '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body style="margin:0;padding:0;background:#f0f4f1;font-family:\'DM Sans\',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4f1;padding:40px 16px;">
  <tr><td align="center">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;">

      <!-- Header -->
      <tr><td style="background:linear-gradient(135deg,#1c6b3f,#2E8B57,#38a169);border-radius:16px 16px 0 0;padding:36px 40px;text-align:center;">
        <div style="display:inline-flex;align-items:center;justify-content:center;width:60px;height:60px;background:rgba(255,255,255,.15);border:1.5px solid rgba(255,255,255,.25);border-radius:14px;margin-bottom:16px;">
          <span style="font-size:26px;">🌿</span>
        </div>
        <h1 style="margin:0;color:#fff;font-size:22px;font-weight:700;letter-spacing:-.3px;">Welcome to ' . htmlspecialchars($company) . '</h1>
        <p style="margin:6px 0 0;color:rgba(255,255,255,.8);font-size:14px;">Your employee portal account is ready</p>
      </td></tr>

      <!-- Body -->
      <tr><td style="background:#ffffff;padding:36px 40px;">
        <p style="margin:0 0 20px;color:#1e2d27;font-size:15px;line-height:1.6;">
          Hi <strong>' . htmlspecialchars($to_name) . '</strong>,<br><br>
          Your account has been created on the <strong>' . htmlspecialchars($company) . '</strong> employee portal. Use the credentials below to log in for the first time.
        </p>

        <!-- Credentials box -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f6faf8;border:1.5px solid #dde5e0;border-radius:12px;margin-bottom:24px;">
          <tr><td style="padding:24px 28px;">
            <p style="margin:0 0 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#6b8077;">Your Login Credentials</p>
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="padding:8px 0;border-bottom:1px solid #dde5e0;">
                  <span style="font-size:12px;color:#6b8077;font-weight:600;">Employee ID</span>
                </td>
                <td style="padding:8px 0;border-bottom:1px solid #dde5e0;text-align:right;">
                  <code style="font-size:14px;font-weight:700;color:#2E8B57;background:#e8f5ee;padding:3px 10px;border-radius:6px;">' . htmlspecialchars($employee_id) . '</code>
                </td>
              </tr>
              <tr>
                <td style="padding:8px 0;">
                  <span style="font-size:12px;color:#6b8077;font-weight:600;">Temporary Password</span>
                </td>
                <td style="padding:8px 0;text-align:right;">
                  <code style="font-size:14px;font-weight:700;color:#2E8B57;background:#e8f5ee;padding:3px 10px;border-radius:6px;">' . htmlspecialchars($temp_password) . '</code>
                </td>
              </tr>
            </table>
          </td></tr>
        </table>

        <!-- Warning -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#fefcbf;border:1px solid rgba(217,119,6,.25);border-radius:10px;margin-bottom:28px;">
          <tr><td style="padding:14px 18px;font-size:13px;color:#78350f;">
            ⚠️ <strong>Important:</strong> You will be required to change this password upon your first login. Do not share your credentials with anyone.
          </td></tr>
        </table>

        <!-- CTA Button -->
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr><td align="center">
            <a href="' . $login_url . '" style="display:inline-block;background:linear-gradient(135deg,#2E8B57,#38a169);color:#fff;font-size:15px;font-weight:700;text-decoration:none;padding:14px 36px;border-radius:10px;letter-spacing:.1px;">
              Log In to Portal →
            </a>
          </td></tr>
        </table>
      </td></tr>

      <!-- Footer -->
      <tr><td style="background:#f6faf8;border:1px solid #dde5e0;border-top:none;border-radius:0 0 16px 16px;padding:20px 40px;text-align:center;">
        <p style="margin:0;font-size:12px;color:#9ab3aa;line-height:1.6;">
          This is an automated message from <strong>' . htmlspecialchars($company) . '</strong>.<br>
          If you did not expect this email, please contact your HR administrator.
        </p>
      </td></tr>

    </table>
  </td></tr>
</table>
</body>
</html>';

            $mail->AltBody = "Welcome to " . $company . "!\n\nYour portal account credentials:\nEmployee ID: $employee_id\nTemporary Password: $temp_password\n\nLog in at: $login_url\n\nYou will be required to change your password on first login.";

            $mail->send();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
