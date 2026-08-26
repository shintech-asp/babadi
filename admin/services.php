<?php
$allowed_roles = ['admin'];
require_once 'includes/admin-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();
$is_local_test_mode = (stripos(SITE_URL, 'localhost') !== false)
                   || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

$success = isset($_GET['success']) ? trim((string)$_GET['success']) : '';
$error = isset($_GET['error']) ? trim((string)$_GET['error']) : '';

$incomingAction = $_POST['action'] ?? ($_GET['action'] ?? '');
$incomingServiceId = (int)($_POST['service_id'] ?? ($_GET['service_id'] ?? 0));

if (in_array($incomingAction, ['toggle_status', 'test_toggle_status'], true) && $incomingServiceId > 0) {
    if ($incomingAction === 'test_toggle_status' && !$is_local_test_mode) {
        header('Location: services.php?error=' . urlencode('Test action is only available in local mode.'));
        exit();
    }
    $redirect = [
        'search' => trim((string)($_REQUEST['search'] ?? '')),
        'status' => trim((string)($_REQUEST['status'] ?? 'all')),
        'sort' => trim((string)($_REQUEST['sort'] ?? 'newest'))
    ];
    try {
        $stmt = $db->prepare("SELECT id, status, title FROM service_listings WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $incomingServiceId]);
        $service = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$service) {
            $redirect['error'] = 'Service not found.';
        } else {
            $current = $service['status'] ?? 'inactive';
            $newStatus = $current === 'active' ? 'inactive' : 'active';

            $upd = $db->prepare("UPDATE service_listings SET status = :status, updated_at = NOW() WHERE id = :id");
            $upd->execute([':status' => $newStatus, ':id' => $incomingServiceId]);

            if ($incomingAction === 'test_toggle_status') {
                $redirect['success'] = '[TEST] Service "' . ($service['title'] ?? 'Untitled') . '" set to ' . ucfirst($newStatus) . '.';
            } else {
                $redirect['success'] = 'Service "' . ($service['title'] ?? 'Untitled') . '" set to ' . ucfirst($newStatus) . '.';
            }

            try {
                $log = $db->prepare("INSERT INTO admin_logs (admin_id, action, details, ip_address, created_at)
                                     VALUES (:admin_id, :action, :details, :ip, NOW())");
                $log->execute([
                    ':admin_id' => (int)($_SESSION['admin_id'] ?? 0),
                    ':action' => 'SERVICE_STATUS_UPDATE',
                    ':details' => 'Service ID ' . $incomingServiceId . ' changed to ' . $newStatus,
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
            } catch (Exception $e) {
            }
        }
    } catch (Exception $e) {
        $redirect['error'] = 'Failed to update service status.';
    }

    $cleanRedirect = array_filter($redirect, static fn($v) => $v !== '' && $v !== null);
    header('Location: services.php?' . http_build_query($cleanRedirect));
    exit();
}

$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$sort = $_GET['sort'] ?? 'newest';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(sl.title LIKE :q OR sl.description LIKE :q OR p.company_name LIKE :q OR sc.name LIKE :q)";
    $params[':q'] = '%' . $search . '%';
}

if (in_array($statusFilter, ['active', 'inactive', 'pending'], true)) {
    $where[] = "sl.status = :status";
    $params[':status'] = $statusFilter;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$orderSql = match ($sort) {
    'oldest' => 'sl.created_at ASC',
    'price_asc' => 'sl.price ASC',
    'price_desc' => 'sl.price DESC',
    default => 'sl.created_at DESC'
};

$query = "SELECT
            sl.id,
            sl.title,
            sl.description,
            sl.price,
            sl.pricing_type,
            sl.status,
            sl.is_eco_friendly,
            sl.is_emergency_available,
            sl.created_at,
            p.id AS provider_id,
            p.company_name,
            sc.name AS category_name
          FROM service_listings sl
          LEFT JOIN providers p ON sl.provider_id = p.id
          LEFT JOIN service_categories sc ON sl.category_id = sc.id
          $whereSql
          ORDER BY $orderSql";

$stmt = $db->prepare($query);
$stmt->execute($params);
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

$counts = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0
];
try {
    $counts['total'] = (int)$db->query("SELECT COUNT(*) FROM service_listings")->fetchColumn();
    $counts['active'] = (int)$db->query("SELECT COUNT(*) FROM service_listings WHERE status = 'active'")->fetchColumn();
    $counts['inactive'] = (int)$db->query("SELECT COUNT(*) FROM service_listings WHERE status = 'inactive'")->fetchColumn();
} catch (Exception $e) {
}

$active_menu = 'services';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Services - Pestify Admin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#2e8b57;--dark:#1a2744;--bg:#f5f7fa;--white:#fff;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.main-content{padding:30px}
.page-header{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:20px}
.page-header h1{font-size:24px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:18px}
.stat{background:#fff;border:1px solid var(--border);border-radius:10px;padding:14px;display:flex;align-items:center;gap:10px}
.stat .icon{width:40px;height:40px;border-radius:9px;display:flex;align-items:center;justify-content:center;color:#fff}
.i-total{background:linear-gradient(135deg,#6366f1,#4f46e5)}
.i-active{background:linear-gradient(135deg,#22c55e,#16a34a)}
.i-inactive{background:linear-gradient(135deg,#94a3b8,#64748b)}
.stat h3{font-size:21px;color:var(--dark)}
.stat p{font-size:12px;color:var(--muted)}
.filters{background:#fff;border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:16px;display:grid;grid-template-columns:2fr 1fr 1fr auto auto;gap:10px}
.filters input,.filters select{width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 13px;border:none;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none}
.btn-primary{background:var(--primary);color:#fff}
.btn-outline{background:#fff;border:1px solid var(--border);color:#334155}
.btn-sm{padding:6px 10px;font-size:12px}
.btn-danger-soft{background:#fff5f5;color:#c53030;border:1px solid #fed7d7}
.btn-success-soft{background:#f0fff4;color:#276749;border:1px solid #c6f6d5}
.alert{padding:12px 14px;border-radius:8px;margin-bottom:14px;font-size:13px}
.alert-success{background:#f0fff4;color:#22543d;border:1px solid #c6f6d5}
.alert-error{background:#fff5f5;color:#742a2a;border:1px solid #fed7d7}
.card{background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden}
.table-wrap{overflow:auto}
table{width:100%;border-collapse:collapse}
thead th{padding:10px 12px;font-size:11px;color:var(--muted);background:#f8fafc;text-transform:uppercase;text-align:left;border-bottom:2px solid var(--border)}
tbody td{padding:11px 12px;font-size:13px;border-bottom:1px solid var(--border);vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
.badge{display:inline-block;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700}
.b-active{background:#dcfce7;color:#166534}
.b-inactive{background:#e2e8f0;color:#475569}
.muted{color:var(--muted);font-size:12px}
.action-group{display:flex;gap:6px;flex-wrap:wrap}
.empty{text-align:center;padding:40px;color:var(--muted)}
@media(max-width:1100px){.filters{grid-template-columns:1fr 1fr}.stats{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php include 'includes/admin-sidebar.php'; ?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1><i class="fas fa-bug"></i> Services Management</h1>
            <p>Review and manage service listings with working action buttons.</p>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="stats">
        <div class="stat"><div class="icon i-total"><i class="fas fa-list"></i></div><div><h3><?= $counts['total'] ?></h3><p>Total Services</p></div></div>
        <div class="stat"><div class="icon i-active"><i class="fas fa-check"></i></div><div><h3><?= $counts['active'] ?></h3><p>Active</p></div></div>
        <div class="stat"><div class="icon i-inactive"><i class="fas fa-pause"></i></div><div><h3><?= $counts['inactive'] ?></h3><p>Inactive</p></div></div>
    </div>

    <form method="GET" class="filters">
        <input type="text" name="search" placeholder="Search title, provider, category..." value="<?= htmlspecialchars($search) ?>">
        <select name="status">
            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
        </select>
        <select name="sort">
            <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
            <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest</option>
            <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price Low-High</option>
            <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price High-Low</option>
        </select>
        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply</button>
        <a href="services.php" class="btn btn-outline"><i class="fas fa-times"></i> Reset</a>
    </form>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Provider</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th>Features</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($services)): ?>
                    <tr><td colspan="8"><div class="empty"><i class="fas fa-inbox"></i><div>No services found.</div></div></td></tr>
                <?php endif; ?>
                <?php foreach ($services as $s): ?>
                    <tr>
                        <td>
                            <div style="font-weight:700"><?= htmlspecialchars($s['title'] ?? 'Untitled') ?></div>
                            <?php $desc = strip_tags((string)($s['description'] ?? '')); ?>
                            <div class="muted"><?= htmlspecialchars(strlen($desc) > 70 ? substr($desc, 0, 67) . '...' : $desc) ?></div>
                        </td>
                        <td><?= htmlspecialchars($s['company_name'] ?? 'Unknown Provider') ?></td>
                        <td><?= htmlspecialchars($s['category_name'] ?? 'Uncategorized') ?></td>
                        <td>
                            <div style="font-weight:700">PHP <?= number_format((float)($s['price'] ?? 0), 2) ?></div>
                            <div class="muted"><?= htmlspecialchars(ucfirst((string)($s['pricing_type'] ?? 'fixed'))) ?></div>
                        </td>
                        <td>
                            <span class="badge <?= ($s['status'] ?? '') === 'active' ? 'b-active' : 'b-inactive' ?>">
                                <?= htmlspecialchars(ucfirst((string)($s['status'] ?? 'inactive'))) ?>
                            </span>
                        </td>
                        <td class="muted">
                            <?= !empty($s['is_eco_friendly']) ? 'Eco ' : '' ?>
                            <?= (!empty($s['is_eco_friendly']) && !empty($s['is_emergency_available'])) ? '| ' : '' ?>
                            <?= !empty($s['is_emergency_available']) ? 'Emergency' : '' ?>
                            <?= (empty($s['is_eco_friendly']) && empty($s['is_emergency_available'])) ? '-' : '' ?>
                        </td>
                        <td class="muted"><?= !empty($s['created_at']) ? date('M d, Y', strtotime($s['created_at'])) : '-' ?></td>
                        <td>
                            <div class="action-group">
                                <a class="btn btn-sm btn-outline" href="../listing-details.php?id=<?= (int)$s['id'] ?>" target="_blank"><i class="fas fa-eye"></i> View</a>
                                <?php
                                $toggleParams = [
                                    'action' => 'toggle_status',
                                    'service_id' => (int)$s['id'],
                                    'search' => $search,
                                    'status' => $statusFilter,
                                    'sort' => $sort
                                ];
                                $toggleUrl = 'services.php?' . http_build_query(array_filter($toggleParams, static fn($v) => $v !== '' && $v !== null));
                                ?>
                                <a href="<?= htmlspecialchars($toggleUrl) ?>"
                                   class="btn btn-sm <?= ($s['status'] ?? '') === 'active' ? 'btn-danger-soft' : 'btn-success-soft' ?>"
                                   onclick="return confirm('Change service status?')">
                                    <i class="fas <?= ($s['status'] ?? '') === 'active' ? 'fa-pause' : 'fa-play' ?>"></i>
                                    <?= ($s['status'] ?? '') === 'active' ? 'Deactivate' : 'Activate' ?>
                                </a>
                                <?php if (!empty($is_local_test_mode)): ?>
                                <?php
                                $testToggleParams = [
                                    'action' => 'test_toggle_status',
                                    'service_id' => (int)$s['id'],
                                    'search' => $search,
                                    'status' => $statusFilter,
                                    'sort' => $sort
                                ];
                                $testToggleUrl = 'services.php?' . http_build_query(array_filter($testToggleParams, static fn($v) => $v !== '' && $v !== null));
                                ?>
                                <a href="<?= htmlspecialchars($testToggleUrl) ?>"
                                   class="btn btn-sm btn-outline"
                                   title="Local test toggle (no confirmation)">
                                    <i class="fas fa-flask"></i> Test Toggle
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
</body>
</html>
