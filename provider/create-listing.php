<?php
chdir(dirname(__DIR__));
// create-listing.php
require_once 'config/config.php';
require_once 'config/database.php';

if (!isLoggedIn() || !isProvider()) {
    redirect('login.php');
}

$database = new Database();
$db = $database->getConnection();

// Check if provider profile is complete
$provider_check = $db->prepare('SELECT business_registration_file, license_file, address, city, state FROM providers WHERE user_id = :uid');
$provider_check->bindParam(':uid', $_SESSION['user_id']);
$provider_check->execute();
$prov_data = $provider_check->fetch(PDO::FETCH_ASSOC);

if (!$prov_data || empty($prov_data['business_registration_file']) || empty($prov_data['license_file']) || 
    empty($prov_data['address']) || empty($prov_data['city']) ||
    strcasecmp(trim((string)($prov_data['state'] ?? '')), 'Cavite') !== 0) {
    redirect('provider-setup.php');
}
$error = '';
$success = '';
$edit_mode = false;
$service = null;

// Get categories
$query = "SELECT * FROM service_categories ORDER BY name";
$stmt = $db->prepare($query);
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if editing
if (isset($_GET['edit'])) {
    $edit_mode = true;
    $query = "SELECT * FROM service_listings WHERE id = :id AND provider_id = :provider_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $_GET['edit']);
    $stmt->bindParam(':provider_id', $_SESSION['provider_id']);
    $stmt->execute();
    $service = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$service) {
        redirect('dashboard.php');
    }
}

// Handle image deletion
if ($edit_mode && isset($_GET['delete_image'])) {
    $image_index = intval($_GET['delete_image']);
    $images = json_decode($service['images'], true);
    
    if (isset($images[$image_index])) {
        // Delete file from server
        $image_path = $images[$image_index];
        if (file_exists($image_path)) {
            unlink($image_path);
        }
        
        // Remove from array
        array_splice($images, $image_index, 1);
        
        // Update database
        $images_json = json_encode(array_values($images));
        $query = "UPDATE service_listings SET images = :images WHERE id = :id AND provider_id = :provider_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':images', $images_json);
        $stmt->bindParam(':id', $service['id']);
        $stmt->bindParam(':provider_id', $_SESSION['provider_id']);
        $stmt->execute();
        
        // Refresh service data
        $query = "SELECT * FROM service_listings WHERE id = :id AND provider_id = :provider_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $_GET['edit']);
        $stmt->bindParam(':provider_id', $_SESSION['provider_id']);
        $stmt->execute();
        $service = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $success = "Image deleted successfully!";
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = sanitize($_POST['title']);
    $description = sanitize($_POST['description']);
    $category_id = sanitize($_POST['category_id']);
    $price = sanitize($_POST['price']);
    $pricing_type = sanitize($_POST['pricing_type']);
    $status = sanitize($_POST['status']);
    $is_eco_friendly = isset($_POST['is_eco_friendly']) ? 1 : 0;
    $is_emergency_available = isset($_POST['is_emergency_available']) ? 1 : 0;
    
    // Handle file uploads
    $images = [];
    
    // Keep existing images if editing
    if ($edit_mode && $service['images']) {
        $images = json_decode($service['images'], true);
    }
    
    // Process uploaded files
    if (!empty($_FILES['images']['name'][0])) {
        $upload_dir = 'uploads/services/';
        
        // Create directory if it doesn't exist
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $max_size = 5 * 1024 * 1024; // 5MB
        $max_files = 5;
        
        $files = $_FILES['images'];
        $file_count = count($files['name']);
        
        // Limit to max 5 files
        $file_count = min($file_count, $max_files - count($images));
        
        for ($i = 0; $i < $file_count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                // Validate file type
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $files['tmp_name'][$i]);
                finfo_close($finfo);
                
                if (!in_array($mime_type, $allowed_types)) {
                    $error .= "File " . ($i + 1) . " is not an allowed image type. ";
                    continue;
                }
                
                // Validate file size
                if ($files['size'][$i] > $max_size) {
                    $error .= "File " . ($i + 1) . " exceeds the 5MB size limit. ";
                    continue;
                }
                
                // Generate unique filename
                $extension = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
                $filename = uniqid() . '_' . time() . '.' . $extension;
                $filepath = $upload_dir . $filename;
                
                // Move uploaded file
                if (move_uploaded_file($files['tmp_name'][$i], $filepath)) {
                    $images[] = $filepath;
                } else {
                    $error .= "Failed to upload file " . ($i + 1) . ". ";
                }
            }
        }
    }
    
    $images_json = json_encode(array_values($images));
    
    if ($edit_mode) {
        $query = "UPDATE service_listings SET title = :title, description = :description, category_id = :category_id, price = :price, pricing_type = :pricing_type, is_eco_friendly = :is_eco_friendly, is_emergency_available = :is_emergency_available, images = :images, status = :status, updated_at = NOW() WHERE id = :id AND provider_id = :provider_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $_GET['edit']);
        $stmt->bindParam(':provider_id', $_SESSION['provider_id']);
    } else {
        $query = "INSERT INTO service_listings (provider_id, category_id, title, description, price, pricing_type, is_eco_friendly, is_emergency_available, images, status, created_at) VALUES (:provider_id, :category_id, :title, :description, :price, :pricing_type, :is_eco_friendly, :is_emergency_available, :images, :status, NOW())";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':provider_id', $_SESSION['provider_id']);
    }
    
    $stmt->bindParam(':title', $title);
    $stmt->bindParam(':description', $description);
    $stmt->bindParam(':category_id', $category_id);
    $stmt->bindParam(':price', $price);
    $stmt->bindParam(':pricing_type', $pricing_type);
    $stmt->bindParam(':is_eco_friendly', $is_eco_friendly);
    $stmt->bindParam(':is_emergency_available', $is_emergency_available);
    $stmt->bindParam(':images', $images_json);
    $stmt->bindParam(':status', $status);
    
    if ($stmt->execute()) {
        $success = $edit_mode ? "Service updated successfully!" : "Service created successfully!";
        if (!$edit_mode) {
            header("refresh:2;url=dashboard.php");
        } else {
            // Refresh service data
            $query = "SELECT * FROM service_listings WHERE id = :id AND provider_id = :provider_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $_GET['edit']);
            $stmt->bindParam(':provider_id', $_SESSION['provider_id']);
            $stmt->execute();
            $service = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } else {
        $error = "Failed to save service. Please try again.";
    }
}

$existing_images = $service && $service['images'] ? json_decode($service['images'], true) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $edit_mode ? 'Edit' : 'Create'; ?> Service - <?php echo SITE_NAME; ?></title>
<link rel="stylesheet" href="<?= appUrl('assets/css/style.css') ?>">
    <style>
        .service-form {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-row .form-group {
            flex: 1;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            border-color: #007bff;
            outline: none;
            box-shadow: 0 0 0 2px rgba(0,123,255,0.25);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 120px;
        }

        /* Image Gallery Styles */
        .image-gallery-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
        }

        .current-images {
            margin-bottom: 25px;
        }

        .image-preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 20px;
            margin-top: 15px;
        }

        .preview-item {
            position: relative;
            width: 100%;
            aspect-ratio: 1;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .preview-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .preview-item:hover .image-overlay {
            opacity: 1;
        }

        .delete-image-btn {
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 20px;
            font-weight: bold;
            transition: all 0.3s;
        }

        .delete-image-btn:hover {
            background: #c82333;
            transform: scale(1.1);
        }

        /* File Upload Area */
        .file-upload-area {
            background: #fff;
            border: 2px dashed #ced4da;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            transition: all 0.3s;
            margin-bottom: 15px;
        }

        .file-upload-area:hover {
            border-color: #007bff;
            background: #f0f7ff;
        }

        .file-upload-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            cursor: pointer;
        }

        .upload-icon {
            font-size: 48px;
            color: #6c757d;
            margin-bottom: 15px;
        }

        .upload-text {
            font-size: 16px;
            font-weight: 600;
            color: #495057;
            margin-bottom: 5px;
        }

        .upload-hint {
            font-size: 14px;
            color: #6c757d;
        }

        .file-input {
            display: none;
        }

        .image-limit-info {
            margin-top: 15px;
            padding: 10px;
            background: #e9ecef;
            border-radius: 4px;
            font-size: 13px;
            color: #495057;
        }

        /* New Image Previews */
        .new-previews-section {
            margin-top: 20px;
        }

        .new-previews-title {
            font-size: 16px;
            font-weight: 600;
            color: #495057;
            margin-bottom: 10px;
        }

        /* Checkbox Styles */
        .checkbox-group {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
            padding: 10px 0;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-weight: normal;
        }

        .checkbox-label input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-right: 8px;
            cursor: pointer;
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-primary {
            background: #007bff;
            color: white;
        }

        .btn-primary:hover {
            background: #0069d9;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        /* Alert Styles */
        .alert {
            padding: 15px 20px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 0;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
            
            .image-preview-grid {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            }
        }
    </style>
</head>
<body>
    <?php include appPath('includes/header.php'); ?>
    
    <div class="container">
        <div class="service-form">
            <h1><?php echo $edit_mode ? 'Edit Service' : 'Create New Service'; ?></h1>
            
            <?php if($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <form method="POST" action="" enctype="multipart/form-data">
                <!-- Basic Information Section -->
                <div class="form-group">
                    <label>Service Title *</label>
                    <input type="text" name="title" value="<?php echo $service ? htmlspecialchars($service['title']) : ''; ?>" required class="form-control" placeholder="e.g., Professional Plumbing Services">
                </div>
                
                <div class="form-group">
                    <label>Description *</label>
                    <textarea name="description" rows="6" required class="form-control" placeholder="Describe your service in detail..."><?php echo $service ? htmlspecialchars($service['description']) : ''; ?></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Category *</label>
                        <select name="category_id" required class="form-control">
                            <option value="">Select Category</option>
                            <?php foreach($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo ($service && $service['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Pricing Type *</label>
                        <select name="pricing_type" required class="form-control">
                            <option value="fixed" <?php echo ($service && $service['pricing_type'] == 'fixed') ? 'selected' : ''; ?>>Fixed Price</option>
                            <option value="per_sqft" <?php echo ($service && $service['pricing_type'] == 'per_sqft') ? 'selected' : ''; ?>>Per Square Foot</option>
                            <option value="hourly" <?php echo ($service && $service['pricing_type'] == 'hourly') ? 'selected' : ''; ?>>Hourly Rate</option>
                            <option value="custom" <?php echo ($service && $service['pricing_type'] == 'custom') ? 'selected' : ''; ?>>Custom Quote</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Price (?) *</label>
                        <input type="number" name="price" step="0.01" value="<?php echo $service ? $service['price'] : ''; ?>" required class="form-control" placeholder="0.00">
                    </div>
                    
                    <div class="form-group">
                        <label>Status *</label>
                        <select name="status" required class="form-control">
                            <option value="active" <?php echo ($service && $service['status'] == 'active') ? 'selected' : ''; ?>>Active (Visible to customers)</option>
                            <option value="inactive" <?php echo ($service && $service['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive (Hidden)</option>
                        </select>
                    </div>
                </div>
                
                <!-- Image Gallery Section -->
                <div class="image-gallery-section">
                    <div class="section-title">
                        <span>?? Service Images</span>
                    </div>
                    
                    <!-- Existing Images -->
                    <?php if($edit_mode && !empty($existing_images)): ?>
                        <div class="current-images">
                            <label style="margin-bottom: 10px;">Current Images (<?php echo count($existing_images); ?>/5)</label>
                            <div class="image-preview-grid">
                                <?php foreach($existing_images as $index => $image): ?>
                                    <div class="preview-item">
                                        <img src="<?php echo htmlspecialchars($image); ?>" alt="Service Image">
                                        <div class="image-overlay">
                                            <a href="?edit=<?php echo $service['id']; ?>&delete_image=<?php echo $index; ?>" 
                                               class="delete-image-btn" 
                                               onclick="return confirm('Are you sure you want to delete this image?')">×</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- File Upload Area -->
                    <?php if(empty($existing_images) || count($existing_images) < 5): ?>
                        <div class="file-upload-area">
                            <label for="images" class="file-upload-label">
                                <span class="upload-icon">??</span>
                                <span class="upload-text">Click to upload or drag and drop</span>
                                <span class="upload-hint">Supported formats: JPG, PNG, GIF, WebP (Max 5MB each)</span>
                            </label>
                            <input type="file" 
                                   name="images[]" 
                                   id="images" 
                                   accept="image/jpeg,image/png,image/gif,image/webp" 
                                   multiple 
                                   class="file-input"
                                   onchange="previewImages(this)">
                            
                            <div class="image-limit-info">
                                <strong>?? Upload Information:</strong><br>
                                • Maximum <?php echo 5 - count($existing_images); ?> more image(s) can be uploaded (Total limit: 5 images)<br>
                                • First <?php echo 5 - count($existing_images); ?> selected image(s) will be uploaded<br>
                                • Images are automatically optimized for web viewing
                            </div>
                        </div>
                        
                        <!-- New Images Preview -->
                        <div id="new-image-previews" class="new-previews-section" style="display: none;">
                            <div class="new-previews-title">New Images to Upload:</div>
                            <div class="image-preview-grid"></div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info" style="margin-top: 15px;">
                            <strong>Maximum images reached (5/5).</strong> Delete some existing images to upload new ones.
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Service Features Section -->
                <div class="form-group">
                    <label>Service Features</label>
                    <div class="checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_eco_friendly" <?php echo ($service && $service['is_eco_friendly']) ? 'checked' : ''; ?>>
                            ?? Eco-Friendly Service
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_emergency_available" <?php echo ($service && $service['is_emergency_available']) ? 'checked' : ''; ?>>
                            ?? Emergency Service Available
                        </label>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="form-actions">
                    <a href="<?php echo appUrl('dashboard.php'); ?>" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">
                        <?php echo $edit_mode ? 'Update Service' : 'Publish Service'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
    function previewImages(input) {
        const newPreviewsSection = document.getElementById('new-image-previews');
        const previewGrid = newPreviewsSection.querySelector('.image-preview-grid');
        previewGrid.innerHTML = '';
        
        if (input.files && input.files.length > 0) {
            const currentCount = <?php echo count($existing_images); ?>;
            const maxFiles = 5;
            const remainingSlots = maxFiles - currentCount;
            
            for (let i = 0; i < Math.min(input.files.length, remainingSlots); i++) {
                const file = input.files[i];
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    const previewDiv = document.createElement('div');
                    previewDiv.className = 'preview-item';
                    
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    
                    previewDiv.appendChild(img);
                    previewGrid.appendChild(previewDiv);
                };
                
                reader.readAsDataURL(file);
            }
            
            // Show the preview section
            newPreviewsSection.style.display = 'block';
            
            // Show warning if too many files selected
            if (input.files.length > remainingSlots) {
                alert(`Only ${remainingSlots} more image(s) can be uploaded. The first ${remainingSlots} file(s) will be used.`);
            }
        } else {
            newPreviewsSection.style.display = 'none';
        }
    }
    
    // Drag and drop functionality
    const uploadArea = document.querySelector('.file-upload-area');
    const fileInput = document.getElementById('images');
    
    if (uploadArea) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        ['dragenter', 'dragover'].forEach(eventName => {
            uploadArea.addEventListener(eventName, highlight, false);
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, unhighlight, false);
        });
        
        function highlight() {
            uploadArea.style.background = '#f0f7ff';
            uploadArea.style.borderColor = '#007bff';
        }
        
        function unhighlight() {
            uploadArea.style.background = '#fff';
            uploadArea.style.borderColor = '#ced4da';
        }
        
        uploadArea.addEventListener('drop', handleDrop, false);
        
        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            fileInput.files = files;
            previewImages(fileInput);
        }
    }
    </script>
    
    <?php include appPath('includes/footer.php'); ?>
    <?php include appPath('includes/provider-guide.php'); ?>
</body>
</html>
