<?php
// provider-portal/my-services.php — field technician self-service: view AND
// process bookings assigned to them. Two ways a booking counts as "theirs"
// (both use the same $where fragment below, reused by every POST handler's
// ownership check too):
//   1. Confirmed: availed_services.assigned_employee_id is set, via
//      provider/service-requests.php's "Prepare Booking" flow, OR because
//      this tech already submitted an inspection report for it (that also
//      sets assigned_employee_id — see submitProviderInspectionReport()).
//   2. Tentative: the booking's service's default handler
//      (services.assigned_staff_id) is this technician — surfaced as soon
//      as the request comes in, even while still 'pending', so a tech has
//      full pipeline visibility. Flagged as "Tentative" in the UI since
//      equipment/notes aren't finalized until Prepare Booking actually runs.
//      A still-pending booking shows no action buttons (nothing to do until
//      the provider accepts it) but is visible + read-only, including chat.
//
// Processing actions (Enter Seeker Code, Scan QR, Submit Inspection Report,
// Mark Job Done) reuse the exact same shared functions
// provider/service-requests.php's own web dashboard calls — see
// includes/booking_workflow_helper.php's "Provider-portal action helpers"
// section — rather than a second, independently-drifting copy of this
// logic. A tech's session (portal_employee_id) is structurally different
// from the provider's own session (user_id/user_type='provider'), which is
// why this page can't just link to service-requests.php directly.
require_once 'includes/portal-auth.php';
require_once '../config/config.php';
require_once '../config/database.php';
require_once appPath('includes/booking_workflow_helper.php');
require_once appPath('includes/availed_booking_helper.php');
require_once appPath('includes/payment_receipt_helper.php');
require_once appPath('config/send_email.php');

$database = new Database();
$db = $database->getConnection();
$pid = (int)$portal_provider_id;

$self_emp_id  = (int)($_SESSION['portal_employee_id'] ?? 0);
$staff_type   = $_SESSION['portal_staff_type'] ?? 'office';
if (!$self_emp_id || $staff_type !== 'field') { header('Location: dashboard.php'); exit; }

require_once 'includes/portal-tier.php';

function safeAll($db,$sql,$p=[]){try{$s=$db->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){return[];}}

// Ownership fragment shared by the page's own listing query AND every POST
// action handler below — a booking is "mine" if either the confirmed or
// tentative condition (see doc comment above) holds.
$OWNERSHIP_WHERE = "av.provider_id = :pid AND (av.assigned_employee_id = :eid OR (av.assigned_employee_id IS NULL AND s.assigned_staff_id = :eid))";

function myServiceBooking(PDO $db, int $pid, int $eid, int $availId, string $ownershipWhere): ?array {
    $stmt = $db->prepare(
        "SELECT av.*, s.requires_inspection
         FROM availed_services av
         LEFT JOIN services s ON s.id = av.service_id
         WHERE av.id = :aid AND $ownershipWhere
         LIMIT 1"
    );
    $stmt->execute([':aid' => $availId, ':pid' => $pid, ':eid' => $eid]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Inventory for the Prepare Booking equipment/consumables picker — same
// query/availability computation as provider/service-requests.php's own
// Prepare Booking modal (see that file's setup block for the doc comment).
$prepInventory = [];
try {
    $invStmt = $db->prepare(
        "SELECT id, item_name, item_type, quantity_available, unit
         FROM inventory_items WHERE provider_id = ? AND is_archived = 0 ORDER BY item_type, item_name"
    );
    $invStmt->execute([$pid]);
    $prepInventory = $invStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* inventory module may not be set up yet */ }
foreach ($prepInventory as &$piRow) {
    $piRow['available_now'] = $piRow['item_type'] === 'equipment'
        ? max(0, (int)$piRow['quantity_available'] - getCheckedOutQuantity($db, (int)$piRow['id']))
        : (int)$piRow['quantity_available'];
}
unset($piRow);
// Not filtered to available_now > 0 — see provider/service-requests.php's
// identical comment: an item already fully checked out elsewhere must still
// appear (at 0) so editing an already-'preparing' booking can find its row
// and raise the ceiling by what this booking itself already holds.
$prepEquipment   = array_values(array_filter($prepInventory, fn($i) => $i['item_type'] === 'equipment'));
$prepConsumables = array_values(array_filter($prepInventory, fn($i) => $i['item_type'] === 'consumable'));

// Other active field technicians this tech can pick as an optional
// companion (co-staff) when preparing/editing a booking — self excluded.
$companionOptions = safeAll(
    $db,
    "SELECT id, CONCAT(first_name, ' ', last_name) AS full_name
     FROM employees
     WHERE provider_id = :pid AND status = 'active' AND staff_type = 'field' AND id != :eid
     ORDER BY first_name",
    [':pid' => $pid, ':eid' => $self_emp_id]
);

// ── AJAX: View Details — read-only mirror of provider/service-requests.php's
// own "View Details" modal, so a tech can see the same booking picture the
// provider sees (payment, control numbers, verification, workflow step,
// inspection/reschedule info) without gaining any new mutation ability —
// every actual action stays a separate button this page already has.
// Reuses the same shared statusInfo()/normalizeWorkflowStatus()/
// fetchReceiptsForBookings() the owner's page uses, not a second copy.
if (isset($_POST['action']) && $_POST['action'] === 'view_details') {
    header('Content-Type: application/json');
    $availId = (int)($_POST['avail_id'] ?? 0);
    $av = myServiceBooking($db, $pid, $self_emp_id, $availId, $OWNERSHIP_WHERE);
    if (!$av) { echo json_encode(['ok' => false, 'error' => 'Booking not found or not assigned to you.']); exit; }

    $si = statusInfo($av['status']);
    $receiptsByBooking = fetchReceiptsForBookings($db, [$availId]);
    $receipts = array_map(function ($r) {
        return [
            'number' => (string)($r['receipt_number'] ?? ''),
            'type'   => paymentReceiptTypeLabel($r['payment_type'] ?? ''),
            'amount' => number_format((float)($r['amount'] ?? 0), 2),
            'paidAt' => !empty($r['paid_at']) ? date('M j, Y g:i A', strtotime((string)$r['paid_at'])) : 'N/A',
        ];
    }, $receiptsByBooking[$availId] ?? []);

    $requiresInspection = !empty($av['requires_inspection']);
    $inspectionAgreed = !empty($av['inspection_agreed_at']);

    $rescheduleState = strtolower(trim((string)($av['reschedule_request_status'] ?? '')));
    $isReschedulePending = $rescheduleState === 'pending' && !empty($av['reschedule_proposed_date']) && !empty($av['reschedule_proposed_time']);
    $isRescheduleRejected = $rescheduleState === 'rejected' && !empty($av['reschedule_proposed_date']) && !empty($av['reschedule_proposed_time']);

    echo json_encode([
        'ok' => true,
        'id'            => (int)$av['id'],
        'service'       => $av['service_name'] ?? '-',
        'fullName'      => $av['full_name'],
        'contact'       => $av['contact_number'],
        'date'          => date('F d, Y', strtotime($av['preferred_date'])),
        'time'          => date('h:i A', strtotime($av['preferred_time'])),
        'address'       => (string)($av['address'] ?? ''),
        'notes'         => (string)($av['notes'] ?? ''),
        'status'        => $av['status'],
        'statusLabel'   => $si['label'],
        'statusBg'      => $si['bg'],
        'statusColor'   => $si['color'],
        'submitted'     => date('M d, Y h:i A', strtotime($av['created_at'])),
        'isEmergency'   => !empty($av['emergency_now_requested']) && !in_array($av['status'], ['completed', 'cancelled'], true),
        'emergencyAccepted' => !empty($av['emergency_now_accepted_at']),
        'totalAmount'   => number_format((float)($av['total_amount'] ?? 0), 2),
        'paidAmount'    => number_format((float)($av['paid_amount'] ?? 0), 2),
        'remainingAmount' => number_format((float)($av['remaining_amount'] ?? 0), 2),
        'paymentMethod' => ucwords(str_replace('_', ' ', (string)($av['payment_method'] ?? ''))),
        'paymentStatus' => ucfirst((string)($av['payment_status'] ?? '')),
        'receipts'      => $receipts,
        // Same "paid or at least a downpayment" bar the action buttons
        // enforce — the whole Control Numbers section stays hidden pre-payment.
        'isPaidEnough'     => in_array((string)($av['payment_status'] ?? ''), ['paid', 'partial'], true),
        // Seeker's code stays masked here too — same reasoning as the
        // provider's own panel: it must come verbally from the seeker in
        // person, not be readable from any dashboard. The provider's own
        // code is shown in the clear since the tech needs to share it.
        'seekerCodeSet'    => !empty($av['control_number']),
        'providerCode'     => (string)($av['provider_control_number'] ?? ''),
        'seekerVerified'   => !empty($av['seeker_verified_at']),
        'providerVerified' => !empty($av['provider_verified_at']),
        'dualVerified'     => !empty($av['dual_verified_at']),
        'requiresInspection' => $requiresInspection,
        'inspectionAgreed'   => $inspectionAgreed,
        'inspectionDate'     => !empty($av['inspection_date']) ? date('F d, Y', strtotime((string)$av['inspection_date'])) : '',
        'workingDate'        => !empty($av['working_date']) ? date('F d, Y', strtotime((string)$av['working_date'])) : '',
        'inspectionRound'    => (int)($av['inspection_round'] ?? 0),
        'inspectionReportNotes'         => (string)($av['inspection_report_notes'] ?? ''),
        'inspectionProposedPrice'       => number_format((float)($av['inspection_proposed_price'] ?? 0), 2),
        'inspectionProposedWorkingDate' => !empty($av['inspection_proposed_working_date']) ? date('F d, Y', strtotime((string)$av['inspection_proposed_working_date'])) : '',
        'inspectionChangeNotes'         => (string)($av['inspection_change_notes'] ?? ''),
        'isReschedulePending'  => $isReschedulePending,
        'isRescheduleRejected' => $isRescheduleRejected,
        'rescheduleSummary'    => ($isReschedulePending || $isRescheduleRejected)
            ? date('M j, Y', strtotime((string)$av['reschedule_proposed_date'])) . ' at ' . date('g:i A', strtotime((string)$av['reschedule_proposed_time']))
            : '',
        'rescheduleReason'     => (string)($av['reschedule_reason'] ?? ''),
        'assignedEmployeeId'   => (int)($av['assigned_employee_id'] ?? 0),
        'companionEmployeeId'  => (int)($av['companion_employee_id'] ?? 0),
        'operationsNotes'      => (string)($av['operations_notes'] ?? ''),
        // Used to prefill "Edit Equipment" on an already-'preparing' booking
        // with what it actually currently holds — see openPrepareModal().
        'assignedEquipment'    => (function() use ($av) {
            $decoded = json_decode((string)($av['assigned_equipment'] ?? ''), true);
            return is_array($decoded) ? array_map(fn($i) => ['id' => (int)($i['inventory_item_id'] ?? 0), 'qty' => (int)($i['quantity_needed'] ?? 0)], $decoded) : [];
        })(),
        'assignedConsumables'  => (function() use ($av) {
            $decoded = json_decode((string)($av['assigned_consumables'] ?? ''), true);
            return is_array($decoded) ? array_map(fn($i) => ['id' => (int)($i['inventory_item_id'] ?? 0), 'qty' => (int)($i['quantity_needed'] ?? 0)], $decoded) : [];
        })(),
    ]);
    exit;
}

// ── AJAX: verify seeker control number ──────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'verify_control_number') {
    header('Content-Type: application/json');
    $availId = (int)($_POST['avail_id'] ?? 0);
    $code    = (string)($_POST['control_number_input'] ?? '');
    $booking = myServiceBooking($db, $pid, $self_emp_id, $availId, $OWNERSHIP_WHERE);
    if (!$booking) { echo json_encode(['result' => 'fail', 'error' => 'Booking not found or not assigned to you.']); exit; }
    $result = verifyProviderSeekerCode($db, $availId, $pid, $code, false, false);
    echo json_encode($result);
    exit;
}

// ── AJAX: accept/decline a pending request ───────────────────────────────
// "Let them decide" — the assigned tech can now accept or decline a still-
// pending request themselves instead of waiting on the owner, reusing the
// exact same acceptAvailedBooking()/declineAvailedBooking() helpers
// provider/service-requests.php's own Accept/Decline buttons call.
if (isset($_POST['action']) && $_POST['action'] === 'accept_request') {
    header('Content-Type: application/json');
    $availId = (int)($_POST['avail_id'] ?? 0);
    $booking = myServiceBooking($db, $pid, $self_emp_id, $availId, $OWNERSHIP_WHERE);
    if (!$booking) { echo json_encode(['ok' => false, 'error' => 'Booking not found or not assigned to you.']); exit; }
    $result = acceptAvailedBooking($db, $availId, $pid, $self_emp_id, 'field_technician', 'Service request accepted by field technician.');
    echo json_encode($result);
    exit;
}
if (isset($_POST['action']) && $_POST['action'] === 'decline_request') {
    header('Content-Type: application/json');
    $availId = (int)($_POST['avail_id'] ?? 0);
    $reason  = trim((string)($_POST['reason'] ?? ''));
    $booking = myServiceBooking($db, $pid, $self_emp_id, $availId, $OWNERSHIP_WHERE);
    if (!$booking) { echo json_encode(['ok' => false, 'error' => 'Booking not found or not assigned to you.']); exit; }
    $result = declineAvailedBooking($db, $availId, $pid, $self_emp_id, 'field_technician', $reason);
    echo json_encode($result);
    exit;
}

// ── AJAX: scan seeker QR ────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'scan_qr') {
    header('Content-Type: application/json');
    $availId = (int)($_POST['avail_id'] ?? 0);
    $token   = strtoupper(trim((string)($_POST['token'] ?? '')));
    $booking = myServiceBooking($db, $pid, $self_emp_id, $availId, $OWNERSHIP_WHERE);
    if (!$booking) { echo json_encode(['success' => false, 'message' => 'Booking not found or not assigned to you.']); exit; }
    if (!$token) { echo json_encode(['success' => false, 'message' => 'No token provided.']); exit; }
    $result = scanQrAndStartService($db, $token, $self_emp_id);
    echo json_encode($result);
    exit;
}

// ── AJAX: mark on-site work done (ongoing → payment/confirmation stage) ──
if (isset($_POST['action']) && $_POST['action'] === 'mark_job_done') {
    header('Content-Type: application/json');
    $availId = (int)($_POST['avail_id'] ?? 0);
    $booking = myServiceBooking($db, $pid, $self_emp_id, $availId, $OWNERSHIP_WHERE);
    if (!$booking) { echo json_encode(['type' => 'error', 'message' => 'Booking not found or not assigned to you.']); exit; }
    // advanceAvailedServiceStatus() itself corrects 'waiting_remaining_payment'
    // down to 'waiting_provider_confirmation' when the booking isn't actually
    // partially paid, so this is the right request regardless of payment_status.
    $result = advanceAvailedServiceStatus($db, $pid, $availId, 'waiting_remaining_payment', $self_emp_id, 'field_technician', 'Field technician');
    echo json_encode($result);
    exit;
}

// ── AJAX: prepare booking (self-assign + equipment/consumables) ─────────
// A tech is the one who will handle the booking, so unlike the owner's
// version (which picks a technician from a dropdown), this always assigns
// the current logged-in tech to themselves — $validStaffIds is just their
// own id, so prepareAvailedBooking() rejects any spoofed staff_id. Also the
// entry point for re-editing an already-'preparing' booking (not just the
// one-shot accepted->preparing trigger) — myServiceBooking()'s ownership
// check covers both the tentative (about to prepare) and confirmed (already
// preparing, editing) cases equally.
if (isset($_POST['action']) && $_POST['action'] === 'prepare_booking') {
    header('Content-Type: application/json');
    $availId = (int)($_POST['avail_id'] ?? 0);
    $booking = myServiceBooking($db, $pid, $self_emp_id, $availId, $OWNERSHIP_WHERE);
    if (!$booking) { echo json_encode(['type' => 'error', 'message' => 'Booking not found or not assigned to you.']); exit; }
    $equipment = json_decode($_POST['equipment_json'] ?? '[]', true) ?: [];
    $consumables = json_decode($_POST['consumables_json'] ?? '[]', true) ?: [];
    $notes = trim((string)($_POST['operations_notes'] ?? ''));
    $companionId = !empty($_POST['companion_id']) ? (int)$_POST['companion_id'] : null;
    $companionIds = array_map('intval', array_column($companionOptions, 'id'));
    $result = prepareAvailedBooking(
        $db, $pid, $availId, $self_emp_id, [$self_emp_id],
        $equipment, $consumables, $notes,
        $self_emp_id, 'field_technician', $companionId, $companionIds
    );
    echo json_encode($result);
    exit;
}

// ── AJAX: submit/resubmit inspection report ─────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'submit_inspection_report') {
    header('Content-Type: application/json');
    $availId = (int)($_POST['avail_id'] ?? 0);
    $booking = myServiceBooking($db, $pid, $self_emp_id, $availId, $OWNERSHIP_WHERE);
    if (!$booking) { echo json_encode(['type' => 'error', 'message' => 'Booking not found or not assigned to you.']); exit; }
    $result = submitProviderInspectionReport(
        $db, $pid, $availId, $self_emp_id, [$self_emp_id],
        trim((string)($_POST['inspection_notes'] ?? '')),
        (float)($_POST['proposed_price'] ?? 0),
        trim((string)($_POST['proposed_working_date'] ?? '')),
        $self_emp_id, 'field_technician'
    );
    echo json_encode($result);
    exit;
}

$statusFilter = $_GET['status'] ?? '';
$where = $OWNERSHIP_WHERE;
$params = [':pid' => $pid, ':eid' => $self_emp_id];
if ($statusFilter !== '') { $where .= " AND av.status = :st"; $params[':st'] = $statusFilter; }

$myBookings = safeAll($db,
    "SELECT av.id, av.service_name, av.full_name, av.contact_number, av.preferred_date, av.preferred_time,
            av.address, av.status, av.operations_notes, av.assigned_employee_id, av.payment_status,
            av.inspection_agreed_at, s.requires_inspection
     FROM availed_services av
     LEFT JOIN services s ON s.id = av.service_id
     WHERE $where
     ORDER BY av.preferred_date DESC, av.preferred_time DESC",
    $params
);

$statusColors = [
    'preparing'  => ['bg'=>'#d1ecf1','color'=>'#0c5460'],
    'starting'   => ['bg'=>'#fef9c3','color'=>'#854d0e'],
    'on_going'   => ['bg'=>'#dbeafe','color'=>'#1e40af'],
    'completed'  => ['bg'=>'#d1fae5','color'=>'#065f46'],
    'cancelled'  => ['bg'=>'#fee2e2','color'=>'#991b1b'],
];

$active_menu = 'my_services';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Assigned Services · <?= htmlspecialchars($portal_company) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
<style>
:root{--primary:#2E8B57;--dark:#1a2744;--bg:#f5f7fa;--border:#e2e8f0;--muted:#718096}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:#2d3748}
.dashboard-layout{display:flex;min-height:100vh}
.portal-main{flex:1;min-width:0}.main-content{padding:30px}
.page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:22px;flex-wrap:wrap;gap:12px}
.page-header h1{font-size:22px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:8px}
.page-header h1 i{color:var(--primary)}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px}
.filters{display:flex;gap:8px;flex-wrap:wrap}
.filters a{padding:7px 14px;border:1px solid var(--border);border-radius:999px;font-size:12px;font-weight:600;color:var(--dark);text-decoration:none;background:#fff}
.filters a.active{background:var(--primary);color:#fff;border-color:var(--primary)}
.booking-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
.booking-card{background:#fff;border-radius:14px;border:1px solid var(--border);box-shadow:0 2px 8px rgba(0,0,0,.06);padding:18px}
.bc-top{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:10px}
.bc-service{font-size:15px;font-weight:700;color:var(--dark)}
.bc-status{font-size:10px;font-weight:700;padding:3px 10px;border-radius:999px;text-transform:uppercase}
.bc-row{display:flex;align-items:flex-start;gap:8px;font-size:13px;color:#475569;margin-bottom:6px}
.bc-row i{width:16px;color:var(--muted);margin-top:2px}
.bc-notes{margin-top:10px;padding-top:10px;border-top:1px solid #f1f5f9;font-size:12px;color:var(--muted)}
.empty-state{padding:40px;text-align:center;color:var(--muted);font-size:13px;background:#fff;border-radius:14px;border:1px solid var(--border)}
.bc-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;padding-top:14px;border-top:1px solid #f1f5f9}
.bc-btn{flex:1;min-width:140px;display:flex;align-items:center;justify-content:center;gap:7px;padding:10px 12px;border-radius:9px;border:none;font-size:12.5px;font-weight:700;cursor:pointer;color:#fff;background:var(--primary)}
.bc-btn.secondary{background:#fff;color:var(--dark);border:1.5px solid var(--border)}
.bc-btn.purple{background:linear-gradient(135deg,#4a235a,#6c3483)}
.bc-btn.blue{background:linear-gradient(135deg,#1e3a8a,#1d4ed8)}
.bc-btn.teal{background:linear-gradient(135deg,#0c5460,#0891b2)}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(10,20,40,.78);backdrop-filter:blur(6px);z-index:21000;align-items:center;justify-content:center;padding:20px}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:18px;max-width:480px;width:100%;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.35);overflow:hidden}
.modal-head{padding:20px 22px 16px;position:relative;display:flex;align-items:center;gap:12px;flex-shrink:0;color:#fff}
.modal-head h3{margin:0 0 2px;font-size:16px;font-weight:800}
.modal-head p{margin:0;font-size:12px;opacity:.85}
.modal-close{position:absolute;top:12px;right:12px;background:rgba(255,255,255,.15);border:none;color:#fff;width:28px;height:28px;border-radius:50%;cursor:pointer}
.modal-body{padding:20px 22px;overflow-y:auto}
.modal-foot{padding:14px 22px;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;gap:10px;flex-shrink:0}
.modal-field{margin-bottom:14px}
.modal-field label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:6px}
.modal-field input,.modal-field textarea{width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:14px;font-family:inherit}
.modal-result{display:none;padding:10px 13px;border-radius:9px;margin-bottom:14px;font-size:12.5px}
.modal-result.info{display:flex;background:#f1f5f9;color:#475569}
.modal-result.ok{display:flex;background:#dcfce7;color:#166534}
.modal-result.err{display:flex;background:#fef2f2;color:#991b1b}
.btn-plain{padding:9px 16px;border:1.5px solid var(--border);border-radius:9px;background:#fff;font-size:12.5px;font-weight:700;cursor:pointer}
/* View Details modal — read-only mirror of the provider admin's own panel */
.vw-box{max-width:640px}
.vw-section{margin-bottom:18px}
.vw-section:last-child{margin-bottom:0}
.vw-section-title{font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px;display:flex;align-items:center;gap:6px}
.vw-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.vw-row{padding:8px 0;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;gap:10px;font-size:13px}
.vw-row:last-child{border-bottom:none}
.vw-row .k{color:#64748b}
.vw-row .v{color:#1a2744;font-weight:600;text-align:right}
.vw-code{font-family:'Courier New',monospace;letter-spacing:.05em;background:#f1f5f9;padding:2px 8px;border-radius:6px}
.vw-check{display:flex;align-items:center;gap:6px;font-size:12.5px}
.vw-check i.yes{color:#16a34a}
.vw-check i.no{color:#cbd5e1}
.vw-badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:10px;font-weight:700;text-transform:uppercase}
.vw-receipt{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:12.5px}
.vw-receipt:last-child{border-bottom:none}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include 'includes/portal-sidebar.php'; ?>
<div class="portal-main"><div class="main-content">

    <div class="page-header">
        <div>
            <h1><i class="fas fa-people-carry-box"></i> My Assigned Services</h1>
            <p>Bookings your provider has assigned to you — process them right from here.</p>
        </div>
        <div class="filters">
            <a href="?status=" class="<?= $statusFilter === '' ? 'active' : '' ?>">All</a>
            <a href="?status=preparing" class="<?= $statusFilter === 'preparing' ? 'active' : '' ?>">Preparing</a>
            <a href="?status=starting" class="<?= $statusFilter === 'starting' ? 'active' : '' ?>">Starting</a>
            <a href="?status=on_going" class="<?= $statusFilter === 'on_going' ? 'active' : '' ?>">Ongoing</a>
            <a href="?status=completed" class="<?= $statusFilter === 'completed' ? 'active' : '' ?>">Completed</a>
        </div>
    </div>

    <?php if (empty($myBookings)): ?>
        <div class="empty-state"><i class="fas fa-inbox" style="font-size:24px;margin-bottom:8px;display:block"></i>No assigned bookings<?= $statusFilter !== '' ? ' with this status' : '' ?> yet.</div>
    <?php else: ?>
    <div class="booking-grid">
        <?php foreach ($myBookings as $b): $sc = $statusColors[$b['status']] ?? ['bg'=>'#f1f5f9','color'=>'#475569']; ?>
        <div class="booking-card">
            <div class="bc-top">
                <div class="bc-service"><?= htmlspecialchars($b['service_name'] ?: 'Service') ?></div>
                <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-end;">
                    <span class="bc-status" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>"><?= htmlspecialchars(ucfirst(str_replace('_',' ',$b['status']))) ?></span>
                    <?php if (empty($b['assigned_employee_id'])): ?>
                    <span class="bc-status" style="background:#fef3c7;color:#92400e;" title="Assigned by default from the service's field technician setting — not yet locked in via Prepare Booking, so equipment/notes may still change.">Tentative</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="bc-row"><i class="fas fa-user"></i> <?= htmlspecialchars($b['full_name']) ?> &middot; <?= htmlspecialchars($b['contact_number']) ?></div>
            <div class="bc-row"><i class="fas fa-calendar"></i> <?= htmlspecialchars(date('M j, Y', strtotime($b['preferred_date']))) ?> at <?= htmlspecialchars(date('g:i A', strtotime($b['preferred_time']))) ?></div>
            <div class="bc-row"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($b['address']) ?></div>
            <?php if (!empty($b['operations_notes'])): ?>
            <div class="bc-notes"><i class="fas fa-note-sticky"></i> <?= htmlspecialchars($b['operations_notes']) ?></div>
            <?php endif; ?>
            <?php if ($b['status'] === 'pending'): ?>
            <div class="bc-notes"><i class="fas fa-hourglass-half"></i> New request — accept or decline it below.</div>
            <?php endif; ?>

            <?php
            $needsInspection = !empty($b['requires_inspection']) && in_array($b['status'], ['accepted', 'revising'], true) && empty($b['inspection_agreed_at']);
            // Same "paid or at least a downpayment" bar verifyProviderSeekerCode()
            // now enforces server-side (includes/booking_workflow_helper.php).
            $isPaidEnough = in_array($b['payment_status'], ['paid', 'partial'], true);
            // Once agreed (or never required) and paid, 'accepted'/'preparing'
            // bookings are ready for the code handshake — the only gate into
            // 'starting'.
            $canEnterCode = in_array($b['status'], ['accepted', 'preparing'], true) && !$needsInspection && $isPaidEnough;
            ?>
            <div class="bc-actions">
                <button class="bc-btn secondary" onclick="openViewModal(<?= (int)$b['id'] ?>)">
                    <i class="fas fa-eye"></i> View
                </button>
                <?php if ($b['status'] === 'pending'): ?>
                <button class="bc-btn" onclick="acceptRequest(<?= (int)$b['id'] ?>, this)">
                    <i class="fas fa-check"></i> Accept
                </button>
                <button class="bc-btn secondary" style="color:#c0392b;border-color:#f1c7c2;" onclick="openDeclineModal(<?= (int)$b['id'] ?>)">
                    <i class="fas fa-times-circle"></i> Decline
                </button>
                <?php endif; ?>
                <?php if ($b['status'] === 'accepted' && !$needsInspection): ?>
                <button class="bc-btn teal" onclick="openPrepareModal(<?= (int)$b['id'] ?>, false)">
                    <i class="fas fa-people-carry-box"></i> Prepare Booking
                </button>
                <?php endif; ?>
                <?php if ($b['status'] === 'preparing'): ?>
                <button class="bc-btn teal" onclick="openPrepareModal(<?= (int)$b['id'] ?>, true)">
                    <i class="fas fa-toolbox"></i> Edit Equipment
                </button>
                <?php endif; ?>
                <?php if ($needsInspection): ?>
                <button class="bc-btn purple" onclick="openInspectionModal(<?= (int)$b['id'] ?>, '<?= $b['status'] === 'revising' ? 'Resubmit' : 'Submit' ?>')">
                    <i class="fas fa-clipboard-check"></i> <?= $b['status'] === 'revising' ? 'Resubmit' : 'Submit' ?> Inspection
                </button>
                <?php elseif ($canEnterCode): ?>
                <button class="bc-btn" onclick="openCodeModal(<?= (int)$b['id'] ?>)">
                    <i class="fas fa-shield-alt"></i> Enter Seeker Code
                </button>
                <?php elseif ($b['status'] === 'starting'): ?>
                <button class="bc-btn blue" onclick="openQrModal(<?= (int)$b['id'] ?>)">
                    <i class="fas fa-qrcode"></i> Scan Seeker QR
                </button>
                <?php elseif ($b['status'] === 'on_going'): ?>
                <button class="bc-btn" onclick="markJobDone(<?= (int)$b['id'] ?>, this)">
                    <i class="fas fa-flag-checkered"></i> Mark Job Done
                </button>
                <?php endif; ?>
                <a class="bc-btn secondary" href="portal-messages.php?booking=<?= (int)$b['id'] ?>" style="text-decoration:none;">
                    <i class="fas fa-comment-dots"></i> Message
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div></div>
</div>

<!-- ── Enter Seeker Code Modal ──────────────────────────────── -->
<div class="modal-overlay" id="codeModal">
  <div class="modal-box">
    <div class="modal-head" style="background:linear-gradient(135deg,#1a2744,#2d3561);">
      <div style="width:38px;height:38px;background:rgba(255,255,255,.15);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;"><i class="fas fa-shield-alt"></i></div>
      <div><h3>Enter Seeker Code</h3><p>Ask the seeker to read out their code, then type it below.</p></div>
      <button class="modal-close" onclick="closeModal('codeModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="modal-result" id="codeResult"></div>
      <div class="modal-field">
        <label>Seeker's Code</label>
        <input type="text" id="codeInput" placeholder="e.g. PCF-2026-000123" style="font-family:'Courier New',monospace;letter-spacing:.05em;text-transform:uppercase" onkeydown="if(event.key==='Enter')submitCode()">
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn-plain" onclick="closeModal('codeModal')">Cancel</button>
      <button class="bc-btn" style="flex:none;padding:9px 18px;" onclick="submitCode()"><i class="fas fa-check"></i> Verify</button>
    </div>
  </div>
</div>

<!-- ── Scan Seeker QR Modal ─────────────────────────────────── -->
<div class="modal-overlay" id="qrModal">
  <div class="modal-box">
    <div class="modal-head" style="background:linear-gradient(135deg,#1e3a8a,#1d4ed8);">
      <div style="width:38px;height:38px;background:rgba(255,255,255,.15);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;"><i class="fas fa-qrcode"></i></div>
      <div><h3>Scan Seeker QR Code</h3><p>Ask the seeker to open their QR, then scan or paste the token.</p></div>
      <button class="modal-close" onclick="closeModal('qrModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="modal-result" id="qrResult"></div>
      <div style="margin-bottom:16px;">
        <div id="qrReader" style="width:100%;border-radius:10px;overflow:hidden;background:#f1f5f9;"></div>
        <div style="display:flex;gap:8px;margin-top:10px;">
          <button id="qrStartBtn" class="bc-btn" style="flex:1;" onclick="startQrCamera()"><i class="fas fa-camera"></i> Start Camera</button>
          <button id="qrStopBtn" class="bc-btn secondary" style="flex:1;display:none;" onclick="stopQrCamera()"><i class="fas fa-stop-circle"></i> Stop Camera</button>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;color:#94a3b8;font-size:12px;">
        <div style="flex:1;height:1px;background:#e2e8f0;"></div>or enter token manually<div style="flex:1;height:1px;background:#e2e8f0;"></div>
      </div>
      <div class="modal-field" style="display:flex;gap:8px;margin-bottom:0;">
        <input type="text" id="qrManualInput" placeholder="e.g. ABC123" maxlength="7" style="font-family:'Courier New',monospace;letter-spacing:.1em;text-transform:uppercase" oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]/g,'')" onkeydown="if(event.key==='Enter')submitQr(this.value)">
        <button class="bc-btn" style="flex:none;padding:10px 16px;" onclick="submitQr(document.getElementById('qrManualInput').value)"><i class="fas fa-check"></i></button>
      </div>
    </div>
  </div>
</div>

<!-- ── Inspection Report Modal ──────────────────────────────── -->
<div class="modal-overlay" id="inspModal">
  <div class="modal-box">
    <div class="modal-head" style="background:linear-gradient(135deg,#4a235a,#6c3483);">
      <div style="width:38px;height:38px;background:rgba(255,255,255,.15);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;"><i class="fas fa-clipboard-check"></i></div>
      <div><h3 id="inspTitle">Submit Inspection Report</h3><p>Photo, findings, final price, and a proposed working date.</p></div>
      <button class="modal-close" onclick="closeModal('inspModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="modal-result" id="inspResult"></div>
      <div class="modal-field">
        <label><i class="fas fa-camera"></i> Inspection Photo *</label>
        <input type="file" id="inspImage" accept="image/jpeg,image/png,image/webp">
        <div style="font-size:11px;color:#94a3b8;margin-top:4px;">JPG, PNG, or WEBP — up to 8MB.</div>
      </div>
      <div class="modal-field">
        <label>What did the inspection find? What will happen? *</label>
        <textarea id="inspNotes" rows="4" placeholder="Describe the site condition, scope of work, and what the seeker should expect on the working date..."></textarea>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="modal-field">
          <label>Final Price (&#8369;) *</label>
          <input type="number" id="inspPrice" min="0.01" step="0.01">
        </div>
        <div class="modal-field">
          <label>Proposed Working Date *</label>
          <input type="date" id="inspDate" min="<?= date('Y-m-d') ?>">
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn-plain" onclick="closeModal('inspModal')">Cancel</button>
      <button class="bc-btn purple" style="flex:none;padding:9px 18px;" id="inspSubmitBtn" onclick="submitInspection()"><i class="fas fa-paper-plane"></i> Send to Seeker</button>
    </div>
  </div>
</div>

<!-- ── Prepare Booking Modal (equipment/consumables — always self-assigned) ── -->
<div class="modal-overlay" id="prepModal">
  <div class="modal-box">
    <div class="modal-head" style="background:linear-gradient(135deg,#0c5460,#0891b2);">
      <div style="width:38px;height:38px;background:rgba(255,255,255,.15);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;"><i class="fas fa-people-carry-box"></i></div>
      <div><h3 id="prepModalTitle">Prepare Booking</h3><p>Assign any equipment/consumables you'll need for this job.</p></div>
      <button class="modal-close" onclick="closeModal('prepModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="modal-result" id="prepResult"></div>
      <?php if (!empty($companionOptions)): ?>
      <div class="modal-field">
        <label>Companion (optional co-staff)</label>
        <select id="prepCompanionSelect" style="width:100%;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:14px;">
          <option value="">— None —</option>
          <?php foreach ($companionOptions as $co): ?>
          <option value="<?= (int)$co['id'] ?>"><?= htmlspecialchars($co['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="modal-field">
        <label><i class="fas fa-toolbox"></i> Equipment</label>
        <?php if (empty($prepEquipment)): ?>
        <p style="color:#94a3b8;font-size:13px;margin:0;">No equipment in inventory.</p>
        <?php else: foreach ($prepEquipment as $item): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f1f5f9;">
          <div style="flex:1;font-size:13px;"><?= htmlspecialchars($item['item_name']) ?>
            <div style="color:#94a3b8;font-size:11px;">Available now: <?= (int)$item['available_now'] ?> of <?= (int)$item['quantity_available'] ?> owned <?= htmlspecialchars($item['unit'] ?? '') ?></div>
          </div>
          <input type="number" class="prep-equip-qty" min="0" max="<?= (int)$item['available_now'] ?>" value="0" data-id="<?= (int)$item['id'] ?>"
              style="width:90px;padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
        </div>
        <?php endforeach; endif; ?>
      </div>
      <div class="modal-field">
        <label><i class="fas fa-flask"></i> Consumables</label>
        <?php if (empty($prepConsumables)): ?>
        <p style="color:#94a3b8;font-size:13px;margin:0;">No consumables in inventory.</p>
        <?php else: foreach ($prepConsumables as $item): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f1f5f9;">
          <div style="flex:1;font-size:13px;"><?= htmlspecialchars($item['item_name']) ?>
            <div style="color:#94a3b8;font-size:11px;">Available: <?= (int)$item['quantity_available'] ?> <?= htmlspecialchars($item['unit'] ?? '') ?></div>
          </div>
          <input type="number" class="prep-cons-qty" min="0" max="<?= (int)$item['quantity_available'] ?>" value="0" data-id="<?= (int)$item['id'] ?>"
              style="width:90px;padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
        </div>
        <?php endforeach; endif; ?>
      </div>
      <div class="modal-field">
        <label>Operations Notes</label>
        <textarea id="prepNotes" rows="2" placeholder="Anything the provider should know about how this job is being prepared..."></textarea>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn-plain" onclick="closeModal('prepModal')">Cancel</button>
      <button class="bc-btn teal" style="flex:none;padding:9px 18px;" id="prepSubmitBtn" onclick="submitPrepare()"><i class="fas fa-check"></i> Save &amp; Set to Preparing</button>
    </div>
  </div>
</div>

<!-- ── Decline Request Modal ────────────────────────────────── -->
<div class="modal-overlay" id="declineModal">
  <div class="modal-box">
    <div class="modal-head" style="background:linear-gradient(135deg,#7f1d1d,#c0392b);">
      <div style="width:38px;height:38px;background:rgba(255,255,255,.15);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;"><i class="fas fa-times-circle"></i></div>
      <div><h3>Decline This Request?</h3><p>The seeker will be notified, with your reason if you give one.</p></div>
      <button class="modal-close" onclick="closeModal('declineModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="modal-result" id="declineResult"></div>
      <div class="modal-field">
        <label>Reason (optional)</label>
        <textarea id="declineReason" rows="3" placeholder="e.g. Outside service area, schedule conflict..."></textarea>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn-plain" onclick="closeModal('declineModal')">Back</button>
      <button class="bc-btn" style="flex:none;padding:9px 18px;background:#c0392b;" id="declineSubmitBtn" onclick="submitDecline()"><i class="fas fa-times-circle"></i> Confirm Decline</button>
    </div>
  </div>
</div>

<!-- ── View Details Modal (read-only mirror of the provider admin panel) ── -->
<div class="modal-overlay" id="viewModal">
  <div class="modal-box vw-box">
    <div class="modal-head" style="background:linear-gradient(135deg,#1a2744,#2d3561);">
      <div style="width:38px;height:38px;background:rgba(255,255,255,.15);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;"><i class="fas fa-eye"></i></div>
      <div style="min-width:0;flex:1;">
        <h3 id="vwRequestTitle">Booking Details</h3>
        <p id="vwSubmitted">-</p>
      </div>
      <button class="modal-close" onclick="closeModal('viewModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="vwBody">
      <div class="vw-section">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <span class="vw-badge" id="vwStatusBadge">-</span>
          <span class="vw-badge" id="vwEmergencyBadge" style="display:none;background:#fee2e2;color:#991b1b;"><i class="fas fa-bolt"></i> Emergency</span>
        </div>
      </div>

      <div class="vw-section">
        <div class="vw-section-title"><i class="fas fa-user"></i> Customer</div>
        <div class="vw-row"><span class="k">Name</span><span class="v" id="vwName">-</span></div>
        <div class="vw-row"><span class="k">Contact</span><span class="v" id="vwContact">-</span></div>
        <div class="vw-row"><span class="k">Date &amp; Time</span><span class="v" id="vwDatetime">-</span></div>
        <div class="vw-row"><span class="k">Address</span><span class="v" id="vwAddress">-</span></div>
      </div>

      <div class="vw-section" id="vwNotesSection" style="display:none;">
        <div class="vw-section-title"><i class="fas fa-note-sticky"></i> Seeker Notes</div>
        <div style="font-size:13px;color:#475569;" id="vwNotes"></div>
      </div>

      <div class="vw-section">
        <div class="vw-section-title"><i class="fas fa-credit-card"></i> Payment</div>
        <div class="vw-row"><span class="k">Total</span><span class="v" id="vwTotal">-</span></div>
        <div class="vw-row"><span class="k">Paid</span><span class="v" id="vwPaid">-</span></div>
        <div class="vw-row"><span class="k">Remaining</span><span class="v" id="vwRemaining">-</span></div>
        <div class="vw-row"><span class="k">Method / Status</span><span class="v" id="vwPayStatus">-</span></div>
        <div id="vwReceipts"></div>
      </div>

      <div class="vw-section" id="vwCodesSection">
        <div class="vw-section-title"><i class="fas fa-shield-alt"></i> Control Numbers &amp; Verification</div>
        <div class="vw-row"><span class="k">Seeker's Code</span><span class="v" id="vwSeekerCode">-</span></div>
        <div class="vw-row"><span class="k">Your Code (share with seeker)</span><span class="v"><span class="vw-code" id="vwProviderCode">-</span></span></div>
        <div style="display:flex;gap:16px;margin-top:8px;flex-wrap:wrap;">
          <div class="vw-check"><i class="fas fa-circle-check" id="vwSeekerVerIcon"></i> Seeker verified</div>
          <div class="vw-check"><i class="fas fa-circle-check" id="vwProviderVerIcon"></i> You verified</div>
          <div class="vw-check"><i class="fas fa-circle-check" id="vwDualVerIcon"></i> Both verified</div>
        </div>
      </div>
      <div class="vw-section" id="vwCodesPendingSection" style="display:none;">
        <div class="vw-section-title"><i class="fas fa-shield-alt"></i> Control Numbers &amp; Verification</div>
        <div style="font-size:12.5px;color:#94a3b8;">Available once the seeker completes payment.</div>
      </div>

      <div class="vw-section" id="vwInspectionSection" style="display:none;">
        <div class="vw-section-title"><i class="fas fa-magnifying-glass"></i> Inspection &amp; Working Date</div>
        <div class="vw-row"><span class="k">Agreed?</span><span class="v" id="vwInspectionAgreed">-</span></div>
        <div class="vw-row"><span class="k">Round</span><span class="v" id="vwInspectionRound">-</span></div>
        <div class="vw-row" id="vwInspectionDateRow" style="display:none;"><span class="k">Inspection Date</span><span class="v" id="vwInspectionDate">-</span></div>
        <div class="vw-row" id="vwWorkingDateRow" style="display:none;"><span class="k">Working Date</span><span class="v" id="vwWorkingDate">-</span></div>
        <div class="vw-row" id="vwProposedRow" style="display:none;"><span class="k">Proposed Price / Date</span><span class="v" id="vwProposed">-</span></div>
        <div id="vwInspectionNotesWrap" style="display:none;margin-top:6px;">
          <div class="k" style="font-size:12px;margin-bottom:3px;">Report Notes</div>
          <div style="font-size:13px;color:#475569;" id="vwInspectionNotes"></div>
        </div>
        <div id="vwInspectionChangeWrap" style="display:none;margin-top:6px;">
          <div class="k" style="font-size:12px;margin-bottom:3px;color:#9a3412;">Seeker Requested Changes</div>
          <div style="font-size:13px;color:#7c2d12;" id="vwInspectionChangeNotes"></div>
        </div>
      </div>

      <div class="vw-section" id="vwRescheduleSection" style="display:none;">
        <div class="vw-section-title"><i class="fas fa-calendar-day"></i> Reschedule Proposal</div>
        <div class="vw-row"><span class="k">Proposed</span><span class="v" id="vwRescheduleSummary">-</span></div>
        <div id="vwRescheduleReasonWrap" style="display:none;margin-top:6px;">
          <div style="font-size:13px;color:#475569;" id="vwRescheduleReason"></div>
        </div>
      </div>

      <div class="vw-section" id="vwOpsNotesSection" style="display:none;">
        <div class="vw-section-title"><i class="fas fa-toolbox"></i> Operations Notes (from Prepare Booking)</div>
        <div style="font-size:13px;color:#475569;" id="vwOpsNotes"></div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn-plain" onclick="closeModal('viewModal')">Close</button>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>
<script>
let _activeAvailId = null;
let _qrScanner = null;

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    if (id === 'qrModal') stopQrCamera();
}

function showResult(elId, kind, msg) {
    const el = document.getElementById(elId);
    el.className = 'modal-result ' + kind;
    el.innerHTML = msg;
}

// ── Enter Seeker Code ────────────────────────────────────────
function openCodeModal(availId) {
    _activeAvailId = availId;
    document.getElementById('codeInput').value = '';
    document.getElementById('codeResult').className = 'modal-result';
    openModal('codeModal');
}
function submitCode() {
    const code = document.getElementById('codeInput').value.trim();
    if (!code) { showResult('codeResult', 'err', 'Please enter the seeker\'s code.'); return; }
    showResult('codeResult', 'info', '<i class="fas fa-spinner fa-spin"></i> Verifying…');
    const fd = new FormData();
    fd.append('action', 'verify_control_number');
    fd.append('avail_id', _activeAvailId);
    fd.append('control_number_input', code);
    fetch('my-services.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.result === 'ok') {
                showResult('codeResult', 'ok', data.message);
                setTimeout(() => location.reload(), 1600);
            } else if (data.result === 'provider_done') {
                showResult('codeResult', 'info', data.message);
            } else if (data.result === 'already_done') {
                showResult('codeResult', 'info', data.message);
                setTimeout(() => location.reload(), 1600);
            } else {
                showResult('codeResult', 'err', data.error || 'Verification failed.');
            }
        })
        .catch(() => showResult('codeResult', 'err', 'Network error. Please try again.'));
}

// ── Scan Seeker QR ───────────────────────────────────────────
function openQrModal(availId) {
    _activeAvailId = availId;
    document.getElementById('qrManualInput').value = '';
    document.getElementById('qrResult').className = 'modal-result';
    openModal('qrModal');
}
function startQrCamera() {
    const reader = document.getElementById('qrReader');
    reader.innerHTML = '';
    _qrScanner = new Html5Qrcode('qrReader');
    _qrScanner.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: 220 },
        decoded => { stopQrCamera(); submitQr(decoded); },
        () => {}
    ).then(() => {
        document.getElementById('qrStartBtn').style.display = 'none';
        document.getElementById('qrStopBtn').style.display = '';
    }).catch(err => {
        _qrScanner = null;
        const s = String(err);
        let msg = 'Camera unavailable. Use the manual token entry below.';
        if (s.includes('NotFound') || s.includes('not found')) msg = 'No camera detected on this device. Use the manual token entry below.';
        else if (s.includes('NotAllowed') || s.includes('Permission')) msg = 'Camera permission denied. Allow camera access or use the manual token entry below.';
        showResult('qrResult', 'err', msg);
    });
}
function stopQrCamera() {
    if (_qrScanner) {
        _qrScanner.stop().catch(() => {}).finally(() => {
            _qrScanner = null;
            document.getElementById('qrStartBtn').style.display = '';
            document.getElementById('qrStopBtn').style.display = 'none';
        });
    }
}
function submitQr(token) {
    token = (token || '').trim().toUpperCase();
    if (!token) { showResult('qrResult', 'err', 'Please enter or scan a token first.'); return; }
    showResult('qrResult', 'info', '<i class="fas fa-spinner fa-spin"></i> Validating…');
    const fd = new FormData();
    fd.append('action', 'scan_qr');
    fd.append('avail_id', _activeAvailId);
    fd.append('token', token);
    fetch('my-services.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            showResult('qrResult', data.success ? 'ok' : 'err', data.message);
            if (data.success) setTimeout(() => location.reload(), 1600);
        })
        .catch(() => showResult('qrResult', 'err', 'Network error. Please try again.'));
}

// ── View Details ──────────────────────────────────────────────
function openViewModal(availId) {
    const body = document.getElementById('vwBody');
    body.style.opacity = '0.5';
    openModal('viewModal');
    document.getElementById('vwRequestTitle').textContent = 'Request #' + availId;

    const fd = new FormData();
    fd.append('action', 'view_details');
    fd.append('avail_id', availId);
    fetch('my-services.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            body.style.opacity = '1';
            if (!data.ok) { alert(data.error || 'Could not load booking details.'); closeModal('viewModal'); return; }
            renderViewModal(data);
        })
        .catch(() => {
            body.style.opacity = '1';
            alert('Network error. Please try again.');
            closeModal('viewModal');
        });
}

function renderViewModal(d) {
    document.getElementById('vwRequestTitle').textContent = d.service + ' — Request #' + d.id;
    document.getElementById('vwSubmitted').textContent = 'Submitted ' + d.submitted;

    const statusBadge = document.getElementById('vwStatusBadge');
    statusBadge.textContent = d.statusLabel;
    statusBadge.style.background = d.statusBg;
    statusBadge.style.color = d.statusColor;
    document.getElementById('vwEmergencyBadge').style.display = d.isEmergency ? 'inline-block' : 'none';

    document.getElementById('vwName').textContent = d.fullName;
    document.getElementById('vwContact').textContent = d.contact;
    document.getElementById('vwDatetime').textContent = d.date + ' at ' + d.time;
    document.getElementById('vwAddress').textContent = d.address;

    document.getElementById('vwNotesSection').style.display = d.notes ? '' : 'none';
    document.getElementById('vwNotes').textContent = d.notes || '';

    document.getElementById('vwTotal').textContent = '₱' + d.totalAmount;
    document.getElementById('vwPaid').textContent = '₱' + d.paidAmount;
    document.getElementById('vwRemaining').textContent = '₱' + d.remainingAmount;
    document.getElementById('vwPayStatus').textContent = (d.paymentMethod || '-') + ' / ' + (d.paymentStatus || '-');
    const receiptsEl = document.getElementById('vwReceipts');
    receiptsEl.innerHTML = (d.receipts || []).map(r =>
        `<div class="vw-receipt"><span>${escapeHtml(r.type)} · ${escapeHtml(r.number)}</span><span>₱${escapeHtml(r.amount)}</span></div>`
    ).join('');

    document.getElementById('vwCodesSection').style.display = d.isPaidEnough ? '' : 'none';
    document.getElementById('vwCodesPendingSection').style.display = d.isPaidEnough ? 'none' : '';
    document.getElementById('vwSeekerCode').innerHTML = d.seekerCodeSet
        ? '<span class="vw-code" style="letter-spacing:.15em;opacity:.5;">••••••••••</span>'
        : '<span style="color:#94a3b8;">Not generated yet</span>';
    document.getElementById('vwProviderCode').textContent = d.providerCode || '—';
    setCheckIcon('vwSeekerVerIcon', d.seekerVerified);
    setCheckIcon('vwProviderVerIcon', d.providerVerified);
    setCheckIcon('vwDualVerIcon', d.dualVerified);

    const showInsp = d.requiresInspection;
    document.getElementById('vwInspectionSection').style.display = showInsp ? '' : 'none';
    if (showInsp) {
        document.getElementById('vwInspectionAgreed').textContent = d.inspectionAgreed ? 'Yes' : 'Not yet';
        document.getElementById('vwInspectionRound').textContent = d.inspectionRound;
        const hasInspDate = !!d.inspectionDate;
        document.getElementById('vwInspectionDateRow').style.display = hasInspDate ? '' : 'none';
        document.getElementById('vwInspectionDate').textContent = d.inspectionDate || '';
        const hasWorkDate = !!d.workingDate;
        document.getElementById('vwWorkingDateRow').style.display = hasWorkDate ? '' : 'none';
        document.getElementById('vwWorkingDate').textContent = d.workingDate || '';
        const hasProposed = !!(d.inspectionProposedPrice && Number(d.inspectionProposedPrice.replace(/,/g,'')) > 0);
        document.getElementById('vwProposedRow').style.display = hasProposed ? '' : 'none';
        document.getElementById('vwProposed').textContent = hasProposed ? ('₱' + d.inspectionProposedPrice + ' / ' + (d.inspectionProposedWorkingDate || '-')) : '';
        document.getElementById('vwInspectionNotesWrap').style.display = d.inspectionReportNotes ? '' : 'none';
        document.getElementById('vwInspectionNotes').textContent = d.inspectionReportNotes || '';
        document.getElementById('vwInspectionChangeWrap').style.display = d.inspectionChangeNotes ? '' : 'none';
        document.getElementById('vwInspectionChangeNotes').textContent = d.inspectionChangeNotes || '';
    }

    const showResched = d.isReschedulePending || d.isRescheduleRejected;
    document.getElementById('vwRescheduleSection').style.display = showResched ? '' : 'none';
    if (showResched) {
        document.getElementById('vwRescheduleSummary').textContent = d.rescheduleSummary + (d.isRescheduleRejected ? ' (rejected by seeker)' : ' (awaiting seeker response)');
        document.getElementById('vwRescheduleReasonWrap').style.display = d.rescheduleReason ? '' : 'none';
        document.getElementById('vwRescheduleReason').textContent = d.rescheduleReason || '';
    }

    document.getElementById('vwOpsNotesSection').style.display = d.operationsNotes ? '' : 'none';
    document.getElementById('vwOpsNotes').textContent = d.operationsNotes || '';
}

function setCheckIcon(elId, isTrue) {
    const el = document.getElementById(elId);
    el.className = 'fas ' + (isTrue ? 'fa-circle-check yes' : 'fa-circle-xmark no');
}

// ── Accept / Decline a pending request ───────────────────────
function acceptRequest(availId, btn) {
    if (!confirm('Accept this request? Control numbers will be generated and the seeker notified.')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Accepting…';
    const fd = new FormData();
    fd.append('action', 'accept_request');
    fd.append('avail_id', availId);
    fetch('my-services.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                location.reload();
            } else {
                alert(data.error || 'Could not accept this request.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check"></i> Accept';
            }
        })
        .catch(() => {
            alert('Network error. Please try again.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Accept';
        });
}
function openDeclineModal(availId) {
    _activeAvailId = availId;
    document.getElementById('declineReason').value = '';
    document.getElementById('declineResult').className = 'modal-result';
    openModal('declineModal');
}
function submitDecline() {
    const btn = document.getElementById('declineSubmitBtn');
    btn.disabled = true;
    showResult('declineResult', 'info', '<i class="fas fa-spinner fa-spin"></i> Declining…');
    const fd = new FormData();
    fd.append('action', 'decline_request');
    fd.append('avail_id', _activeAvailId);
    fd.append('reason', document.getElementById('declineReason').value.trim());
    fetch('my-services.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (data.ok) {
                showResult('declineResult', 'ok', 'Request declined.');
                setTimeout(() => location.reload(), 1200);
            } else {
                showResult('declineResult', 'err', data.error || 'Could not decline this request.');
            }
        })
        .catch(() => {
            btn.disabled = false;
            showResult('declineResult', 'err', 'Network error. Please try again.');
        });
}

// ── Prepare Booking (equipment/consumables — always self-assigned) ──────
// isEdit=true (booking already 'preparing', opened via "Edit Equipment"):
// fetches the booking's current assignment via the same view_details
// endpoint the View modal uses, and prefills + raises each input's max by
// what's already held — mirrors provider/service-requests.php's identical
// approach; the server independently re-verifies via
// prepareAvailedBooking()'s $excludeAvailedId exclusion either way.
function openPrepareModal(availId, isEdit) {
    _activeAvailId = availId;
    document.getElementById('prepModalTitle').textContent = isEdit ? 'Edit Equipment' : 'Prepare Booking';
    document.getElementById('prepSubmitBtn').innerHTML = isEdit
        ? '<i class="fas fa-check"></i> Save Changes'
        : '<i class="fas fa-check"></i> Save &amp; Set to Preparing';
    document.querySelectorAll('.prep-equip-qty, .prep-cons-qty').forEach(el => el.value = 0);
    document.getElementById('prepNotes').value = '';
    const compSel = document.getElementById('prepCompanionSelect');
    if (compSel) { compSel.value = ''; }
    document.getElementById('prepResult').className = 'modal-result';

    if (!isEdit) { openModal('prepModal'); return; }

    const fd = new FormData();
    fd.append('action', 'view_details');
    fd.append('avail_id', availId);
    fetch('my-services.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) { alert(data.error || 'Could not load booking.'); return; }
            if (compSel && data.companionEmployeeId) { compSel.value = String(data.companionEmployeeId); }
            document.getElementById('prepNotes').value = data.operationsNotes || '';
            const heldById = {};
            (data.assignedEquipment || []).forEach(e => { heldById[String(e.id)] = e.qty; });
            (data.assignedConsumables || []).forEach(e => { heldById[String(e.id)] = e.qty; });
            document.querySelectorAll('.prep-equip-qty, .prep-cons-qty').forEach(el => {
                const held = heldById[el.dataset.id] || 0;
                if (held > 0) { el.max = String(parseInt(el.max, 10) + held); }
                el.value = held;
            });
            openModal('prepModal');
        })
        .catch(() => alert('Network error loading booking.'));
}
function submitPrepare() {
    // No staff/companion-collision check needed here — primary is always
    // self, and the companion dropdown already excludes self server-side.
    const compSel = document.getElementById('prepCompanionSelect');
    const eq = [], cn = [];
    document.querySelectorAll('.prep-equip-qty').forEach(el => { if (+el.value > 0) eq.push({inventory_item_id: +el.dataset.id, quantity_needed: +el.value}); });
    document.querySelectorAll('.prep-cons-qty').forEach(el => { if (+el.value > 0) cn.push({inventory_item_id: +el.dataset.id, quantity_needed: +el.value}); });

    const btn = document.getElementById('prepSubmitBtn');
    btn.disabled = true;
    showResult('prepResult', 'info', '<i class="fas fa-spinner fa-spin"></i> Saving…');

    const fd = new FormData();
    fd.append('action', 'prepare_booking');
    fd.append('avail_id', _activeAvailId);
    fd.append('equipment_json', JSON.stringify(eq));
    fd.append('consumables_json', JSON.stringify(cn));
    fd.append('operations_notes', document.getElementById('prepNotes').value.trim());
    if (compSel) { fd.append('companion_id', compSel.value); }

    fetch('my-services.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (data.type === 'preparing') {
                showResult('prepResult', 'ok', data.message);
                setTimeout(() => location.reload(), 1600);
            } else {
                showResult('prepResult', 'err', data.message || 'Could not prepare this booking.');
            }
        })
        .catch(() => {
            btn.disabled = false;
            showResult('prepResult', 'err', 'Network error. Please try again.');
        });
}

// ── Mark Job Done ────────────────────────────────────────────
function markJobDone(availId, btn) {
    if (!confirm('Mark this job as done on-site? The seeker will be asked to confirm or pay any remaining balance.')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating…';
    const fd = new FormData();
    fd.append('action', 'mark_job_done');
    fd.append('avail_id', availId);
    fetch('my-services.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.type === 'error') {
                alert(data.message || 'Could not update status.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-flag-checkered"></i> Mark Job Done';
            } else {
                location.reload();
            }
        })
        .catch(() => {
            alert('Network error. Please try again.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-flag-checkered"></i> Mark Job Done';
        });
}

// ── Inspection Report ────────────────────────────────────────
function openInspectionModal(availId, verb) {
    _activeAvailId = availId;
    document.getElementById('inspTitle').textContent = verb + ' Inspection Report';
    document.getElementById('inspImage').value = '';
    document.getElementById('inspNotes').value = '';
    document.getElementById('inspPrice').value = '';
    document.getElementById('inspDate').value = '';
    document.getElementById('inspResult').className = 'modal-result';
    openModal('inspModal');
}
function submitInspection() {
    const img = document.getElementById('inspImage').files[0];
    const notes = document.getElementById('inspNotes').value.trim();
    const price = document.getElementById('inspPrice').value;
    const wdate = document.getElementById('inspDate').value;
    if (!img) { showResult('inspResult', 'err', 'Please attach a photo from the inspection.'); return; }
    if (!notes) { showResult('inspResult', 'err', 'Please describe what the inspection found.'); return; }
    if (!price || Number(price) <= 0) { showResult('inspResult', 'err', 'Please enter a valid final price.'); return; }
    if (!wdate) { showResult('inspResult', 'err', 'Please choose a proposed working date.'); return; }

    const btn = document.getElementById('inspSubmitBtn');
    btn.disabled = true;
    showResult('inspResult', 'info', '<i class="fas fa-spinner fa-spin"></i> Submitting…');

    const fd = new FormData();
    fd.append('action', 'submit_inspection_report');
    fd.append('avail_id', _activeAvailId);
    fd.append('inspection_image', img);
    fd.append('inspection_notes', notes);
    fd.append('proposed_price', price);
    fd.append('proposed_working_date', wdate);

    fetch('my-services.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (data.type === 'accepted') {
                showResult('inspResult', 'ok', data.message);
                setTimeout(() => location.reload(), 1600);
            } else {
                showResult('inspResult', 'err', data.message || 'Could not submit the report.');
            }
        })
        .catch(() => {
            btn.disabled = false;
            showResult('inspResult', 'err', 'Network error. Please try again.');
        });
}
</script>
</body></html>
