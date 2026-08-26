<?php
chdir(dirname(__DIR__));
// provider-payment-settings.php - Provider payment method configuration
session_start();
require_once 'config/config.php';
require_once 'config/database.php';

// Check if user is logged in and is a provider
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'provider') {
    header("Location: " . appUrl('login.php'));
    exit();
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

// Get provider information
$query = "SELECT p.* FROM providers p WHERE p.user_id = :user_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $user_id);
$stmt->execute();
$provider = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$provider) {
    header("Location: " . appUrl('login.php'));
    exit();
}

$provider_id = $provider['id'];
$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $payment_method_type = isset($_POST['payment_method_type']) ? trim($_POST['payment_method_type']) : 'both';
    $downpayment_percentage = isset($_POST['downpayment_percentage']) ? intval($_POST['downpayment_percentage']) : 50;
    
    // Validate inputs
    if (!in_array($payment_method_type, ['full_payment', 'downpayment', 'both'])) {
        $error = "Invalid payment method type selected!";
    } elseif ($downpayment_percentage < 1 || $downpayment_percentage > 99) {
        $error = "Downpayment percentage must be between 1 and 99!";
    } else {
        // Update provider payment settings
        $query = "UPDATE providers SET 
                  payment_method_type = :payment_method_type,
                  downpayment_percentage = :downpayment_percentage,
                  payment_settings_updated_at = NOW()
                  WHERE id = :provider_id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':payment_method_type', $payment_method_type);
        $stmt->bindParam(':downpayment_percentage', $downpayment_percentage, PDO::PARAM_INT);
        $stmt->bindParam(':provider_id', $provider_id, PDO::PARAM_INT);
        
        if ($stmt->execute()) {
            $success = "Payment settings updated successfully!";
            // Refresh provider data
            $query = "SELECT p.* FROM providers p WHERE p.user_id = :user_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            $provider = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $error = "Failed to update payment settings. Please try again.";
        }
    }
}

// Get current settings
$current_payment_method = $provider['payment_method_type'] ?? 'both';
$current_downpayment_percentage = $provider['downpayment_percentage'] ?? 50;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Settings - Pestify Provider</title>
<link rel="stylesheet" href="<?= appUrl('assets/css/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #007bff;
            text-decoration: none;
            margin-bottom: 2rem;
            font-weight: 500;
            transition: gap 0.3s;
        }

        .back-link:hover {
            gap: 12px;
        }

        .page-header {
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 8px;
        }

        .page-subtitle {
            color: #666;
            font-size: 14px;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .card-title {
            font-size: 18px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-title i {
            color: #007bff;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .alert i {
            font-size: 18px;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-group label .required {
            color: #dc3545;
            margin-left: 3px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #ecf0f1;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }

        .form-hint {
            font-size: 12px;
            color: #666;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .payment-method-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .payment-option {
            position: relative;
        }

        .payment-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }

        .payment-option-label {
            display: block;
            padding: 1.5rem;
            border: 2px solid #ecf0f1;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }

        .payment-option input[type="radio"]:checked + .payment-option-label {
            border-color: #007bff;
            background: #f0f7ff;
        }

        .payment-option-label i {
            font-size: 24px;
            color: #007bff;
            margin-bottom: 8px;
            display: block;
        }

        .payment-option-label h4 {
            margin: 0 0 4px;
            font-size: 14px;
            color: #1a1a2e;
        }

        .payment-option-label p {
            margin: 0;
            font-size: 12px;
            color: #666;
        }

        .downpayment-settings {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .downpayment-settings h4 {
            margin: 0 0 1rem;
            font-size: 14px;
            color: #1a1a2e;
            font-weight: 600;
        }

        .percentage-slider {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .percentage-slider input[type="range"] {
            flex: 1;
            height: 6px;
            border-radius: 3px;
            background: #ddd;
            outline: none;
            -webkit-appearance: none;
        }

        .percentage-slider input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #007bff;
            cursor: pointer;
        }

        .percentage-slider input[type="range"]::-moz-range-thumb {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #007bff;
            cursor: pointer;
            border: none;
        }

        .percentage-display {
            min-width: 60px;
            text-align: center;
            font-weight: 600;
            color: #007bff;
            font-size: 16px;
        }

        .info-box {
            background: #fffbea;
            border-left: 4px solid #f39c12;
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
        }

        .info-box h4 {
            margin: 0 0 8px;
            font-size: 14px;
            color: #d68910;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-box p {
            margin: 0;
            font-size: 13px;
            color: #7f8c8d;
            line-height: 1.5;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #ecf0f1;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .btn-primary {
            background: #007bff;
            color: white;
        }

        .btn-primary:hover {
            background: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 123, 255, 0.3);
        }

        .btn-secondary {
            background: #ecf0f1;
            color: #1a1a2e;
            border: 2px solid #ecf0f1;
        }

        .btn-secondary:hover {
            background: #bdc3c7;
        }

        .settings-preview {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            margin-top: 2rem;
        }

        .settings-preview h4 {
            margin: 0 0 1rem;
            font-size: 14px;
            color: #1a1a2e;
            font-weight: 600;
        }

        .preview-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #ecf0f1;
            font-size: 13px;
        }

        .preview-item:last-child {
            border-bottom: none;
        }

        .preview-label {
            color: #666;
        }

        .preview-value {
            font-weight: 600;
            color: #1a1a2e;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .page-title {
                font-size: 22px;
            }

            .card {
                padding: 1.5rem;
            }

            .payment-method-options {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php $current_page = 'providers-dashboard'; include appPath('includes/header.php'); ?>
    
    <div class="container">
        <a href="<?php echo appUrl('providers-dashboard.php'); ?>" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-credit-card"></i> Payment Settings
            </h1>
            <p class="page-subtitle">Configure how you want to accept payments from customers</p>
        </div>
        
        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-title">
                <i class="fas fa-cog"></i> Payment Method Configuration
            </div>
            
            <div class="info-box">
                <h4><i class="fas fa-info-circle"></i> How This Works</h4>
                <p>
                    Choose your preferred payment method(s) for customers booking your services. 
                    If you select "Both", customers can choose between full payment or downpayment. 
                    The downpayment percentage you set here will be applied to all downpayment bookings.
                </p>
            </div>
            
            <form method="POST" id="paymentSettingsForm">
                <!-- Payment Method Type Selection -->
                <div class="form-group">
                    <label>Payment Method Type <span class="required">*</span></label>
                    <div class="payment-method-options">
                        <div class="payment-option">
                            <input type="radio" id="full_payment" name="payment_method_type" value="full_payment" 
                                   <?php echo ($current_payment_method === 'full_payment') ? 'checked' : ''; ?>>
                            <label for="full_payment" class="payment-option-label">
                                <i class="fas fa-money-bill-wave"></i>
                                <h4>Full Payment Only</h4>
                                <p>Customers must pay the full amount upfront</p>
                            </label>
                        </div>
                        
                        <div class="payment-option">
                            <input type="radio" id="downpayment" name="payment_method_type" value="downpayment" 
                                   <?php echo ($current_payment_method === 'downpayment') ? 'checked' : ''; ?>>
                            <label for="downpayment" class="payment-option-label">
                                <i class="fas fa-hand-holding-usd"></i>
                                <h4>Downpayment Only</h4>
                                <p>Customers pay a percentage upfront, rest on completion</p>
                            </label>
                        </div>
                        
                        <div class="payment-option">
                            <input type="radio" id="both" name="payment_method_type" value="both" 
                                   <?php echo ($current_payment_method === 'both') ? 'checked' : ''; ?>>
                            <label for="both" class="payment-option-label">
                                <i class="fas fa-exchange-alt"></i>
                                <h4>Both Options</h4>
                                <p>Let customers choose their preferred method</p>
                            </label>
                        </div>
                    </div>
                </div>
                
                <!-- Downpayment Percentage Setting -->
                <div class="downpayment-settings" id="downpaymentSettings">
                    <h4>Downpayment Percentage</h4>
                    <p style="font-size: 12px; color: #666; margin-bottom: 1rem;">
                        Set the percentage of the total service cost that customers must pay as downpayment
                    </p>
                    
                    <div class="percentage-slider">
                        <input type="range" id="downpaymentPercentage" name="downpayment_percentage" 
                               min="1" max="99" value="<?php echo $current_downpayment_percentage; ?>" 
                               oninput="updatePercentageDisplay(this.value)">
                        <div class="percentage-display" id="percentageDisplay">
                            <?php echo $current_downpayment_percentage; ?>%
                        </div>
                    </div>
                    
                    <div class="form-hint" style="margin-top: 1rem;">
                        <i class="fas fa-info-circle"></i>
                        <span>Customers will pay <strong id="percentageText"><?php echo $current_downpayment_percentage; ?>%</strong> upfront and the remaining <strong id="remainingText"><?php echo (100 - $current_downpayment_percentage); ?>%</strong> after service completion</span>
                    </div>
                </div>
                
                <!-- Settings Preview -->
                <div class="settings-preview">
                    <h4>Settings Preview</h4>
                    <div class="preview-item">
                        <span class="preview-label">Payment Method:</span>
                        <span class="preview-value" id="previewMethod">Both Options</span>
                    </div>
                    <div class="preview-item">
                        <span class="preview-label">Downpayment Percentage:</span>
                        <span class="preview-value" id="previewPercentage"><?php echo $current_downpayment_percentage; ?>%</span>
                    </div>
                    <div class="preview-item">
                        <span class="preview-label">Example (?10,000 service):</span>
                        <span class="preview-value" id="previewExample">?<?php echo number_format($current_downpayment_percentage * 100, 2); ?> down, ?<?php echo number_format((100 - $current_downpayment_percentage) * 100, 2); ?> later</span>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Payment Settings
                    </button>
                    <a href="<?php echo appUrl('providers-dashboard.php'); ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <?php include appPath('includes/footer.php'); ?>
    
    <script>
        // Update percentage display and preview
        function updatePercentageDisplay(value) {
            const percentage = parseInt(value);
            const remaining = 100 - percentage;
            
            document.getElementById('percentageDisplay').textContent = percentage + '%';
            document.getElementById('percentageText').textContent = percentage + '%';
            document.getElementById('remainingText').textContent = remaining + '%';
            document.getElementById('previewPercentage').textContent = percentage + '%';
            
            // Update example calculation
            const exampleTotal = 10000;
            const downpayment = (percentage / 100) * exampleTotal;
            const remainingAmount = exampleTotal - downpayment;
            document.getElementById('previewExample').textContent = 
                '?' + downpayment.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + 
                ' down, ?' + remainingAmount.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + 
                ' later';
        }
        
        // Update preview method text
        function updateMethodPreview() {
            const selected = document.querySelector('input[name="payment_method_type"]:checked').value;
            const methodMap = {
                'full_payment': 'Full Payment Only',
                'downpayment': 'Downpayment Only',
                'both': 'Both Options'
            };
            document.getElementById('previewMethod').textContent = methodMap[selected];
            
            // Show/hide downpayment settings based on selection
            const downpaymentSettings = document.getElementById('downpaymentSettings');
            if (selected === 'full_payment') {
                downpaymentSettings.style.display = 'none';
            } else {
                downpaymentSettings.style.display = 'block';
            }
        }
        
        // Add event listeners to radio buttons
        document.querySelectorAll('input[name="payment_method_type"]').forEach(radio => {
            radio.addEventListener('change', updateMethodPreview);
        });
        
        // Initialize on page load
        window.addEventListener('load', function() {
            updateMethodPreview();
        });
    </script>
    <?php include appPath('includes/provider-guide.php'); ?>
</body>
</html>
