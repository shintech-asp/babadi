<?php
$allowed_roles = ['super_admin'];
require_once 'includes/admin-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/provider_verification_helper.php';

$database = new Database();
$db = $database->getConnection();
ensureProviderVerificationWorkflow($db);
syncProviderVerificationStatus($db);

$superAdminName = trim((string)($_SESSION['admin_full_name'] ?? ($_SESSION['admin_username'] ?? 'Super Admin')));
$verificationExpr = "COALESCE(NULLIF(p.verification_status, ''), CASE
    WHEN p.status = 'active' THEN 'approved'
    WHEN p.status = 'rejected' THEN 'rejected'
    ELSE 'pending'
END)";

$pendingCount = (int)$db->query("SELECT COUNT(*) FROM providers p WHERE {$verificationExpr} = 'pending'")->fetchColumn();
$approvedCount = (int)$db->query("SELECT COUNT(*) FROM providers p WHERE {$verificationExpr} = 'approved'")->fetchColumn();
$rejectedCount = (int)$db->query("SELECT COUNT(*) FROM providers p WHERE {$verificationExpr} = 'rejected'")->fetchColumn();
$reviewedToday = (int)$db->query("SELECT COUNT(*) FROM providers p WHERE p.verification_date IS NOT NULL AND DATE(p.verification_date) = CURDATE()")->fetchColumn();
$urgentPendingCount = (int)$db->query("SELECT COUNT(*) FROM providers p WHERE {$verificationExpr} = 'pending' AND TIMESTAMPDIFF(HOUR, COALESCE(p.verification_submitted_at, p.created_at), NOW()) >= 72")->fetchColumn();
$oldestPendingHours = (int)$db->query("SELECT COALESCE(MAX(TIMESTAMPDIFF(HOUR, COALESCE(p.verification_submitted_at, p.created_at), NOW())), 0) FROM providers p WHERE {$verificationExpr} = 'pending'")->fetchColumn();
$avgPendingHours = (float)$db->query("SELECT COALESCE(AVG(TIMESTAMPDIFF(HOUR, COALESCE(p.verification_submitted_at, p.created_at), NOW())), 0) FROM providers p WHERE {$verificationExpr} = 'pending'")->fetchColumn();

$recentQueueStmt = $db->prepare(
    "SELECT p.id, p.company_name, p.verification_status, p.verification_submitted_at, p.created_at,
            u.first_name, u.last_name, u.email,
            TIMESTAMPDIFF(HOUR, COALESCE(p.verification_submitted_at, p.created_at), NOW()) AS queue_age_hours
     FROM providers p
     JOIN users u ON u.id = p.user_id
     WHERE {$verificationExpr} = 'pending'
     ORDER BY COALESCE(p.verification_submitted_at, p.created_at) ASC
     LIMIT 6"
);
$recentQueueStmt->execute();
$recentQueue = $recentQueueStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard - Pestify</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2E8B57;
            --primary-dark: #276749;
            --neutral-dark: #10213f;
            --neutral-gray: #64748b;
            --neutral-light: #dbe5f0;
            --neutral-soft: #f4f8fb;
            --panel: #ffffff;
            --info: #2563eb;
            --warning: #d97706;
            --danger: #dc2626;
            --success: #15803d;
            --radius: 14px;
            --radius-lg: 20px;
            --shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            color: var(--neutral-dark);
            background:
                radial-gradient(circle at 0% 0%, rgba(46,139,87,.10), transparent 28%),
                radial-gradient(circle at 100% 0%, rgba(37,99,235,.10), transparent 24%),
                linear-gradient(180deg, #f8fbff 0%, #f2f7fb 100%);
        }

        .super-admin-shell { display: flex; min-height: 100vh; }
        .super-admin-sidebar {
            width: 268px;
            background: linear-gradient(180deg, #162443 0%, #1c2e58 100%);
            color: #fff;
            position: fixed;
            inset: 0 auto 0 0;
            display: flex;
            flex-direction: column;
            box-shadow: 6px 0 28px rgba(15, 23, 42, 0.18);
        }
        .super-admin-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 26px 22px 18px;
            border-bottom: 1px solid rgba(255,255,255,.1);
        }
        .super-admin-brand-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, #30a46c, #2E8B57);
            display: grid;
            place-items: center;
            font-size: 18px;
        }
        .super-admin-brand h2 { margin: 0; font-size: 18px; }
        .super-admin-brand p { margin: 4px 0 0; font-size: 12px; color: rgba(255,255,255,.66); }
        .super-admin-nav { padding: 18px 0; flex: 1; }
        .super-admin-section {
            padding: 8px 18px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: rgba(255,255,255,.45);
            font-weight: 700;
        }
        .super-admin-link {
            display: flex;
            align-items: center;
            gap: 12px;
            color: rgba(255,255,255,.84);
            text-decoration: none;
            padding: 13px 20px;
            border-left: 3px solid transparent;
            font-weight: 600;
        }
        .super-admin-link:hover,
        .super-admin-link.active {
            background: rgba(255,255,255,.1);
            color: #fff;
            border-left-color: #7dd3fc;
        }
        .super-admin-pill,
        .super-admin-badge {
            margin-left: auto;
            border-radius: 999px;
            padding: 3px 9px;
            font-size: 11px;
            font-weight: 700;
            background: rgba(59,130,246,.25);
            color: #dbeafe;
        }
        .super-admin-badge { background: rgba(220,38,38,.24); color: #fecaca; }
        .super-admin-footer {
            padding: 18px;
            border-top: 1px solid rgba(255,255,255,.1);
        }
        .super-admin-user {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 14px;
            background: rgba(255,255,255,.08);
        }
        .super-admin-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, #34d399, #0ea5e9);
            display: grid;
            place-items: center;
            font-weight: 700;
        }
        .super-admin-user h4 { margin: 0; font-size: 13px; }
        .super-admin-user p { margin: 2px 0 0; font-size: 11px; color: rgba(255,255,255,.68); text-transform: uppercase; letter-spacing: .06em; }

        .super-admin-main {
            flex: 1;
            margin-left: 268px;
            padding: 28px;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 20px;
        }
        .page-header h1 { margin: 0 0 8px; font-size: 38px; }
        .page-header p { margin: 0; color: var(--neutral-gray); font-size: 16px; line-height: 1.6; max-width: 760px; }
        .header-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 18px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            border: 1px solid transparent;
        }
        .btn-primary {
            background: linear-gradient(135deg, #2E8B57, #1f9d55);
            color: #fff;
            box-shadow: 0 10px 24px rgba(46,139,87,.2);
        }
        .btn-secondary {
            background: #fff;
            color: var(--neutral-dark);
            border-color: var(--neutral-light);
        }

        .hero-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            background: #fff;
            border: 1px solid var(--neutral-light);
            font-weight: 700;
        }
        .dashboard-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--panel);
            border: 1px solid var(--neutral-light);
            border-radius: 20px;
            padding: 18px 20px;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary);
        }
        .stat-card.approved::before { background: var(--success); }
        .stat-card.rejected::before { background: var(--danger); }
        .stat-card.reviewed::before { background: var(--info); }
        .stat-card .label {
            color: var(--neutral-gray);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .08em;
            font-weight: 700;
        }
        .stat-card .value {
            font-size: 38px;
            font-weight: 800;
            margin: 10px 0 6px;
        }
        .stat-card .meta {
            color: var(--neutral-gray);
            font-size: 14px;
            line-height: 1.55;
        }

        .content-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.25fr) minmax(320px, .85fr);
            gap: 18px;
        }
        .panel {
            background: var(--panel);
            border: 1px solid var(--neutral-light);
            border-radius: 22px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        .panel-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 20px 22px;
            border-bottom: 1px solid var(--neutral-light);
        }
        .panel-head h3 { margin: 0; font-size: 22px; }
        .panel-head p { margin: 6px 0 0; color: var(--neutral-gray); font-size: 14px; }
        .panel-body { padding: 18px 22px 22px; }
        .queue-list {
            display: grid;
            gap: 12px;
        }
        .queue-item {
            display: grid;
            gap: 8px;
            padding: 16px 18px;
            border: 1px solid var(--neutral-light);
            border-radius: 18px;
            background: linear-gradient(180deg, #fff, #f8fbff);
        }
        .queue-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
        }
        .queue-item h4 { margin: 0; font-size: 19px; }
        .queue-item p { margin: 0; color: var(--neutral-gray); }
        .queue-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            color: var(--neutral-gray);
            font-size: 14px;
        }
        .queue-meta span { display: inline-flex; align-items: center; gap: 6px; }
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
        }
        .pill.warning { background: #FEF3C7; color: #92400E; }
        .pill.critical { background: #FEE2E2; color: #991B1B; }
        .actions-stack {
            display: grid;
            gap: 12px;
        }
        .action-card {
            padding: 18px;
            border: 1px solid var(--neutral-light);
            border-radius: 18px;
            background: linear-gradient(180deg, #fff, #f8fbff);
        }
        .action-card h4 { margin: 0 0 8px; font-size: 18px; }
        .action-card p { margin: 0 0 14px; color: var(--neutral-gray); line-height: 1.6; }

        @media (max-width: 1100px) {
            .content-grid,
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 860px) {
            .super-admin-sidebar { display: none; }
            .super-admin-main { margin-left: 0; padding: 20px; }
            .page-header { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="super-admin-shell">
        <aside class="super-admin-sidebar">
            <div class="super-admin-brand">
                <div class="super-admin-brand-icon"><i class="fas fa-bug"></i></div>
                <div>
                    <h2>Pestify</h2>
                    <p>Super Admin Portal</p>
                </div>
            </div>

            <nav class="super-admin-nav">
                <div class="super-admin-section">Workspace</div>
                <a href="super-admin-dashboard.php" class="super-admin-link active">
                    <i class="fas fa-house"></i> Dashboard
                </a>
                <a href="verify-providers.php?filter=pending" class="super-admin-link">
                    <i class="fas fa-hourglass-half"></i> Review Queue
                    <?php if ($pendingCount > 0): ?>
                        <span class="super-admin-badge"><?php echo (int)$pendingCount; ?></span>
                    <?php endif; ?>
                </a>
                <a href="verify-providers.php?filter=approved" class="super-admin-link">
                    <i class="fas fa-circle-check"></i> Approved Businesses
                    <span class="super-admin-pill"><?php echo (int)$approvedCount; ?></span>
                </a>
                <a href="verify-providers.php?filter=rejected" class="super-admin-link">
                    <i class="fas fa-circle-xmark"></i> Rejected Businesses
                    <span class="super-admin-pill"><?php echo (int)$rejectedCount; ?></span>
                </a>
                <a href="verify-providers.php?filter=all" class="super-admin-link">
                    <i class="fas fa-layer-group"></i> Full Audit View
                    <span class="super-admin-pill"><?php echo (int)($pendingCount + $approvedCount + $rejectedCount); ?></span>
                </a>

                <div class="super-admin-section">Account</div>
                <a href="<?php echo appUrl('logout.php'); ?>" class="super-admin-link">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </nav>

            <div class="super-admin-footer">
                <div class="super-admin-user">
                    <div class="super-admin-avatar"><?php echo strtoupper(substr($superAdminName !== '' ? $superAdminName : 'S', 0, 1)); ?></div>
                    <div>
                        <h4><?php echo htmlspecialchars($superAdminName); ?></h4>
                        <p>Super Admin</p>
                    </div>
                </div>
            </div>
        </aside>

        <main class="super-admin-main">
            <div class="page-header">
                <div>
                    <h1>Super Admin Dashboard</h1>
                    <p>Review queue health, verification activity, and direct shortcuts without dropping into the generic admin layout.</p>
                </div>
                <div class="header-actions">
                    <a href="verify-providers.php?filter=pending" class="btn btn-primary"><i class="fas fa-shield-check"></i> Open Verification Queue</a>
                    <a href="<?php echo appUrl('admin/change-password.php'); ?>" class="btn btn-secondary"><i class="fas fa-key"></i> Security</a>
                </div>
            </div>

            <div class="dashboard-meta">
                <span class="hero-chip"><i class="fas fa-user"></i> <?php echo htmlspecialchars($superAdminName); ?></span>
                <span class="hero-chip"><i class="fas fa-clipboard-check"></i> <?php echo (int)$reviewedToday; ?> reviewed today</span>
                <span class="hero-chip"><i class="fas fa-hourglass-half"></i> Avg pending <?php echo number_format($avgPendingHours, 1); ?>h</span>
                <span class="hero-chip"><i class="fas fa-triangle-exclamation"></i> <?php echo (int)$urgentPendingCount; ?> urgent</span>
                <span class="hero-chip"><i class="fas fa-timer"></i> Oldest <?php echo (int)$oldestPendingHours; ?>h</span>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="label">Pending Queue</div>
                    <div class="value"><?php echo (int)$pendingCount; ?></div>
                    <div class="meta">Businesses still waiting for Super Admin action.</div>
                </div>
                <div class="stat-card approved">
                    <div class="label">Approved</div>
                    <div class="value"><?php echo (int)$approvedCount; ?></div>
                    <div class="meta">Businesses already cleared for live bookings.</div>
                </div>
                <div class="stat-card rejected">
                    <div class="label">Rejected</div>
                    <div class="value"><?php echo (int)$rejectedCount; ?></div>
                    <div class="meta">Profiles that still need corrections and resubmission.</div>
                </div>
                <div class="stat-card reviewed">
                    <div class="label">Reviewed Today</div>
                    <div class="value"><?php echo (int)$reviewedToday; ?></div>
                    <div class="meta">Today’s completed verification decisions.</div>
                </div>
            </div>

            <div class="content-grid">
                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <h3>Oldest Pending Reviews</h3>
                            <p>Prioritize providers that have been waiting the longest.</p>
                        </div>
                        <a href="verify-providers.php?filter=pending" class="btn btn-secondary"><i class="fas fa-arrow-right"></i> Open Queue</a>
                    </div>
                    <div class="panel-body">
                        <?php if (empty($recentQueue)): ?>
                            <p style="margin:0;color:var(--neutral-gray);">No pending provider reviews right now.</p>
                        <?php else: ?>
                            <div class="queue-list">
                                <?php foreach ($recentQueue as $row): ?>
                                    <?php $isCritical = ((int)($row['queue_age_hours'] ?? 0)) >= 72; ?>
                                    <div class="queue-item">
                                        <div class="queue-top">
                                            <div>
                                                <h4><?php echo htmlspecialchars((string)$row['company_name']); ?></h4>
                                                <p><?php echo htmlspecialchars(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))); ?></p>
                                            </div>
                                            <span class="pill <?php echo $isCritical ? 'critical' : 'warning'; ?>">
                                                <i class="fas fa-<?php echo $isCritical ? 'triangle-exclamation' : 'clock'; ?>"></i>
                                                <?php echo $isCritical ? 'Critical queue' : 'Pending review'; ?>
                                            </span>
                                        </div>
                                        <div class="queue-meta">
                                            <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars((string)$row['email']); ?></span>
                                            <span><i class="fas fa-hourglass-half"></i> <?php echo (int)($row['queue_age_hours'] ?? 0); ?> hour(s) waiting</span>
                                            <span><i class="fas fa-calendar-plus"></i> <?php echo date('M d, Y', strtotime((string)($row['verification_submitted_at'] ?: $row['created_at']))); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <aside class="panel">
                    <div class="panel-head">
                        <div>
                            <h3>Quick Actions</h3>
                            <p>Core Super Admin actions without admin clutter.</p>
                        </div>
                    </div>
                    <div class="panel-body">
                        <div class="actions-stack">
                            <div class="action-card">
                                <h4>Review pending providers</h4>
                                <p>Open the queue and decide approvals, rejections, and notes from the dedicated verification workspace.</p>
                                <a href="verify-providers.php?filter=pending" class="btn btn-primary"><i class="fas fa-gavel"></i> Review Queue</a>
                            </div>
                            <div class="action-card">
                                <h4>Audit all decisions</h4>
                                <p>See approved, rejected, and pending businesses in one place for a quick audit sweep.</p>
                                <a href="verify-providers.php?filter=all" class="btn btn-secondary"><i class="fas fa-layer-group"></i> Full Audit View</a>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        </main>
    </div>
</body>
</html>
