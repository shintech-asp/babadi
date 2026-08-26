<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

if (($_SESSION['user_type'] ?? '') !== 'provider') {
    return;
}

$pgu_page = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
$pgu_view = trim((string)($_GET['view'] ?? ''));
$pgu_user_id = (int)($_SESSION['user_id'] ?? 0);
$pgu_force_open = false;
$pgu_provider_id = 0;
$pgu_service_count = 0;
$pgu_request_count = 0;
$pgu_availed_count = 0;
$pgu_completed_count = 0;
$pgu_profile_ready = false;

$pgu_current_section = 'dashboard';
if ($pgu_page === 'providers-dashboard.php' && $pgu_view === 'settings') {
    $pgu_current_section = 'settings';
} elseif ($pgu_page === 'services.php' || $pgu_page === 'create-listing.php' || $pgu_page === 'listing-details.php') {
    $pgu_current_section = 'services';
} elseif ($pgu_page === 'service-requests.php' || $pgu_page === 'verify-service.php') {
    $pgu_current_section = 'requests';
} elseif ($pgu_page === 'messages-provider.php' || $pgu_page === 'messages.php') {
    $pgu_current_section = 'messages';
} elseif ($pgu_page === 'profile.php') {
    $pgu_current_section = 'profile';
} elseif ($pgu_page === 'provider-payment-settings.php') {
    $pgu_current_section = 'settings';
}

$pgu_sidebar_guides = [
    'dashboard' => [
        'label' => 'Dashboard',
        'icon'  => 'fa-home',
        'title' => 'How to Use Dashboard',
        'steps' => [
            'Check stat cards first to monitor pending, active, and completed activity.',
            'Review recent activity and open urgent bookings immediately.',
            'Use dashboard shortcuts to jump into Requests and Settings.',
            'Return here daily to track team workload and service status.'
        ]
    ],
    'services' => [
        'label' => 'My Services',
        'icon'  => 'fa-briefcase',
        'title' => 'How to Use My Services',
        'steps' => [
            'Create clear service titles, scope, price, and expected duration.',
            'Add a complete contract, then sign it before publishing.',
            'Use payment settings per service to define full payment/downpayment.',
            'Update or deactivate outdated services to avoid seeker confusion.'
        ]
    ],
    'requests' => [
        'label' => 'Requests',
        'icon'  => 'fa-list-check',
        'title' => 'How to Use Requests',
        'steps' => [
            'Check new requests frequently and respond quickly.',
            'Accept/reject with clear reasons and confirm schedule details.',
            'Move each booking status correctly as work progresses.',
            'On service day, complete control-number verification before starting.'
        ]
    ],
    'messages' => [
        'label' => 'Messages',
        'icon'  => 'fa-comments',
        'title' => 'How to Use Messages',
        'steps' => [
            'Use chat to confirm date, time, and service preparation.',
            'Keep replies short, clear, and professional for every seeker.',
            'Send updates immediately for delays, reschedules, or changes.',
            'Use messages as audit trail for agreements and clarifications.'
        ]
    ],
    'profile' => [
        'label' => 'Profile',
        'icon'  => 'fa-user',
        'title' => 'How to Use Profile',
        'steps' => [
            'Keep company info, logo, contact details, and address updated.',
            'Review listed services from profile and remove inaccurate entries.',
            'Update password and account details regularly for security.',
            'Ensure profile data matches what seekers see in listings.'
        ]
    ],
    'settings' => [
        'label' => 'Settings',
        'icon'  => 'fa-sliders-h',
        'title' => 'How to Use Settings',
        'steps' => [
            'Set preparing days and max daily jobs based on real capacity.',
            'Enable useful notifications so you do not miss bookings.',
            'Configure automation options carefully before enabling them.',
            'Re-check settings after save to confirm they are applied.'
        ]
    ]
];

$pgu_sidebar_personality = [
    'dashboard' => 'I am your command center. Check me first every day so you instantly know what needs action.',
    'services'  => 'I am your sales floor. Strong service details and clear contracts here turn views into bookings.',
    'requests'  => 'I am your priority lane. Fast, decisive replies here make you look reliable and professional.',
    'messages'  => 'I am your voice with seekers. Use me to confirm schedules, set expectations, and avoid confusion.',
    'profile'   => 'I am your business face. Keep me polished so seekers trust you before they even send a request.',
    'settings'  => 'I am your control panel. Configure me right and your daily operations become smoother and safer.'
];

if (!isset($pgu_sidebar_guides[$pgu_current_section])) {
    $pgu_current_section = 'dashboard';
}

$pgu_walkthrough = [
    'Start in Dashboard to monitor activity and prioritize urgent items.',
    'Create and maintain complete services including contract and pricing.',
    'Handle requests fast and keep booking statuses updated.',
    'Use Messages to coordinate with seekers before service day.',
    'Update Profile and Settings to keep your provider account reliable.'
];

$pgu_links = [
    ['label' => 'Dashboard', 'href' => appUrl('providers-dashboard.php')],
    ['label' => 'My Services', 'href' => appUrl('services.php')],
    ['label' => 'Requests', 'href' => appUrl('service-requests.php')],
    ['label' => 'Messages', 'href' => appUrl('messages.php')],
    ['label' => 'Profile', 'href' => appUrl('profile.php')],
    ['label' => 'Settings', 'href' => appUrl('providers-dashboard.php?view=settings#dashboard-settings')],
];

if ($pgu_user_id > 0 && isset($db) && $db instanceof PDO) {
    try {
        $pgu_stmt = $db->prepare('SELECT id, business_registration_file, license_file, address, city, state FROM providers WHERE user_id = :uid LIMIT 1');
        $pgu_stmt->execute([':uid' => $pgu_user_id]);
        $pgu_provider_row = $pgu_stmt->fetch(PDO::FETCH_ASSOC);
        if ($pgu_provider_row) {
            $pgu_provider_id = (int)($pgu_provider_row['id'] ?? 0);
            $pgu_profile_ready =
                !empty($pgu_provider_row['business_registration_file']) &&
                !empty($pgu_provider_row['license_file']) &&
                !empty($pgu_provider_row['address']) &&
                !empty($pgu_provider_row['city']) &&
                strcasecmp(trim((string)($pgu_provider_row['state'] ?? '')), 'Cavite') === 0;
        }

        if ($pgu_provider_id > 0) {
            try {
                $pgu_stmt = $db->prepare('SELECT COUNT(*) FROM services WHERE provider_id = :pid');
                $pgu_stmt->execute([':pid' => $pgu_provider_id]);
                $pgu_service_count = (int)$pgu_stmt->fetchColumn();
            } catch (Throwable $e) {}

            try {
                $pgu_stmt = $db->prepare('SELECT COUNT(*) FROM service_requests WHERE provider_id = :pid');
                $pgu_stmt->execute([':pid' => $pgu_provider_id]);
                $pgu_request_count = (int)$pgu_stmt->fetchColumn();
            } catch (Throwable $e) {}

            try {
                $pgu_stmt = $db->prepare("SELECT COUNT(*) FROM service_requests WHERE provider_id = :pid AND status = 'completed'");
                $pgu_stmt->execute([':pid' => $pgu_provider_id]);
                $pgu_completed_count = (int)$pgu_stmt->fetchColumn();
            } catch (Throwable $e) {}

            try {
                $pgu_stmt = $db->prepare('SELECT COUNT(*) FROM availed_services WHERE provider_id = :pid');
                $pgu_stmt->execute([':pid' => $pgu_provider_id]);
                $pgu_availed_count = (int)$pgu_stmt->fetchColumn();
            } catch (Throwable $e) {}

            if ($pgu_service_count === 0 && $pgu_request_count === 0 && $pgu_availed_count === 0) {
                $pgu_force_open = true;
            }
        } else {
            // Treat missing provider record as an onboarding state.
            $pgu_force_open = true;
        }
    } catch (Throwable $e) {}
}

$pgu_onboarding_steps = [
    [
        'title' => 'Complete provider setup',
        'desc' => 'Submit required documents and business address.',
        'href' => appUrl('provider-setup.php'),
        'done' => $pgu_profile_ready
    ],
    [
        'title' => 'Create your first service',
        'desc' => 'Add service details, contract, and signature.',
        'href' => appUrl('services.php'),
        'done' => $pgu_service_count > 0
    ],
    [
        'title' => 'Respond to your first request',
        'desc' => 'Accept/reject quickly and keep status updated.',
        'href' => appUrl('service-requests.php'),
        'done' => ($pgu_request_count > 0 || $pgu_availed_count > 0)
    ],
    [
        'title' => 'Complete your first booking',
        'desc' => 'Finish service flow and confirmation properly.',
        'href' => appUrl('service-requests.php'),
        'done' => $pgu_completed_count > 0
    ]
];

$pgu_done_count = 0;
foreach ($pgu_onboarding_steps as $pgu_step) {
    if (!empty($pgu_step['done'])) {
        $pgu_done_count++;
    }
}
$pgu_total_steps = count($pgu_onboarding_steps);
$pgu_progress_pct = $pgu_total_steps > 0 ? (int)round(($pgu_done_count / $pgu_total_steps) * 100) : 0;
$pgu_next_step = null;
foreach ($pgu_onboarding_steps as $pgu_step) {
    if (empty($pgu_step['done'])) {
        $pgu_next_step = $pgu_step;
        break;
    }
}

$pgu_dismiss_key = 'pestify.provider.guide.dismissed.v2.' . $pgu_user_id;
$pgu_seen_key = 'pestify.provider.guide.seen.v2.' . $pgu_user_id;
$pgu_coach_seen_key = 'pestify.provider.sidebar.coach.seen.v2.' . $pgu_user_id;
?>
<style>
    .pgu-fab {
        position: fixed;
        right: 20px;
        bottom: 20px;
        z-index: 12000;
        border: none;
        border-radius: 999px;
        padding: 10px 14px;
        background: linear-gradient(135deg, #0ea5e9, #0284c7);
        color: #fff;
        font-weight: 700;
        font-size: 13px;
        font-family: inherit;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        box-shadow: 0 10px 24px rgba(3, 105, 161, 0.35);
    }
    .pgu-modal {
        position: fixed;
        inset: 0;
        z-index: 13000;
        display: none;
    }
    .pgu-modal.open { display: block; }
    .pgu-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(2, 6, 23, 0.6);
    }
    .pgu-dialog {
        position: relative;
        width: min(760px, calc(100% - 24px));
        margin: 28px auto;
        max-height: calc(100vh - 56px);
        overflow: auto;
        background: #fff;
        border-radius: 14px;
        border: 1px solid #dbeafe;
        box-shadow: 0 30px 80px rgba(2, 6, 23, 0.4);
    }
    .pgu-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 16px 18px;
        border-bottom: 1px solid #e2e8f0;
        background: linear-gradient(135deg, #eff6ff, #ecfeff);
    }
    .pgu-title {
        margin: 0;
        font-size: 18px;
        font-weight: 800;
        color: #0f172a;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .pgu-close {
        border: 1px solid #cbd5e1;
        background: #fff;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        cursor: pointer;
        color: #334155;
        font-size: 16px;
        line-height: 1;
    }
    .pgu-body {
        padding: 16px 18px;
        color: #1e293b;
    }
    .pgu-intro {
        margin: 0 0 12px;
        font-size: 14px;
        color: #334155;
    }
    .pgu-section {
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #f8fafc;
        padding: 12px 14px;
        margin-bottom: 12px;
    }
    .pgu-section h4 {
        margin: 0 0 10px;
        font-size: 14px;
        color: #0f172a;
    }
    .pgu-tab-row {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 10px;
    }
    .pgu-tab {
        border: 1px solid #cbd5e1;
        background: #fff;
        color: #334155;
        border-radius: 999px;
        padding: 6px 10px;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-family: inherit;
    }
    .pgu-tab.active {
        background: #0ea5e9;
        color: #fff;
        border-color: #0284c7;
    }
    .pgu-guide-panel {
        display: none;
        border: 1px solid #dbeafe;
        background: #fff;
        border-radius: 10px;
        padding: 10px 12px;
    }
    .pgu-guide-panel.active { display: block; }
    .pgu-guide-panel-title {
        font-size: 13px;
        font-weight: 800;
        color: #0f172a;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 7px;
    }
    .pgu-progress {
        height: 9px;
        border-radius: 999px;
        background: #e2e8f0;
        overflow: hidden;
        margin: 10px 0 8px;
    }
    .pgu-progress-bar {
        height: 100%;
        background: linear-gradient(90deg, #0ea5e9, #22c55e);
    }
    .pgu-progress-meta {
        font-size: 12px;
        color: #475569;
        margin-bottom: 6px;
    }
    .pgu-steps {
        display: grid;
        gap: 8px;
        margin: 10px 0 0;
    }
    .pgu-step {
        border: 1px solid #dbeafe;
        border-radius: 10px;
        background: #fff;
        padding: 10px 12px;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
    }
    .pgu-step-main {
        display: flex;
        align-items: flex-start;
        gap: 8px;
    }
    .pgu-step-icon {
        margin-top: 2px;
        color: #0284c7;
        min-width: 14px;
    }
    .pgu-step.done .pgu-step-icon { color: #16a34a; }
    .pgu-step-title {
        font-size: 13px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 2px;
    }
    .pgu-step-desc {
        font-size: 12px;
        color: #475569;
    }
    .pgu-step-link {
        font-size: 12px;
        font-weight: 700;
        text-decoration: none;
        color: #0369a1;
        background: #e0f2fe;
        border: 1px solid #bae6fd;
        border-radius: 999px;
        padding: 5px 10px;
        white-space: nowrap;
    }
    .pgu-next {
        margin-top: 10px;
        padding: 9px 10px;
        border-radius: 8px;
        border: 1px solid #bfdbfe;
        background: #eff6ff;
        font-size: 12px;
        color: #1e3a8a;
    }
    .pgu-section ol, .pgu-section ul {
        margin: 0;
        padding-left: 18px;
        font-size: 13px;
        line-height: 1.6;
        color: #334155;
    }
    .pgu-links {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .pgu-link {
        text-decoration: none;
        font-size: 12px;
        font-weight: 700;
        color: #075985;
        background: #e0f2fe;
        border: 1px solid #bae6fd;
        padding: 7px 10px;
        border-radius: 999px;
    }
    .pgu-foot {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 12px 18px 16px;
        border-top: 1px solid #e2e8f0;
    }
    .pgu-remember {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        color: #475569;
    }
    .pgu-btn {
        border: none;
        background: #0ea5e9;
        color: #fff;
        border-radius: 8px;
        padding: 9px 14px;
        font-weight: 700;
        font-size: 13px;
        cursor: pointer;
        font-family: inherit;
    }
    .pgu-coach-bubble {
        position: fixed;
        z-index: 14000;
        width: min(320px, calc(100vw - 20px));
        background: #0f172a;
        color: #e2e8f0;
        border: 1px solid #1e293b;
        border-radius: 12px;
        padding: 10px 12px;
        box-shadow: 0 16px 36px rgba(2, 6, 23, 0.48);
        font-size: 12px;
        line-height: 1.45;
    }
    .pgu-coach-bubble::after {
        content: '';
        position: absolute;
        left: 24px;
        bottom: -8px;
        border-left: 8px solid transparent;
        border-right: 8px solid transparent;
        border-top: 8px solid #0f172a;
    }
    .pgu-coach-bubble.below::after {
        top: -8px;
        bottom: auto;
        border-top: none;
        border-bottom: 8px solid #0f172a;
    }
    .pgu-coach-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 6px;
    }
    .pgu-coach-title {
        font-size: 12px;
        font-weight: 800;
        color: #67e8f9;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .pgu-coach-step {
        font-size: 11px;
        color: #94a3b8;
    }
    .pgu-coach-actions {
        display: flex;
        gap: 8px;
        justify-content: flex-end;
        margin-top: 9px;
    }
    .pgu-coach-btn {
        border: 1px solid #334155;
        border-radius: 7px;
        padding: 4px 8px;
        font-size: 11px;
        font-weight: 700;
        background: #1e293b;
        color: #e2e8f0;
        cursor: pointer;
        font-family: inherit;
    }
    .pgu-coach-btn.primary {
        background: #0ea5e9;
        border-color: #0ea5e9;
        color: #fff;
    }
    .pgu-coach-target {
        outline: 2px solid #22d3ee;
        outline-offset: 2px;
        border-radius: 9px;
        box-shadow: 0 0 0 6px rgba(34, 211, 238, 0.12);
        position: relative;
        z-index: 13999;
    }
    @media (max-width: 768px) {
        .pgu-fab { right: 12px; bottom: 12px; }
        .pgu-dialog { width: calc(100% - 16px); margin: 12px auto; max-height: calc(100vh - 24px); }
        .pgu-foot { flex-direction: column; align-items: flex-start; }
    }
</style>

<button type="button" class="pgu-fab" id="providerGuideToggleBtn" title="Open provider guide">
    <i class="fas fa-compass"></i> Guide
</button>

<div class="pgu-modal" id="providerGuideModal"
     data-dismiss-key="<?php echo htmlspecialchars($pgu_dismiss_key, ENT_QUOTES); ?>"
     data-seen-key="<?php echo htmlspecialchars($pgu_seen_key, ENT_QUOTES); ?>"
     data-force-open="<?php echo $pgu_force_open ? '1' : '0'; ?>"
     data-current-section="<?php echo htmlspecialchars($pgu_current_section, ENT_QUOTES); ?>">
    <div class="pgu-backdrop" data-guide-close="1"></div>
    <div class="pgu-dialog" role="dialog" aria-modal="true" aria-label="Provider guide">
        <div class="pgu-head">
            <h3 class="pgu-title"><i class="fas fa-route"></i> Provider System Guide</h3>
            <button type="button" class="pgu-close" data-guide-close="1" aria-label="Close guide">&times;</button>
        </div>
        <div class="pgu-body">
            <p class="pgu-intro">Use this guide as your default workflow for managing your provider account from setup to completed jobs.</p>

            <div class="pgu-section">
                <h4>New Provider Quick Start</h4>
                <div class="pgu-progress-meta">Progress: <?php echo (int)$pgu_done_count; ?> / <?php echo (int)$pgu_total_steps; ?> steps completed (<?php echo (int)$pgu_progress_pct; ?>%)</div>
                <div class="pgu-progress">
                    <div class="pgu-progress-bar" style="width: <?php echo (int)$pgu_progress_pct; ?>%;"></div>
                </div>
                <div class="pgu-steps">
                    <?php foreach ($pgu_onboarding_steps as $pgu_step): ?>
                        <div class="pgu-step <?php echo !empty($pgu_step['done']) ? 'done' : ''; ?>">
                            <div class="pgu-step-main">
                                <i class="fas <?php echo !empty($pgu_step['done']) ? 'fa-check-circle' : 'fa-circle'; ?> pgu-step-icon"></i>
                                <div>
                                    <div class="pgu-step-title"><?php echo htmlspecialchars($pgu_step['title']); ?></div>
                                    <div class="pgu-step-desc"><?php echo htmlspecialchars($pgu_step['desc']); ?></div>
                                </div>
                            </div>
                            <?php if (empty($pgu_step['done'])): ?>
                                <a class="pgu-step-link" href="<?php echo htmlspecialchars($pgu_step['href']); ?>">Open</a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($pgu_next_step): ?>
                    <div class="pgu-next">
                        Next recommended action: <strong><?php echo htmlspecialchars($pgu_next_step['title']); ?></strong>
                    </div>
                <?php else: ?>
                    <div class="pgu-next" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534;">
                        Onboarding completed. Continue using Dashboard and Requests for daily operations.
                    </div>
                <?php endif; ?>
            </div>

            <div class="pgu-section">
                <h4>Whole Provider Side Workflow</h4>
                <ol>
                    <?php foreach ($pgu_walkthrough as $pgu_item): ?>
                        <li><?php echo htmlspecialchars($pgu_item); ?></li>
                    <?php endforeach; ?>
                </ol>
            </div>

            <div class="pgu-section">
                <h4>Sidebar-Specific Guides</h4>
                <div class="pgu-tab-row">
                    <?php foreach ($pgu_sidebar_guides as $pgu_key => $pgu_guide): ?>
                        <button type="button"
                                class="pgu-tab <?php echo $pgu_key === $pgu_current_section ? 'active' : ''; ?>"
                                data-guide-tab="<?php echo htmlspecialchars($pgu_key); ?>">
                            <i class="fas <?php echo htmlspecialchars($pgu_guide['icon']); ?>"></i>
                            <?php echo htmlspecialchars($pgu_guide['label']); ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <?php foreach ($pgu_sidebar_guides as $pgu_key => $pgu_guide): ?>
                    <div class="pgu-guide-panel <?php echo $pgu_key === $pgu_current_section ? 'active' : ''; ?>"
                         data-guide-panel="<?php echo htmlspecialchars($pgu_key); ?>">
                        <div class="pgu-guide-panel-title">
                            <i class="fas <?php echo htmlspecialchars($pgu_guide['icon']); ?>"></i>
                            <?php echo htmlspecialchars($pgu_guide['title']); ?>
                        </div>
                        <ol>
                            <?php foreach ($pgu_guide['steps'] as $pgu_tip): ?>
                                <li><?php echo htmlspecialchars($pgu_tip); ?></li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="pgu-section">
                <h4>Quick Navigation</h4>
                <div class="pgu-links">
                    <?php foreach ($pgu_links as $pgu_link): ?>
                        <a class="pgu-link" href="<?php echo htmlspecialchars($pgu_link['href']); ?>">
                            <?php echo htmlspecialchars($pgu_link['label']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="pgu-foot">
            <label class="pgu-remember">
                <input type="checkbox" id="providerGuideDontAutoOpen">
                Do not auto-open this guide again
            </label>
            <button type="button" class="pgu-btn" data-guide-close="1">Close Guide</button>
        </div>
    </div>
</div>

<script>
    (function () {
        const modal = document.getElementById('providerGuideModal');
        const toggle = document.getElementById('providerGuideToggleBtn');
        if (!modal || !toggle) return;

        const dismissKey = String(modal.dataset.dismissKey || '');
        const seenKey = String(modal.dataset.seenKey || '');
        const forceOpen = String(modal.dataset.forceOpen || '0') === '1';
        const currentSection = String(modal.dataset.currentSection || 'dashboard');
        const rememberInput = document.getElementById('providerGuideDontAutoOpen');
        const coachSeenKey = <?php echo json_encode($pgu_coach_seen_key); ?>;
        const coachMessages = <?php echo json_encode($pgu_sidebar_personality); ?>;
        const coachGuideMeta = <?php echo json_encode($pgu_sidebar_guides); ?>;
        const coachSelectors = {
            dashboard: ['.sidebar-menu a[href$="providers-dashboard.php"]', '.sidebar-menu a[href*="providers-dashboard.php"]:not([href*="view=settings"])'],
            services:  ['.sidebar-menu a[href$="services.php"]', '.sidebar-menu a[href*="services.php"]'],
            requests:  ['.sidebar-menu a[href$="service-requests.php"]', '.sidebar-menu a[href*="service-requests.php"]'],
            messages:  ['.sidebar-menu a[href$="messages.php"]', '.sidebar-menu a[href*="messages.php"]'],
            profile:   ['.sidebar-menu a[href$="profile.php"]', '.sidebar-menu a[href*="profile.php"]'],
            settings:  ['.sidebar-menu a[href*="view=settings"]']
        };
        let coachKeys = [];
        let coachIndex = 0;
        let coachBubble = null;
        let coachTarget = null;

        function getStorage(key) {
            try { return localStorage.getItem(key); } catch (_) { return null; }
        }

        function setStorage(key, value) {
            try {
                if (value === null) localStorage.removeItem(key);
                else localStorage.setItem(key, value);
            } catch (_) {}
        }

        function isDismissed() {
            if (!dismissKey) return false;
            return getStorage(dismissKey) === '1';
        }

        function setDismissed(value) {
            if (!dismissKey) return;
            if (value) {
                setStorage(dismissKey, '1');
                if (coachSeenKey) setStorage(coachSeenKey, '1');
            } else {
                setStorage(dismissKey, null);
            }
        }

        function openGuide() {
            finishCoach(false);
            modal.classList.add('open');
        }

        function closeGuide() {
            modal.classList.remove('open');
            if (rememberInput) setDismissed(!!rememberInput.checked);
        }

        function findTargetForSection(section) {
            const selectors = coachSelectors[section] || [];
            for (let i = 0; i < selectors.length; i++) {
                const el = document.querySelector(selectors[i]);
                if (!el) continue;
                const rect = el.getBoundingClientRect();
                if (rect.width > 0 && rect.height > 0) return el;
            }
            return null;
        }

        function clearCoachTarget() {
            if (coachTarget) {
                coachTarget.classList.remove('pgu-coach-target');
                coachTarget = null;
            }
        }

        function removeCoachBubble() {
            if (coachBubble && coachBubble.parentNode) {
                coachBubble.parentNode.removeChild(coachBubble);
            }
            coachBubble = null;
        }

        function finishCoach(markSeen) {
            clearCoachTarget();
            removeCoachBubble();
            window.removeEventListener('resize', positionCoachBubble);
            window.removeEventListener('scroll', positionCoachBubble, true);
            if (markSeen && coachSeenKey) setStorage(coachSeenKey, '1');
        }

        function positionCoachBubble() {
            if (!coachBubble || !coachTarget) return;
            const rect = coachTarget.getBoundingClientRect();
            const bubbleRect = coachBubble.getBoundingClientRect();
            const vw = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
            const margin = 8;
            let left = rect.left + (rect.width / 2) - (bubbleRect.width / 2);
            left = Math.max(margin, Math.min(vw - bubbleRect.width - margin, left));

            let top = rect.top - bubbleRect.height - 12;
            coachBubble.classList.remove('below');
            if (top < margin) {
                top = rect.bottom + 12;
                coachBubble.classList.add('below');
            }
            coachBubble.style.left = left + 'px';
            coachBubble.style.top = top + 'px';
        }

        function ensureCoachBubble() {
            if (coachBubble) return;
            coachBubble = document.createElement('div');
            coachBubble.className = 'pgu-coach-bubble';
            document.body.appendChild(coachBubble);
            coachBubble.addEventListener('click', function (e) {
                const nextBtn = e.target.closest('[data-coach-next]');
                const doneBtn = e.target.closest('[data-coach-done]');
                if (doneBtn) {
                    finishCoach(true);
                    return;
                }
                if (nextBtn) {
                    showCoachStep(coachIndex + 1);
                }
            });
        }

        function showCoachStep(index) {
            if (index >= coachKeys.length) {
                finishCoach(true);
                return;
            }

            const section = coachKeys[index];
            const target = findTargetForSection(section);
            if (!target) {
                showCoachStep(index + 1);
                return;
            }

            coachIndex = index;
            clearCoachTarget();
            coachTarget = target;
            coachTarget.classList.add('pgu-coach-target');

            ensureCoachBubble();
            const label = (coachGuideMeta[section] && coachGuideMeta[section].label) ? coachGuideMeta[section].label : section;
            const icon = (coachGuideMeta[section] && coachGuideMeta[section].icon) ? coachGuideMeta[section].icon : 'fa-circle-info';
            const text = coachMessages[section] || 'Use this section to manage your provider workflow.';
            const isLast = coachIndex >= (coachKeys.length - 1);
            coachBubble.innerHTML = ''
                + '<div class="pgu-coach-head">'
                + '  <div class="pgu-coach-title"><i class="fas ' + icon + '"></i> ' + label + '</div>'
                + '  <div class="pgu-coach-step">' + (coachIndex + 1) + '/' + coachKeys.length + '</div>'
                + '</div>'
                + '<div>' + text + '</div>'
                + '<div class="pgu-coach-actions">'
                + '  <button type="button" class="pgu-coach-btn" data-coach-done="1">Done</button>'
                + '  <button type="button" class="pgu-coach-btn primary" data-coach-next="1">' + (isLast ? 'Finish' : 'Next') + '</button>'
                + '</div>';
            positionCoachBubble();
        }

        function startCoachTour() {
            if (isDismissed() && !forceOpen) return;
            if (!forceOpen && coachSeenKey && getStorage(coachSeenKey) === '1') return;

            coachKeys = Object.keys(coachMessages).filter(function (key) {
                return !!findTargetForSection(key);
            });
            if (!coachKeys.length) return;

            window.addEventListener('resize', positionCoachBubble);
            window.addEventListener('scroll', positionCoachBubble, true);
            showCoachStep(0);
        }

        function activateGuideSection(key) {
            const target = key || currentSection;
            modal.querySelectorAll('[data-guide-tab]').forEach(function (btn) {
                btn.classList.toggle('active', btn.dataset.guideTab === target);
            });
            modal.querySelectorAll('[data-guide-panel]').forEach(function (panel) {
                panel.classList.toggle('active', panel.dataset.guidePanel === target);
            });
        }

        toggle.addEventListener('click', openGuide);
        modal.querySelectorAll('[data-guide-close="1"]').forEach(function (el) {
            el.addEventListener('click', closeGuide);
        });
        modal.querySelectorAll('[data-guide-tab]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                activateGuideSection(btn.dataset.guideTab || currentSection);
            });
        });

        if (rememberInput) {
            rememberInput.checked = isDismissed();
            rememberInput.addEventListener('change', function () {
                setDismissed(!!rememberInput.checked);
                if (!!rememberInput.checked) finishCoach(true);
            });
        }

        activateGuideSection(currentSection);

        if (forceOpen) {
            setTimeout(startCoachTour, 550);
        } else if (!isDismissed() && seenKey) {
            try {
                if (getStorage(seenKey) !== '1') {
                    setTimeout(openGuide, 450);
                    setStorage(seenKey, '1');
                }
            } catch (_) {
                setTimeout(openGuide, 450);
            }
        }
    })();
</script>
