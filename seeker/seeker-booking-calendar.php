<?php
chdir(dirname(__DIR__));
// seeker-booking-calendar.php - Calendar view for seeker bookings
session_start();
require_once 'config/config.php';
require_once 'config/database.php';

if (!isLoggedIn() || !isSeeker()) {
    redirect('login.php');
}

$database = new Database();
$db = $database->getConnection();
$bookingDetailsUrl = appUrl('booking-details.php');

// Get month and year from query or use current
$month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Validate month/year
if ($month < 1 || $month > 12) $month = date('m');
if ($year < 2020 || $year > 2030) $year = date('Y');

// Get all bookings for this seeker
$query = "SELECT a.*, p.company_name, s.service_name
          FROM availed_services a
          JOIN providers p ON a.provider_id = p.id
          LEFT JOIN services s ON a.service_id = s.id
          WHERE a.user_id = :user_id
          ORDER BY a.preferred_date ASC";

$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build calendar data
$calendar_data = [];
foreach ($bookings as $booking) {
    $booking_date = date('Y-m-d', strtotime($booking['preferred_date']));
    $booking_month = date('m', strtotime($booking['preferred_date']));
    $booking_year = date('Y', strtotime($booking['preferred_date']));
    
    if ($booking_month == $month && $booking_year == $year) {
        $day = date('d', strtotime($booking['preferred_date']));
        if (!isset($calendar_data[$day])) {
            $calendar_data[$day] = [];
        }
        $calendar_data[$day][] = [
            'id' => $booking['id'],
            'status' => $booking['status'],
            'service' => $booking['service_name'] ?? 'Service',
            'provider' => $booking['company_name'],
            'time' => date('h:i A', strtotime($booking['preferred_time']))
        ];
    }
}

// Get status colors
$status_colors = [
    'pending' => '#ffc107',
    'accepted' => '#17a2b8',
    'preparing' => '#fd7e14',
    'starting' => '#0dcaf0',
    'on_going' => '#0d6efd',
    'waiting_for_remaining_payment' => '#dc3545',
    'waiting_for_provider_confirmation' => '#6f42c1',
    'waiting_for_seeker_confirmation' => '#198754',
    'completed' => '#28a745',
    'cancelled' => '#6c757d'
];

$status_labels = [
    'pending' => 'Pending',
    'accepted' => 'Accepted',
    'preparing' => 'Preparing',
    'starting' => 'Starting',
    'on_going' => 'On-going',
    'waiting_for_remaining_payment' => 'Payment',
    'waiting_for_provider_confirmation' => 'Awaiting',
    'waiting_for_seeker_confirmation' => 'Confirm',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Calendar - Pestify</title>
<link rel="stylesheet" href="<?= appUrl('assets/css/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f7fa; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        
        .container { max-width: 1200px; margin: 0 auto; padding: 2rem 1rem; }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .page-title { font-size: 28px; font-weight: 700; color: #1a1a2e; }
        
        .calendar-container {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        
        .calendar-nav {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .calendar-nav button {
            background: #007bff;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s;
        }
        
        .calendar-nav button:hover { background: #0056b3; }
        
        .calendar-month-year {
            font-size: 20px;
            font-weight: 700;
            color: #1a1a2e;
            min-width: 200px;
            text-align: center;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: #dee2e6;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .calendar-weekday {
            background: #f8f9fa;
            padding: 12px;
            text-align: center;
            font-weight: 700;
            color: #666;
            font-size: 13px;
        }
        
        .calendar-day {
            background: white;
            padding: 12px;
            min-height: 120px;
            position: relative;
            overflow-y: auto;
        }
        
        .calendar-day.other-month { background: #f8f9fa; color: #ccc; }
        .calendar-day.today { background: #e8f4fd; border: 2px solid #007bff; }
        
        .calendar-day-number {
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .calendar-day.other-month .calendar-day-number { color: #ccc; }
        
        .booking-badge {
            display: block;
            padding: 4px 6px;
            margin-bottom: 4px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            color: white;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .booking-badge:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        
        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid #dee2e6;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }
        
        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 3px;
        }
        
        .bookings-list {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .bookings-list-title {
            font-size: 20px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 1.5rem;
        }
        
        .booking-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 1rem;
            transition: all 0.3s;
        }
        
        .booking-item:hover {
            border-color: #007bff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .booking-item-info {
            flex: 1;
        }
        
        .booking-item-date {
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 4px;
        }
        
        .booking-item-details {
            font-size: 13px;
            color: #666;
        }
        
        .booking-item-status {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: white;
        }
        
        .booking-item-action {
            margin-left: 1rem;
        }
        
        .booking-item-action a {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            transition: background 0.3s;
        }
        
        .booking-item-action a:hover { background: #0056b3; }
        
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #999;
        }
        
        .empty-state i { font-size: 48px; margin-bottom: 1rem; }
        
        @media (max-width: 768px) {
            .calendar-grid { grid-template-columns: repeat(7, 1fr); }
            .calendar-day { min-height: 80px; font-size: 12px; }
            .booking-badge { font-size: 10px; padding: 3px 4px; }
            .legend { gap: 1rem; }
            .booking-item { flex-direction: column; align-items: flex-start; }
            .booking-item-action { margin-left: 0; margin-top: 1rem; }
        }
    </style>
</head>
<body>
    <?php $current_page = 'my-requests'; include appPath('includes/header.php'); ?>
    
    <div class="container">
        <div class="page-header">
            <h1 class="page-title">Booking Calendar</h1>
            <a href="<?php echo appUrl('my-requests.php'); ?>" style="color: #007bff; text-decoration: none; font-weight: 600;">
                <i class="fas fa-arrow-left"></i> Back to Requests
            </a>
        </div>
        
        <div class="calendar-container">
            <div class="calendar-header">
                <div class="calendar-nav">
                    <a href="?month=<?php echo $month === 1 ? 12 : $month - 1; ?>&year=<?php echo $month === 1 ? $year - 1 : $year; ?>" 
                       class="calendar-nav" style="text-decoration: none;">
                        <button><i class="fas fa-chevron-left"></i> Previous</button>
                    </a>
                </div>
                <div class="calendar-month-year">
                    <?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?>
                </div>
                <div class="calendar-nav">
                    <a href="?month=<?php echo $month === 12 ? 1 : $month + 1; ?>&year=<?php echo $month === 12 ? $year + 1 : $year; ?>" 
                       class="calendar-nav" style="text-decoration: none;">
                        <button>Next <i class="fas fa-chevron-right"></i></button>
                    </a>
                </div>
            </div>
            
            <div class="calendar-grid">
                <!-- Weekday headers -->
                <div class="calendar-weekday">Sun</div>
                <div class="calendar-weekday">Mon</div>
                <div class="calendar-weekday">Tue</div>
                <div class="calendar-weekday">Wed</div>
                <div class="calendar-weekday">Thu</div>
                <div class="calendar-weekday">Fri</div>
                <div class="calendar-weekday">Sat</div>
                
                <!-- Calendar days -->
                <?php
chdir(dirname(__DIR__));
                $first_day = date('w', mktime(0, 0, 0, $month, 1, $year));
                $days_in_month = date('t', mktime(0, 0, 0, $month, 1, $year));
                $prev_month_days = date('t', mktime(0, 0, 0, $month - 1, 1, $year));
                
                $day_counter = 1;
                $today = date('Y-m-d');
                
                // Previous month days
                for ($i = $first_day - 1; $i >= 0; $i--) {
                    $prev_day = $prev_month_days - $i;
                    echo '<div class="calendar-day other-month"><div class="calendar-day-number">' . $prev_day . '</div></div>';
                }
                
                // Current month days
                for ($day = 1; $day <= $days_in_month; $day++) {
                    $current_date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $is_today = ($current_date === $today);
                    $class = $is_today ? 'calendar-day today' : 'calendar-day';
                    
                    echo '<div class="' . $class . '">';
                    echo '<div class="calendar-day-number">' . $day . '</div>';
                    
                    if (isset($calendar_data[$day])) {
                        foreach ($calendar_data[$day] as $booking) {
                            $color = $status_colors[$booking['status']] ?? '#999';
                            echo '<a href="' . htmlspecialchars($bookingDetailsUrl . '?id=' . (int)$booking['id'], ENT_QUOTES, 'UTF-8') . '" style="text-decoration: none;">';
                            echo '<div class="booking-badge" style="background-color: ' . $color . '; title="' . htmlspecialchars($booking['service']) . '">';
                            echo htmlspecialchars(substr($booking['service'], 0, 12));
                            echo '</div>';
                            echo '</a>';
                        }
                    }
                    
                    echo '</div>';
                }
                
                // Next month days
                $remaining = (42 - ($first_day + $days_in_month));
                for ($i = 1; $i <= $remaining; $i++) {
                    echo '<div class="calendar-day other-month"><div class="calendar-day-number">' . $i . '</div></div>';
                }
                ?>
            </div>
            
            <div class="legend">
                <div style="font-weight: 700; color: #1a1a2e; width: 100%; margin-bottom: 8px;">Status Legend:</div>
                <?php foreach ($status_colors as $status => $color): ?>
                    <div class="legend-item">
                        <div class="legend-color" style="background-color: <?php echo $color; ?>;"></div>
                        <span><?php echo $status_labels[$status] ?? ucfirst($status); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Upcoming Bookings List -->
        <div class="bookings-list">
            <h2 class="bookings-list-title">
                <i class="fas fa-list"></i> Your Bookings
            </h2>
            
            <?php if (count($bookings) > 0): ?>
                <?php foreach ($bookings as $booking): ?>
                    <div class="booking-item">
                        <div class="booking-item-info">
                            <div class="booking-item-date">
                                <?php echo date('M d, Y', strtotime($booking['preferred_date'])); ?> at <?php echo date('h:i A', strtotime($booking['preferred_time'])); ?>
                            </div>
                            <div class="booking-item-details">
                                <strong><?php echo htmlspecialchars($booking['company_name']); ?></strong> - 
                                <?php echo htmlspecialchars($booking['service_name'] ?? 'Service'); ?>
                            </div>
                        </div>
                        <div class="booking-item-status">
                            <div class="status-badge" style="background-color: <?php echo $status_colors[$booking['status']] ?? '#999'; ?>;">
                                <?php echo $status_labels[$booking['status']] ?? ucfirst($booking['status']); ?>
                            </div>
                        </div>
                        <div class="booking-item-action">
                            <a href="<?php echo appUrl('booking-details.php'); ?>?id=<?php echo $booking['id']; ?>">
                                <i class="fas fa-eye"></i> View Details
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <h3>No Bookings Yet</h3>
                    <p>You haven't made any bookings yet. Browse our providers to get started.</p>
                    <a href="<?php echo appUrl('providers.php'); ?>" style="display: inline-block; margin-top: 1rem; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 6px; font-weight: 600;">
                        Browse Providers
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php include appPath('includes/footer.php'); ?>
</body>
</html>
