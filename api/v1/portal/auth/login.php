<?php
// api/v1/portal/auth/login.php
// Portal staff/employee login — standalone endpoint, no auth guard required.
// Kept working on its own (nothing about it changed behaviorally), but the
// Flutter app's actual Login screen calls the centralized
// api/v1/auth/login.php instead — both share the exact same credential/JWT
// logic via resolve_portal_login() in api/v1/_bootstrap.php.
require_once dirname(__DIR__) . '/_bootstrap.php';

allow('POST');

$username_or_email = req_inp('username_or_email', 'username_or_email');
$password          = req_inp('password');

$match = resolve_portal_login($username_or_email, $password);
if ($match) {
    $token = jwt_issue($match['token_claims']);
    ok([
        'account_type'          => $match['account_type'],
        'token'                 => $token,
        'must_change_password'  => $match['must_change_password'],
        'staff'                 => $match['staff'],
    ]);
}

fail('Invalid credentials', 401);
