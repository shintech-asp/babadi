<?php
chdir(dirname(__DIR__));
// config/send_email.php
require_once 'config.php';

class EmailSender {
    private $smtp_host = SMTP_HOST;
    private $smtp_port = SMTP_PORT;
    private $smtp_username = SMTP_USERNAME;
    private $smtp_password = SMTP_PASSWORD;
    private $from_email = NOREPLY_EMAIL;
    private $from_name = SITE_NAME;
    
    public function sendVerificationEmail($to_email, $to_name, $verification_token) {
        $subject = "Verify Your Email - " . SITE_NAME;
        $verification_link = SITE_URL . "/verify.php?token=" . $verification_token;
        
        $message = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #2c5aa0; color: white; padding: 20px; text-align: center; }
                .content { padding: 30px; background: #f9f9f9; }
                .button { display: inline-block; padding: 12px 24px; background: #2c5aa0; 
                         color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
                .otp-box { background: #fff; border: 2px dashed #2c5aa0; padding: 15px; 
                          text-align: center; font-size: 24px; font-weight: bold; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>" . SITE_NAME . "</h1>
                </div>
                <div class='content'>
                    <h2>Verify Your Email Address</h2>
                    <p>Hello " . htmlspecialchars($to_name) . ",</p>
                    <p>Thank you for registering with " . SITE_NAME . ". Please verify your email address by clicking the button below:</p>
                    
                    <div style='text-align: center;'>
                        <a href='" . $verification_link . "' class='button'>Verify Email Address</a>
                    </div>
                    
                    <p>Or copy and paste this link in your browser:</p>
                    <p><small>" . $verification_link . "</small></p>
                    
                    <p>This verification link will expire in 24 hours.</p>
                    
                    <p>If you did not create an account, please ignore this email.</p>
                </div>
                <div class='footer'>
                    <p>© " . date('Y') . " " . SITE_NAME . ". All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";
        
        return $this->sendEmail($to_email, $subject, $message);
    }
    
    public function sendOTPEmail($to_email, $to_name, $otp_code) {
        $subject = "Your Verification Code - " . SITE_NAME;
        
        $message = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #2c5aa0; color: white; padding: 20px; text-align: center; }
                .content { padding: 30px; background: #f9f9f9; }
                .otp-box { background: #fff; border: 2px dashed #2c5aa0; padding: 20px; 
                          text-align: center; font-size: 32px; font-weight: bold; 
                          letter-spacing: 10px; margin: 30px 0; color: #2c5aa0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>" . SITE_NAME . "</h1>
                </div>
                <div class='content'>
                    <h2>Your Verification Code</h2>
                    <p>Hello " . htmlspecialchars($to_name) . ",</p>
                    <p>Use the following OTP (One-Time Password) to complete your email verification:</p>
                    
                    <div class='otp-box'>
                        " . $otp_code . "
                    </div>
                    
                    <p>This OTP is valid for 15 minutes.</p>
                    <p>If you did not request this verification, please ignore this email.</p>
                    <p><strong>Security Tip:</strong> Never share your OTP with anyone.</p>
                </div>
                <div class='footer'>
                    <p>© " . date('Y') . " " . SITE_NAME . ". All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";
        
        return $this->sendEmail($to_email, $subject, $message);
    }
    
    private function sendEmail($to, $subject, $message) {
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: " . $this->from_name . " <" . $this->from_email . ">" . "\r\n";
        $headers .= "Reply-To: " . $this->from_email . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
        
        return mail($to, $subject, $message, $headers);
    }
}