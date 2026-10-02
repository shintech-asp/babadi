<?php
// POST api/v1/portal/hr/employees/store.php
// Create a new employee — mirrors provider-portal/employees.php's "add"
// action exactly: same employee_id code format, same temp-password
// generation, same duplicate-email check, same welcome email (via the
// shared includes/employee_email_helper.php so this can't drift from the
// web version — see that file's doc comment).
require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once dirname(__DIR__, 5) . '/includes/employee_catalog.php';
require_once dirname(__DIR__, 5) . '/vendor/autoload.php';
require_once dirname(__DIR__, 5) . '/includes/employee_email_helper.php';

allow('POST');
$staff = require_portal_role('owner', 'hr');
$pid   = (int)$staff['provider_id'];

$fn   = trim((string) req_inp('first_name', 'First name'));
$ln   = trim((string) req_inp('last_name', 'Last name'));
$em   = trim((string) req_inp('email', 'Email'));
$pos  = trim((string) inp('position', ''));
$dept = trim((string) inp('department', ''));
$sal  = (float) inp('basic_salary', 0);
$hire = trim((string) inp('hire_date', '')) ?: date('Y-m-d');
$emp_type = trim((string) inp('employment_type', 'regular'));
$staff_type = inp('staff_type', 'office') === 'field' ? 'field' : 'office';
$sss_no = trim((string) inp('sss_no', ''));
$philhealth_no = trim((string) inp('philhealth_no', ''));
$pagibig_no = trim((string) inp('pagibig_no', ''));
$tin_no = trim((string) inp('tin_no', ''));

if (!filter_var($em, FILTER_VALIDATE_EMAIL)) {
    fail('A valid email address is required.');
}
if (!in_array($emp_type, ['regular', 'contractual', 'probationary', 'part_time'], true)) {
    fail('Invalid employment_type.');
}

$pdo = db();

$emailCheck = $pdo->prepare('SELECT id FROM employees WHERE email = ?');
$emailCheck->execute([$em]);
if ($emailCheck->fetch()) {
    fail('An employee with this email already exists. Employee login requires a unique email.');
}

$code = 'EMP-' . strtoupper(substr($ln, 0, 3)) . '-' . rand(1000, 9999);
$tmp_pwd = 'Pass@' . rand(10000, 99999);
$hash = password_hash($tmp_pwd, PASSWORD_BCRYPT);

try {
    $stmt = $pdo->prepare(
        'INSERT INTO employees
            (provider_id, employee_id, first_name, last_name, email, position, department, staff_type,
             basic_salary, salary, hire_date, employment_type, sss_no, philhealth_no, pagibig_no, tin_no,
             status, temp_password, password_hash, must_change_pwd, created_at)
         VALUES
            (:pid, :code, :fn, :ln, :em, :pos, :dept, :stype,
             :sal, :sal, :hire, :etype, :sss, :phil, :pag, :tin,
             \'active\', :tmp, :hash, 1, NOW())'
    );
    $stmt->execute([
        ':pid' => $pid, ':code' => $code, ':fn' => $fn, ':ln' => $ln, ':em' => $em,
        ':pos' => $pos, ':dept' => $dept, ':stype' => $staff_type, ':sal' => $sal,
        ':hire' => $hire, ':etype' => $emp_type, ':sss' => $sss_no, ':phil' => $philhealth_no,
        ':pag' => $pagibig_no, ':tin' => $tin_no, ':tmp' => $tmp_pwd, ':hash' => $hash,
    ]);
} catch (Exception $e) {
    fail('Could not create employee: ' . $e->getMessage(), 500);
}

$newId = (int)$pdo->lastInsertId();

$company = $pdo->prepare('SELECT company_name FROM providers WHERE id = ? LIMIT 1');
$company->execute([$pid]);
$companyName = $company->fetchColumn() ?: 'Your Provider';

$emailSent = sendEmployeeWelcomeEmail($em, "$fn $ln", $code, $tmp_pwd, $companyName);

ok([
    'data' => [
        'id' => $newId,
        'employee_id' => $code,
        'temp_password' => $tmp_pwd,
        'email_sent' => $emailSent,
    ],
], 201);
