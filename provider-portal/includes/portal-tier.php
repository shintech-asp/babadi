<?php
// provider-portal/includes/portal-tier.php
// Include AFTER portal-auth.php and after $db is set.
// Sets $provider_tier ('free'|'paid'|'grace'), $tier_is_paid, $tier_expires, $tier_grace, $tier_cycle, $tier_plan

if (!function_exists('getProviderTier')) {
    function getProviderTier($db, $provider_id): array {
        $empty = ['tier'=>'free','expires_at'=>null,'grace_ends_at'=>null,'billing_cycle'=>null,'plan_name'=>null];
        if (!$db || !$provider_id) return $empty;
        try {
            $now = date('Y-m-d H:i:s');
            // H7 fix: exclude 'pending' — renewal-in-progress must not mask the active row
            // H1 fix: NULL expires_at sorts last under DESC on MariaDB; id DESC is the tie-break
            $s = $db->prepare(
                "SELECT ps.*, sp.name AS plan_name
                 FROM provider_subscriptions ps
                 LEFT JOIN subscription_plans sp ON sp.id = ps.plan_id
                 WHERE ps.provider_id = :p AND ps.plan_id IS NOT NULL
                   AND ps.status IN ('active','grace')
                   AND ps.expires_at IS NOT NULL
                 ORDER BY ps.expires_at DESC, ps.id DESC LIMIT 1"
            );
            $s->execute([':p' => (int)$provider_id]);
            $sub = $s->fetch(PDO::FETCH_ASSOC);

            if (!$sub) return $empty;

            if ($sub['expires_at'] > $now) {
                return [
                    'tier'         => 'paid',
                    'expires_at'   => $sub['expires_at'],
                    'grace_ends_at'=> $sub['grace_ends_at'],
                    'billing_cycle'=> $sub['billing_cycle'],
                    'plan_name'    => $sub['plan_name'],
                ];
            }

            // H2 fix: derive grace end from expires_at if grace_ends_at is NULL
            $graceEnds = $sub['grace_ends_at']
                ?: date('Y-m-d H:i:s', strtotime($sub['expires_at']) + 3 * 86400);

            if ($graceEnds > $now) {
                if ($sub['status'] !== 'grace') {
                    try {
                        $db->prepare("UPDATE provider_subscriptions SET status='grace', grace_ends_at=:g, updated_at=NOW() WHERE id=:id")
                           ->execute([':g' => $graceEnds, ':id' => $sub['id']]);
                    } catch (Exception $e) {}
                }
                return [
                    'tier'         => 'grace',
                    'expires_at'   => $sub['expires_at'],
                    'grace_ends_at'=> $graceEnds,
                    'billing_cycle'=> $sub['billing_cycle'],
                    'plan_name'    => $sub['plan_name'],
                ];
            }

            if ($sub['status'] !== 'expired') {
                try {
                    $db->prepare("UPDATE provider_subscriptions SET status='expired',updated_at=NOW() WHERE id=:id")
                       ->execute([':id' => $sub['id']]);
                } catch (Exception $e) {}
            }
            return $empty;
        } catch (Exception $e) { return $empty; }
    }

    function _tierLockedPage(string $feature, string $upgrade_url = 'subscriptions.php'): string {
        $f = htmlspecialchars($feature);
        $u = htmlspecialchars($upgrade_url);
        return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $f . ' &mdash; Pro Required &middot; Pestify</title>'
            . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">'
            . '<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,700;9..40,800&display=swap" rel="stylesheet">'
            . '<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:"DM Sans",sans-serif;background:#f5f7fa}'
            . '.portal-main{margin-left:250px;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:40px}'
            . '@media(max-width:768px){.portal-main{margin-left:0}}</style></head><body>'
            . '<div class="portal-main"><div style="text-align:center;max-width:460px">'
            . '<div style="width:68px;height:68px;background:linear-gradient(135deg,#e0e7ff,#c7d2fe);border-radius:20px;display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 18px">&#128274;</div>'
            . '<h2 style="font-size:20px;font-weight:800;color:#1e293b;margin-bottom:8px">' . $f . ' &mdash; Pro Feature</h2>'
            . '<p style="font-size:13px;color:#718096;max-width:340px;margin:0 auto 22px;line-height:1.6">This feature requires an active Pro subscription. Upgrade to unlock the full provider portal including HR, Finance, CRM, and Biometric Attendance.</p>'
            . '<a href="' . $u . '" style="display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;padding:12px 26px;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none;box-shadow:0 4px 14px rgba(99,102,241,.3)">'
            . '<i class="fas fa-star"></i> Upgrade to Pro</a>'
            . '</div></div></body></html>';
    }
}

if (isset($db, $portal_provider_id)) {
    $__tier        = getProviderTier($db, $portal_provider_id);
    $provider_tier = $__tier['tier'];
    $tier_is_paid  = in_array($provider_tier, ['paid', 'grace']);
    $tier_expires  = $__tier['expires_at'];
    $tier_grace    = $__tier['grace_ends_at'];
    $tier_cycle    = $__tier['billing_cycle'];
    $tier_plan     = $__tier['plan_name'];
}
