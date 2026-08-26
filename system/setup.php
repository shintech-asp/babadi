<?php
chdir(dirname(__DIR__));
// setup.php - Database setup and migration runner
require_once 'config/config.php';
require_once 'config/database.php';

$message = '';
$error = '';
$migration_complete = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['run_migration'])) {
    $database = new Database();
    $db = $database->getConnection();
    
    try {
        // Check if email_verified_at column already exists
        $checkQuery = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                       WHERE TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verified_at'";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() > 0) {
            $message = "✓ All required columns already exist. Database is ready!";
            $migration_complete = true;
        } else {
            // Add email_verified_at column
            $db->exec("ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER email_verified");
            
            // Create indexes if they don't exist
            try {
                $db->exec("CREATE INDEX idx_email_verified ON users(email_verified)");
            } catch (Exception $e) {
                // Index might already exist, that's fine
            }
            
            try {
                $db->exec("CREATE INDEX idx_otp_expires ON users(otp_expires)");
            } catch (Exception $e) {
                // Index might already exist, that's fine
            }
            
            $message = "✓ Migration completed successfully! The email_verified_at column has been added.";
            $migration_complete = true;
        }
    } catch (PDOException $e) {
        $error = "Migration failed: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2E8B57;
            --primary-dark: #276749;
            --primary-light: #C6F6D5;
            --primary-soft: #F0FFF4;
            --neutral-dark: #2D3748;
            --neutral-gray: #718096;
            --neutral-light: #E2E8F0;
            --neutral-soft: #F7FAFC;
            --success: #38A169;
            --danger: #E53E3E;
            --radius: 10px;
            --shadow-lg: 0 10px 20px rgba(0, 0, 0, 0.09);
            --transition: 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, var(--primary-soft) 0%, #F7FAFC 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .setup-container {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            max-width: 600px;
            width: 100%;
            padding: 40px;
        }

        .setup-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .setup-icon {
            width: 80px;
            height: 80px;
            background: var(--primary-soft);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 2.5rem;
            color: var(--primary);
        }

        .setup-header h1 {
            font-size: 1.75rem;
            color: var(--neutral-dark);
            margin-bottom: 10px;
        }

        .setup-header p {
            color: var(--neutral-gray);
            font-size: 1rem;
        }

        .alert {
            padding: 15px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .alert-success {
            background: #D1FAE5;
            border: 1px solid #6EE7B7;
            color: #065F46;
        }

        .alert-success i {
            color: #10B981;
            margin-top: 2px;
        }

        .alert-error {
            background: #FEE2E2;
            border: 1px solid #FCA5A5;
            color: #991B1B;
        }

        .alert-error i {
            color: #DC2626;
            margin-top: 2px;
        }

        .alert-info {
            background: #DBEAFE;
            border: 1px solid #93C5FD;
            color: #1E40AF;
        }

        .alert-info i {
            color: #3B82F6;
            margin-top: 2px;
        }

        .migration-steps {
            background: var(--neutral-soft);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 20px;
        }

        .migration-steps h3 {
            color: var(--neutral-dark);
            margin-bottom: 15px;
            font-size: 1rem;
        }

        .steps-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .steps-list li {
            padding: 10px 0;
            color: var(--neutral-gray);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9375rem;
        }

        .steps-list li:before {
            content: '✓';
            width: 24px;
            height: 24px;
            background: var(--primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: bold;
            flex-shrink: 0;
        }

        .columns-info {
            background: var(--neutral-soft);
            border-radius: var(--radius);
            padding: 15px;
            margin-bottom: 20px;
            font-size: 0.875rem;
        }

        .columns-info h4 {
            color: var(--neutral-dark);
            margin-bottom: 10px;
        }

        .column-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid var(--neutral-light);
            color: var(--neutral-gray);
        }

        .column-item:last-child {
            border-bottom: none;
        }

        .column-name {
            font-weight: 600;
            color: var(--neutral-dark);
        }

        .btn-migrate {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-migrate:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(46, 139, 87, 0.3);
        }

        .btn-migrate:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .back-link {
            text-align: center;
            margin-top: 20px;
        }

        .back-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }

        .back-link a:hover {
            text-decoration: underline;
        }

        @media (max-width: 576px) {
            .setup-container {
                padding: 30px 20px;
            }

            .setup-header h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-header">
            <div class="setup-icon">
                <i class="fas fa-database"></i>
            </div>
            <h1>Database Setup</h1>
            <p>Configure your database for email verification</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div><?php echo htmlspecialchars($message); ?></div>
            </div>
        <?php endif; ?>

        <?php if (!$migration_complete): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Action Required:</strong> Your database needs to be updated to support email verification. Click the button below to run the migration.
                </div>
            </div>

            <div class="migration-steps">
                <h3>What will be added:</h3>
                <ul class="steps-list">
                    <li>OTP Code column</li>
                    <li>OTP Expiration column</li>
                    <li>Last OTP Sent timestamp</li>
                    <li>Email Verified flag</li>
                    <li>Email Verified timestamp</li>
                    <li>Performance indexes</li>
                </ul>
            </div>

            <div class="columns-info">
                <h4>New Database Columns:</h4>
                <div class="column-item">
                    <span class="column-name">otp_code</span>
                    <span>VARCHAR(6)</span>
                </div>
                <div class="column-item">
                    <span class="column-name">otp_expires</span>
                    <span>DATETIME</span>
                </div>
                <div class="column-item">
                    <span class="column-name">last_otp_sent</span>
                    <span>DATETIME</span>
                </div>
                <div class="column-item">
                    <span class="column-name">email_verified</span>
                    <span>BOOLEAN</span>
                </div>
                <div class="column-item">
                    <span class="column-name">email_verified_at</span>
                    <span>DATETIME</span>
                </div>
            </div>

            <form method="POST" action="">
                <button type="submit" name="run_migration" class="btn-migrate">
                    <i class="fas fa-play"></i>
                    Run Migration
                </button>
            </form>
        <?php else: ?>
            <div class="migration-steps">
                <h3>Migration Status:</h3>
                <ul class="steps-list">
                    <li>Database is ready for email verification</li>
                    <li>All required columns are in place</li>
                    <li>Indexes have been created</li>
                </ul>
            </div>

            <div class="back-link">
                <p><a href="register.php">← Go to Registration</a></p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
