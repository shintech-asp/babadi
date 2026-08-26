<?php
// admin/service-requests.php
$allowed_roles = ['admin'];
require_once 'includes/admin-auth.php';
require_once '../config/database.php';
require_once '../includes/booking_workflow_helper.php';

$pdo          = getDBConnection();
$host         = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$remoteAddr   = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$isLocalTestMode = in_array($remoteAddr, ['127.0.0.1', '::1'], true)
    || $host === 'localhost'
    || str_starts_with($host, 'localhost:')
    || $host === '127.0.0.1'
    || str_starts_with($host, '127.0.0.1:');
$filterStatus = $_GET['status'] ?? 'all';
$search       = trim($_GET['search'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;
$today        = date('Y-m-d');
$testVerifyBookingId = $isLocalTestMode ? max(0, (int)($_GET['test_verify_booking'] ?? 0)) : 0;
$isRealTimestamp = static function ($value): bool {
    $v = trim((string)$value);
    return $v !== '' && $v !== '0000-00-00 00:00:00';
};

// Keep verify fields available on installs that have older schema snapshots.
try { $pdo->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS control_number VARCHAR(30) DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS provider_verified_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS seeker_verified_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE availed_services ADD COLUMN IF NOT EXISTS dual_verified_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}

$verifyStatuses = [BK_ACCEPTED, BK_PREPARING, BK_STARTING];
$buildReturnUrl = function (string $verifyKey = '', int $bookingId = 0, int $testBookingId = -1) use ($filterStatus, $search, $page, $testVerifyBookingId): string {
    $resolvedTestBookingId = $testBookingId >= 0 ? $testBookingId : $testVerifyBookingId;
    $url = 'service-requests.php?status=' . urlencode($filterStatus)
        . '&search=' . urlencode($search)
        . '&page=' . max(1, (int)$page);
    if ($verifyKey !== '') {
        $url .= '&verify=' . urlencode($verifyKey);
    }
    if ($bookingId > 0) {
        $url .= '&booking_id=' . $bookingId;
    }
    if ($resolvedTestBookingId > 0) {
        $url .= '&test_verify_booking=' . $resolvedTestBookingId;
    }
    return $url;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admin_verify_service_day') {
    $bid           = (int)($_POST['booking_id'] ?? 0);
    $enteredCode   = strtoupper(preg_replace('/\s+/', '', trim((string)($_POST['verify_code'] ?? ''))));
    $allowAnytime  = $isLocalTestMode && (($_POST['test_service_day_anytime'] ?? '0') === '1');

    if ($bid <= 0 || $enteredCode === '') {
        header('Location: ' . $buildReturnUrl('missing', $bid));
        exit;
    }

    try {
        $verifyStmt = $pdo->prepare(
            "SELECT id, provider_id, seeker_user_id, preferred_date, status, service_name,
                    control_number, provider_verified_at, seeker_verified_at, dual_verified_at
             FROM availed_services
             WHERE id = :id
             LIMIT 1"
        );
        $verifyStmt->execute([':id' => $bid]);
        $row = $verifyStmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            header('Location: ' . $buildReturnUrl('notfound', $bid));
            exit;
        }

        if (!in_array((string)$row['status'], $verifyStatuses, true) && !$isRealTimestamp($row['dual_verified_at'] ?? null)) {
            header('Location: ' . $buildReturnUrl('badstatus', $bid));
            exit;
        }

        if (!$allowAnytime && ((string)($row['preferred_date'] ?? '') !== $today)) {
            header('Location: ' . $buildReturnUrl('noday', $bid));
            exit;
        }

        if ($isRealTimestamp($row['dual_verified_at'] ?? null)) {
            header('Location: ' . $buildReturnUrl('already', $bid));
            exit;
        }

        $expectedCode = strtoupper(preg_replace('/\s+/', '', trim((string)($row['control_number'] ?? ''))));
        if ($expectedCode === '') {
            header('Location: ' . $buildReturnUrl('nocode', $bid));
            exit;
        }

        if (!hash_equals($expectedCode, $enteredCode)) {
            header('Location: ' . $buildReturnUrl('wrong', $bid));
            exit;
        }

        if (!$isRealTimestamp($row['provider_verified_at'] ?? null)) {
            $pdo->prepare(
                "UPDATE availed_services
                 SET provider_verified_at = NOW(), updated_at = NOW()
                 WHERE id = :id
                   AND (provider_verified_at IS NULL OR provider_verified_at = '0000-00-00 00:00:00')"
            )->execute([':id' => $bid]);
        }

        $verifyStmt->execute([':id' => $bid]);
        $fresh = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        $seekerDone = $isRealTimestamp($fresh['seeker_verified_at'] ?? null);

        if ($seekerDone) {
            $pdo->prepare(
                "UPDATE availed_services
                 SET status = 'starting',
                     dual_verified_at = IFNULL(dual_verified_at, NOW()),
                     updated_at = NOW()
                 WHERE id = :id"
            )->execute([':id' => $bid]);

            try {
                $pdo->prepare(
                    "INSERT INTO seeker_notifications
                         (seeker_user_id, avail_id, provider_id, service_name, type, message, is_read)
                     VALUES (:suid, :avid, :pid, :sname, 'accepted', :msg, 0)"
                )->execute([
                    ':suid'  => (int)($fresh['seeker_user_id'] ?? 0),
                    ':avid'  => $bid,
                    ':pid'   => (int)($fresh['provider_id'] ?? 0),
                    ':sname' => (string)($fresh['service_name'] ?? ''),
                    ':msg'   => 'Your service has officially started. Both control numbers were verified.',
                ]);
            } catch (Exception $e) {}

            header('Location: ' . $buildReturnUrl('ok', $bid));
            exit;
        }

        header('Location: ' . $buildReturnUrl('waiting', $bid));
        exit;

    } catch (Exception $e) {
        header('Location: ' . $buildReturnUrl('error', $bid));
        exit;
    }
}

$verifyKey = trim((string)($_GET['verify'] ?? ''));
$verifyBid = (int)($_GET['booking_id'] ?? 0);
$verifyAlertClass = '';
$verifyAlertText  = '';
if ($verifyKey !== '') {
    $verifyLabel = $verifyBid > 0 ? 'Booking #' . $verifyBid : 'This booking';
    switch ($verifyKey) {
        case 'ok':
            $verifyAlertClass = 'success';
            $verifyAlertText  = $verifyLabel . ' is fully verified. Service is now Starting.';
            break;
        case 'waiting':
            $verifyAlertClass = 'info';
            $verifyAlertText  = $verifyLabel . ' accepted the provider-side code. Waiting for seeker verification.';
            break;
        case 'already':
            $verifyAlertClass = 'info';
            $verifyAlertText  = $verifyLabel . ' was already fully verified.';
            break;
        case 'noday':
            $verifyAlertClass = 'warning';
            $verifyAlertText  = 'Verification is only available on the service day.';
            break;
        case 'wrong':
            $verifyAlertClass = 'danger';
            $verifyAlertText  = 'Incorrect seeker code. Double-check the PCF code and try again.';
            break;
        case 'nocode':
            $verifyAlertClass = 'warning';
            $verifyAlertText  = 'No seeker control number exists yet for this booking.';
            break;
        case 'badstatus':
            $verifyAlertClass = 'warning';
            $verifyAlertText  = 'Verification is only allowed for Accepted, Preparing, or Starting bookings.';
            break;
        case 'notfound':
            $verifyAlertClass = 'danger';
            $verifyAlertText  = 'Booking was not found.';
            break;
        case 'missing':
            $verifyAlertClass = 'warning';
            $verifyAlertText  = 'Please enter the seeker control number before submitting.';
            break;
        default:
            $verifyAlertClass = 'danger';
            $verifyAlertText  = 'Verification failed. Please try again.';
            break;
    }
}

$where = ['1=1']; $params = [];
if ($filterStatus !== 'all') { $where[] = 'a.status = ?'; $params[] = $filterStatus; }
if ($search) {
    $where[] = "(CONCAT(u.first_name,' ',u.last_name) LIKE ? OR u.email LIKE ? OR a.id = ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = (int)$search;
}
$wc = implode(' AND ', $where);

$total = $pdo->prepare("SELECT COUNT(*) FROM availed_services a JOIN users u ON u.id = a.user_id WHERE $wc");
$total->execute($params);
$totalRows  = $total->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

$stmt = $pdo->prepare("
    SELECT a.*,
           CONCAT(u.first_name,' ',u.last_name) AS seeker_name,
           u.email AS seeker_email,
           s.service_name,
           p.company_name AS provider_name
    FROM   availed_services a
    JOIN   users     u ON u.id = a.user_id
    JOIN   services  s ON s.id = a.service_id
    JOIN   providers p ON p.id = a.provider_id
    WHERE  $wc
    ORDER  BY a.created_at DESC
    LIMIT  $perPage OFFSET $offset
");
$stmt->execute($params);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Status tab counts
$counts = [];
foreach ($pdo->query("SELECT status, COUNT(*) cnt FROM availed_services GROUP BY status")->fetchAll() as $r) {
    $counts[$r['status']] = $r['cnt'];
}

$allStatuses = [
    'all'                            => 'All',
    BK_PENDING                       => 'Pending',
    BK_ACCEPTED                      => 'Accepted',
    BK_PREPARING                     => 'Preparing',
    BK_STARTING                      => 'Starting',
    BK_ONGOING                       => 'On-going',
    BK_WAITING_REMAINING             => 'Waiting Payment',
    BK_WAITING_SEEKER_CONFIRM        => 'Seeker Confirm',
    BK_WAITING_PROVIDER_CONFIRM      => 'Provider Confirm',
    BK_COMPLETED                     => 'Completed',
    BK_CANCELLED                     => 'Cancelled',
];
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Service Requests – Pestify Admin</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head><body>
<?php include 'includes/admin-sidebar.php'; ?>
<div class="main-content p-4">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-list-check text-primary me-2"></i>Service Requests</h2>
    <form class="d-flex gap-2" method="GET">
      <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
      <input type="search" name="search" class="form-control form-control-sm" placeholder="Name, email or ID…" value="<?= htmlspecialchars($search) ?>">
      <button class="btn btn-sm btn-primary">Search</button>
    </form>
  </div>

  <?php if ($verifyAlertText !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($verifyAlertClass) ?> py-2">
    <i class="bi bi-shield-check me-1"></i><?= htmlspecialchars($verifyAlertText) ?>
  </div>
  <?php endif; ?>

  <div class="mb-4 overflow-auto">
    <ul class="nav nav-tabs flex-nowrap">
    <?php foreach ($allStatuses as $st => $label):
      $cnt = ($st === 'all') ? array_sum($counts) : ($counts[$st] ?? 0);
    ?>
      <li class="nav-item">
        <a class="nav-link <?= $filterStatus === $st ? 'active fw-bold' : '' ?>"
           href="?status=<?= $st ?>&search=<?= urlencode($search) ?>">
          <?= htmlspecialchars($label) ?>
          <?php if ($cnt > 0): ?><span class="badge bg-primary ms-1"><?= $cnt ?></span><?php endif; ?>
        </a>
      </li>
    <?php endforeach; ?>
    </ul>
  </div>

  <div class="card shadow-sm">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>#</th><th>Seeker</th><th>Service</th><th>Provider</th>
              <th>Schedule</th><th>Payment</th><th>Amount</th><th>Status</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($bookings)): ?>
            <tr><td colspan="9" class="text-center py-4 text-muted">No bookings found.</td></tr>
          <?php else: ?>
          <?php foreach ($bookings as $bk): ?>
            <tr>
              <td><?= $bk['id'] ?></td>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($bk['seeker_name']) ?></div>
                <small class="text-muted"><?= htmlspecialchars($bk['seeker_email']) ?></small>
              </td>
              <td><?= htmlspecialchars($bk['service_name']) ?></td>
              <td><?= htmlspecialchars($bk['provider_name']) ?></td>
              <td><?= htmlspecialchars($bk['preferred_date'] ?? '–') ?></td>
              <td><span class="badge bg-<?= $bk['payment_method'] === 'downpayment' ? 'warning text-dark' : 'info' ?>">
                <?= ucfirst(str_replace('_', ' ', $bk['payment_method'] ?? '')) ?>
              </span></td>
              <td>₱<?= number_format($bk['total_amount'] ?? 0, 2) ?></td>
              <td><span class="<?= bookingStatusBadgeClass($bk['status']) ?>"><?= bookingStatusLabel($bk['status']) ?></span></td>
              <td>
                <?php
                  $isServiceDay = !empty($bk['preferred_date']) && $bk['preferred_date'] === $today;
                  $rowDualDone = $isRealTimestamp($bk['dual_verified_at'] ?? null);
                  $canVerifyFlow = in_array((string)$bk['status'], $verifyStatuses, true)
                      && !empty($bk['control_number'])
                      && !$rowDualDone;
                  $isVerifyTestDay = $isLocalTestMode && !$isServiceDay && ((int)$bk['id'] === $testVerifyBookingId);
                  $canVerifyNow = $canVerifyFlow && ($isServiceDay || $isVerifyTestDay);
                ?>
                <div class="d-flex gap-1">
                  <a href="../booking-details.php?id=<?= $bk['id'] ?>" class="btn btn-xs btn-outline-secondary" title="View"><i class="bi bi-eye"></i></a>
                  <?php if ($bk['status'] === BK_ACCEPTED && $isServiceDay): ?>
                    <a href="booking-preparing.php?id=<?= $bk['id'] ?>" class="btn btn-xs btn-warning" title="Prepare"><i class="bi bi-clipboard-check"></i></a>
                  <?php elseif ($bk['status'] === BK_ACCEPTED && !$isServiceDay): ?>
                    <span class="btn btn-xs btn-outline-secondary disabled" title="Available on service day"><i class="bi bi-calendar-event"></i></span>
                    <?php if ($isLocalTestMode): ?>
                      <a href="booking-preparing.php?id=<?= $bk['id'] ?>&test_service_day=1" class="btn btn-xs btn-outline-warning" title="Local test: open preparing anytime"><i class="bi bi-flask"></i></a>
                    <?php endif; ?>
                  <?php endif; ?>
                  <?php if ($canVerifyFlow && !$isServiceDay): ?>
                    <span class="btn btn-xs btn-outline-secondary disabled" title="Verification is available on the service day"><i class="bi bi-shield-lock"></i></span>
                  <?php endif; ?>
                  <?php if ($canVerifyNow): ?>
                    <button
                      type="button"
                      class="btn btn-xs btn-outline-primary"
                      title="Verify seeker code"
                      onclick='openAdminVerifyModal(<?= (int)$bk['id'] ?>, <?= json_encode($bk['seeker_name'] ?? '') ?>, <?= json_encode($bk['preferred_date'] ?? '') ?>, <?= $isServiceDay ? 'true' : 'false' ?>, <?= $isVerifyTestDay ? 'true' : 'false' ?>);'
                    ><i class="bi bi-shield-check"></i></button>
                  <?php endif; ?>
                  <?php if ($isLocalTestMode && $canVerifyFlow && !$isServiceDay): ?>
                    <?php if (!$isVerifyTestDay): ?>
                      <a
                        href="<?= htmlspecialchars($buildReturnUrl('', 0, (int)$bk['id'])) ?>"
                        class="btn btn-xs btn-outline-warning"
                        title="Local test: enable verify button for this booking"
                      ><i class="bi bi-flask"></i></a>
                    <?php else: ?>
                      <a
                        href="<?= htmlspecialchars($buildReturnUrl('', 0, 0)) ?>"
                        class="btn btn-xs btn-outline-secondary"
                        title="Clear test service day mode"
                      ><i class="bi bi-calendar-x"></i></a>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <?php if ($totalPages > 1): ?>
  <nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
          <a class="page-link" href="?status=<?= urlencode($filterStatus) ?>&search=<?= urlencode($search) ?>&page=<?= $p ?>"><?= $p ?></a>
        </li>
      <?php endfor; ?>
    </ul>
  </nav>
  <?php endif; ?>

  <div class="modal fade" id="adminVerifyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="POST">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-shield-check me-1"></i>Verify on Service Day</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="action" value="admin_verify_service_day">
            <input type="hidden" name="booking_id" id="verifyBookingId" value="">
            <input type="hidden" name="test_service_day_anytime" id="verifyAnytimeFlag" value="0">

            <p class="mb-2">Enter the seeker's code (<strong>PCF-...</strong>) for <strong id="verifySeekerName">this booking</strong>.</p>
            <div id="verifyDateHint" class="alert alert-info py-2 small mb-3">Verification is only available on the service day.</div>

            <label for="verifyCodeInput" class="form-label fw-semibold">Seeker Control Number</label>
            <input
              type="text"
              id="verifyCodeInput"
              name="verify_code"
              class="form-control"
              placeholder="PCF-2026-XXXXXX"
              maxlength="20"
              required
              autocomplete="off"
              spellcheck="false"
              oninput="this.value=this.value.toUpperCase()"
            >
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Verify</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<script>
let adminVerifyModal = null;

function openAdminVerifyModal(bookingId, seekerName, serviceDate, isServiceDay, allowAnytimeTest = false) {
  if (!adminVerifyModal) {
    adminVerifyModal = new bootstrap.Modal(document.getElementById('adminVerifyModal'));
  }
  document.getElementById('verifyBookingId').value = bookingId;
  document.getElementById('verifySeekerName').textContent = seekerName || 'this booking';
  document.getElementById('verifyCodeInput').value = '';

  const dateHint = document.getElementById('verifyDateHint');
  const anyTime = !!allowAnytimeTest;
  document.getElementById('verifyAnytimeFlag').value = anyTime ? '1' : '0';

  if (isServiceDay) {
    dateHint.className = 'alert alert-success py-2 small mb-3';
    dateHint.textContent = 'Today is the service day. Verification is enabled.';
  } else if (anyTime) {
    dateHint.className = 'alert alert-warning py-2 small mb-3';
    dateHint.textContent = 'Local test mode: verification is allowed before service day.';
  } else {
    dateHint.className = 'alert alert-info py-2 small mb-3';
    dateHint.textContent = 'Verification is only available on the service day (' + (serviceDate || 'scheduled date') + ').';
  }

  adminVerifyModal.show();
  setTimeout(() => document.getElementById('verifyCodeInput').focus(), 120);
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
