<?php
// provider-portal/includes/portal-subscription.php
// Include AFTER portal-auth.php. Checks active subscriptions + owner dept toggles.
// Sets: $sub_hr, $sub_finance, $sub_crm (bool), $sub_bundle (bool), $subscriptions array.
// Also sets: $toggle_hr, $toggle_finance, $toggle_crm (owner's manual on/off preference).

if (!isset($db) || !isset($portal_provider_id)) return;

$_pid = (int)$portal_provider_id;

function getActiveSubs($db, $pid) {
    try {
        $s = $db->prepare("SELECT plan, expires_at FROM provider_subscriptions
                           WHERE provider_id=:p AND status='active' AND expires_at > NOW()");
        $s->execute([':p' => $pid]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

function getSubExpiry($db, $pid, $plan) {
    try {
        $s = $db->prepare("SELECT expires_at FROM provider_subscriptions
                           WHERE provider_id=:p AND plan=:plan AND status='active' AND expires_at > NOW()
                           ORDER BY expires_at DESC LIMIT 1");
        $s->execute([':p'=>$pid, ':plan'=>$plan]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ? $r['expires_at'] : null;
    } catch (Exception $e) { return null; }
}

function getDeptToggles($db, $pid) {
    // Returns ['hr'=>1, 'finance'=>1, 'crm'=>1] by default (all on)
    $defaults = ['hr'=>1, 'finance'=>1, 'crm'=>1];
    try {
        $s = $db->prepare("SELECT dept, enabled FROM provider_dept_toggles WHERE provider_id=:p");
        $s->execute([':p'=>$pid]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $defaults[$r['dept']] = (int)$r['enabled'];
        }
    } catch (Exception $e) { /* table may not exist yet, default all on */ }
    return $defaults;
}

$_active_subs  = getActiveSubs($db, $_pid);
$_active_plans = array_column($_active_subs, 'plan');
$_has_bundle   = in_array('bundle', $_active_plans);

// Load owner's dept toggle preferences
$_toggles     = getDeptToggles($db, $_pid);
$toggle_hr      = (bool)$_toggles['hr'];
$toggle_finance = (bool)$_toggles['finance'];
$toggle_crm     = (bool)$_toggles['crm'];

if ($portal_role === 'owner') {
    // Owner: must have subscription AND have the dept toggled ON
    $sub_bundle  = $_has_bundle;
    $sub_hr      = ($_has_bundle || in_array('hr',      $_active_plans)) && $toggle_hr;
    $sub_finance = ($_has_bundle || in_array('finance', $_active_plans)) && $toggle_finance;
    $sub_crm     = ($_has_bundle || in_array('crm',     $_active_plans)) && $toggle_crm;
} else {
    // Staff: dept access based on assignment (toggle doesn't affect staff directly)
    $sub_bundle  = false;
    $sub_hr      = ($portal_dept === 'hr'      || $portal_dept === 'all');
    $sub_finance = ($portal_dept === 'finance' || $portal_dept === 'all');
    $sub_crm     = ($portal_dept === 'crm'     || $portal_dept === 'all');
}

// Expiry dates for display
$sub_bundle_exp  = getSubExpiry($db, $_pid, 'bundle');
$sub_hr_exp      = $sub_bundle_exp ?: getSubExpiry($db, $_pid, 'hr');
$sub_finance_exp = $sub_bundle_exp ?: getSubExpiry($db, $_pid, 'finance');
$sub_crm_exp     = $sub_bundle_exp ?: getSubExpiry($db, $_pid, 'crm');

// Subscriptions array for the subscription page
$subscriptions = $_active_subs;