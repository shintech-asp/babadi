<?php
// admin/get_user_details.php
session_start();

if (!isset($_SESSION['admin_id']) || !($_SESSION['admin_logged_in'] ?? false) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../config/config.php';
require_once '../config/database.php';

// Set JSON header
header('Content-Type: application/json');

// Get user ID from query parameter
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($user_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user ID']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Fetch user data
    $query = "SELECT id, user_type, email, first_name, last_name, phone, address, city, state, 
              zip_code, status, created_at, last_login, is_archived 
              FROM users 
              WHERE id = :id";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $user_id);
    $stmt->execute();
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit();
    }
    
    // Fetch service requests for this user
    $requests_query = "SELECT sr.id, sr.status, sr.created_at, sr.preferred_date,
                              sl.title as service_title, sl.price,
                              p.company_name, u2.first_name as provider_first_name, u2.last_name as provider_last_name
                       FROM service_requests sr
                       LEFT JOIN service_listings sl ON sr.listing_id = sl.id
                       LEFT JOIN providers p ON sr.provider_id = p.user_id
                       LEFT JOIN users u2 ON sr.provider_id = u2.id
                       WHERE sr.seeker_id = :user_id
                       ORDER BY sr.created_at DESC
                       LIMIT 10";
    
    $requests_stmt = $db->prepare($requests_query);
    $requests_stmt->bindParam(':user_id', $user_id);
    $requests_stmt->execute();
    $service_requests = $requests_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch activity logs (admin logs related to this user)
    $activity_query = "SELECT action, details, created_at, ip_address
                       FROM admin_logs
                       WHERE details LIKE :user_id_pattern
                       ORDER BY created_at DESC
                       LIMIT 10";
    
    $activity_stmt = $db->prepare($activity_query);
    $pattern = "%user ID: " . $user_id . "%";
    $activity_stmt->bindParam(':user_id_pattern', $pattern);
    $activity_stmt->execute();
    $activity_logs = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Prepare response
    $response = [
        'user' => $user,
        'service_requests' => $service_requests,
        'activity_logs' => $activity_logs
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>
