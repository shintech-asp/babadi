<?php
// ============================================================
// ADD THIS CODE to your provider registration success handler
// (wherever a new provider account is created/approved)
// This creates the Owner portal account automatically
// ============================================================

/**
 * Call this function after a provider is created/approved
 * @param PDO    $db          Your database connection
 * @param int    $provider_id The new provider's ID
 * @param string $company     The company name
 * @param string $email       The provider's email
 * @param string $first_name  Owner's first name
 * @param string $last_name   Owner's last name
 */
function createProviderPortalOwner($db, $provider_id, $company, $email, $first_name, $last_name) {
    // Check if owner already exists
    $chk = $db->prepare("SELECT id FROM provider_staff WHERE provider_id=:p AND role='owner'");
    $chk->execute([':p' => $provider_id]);
    if ($chk->rowCount()) return; // Already exists

    $full_name = trim("$first_name $last_name");
    // Generate username from company name
    $base_username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $company));
    $base_username = substr($base_username, 0, 15) . '_owner';

    // Make sure username is unique
    $username = $base_username;
    $i = 1;
    while (true) {
        $uchk = $db->prepare("SELECT id FROM provider_staff WHERE username=:u");
        $uchk->execute([':u' => $username]);
        if (!$uchk->rowCount()) break;
        $username = $base_username . $i++;
    }

    // Generate temp password
    $temp_pass = substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#'), 0, 10);
    $hash      = password_hash($temp_pass, PASSWORD_DEFAULT);

    $db->prepare("INSERT INTO provider_staff (provider_id, full_name, username, email, password_hash, temp_password, role, department, must_change_password, status)
                  VALUES (:p, :n, :u, :e, :h, :tp, 'owner', 'all', 1, 'active')")
       ->execute([':p'=>$provider_id, ':n'=>$full_name, ':u'=>$username, ':e'=>$email, ':h'=>$hash, ':tp'=>$temp_pass]);

    // Send welcome email
    $subject = "Your Provider Portal Access - Pestify";
    $body    = "Hello $full_name,\n\nYour provider portal account for $company has been created.\n\nPortal Login URL: " . SITE_URL . "/provider-portal/login.php\nUsername: $username\nTemporary Password: $temp_pass\n\nYou can use this portal to manage your HR and Finance departments.\nPlease change your password on first login.\n\nRegards,\nPestify Team";
    @mail($email, $subject, $body, "From: " . NOREPLY_EMAIL);
}

// ============================================================
// EXAMPLE USAGE — Add this where provider is created:
// ============================================================
// After: $db->prepare("INSERT INTO providers ...")->execute([...]);
// Add:
//
// $new_provider_id = $db->lastInsertId();
// createProviderPortalOwner(
//     $db,
//     $new_provider_id,
//     $company_name,    // from $_POST
//     $email,           // provider email
//     $first_name,      // from users table
//     $last_name
// );
//
// ============================================================
// OR — To create for EXISTING providers, run this once:
// ============================================================
//
// $existing = $db->query("
//     SELECT p.id, p.company_name, u.email, u.first_name, u.last_name
//     FROM providers p
//     JOIN users u ON p.user_id = u.id
// ")->fetchAll(PDO::FETCH_ASSOC);
//
// foreach ($existing as $prov) {
//     createProviderPortalOwner($db, $prov['id'], $prov['company_name'], $prov['email'], $prov['first_name'], $prov['last_name']);
// }
// echo "Done! Portal accounts created for all existing providers.";
//