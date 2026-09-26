<?php
// config/send_email.php

require_once 'config.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Include PHPMailer
require_once __DIR__ . '/../vendor/autoload.php';

class EmailSender {
    
    private $lastError = '';
    
    public function getLastError() {
        return $this->lastError;
    }
    
    private function initializeDebugLogging() {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
    }
    
    private function getMailer() {
        $this->initializeDebugLogging();
        $mail = new PHPMailer(true);
        
        try {
            // Validate SMTP config
            if (SMTP_USERNAME === 'your-email@gmail.com' || SMTP_PASSWORD === 'your-app-password') {
                throw new Exception('SMTP credentials not configured. Update config/config.php');
            }
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = SMTP_PORT;
            $mail->CharSet = 'UTF-8';
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );
            $mail->Timeout = 10;
            
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("EmailSender Config Error: " . $this->lastError);
        }
        
        return $mail;
    }
    
    public function sendWelcomeEmail($to_email, $to_name) {
        $mail = $this->getMailer();
        
        try {
            // Recipients
            $mail->setFrom(NOREPLY_EMAIL, SITE_NAME);
            $mail->addAddress($to_email, $to_name);
            
            // Subject
            $mail->Subject = "Welcome to " . SITE_NAME . "!";
            
            // HTML email template
            $message = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Welcome Email</title>
                <style>
                    body { font-family: Arial, sans-serif; background: #f4f4f4; padding: 20px; }
                    .container { max-width: 600px; background: white; margin: 0 auto; border-radius: 10px; overflow: hidden; }
                    .header { background: #2c5aa0; color: white; padding: 20px; text-align: center; }
                    .content { padding: 30px; }
                    .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; background: #f8f9fa; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>Welcome to ' . SITE_NAME . '!</h1>
                    </div>
                    <div class="content">
                        <p>Hello <strong>' . htmlspecialchars($to_name) . '</strong>,</p>
                        <p>Your account has been successfully verified and is now active!</p>
                        <p>You can now access all features of ' . SITE_NAME . '.</p>
                    </div>
                    <div class="footer">
                        <p>© ' . date('Y') . ' ' . SITE_NAME . '. All rights reserved.</p>
                    </div>
                </div>
            </body>
            </html>';
            
            $mail->isHTML(true);
            $mail->Body = $message;
            $mail->AltBody = "Your account is now verified!";
            
            $result = $mail->send();
            return $result;
            
        } catch (Exception $e) {
            $errorMsg = "Welcome email sending failed for $to_email: " . $e->getMessage();
            if (isset($mail)) {
                $errorMsg .= " | PHPMailer Error: " . $mail->ErrorInfo;
            }
            error_log($errorMsg);
            $this->lastError = $errorMsg;
            return false;
        }
    }
    
    public function sendPasswordResetEmail($to_email, $to_name, $reset_link) {
        $mail = $this->getMailer();
        
        try {
            // Recipients
            $mail->setFrom(NOREPLY_EMAIL, SITE_NAME);
            $mail->addAddress($to_email, $to_name);
            
            // Subject
            $mail->Subject = "Password Reset Request - " . SITE_NAME;
            
            // HTML email template
            $message = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Password Reset</title>
                <style>
                    body { font-family: Arial, sans-serif; background: #f4f4f4; padding: 20px; }
                    .container { max-width: 600px; background: white; margin: 0 auto; border-radius: 10px; overflow: hidden; }
                    .header { background: #2c5aa0; color: white; padding: 20px; text-align: center; }
                    .content { padding: 30px; }
                    .button-section { text-align: center; margin: 30px 0; }
                    .reset-button { background: #2c5aa0; color: white; padding: 14px 32px; text-decoration: none; border-radius: 6px; display: inline-block; font-weight: bold; }
                    .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; background: #f8f9fa; }
                    .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px; color: #856404; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>' . SITE_NAME . '</h1>
                    </div>
                    <div class="content">
                        <h2>Password Reset Request</h2>
                        <p>Hello <strong>' . htmlspecialchars($to_name) . '</strong>,</p>
                        
                        <p>We received a request to reset the password for your ' . SITE_NAME . ' account. Click the button below to create a new password:</p>
                        
                        <div class="button-section">
                            <a href="' . $reset_link . '" class="reset-button">Reset Your Password</a>
                        </div>
                        
                        <p style="color: #666; font-size: 14px;">Or copy and paste this link in your browser:</p>
                        <p style="background: #f8f9fa; padding: 12px; border-radius: 4px; word-break: break-all; font-size: 12px; color: #666;">
                            ' . $reset_link . '
                        </p>
                        
                        <div class="warning">
                            <p style="margin: 0;"><strong>⏰ This link will expire in 1 hour</strong></p>
                            <p style="margin: 8px 0 0; font-size: 14px;">If you did not request this, please ignore this email and your password will remain unchanged.</p>
                        </div>
                        
                        <p style="margin-top: 30px; color: #999; font-size: 12px;">
                            If you have any questions, please contact our support team.
                        </p>
                    </div>
                    <div class="footer">
                        <p>© ' . date('Y') . ' ' . SITE_NAME . '. All rights reserved.</p>
                        <p>This is an automated email, please do not reply.</p>
                    </div>
                </div>
            </body>
            </html>';
            
            $mail->isHTML(true);
            $mail->Body = $message;
            $mail->AltBody = "Click here to reset your password: " . $reset_link;
            
            $result = $mail->send();
            return $result;
            
        } catch (Exception $e) {
            $errorMsg = "Password reset email sending failed for $to_email: " . $e->getMessage();
            if (isset($mail)) {
                $errorMsg .= " | PHPMailer Error: " . $mail->ErrorInfo;
            }
            error_log($errorMsg);
            $this->lastError = $errorMsg;
            return false;
        }
    }
    
    public function sendCustomEmail($to_email, $to_name, $subject, $body_text, $from_name = null, $cta_label = null, $cta_url = null) {
        $mail = $this->getMailer();

        try {
            $displayFrom = $from_name ?: SITE_NAME;

            $mail->setFrom(NOREPLY_EMAIL, $displayFrom);
            $mail->addAddress($to_email, $to_name);
            $mail->Subject = $subject;

            $safeBody = nl2br(htmlspecialchars($body_text));

            $ctaHtml = '';
            $ctaAlt  = '';
            if ($cta_url) {
                $safeUrl   = htmlspecialchars($cta_url);
                $safeLabel = htmlspecialchars($cta_label ?: 'View & Book');
                $ctaHtml = '
                    <p style="text-align:center;margin:26px 0 8px;">
                        <a href="' . $safeUrl . '" style="background:#2E8B57;color:#fff;padding:13px 28px;border-radius:8px;text-decoration:none;font-weight:700;display:inline-block;">' . $safeLabel . '</a>
                    </p>
                    <p style="text-align:center;font-size:11px;color:#999;">Or copy this link: <a href="' . $safeUrl . '" style="color:#2E8B57;">' . $safeUrl . '</a></p>';
                $ctaAlt = "\n\n" . ($cta_label ?: 'View & Book') . ": " . $cta_url;
            }

            $message = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>' . htmlspecialchars($subject) . '</title>
                <style>
                    body { font-family: Arial, sans-serif; background: #f4f4f4; padding: 20px; }
                    .container { max-width: 600px; background: white; margin: 0 auto; border-radius: 10px; overflow: hidden; }
                    .header { background: #2E8B57; color: white; padding: 20px; text-align: center; }
                    .content { padding: 30px; line-height: 1.6; color: #333; }
                    .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; background: #f8f9fa; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>' . htmlspecialchars($displayFrom) . '</h1>
                    </div>
                    <div class="content">
                        <p>Hello <strong>' . htmlspecialchars($to_name) . '</strong>,</p>
                        <p>' . $safeBody . '</p>
                        ' . $ctaHtml . '
                    </div>
                    <div class="footer">
                        <p>Sent via ' . SITE_NAME . ' &middot; &copy; ' . date('Y') . '</p>
                    </div>
                </div>
            </body>
            </html>';

            $mail->isHTML(true);
            $mail->Body = $message;
            $mail->AltBody = $body_text . $ctaAlt;

            return $mail->send();

        } catch (Exception $e) {
            $errorMsg = "Custom email sending failed for $to_email: " . $e->getMessage();
            if (isset($mail)) {
                $errorMsg .= " | PHPMailer Error: " . $mail->ErrorInfo;
            }
            error_log($errorMsg);
            $this->lastError = $errorMsg;
            return false;
        }
    }

    public function sendOTPEmail($to_email, $to_name, $otp) {
        $mail = $this->getMailer();
        
        try {
            // Recipients
            $mail->setFrom(NOREPLY_EMAIL, SITE_NAME);
            $mail->addAddress($to_email, $to_name);
            
            // Subject
            $mail->Subject = "Your Password Reset OTP - " . SITE_NAME;
            
            // HTML email template
            $message = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Password Reset OTP</title>
                <style>
                    body { font-family: Arial, sans-serif; background: #f4f4f4; padding: 20px; }
                    .container { max-width: 600px; background: white; margin: 0 auto; border-radius: 10px; overflow: hidden; }
                    .header { background: #2c5aa0; color: white; padding: 20px; text-align: center; }
                    .content { padding: 30px; }
                    .otp-section { text-align: center; margin: 30px 0; }
                    .otp-code { background: #f0f8ff; border: 2px dashed #2c5aa0; padding: 20px; border-radius: 6px; font-size: 36px; font-weight: bold; color: #2c5aa0; letter-spacing: 5px; font-family: "Courier New", monospace; }
                    .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; background: #f8f9fa; }
                    .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px; color: #856404; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>' . SITE_NAME . '</h1>
                    </div>
                    <div class="content">
                        <h2>Password Reset OTP</h2>
                        <p>Hello <strong>' . htmlspecialchars($to_name) . '</strong>,</p>
                        
                        <p>We received a request to reset the password for your ' . SITE_NAME . ' account. Use the One-Time Password (OTP) below to verify your identity:</p>
                        
                        <div class="otp-section">
                            <div class="otp-code">' . htmlspecialchars($otp) . '</div>
                        </div>
                        
                        <p style="text-align: center; color: #666; font-size: 14px;">Enter this code in the password reset form</p>
                        
                        <div class="warning">
                            <p style="margin: 0;"><strong>⏰ This OTP will expire in 15 minutes</strong></p>
                            <p style="margin: 8px 0 0; font-size: 14px;">Do not share this OTP with anyone. ' . SITE_NAME . ' support will never ask for your OTP.</p>
                            <p style="margin: 8px 0 0; font-size: 14px;">If you did not request this, please ignore this email and your password will remain unchanged.</p>
                        </div>
                        
                        <p style="margin-top: 30px; color: #999; font-size: 12px;">
                            If you have any questions, please contact our support team.
                        </p>
                    </div>
                    <div class="footer">
                        <p>© ' . date('Y') . ' ' . SITE_NAME . '. All rights reserved.</p>
                        <p>This is an automated email, please do not reply.</p>
                    </div>
                </div>
            </body>
            </html>';
            
            $mail->isHTML(true);
            $mail->Body = $message;
            $mail->AltBody = "Your OTP is: " . $otp . ". This OTP will expire in 15 minutes.";
            
            $result = $mail->send();
            return $result;
            
        } catch (Exception $e) {
            $errorMsg = "OTP email sending failed for $to_email: " . $e->getMessage();
            if (isset($mail)) {
                $errorMsg .= " | PHPMailer Error: " . $mail->ErrorInfo;
            }
            error_log($errorMsg);
            $this->lastError = $errorMsg;
            return false;
        }
    }
}