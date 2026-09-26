<?php
// provider-portal/includes/portal-settings.php
// Shared get/set for per-provider portal settings, stored in admin_settings
// as portal_{pid}_{key}. Used by settings.php (where they're edited) and any
// other portal page that needs to read a configured rate/value (e.g. payroll.php).

if (!function_exists('getSetting')) {
    function getSetting($db, $pid, $key, $default = '') {
        try {
            $s = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key=:k LIMIT 1");
            $s->execute([':k' => "portal_{$pid}_{$key}"]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            return $r ? $r['setting_value'] : $default;
        } catch (Exception $e) { return $default; }
    }
}

if (!function_exists('setSetting')) {
    function setSetting($db, $pid, $key, $value) {
        try {
            $db->prepare("INSERT INTO admin_settings (setting_key, setting_value) VALUES (:k,:v)
                          ON DUPLICATE KEY UPDATE setting_value=:v")
               ->execute([':k' => "portal_{$pid}_{$key}", ':v' => $value]);
        } catch (Exception $e) {}
    }
}
