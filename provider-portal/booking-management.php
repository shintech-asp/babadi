<?php
// provider-portal/booking-management.php
// UPDATED: Accept/Reject now go through portal-request-action.php
// Only owner and crm roles can accept/reject pending requests.
session_start();
require_once '../config/database.php';
require_once '../provider-portal/includes/portal-auth.php';
require_once '../includes/booking_workflow_helper.php';

$pdo     = getDBConnection();
$staffId = $_SESSION['staff_id'] ?? $_SESSION['portal_staff_id'] ?? 0;
$role    = $_SESSION['portal_role'] ?? 'hr';

// Get provider_id
$provRow = $pdo->prepare("SELECT provider_id FROM provider_staff WHERE id = ?");
$provRow->execute([$staffId]);
$providerRow = $provRow->fetch(PDO::FETCH_ASSOC);
$providerId  = $providerRow['provider_id'] ?? 0;

// availed_services.provider_id = providers.id — query directly with $providerId.
// $provUserId (providers.user_id) is kept only for other lookups (e.g. notifications).
$puStmt = $pdo->prepare("SELECT user_id FROM providers WHERE id = ?");
$puStmt->execute([$providerId]);
$provUserRow = $puStmt->fetch(PDO::FETCH_ASSOC);
$provUserId  = $provUserRow['user_id'] ?? 0; // providers.user_id — used for notifications only

$message = $_SESSION['portal_success'] ?? '';
$error   = $_SESSION['portal_error']   ?? '';
unset($_SESSION['portal_success'], $_SESSION['portal_error']);

// Handle non-request actions (accept/reject now in portal-request-action.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $availedId = (int)($_POST['availed_id'] ?? 0);
    $action    = $_POST['action'] ?? '';

    switch ($action) {
        case 'set_starting':
            $ok = transitionBookingStatus($pdo, $availedId, BK_STARTING, $staffId, 'provider', 'Team dispatched');
            $message = $ok ? 'QR code generated. Notify the seeker.' : 'Failed.';
            break;
        case 'end_service':
            $bkRow = $pdo->query("SELECT payment_method FROM availed_services WHERE id = $availedId")->fetch();
            $next  = ($bkRow['payment_method'] === 'downpayment') ? BK_WAITING_REMAINING : BK_WAITING_SEEKER_CONFIRM;
            $ok    = transitionBookingStatus($pdo, $availedId, $next, $staffId, 'provider', 'Service ended');
            $message = $ok ? 'Status updated.' : 'Failed.';
            break;
        case 'confirm_payment_received':
            $ok = transitionBookingStatus($pdo, $availedId, BK_WAITING_PROVIDER_CONFIRM, $staffId, 'provider', 'Remaining payment received');
            $message = $ok ? 'Payment confirmed.' : 'Failed.';
            break;
        case 'confirm_complete':
            $ok = transitionBookingStatus($pdo, $availedId, BK_COMPLETED, $staffId, 'provider', 'Provider confirmed completion');
            $message = $ok ? 'Booking completed!' : 'Failed.';
            break;
    }
}

// Fetch bookings
$bookings = $pdo->prepare("
    SELECT a.*,
           CONCAT(u.first_name,' ',u.last_name) AS seeker_name,
           u.phone   AS seeker_phone,
           s.service_name
    FROM   availed_services a
    LEFT JOIN users    u ON u.id = a.user_id
    LEFT JOIN services s ON s.id = a.service_id
    WHERE  a.provider_id = ?
      AND  a.status NOT IN (?, ?)
    ORDER  BY FIELD(a.status,'pending','accepted','starting','on_going',
              'waiting_for_remaining_payment','waiting_for_seeker_confirmation',
              'waiting_for_provider_confirmation','preparing'), a.preferred_date ASC
");
$bookings->execute([$providerId, BK_COMPLETED, BK_CANCELLED]);
$activeBookings = $bookings->fetchAll(PDO::FETCH_ASSOC);

$canActOnRequests = in_array($role, ['owner', 'crm']);
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Booking Management – Provider Portal</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<style>
.pending-card { border-left-color: #f59e0b !important; }
.role-badge { font-size: 11px; padding: 2px 8px; border-radius: 20px; font-weight: 700; }
.role-owner { background: #fef3c7; color: #92400e; }
.role-crm   { background: #dbeafe; color: #1e40af; }
</style>
</head><body>
<?php include 'includes/portal-sidebar.php'; ?>
<div class="main-content p-4">
  <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h2 class="mb-0"><i class="bi bi-calendar2-check text-primary me-2"></i>Booking & Request Management</h2>
    <?php if ($canActOnRequests): ?>
    <span class="badge bg-success">You can Accept/Reject requests</span>
    <?php else: ?>
    <span class="badge bg-secondary">View only — Accept/Reject requires Owner or CRM role</span>
    <?php endif; ?>
  </div>

  <?php if ($message): ?>
  <div class="alert alert-success alert-dismissible">
    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="alert alert-danger alert-dismissible">
    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <div class="mb-3">
    <a href="scan-qr.php" class="btn btn-outline-primary"><i class="bi bi-qr-code-scan me-1"></i>Scan QR</a>
    <a href="/admin/booking-preparing.php" class="btn btn-outline-warning ms-2"><i class="bi bi-clipboard-check me-1"></i>Prepare a Booking</a>
  </div>

  <?php if (empty($activeBookings)): ?>
  <div class="alert alert-info">No active bookings or requests right now.</div>
  <?php else: ?>
  <div class="row g-4">
  <?php foreach ($activeBookings as $bk): ?>
    <div class="col-md-6 col-lg-4">
      <div class="card shadow-sm h-100 border-start border-4
        <?= $bk['status'] === 'pending'  ? 'pending-card' :
           ($bk['status'] === BK_ONGOING ? 'border-success' :
           ($bk['status'] === BK_STARTING? 'border-primary' : 'border-secondary')) ?>">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-1">
          <span class="fw-bold">#<?= $bk['id'] ?> <?= htmlspecialchars($bk['service_name'] ?? '—') ?></span>
          <?php
          $statusLabel = match($bk['status']) {
              'pending'   => '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Pending Approval</span>',
              'accepted'  => '<span class="badge bg-info text-dark">Accepted – Awaiting Payment</span>',
              'rejected'  => '<span class="badge bg-danger">Rejected</span>',
              default     => '<span class="badge bg-secondary">'.bookingStatusLabel($bk['status']).'</span>'
          };
          echo isset($bk['status']) && in_array($bk['status'],['pending','accepted','rejected'])
              ? $statusLabel
              : '<span class="'.bookingStatusBadgeClass($bk['status']).'">'.bookingStatusLabel($bk['status']).'</span>';
          ?>
        </div>
        <div class="card-body">
          <p class="mb-1"><i class="bi bi-person me-1"></i><?= htmlspecialchars($bk['seeker_name'] ?? $bk['full_name'] ?? '—') ?></p>
          <p class="mb-1"><i class="bi bi-calendar me-1"></i><?= htmlspecialchars($bk['preferred_date'] ?? 'TBD') ?> <?= htmlspecialchars($bk['preferred_time'] ?? '') ?></p>
          <p class="mb-1"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($bk['address'] ?? '') ?></p>
          <p class="mb-1">
            <i class="bi bi-cash me-1"></i>₱<?= number_format($bk['total_amount'] ?? 0, 2) ?>
            <span class="badge bg-<?= $bk['payment_method'] === 'downpayment' ? 'warning text-dark' : 'info' ?> ms-1">
              <?= ucfirst(str_replace('_', ' ', $bk['payment_method'] ?? '')) ?>
            </span>
          </p>
          <?php if ($bk['status'] === 'pending'): ?>
          <div class="alert alert-warning py-1 px-2 mb-0 mt-2" style="font-size:12px;">
            <i class="bi bi-clock me-1"></i>Waiting for <?= $canActOnRequests ? 'your' : 'owner/CRM' ?> decision
          </div>
          <?php endif; ?>
        </div>
        <div class="card-footer bg-transparent d-flex flex-wrap gap-2">

          <?php if ($bk['status'] === 'pending'): ?>
            <?php if ($canActOnRequests): ?>
              <!-- Accept -->
              <form method="POST" action="portal-request-action.php" class="d-inline">
                <input type="hidden" name="availed_id" value="<?= $bk['id'] ?>">
                <input type="hidden" name="action"     value="accept">
                <button class="btn btn-sm btn-success" onclick="return confirm('Accept this request? A payment link will be sent to the seeker.')">
                  <i class="bi bi-check-lg me-1"></i>Accept
                </button>
              </form>
              <!-- Reject -->
              <button class="btn btn-sm btn-danger" onclick="openRejectModal(<?= $bk['id'] ?>)">
                <i class="bi bi-x-lg me-1"></i>Reject
              </button>
            <?php else: ?>
              <span class="text-muted small"><i class="bi bi-lock me-1"></i>Owner/CRM action required</span>
            <?php endif; ?>

          <?php elseif ($bk['status'] === 'accepted'): ?>
            <span class="text-info small"><i class="bi bi-hourglass-split me-1"></i>Awaiting seeker payment…</span>

          <?php elseif ($bk['status'] === BK_PREPARING): ?>
            <form method="POST" class="d-inline">
              <input type="hidden" name="availed_id" value="<?= $bk['id'] ?>">
              <input type="hidden" name="action"     value="set_starting">
              <button class="btn btn-sm btn-primary" onclick="return confirm('Generate QR and send to seeker?')">
                <i class="bi bi-qr-code"></i> Set Starting
              </button>
            </form>

          <?php elseif ($bk['status'] === BK_ONGOING): ?>
            <form method="POST" class="d-inline">
              <input type="hidden" name="availed_id" value="<?= $bk['id'] ?>">
              <input type="hidden" name="action"     value="end_service">
              <button class="btn btn-sm btn-outline-success" onclick="return confirm('Mark service as ended?')">
                <i class="bi bi-flag-fill"></i> End Service
              </button>
            </form>

          <?php elseif ($bk['status'] === BK_WAITING_REMAINING): ?>
            <form method="POST" class="d-inline">
              <input type="hidden" name="availed_id" value="<?= $bk['id'] ?>">
              <input type="hidden" name="action"     value="confirm_payment_received">
              <button class="btn btn-sm btn-info text-white" onclick="return confirm('Confirm remaining payment received?')">
                <i class="bi bi-cash-coin"></i> Payment Received
              </button>
            </form>

          <?php elseif ($bk['status'] === BK_WAITING_PROVIDER_CONFIRM): ?>
            <form method="POST" class="d-inline">
              <input type="hidden" name="availed_id" value="<?= $bk['id'] ?>">
              <input type="hidden" name="action"     value="confirm_complete">
              <button class="btn btn-sm btn-success" onclick="return confirm('Mark booking as completed?')">
                <i class="bi bi-trophy"></i> Confirm Complete
              </button>
            </form>

          <?php elseif ($bk['status'] === BK_WAITING_SEEKER_CONFIRM): ?>
            <span class="text-muted small"><i class="bi bi-hourglass-split"></i> Awaiting seeker confirmation…</span>
          <?php endif; ?>

        </div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-x-circle text-danger me-2"></i>Reject Service Request</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="portal-request-action.php">
        <div class="modal-body">
          <input type="hidden" name="availed_id" id="rejectBookingId">
          <input type="hidden" name="action"     value="reject">
          <div class="mb-3">
            <label class="form-label fw-bold">Reason for Rejection <span class="text-danger">*</span></label>
            <textarea name="rejection_reason" class="form-control" rows="3" required
                      placeholder="e.g. Date not available, outside service area, missing information…"></textarea>
            <small class="text-muted">The seeker will be notified with this reason.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i>Reject Request</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openRejectModal(id) {
    document.getElementById('rejectBookingId').value = id;
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}
</script>
</body></html>