<?php
chdir(dirname(__DIR__));
// submit-feedback.php - Handle feedback submission
session_start();
require_once 'config/config.php';
require_once 'config/database.php';
require_once appPath('includes/feedback_media_helper.php');

$bookingDetailsUrl = appUrl('booking-details.php');
$myRequestsUrl = appUrl('my-requests.php');

if (!isLoggedIn() || !isSeeker()) {
    http_response_code(403);
    exit('Unauthorized');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    exit('Invalid request');
}

$database = new Database();
$db = $database->getConnection();

$booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
$overall_rating = isset($_POST['overall_rating']) ? intval($_POST['overall_rating']) : 0;
$cleanliness_rating = isset($_POST['cleanliness_rating']) ? intval($_POST['cleanliness_rating']) : null;
$peofessionalism_rating = isset($_POST['peofessionalism_rating']) ? intval($_POST['peofessionalism_rating']) : null;
$timeliness_rating = isset($_POST['timeliness_rating']) ? intval($_POST['timeliness_rating']) : null;
$comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';

// Validate
if ($booking_id === 0 || $overall_rating < 1 || $overall_rating > 5) {
    header('Location: ' . $bookingDetailsUrl . '?id=' . $booking_id . '&error=invalid_rating');
    exit();
}

// Get booking details
$query = "SELECT * FROM availed_services WHERE id = :booking_id AND user_id = :user_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':booking_id', $booking_id);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    header('Location: ' . $myRequestsUrl . '?error=booking_not_found');
    exit();
}

// Check if feedback already exists
$query = "SELECT id FROM service_feedback WHERE availed_service_id = :booking_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':booking_id', $booking_id);
$stmt->execute();
if ($stmt->fetch()) {
    header('Location: ' . $bookingDetailsUrl . '?id=' . $booking_id . '&error=feedback_exists');
    exit();
}

ensureFeedbackImageColumn($db, 'service_feedback');
$feedbackImagePath = uploadFeedbackImage('feedback_image', (int)$_SESSION['user_id'], $booking_id, 'complaints');
if ($feedbackImagePath === false) {
    header('Location: ' . $bookingDetailsUrl . '?id=' . $booking_id . '&error=image_upload');
    exit();
}

// Insert feedback
$query = "INSERT INTO service_feedback 
          (availed_service_id, seeker_id, provider_id, rating, comment,
           cleanliness_rating, peofessionalism_rating, timeliness_rating, feedback_image)
          VALUES 
          (:booking_id, :seeker_id, :provider_id, :rating, :comment,
           :cleanliness_rating, :peofessionalism_rating, :timeliness_rating, :feedback_image)";

$stmt = $db->prepare($query);
$stmt->bindParam(':booking_id', $booking_id);
$stmt->bindParam(':seeker_id', $_SESSION['user_id']);
$stmt->bindParam(':provider_id', $booking['provider_id']);
$stmt->bindParam(':rating', $overall_rating);
$stmt->bindParam(':comment', $comment);
$stmt->bindParam(':cleanliness_rating', $cleanliness_rating);
$stmt->bindParam(':peofessionalism_rating', $peofessionalism_rating);
$stmt->bindParam(':timeliness_rating', $timeliness_rating);
$stmt->bindParam(':feedback_image', $feedbackImagePath);

if ($stmt->execute()) {
    header('Location: ' . $bookingDetailsUrl . '?id=' . $booking_id . '&success=feedback_submitted');
} else {
    header('Location: ' . $bookingDetailsUrl . '?id=' . $booking_id . '&error=db_error');
}
exit();
?>
