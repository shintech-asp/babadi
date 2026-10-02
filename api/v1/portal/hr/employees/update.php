<?php
// POST api/v1/portal/hr/employees/update.php
// Partial update of an employee — the web only ever lets HR change status
// (provider-portal/employees.php's "Edit" modal is status-only), but this
// mobile endpoint supports the full editable field set since "CRUD" is the
// explicit ask here; status-only is just the minimum, not a ceiling.
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

allow('POST');
$staff = require_portal_role('owner', 'hr');
$pid   = (int)$staff['provider_id'];

$id = (int) req_inp('id', 'Employee ID');

$pdo = db();

$owns = $pdo->prepare('SELECT id FROM employees WHERE id = ? AND provider_id = ? LIMIT 1');
$owns->execute([$id, $pid]);
if (!$owns->fetch()) {
    fail('Employee not found.', 404);
}

$fields = [];
$params = [':id' => $id, ':pid' => $pid];

$status = inp('status');
if ($status !== null) {
    if (!in_array($status, ['active', 'inactive', 'on_leave'], true)) {
        fail('status must be active, inactive, or on_leave');
    }
    $fields[] = 'status = :status';
    $params[':status'] = $status;
}

$position = inp('position');
if ($position !== null) {
    $fields[] = 'position = :position';
    $params[':position'] = trim($position);
}

$department = inp('department');
if ($department !== null) {
    $fields[] = 'department = :department';
    $params[':department'] = trim($department);
}

$employment_type = inp('employment_type');
if ($employment_type !== null) {
    if (!in_array($employment_type, ['regular', 'contractual', 'probationary', 'part_time'], true)) {
        fail('Invalid employment_type.');
    }
    $fields[] = 'employment_type = :employment_type';
    $params[':employment_type'] = $employment_type;
}

$staff_type = inp('staff_type');
if ($staff_type !== null) {
    $fields[] = 'staff_type = :staff_type';
    $params[':staff_type'] = $staff_type === 'field' ? 'field' : 'office';
}

$basic_salary = inp('basic_salary');
if ($basic_salary !== null) {
    $fields[] = 'basic_salary = :basic_salary, salary = :basic_salary';
    $params[':basic_salary'] = (float)$basic_salary;
}

$sss_no = inp('sss_no');
if ($sss_no !== null) {
    $fields[] = 'sss_no = :sss_no';
    $params[':sss_no'] = trim($sss_no);
}

$philhealth_no = inp('philhealth_no');
if ($philhealth_no !== null) {
    $fields[] = 'philhealth_no = :philhealth_no';
    $params[':philhealth_no'] = trim($philhealth_no);
}

$pagibig_no = inp('pagibig_no');
if ($pagibig_no !== null) {
    $fields[] = 'pagibig_no = :pagibig_no';
    $params[':pagibig_no'] = trim($pagibig_no);
}

$tin_no = inp('tin_no');
if ($tin_no !== null) {
    $fields[] = 'tin_no = :tin_no';
    $params[':tin_no'] = trim($tin_no);
}

if (empty($fields)) {
    fail('No fields provided to update.');
}

$sql = 'UPDATE employees SET ' . implode(', ', $fields) . ' WHERE id = :id AND provider_id = :pid';
$pdo->prepare($sql)->execute($params);

ok(['data' => ['message' => 'Employee updated.']]);
