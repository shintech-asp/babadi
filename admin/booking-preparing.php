<?php
// admin/booking-preparing.php
// Operations Manager: assign staff + equipment/consumables → sets status to "preparing"
$allowed_roles = ['admin'];
require_once 'includes/admin-auth.php';
require_once '../config/database.php';
require_once '../includes/booking_workflow_helper.php';

$pdo       = getDBConnection();
$availedId = (int)($_GET['id'] ?? 0);
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $staffIds    = array_map('intval', $_POST['staff_ids']    ?? []);
    $equipItems  = json_decode($_POST['equipment_json']  ?? '[]', true);
    $consItems   = json_decode($_POST['consumables_json'] ?? '[]', true);
    $notes       = trim($_POST['operations_notes'] ?? '');
    $allItems    = array_merge($equipItems, $consItems);

    if (empty($staffIds)) {
        $error = 'Please assign at least one staff member.';
    } else {
        // Fetch booking
        $bk = $pdo->prepare("SELECT a.*, p.id AS prov_id FROM availed_services a JOIN providers p ON p.user_id = a.provider_id WHERE a.id = ?");
        $bk->execute([$availedId]);
        $booking = $bk->fetch(PDO::FETCH_ASSOC);

        if (!$booking || $booking['status'] !== BK_ACCEPTED) {
            $error = 'Booking must be "Accepted" before preparing.';
        } else {
            $provId = $booking['prov_id'];

            // Check inventory
            $insufficient = checkInventorySufficiency($pdo, $provId, $allItems);
            if (!empty($insufficient)) {
                $parts = array_map(fn($i) => "{$i['name']} (need {$i['needed']}, have {$i['available']})", $insufficient);
                $error = 'Insufficient inventory: ' . implode('; ', $parts);
            } else {
                assignStaffToBooking($pdo, $availedId, $staffIds, $_SESSION['staff_id'] ?? 0);

                $pdo->prepare("
                    UPDATE availed_services
                    SET assigned_equipment   = ?,
                        assigned_consumables = ?,
                        operations_notes     = ?,
                        preparing_set_by     = ?,
                        preparing_set_at     = NOW()
                    WHERE id = ?
                ")->execute([
                    json_encode($equipItems),
                    json_encode($consItems),
                    $notes,
                    $_SESSION['staff_id'] ?? null,
                    $availedId,
                ]);

                if (!empty($allItems)) deductInventoryForBooking($pdo, $availedId, $allItems);

                if (transitionBookingStatus($pdo, $availedId, BK_PREPARING, $_SESSION['staff_id'] ?? 0, 'admin', 'Preparations saved')) {
                    header("Location: service-requests.php?msg=preparing_set"); exit;
                }
                $error = 'Status transition failed.';
            }
        }
    }
}

// Load booking detail
$stmt = $pdo->prepare("
    SELECT a.*,
           CONCAT(u.first_name,' ',u.last_name) AS seeker_name,
           s.service_name,
           p.company_name AS provider_name,
           p.id           AS prov_id
    FROM   availed_services a
    JOIN   users     u ON u.id = a.user_id
    JOIN   services  s ON s.id = a.service_id
    JOIN   providers p ON p.user_id = a.provider_id
    WHERE  a.id = ?
");
$stmt->execute([$availedId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$booking) { header('Location: service-requests.php'); exit; }

// Staff list from provider_staff
$staffList = $pdo->prepare("SELECT id, full_name, email FROM provider_staff WHERE provider_id = ? AND status = 'active' ORDER BY full_name");
$staffList->execute([$booking['prov_id']]);
$staff = $staffList->fetchAll(PDO::FETCH_ASSOC);
$assignedStaff = json_decode($booking['assigned_staff'] ?? '[]', true);

// Inventory
$invStmt = $pdo->prepare("SELECT id, item_name, item_type, quantity_available, unit FROM inventory_items WHERE provider_id = ? AND quantity_available > 0 ORDER BY item_type, item_name");
$invStmt->execute([$booking['prov_id']]);
$inventory   = $invStmt->fetchAll(PDO::FETCH_ASSOC);
$equipment   = array_filter($inventory, fn($i) => $i['item_type'] === 'equipment');
$consumables = array_filter($inventory, fn($i) => $i['item_type'] === 'consumable');
$prevEquip   = json_decode($booking['assigned_equipment']   ?? '[]', true);
$prevCons    = json_decode($booking['assigned_consumables'] ?? '[]', true);
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Prepare Booking #<?= $availedId ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head><body>
<?php include 'includes/admin-sidebar.php'; ?>
<div class="main-content p-4">
  <div class="d-flex align-items-center mb-4">
    <a href="service-requests.php" class="btn btn-outline-secondary me-3"><i class="bi bi-arrow-left"></i> Back</a>
    <h2 class="mb-0"><i class="bi bi-clipboard-check text-warning me-2"></i>Prepare Booking #<?= $availedId ?></h2>
  </div>

  <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="card mb-4 shadow-sm">
    <div class="card-header bg-light"><strong>Booking Summary</strong></div>
    <div class="card-body row g-2">
      <div class="col-md-4"><strong>Seeker:</strong> <?= htmlspecialchars($booking['seeker_name']) ?></div>
      <div class="col-md-4"><strong>Service:</strong> <?= htmlspecialchars($booking['service_name']) ?></div>
      <div class="col-md-4"><strong>Provider:</strong> <?= htmlspecialchars($booking['provider_name']) ?></div>
      <div class="col-md-4"><strong>Date:</strong> <?= htmlspecialchars($booking['preferred_date'] ?? 'N/A') ?> <?= htmlspecialchars($booking['preferred_time'] ?? '') ?></div>
      <div class="col-md-4"><strong>Payment:</strong>
        <span class="badge bg-<?= $booking['payment_method'] === 'downpayment' ? 'warning text-dark' : 'info' ?>">
          <?= ucfirst(str_replace('_', ' ', $booking['payment_method'] ?? '')) ?>
        </span>
      </div>
      <div class="col-md-4"><strong>Status:</strong>
        <span class="<?= bookingStatusBadgeClass($booking['status']) ?>"><?= bookingStatusLabel($booking['status']) ?></span>
      </div>
    </div>
  </div>

  <?php if ($booking['status'] !== BK_ACCEPTED): ?>
    <div class="alert alert-info">Booking is <strong><?= bookingStatusLabel($booking['status']) ?></strong>. Only "Accepted" bookings can be prepared.</div>
  <?php else: ?>
  <form method="POST" id="prepForm">
    <!-- Staff -->
    <div class="card mb-4 shadow-sm">
      <div class="card-header bg-warning text-dark"><i class="bi bi-people-fill me-2"></i><strong>Assign Staff</strong></div>
      <div class="card-body">
        <?php if (empty($staff)): ?>
          <p class="text-muted">No active staff found for this provider.</p>
        <?php else: ?>
        <div class="row row-cols-1 row-cols-md-3 g-3">
          <?php foreach ($staff as $s): ?>
          <div class="col">
            <div class="form-check border rounded p-3">
              <input class="form-check-input" type="checkbox" name="staff_ids[]"
                     value="<?= $s['id'] ?>" id="st_<?= $s['id'] ?>"
                     <?= in_array($s['id'], $assignedStaff) ? 'checked' : '' ?>>
              <label class="form-check-label" for="st_<?= $s['id'] ?>">
                <strong><?= htmlspecialchars($s['full_name']) ?></strong><br>
                <small class="text-muted"><?= htmlspecialchars($s['email']) ?></small>
              </label>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Equipment -->
    <div class="card mb-4 shadow-sm">
      <div class="card-header bg-primary text-white"><i class="bi bi-tools me-2"></i><strong>Equipment</strong></div>
      <div class="card-body">
        <?php if (empty($equipment)): ?><p class="text-muted">No equipment in inventory.</p>
        <?php else: foreach ($equipment as $item):
          $pq = 0; foreach ($prevEquip as $pe) { if ($pe['inventory_item_id'] == $item['id']) { $pq = $pe['quantity_needed']; break; } }
        ?>
        <div class="row mb-2 align-items-center border-bottom pb-2">
          <div class="col-md-5"><?= htmlspecialchars($item['item_name']) ?></div>
          <div class="col-md-3 text-muted small">Available: <?= $item['quantity_available'] ?> <?= htmlspecialchars($item['unit'] ?? '') ?></div>
          <div class="col-md-4">
            <input type="number" class="form-control equip-qty" min="0" max="<?= $item['quantity_available'] ?>"
                   value="<?= $pq ?>" data-id="<?= $item['id'] ?>">
          </div>
        </div>
        <?php endforeach; endif; ?>
        <input type="hidden" name="equipment_json" id="equipJson">
      </div>
    </div>

    <!-- Consumables -->
    <div class="card mb-4 shadow-sm">
      <div class="card-header bg-success text-white"><i class="bi bi-droplet-half me-2"></i><strong>Consumables</strong></div>
      <div class="card-body">
        <?php if (empty($consumables)): ?><p class="text-muted">No consumables in inventory.</p>
        <?php else: foreach ($consumables as $item):
          $pq = 0; foreach ($prevCons as $pc) { if ($pc['inventory_item_id'] == $item['id']) { $pq = $pc['quantity_needed']; break; } }
        ?>
        <div class="row mb-2 align-items-center border-bottom pb-2">
          <div class="col-md-5"><?= htmlspecialchars($item['item_name']) ?></div>
          <div class="col-md-3 text-muted small">Available: <?= $item['quantity_available'] ?> <?= htmlspecialchars($item['unit'] ?? '') ?></div>
          <div class="col-md-4">
            <input type="number" class="form-control cons-qty" min="0" max="<?= $item['quantity_available'] ?>"
                   value="<?= $pq ?>" data-id="<?= $item['id'] ?>">
          </div>
        </div>
        <?php endforeach; endif; ?>
        <input type="hidden" name="consumables_json" id="consJson">
      </div>
    </div>

    <!-- Notes -->
    <div class="card mb-4 shadow-sm">
      <div class="card-header bg-light"><strong>Operations Notes</strong></div>
      <div class="card-body">
        <textarea name="operations_notes" class="form-control" rows="3"><?= htmlspecialchars($booking['operations_notes'] ?? '') ?></textarea>
      </div>
    </div>

    <button type="submit" class="btn btn-warning btn-lg">
      <i class="bi bi-check-circle me-2"></i>Save & Set to PREPARING
    </button>
  </form>
  <?php endif; ?>
</div>
<script>
document.getElementById('prepForm')?.addEventListener('submit', function() {
    const eq = [], cn = [];
    document.querySelectorAll('.equip-qty').forEach(el => { if (+el.value > 0) eq.push({inventory_item_id: +el.dataset.id, quantity_needed: +el.value}); });
    document.querySelectorAll('.cons-qty').forEach(el => { if (+el.value > 0) cn.push({inventory_item_id: +el.dataset.id, quantity_needed: +el.value}); });
    document.getElementById('equipJson').value = JSON.stringify(eq);
    document.getElementById('consJson').value  = JSON.stringify(cn);
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body></html>