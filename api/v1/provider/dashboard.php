<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('GET');

$p = current_provider();
$pid = $p['id'];

$db = db();

$stmt = $db->prepare('SELECT COUNT(*) FROM availed_services WHERE provider_id = :pid AND status = :status');
$stmt->execute([':pid' => $pid, ':status' => 'pending']);
$pending_requests = (int) $stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM availed_services WHERE provider_id = :pid AND status IN (\'accepted\', \'preparing\', \'starting\', \'ongoing\')');
$stmt->execute([':pid' => $pid]);
$active_bookings = (int) $stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM availed_services WHERE provider_id = :pid AND status = :status');
$stmt->execute([':pid' => $pid, ':status' => 'completed']);
$completed_jobs = (int) $stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM service_listings WHERE provider_id = :pid AND status = :status');
$stmt->execute([':pid' => $pid, ':status' => 'active']);
$active_listings = (int) $stmt->fetchColumn();

$stmt = $db->prepare('SELECT AVG(rating) AS avg_rating, COUNT(*) AS review_count FROM service_reviews WHERE provider_id = :pid');
$stmt->execute([':pid' => $pid]);
$review_row = $stmt->fetch(PDO::FETCH_ASSOC);
$avg_rating = $review_row['avg_rating'] !== null ? round((float) $review_row['avg_rating'], 2) : null;
$review_count = (int) $review_row['review_count'];

$stmt = $db->prepare('SELECT id, service_name, status, preferred_date, preferred_time, created_at FROM availed_services WHERE provider_id = :pid ORDER BY created_at DESC LIMIT 5');
$stmt->execute([':pid' => $pid]);
$recent_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

ok([
    'data' => [
        'pending_requests' => $pending_requests,
        'active_bookings'  => $active_bookings,
        'completed_jobs'   => $completed_jobs,
        'active_listings'  => $active_listings,
        'avg_rating'       => $avg_rating,
        'review_count'     => $review_count,
        'recent_requests'  => $recent_requests,
    ]
]);
