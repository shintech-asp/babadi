<?php
chdir(dirname(__DIR__));
// check-db.php - Database Connection Checker
require_once 'config/config.php';
require_once 'config/database.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Connection Check - Pestify</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 40px 20px; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); }
        h1 { color: #333; margin-bottom: 30px; text-align: center; }
        .status { padding: 15px; margin-bottom: 20px; border-radius: 5px; border-left: 4px solid; }
        .status.success { background: #d4edda; border-color: #28a745; color: #155724; }
        .status.error { background: #f8d7da; border-color: #dc3545; color: #721c24; }
        .info-box { background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid #007bff; }
        .info-box strong { display: block; color: #007bff; margin-bottom: 10px; }
        .info-box p { color: #555; font-size: 14px; line-height: 1.6; }
        .instructions { background: #fff3cd; padding: 20px; border-radius: 5px; border-left: 4px solid #ffc107; margin-top: 20px; }
        .instructions h2 { color: #856404; font-size: 16px; margin-bottom: 10px; }
        .instructions ol { margin-left: 20px; color: #856404; }
        .instructions li { margin-bottom: 8px; }
        code { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; font-family: 'Courier New', monospace; font-size: 13px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Database Connection Check</h1>
        
        <?php
chdir(dirname(__DIR__));
        // Test database connection
        $database = new Database();
        $db = $database->getConnection();
        
        if ($db) {
            echo '<div class="status success">✓ Database connection successful!</div>';
            
            // Try to get database info
            try {
                $stmt = $db->query("SELECT DATABASE() as db_name; SELECT VERSION() as version;");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                echo '<div class="info-box">';
                echo '<strong>Connection Details:</strong>';
                echo '<p><strong>Host:</strong> ' . htmlspecialchars(DB_HOST) . '</p>';
                echo '<p><strong>Database:</strong> ' . htmlspecialchars(DB_NAME) . '</p>';
                echo '<p><strong>User:</strong> ' . htmlspecialchars(DB_USER) . '</p>';
                echo '</div>';
                
                echo '<div class="status success">✓ Database is accessible and ready to use!</div>';
            } catch (Exception $e) {
                echo '<div class="status error">⚠ Database connected but unable to fetch info: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        } else {
            echo '<div class="status error">✗ Database connection failed!</div>';
            
            $error = $database->getError();
            if ($error) {
                echo '<div class="info-box">';
                echo '<strong>Error Details:</strong>';
                echo '<p style="color: #dc3545; word-break: break-all;">' . htmlspecialchars($error) . '</p>';
                echo '</div>';
            }
            
            echo '<div class="info-box">';
            echo '<strong>Configuration Details:</strong>';
            echo '<p><strong>Host:</strong> ' . htmlspecialchars(DB_HOST) . '</p>';
            echo '<p><strong>Database:</strong> ' . htmlspecialchars(DB_NAME) . '</p>';
            echo '<p><strong>User:</strong> ' . htmlspecialchars(DB_USER) . '</p>';
            echo '<p><strong>Password:</strong> ' . (DB_PASS ? '(set)' : '(empty)') . '</p>';
            echo '</div>';
            
            echo '<div class="instructions">';
            echo '<h2>How to Fix MySQL Connection Issues:</h2>';
            echo '<ol>';
            echo '<li><strong>Start MySQL Server:</strong>';
            echo '<ol type="a" style="margin-top: 5px;">';
            echo '<li>If using XAMPP on Windows, open XAMPP Control Panel</li>';
            echo '<li>Click the <code>Start</code> button next to "MySQL"</li>';
            echo '<li>Wait for it to show "Running" (green highlight)</li>';
            echo '</ol></li>';
            echo '<li><strong>Verify MySQL is running:</strong>';
            echo '<ol type="a" style="margin-top: 5px;">';
            echo '<li>Open Command Prompt (Run as Administrator)</li>';
            echo '<li>Type: <code>netstat -ano | findstr 3306</code></li>';
            echo '<li>If you see listening ports, MySQL is running</li>';
            echo '</ol></li>';
            echo '<li><strong>Check database exists:</strong>';
            echo '<ol type="a" style="margin-top: 5px;">';
            echo '<li>Open phpMyAdmin (usually at http://localhost/phpmyadmin)</li>';
            echo '<li>Check if database "' . htmlspecialchars(DB_NAME) . '" exists in the left sidebar</li>';
            echo '<li>If not, import the SQL file: <code>pestify (6).sql</code></li>';
            echo '</ol></li>';
            echo '<li><strong>Verify credentials:</strong>';
            echo '<ol type="a" style="margin-top: 5px;">';
            echo '<li>Default MySQL user is <code>root</code> with empty password</li>';
            echo '<li>If you changed the password, update <code>config/config.php</code></li>';
            echo '</ol></li>';
            echo '</ol>';
            echo '</div>';
        }
        ?>
        
        <div style="text-align: center; margin-top: 30px;">
            <a href="javascript:location.reload()" style="display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;">🔄 Refresh Check</a>
            <a href="index.php" style="display: inline-block; padding: 10px 20px; background: #28a745; color: white; text-decoration: none; border-radius: 5px; font-weight: bold; margin-left: 10px;">← Back to Home</a>
        </div>
    </div>
</body>
</html>
