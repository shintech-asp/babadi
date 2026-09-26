<?php
chdir(dirname(__DIR__));
// services.php - Provider Services Management
session_start();
require_once 'config/config.php';
require_once 'config/database.php';

$loginUrl = appUrl('login.php');
$providerSetupUrl = appUrl('provider-setup.php');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'provider') {
    header('Location: ' . $loginUrl);
    exit();
}

$database = new Database();
$db = $database->getConnection();
$provider_id = $_SESSION['provider_id'] ?? null;

if (!$provider_id) {
    $stmtP = $db->prepare('SELECT id, business_registration_file, license_file, address, city, state FROM providers WHERE user_id = :uid');
    $stmtP->bindParam(':uid', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmtP->execute();
    $prov = $stmtP->fetch(PDO::FETCH_ASSOC);
    if ($prov) {
        $provider_id = (int)$prov['id'];
        $_SESSION['provider_id'] = $provider_id;
        if (empty($prov['business_registration_file']) || empty($prov['license_file']) ||
            empty($prov['address']) || empty($prov['city']) ||
            strcasecmp(trim((string)($prov['state'] ?? '')), 'Cavite') !== 0) {
            header('Location: ' . $providerSetupUrl);
            exit();
        }
    } else {
        header('Location: ' . $loginUrl);
        exit();
    }
}

$stmtProv = $db->prepare("SELECT p.*, u.email, u.first_name, u.last_name, u.profile_image FROM providers p JOIN users u ON p.user_id = u.id WHERE p.id = :pid");
$stmtProv->bindParam(':pid', $provider_id, PDO::PARAM_INT);
$stmtProv->execute();
$provider = $stmtProv->fetch(PDO::FETCH_ASSOC);

function countServiceLockingBookings(PDO $db, int $providerId, int $providerUserId, int $serviceId, string $serviceName): int {
    $count = 0;
    $providerUserId = $providerUserId > 0 ? $providerUserId : -1;
    $serviceName = trim($serviceName);

    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*)
             FROM availed_services
             WHERE provider_id IN (:pid, :puid)
               AND status NOT IN ('completed', 'cancelled', 'rejected')
               AND (
                    service_id = :sid
                    OR ((service_id IS NULL OR service_id = 0) AND TRIM(COALESCE(service_name, '')) = :sname)
               )"
        );
        $stmt->execute([
            ':pid' => $providerId,
            ':puid' => $providerUserId,
            ':sid' => $serviceId,
            ':sname' => $serviceName,
        ]);
        $count += (int)$stmt->fetchColumn();
    } catch (Exception $e) {}

    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*)
             FROM service_requests
             WHERE provider_id IN (:pid, :puid)
               AND status NOT IN ('completed', 'cancelled', 'rejected')"
        );
        $stmt->execute([
            ':pid' => $providerId,
            ':puid' => $providerUserId,
        ]);
        $count += (int)$stmt->fetchColumn();
    } catch (Exception $e) {}

    return $count;
}

// ── Ensure pesticide + contract columns exist ──
try {
    $db->exec("ALTER TABLE services ADD COLUMN IF NOT EXISTS pesticide_name VARCHAR(255) DEFAULT NULL");
    $db->exec("ALTER TABLE services ADD COLUMN IF NOT EXISTS pesticide_brand VARCHAR(255) DEFAULT NULL");
    $db->exec("ALTER TABLE services ADD COLUMN IF NOT EXISTS pesticide_type VARCHAR(100) DEFAULT NULL");
    $db->exec("ALTER TABLE services ADD COLUMN IF NOT EXISTS pesticide_notes TEXT DEFAULT NULL");
    $db->exec("ALTER TABLE services ADD COLUMN IF NOT EXISTS contract_text TEXT DEFAULT NULL");
    $db->exec("ALTER TABLE services ADD COLUMN IF NOT EXISTS contract_signature TEXT DEFAULT NULL");
    $db->exec("ALTER TABLE services ADD COLUMN IF NOT EXISTS contract_signed_at DATETIME DEFAULT NULL");
    $db->exec("ALTER TABLE services ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL");
} catch(Exception $e) {}

// Field technicians eligible to be the default handler for a service:
// any active employee flagged as 'field' staff type. No portal login
// (provider_staff) is required — field technicians log in directly via
// the employee self-service portal (see auth/login.php's employees tier).
$fieldStaffOptions = [];
try {
    $fsStmt = $db->prepare(
        "SELECT id, CONCAT(first_name, ' ', last_name) AS full_name
         FROM employees
         WHERE provider_id = :pid AND status = 'active' AND staff_type = 'field'
         ORDER BY first_name"
    );
    $fsStmt->bindParam(':pid', $provider_id, PDO::PARAM_INT);
    $fsStmt->execute();
    $fieldStaffOptions = $fsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $ex) { /* employees table may not have matching rows yet */ }

// Equipment a service can declare it needs (from the Finance inventory
// register, provider-portal/inventory.php) — pre-fills the equipment picker
// in this file's "Prepare Booking" flow. Consumables stay a per-booking pick
// there since quantity used varies per job.
$equipmentOptions = [];
try {
    $eqStmt = $db->prepare(
        "SELECT id, item_name, unit_price FROM inventory_items WHERE provider_id = :pid AND item_type = 'equipment' AND is_archived = 0 ORDER BY item_name"
    );
    $eqStmt->bindParam(':pid', $provider_id, PDO::PARAM_INT);
    $eqStmt->execute();
    $equipmentOptions = $eqStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $ex) { /* inventory_items may not have matching rows yet */ }

// Real category picker sourced from service_categories — replaces the old
// hardcoded 5-option free-text <select> (see CLAUDE.md's "Pricing Model"
// Recent Work Log entry: category was collected and validated but never
// actually written to the DB before that fix; this now writes category_id).
$serviceCategoryOptions = [];
try {
    $scStmt = $db->query('SELECT id, name FROM service_categories ORDER BY name');
    $serviceCategoryOptions = $scStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $ex) { /* service_categories always exists, defensive only */ }
$validCategoryIds = array_map('intval', array_column($serviceCategoryOptions, 'id'));

$add_success = '';
$add_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_service'])) {
    $service_name      = trim($_POST['service_name'] ?? '');
    $description       = trim($_POST['description'] ?? '');
    $price             = trim($_POST['price'] ?? '');
    $category_id       = (int)($_POST['category_id'] ?? 0);
    $pricing_type      = trim($_POST['pricing_type'] ?? '');
    $status            = trim($_POST['status'] ?? 'active');
    $eco_friendly      = isset($_POST['eco_friendly']) ? 1 : 0;
    $emergency         = isset($_POST['emergency']) ? 1 : 0;
    if (!in_array($pricing_type, ['fixed', 'custom'], true)) {
        $pricing_type = 'fixed';
    }
    // Custom Quote can't be charged upfront — force inspection on
    // regardless of what the checkbox posted, so the client-side lock
    // (onPricingTypeChange() in the page's JS) can't be bypassed.
    $requires_inspection = ($pricing_type !== 'fixed') ? 1 : (isset($_POST['requires_inspection']) ? 1 : 0);
    $pesticide_name    = trim($_POST['pesticide_name'] ?? '');
    $pesticide_brand   = trim($_POST['pesticide_brand'] ?? '');
    $pesticide_type    = trim($_POST['pesticide_type'] ?? '');
    $pesticide_notes   = trim($_POST['pesticide_notes'] ?? '');
    $assigned_staff_id = (int)($_POST['assigned_staff_id'] ?? 0);
    // Never trust the posted staff id directly — only accept it if it's
    // actually one of this provider's eligible field technicians.
    if ($assigned_staff_id > 0 && !in_array($assigned_staff_id, array_map('intval', array_column($fieldStaffOptions, 'id')), true)) {
        $assigned_staff_id = 0;
    }
    // Equipment this service uses — never trust posted ids directly, only
    // accept ones that are actually in this provider's equipment inventory.
    $validEquipmentIds = array_map('intval', array_column($equipmentOptions, 'id'));
    $selectedEquipmentIds = array_values(array_intersect(
        array_map('intval', $_POST['equipment_ids'] ?? []),
        $validEquipmentIds
    ));

    $errors = [];
    if ($service_name === '')                               { $errors[] = 'Service name is required.'; }
    if ($price === '' || !is_numeric($price) || $price < 0) { $errors[] = 'Valid price is required.'; }
    if ($category_id <= 0 || !in_array($category_id, $validCategoryIds, true)) { $errors[] = 'Category is required.'; }

    // Contract / signature
    $contract_text      = trim($_POST['contract_text'] ?? '');
    $contract_signature = trim($_POST['contract_signature'] ?? ''); // base64 PNG data URL

    if ($contract_signature === '') { $errors[] = 'Please sign the service contract before publishing.'; }

    if (empty($errors)) {
        try {
            $stmtIns = $db->prepare("INSERT INTO services (provider_id, service_name, description, price, category_id, pricing_type, requires_inspection, pesticide_name, pesticide_brand, pesticide_type, pesticide_notes, contract_text, contract_signature, contract_signed_at, assigned_staff_id, created_at) VALUES (:provider_id, :service_name, :description, :price, :category_id, :pricing_type, :requires_inspection, :pesticide_name, :pesticide_brand, :pesticide_type, :pesticide_notes, :contract_text, :contract_signature, NOW(), :assigned_staff_id, NOW())");
            $stmtIns->bindParam(':provider_id',         $provider_id, PDO::PARAM_INT);
            $stmtIns->bindParam(':service_name',        $service_name);
            $stmtIns->bindParam(':description',         $description);
            $stmtIns->bindParam(':price',               $price);
            $stmtIns->bindParam(':category_id',         $category_id, PDO::PARAM_INT);
            $stmtIns->bindParam(':pricing_type',        $pricing_type);
            $stmtIns->bindParam(':requires_inspection', $requires_inspection, PDO::PARAM_INT);
            $stmtIns->bindParam(':pesticide_name',      $pesticide_name);
            $stmtIns->bindParam(':pesticide_brand',     $pesticide_brand);
            $stmtIns->bindParam(':pesticide_type',      $pesticide_type);
            $stmtIns->bindParam(':pesticide_notes',     $pesticide_notes);
            $stmtIns->bindParam(':contract_text',       $contract_text);
            $stmtIns->bindParam(':contract_signature',  $contract_signature);
            if ($assigned_staff_id > 0) {
                $stmtIns->bindParam(':assigned_staff_id', $assigned_staff_id, PDO::PARAM_INT);
            } else {
                $stmtIns->bindValue(':assigned_staff_id', null, PDO::PARAM_NULL);
            }
            $stmtIns->execute();
            $newServiceId = (int)$db->lastInsertId();
            if ($newServiceId > 0 && !empty($selectedEquipmentIds)) {
                $eqLinkStmt = $db->prepare("INSERT IGNORE INTO service_equipment_items (service_id, inventory_item_id, quantity_needed) VALUES (:sid, :iid, :qty)");
                foreach ($selectedEquipmentIds as $eqId) {
                    $qty = max(1, (int)($_POST['equipment_qty'][$eqId] ?? 1));
                    $eqLinkStmt->execute([':sid' => $newServiceId, ':iid' => $eqId, ':qty' => $qty]);
                }
            }
            $add_success = 'Service added successfully! Contract signed and saved.';
        } catch (PDOException $ex) {
            $add_error = 'Failed to add service. Please try again.';
        }
    } else {
        $add_error = implode(' ', $errors);
    }
}

$delete_success = '';
$delete_error   = '';
if (isset($_GET['delete']) && ctype_digit($_GET['delete'])) {
    $sid = (int)$_GET['delete'];
    try {
        // Soft-delete service so related seeker requests/bookings remain intact.
        $stmtDel = $db->prepare("UPDATE services SET status='inactive', deleted_at=NOW(), updated_at=NOW() WHERE id = :id AND provider_id = :pid AND deleted_at IS NULL");
        $stmtDel->bindParam(':id',  $sid,         PDO::PARAM_INT);
        $stmtDel->bindParam(':pid', $provider_id, PDO::PARAM_INT);
        $stmtDel->execute();
        if ($stmtDel->rowCount() > 0) { $delete_success = 'Service removed from listings. Existing seeker requests were kept.'; }
        else                           { $delete_error   = 'Unable to remove the service.'; }
    } catch (PDOException $ex) {
        $delete_error = 'Database error while deleting service.';
    }
}

// ── Handle Edit Service ──
$edit_success = '';
$edit_error   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_service'])) {
    $edit_id           = (int)($_POST['edit_id'] ?? 0);
    $e_name            = trim($_POST['edit_service_name'] ?? '');
    $e_desc            = trim($_POST['edit_description'] ?? '');
    $e_price           = trim($_POST['edit_price'] ?? '');
    $e_category_id     = (int)($_POST['edit_category_id'] ?? 0);
    $e_pricing_type    = trim($_POST['edit_pricing_type'] ?? '');
    $e_status          = trim($_POST['edit_status'] ?? 'active');
    $e_pest_name       = trim($_POST['edit_pesticide_name'] ?? '');
    $e_pest_brand      = trim($_POST['edit_pesticide_brand'] ?? '');
    $e_pest_type       = trim($_POST['edit_pesticide_type'] ?? '');
    $e_pest_notes      = trim($_POST['edit_pesticide_notes'] ?? '');
    if (!in_array($e_pricing_type, ['fixed', 'custom'], true)) {
        $e_pricing_type = 'fixed';
    }
    // Custom Quote can't be charged upfront — force inspection on
    // regardless of what the checkbox posted, same rule as add_service.
    $e_requires_inspection = ($e_pricing_type !== 'fixed') ? 1 : (isset($_POST['edit_requires_inspection']) ? 1 : 0);
    $e_assigned_staff_id = (int)($_POST['edit_assigned_staff_id'] ?? 0);
    if ($e_assigned_staff_id > 0 && !in_array($e_assigned_staff_id, array_map('intval', array_column($fieldStaffOptions, 'id')), true)) {
        $e_assigned_staff_id = 0;
    }
    $e_validEquipmentIds = array_map('intval', array_column($equipmentOptions, 'id'));
    $e_selectedEquipmentIds = array_values(array_intersect(
        array_map('intval', $_POST['edit_equipment_ids'] ?? []),
        $e_validEquipmentIds
    ));

    $e_errors = [];
    if ($e_name === '')                                { $e_errors[] = 'Service name is required.'; }
    if ($e_price === '' || !is_numeric($e_price) || $e_price < 0) { $e_errors[] = 'Valid price is required.'; }
    if ($e_category_id <= 0 || !in_array($e_category_id, $validCategoryIds, true)) { $e_errors[] = 'Category is required.'; }
    if ($edit_id <= 0)                                 { $e_errors[] = 'Invalid service.'; }

    if (empty($e_errors)) {
        // Ownership check first — rowCount() on the UPDATE below isn't a
        // reliable "found" signal (PDO/MySQL only counts rows whose values
        // actually changed, so an equipment-only edit with identical other
        // fields would report 0 even for a legitimate service), and without
        // this check the equipment DELETE below would run unconditionally
        // regardless of whether edit_id even belongs to this provider.
        $ownerCheckStmt = $db->prepare("SELECT id FROM services WHERE id=:id AND provider_id=:pid");
        $ownerCheckStmt->execute([':id' => $edit_id, ':pid' => $provider_id]);
        $ownsService = (bool)$ownerCheckStmt->fetch(PDO::FETCH_ASSOC);

        if (!$ownsService) {
            $edit_error = 'Service not found.';
        } else {
        try {
            $stmtEdit = $db->prepare("UPDATE services SET service_name=:name, description=:desc, price=:price, category_id=:category_id, pricing_type=:pricing_type, requires_inspection=:requires_inspection, pesticide_name=:pname, pesticide_brand=:pbrand, pesticide_type=:ptype, pesticide_notes=:pnotes, assigned_staff_id=:assigned_staff_id, updated_at=NOW() WHERE id=:id AND provider_id=:pid");
            $stmtEdit->bindParam(':name',   $e_name);
            $stmtEdit->bindParam(':desc',   $e_desc);
            $stmtEdit->bindParam(':price',  $e_price);
            $stmtEdit->bindParam(':category_id', $e_category_id, PDO::PARAM_INT);
            $stmtEdit->bindParam(':pricing_type', $e_pricing_type);
            $stmtEdit->bindParam(':requires_inspection', $e_requires_inspection, PDO::PARAM_INT);
            $stmtEdit->bindParam(':pname',  $e_pest_name);
            $stmtEdit->bindParam(':pbrand', $e_pest_brand);
            $stmtEdit->bindParam(':ptype',  $e_pest_type);
            $stmtEdit->bindParam(':pnotes', $e_pest_notes);
            if ($e_assigned_staff_id > 0) {
                $stmtEdit->bindParam(':assigned_staff_id', $e_assigned_staff_id, PDO::PARAM_INT);
            } else {
                $stmtEdit->bindValue(':assigned_staff_id', null, PDO::PARAM_NULL);
            }
            $stmtEdit->bindParam(':id',     $edit_id,    PDO::PARAM_INT);
            $stmtEdit->bindParam(':pid',    $provider_id, PDO::PARAM_INT);
            $stmtEdit->execute();

            // Re-sync the equipment list regardless of whether other fields
            // changed, so "just update equipment" still saves.
            $db->prepare("DELETE FROM service_equipment_items WHERE service_id = :sid")->execute([':sid' => $edit_id]);
            if (!empty($e_selectedEquipmentIds)) {
                $eqLinkStmt = $db->prepare("INSERT IGNORE INTO service_equipment_items (service_id, inventory_item_id, quantity_needed) VALUES (:sid, :iid, :qty)");
                foreach ($e_selectedEquipmentIds as $eqId) {
                    $qty = max(1, (int)($_POST['edit_equipment_qty'][$eqId] ?? 1));
                    $eqLinkStmt->execute([':sid' => $edit_id, ':iid' => $eqId, ':qty' => $qty]);
                }
            }

            $edit_success = 'Service updated successfully!';
        } catch (PDOException $ex) {
            $edit_error = 'Failed to update service. Please try again.';
        }
        }
    } else {
        $edit_error = implode(' ', $e_errors);
    }
}

// Edit contract text from View modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_contract'])) {
    $contract_service_id = (int)($_POST['contract_service_id'] ?? 0);
    $contract_text_edit  = trim($_POST['edit_contract_text'] ?? '');

    if ($contract_service_id <= 0) {
        $edit_error = 'Invalid service for contract update.';
    } elseif ($contract_text_edit === '') {
        $edit_error = 'Contract text cannot be empty.';
    } else {
        try {
            $stmtContract = $db->prepare("UPDATE services SET contract_text=:contract_text, updated_at=NOW() WHERE id=:id AND provider_id=:pid");
            $stmtContract->bindParam(':contract_text', $contract_text_edit);
            $stmtContract->bindParam(':id', $contract_service_id, PDO::PARAM_INT);
            $stmtContract->bindParam(':pid', $provider_id, PDO::PARAM_INT);
            $stmtContract->execute();
            if ($stmtContract->rowCount() > 0) {
                $edit_success = 'Contract updated successfully.';
            } else {
                $edit_error = 'No contract changes saved or service not found.';
            }
        } catch (PDOException $ex) {
            $edit_error = 'Failed to update contract. Please try again.';
        }
    }
}

// ── Handle Payment Settings ──
$pay_success = '';
$pay_error   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payment'])) {
    $pay_service_id  = (int)($_POST['pay_service_id'] ?? 0);
    $pay_type        = trim($_POST['payment_type'] ?? 'full');
    $dp_mode         = trim($_POST['dp_mode'] ?? 'percent');
    $dp_percent      = (float)($_POST['dp_percent'] ?? 50);
    $dp_fixed        = (float)($_POST['dp_fixed'] ?? 0);

    if ($pay_service_id > 0) {
        try {
            $stmtServiceMeta = $db->prepare("SELECT id, service_name FROM services WHERE id = :id AND provider_id = :pid LIMIT 1");
            $stmtServiceMeta->bindParam(':id', $pay_service_id, PDO::PARAM_INT);
            $stmtServiceMeta->bindParam(':pid', $provider_id, PDO::PARAM_INT);
            $stmtServiceMeta->execute();
            $service_meta = $stmtServiceMeta->fetch(PDO::FETCH_ASSOC);

            if (!$service_meta) {
                $pay_error = 'Invalid service for payment settings.';
            } else {
                $locking_booking_count = countServiceLockingBookings(
                    $db,
                    (int)$provider_id,
                    (int)($provider['user_id'] ?? 0),
                    $pay_service_id,
                    (string)($service_meta['service_name'] ?? '')
                );

                if ($locking_booking_count > 0) {
                    $pay_error = 'Payment settings cannot be changed while this service already has booking requests.';
                } else {
                    $pay_data = json_encode([
                        'type'       => $pay_type,
                        'dp_mode'    => $dp_mode,
                        'dp_percent' => $dp_percent,
                        'dp_fixed'   => $dp_fixed,
                    ]);
                    $stmtPay = $db->prepare("UPDATE services SET payment_settings=:ps WHERE id=:id AND provider_id=:pid");
                    $stmtPay->bindParam(':ps',  $pay_data);
                    $stmtPay->bindParam(':id',  $pay_service_id, PDO::PARAM_INT);
                    $stmtPay->bindParam(':pid', $provider_id,    PDO::PARAM_INT);
                    $stmtPay->execute();
                    $pay_success = 'Payment settings saved!';
                }
            }
        } catch (PDOException $ex) {
            $pay_error = 'Could not save payment settings. Run the migration to add the payment_settings column.';
        }
    } else {
        $pay_error = 'Invalid service for payment settings.';
    }
}

$providerUserIdForServiceLock = (int)($provider['user_id'] ?? 0);
$stmt = $db->prepare('SELECT s.id, s.service_name, s.description, s.price, s.category_id, sc.name AS category_name, s.pricing_type, s.requires_inspection, s.duration, s.status, s.created_at, s.payment_settings, s.pesticide_name, s.pesticide_brand, s.pesticide_type, s.pesticide_notes, s.contract_text, s.contract_signature, s.contract_signed_at, s.assigned_staff_id
    FROM services s LEFT JOIN service_categories sc ON sc.id = s.category_id
    WHERE s.provider_id = :pid AND s.deleted_at IS NULL ORDER BY s.created_at DESC');
$stmt->bindParam(':pid', $provider_id, PDO::PARAM_INT);
$stmt->execute();
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);
$services = array_map(function(array $service) use ($db, $provider_id, $providerUserIdForServiceLock) {
    $service['locking_booking_count'] = countServiceLockingBookings(
        $db,
        (int)$provider_id,
        $providerUserIdForServiceLock,
        (int)($service['id'] ?? 0),
        (string)($service['service_name'] ?? '')
    );
    try {
        $eqIdsStmt = $db->prepare("SELECT inventory_item_id, quantity_needed FROM service_equipment_items WHERE service_id = :sid");
        $eqIdsStmt->execute([':sid' => (int)($service['id'] ?? 0)]);
        $eqRows = $eqIdsStmt->fetchAll(PDO::FETCH_ASSOC);
        $service['equipment_ids'] = array_map(fn($r) => (int)$r['inventory_item_id'], $eqRows);
        $service['equipment_qty'] = array_combine(
            array_map(fn($r) => (int)$r['inventory_item_id'], $eqRows),
            array_map(fn($r) => (int)$r['quantity_needed'], $eqRows)
        );
    } catch (Exception $e) { $service['equipment_ids'] = []; $service['equipment_qty'] = []; }
    return $service;
}, $services);
$show_first_time_guide = count($services) === 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Services - Pestify</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap">
    <style>
        :root {
            --primary: #3498db;
            --primary-dark: #2980b9;
            --dark: #2c3e50;
            --light-bg: #f5f7fa;
            --sidebar-from: #1a1f3a;
            --sidebar-to: #2d3561;
            --white: #ffffff;
            --border: #ecf0f1;
            --text-muted: #7f8c8d;
            --success-bg: #d4edda;
            --success-text: #155724;
            --warning-bg: #fff3cd;
            --warning-text: #856404;
            --pest-green: #1a7a4a;
            --pest-green-bg: #e8f5ee;
            --pest-green-border: #b2dfca;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DM Sans', sans-serif; background: var(--light-bg); color: var(--dark); }
        .dashboard-container { display: flex; min-height: 100vh; }

        /* Sidebar */
        .sidebar { width: 260px; background: linear-gradient(135deg, var(--sidebar-from) 0%, var(--sidebar-to) 100%); color: white; position: fixed; height: 100vh; overflow-y: auto; box-shadow: 2px 0 10px rgba(0,0,0,0.1); z-index: 100; }
        .sidebar-header { padding: 25px 20px; border-bottom: 1px solid rgba(255,255,255,0.15); background: rgba(0,0,0,0.1); }
        .sidebar-header h2 { font-size: 22px; color: #fff; display: flex; align-items: center; gap: 10px; font-weight: 700; }
        .sidebar-header p  { font-size: 12px; color: rgba(255,255,255,0.7); margin-top: 4px; }
        .sidebar-menu { list-style: none; padding: 15px 0; }
        .sidebar-menu li { margin-bottom: 2px; }
        .sidebar-menu a { display: flex; align-items: center; padding: 14px 20px; color: rgba(255,255,255,0.85); text-decoration: none; transition: all 0.25s; font-size: 14px; font-weight: 500; }
        .sidebar-menu a:hover, .sidebar-menu a.active { background: rgba(255,255,255,0.18); color: white; border-left: 4px solid white; padding-left: 16px; }
        .sidebar-menu a i { margin-right: 12px; width: 20px; text-align: center; }
        .sidebar-footer { padding: 20px; border-top: 1px solid rgba(255,255,255,0.15); position: absolute; bottom: 0; width: 100%; background: rgba(0,0,0,0.05); }
        .user-profile { display: flex; align-items: center; gap: 12px; padding: 12px; background: rgba(255,255,255,0.1); border-radius: 10px; }
        .user-avatar { width: 42px; height: 42px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 17px; font-weight: 700; color: var(--primary); flex-shrink: 0; overflow: hidden; }
        .user-avatar.has-photo { background: transparent; color: transparent; }
        .user-avatar-img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; display: block; }
        .user-info h4 { font-size: 13px; color: white; font-weight: 600; margin-bottom: 2px; }
        .user-info p  { font-size: 11px; color: rgba(255,255,255,0.7); }

        /* Main */
        .main-content { flex: 1; margin-left: 260px; padding: 32px; padding-bottom: 80px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; }
        .page-header-left h1 { font-size: 28px; font-weight: 700; color: var(--dark); }
        .page-header-left p  { font-size: 14px; color: var(--text-muted); margin-top: 4px; }

        /* Buttons */
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; text-decoration: none; font-weight: 600; font-size: 14px; font-family: inherit; transition: all 0.25s; }
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; box-shadow: 0 4px 12px rgba(52,152,219,0.3); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(52,152,219,0.4); }
        .btn-secondary { background: var(--white); color: var(--dark); border: 1px solid var(--border); }
        .btn-secondary:hover { background: #f0f0f0; }
        .btn-danger { background: #fff0f0; color: #e74c3c; border: 1px solid #ffd5d5; }
        .btn-danger:hover { background: #ffe0e0; }
        .btn-sm { padding: 7px 14px; font-size: 13px; }

        /* Alerts */
        .alert { display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; font-weight: 500; border-left: 4px solid; }
        .alert-success { background: var(--success-bg); color: var(--success-text); border-color: #27ae60; }
        .alert-warning { background: var(--warning-bg); color: var(--warning-text); border-color: #f39c12; }

        /* First-time guide */
        .first-time-guide {
            margin-bottom: 20px;
            border: 1px solid #bae6fd;
            background: linear-gradient(135deg, #f0f9ff 0%, #ecfeff 100%);
            border-radius: 14px;
            padding: 16px 18px;
            box-shadow: 0 8px 20px rgba(14, 116, 144, 0.08);
        }
        .first-time-guide-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }
        .first-time-guide-title {
            font-size: 16px;
            font-weight: 800;
            color: #0c4a6e;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .first-time-guide-sub {
            font-size: 13px;
            color: #155e75;
            margin-bottom: 12px;
        }
        .first-time-guide-steps {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 12px;
            margin-bottom: 12px;
        }
        .first-time-guide-step {
            font-size: 13px;
            color: #0f172a;
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid #cde8f8;
            border-radius: 9px;
            padding: 8px 10px;
        }
        .first-time-guide-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .guide-dismiss-btn {
            padding: 10px 14px;
            border-radius: 9px;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #334155;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            font-size: 13px;
        }

        /* Stats */
        .stats-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 28px; }
        .mini-stat { background: var(--white); border-radius: 12px; padding: 20px 22px; display: flex; align-items: center; gap: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border: 1px solid var(--border); }
        .mini-stat-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; color: white; flex-shrink: 0; }
        .mini-stat-icon.blue   { background: linear-gradient(135deg, #3498db, #2980b9); }
        .mini-stat-icon.green  { background: linear-gradient(135deg, #27ae60, #16a085); }
        .mini-stat-icon.purple { background: linear-gradient(135deg, #9b59b6, #8e44ad); }
        .mini-stat-info h3 { font-size: 22px; font-weight: 700; color: var(--dark); }
        .mini-stat-info p  { font-size: 12px; color: var(--text-muted); margin-top: 2px; }

        /* Card */
        .content-card { background: var(--white); border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.07); border: 1px solid var(--border); overflow: hidden; }
        .card-header { display: flex; justify-content: space-between; align-items: center; padding: 22px 26px; border-bottom: 1px solid var(--border); }
        .card-header h2 { font-size: 17px; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 10px; }
        .card-header h2 i { color: var(--primary); }

        /* Table */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        thead th { background: #f8f9fa; padding: 14px 18px; text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: var(--text-muted); border-bottom: 2px solid var(--border); }
        tbody td { padding: 18px 18px; border-bottom: 1px solid var(--border); font-size: 14px; vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #fafbfc; }
        .service-name { font-weight: 600; color: var(--dark); margin-bottom: 3px; }
        .service-desc { font-size: 12px; color: var(--text-muted); }
        .price-badge { display: inline-block; background: #e8f5e9; color: #2e7d32; font-weight: 700; font-size: 13px; padding: 5px 12px; border-radius: 20px; }
        .date-text { font-size: 13px; color: var(--text-muted); }
        .actions-cell { display: flex; gap: 8px; align-items: center; }

        /* Pesticide badge in table */
        .pesticide-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: var(--pest-green-bg); color: var(--pest-green);
            border: 1px solid var(--pest-green-border);
            padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 600;
            margin-top: 4px;
        }

        /* Empty */
        .empty-state { text-align: center; padding: 70px 20px; color: var(--text-muted); }
        .empty-icon { width: 80px; height: 80px; background: var(--light-bg); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; }
        .empty-icon i { font-size: 36px; color: #bdc3c7; }
        .empty-state h3 { font-size: 20px; color: var(--dark); margin-bottom: 8px; }
        .empty-state p  { font-size: 14px; margin-bottom: 20px; }

        /* Modals */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 9999; align-items: center; justify-content: center; padding: 20px; }
        .modal-overlay.open { display: flex; }
        .modal-box { background: white; border-radius: 16px; width: 100%; max-width: 560px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,0.2); animation: slideUp 0.3s; }
        .modal-head { padding: 22px 26px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
        .modal-head h3 { font-size: 18px; font-weight: 700; color: var(--dark); }
        .modal-close { width: 34px; height: 34px; border-radius: 50%; border: none; background: var(--light-bg); color: var(--text-muted); font-size: 18px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.2s; }
        .modal-close:hover { background: #e0e0e0; color: var(--dark); }
        .modal-body { padding: 26px; overflow-y: auto; flex: 1; }
        .modal-foot { padding: 18px 26px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; }

        /* Add form */
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; font-size: 13px; font-weight: 600; color: var(--dark); margin-bottom: 8px; }
        .form-control { width: 100%; padding: 11px 14px; border: 1.5px solid #dde1e7; border-radius: 9px; font-size: 14px; font-family: inherit; color: var(--dark); background: white; transition: border-color 0.2s, box-shadow 0.2s; }
        .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(52,152,219,0.12); }
        textarea.form-control { resize: vertical; min-height: 90px; line-height: 1.6; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .checkbox-group { display: flex; gap: 24px; flex-wrap: wrap; margin-top: 6px; }
        .checkbox-item { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--dark); cursor: pointer; font-weight: 500; }
        .checkbox-item input[type="checkbox"] { width: 17px; height: 17px; accent-color: var(--primary); cursor: pointer; }

        /* ── Pesticide Section ── */
        .pesticide-section {
            background: var(--pest-green-bg);
            border: 1.5px solid var(--pest-green-border);
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 20px;
        }
        .pesticide-section-title {
            display: flex; align-items: center; gap: 8px;
            font-size: 14px; font-weight: 700; color: var(--pest-green);
            margin-bottom: 16px;
        }
        .pesticide-section-title i { font-size: 16px; }
        .pesticide-type-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;
            margin-bottom: 4px;
        }
        .pest-type-btn {
            padding: 8px 6px; border: 1.5px solid var(--pest-green-border);
            border-radius: 8px; background: white; cursor: pointer;
            font-size: 12px; font-weight: 600; font-family: inherit;
            color: var(--pest-green); text-align: center; transition: all 0.2s;
            display: flex; flex-direction: column; align-items: center; gap: 4px;
        }
        .pest-type-btn i { font-size: 16px; }
        .pest-type-btn:hover { background: #c8ebd8; border-color: var(--pest-green); }
        .pest-type-btn.selected { background: var(--pest-green); color: white; border-color: var(--pest-green); }
        .pest-hidden { display: none; }

        /* View modal */
        .view-modal-box { max-width: 520px; }
        .view-section { margin-bottom: 22px; }
        .view-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.7px; color: var(--text-muted); margin-bottom: 5px; }
        .view-value { font-size: 15px; color: var(--dark); font-weight: 500; line-height: 1.6; }
        .view-value.large { font-size: 26px; font-weight: 700; color: #27ae60; }
        .view-value.muted { font-size: 14px; font-weight: 400; color: #555; background: var(--light-bg); padding: 12px 14px; border-radius: 8px; line-height: 1.7; }
        .view-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .view-divider { border: none; border-top: 1px solid var(--border); margin: 4px 0 22px; }

        /* Pesticide view block */
        .pesticide-view-block {
            background: var(--pest-green-bg);
            border: 1.5px solid var(--pest-green-border);
            border-radius: 12px; padding: 16px 18px; margin-bottom: 0;
        }
        .pesticide-view-block .view-label { color: var(--pest-green); }
        .pesticide-view-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
        .pesticide-view-item .pv-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: var(--pest-green); margin-bottom: 3px; }
        .pesticide-view-item .pv-value { font-size: 14px; font-weight: 600; color: var(--dark); }
        .pesticide-view-item .pv-value.empty { color: #aaa; font-weight: 400; font-style: italic; }

        .btn-settings { background: #f0f4ff; color: #3b5bdb; border: 1px solid #c5d0f5; }
        .btn-settings:hover { background: #dbe4ff; border-color: #748ffc; }

        /* Payment Toggle */
        .pay-toggle { display: flex; gap: 10px; margin-bottom: 18px; }
        .pay-toggle-btn { flex: 1; padding: 12px; border: 2px solid #e2e8f0; border-radius: 10px; background: white; cursor: pointer; font-size: 14px; font-weight: 600; font-family: inherit; color: var(--text-muted); transition: all 0.2s; text-align: center; }
        .pay-toggle-btn i { display: block; font-size: 22px; margin-bottom: 6px; }
        .pay-toggle-btn.active-pay { border-color: var(--primary); background: #eff6ff; color: var(--primary); }
        .pay-toggle-btn:hover:not(.active-pay) { border-color: #cbd5e1; background: #f8fafc; }
        .dp-section { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px; margin-top: 4px; }
        .dp-mode-row { display: flex; gap: 10px; margin-bottom: 14px; }
        .dp-mode-btn { flex: 1; padding: 8px; border: 1.5px solid #e2e8f0; border-radius: 8px; background: white; cursor: pointer; font-size: 13px; font-weight: 600; font-family: inherit; color: var(--text-muted); transition: all 0.2s; }
        .dp-mode-btn.active-mode { border-color: #22c55e; background: #f0fdf4; color: #16a34a; }
        .dp-preview { margin-top: 12px; padding: 10px 14px; background: white; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 13px; color: var(--text-muted); }
        .dp-preview strong { color: var(--dark); font-size: 15px; }

        /* Settings modal tabs */
        .settings-tabs { display: flex; border-bottom: 2px solid var(--border); margin-bottom: 22px; }
        .settings-tab { padding: 10px 20px; font-size: 14px; font-weight: 600; color: var(--text-muted); cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.2s; background: none; border-top: none; border-left: none; border-right: none; font-family: inherit; }
        .settings-tab.active-tab { color: var(--primary); border-bottom-color: var(--primary); }
        .pay-lock-note { display:none; background:#fff7ed; border:1px solid #fdba74; color:#9a3412; border-radius:12px; padding:12px 14px; font-size:13px; line-height:1.6; margin-bottom:16px; }
        .pay-lock-note.show { display:block; }
        .tab-panel { display: none; }
        .tab-panel.active-panel { display: block; }
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.25); border-radius: 3px; }

        /* ── Multi-step add modal ── */
        .add-step { display:none; }
        .add-step.active { display:block; }
        .step-pill { display:flex; align-items:center; gap:7px; font-size:13px; font-weight:600; color:#94a3b8; padding-bottom:14px; white-space:nowrap; }
        .step-pill.active { color:var(--primary); }
        .step-pill.done { color:#27ae60; }
        .step-num { width:24px; height:24px; border-radius:50%; background:#e2e8f0; color:#64748b; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; flex-shrink:0; }
        .step-pill.active .step-num { background:var(--primary); color:white; }
        .step-pill.done .step-num { background:#27ae60; color:white; }
        .step-line { flex:1; height:2px; background:#e2e8f0; margin-bottom:14px; min-width:20px; }
        .step-line.done { background:#27ae60; }

        @keyframes slideUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        @media (max-width: 1024px) { .sidebar { width: 220px; } .main-content { margin-left: 220px; } }
        @media (max-width: 768px)  { .sidebar { display: none; } .main-content { margin-left: 0; padding: 20px; } .stats-row, .view-grid, .form-row, .pesticide-type-grid, .pesticide-view-grid, .first-time-guide-steps { grid-template-columns: 1fr; } .page-header { flex-direction: column; align-items: flex-start; gap: 12px; } }
    </style>
    <style>
        :root{
            --ui-primary:#0ea5e9;
            --ui-primary-dark:#0369a1;
            --ui-secondary:#10b981;
            --ui-ink:#0f172a;
            --ui-muted:#64748b;
            --ui-glow:0 20px 40px rgba(15,23,42,.08);
        }

        body{
            font-family:'Manrope',sans-serif;
            color:var(--ui-ink);
            background:
                radial-gradient(circle at 10% -10%, rgba(14,165,233,.18), transparent 35%),
                radial-gradient(circle at 95% 5%, rgba(16,185,129,.14), transparent 28%),
                linear-gradient(180deg,#f8fbff 0%,#f1f6fb 100%);
        }

        .sidebar{
            background: linear-gradient(165deg,#0b1a3a 0%,#132f57 55%,#0d3a58 100%);
            border-right: 1px solid rgba(255,255,255,.14);
            box-shadow: 0 12px 35px rgba(2,6,23,.28);
        }

        .sidebar-header{
            background: linear-gradient(180deg,rgba(255,255,255,.08),rgba(255,255,255,.02));
        }

        .sidebar-header h2{
            font-family:'Space Grotesk',sans-serif;
            font-size:28px;
            letter-spacing:-.4px;
        }

        .sidebar-menu a{
            border-left:0;
            border-radius:12px;
            margin:4px 12px;
            padding:12px 14px;
            font-weight:600;
            position:relative;
            overflow:hidden;
        }

        .sidebar-menu a::before{
            content:'';
            position:absolute;
            left:0;
            top:0;
            bottom:0;
            width:0;
            background: linear-gradient(180deg,var(--ui-primary),var(--ui-secondary));
            transition: width .25s ease;
            border-radius:10px;
        }

        .sidebar-menu a:hover,
        .sidebar-menu a.active{
            background: rgba(255,255,255,.16);
            padding-left:14px;
            backdrop-filter: blur(3px);
            border-left:0;
        }

        .sidebar-menu a:hover::before,
        .sidebar-menu a.active::before{
            width:4px;
        }

        .main-content{
            padding:34px 34px 120px;
        }

        .page-header-left h1{
            font-family:'Space Grotesk',sans-serif;
            letter-spacing:-.6px;
            font-size: clamp(2rem,2.4vw,2.35rem);
        }

        .page-header-left p{
            color:var(--ui-muted);
            font-weight:500;
        }

        .btn{
            border-radius:11px;
            font-weight:700;
            transition: transform .2s ease, box-shadow .2s ease, background .2s ease;
        }

        .btn-primary{
            background: linear-gradient(135deg,var(--ui-primary) 0%,var(--ui-primary-dark) 100%);
            box-shadow: 0 10px 18px rgba(14,165,233,.28);
        }

        .btn-primary:hover{
            background: linear-gradient(135deg,#0284c7 0%,#075985 100%);
            box-shadow: 0 14px 26px rgba(14,165,233,.32);
            transform: translateY(-2px);
        }

        .btn-secondary{
            background:#fff;
            border:1px solid #d6e3ef;
            color:#1e3a5f;
        }

        .btn-secondary:hover{
            background:#f3f9ff;
            border-color:#bbd9ef;
        }

        .mini-stat{
            border-radius:18px;
            border:1px solid #dfeaf5;
            background: linear-gradient(180deg,#ffffff 0%,#f9fcff 100%);
            box-shadow: var(--ui-glow);
            position:relative;
            overflow:hidden;
        }

        .mini-stat::after{
            content:'';
            position:absolute;
            left:0;right:0;top:0;
            height:4px;
            background: linear-gradient(90deg,rgba(14,165,233,.95),rgba(16,185,129,.95));
            opacity:.75;
        }

        .mini-stat:hover{
            transform: translateY(-6px);
            box-shadow: 0 24px 45px rgba(15,23,42,.12);
            border-color:#c5def1;
        }

        .mini-stat-info h3{
            font-family:'Space Grotesk',sans-serif;
            font-size:34px;
            letter-spacing:-.5px;
        }

        .content-card{
            border-radius:18px;
            border:1px solid #dae7f3;
            box-shadow: var(--ui-glow);
            background: linear-gradient(180deg,#fff 0%,#fcfeff 100%);
        }

        .card-header{
            border-bottom:1px solid #e4edf5;
        }

        .card-header h2{
            font-family:'Space Grotesk',sans-serif;
            font-size:24px;
            letter-spacing:-.3px;
        }

        thead th{
            background:#f2f8ff;
            color:#3b536f;
            border-bottom:1px solid #d9e7f3;
            font-size:11px;
            position:sticky;
            top:0;
            z-index:1;
        }

        tbody td{
            border-bottom-color:#e7edf4;
        }

        tbody tr:hover{
            background:#f7fbff;
        }

        .alert{
            border-left-width:6px;
            border-radius:14px;
            box-shadow:0 8px 22px rgba(15,23,42,.07);
        }

        .form-control{
            border:1px solid #cfe0ef;
            border-radius:10px;
            padding:11px 12px;
            background:#fbfdff;
        }

        .form-control:focus{
            border-color:#60a5fa;
            box-shadow:0 0 0 3px rgba(96,165,250,.2);
        }

        .user-profile{
            border:1px solid rgba(255,255,255,.16);
            background: linear-gradient(180deg,rgba(255,255,255,.14),rgba(255,255,255,.07));
        }

        @media (max-width:1024px){
            .main-content{ padding:28px 24px 100px; }
        }

        @media (max-width:768px){
            .main-content{ padding:20px 16px 78px; }
            .page-header-left h1{ font-size:26px; }
            .card-header h2{ font-size:20px; }
            .mini-stat-info h3{ font-size:30px; }
        }
    </style>
</head>
<body>
<div class="dashboard-container">

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <h2><i class="fas fa-bug"></i> Pestify</h2>
            <p>Provider Portal</p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="<?php echo appUrl('providers-dashboard.php'); ?>"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="<?php echo appUrl('services.php'); ?>" class="active"><i class="fas fa-briefcase"></i> My Services</a></li>
            <li><a href="<?php echo appUrl('service-requests.php'); ?>"><i class="fas fa-list-check"></i> Requests</a></li>
            <li><a href="<?php echo appUrl('messages.php'); ?>"><i class="fas fa-comments"></i> Messages</a></li>
            <li><a href="<?php echo appUrl('profile.php'); ?>"><i class="fas fa-user"></i> Profile</a></li>
            <li><a href="<?php echo appUrl('providers-dashboard.php'); ?>?view=settings#dashboard-settings"><i class="fas fa-sliders-h"></i> Settings</a></li>
            <li><a href="<?php echo appUrl('logout.php'); ?>"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
        <div class="sidebar-footer">
            <div class="user-profile">
                <?php
                // profile_image is a bare app-root-relative path — must be resolved
                // to an absolute URL before rendering here (this file lives one
                // directory below the app root). logo_url is already a full external
                // URL a provider pastes in, so it's left untouched.
                $provider_avatar = trim((string)($provider['profile_image'] ?? ''));
                if ($provider_avatar !== '') { $provider_avatar = siteUrl($provider_avatar); }
                if ($provider_avatar === '') { $provider_avatar = trim((string)($provider['logo_url'] ?? '')); }
                ?>
                <div class="user-avatar <?php echo $provider_avatar !== '' ? 'has-photo' : ''; ?>">
                    <?php if ($provider_avatar !== ''): ?>
                        <img src="<?php echo htmlspecialchars($provider_avatar); ?>" alt="Profile Photo" class="user-avatar-img">
                    <?php else: ?>
                        <?php echo strtoupper(substr($provider['company_name'] ?? 'P', 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($provider['company_name'] ?? 'Provider'); ?></h4>
                    <p><?php echo htmlspecialchars($provider['email'] ?? ''); ?></p>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main -->
    <main class="main-content">

        <div class="page-header">
            <div class="page-header-left">
                <h1>My Services</h1>
                <p>Manage the services you offer to customers</p>
            </div>
            <button type="button" id="openModalBtn" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add Service
            </button>
        </div>

        <?php if (!empty($add_success)):    ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($add_success); ?></div><?php endif; ?>
        <?php if (!empty($add_error)):      ?><div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($add_error); ?></div><?php endif; ?>
        <?php if (!empty($delete_success)): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($delete_success); ?></div><?php endif; ?>
        <?php if (!empty($delete_error)):   ?><div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($delete_error); ?></div><?php endif; ?>
        <?php if (!empty($edit_success)):   ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($edit_success); ?></div><?php endif; ?>
        <?php if (!empty($edit_error)):     ?><div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($edit_error); ?></div><?php endif; ?>
        <?php if (!empty($pay_success)):    ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($pay_success); ?></div><?php endif; ?>
        <?php if (!empty($pay_error)):      ?><div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($pay_error); ?></div><?php endif; ?>

        <?php if ($show_first_time_guide): ?>
        <section class="first-time-guide" id="firstTimeGuide" data-storage-key="pestify.services.first_guide.<?php echo (int)$provider_id; ?>">
            <div class="first-time-guide-head">
                <div class="first-time-guide-title"><i class="fas fa-map-signs"></i> First-Time Guide</div>
            </div>
            <p class="first-time-guide-sub">Set up your first service in 5 quick steps.</p>
            <div class="first-time-guide-steps">
                <div class="first-time-guide-step"><strong>1.</strong> Click <strong>Add Service</strong>.</div>
                <div class="first-time-guide-step"><strong>2.</strong> Fill in service details and pricing.</div>
                <div class="first-time-guide-step"><strong>3.</strong> Add your contract and generate PDF if needed.</div>
                <div class="first-time-guide-step"><strong>4.</strong> Draw your e-signature under the contract.</div>
                <div class="first-time-guide-step"><strong>5.</strong> Review everything, then publish.</div>
            </div>
            <div class="first-time-guide-actions">
                <button type="button" class="btn btn-primary btn-sm" id="guideStartBtn"><i class="fas fa-play"></i> Start Setup</button>
                <button type="button" class="guide-dismiss-btn" id="guideDismissBtn">Hide this guide</button>
            </div>
        </section>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-row">
            <div class="mini-stat">
                <div class="mini-stat-icon blue"><i class="fas fa-briefcase"></i></div>
                <div class="mini-stat-info"><h3><?php echo count($services); ?></h3><p>Total Services</p></div>
            </div>
            <div class="mini-stat">
                <div class="mini-stat-icon green"><i class="fas fa-check-circle"></i></div>
                <div class="mini-stat-info">
                    <h3><?php echo count(array_filter($services, fn($s) => ($s['status'] ?? 'active') === 'active')); ?></h3>
                    <p>Active Services</p>
                </div>
            </div>
            <div class="mini-stat">
                <div class="mini-stat-icon purple"><i class="fas fa-peso-sign"></i></div>
                <div class="mini-stat-info">
                    <h3>&#8369;<?php echo number_format(array_sum(array_column($services, 'price')), 0); ?></h3>
                    <p>Total Value Listed</p>
                </div>
            </div>
        </div>

        <!-- Table -->
        <div class="content-card">
            <div class="card-header">
                <h2><i class="fas fa-list"></i> All Services</h2>
                <?php if (count($services) > 0): ?>
                    <span style="font-size:13px; color:var(--text-muted);"><?php echo count($services); ?> service<?php echo count($services) !== 1 ? 's' : ''; ?> found</span>
                <?php endif; ?>
            </div>

            <?php if (count($services) > 0): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Service</th>
                            <th>Pesticide / Product</th>
                            <th>Duration</th>
                            <th>Price</th>
                            <th>Date Added</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($services as $s): ?>
                        <tr>
                            <td style="color:var(--text-muted); font-size:13px;"><?php echo (int)$s['id']; ?></td>
                            <td>
                                <div class="service-name"><?php echo htmlspecialchars($s['service_name']); ?></div>
                                <?php if (!empty($s['requires_inspection'])): ?>
                                    <span class="pesticide-badge" style="background:#e8daef;color:#4a235a;">
                                        <i class="fas fa-magnifying-glass"></i> Requires Inspection
                                    </span>
                                <?php endif; ?>
                                <div class="service-desc"><?php echo htmlspecialchars(mb_strimwidth($s['description'] ?? '', 0, 70, '...')); ?></div>
                            </td>
                            <td>
                                <?php if (!empty($s['pesticide_name'])): ?>
                                    <div style="font-weight:600; font-size:13px; color:var(--pest-green);">
                                        <i class="fas fa-flask" style="font-size:11px;"></i>
                                        <?php echo htmlspecialchars($s['pesticide_name']); ?>
                                    </div>
                                    <?php if (!empty($s['pesticide_brand'])): ?>
                                        <div class="service-desc"><?php echo htmlspecialchars($s['pesticide_brand']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($s['pesticide_type'])): ?>
                                        <span class="pesticide-badge">
                                            <i class="fas fa-tag"></i>
                                            <?php echo htmlspecialchars($s['pesticide_type']); ?>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#ccc; font-size:13px;">�</span>
                                <?php endif; ?>
                            </td>
                            <td class="date-text"><?php echo htmlspecialchars($s['duration'] ?? '�'); ?></td>
                            <td>
                                <span class="price-badge">&#8369;<?php echo number_format((float)$s['price'], 2); ?></span>
                                <div style="font-size:11px;color:var(--text-muted);margin-top:3px;"><?php echo htmlspecialchars(ucfirst($s['pricing_type'] ?? 'fixed')); ?><?php echo ($s['pricing_type'] ?? 'fixed') !== 'fixed' ? ' (estimate)' : ''; ?></div>
                            </td>
                            <td class="date-text"><?php echo date('M d, Y', strtotime($s['created_at'])); ?></td>
                            <?php $pay_settings = json_decode($s['payment_settings'] ?? '{}', true) ?? []; ?>
                            <td>
                                <div class="actions-cell">
                                    <button type="button" class="btn btn-secondary btn-sm view-btn"
                                        data-name="<?php echo htmlspecialchars($s['service_name'], ENT_QUOTES); ?>"
                                        data-price="<?php echo number_format((float)$s['price'], 2); ?>"
                                        data-duration="<?php echo htmlspecialchars($s['duration'] ?? '�', ENT_QUOTES); ?>"
                                        data-desc="<?php echo htmlspecialchars($s['description'] ?? '', ENT_QUOTES); ?>"
                                        data-created="<?php echo date('F d, Y', strtotime($s['created_at'])); ?>"
                                        data-id="<?php echo (int)$s['id']; ?>"
                                        data-pest-name="<?php echo htmlspecialchars($s['pesticide_name'] ?? '', ENT_QUOTES); ?>"
                                        data-pest-brand="<?php echo htmlspecialchars($s['pesticide_brand'] ?? '', ENT_QUOTES); ?>"
                                        data-pest-type="<?php echo htmlspecialchars($s['pesticide_type'] ?? '', ENT_QUOTES); ?>"
                                        data-pest-notes="<?php echo htmlspecialchars($s['pesticide_notes'] ?? '', ENT_QUOTES); ?>"
                                        data-contract-text="<?php echo htmlspecialchars(base64_encode($s['contract_text'] ?? ''), ENT_QUOTES); ?>"
                                        data-contract-signed-at="<?php echo htmlspecialchars($s['contract_signed_at'] ?? '', ENT_QUOTES); ?>">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button type="button" class="btn btn-settings btn-sm settings-btn"
                                        data-id="<?php echo (int)$s['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($s['service_name'], ENT_QUOTES); ?>"
                                        data-price="<?php echo number_format((float)$s['price'], 2); ?>"
                                        data-duration="<?php echo htmlspecialchars($s['duration'] ?? '', ENT_QUOTES); ?>"
                                        data-desc="<?php echo htmlspecialchars($s['description'] ?? '', ENT_QUOTES); ?>"
                                        data-pest-name="<?php echo htmlspecialchars($s['pesticide_name'] ?? '', ENT_QUOTES); ?>"
                                        data-pest-brand="<?php echo htmlspecialchars($s['pesticide_brand'] ?? '', ENT_QUOTES); ?>"
                                        data-pest-type="<?php echo htmlspecialchars($s['pesticide_type'] ?? '', ENT_QUOTES); ?>"
                                        data-pest-notes="<?php echo htmlspecialchars($s['pesticide_notes'] ?? '', ENT_QUOTES); ?>"
                                        data-assigned-staff-id="<?php echo (int)($s['assigned_staff_id'] ?? 0); ?>"
                                        data-requires-inspection="<?php echo (int)($s['requires_inspection'] ?? 0); ?>"
                                        data-category-id="<?php echo (int)($s['category_id'] ?? 0); ?>"
                                        data-pricing-type="<?php echo htmlspecialchars($s['pricing_type'] ?? 'fixed', ENT_QUOTES); ?>"
                                        data-equipment-ids="<?php echo htmlspecialchars(json_encode($s['equipment_ids'] ?? []), ENT_QUOTES); ?>"
                                        data-equipment-qty="<?php echo htmlspecialchars(json_encode($s['equipment_qty'] ?? []), ENT_QUOTES); ?>"
                                        data-pay-type="<?php echo htmlspecialchars($pay_settings['type'] ?? 'full', ENT_QUOTES); ?>"
                                        data-dp-mode="<?php echo htmlspecialchars($pay_settings['dp_mode'] ?? 'percent', ENT_QUOTES); ?>"
                                        data-dp-percent="<?php echo (float)($pay_settings['dp_percent'] ?? 50); ?>"
                                        data-dp-fixed="<?php echo (float)($pay_settings['dp_fixed'] ?? 0); ?>"
                                        data-pay-locked="<?php echo (int)($s['locking_booking_count'] ?? 0) > 0 ? '1' : '0'; ?>"
                                        data-pending-booking-count="<?php echo (int)($s['locking_booking_count'] ?? 0); ?>">
                                        <i class="fas fa-cog"></i>
                                    </button>
                                    <button type="button"
                                        class="btn btn-danger btn-sm delete-service-btn"
                                        data-delete-url="services.php?delete=<?php echo (int)$s['id']; ?>"
                                        data-service-name="<?php echo htmlspecialchars($s['service_name'], ENT_QUOTES); ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-clipboard-list"></i></div>
                <h3>No services yet</h3>
                <p>Add your first service to start receiving requests from customers.</p>
                <button type="button" id="openModalBtn2" class="btn btn-primary"><i class="fas fa-plus"></i> Add Your First Service</button>
            </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<!-- ── View Service Modal ── -->
<div class="modal-overlay" id="viewModal">
    <div class="modal-box view-modal-box">
        <div class="modal-head">
            <h3><i class="fas fa-eye" style="color:var(--primary); margin-right:8px;"></i> Service Details</h3>
            <button class="modal-close" id="closeViewModal">&times;</button>
        </div>
        <div class="modal-body">

            <div class="view-section">
                <div class="view-label">Service Name</div>
                <div class="view-value" id="view-name">�</div>
            </div>
            <hr class="view-divider">

            <div class="view-grid">
                <div class="view-section">
                    <div class="view-label">Price</div>
                    <div class="view-value large" id="view-price">�</div>
                </div>
                <div class="view-section">
                    <div class="view-label">Duration</div>
                    <div class="view-value" id="view-duration">�</div>
                </div>
            </div>

            <div class="view-section">
                <div class="view-label">Description</div>
                <div class="view-value muted" id="view-desc">�</div>
            </div>

            <!-- Pesticide view block -->
            <div class="view-section" id="view-pest-block">
                <hr class="view-divider">
                <div class="pesticide-view-block">
                    <div class="view-label" style="color:var(--pest-green); margin-bottom:12px;">
                        <i class="fas fa-flask" style="margin-right:5px;"></i> Pesticide / Product Used
                    </div>
                    <div class="pesticide-view-grid">
                        <div class="pesticide-view-item">
                            <div class="pv-label">Product Name</div>
                            <div class="pv-value" id="view-pest-name">�</div>
                        </div>
                        <div class="pesticide-view-item">
                            <div class="pv-label">Brand</div>
                            <div class="pv-value" id="view-pest-brand">�</div>
                        </div>
                        <div class="pesticide-view-item">
                            <div class="pv-label">Type / Category</div>
                            <div class="pv-value" id="view-pest-type">�</div>
                        </div>
                    </div>
                    <div class="pesticide-view-item" id="view-pest-notes-wrap">
                        <div class="pv-label">Notes / Instructions</div>
                        <div class="pv-value" id="view-pest-notes" style="background:white; padding:10px 12px; border-radius:8px; font-size:13px; line-height:1.6; font-weight:400;">�</div>
                    </div>
                </div>
            </div>

            <div class="view-section" style="margin-bottom:0;">
                <div class="view-label">Date Added</div>
                <div class="view-value" style="color:var(--text-muted); font-size:14px;" id="view-created">�</div>
            </div>

            <div class="view-section" style="margin-top:18px; margin-bottom:0;">
                <hr class="view-divider">
                <div class="view-label">Service Contract Agreement (Editable)</div>
                <textarea id="view-contract-text" class="form-control" style="min-height:220px;" placeholder="No saved contract text for this service yet."></textarea>
                <div id="view-contract-signed-at" style="font-size:12px; color:var(--text-muted); margin-top:8px;"></div>
            </div>

        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-secondary" id="printViewContractBtn">
                <i class="fas fa-print"></i> Print PDF
            </button>
            <button type="button" class="btn btn-primary" id="saveViewContractBtn">
                <i class="fas fa-save"></i> Save Contract
            </button>
            <button type="button" class="btn btn-secondary" id="closeViewBtn">Close</button>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal-overlay" id="deleteConfirmModal">
    <div class="modal-box" style="max-width:500px;">
        <div class="modal-head">
            <h3><i class="fas fa-triangle-exclamation" style="color:#e74c3c; margin-right:8px;"></i> Remove Service</h3>
            <button class="modal-close" id="closeDeleteConfirmModal">&times;</button>
        </div>
        <div class="modal-body" style="padding-top:20px;">
            <p id="deleteConfirmMessage" style="font-size:15px; color:var(--dark); line-height:1.7; margin:0;"></p>
            <p style="font-size:13px; color:var(--text-muted); margin-top:12px;">
                Existing seeker requests/bookings for this service will be kept.
            </p>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-secondary" id="deleteConfirmCancelBtn">Cancel</button>
            <button type="button" class="btn btn-danger" id="deleteConfirmProceedBtn">Continue</button>
        </div>
    </div>
</div>

<form method="POST" action="<?php echo appUrl('services.php'); ?>" id="editContractForm" style="display:none;">
    <input type="hidden" name="edit_contract" value="1">
    <input type="hidden" name="contract_service_id" id="edit_contract_service_id" value="">
    <textarea name="edit_contract_text" id="edit_contract_text_hidden"></textarea>
</form>

<!-- ── Add Service Modal (Multi-Step) ── -->
<div class="modal-overlay" id="addModal">
    <div class="modal-box" style="max-width:660px;">
        <div class="modal-head">
            <h3 id="addModalTitle"><i class="fas fa-plus-circle" style="color:var(--primary); margin-right:8px;"></i> Add New Service</h3>
            <button class="modal-close" id="closeModal">&times;</button>
        </div>

        <!-- Step Indicator -->
        <div style="display:flex; align-items:center; gap:0; padding:16px 26px 0; border-bottom:1px solid var(--border); background:#fafbfc;">
            <div class="step-pill active" id="pill-1"><span class="step-num">1</span> Service Details</div>
            <div class="step-line" id="line-1"></div>
            <div class="step-pill" id="pill-2"><span class="step-num">2</span> Contract + Signature</div>
            <div class="step-line" id="line-2"></div>
            <div class="step-pill" id="pill-3"><span class="step-num">3</span> Final Preview</div>
        </div>

        <!-- Shown only when a draft from an earlier unfinished attempt exists —
             never auto-restored, so nothing fills in without the user choosing to. -->
        <div id="draftRestoreBanner" style="display:none; margin:16px 26px 0; padding:12px 16px; background:#fef9c3; border:1px solid #fde68a; border-radius:10px; font-size:13px; color:#713f12; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <i class="fas fa-clock-rotate-left" style="font-size:15px;"></i>
            <span style="flex:1; min-width:200px;">You have an unfinished service draft from earlier.</span>
            <button type="button" class="btn btn-sm btn-primary" id="draftRestoreBtn">Restore Draft</button>
            <button type="button" class="btn btn-sm btn-secondary" id="draftDismissBtn">Start Blank</button>
        </div>

        <div class="modal-body" style="padding-top:22px;">

            <!-- ══ STEP 1: Service Details ══ -->
            <div class="add-step active" id="add-step-1">
                <form id="addServiceForm">
                    <input type="hidden" name="add_service" value="1">
                    <div class="form-group">
                        <label class="form-label">Service Name *</label>
                        <input type="text" name="service_name" id="add_service_name" class="form-control" required placeholder="e.g., Professional Termite Treatment" maxlength="80">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Service Type *</label>
                            <select name="category_id" id="add_category" class="form-control" required>
                                <option value="">Select type</option>
                                <?php foreach ($serviceCategoryOptions as $sc): ?>
                                <option value="<?php echo (int)$sc['id']; ?>"><?php echo htmlspecialchars($sc['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Pricing Model *</label>
                            <select name="pricing_type" id="add_pricing_type" class="form-control" required onchange="onPricingTypeChange('add')">
                                <option value="fixed">Fixed Price</option>
                                <option value="custom">Custom Quote</option>
                            </select>
                            <div class="form-hint" style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                                Custom Quote can't be charged upfront — it requires an on-site inspection so the final price can be set afterward.
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" id="add_price_label">Price (&#8369;) *</label>
                        <input type="number" name="price" id="add_price" class="form-control" step="0.01" min="0" required placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
                            <input type="checkbox" name="requires_inspection" id="add_requires_inspection" value="1">
                            Requires On-Site Inspection First
                        </label>
                        <div class="form-hint" style="font-size:12px;color:var(--text-muted);margin-top:4px;" id="add_requires_inspection_hint">
                            Seeker requests will collect only an Inspection Date and an estimated price. A field technician must submit an inspection report (photo, notes, and a final price) before the seeker agrees to a Working Date and payment is collected.
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Assigned Field Technician</label>
                        <select name="assigned_staff_id" id="add_assigned_staff_id" class="form-control">
                            <option value="0">— Unassigned —</option>
                            <?php foreach ($fieldStaffOptions as $fs): ?>
                            <option value="<?php echo (int)$fs['id']; ?>"><?php echo htmlspecialchars($fs['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-hint" style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                            <?php if (empty($fieldStaffOptions)): ?>
                                No field technicians yet — add one in the HR module and set their Staff Type to "Field Technician" (no promotion needed, they can log in right away).
                            <?php else: ?>
                                This staff member will be pre-filled to handle bookings for this service instead of the platform admin.
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Equipment Used</label>
                        <?php if (empty($equipmentOptions)): ?>
                        <div class="form-hint" style="font-size:12px;color:var(--text-muted);">
                            No equipment in inventory yet — add some in Finance &gt; Inventory, then come back here to link it to this service.
                        </div>
                        <?php else: ?>
                        <div style="border:1px solid var(--border);border-radius:8px;padding:10px 12px;max-height:180px;overflow-y:auto;">
                            <?php foreach ($equipmentOptions as $eq): ?>
                            <div style="display:flex;align-items:center;gap:8px;padding:4px 0;font-size:13px;">
                                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;flex:1;min-width:0;">
                                    <input type="checkbox" class="add-equipment-checkbox" name="equipment_ids[]" value="<?php echo (int)$eq['id']; ?>" onchange="document.getElementById('add_eq_qty_<?php echo (int)$eq['id']; ?>').disabled = !this.checked;">
                                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($eq['item_name']); ?></span>
                                    <span style="color:var(--text-muted);font-size:11px;flex-shrink:0;">(₱<?php echo number_format((float)$eq['unit_price'], 2); ?>)</span>
                                </label>
                                <input type="number" name="equipment_qty[<?php echo (int)$eq['id']; ?>]" id="add_eq_qty_<?php echo (int)$eq['id']; ?>" class="form-control" style="width:64px;flex-shrink:0;" min="1" value="1" disabled>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-hint" style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                            Pre-fills the equipment picker (with this quantity) when preparing a booking for this service.
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="add_description" class="form-control" placeholder="Describe your service � what's included, coverage area, special features, etc."></textarea>
                    </div>

                    <!-- Pesticide Section -->
                    <div class="pesticide-section">
                        <div class="pesticide-section-title">
                            <i class="fas fa-flask"></i> Pesticide / Product Used
                            <span style="font-size:12px; color:#5a9a75; font-weight:400; margin-left:4px;">(optional but recommended)</span>
                        </div>
                        <div class="form-row">
                            <div class="form-group" style="margin-bottom:14px;">
                                <label class="form-label">Product / Chemical Name</label>
                                <input type="text" name="pesticide_name" class="form-control" placeholder="e.g., Bifenthrin, Chlorpyrifos" maxlength="255">
                            </div>
                            <div class="form-group" style="margin-bottom:14px;">
                                <label class="form-label">Brand</label>
                                <input type="text" name="pesticide_brand" class="form-control" placeholder="e.g., Syngenta, Bayer, Dow" maxlength="255">
                            </div>
                        </div>
                        <div class="form-group" style="margin-bottom:14px;">
                            <label class="form-label">Pesticide Type</label>
                            <div class="pesticide-type-grid" id="add-pest-type-grid">
                                <button type="button" class="pest-type-btn" onclick="selectPestType('add','Insecticide',this)"><i class="fas fa-bug"></i> Insecticide</button>
                                <button type="button" class="pest-type-btn" onclick="selectPestType('add','Termiticide',this)"><i class="fas fa-house-damage"></i> Termiticide</button>
                                <button type="button" class="pest-type-btn" onclick="selectPestType('add','Rodenticide',this)"><i class="fas fa-paw"></i> Rodenticide</button>
                                <button type="button" class="pest-type-btn" onclick="selectPestType('add','Fungicide',this)"><i class="fas fa-seedling"></i> Fungicide</button>
                                <button type="button" class="pest-type-btn" onclick="selectPestType('add','Repellent',this)"><i class="fas fa-shield-alt"></i> Repellent</button>
                                <button type="button" class="pest-type-btn" onclick="selectPestType('add','Other',this)"><i class="fas fa-flask"></i> Other</button>
                            </div>
                            <input type="hidden" name="pesticide_type" id="add-pesticide-type" value="">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Application Notes / Safety Info</label>
                            <textarea name="pesticide_notes" class="form-control" style="min-height:70px;" placeholder="e.g., Keep children and pets away for 4 hours after application."></textarea>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="active">Active � Visible to customers</option>
                            <option value="inactive">Inactive � Hidden from customers</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Additional Features</label>
                        <div class="checkbox-group">
                            <label class="checkbox-item">
                                <input type="checkbox" name="emergency" value="1">
                                <span><i class="fas fa-bolt" style="color:#e74c3c;"></i> Emergency Available</span>
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="eco_friendly" value="1">
                                <span><i class="fas fa-leaf" style="color:#27ae60;"></i> Eco-Friendly</span>
                            </label>
                        </div>
                    </div>
                </form>
            </div>

            <!-- STEP 2: Contract + Signature -->
            <div class="add-step" id="add-step-2">
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px 24px; margin-bottom:16px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                        <div style="font-size:15px; font-weight:700; color:var(--dark);"><i class="fas fa-file-contract" style="color:var(--primary); margin-right:8px;"></i>Service Contract Agreement</div>
                        <button type="button" onclick="generateContractPDF()" style="background:#3498db; color:white; border:none; border-radius:8px; padding:7px 14px; font-size:12px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px; font-family:inherit;">
                            <i class="fas fa-file-pdf"></i> Generate PDF
                        </button>
                    </div>
                    <div style="display:grid; grid-template-columns:1fr; gap:10px; margin-bottom:12px;">
                        <select id="contractTemplateSelect" class="form-control">
                            <option value="manual">Manual (Start Blank)</option>
                            <option value="standard">Standard Service Contract</option>
                            <option value="termite">Termite Treatment Contract</option>
                            <option value="emergency">Emergency Service Contract</option>
                            <option value="maintenance">Monthly Maintenance Contract</option>
                        </select>
                    </div>
                    <div style="font-size:12px; color:#64748b; margin-bottom:10px;">
                        Selecting a template auto-loads it. Choose Manual if you want to write from scratch.
                    </div>
                    <textarea id="contractEditor" class="form-control" style="min-height:280px;" placeholder="Write your full contract agreement here. This will be used for Final Review and PDF output."></textarea>
                </div>

                <div style="margin-bottom:14px;">
                    <div style="font-size:14px; font-weight:600; color:var(--dark); margin-bottom:4px;"><i class="fas fa-pen-fancy" style="color:var(--primary); margin-right:6px;"></i>E-Signature (at the bottom of contract)</div>
                    <div style="font-size:13px; color:var(--text-muted);">Sign below before proceeding to final review.</div>
                </div>
                <div style="position:relative; border:2px solid #cbd5e1; border-radius:12px; background:#fff; overflow:hidden;">
                    <canvas id="signatureCanvas" style="display:block; width:100%; height:180px; cursor:crosshair; touch-action:none;"></canvas>
                    <div id="sigPlaceholder" style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center; pointer-events:none; color:#cbd5e1; font-size:14px; font-style:italic;">
                        <i class="fas fa-signature" style="margin-right:8px; font-size:22px;"></i> Sign here
                    </div>
                </div>
                <div style="display:flex; gap:10px; margin-top:10px;">
                    <button type="button" onclick="clearSignature()" style="padding:8px 16px; background:#fff0f0; color:#e74c3c; border:1px solid #fecaca; border-radius:8px; cursor:pointer; font-size:13px; font-weight:600; font-family:inherit; display:flex; align-items:center; gap:6px;">
                        <i class="fas fa-eraser"></i> Clear
                    </button>
                    <div id="sigStatus" style="flex:1; display:flex; align-items:center; font-size:13px; color:#94a3b8; font-style:italic;">
                        <i class="fas fa-pen" style="margin-right:6px;"></i> Not signed yet
                    </div>
                </div>

                <div style="margin-top:18px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px 18px; font-size:13px; color:#475569;">
                    <div style="font-weight:700; color:var(--dark); margin-bottom:10px;"><i class="fas fa-check-circle" style="color:#27ae60; margin-right:6px;"></i>Signing Summary</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                        <div><span style="color:#94a3b8;">Service:</span> <strong id="sum-name">�</strong></div>
                        <div><span style="color:#94a3b8;">Price:</span> <strong id="sum-price">�</strong></div>
                        <div><span style="color:#94a3b8;">Provider:</span> <strong><?php echo htmlspecialchars($provider['company_name'] ?? ''); ?></strong></div>
                        <div><span style="color:#94a3b8;">Date:</span> <strong id="sum-date"></strong></div>
                    </div>
                </div>

                <input type="hidden" id="hidden_contract_text" name="contract_text" form="addServiceForm">
                <input type="hidden" id="hidden_contract_signature" name="contract_signature" form="addServiceForm">
            </div>

            <div class="add-step" id="add-step-3">
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px 24px; margin-bottom:14px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                        <div style="font-size:15px; font-weight:700; color:var(--dark);"><i class="fas fa-clipboard-check" style="color:#16a34a; margin-right:8px;"></i>Final Contract Preview</div>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <button type="button" onclick="viewContractPDF()" style="background:#0ea5e9; color:white; border:none; border-radius:8px; padding:7px 14px; font-size:12px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px; font-family:inherit;">
                                <i class="fas fa-file-pdf"></i> View PDF
                            </button>
                            <button type="button" onclick="generateContractPDF()" style="background:#16a34a; color:white; border:none; border-radius:8px; padding:7px 14px; font-size:12px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px; font-family:inherit;">
                                <i class="fas fa-print"></i> Print PDF
                            </button>
                        </div>
                    </div>
                    <div id="finalContractPreview" style="font-size:13px; color:#334155; line-height:1.9; max-height:380px; overflow-y:auto; padding-right:4px;"></div>
                </div>
                <div style="background:#ecfeff; border:1px solid #a5f3fc; border-radius:10px; padding:12px 16px; font-size:13px; color:#0e7490; display:flex; align-items:flex-start; gap:10px;">
                    <i class="fas fa-info-circle" style="margin-top:2px; flex-shrink:0;"></i>
                    <span>Review the full contract and print a PDF copy before publishing this service.</span>
                </div>
            </div>

        </div>

        <!-- Footer Buttons -->
        <div class="modal-foot" style="justify-content:space-between;">
            <div>
                <button type="button" class="btn btn-secondary" id="cancelModal">Cancel</button>
                <button type="button" class="btn btn-secondary" id="btnStepBack" style="display:none;" onclick="addStepBack()">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
            </div>
            <div>
                <button type="button" class="btn btn-primary" id="btnStepNext" onclick="addStepNext()">
                    Next: Contract + Signature <i class="fas fa-arrow-right"></i>
                </button>
                <button type="button" class="btn btn-primary" id="btnPublish" style="display:none; background:linear-gradient(135deg,#27ae60,#16a085);" onclick="submitWithSignature()">
                    <i class="fas fa-check"></i> Publish Service
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Step Transition Confirmation Modal -->
<div class="modal-overlay" id="stepConfirmModal">
    <div class="modal-box" style="max-width:500px;">
        <div class="modal-head">
            <h3><i class="fas fa-circle-question" style="color:#0ea5e9; margin-right:8px;"></i> Confirm Action</h3>
            <button class="modal-close" id="closeStepConfirmModal">&times;</button>
        </div>
        <div class="modal-body" style="padding-top:20px;">
            <p id="stepConfirmMessage" style="font-size:16px; color:var(--dark); line-height:1.7; margin:0;"></p>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-secondary" id="stepConfirmCancelBtn">Cancel</button>
            <button type="button" class="btn btn-primary" id="stepConfirmProceedBtn">Proceed</button>
        </div>
    </div>
</div>

<!-- ── Signature Confirmation Modal ── -->
<div class="modal-overlay" id="sigConfirmModal">
    <div class="modal-box" style="max-width:460px;">
        <div class="modal-head">
            <h3><i class="fas fa-signature" style="color:var(--primary); margin-right:8px;"></i> Confirm Signature</h3>
            <button class="modal-close" id="closeSigConfirmModal">&times;</button>
        </div>
        <div class="modal-body" style="padding-top:20px; text-align:center;">
            <p style="font-size:15px; color:var(--dark); margin-bottom:16px;">Does this signature look correct?</p>
            <div style="border:2px solid #e2e8f0; border-radius:12px; overflow:hidden; background:#fff; padding:12px; display:inline-block; max-width:100%;">
                <img id="sigConfirmPreview" src="" alt="Signature Preview" style="max-width:100%; max-height:130px; display:block;">
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-secondary" id="sigConfirmNoBtn"><i class="fas fa-redo"></i> No, Redraw</button>
            <button type="button" class="btn btn-primary" id="sigConfirmYesBtn"><i class="fas fa-check"></i> Yes, Looks Good</button>
        </div>
    </div>
</div>

<!-- ── Settings Modal (Edit + Payment) ── -->
<div class="modal-overlay" id="settingsModal">
    <div class="modal-box" style="max-width:600px;">
        <div class="modal-head">
            <h3><i class="fas fa-cog" style="color:var(--primary); margin-right:8px;"></i> Service Settings</h3>
            <button class="modal-close" id="closeSettingsModal">&times;</button>
        </div>
        <div class="modal-body">

            <!-- Tabs -->
            <div class="settings-tabs">
                <button class="settings-tab active-tab" data-tab="edit-panel"><i class="fas fa-edit" style="margin-right:6px;"></i>Edit Service</button>
                <button class="settings-tab" data-tab="pay-panel"><i class="fas fa-credit-card" style="margin-right:6px;"></i>Payment Settings</button>
            </div>

            <!-- Tab: Edit Service -->
            <div class="tab-panel active-panel" id="edit-panel">
                <form method="POST" action="<?php echo appUrl('services.php'); ?>" id="editServiceForm">
                    <input type="hidden" name="edit_service" value="1">
                    <input type="hidden" name="edit_id" id="edit_id" value="">
                    <div class="form-group">
                        <label class="form-label">Service Name *</label>
                        <input type="text" name="edit_service_name" id="edit_service_name" class="form-control" required maxlength="80">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Service Type *</label>
                            <select name="edit_category_id" id="edit_category" class="form-control" required>
                                <option value="">Select type</option>
                                <?php foreach ($serviceCategoryOptions as $sc): ?>
                                <option value="<?php echo (int)$sc['id']; ?>"><?php echo htmlspecialchars($sc['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Pricing Model *</label>
                            <select name="edit_pricing_type" id="edit_pricing_type" class="form-control" required onchange="onPricingTypeChange('edit')">
                                <option value="fixed">Fixed Price</option>
                                <option value="custom">Custom Quote</option>
                            </select>
                            <div class="form-hint" style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                                Custom Quote can't be charged upfront — it requires an on-site inspection so the final price can be set afterward.
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" id="edit_price_label">Price (&#8369;) *</label>
                        <input type="number" name="edit_price" id="edit_price" class="form-control" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="edit_description" id="edit_description" class="form-control"></textarea>
                    </div>
                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
                            <input type="checkbox" name="edit_requires_inspection" id="edit_requires_inspection" value="1">
                            Requires On-Site Inspection First
                        </label>
                        <div class="form-hint" style="font-size:12px;color:var(--text-muted);margin-top:4px;" id="edit_requires_inspection_hint">
                            Seeker requests will collect only an Inspection Date and an estimated price. A field technician must submit an inspection report before the seeker agrees to a Working Date and payment is collected.
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Assigned Field Technician</label>
                        <select name="edit_assigned_staff_id" id="edit_assigned_staff_id" class="form-control">
                            <option value="0">— Unassigned —</option>
                            <?php foreach ($fieldStaffOptions as $fs): ?>
                            <option value="<?php echo (int)$fs['id']; ?>"><?php echo htmlspecialchars($fs['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-hint" style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                            This staff member handles bookings for this service instead of the platform admin.
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Equipment Used</label>
                        <?php if (empty($equipmentOptions)): ?>
                        <div class="form-hint" style="font-size:12px;color:var(--text-muted);">
                            No equipment in inventory yet — add some in Finance &gt; Inventory.
                        </div>
                        <?php else: ?>
                        <div id="editEquipmentList" style="border:1px solid var(--border);border-radius:8px;padding:10px 12px;max-height:180px;overflow-y:auto;">
                            <?php foreach ($equipmentOptions as $eq): ?>
                            <div style="display:flex;align-items:center;gap:8px;padding:4px 0;font-size:13px;">
                                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;flex:1;min-width:0;">
                                    <input type="checkbox" name="edit_equipment_ids[]" class="edit-equipment-checkbox" value="<?php echo (int)$eq['id']; ?>" onchange="document.getElementById('edit_eq_qty_<?php echo (int)$eq['id']; ?>').disabled = !this.checked;">
                                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($eq['item_name']); ?></span>
                                    <span style="color:var(--text-muted);font-size:11px;flex-shrink:0;">(₱<?php echo number_format((float)$eq['unit_price'], 2); ?>)</span>
                                </label>
                                <input type="number" name="edit_equipment_qty[<?php echo (int)$eq['id']; ?>]" id="edit_eq_qty_<?php echo (int)$eq['id']; ?>" class="edit-equipment-qty form-control" style="width:64px;flex-shrink:0;" min="1" value="1" disabled data-item-id="<?php echo (int)$eq['id']; ?>">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- ── Pesticide Section (Edit) ── -->
                    <div class="pesticide-section">
                        <div class="pesticide-section-title">
                            <i class="fas fa-flask"></i> Pesticide / Product Used
                        </div>
                        <div class="form-row">
                            <div class="form-group" style="margin-bottom:14px;">
                                <label class="form-label">Product / Chemical Name</label>
                                <input type="text" name="edit_pesticide_name" id="edit_pesticide_name" class="form-control" placeholder="e.g., Bifenthrin" maxlength="255">
                            </div>
                            <div class="form-group" style="margin-bottom:14px;">
                                <label class="form-label">Brand</label>
                                <input type="text" name="edit_pesticide_brand" id="edit_pesticide_brand" class="form-control" placeholder="e.g., Syngenta" maxlength="255">
                            </div>
                        </div>
                        <div class="form-group" style="margin-bottom:14px;">
                            <label class="form-label">Pesticide Type</label>
                            <div class="pesticide-type-grid" id="edit-pest-type-grid">
                                <button type="button" class="pest-type-btn" onclick="selectPestType('edit','Insecticide',this)"><i class="fas fa-bug"></i> Insecticide</button>
                                <button type="button" class="pest-type-btn" onclick="selectPestType('edit','Termiticide',this)"><i class="fas fa-house-damage"></i> Termiticide</button>
                                <button type="button" class="pest-type-btn" onclick="selectPestType('edit','Rodenticide',this)"><i class="fas fa-paw"></i> Rodenticide</button>
                                <button type="button" class="pest-type-btn" onclick="selectPestType('edit','Fungicide',this)"><i class="fas fa-seedling"></i> Fungicide</button>
                                <button type="button" class="pest-type-btn" onclick="selectPestType('edit','Repellent',this)"><i class="fas fa-shield-alt"></i> Repellent</button>
                                <button type="button" class="pest-type-btn" onclick="selectPestType('edit','Other',this)"><i class="fas fa-flask"></i> Other</button>
                            </div>
                            <input type="hidden" name="edit_pesticide_type" id="edit-pesticide-type" value="">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Application Notes / Safety Info</label>
                            <textarea name="edit_pesticide_notes" id="edit_pesticide_notes" class="form-control" style="min-height:70px;" placeholder="e.g., Keep children and pets away for 4 hours after application."></textarea>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Tab: Payment Settings -->
            <div class="tab-panel" id="pay-panel">
                <form method="POST" action="<?php echo appUrl('services.php'); ?>" id="paymentForm">
                    <input type="hidden" name="save_payment" value="1">
                    <input type="hidden" name="pay_service_id" id="pay_service_id" value="">
                    <div class="pay-lock-note" id="pay-lock-note"></div>
                    <p style="font-size:13px; color:var(--text-muted); margin-bottom:16px;">Choose how customers can pay for <strong id="pay-service-label">this service</strong>.</p>
                    <div class="pay-toggle">
                        <button type="button" class="pay-toggle-btn active-pay" id="btn-full" onclick="setPayType('full')">
                            <i class="fas fa-money-bill-wave"></i> Full Payment
                        </button>
                        <button type="button" class="pay-toggle-btn" id="btn-down" onclick="setPayType('downpayment')">
                            <i class="fas fa-hand-holding-usd"></i> Downpayment
                        </button>
                    </div>
                    <input type="hidden" name="payment_type" id="payment_type" value="full">
                    <div class="dp-section" id="dp-section" style="display:none;">
                        <label class="form-label" style="margin-bottom:10px;">Downpayment Amount</label>
                        <div class="dp-mode-row">
                            <button type="button" class="dp-mode-btn active-mode" id="btn-pct" onclick="setDpMode('percent')">% Percentage</button>
                            <button type="button" class="dp-mode-btn" id="btn-fixed" onclick="setDpMode('fixed')">&#8369; Fixed Amount</button>
                        </div>
                        <input type="hidden" name="dp_mode" id="dp_mode" value="percent">
                        <div id="pct-input">
                            <label class="form-label">Percentage (%)</label>
                            <input type="number" name="dp_percent" id="dp_percent" class="form-control" min="1" max="99" step="1" value="50" oninput="updatePreview()">
                        </div>
                        <div id="fixed-input" style="display:none;">
                            <label class="form-label">Fixed Amount (&#8369;)</label>
                            <input type="number" name="dp_fixed" id="dp_fixed" class="form-control" min="1" step="0.01" value="0" oninput="updatePreview()">
                        </div>
                        <div class="dp-preview" id="dp-preview">
                            Customer pays <strong id="dp-preview-val">�</strong> upfront before the service begins.
                        </div>
                    </div>
                    <div id="full-info" style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:14px 16px; font-size:13px; color:#15803d; margin-top:4px;">
                        <i class="fas fa-check-circle" style="margin-right:6px;"></i> Customer pays the <strong>full amount</strong> before the service begins.
                    </div>
                </form>
            </div>

        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-secondary" id="cancelSettings">Cancel</button>
            <button type="button" class="btn btn-primary" id="saveSettingsBtn"><i class="fas fa-save"></i> Save Changes</button>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
    const providerCompanyName = <?php echo json_encode((string)($provider['company_name'] ?? 'Provider')); ?>;
    const addServiceSubmitSucceeded = <?php echo json_encode(!empty($add_success)); ?>;
    // Scoped to this provider's own user id — without this, a shared browser
    // (a second provider logging in later on the same computer) would see
    // and get offered the first provider's unfinished draft.
    const ADD_SERVICE_DRAFT_KEY = 'pestify.add_service_draft.v1.' + <?php echo json_encode((string)$_SESSION['user_id']); ?>;
    const firstTimeGuide = document.getElementById('firstTimeGuide');
    const firstTimeGuideKey = firstTimeGuide ? String(firstTimeGuide.dataset.storageKey || '') : '';

    // ── Add Modal (multi-step) ──────────────────────────────────
    const addModal  = document.getElementById('addModal');
    const openBtn   = document.getElementById('openModalBtn');
    const openBtn2  = document.getElementById('openModalBtn2');
    const closeBtn  = document.getElementById('closeModal');
    const cancelBtn = document.getElementById('cancelModal');
    const addServiceForm = document.getElementById('addServiceForm');
    const stepConfirmModal = document.getElementById('stepConfirmModal');
    const stepConfirmMessage = document.getElementById('stepConfirmMessage');
    const closeStepConfirmModal = document.getElementById('closeStepConfirmModal');
    const stepConfirmCancelBtn = document.getElementById('stepConfirmCancelBtn');
    const stepConfirmProceedBtn = document.getElementById('stepConfirmProceedBtn');

    let currentStep = 1;
    let pendingStepAction = null;
    let sigCanvas = null, sigCtx = null, isDrawing = false, hasSigned = false;

    function getAddServiceField(name) {
        return document.querySelector('#addServiceForm [name="' + name + '"]');
    }

    function setFieldValue(selector, value) {
        const el = document.querySelector(selector);
        if (el) el.value = value ?? '';
    }

    function isAddDraftMeaningful(draft) {
        if (!draft || typeof draft !== 'object') return false;
        const hasText = [
            draft.service_name,
            draft.category,
            draft.price,
            draft.description,
            draft.pesticide_name,
            draft.pesticide_brand,
            draft.pesticide_type,
            draft.pesticide_notes,
            draft.contract_text,
            draft.hidden_contract_text,
            draft.hidden_contract_signature
        ].some(v => String(v || '').trim() !== '');

        const hasOptionChanges =
            (draft.pricing_type && draft.pricing_type !== 'fixed') ||
            (draft.status && draft.status !== 'active') ||
            (draft.contract_template && draft.contract_template !== 'manual') ||
            !!draft.emergency ||
            !!draft.eco_friendly ||
            (parseInt(draft.current_step || 1, 10) > 1);

        return hasText || hasOptionChanges;
    }

    function getAddServiceDraftPayload() {
        return {
            service_name: document.getElementById('add_service_name')?.value || '',
            category: document.getElementById('add_category')?.value || '',
            pricing_type: getAddServiceField('pricing_type')?.value || 'fixed',
            price: document.getElementById('add_price')?.value || '',
            description: document.getElementById('add_description')?.value || '',
            pesticide_name: getAddServiceField('pesticide_name')?.value || '',
            pesticide_brand: getAddServiceField('pesticide_brand')?.value || '',
            pesticide_type: document.getElementById('add-pesticide-type')?.value || '',
            pesticide_notes: getAddServiceField('pesticide_notes')?.value || '',
            status: getAddServiceField('status')?.value || 'active',
            emergency: !!getAddServiceField('emergency')?.checked,
            eco_friendly: !!getAddServiceField('eco_friendly')?.checked,
            contract_template: document.getElementById('contractTemplateSelect')?.value || 'manual',
            contract_text: document.getElementById('contractEditor')?.value || '',
            hidden_contract_text: document.getElementById('hidden_contract_text')?.value || '',
            hidden_contract_signature: document.getElementById('hidden_contract_signature')?.value || '',
            current_step: currentStep
        };
    }

    function saveAddServiceDraft() {
        const draft = getAddServiceDraftPayload();
        try {
            if (!isAddDraftMeaningful(draft)) {
                localStorage.removeItem(ADD_SERVICE_DRAFT_KEY);
                return;
            }
            localStorage.setItem(ADD_SERVICE_DRAFT_KEY, JSON.stringify(draft));
        } catch (_) {}
    }

    function readAddServiceDraft() {
        try {
            const raw = localStorage.getItem(ADD_SERVICE_DRAFT_KEY);
            if (!raw) return null;
            const parsed = JSON.parse(raw);
            if (!isAddDraftMeaningful(parsed)) return null;
            return parsed;
        } catch (_) {
            return null;
        }
    }

    function clearAddServiceDraft() {
        try { localStorage.removeItem(ADD_SERVICE_DRAFT_KEY); } catch (_) {}
    }

    function applyPesticideTypeSelection(prefix, selectedType) {
        const grid = document.getElementById(prefix + '-pest-type-grid');
        if (!grid) return;
        grid.querySelectorAll('.pest-type-btn').forEach(btn => btn.classList.remove('selected'));
        if (!selectedType) return;
        const wanted = selectedType.toLowerCase();
        const match = Array.from(grid.querySelectorAll('.pest-type-btn')).find(btn =>
            btn.textContent.toLowerCase().includes(wanted)
        );
        if (match) match.classList.add('selected');
    }

    // Custom Quote can't be charged upfront — it automatically
    // requires an on-site inspection so the field technician can set the
    // final price afterward (see CLAUDE.md's "Recent Work Log": pricing
    // model was previously collected but never saved anywhere, and even if
    // saved nothing downstream branched on it — this makes it functional).
    const REQUIRES_INSPECTION_DEFAULT_HINT = 'Seeker requests will collect only an Inspection Date and an estimated price. A field technician must submit an inspection report (photo, notes, and a final price) before the seeker agrees to a Working Date and payment is collected.';
    function onPricingTypeChange(prefix) {
        const select = document.getElementById(prefix + '_pricing_type');
        const checkbox = document.getElementById(prefix + '_requires_inspection');
        const hint = document.getElementById(prefix + '_requires_inspection_hint');
        const priceLabel = document.getElementById(prefix + '_price_label');
        if (!select || !checkbox) return;
        const isFixed = select.value === 'fixed';
        if (!isFixed) {
            checkbox.checked = true;
            checkbox.disabled = true;
            const modelLabel = select.options[select.selectedIndex]?.text || 'this pricing model';
            if (hint) hint.innerHTML = '<strong>Required for ' + modelLabel + '.</strong> The price below is only an estimate — the field technician sets the final price after inspection, and the seeker agrees before anything is charged.';
            if (priceLabel) priceLabel.textContent = 'Estimated Price (₱) *';
        } else {
            checkbox.disabled = false;
            if (hint) hint.textContent = REQUIRES_INSPECTION_DEFAULT_HINT;
            if (priceLabel) priceLabel.textContent = 'Price (₱) *';
        }
    }

    function resetAddServiceFormState() {
        if (addServiceForm) addServiceForm.reset();
        setFieldValue('#add-pesticide-type', '');
        applyPesticideTypeSelection('add', '');
        setFieldValue('#hidden_contract_signature', '');
        setFieldValue('#hidden_contract_text', '');
        setFieldValue('#contractEditor', '');
        setFieldValue('#contractTemplateSelect', 'manual');
        const finalPreview = document.getElementById('finalContractPreview');
        if (finalPreview) finalPreview.innerHTML = '';
        hasSigned = false;
        const sigPlaceholder = document.getElementById('sigPlaceholder');
        if (sigPlaceholder) sigPlaceholder.style.display = 'flex';
        const sigStatus = document.getElementById('sigStatus');
        if (sigStatus) sigStatus.innerHTML = '<i class="fas fa-pen" style="margin-right:6px;"></i> Not signed yet';
    }

    function restoreAddServiceDraft() {
        const draft = readAddServiceDraft();
        if (!draft) return null;

        setFieldValue('#add_service_name', draft.service_name);
        setFieldValue('#add_category', draft.category);
        setFieldValue('#add_price', draft.price);
        setFieldValue('#add_description', draft.description);
        setFieldValue('#addServiceForm [name="pricing_type"]', draft.pricing_type || 'fixed');
        setFieldValue('#addServiceForm [name="pesticide_name"]', draft.pesticide_name);
        setFieldValue('#addServiceForm [name="pesticide_brand"]', draft.pesticide_brand);
        setFieldValue('#addServiceForm [name="pesticide_notes"]', draft.pesticide_notes);
        setFieldValue('#addServiceForm [name="status"]', draft.status || 'active');
        setFieldValue('#add-pesticide-type', draft.pesticide_type);
        applyPesticideTypeSelection('add', draft.pesticide_type || '');

        const emergencyInput = getAddServiceField('emergency');
        if (emergencyInput) emergencyInput.checked = !!draft.emergency;
        const ecoInput = getAddServiceField('eco_friendly');
        if (ecoInput) ecoInput.checked = !!draft.eco_friendly;

        setFieldValue('#contractTemplateSelect', draft.contract_template || 'manual');
        const restoredContractText = draft.contract_text || draft.hidden_contract_text || '';
        setFieldValue('#contractEditor', restoredContractText);
        setFieldValue('#hidden_contract_text', restoredContractText);
        setFieldValue('#hidden_contract_signature', draft.hidden_contract_signature || '');

        const finalPreview = document.getElementById('finalContractPreview');
        if (finalPreview) finalPreview.innerHTML = '';
        hasSigned = !!(draft.hidden_contract_signature && String(draft.hidden_contract_signature).trim() !== '');

        const restoredStep = parseInt(draft.current_step || 1, 10);
        return Number.isFinite(restoredStep) ? Math.min(3, Math.max(1, restoredStep)) : 1;
    }

    function openAdd() {
        addModal.classList.add('open');
        document.body.style.overflow = 'hidden';
        resetAddServiceFormState();
        goToStep(1);
        // Never auto-fills — only offers a Restore/Start Blank choice when a
        // draft from an earlier unfinished attempt actually exists, so nothing
        // fills in silently.
        const draftBanner = document.getElementById('draftRestoreBanner');
        if (draftBanner) draftBanner.style.display = readAddServiceDraft() ? 'flex' : 'none';
    }
    function closeAdd() {
        addModal.classList.remove('open');
        closeStepConfirm();
        document.body.style.overflow = '';
        currentStep = 1;
    }

    function hideFirstTimeGuide(remember) {
        if (!firstTimeGuide) return;
        firstTimeGuide.style.display = 'none';
        if (remember && firstTimeGuideKey) {
            try { localStorage.setItem(firstTimeGuideKey, '1'); } catch (_) {}
        }
    }

    if (openBtn)  openBtn.addEventListener('click', openAdd);
    if (openBtn2) openBtn2.addEventListener('click', openAdd);
    if (firstTimeGuide && firstTimeGuideKey) {
        try {
            if (localStorage.getItem(firstTimeGuideKey) === '1') {
                firstTimeGuide.style.display = 'none';
            }
        } catch (_) {}
    }
    const guideStartBtn = document.getElementById('guideStartBtn');
    if (guideStartBtn) {
        guideStartBtn.addEventListener('click', function () {
            hideFirstTimeGuide(true);
            openAdd();
        });
    }
    const guideDismissBtn = document.getElementById('guideDismissBtn');
    if (guideDismissBtn) {
        guideDismissBtn.addEventListener('click', function () {
            hideFirstTimeGuide(true);
        });
    }
    closeBtn.addEventListener('click', closeAdd);
    cancelBtn.addEventListener('click', closeAdd);
    addModal.addEventListener('click', e => { if (e.target === addModal) closeAdd(); });

    if (addServiceForm) {
        addServiceForm.querySelectorAll('input, select, textarea').forEach(el => {
            el.addEventListener('input', saveAddServiceDraft);
            el.addEventListener('change', saveAddServiceDraft);
        });
    }

    const draftRestoreBtn = document.getElementById('draftRestoreBtn');
    const draftDismissBtn = document.getElementById('draftDismissBtn');
    if (draftRestoreBtn) {
        draftRestoreBtn.addEventListener('click', function () {
            const restoredStep = restoreAddServiceDraft();
            document.getElementById('draftRestoreBanner').style.display = 'none';
            goToStep(restoredStep || 1);
        });
    }
    if (draftDismissBtn) {
        draftDismissBtn.addEventListener('click', function () {
            clearAddServiceDraft();
            document.getElementById('draftRestoreBanner').style.display = 'none';
        });
    }

    // Draft restore is now only ever offered through the banner above, on
    // manual "Add Service" click — the modal never opens itself on page load.
    if (addServiceSubmitSucceeded) {
        clearAddServiceDraft();
    }

    function openStepConfirm(message, onProceed) {
        pendingStepAction = onProceed;
        stepConfirmMessage.textContent = message;
        stepConfirmModal.classList.add('open');
    }

    function closeStepConfirm() {
        stepConfirmModal.classList.remove('open');
        pendingStepAction = null;
    }

    closeStepConfirmModal.addEventListener('click', closeStepConfirm);
    stepConfirmCancelBtn.addEventListener('click', closeStepConfirm);
    stepConfirmModal.addEventListener('click', e => { if (e.target === stepConfirmModal) closeStepConfirm(); });
    stepConfirmProceedBtn.addEventListener('click', function () {
        const action = pendingStepAction;
        closeStepConfirm();
        if (typeof action === 'function') action();
    });

    // ── Signature Confirmation Modal ──────────────────────────────
    const sigConfirmModal = document.getElementById('sigConfirmModal');
    const closeSigConfirmModal = document.getElementById('closeSigConfirmModal');

    function closeSigConfirm() {
        sigConfirmModal.classList.remove('open');
    }

    closeSigConfirmModal.addEventListener('click', closeSigConfirm);
    sigConfirmModal.addEventListener('click', e => { if (e.target === sigConfirmModal) closeSigConfirm(); });

    document.getElementById('sigConfirmNoBtn').addEventListener('click', function () {
        closeSigConfirm();
        clearSignature();
    });

    document.getElementById('sigConfirmYesBtn').addEventListener('click', function () {
        const contractText = getContractEditorText();
        if (!contractText) {
            closeSigConfirm();
            alert('Please write your contract agreement before continuing.');
            return;
        }
        closeSigConfirm();
        syncContractTextFromEditor();
        document.getElementById('hidden_contract_signature').value = sigCanvas.toDataURL('image/png');
        goToStep(3);
    });

    function goToStep(n) {
        currentStep = n;
        document.querySelectorAll('.add-step').forEach((el, i) => {
            el.classList.toggle('active', i + 1 === n);
        });
        // Step pills
        [1,2,3].forEach(i => {
            const pill = document.getElementById('pill-' + i);
            pill.classList.remove('active','done');
            if (i === n) pill.classList.add('active');
            else if (i < n) pill.classList.add('done');
        });
        [1,2].forEach(i => {
            document.getElementById('line-' + i)?.classList.toggle('done', i < n);
        });
        // Buttons
        document.getElementById('btnStepBack').style.display  = n > 1  ? 'inline-flex' : 'none';
        document.getElementById('btnStepNext').style.display  = n === 1 ? 'inline-flex' : 'none';
        document.getElementById('btnPublish').style.display   = n === 3 ? 'inline-flex' : 'none';

        if (n === 2) {
            buildContractPreview();
            initSignaturePad();
        }
        if (n === 3) buildFinalContractPreview();
        saveAddServiceDraft();
    }

    function addStepNext() {
        if (currentStep === 1) {
            // Validate step 1
            const name  = document.getElementById('add_service_name').value.trim();
            const cat   = document.getElementById('add_category').value;
            const price = document.getElementById('add_price').value;
            if (!name)  { alert('Please enter a service name.'); return; }
            if (!cat)   { alert('Please select a service type.'); return; }
            if (!price || parseFloat(price) < 0) { alert('Please enter a valid price.'); return; }
            openStepConfirm('Are you sure you want to proceed to Contract and Signature?', () => goToStep(2));
            return;
        } else if (currentStep === 2) {
            const contractText = getContractEditorText();
            if (!contractText) {
                alert('Please write your contract agreement before continuing.');
                return;
            }
            if (!hasSigned) {
                alert('Please draw your signature at the bottom of the contract before continuing.');
                return;
            }
            openStepConfirm('Are you sure you want to proceed to Final Review?', () => {
                syncContractTextFromEditor();
                document.getElementById('hidden_contract_signature').value = sigCanvas.toDataURL('image/png');
                goToStep(3);
            });
            return;
        }
    }
    function addStepBack() {
        if (currentStep > 1) goToStep(currentStep - 1);
    }

    function getContractDraftValues() {
        const name = (document.getElementById('add_service_name')?.value || '').trim() || '[Service Name]';
        const priceRaw = parseFloat(document.getElementById('add_price')?.value || '0');
        const price = isNaN(priceRaw) ? '[Price]' : ('PHP ' + priceRaw.toFixed(2));
        const category = (document.getElementById('add_category')?.value || '').trim() || '[Service Type]';
        const today = new Date().toLocaleDateString('en-PH', { year:'numeric', month:'long', day:'numeric' });

        return { name, price, category, today };
    }

    function buildContractTemplateText(templateKey) {
        const v = getContractDraftValues();

        const templates = {
            manual: ``,
            standard: `1. PARTIES
This Service Contract Agreement is made on ${v.today} between ${providerCompanyName} ("Provider") and the Customer ("Seeker").

2. SERVICE DETAILS
Service Name: ${v.name}
Service Type: ${v.category}
Contract Price: ${v.price}

3. SCOPE OF WORK
The Provider agrees to perform the stated pest control service according to professional standards, including inspection, treatment, and recommendations.

4. CUSTOMER RESPONSIBILITIES
The Seeker agrees to provide safe access to the property, remove sensitive items when advised, and follow safety instructions after treatment.

5. PAYMENT TERMS
The Seeker agrees to pay the agreed amount based on the payment settings configured for this service.

6. WARRANTY / LIMITATION
Results may vary depending on infestation severity and environmental factors. Warranty coverage, if offered, follows the service terms provided by the Provider.

7. SAFETY
Both parties agree to follow pesticide safety protocols, including temporary evacuation and post-treatment precautions when necessary.

8. SIGNATURES
This agreement is validated by the Provider e-signature below and Seeker confirmation upon booking.`,

            termite: `1. PARTIES
This Termite Treatment Agreement is made on ${v.today} between ${providerCompanyName} ("Provider") and the Customer ("Seeker").

2. SERVICE DETAILS
Service Name: ${v.name}
Service Type: ${v.category}
Contract Price: ${v.price}

3. TERMITE TREATMENT SCOPE
The Provider will conduct inspection, identify termite activity points, apply appropriate treatment, and provide prevention recommendations.

4. SITE PREPARATION
The Seeker shall provide access to treatment zones, including affected walls, flooring edges, and external perimeter areas as required.

5. FOLLOW-UP AND WARRANTY
Follow-up schedule and warranty coverage shall depend on inspection findings and treatment package selected by the Seeker.

6. EXCLUSIONS
Provider is not liable for reinfestation caused by structural defects, untreated adjacent properties, flooding, or unauthorized chemical use.

7. SIGNATURES
This agreement is validated by the Provider e-signature below and Seeker confirmation upon booking.`,

            emergency: `1. PARTIES
This Emergency Pest Service Agreement is made on ${v.today} between ${providerCompanyName} ("Provider") and the Customer ("Seeker").

2. SERVICE DETAILS
Service Name: ${v.name}
Service Type: ${v.category}
Emergency Service Fee: ${v.price}

3. RESPONSE TERMS
Provider will prioritize this request for immediate handling based on current availability and site safety conditions.

4. CUSTOMER ACKNOWLEDGEMENT
The Seeker understands emergency services may include after-hours scheduling and expedited treatment procedures.

5. PAYMENT
The Seeker agrees to settle the required payment under the configured emergency payment terms before or during service execution.

6. SAFETY AND ACCESS
The Seeker must ensure access to affected areas and disclose presence of children, elderly, pets, and health-sensitive occupants.

7. SIGNATURES
This agreement is validated by the Provider e-signature below and Seeker confirmation upon booking.`,

            maintenance: `1. PARTIES
This Preventive Maintenance Service Agreement is made on ${v.today} between ${providerCompanyName} ("Provider") and the Customer ("Seeker").

2. SERVICE DETAILS
Service Name: ${v.name}
Service Type: ${v.category}
Service Fee: ${v.price}

3. MAINTENANCE PLAN
Provider will perform scheduled pest prevention treatment, routine monitoring, and service reporting based on agreed intervals.

4. CUSTOMER RESPONSIBILITIES
Seeker agrees to keep treatment areas accessible and report recurring pest activity before each scheduled visit.

5. RESCHEDULING
Requested schedule changes should be made in advance; missed appointments may be moved to the next available maintenance window.

6. SERVICE LIMITATIONS
Preventive treatment lowers risk but does not guarantee zero pest activity due to external and environmental conditions.

7. SIGNATURES
This agreement is validated by the Provider e-signature below and Seeker confirmation upon booking.`
        };

        return templates[templateKey] || templates.manual;
    }

    function loadContractTemplate(append, forceOverwrite) {
        const editor = document.getElementById('contractEditor');
        if (!editor) return;

        const templateKey = document.getElementById('contractTemplateSelect')?.value || 'standard';
        const templateText = buildContractTemplateText(templateKey);
        const currentText = (editor.value || '').trim();

        if (templateKey === 'manual' && !append) {
            editor.value = '';
            syncContractTextFromEditor();
            const finalPreviewManual = document.getElementById('finalContractPreview');
            if (finalPreviewManual) finalPreviewManual.innerHTML = '';
            saveAddServiceDraft();
            return;
        }

        if (append) {
            editor.value = currentText ? (currentText + '\n\n' + templateText) : templateText;
        } else {
            if (!forceOverwrite && currentText && !confirm('Replace the current contract text with the selected template?')) {
                return;
            }
            editor.value = templateText;
        }

        syncContractTextFromEditor();
        const finalPreview = document.getElementById('finalContractPreview');
        if (finalPreview) finalPreview.innerHTML = '';
        saveAddServiceDraft();
    }

    // ── Provider-written contract builder ────────────────────────────────────────
    function getContractEditorText() {
        const editor = document.getElementById('contractEditor');
        return (editor?.value || '').trim();
    }

    function syncContractTextFromEditor() {
        document.getElementById('hidden_contract_text').value = getContractEditorText();
        updateSignatureLock();
    }

    // Visually locks the signature canvas until contract text exists, so a
    // provider can never reach "signed, but rejected because the contract
    // is blank" — the default template is "Manual (Start Blank)", so this
    // is the common path for anyone who scrolls straight to the signature
    // box without noticing the empty editor above it.
    function updateSignatureLock() {
        const canvas = document.getElementById('signatureCanvas');
        const placeholder = document.getElementById('sigPlaceholder');
        if (!canvas) return;
        const hasText = !!getContractEditorText();
        canvas.style.pointerEvents = hasText ? 'auto' : 'none';
        canvas.style.opacity = hasText ? '1' : '0.5';
        canvas.style.cursor = hasText ? 'crosshair' : 'not-allowed';
        if (placeholder && !hasSigned) {
            placeholder.innerHTML = hasText
                ? '<i class="fas fa-signature" style="margin-right:8px; font-size:22px;"></i> Sign here'
                : '<i class="fas fa-lock" style="margin-right:8px; font-size:18px;"></i> Write your contract above first';
        }
    }

    function buildContractDisplayHtml(contractText) {
        const safeContract = escapeHtml(contractText || 'No contract agreement provided.');
        return `
            <div style="text-align:center; margin-bottom:16px;">
                <div style="font-size:18px; font-weight:800; color:#1a1a2e; letter-spacing:0.5px;">SERVICE CONTRACT AGREEMENT</div>
            </div>
            <hr style="border:none; border-top:1px solid #e2e8f0; margin:12px 0 16px;">
            <div style="font-size:13px; color:#334155; line-height:1.9; white-space:pre-wrap;">${safeContract}</div>
        `;
    }

    const contractEditorInput = document.getElementById('contractEditor');
    if (contractEditorInput) {
        contractEditorInput.addEventListener('input', function () {
            syncContractTextFromEditor();
            const finalPreview = document.getElementById('finalContractPreview');
            if (finalPreview) finalPreview.innerHTML = '';
            saveAddServiceDraft();
        });
    }

    const contractTemplateSelectInput = document.getElementById('contractTemplateSelect');
    if (contractTemplateSelectInput) {
        contractTemplateSelectInput.addEventListener('change', function () {
            loadContractTemplate(false, true);
            saveAddServiceDraft();
        });
    }

    function buildContractPreview() {
        const name  = document.getElementById('add_service_name').value.trim();
        const price = parseFloat(document.getElementById('add_price').value || '0');
        const today = new Date().toLocaleDateString('en-PH', { year:'numeric', month:'long', day:'numeric' });
        const contractEditor = document.getElementById('contractEditor');
        const hiddenContract = document.getElementById('hidden_contract_text').value;

        if (contractEditor && !contractEditor.value.trim() && hiddenContract.trim()) {
            contractEditor.value = hiddenContract;
        }
        if (contractEditor && !contractEditor.value.trim()) {
            const selectedTemplate = document.getElementById('contractTemplateSelect')?.value || 'manual';
            if (selectedTemplate !== 'manual') {
                loadContractTemplate(false, true);
            }
        }

        syncContractTextFromEditor();

        // Summary only (contract body is provider-written)
        document.getElementById('sum-name').textContent  = name || '�';
        document.getElementById('sum-price').textContent = '\u20B1' + (isNaN(price) ? '0.00' : price.toFixed(2));
        document.getElementById('sum-date').textContent  = today;
    }

    function buildFinalContractPreview() {
        syncContractTextFromEditor();
        const contractText = getContractEditorText();
        const signatureData = document.getElementById('hidden_contract_signature').value;
        const signedOn = new Date().toLocaleString('en-PH', { year:'numeric', month:'long', day:'numeric', hour:'2-digit', minute:'2-digit' });
        const seekerReserveHTML = `
            <div style="margin-top:24px;">
                <div style="font-size:12px; color:#64748b; margin-bottom:8px;">Seeker E-Signature (Reserved)</div>
                <div style="width:260px; border-bottom:1px solid #cbd5e1; height:32px;"></div>
                <div style="font-size:11px; color:#64748b; margin-top:8px;">To be signed by seeker upon agreement.</div>
            </div>`;
        const signatureHTML = signatureData
            ? `<div style="margin-top:28px;">
                    <div style="font-size:12px; color:#64748b; margin-bottom:8px;">Provider E-Signature</div>
                    <img src="${signatureData}" alt="Signature" style="max-width:260px; max-height:90px; border-bottom:1px solid #cbd5e1; padding-bottom:4px;">
                    <div style="font-size:11px; color:#0f172a; margin-top:8px; font-weight:600;">${providerCompanyName || 'Provider'}</div>
                    <div style="font-size:11px; color:#64748b; margin-top:6px;">Signed on ${signedOn}</div>
               </div>${seekerReserveHTML}`
            : `<div style="margin-top:28px; font-size:12px; color:#b91c1c;">No e-signature captured.</div>${seekerReserveHTML}`;

        document.getElementById('finalContractPreview').innerHTML = buildContractDisplayHtml(contractText) + signatureHTML;
    }

    function getContractPDFContent() {
        const hasFinalPreview = document.getElementById('finalContractPreview') && document.getElementById('finalContractPreview').innerHTML.trim() !== '';
        const contractText = getContractEditorText() || (document.getElementById('hidden_contract_text')?.value || '');
        const baseContract = buildContractDisplayHtml(contractText);
        const signatureData = document.getElementById('hidden_contract_signature').value || (hasSigned && sigCanvas ? sigCanvas.toDataURL('image/png') : '');
        const seekerReserveHTML = `
            <div style="margin-top:24px;">
                <div style="font-size:12px; color:#64748b; margin-bottom:8px;">Seeker E-Signature (Reserved)</div>
                <div style="width:260px; border-bottom:1px solid #cbd5e1; height:32px;"></div>
                <div style="font-size:11px; color:#64748b; margin-top:8px;">To be signed by seeker upon agreement.</div>
            </div>`;
        const signatureHTML = signatureData
            ? `<div style="margin-top:28px;">
                    <div style="font-size:12px; color:#64748b; margin-bottom:8px;">Provider E-Signature</div>
                    <img src="${signatureData}" alt="Signature" style="max-width:260px; max-height:90px; border-bottom:1px solid #cbd5e1; padding-bottom:4px;">
                    <div style="font-size:11px; color:#0f172a; margin-top:8px; font-weight:600;">${providerCompanyName || 'Provider'}</div>
               </div>${seekerReserveHTML}`
            : seekerReserveHTML;
        return hasFinalPreview ? document.getElementById('finalContractPreview').innerHTML : (baseContract + signatureHTML);
    }

    function getContractPlainText() {
        const editorText = getContractEditorText();
        if (editorText) return editorText;

        const hiddenText = (document.getElementById('hidden_contract_text')?.value || '').trim();
        if (hiddenText) return hiddenText;

        const finalText = (document.getElementById('finalContractPreview')?.innerText || '').trim();
        return finalText;
    }

    function buildContractPdfBlob() {
        if (!(window.jspdf && window.jspdf.jsPDF)) return null;

        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation: 'p', unit: 'pt', format: 'a4' });
        const pageWidth = doc.internal.pageSize.getWidth();
        const pageHeight = doc.internal.pageSize.getHeight();
        const margin = 48;
        const maxWidth = pageWidth - (margin * 2);
        let y = 56;

        doc.setFont('helvetica', 'bold');
        doc.setFontSize(16);
        doc.text('SERVICE CONTRACT AGREEMENT', pageWidth / 2, y, { align: 'center' });
        y += 28;

        doc.setFont('helvetica', 'normal');
        doc.setFontSize(11);
        const plainText = getContractPlainText() || 'No contract content available.';
        const lines = doc.splitTextToSize(plainText, maxWidth);

        lines.forEach(line => {
            if (y > pageHeight - margin) {
                doc.addPage();
                y = margin;
            }
            doc.text(line, margin, y);
            y += 15;
        });

        const signatureData = document.getElementById('hidden_contract_signature').value || (hasSigned && sigCanvas ? sigCanvas.toDataURL('image/png') : '');
        if (signatureData) {
            if (y > pageHeight - 150) {
                doc.addPage();
                y = margin;
            }
            y += 10;
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(12);
            doc.text('Provider E-Signature', margin, y);
            y += 10;
            doc.addImage(signatureData, 'PNG', margin, y, 190, 60);
            y += 76;
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(10);
            doc.text(providerCompanyName || 'Provider', margin, y);
            y += 14;
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(10);
            const signedOn = new Date().toLocaleString('en-PH', { year:'numeric', month:'long', day:'numeric', hour:'2-digit', minute:'2-digit' });
            doc.text(`Signed on ${signedOn}`, margin, y);
            y += 24;
        }

        if (y > pageHeight - 90) {
            doc.addPage();
            y = margin;
        }
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(12);
        doc.text('Seeker E-Signature (Reserved)', margin, y);
        y += 14;
        doc.setLineWidth(0.8);
        doc.line(margin, y, margin + 240, y);
        y += 16;
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10);
        doc.text('To be signed by seeker upon agreement.', margin, y);

        return doc.output('blob');
    }

    function viewContractPDF() {
        const blob = buildContractPdfBlob();
        if (blob) {
            const blobUrl = URL.createObjectURL(blob);
            window.open(blobUrl, '_blank');
            setTimeout(() => URL.revokeObjectURL(blobUrl), 60000);
            return;
        }

        // Fallback if PDF library fails to load
        const content = getContractPDFContent();
        const win = window.open('', '_blank');
        win.document.write(`<!DOCTYPE html><html><head><title>Service Contract PDF View</title>
        <style>body{font-family:Arial,sans-serif;padding:40px;color:#1a1a2e;font-size:13px;line-height:1.8;}
        h1{font-size:20px;text-align:center;}hr{border:none;border-top:1px solid #ddd;margin:16px 0;}
        p{margin-bottom:10px;}</style></head><body>${content}</body></html>`);
        win.document.close();
    }

    function generateContractPDF() {
        const content = getContractPDFContent();
        const win = window.open('', '_blank');
        win.document.write(`<!DOCTYPE html><html><head><title>Service Contract</title>
        <style>body{font-family:Arial,sans-serif;padding:40px;color:#1a1a2e;font-size:13px;line-height:1.8;}
        h1{font-size:20px;text-align:center;}hr{border:none;border-top:1px solid #ddd;margin:16px 0;}
        p{margin-bottom:10px;}</style></head><body>${content}
        <script>window.onload=function(){window.print();}<\/script></body></html>`);
        win.document.close();
    }

    // ── Signature Pad ───────────────────────────────────────────
    function initSignaturePad() {
        sigCanvas = document.getElementById('signatureCanvas');
        // Match canvas resolution to display size
        const rect = sigCanvas.getBoundingClientRect();
        sigCanvas.width  = rect.width  * window.devicePixelRatio;
        sigCanvas.height = rect.height * window.devicePixelRatio;
        sigCtx = sigCanvas.getContext('2d');
        sigCtx.scale(window.devicePixelRatio, window.devicePixelRatio);
        sigCtx.strokeStyle = '#1a1a2e';
        sigCtx.lineWidth   = 2.5;
        sigCtx.lineCap     = 'round';
        sigCtx.lineJoin    = 'round';

        // Remove old listeners by cloning
        const newCanvas = sigCanvas.cloneNode(true);
        sigCanvas.parentNode.replaceChild(newCanvas, sigCanvas);
        sigCanvas = newCanvas;
        sigCtx = sigCanvas.getContext('2d');
        sigCtx.scale(window.devicePixelRatio, window.devicePixelRatio);
        sigCtx.strokeStyle = '#1a1a2e';
        sigCtx.lineWidth   = 2.5;
        sigCtx.lineCap     = 'round';
        sigCtx.lineJoin    = 'round';

        // Mouse
        sigCanvas.addEventListener('mousedown',  e => startDraw(e.offsetX, e.offsetY));
        sigCanvas.addEventListener('mousemove',  e => { if (isDrawing) draw(e.offsetX, e.offsetY); });
        sigCanvas.addEventListener('mouseup',    () => endDraw());
        sigCanvas.addEventListener('mouseleave', () => endDraw());
        // Touch
        sigCanvas.addEventListener('touchstart', e => { e.preventDefault(); const t = getTouchPos(e); startDraw(t.x, t.y); }, { passive:false });
        sigCanvas.addEventListener('touchmove',  e => { e.preventDefault(); const t = getTouchPos(e); if (isDrawing) draw(t.x, t.y); }, { passive:false });
        sigCanvas.addEventListener('touchend',   () => endDraw());

        const existingSignature = document.getElementById('hidden_contract_signature').value;
        if (existingSignature) {
            const img = new Image();
            img.onload = () => {
                const canvasRect = sigCanvas.getBoundingClientRect();
                sigCtx.drawImage(img, 0, 0, canvasRect.width, canvasRect.height);
                hasSigned = true;
                document.getElementById('sigPlaceholder').style.display = 'none';
                document.getElementById('sigStatus').innerHTML = '<i class="fas fa-check-circle" style="color:#27ae60;margin-right:6px;"></i><span style="color:#27ae60;font-weight:600;font-style:normal;">Signature captured</span>';
            };
            img.src = existingSignature;
        } else {
            hasSigned = false;
            document.getElementById('sigPlaceholder').style.display = 'flex';
            document.getElementById('sigStatus').innerHTML = '<i class="fas fa-pen" style="margin-right:6px;"></i> Not signed yet';
        }
        updateSignatureLock();
    }

    function getTouchPos(e) {
        const r = sigCanvas.getBoundingClientRect();
        return { x: e.touches[0].clientX - r.left, y: e.touches[0].clientY - r.top };
    }
    function startDraw(x, y) {
        // Contract text is required before signing — checked here (not just
        // via CSS) so drawing genuinely can't start, instead of letting the
        // user sign first and only rejecting them afterward when they
        // confirm the signature (which discards the stroke they just drew).
        if (!getContractEditorText()) return;
        isDrawing = true;
        sigCtx.beginPath();
        sigCtx.moveTo(x, y);
        document.getElementById('sigPlaceholder').style.display = 'none';
    }
    function draw(x, y) {
        sigCtx.lineTo(x, y);
        sigCtx.stroke();
        hasSigned = true;
        document.getElementById('sigStatus').innerHTML = '<i class="fas fa-check-circle" style="color:#27ae60;margin-right:6px;"></i><span style="color:#27ae60;font-weight:600;font-style:normal;">Signature captured</span>';
    }
    function endDraw() {
        isDrawing = false;
        if (hasSigned && sigCanvas) {
            const sigData = sigCanvas.toDataURL('image/png');
            document.getElementById('hidden_contract_signature').value = sigData;
            document.getElementById('sigConfirmPreview').src = sigData;
            document.getElementById('sigConfirmModal').classList.add('open');
        }
        saveAddServiceDraft();
    }

    function clearSignature() {
        const rect = sigCanvas.getBoundingClientRect();
        sigCtx.clearRect(0, 0, rect.width * window.devicePixelRatio, rect.height * window.devicePixelRatio);
        hasSigned = false;
        document.getElementById('hidden_contract_signature').value = '';
        document.getElementById('sigPlaceholder').style.display = 'flex';
        document.getElementById('sigStatus').innerHTML = '<i class="fas fa-pen" style="margin-right:6px;"></i> Not signed yet';
        updateSignatureLock();
        saveAddServiceDraft();
    }

    function submitWithSignature() {
        const hiddenSignature = document.getElementById('hidden_contract_signature');
        if (!hasSigned && !hiddenSignature.value) {
            alert('Please draw your signature before publishing the service.');
            return;
        }
        if (!hiddenSignature.value && sigCanvas) {
            hiddenSignature.value = sigCanvas.toDataURL('image/png');
        }
        syncContractTextFromEditor();
        saveAddServiceDraft();
        // Submit the form normally via POST
        const form = document.getElementById('addServiceForm');
        form.method = 'POST';
        form.action = 'services.php';
        form.submit();
    }

    // ── View Modal ──
    const viewModal    = document.getElementById('viewModal');
    const closeViewX   = document.getElementById('closeViewModal');
    const closeViewBtn = document.getElementById('closeViewBtn');
    const printViewContractBtn = document.getElementById('printViewContractBtn');
    const saveViewContractBtn  = document.getElementById('saveViewContractBtn');
    const editContractForm     = document.getElementById('editContractForm');
    const deleteConfirmModal   = document.getElementById('deleteConfirmModal');
    const closeDeleteConfirmModal = document.getElementById('closeDeleteConfirmModal');
    const deleteConfirmCancelBtn  = document.getElementById('deleteConfirmCancelBtn');
    const deleteConfirmProceedBtn = document.getElementById('deleteConfirmProceedBtn');
    const deleteConfirmMessage    = document.getElementById('deleteConfirmMessage');
    let activeViewServiceId = 0;
    let pendingDeleteUrl = '';

    function openView()  { viewModal.classList.add('open'); document.body.style.overflow = 'hidden'; }
    function closeView() { viewModal.classList.remove('open'); document.body.style.overflow = ''; }
    function openDeleteConfirm(deleteUrl, serviceName) {
        pendingDeleteUrl = deleteUrl || '';
        const safeServiceName = serviceName || 'this service';
        deleteConfirmMessage.textContent = `Are you sure you want to remove "${safeServiceName}"?`;
        deleteConfirmModal.classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function closeDeleteConfirm() {
        deleteConfirmModal.classList.remove('open');
        pendingDeleteUrl = '';
        if (!viewModal.classList.contains('open') && !settingsModal.classList.contains('open') && !addModal.classList.contains('open')) {
            document.body.style.overflow = '';
        }
    }

    function decodeBase64ToText(value) {
        if (!value) return '';
        try {
            const binary = atob(value);
            const bytes = Uint8Array.from(binary, c => c.charCodeAt(0));
            return new TextDecoder().decode(bytes);
        } catch (e) {
            try { return atob(value); } catch (_) { return ''; }
        }
    }

    function escapeHtml(text) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#039;' };
        return String(text).replace(/[&<>\"']/g, m => map[m]);
    }

    function printViewContractPDF() {
        const serviceName = document.getElementById('view-name').textContent || 'Service';
        const contractText = document.getElementById('view-contract-text').value.trim();
        if (!contractText) {
            alert('No contract text available to print.');
            return;
        }

        const bodyHtml = `
            <h2 style="text-align:center; margin-bottom:20px;">SERVICE CONTRACT AGREEMENT</h2>
            <p><strong>Service:</strong> ${escapeHtml(serviceName)}</p>
            <hr style="border:none; border-top:1px solid #ddd; margin:14px 0;">
            <div style="white-space:pre-wrap;">${escapeHtml(contractText)}</div>
            <div style="margin-top:24px;">
                <div style="font-size:12px; color:#64748b; margin-bottom:8px;">Seeker E-Signature (Reserved)</div>
                <div style="width:260px; border-bottom:1px solid #cbd5e1; height:32px;"></div>
                <div style="font-size:11px; color:#64748b; margin-top:8px;">To be signed by seeker upon agreement.</div>
            </div>
        `;

        const win = window.open('', '_blank');
        win.document.write(`<!DOCTYPE html><html><head><title>Service Contract</title>
        <style>body{font-family:Arial,sans-serif;padding:40px;color:#1a1a2e;font-size:13px;line-height:1.8;}
        p{margin-bottom:10px;}</style></head><body>${bodyHtml}
        <script>window.onload=function(){window.print();}<\/script></body></html>`);
        win.document.close();
    }

    closeViewX.addEventListener('click', closeView);
    closeViewBtn.addEventListener('click', closeView);
    viewModal.addEventListener('click', e => { if (e.target === viewModal) closeView(); });
    printViewContractBtn.addEventListener('click', printViewContractPDF);

    closeDeleteConfirmModal.addEventListener('click', closeDeleteConfirm);
    deleteConfirmCancelBtn.addEventListener('click', closeDeleteConfirm);
    deleteConfirmModal.addEventListener('click', e => { if (e.target === deleteConfirmModal) closeDeleteConfirm(); });
    deleteConfirmProceedBtn.addEventListener('click', function () {
        if (pendingDeleteUrl) {
            window.location.href = pendingDeleteUrl;
        } else {
            closeDeleteConfirm();
        }
    });

    saveViewContractBtn.addEventListener('click', function () {
        if (!activeViewServiceId) {
            alert('No selected service contract to update.');
            return;
        }
        const contractText = document.getElementById('view-contract-text').value.trim();
        if (!contractText) {
            alert('Please enter contract text before saving.');
            return;
        }
        if (!confirm('Are you sure you want to save this contract update?')) return;
        document.getElementById('edit_contract_service_id').value = activeViewServiceId;
        document.getElementById('edit_contract_text_hidden').value = contractText;
        editContractForm.submit();
    });

    document.querySelectorAll('.view-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            activeViewServiceId = parseInt(this.dataset.id || '0', 10);
            document.getElementById('view-name').textContent     = this.dataset.name     || '�';
            document.getElementById('view-price').textContent    = '\u20B1' + (this.dataset.price || '0.00');
            document.getElementById('view-duration').textContent = this.dataset.duration  || '�';
            document.getElementById('view-created').textContent  = this.dataset.created   || '�';
            const desc = (this.dataset.desc || '').trim();
            document.getElementById('view-desc').textContent = desc !== '' ? desc : 'No description provided.';

            // Pesticide fields
            const pestName  = (this.dataset.pestName  || '').trim();
            const pestBrand = (this.dataset.pestBrand || '').trim();
            const pestType  = (this.dataset.pestType  || '').trim();
            const pestNotes = (this.dataset.pestNotes || '').trim();

            const setV = (id, val, empty = '�') => {
                const el = document.getElementById(id);
                el.textContent = val || empty;
                el.classList.toggle('empty', !val);
            };
            setV('view-pest-name',  pestName);
            setV('view-pest-brand', pestBrand);
            setV('view-pest-type',  pestType);
            setV('view-pest-notes', pestNotes, 'No notes provided.');

            // Contract fields
            const contractText = decodeBase64ToText(this.dataset.contractText || '');
            document.getElementById('view-contract-text').value = contractText;
            const signedAtRaw = this.dataset.contractSignedAt || '';
            document.getElementById('view-contract-signed-at').textContent = signedAtRaw
                ? ('Signed on: ' + signedAtRaw)
                : 'No signature timestamp saved.';

            // Show/hide block
            document.getElementById('view-pest-block').style.display = pestName || pestBrand || pestType || pestNotes ? 'block' : 'none';

            openView();
        });
    });

    document.querySelectorAll('.delete-service-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const deleteUrl = this.dataset.deleteUrl || '';
            const serviceName = this.dataset.serviceName || 'this service';
            openDeleteConfirm(deleteUrl, serviceName);
        });
    });

    // ── Pesticide type button toggle ──
    function selectPestType(prefix, type, el) {
        const grid = document.getElementById(prefix + '-pest-type-grid');
        grid.querySelectorAll('.pest-type-btn').forEach(b => b.classList.remove('selected'));
        const hiddenInput = document.getElementById(prefix + '-pesticide-type');
        if (hiddenInput.value === type) {
            // Deselect
            hiddenInput.value = '';
        } else {
            el.classList.add('selected');
            hiddenInput.value = type;
        }
        if (prefix === 'add') saveAddServiceDraft();
    }

    // Pre-select pest type button in edit form
    function preselectPestType(prefix, type) {
        if (!type) return;
        const grid = document.getElementById(prefix + '-pest-type-grid');
        grid.querySelectorAll('.pest-type-btn').forEach(b => {
            if (b.textContent.trim().includes(type)) {
                b.classList.add('selected');
            }
        });
        document.getElementById(prefix + '-pesticide-type').value = type;
    }

    // ── Settings Modal ──
    const settingsModal = document.getElementById('settingsModal');
    let activeTab = 'edit';
    let paymentSettingsLocked = false;

    function refreshSettingsSaveButton() {
        const saveBtn = document.getElementById('saveSettingsBtn');
        if (activeTab === 'pay' && paymentSettingsLocked) {
            saveBtn.disabled = true;
            saveBtn.title = 'Payment settings are locked while this service has pending bookings.';
        } else {
            saveBtn.disabled = false;
            saveBtn.title = '';
        }
    }

    function applyPaymentLockState(isLocked, pendingCount) {
        paymentSettingsLocked = !!isLocked;
        const count = Number(pendingCount || 0);
        const lockNote = document.getElementById('pay-lock-note');
        const lockedControls = ['btn-full', 'btn-down', 'btn-pct', 'btn-fixed', 'dp_percent', 'dp_fixed'];

        lockedControls.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.disabled = paymentSettingsLocked;
        });

        if (paymentSettingsLocked) {
            const bookingLabel = count === 1 ? '1 pending booking' : count + ' pending bookings';
            lockNote.textContent = 'Payment settings are locked because this service already has ' + bookingLabel + '. Finish or clear those booking requests first before changing the downpayment setup.';
            lockNote.classList.add('show');
        } else {
            lockNote.textContent = '';
            lockNote.classList.remove('show');
        }

        refreshSettingsSaveButton();
    }

    function openSettings()  { settingsModal.classList.add('open'); document.body.style.overflow = 'hidden'; }
    function closeSettings() { settingsModal.classList.remove('open'); document.body.style.overflow = ''; }

    document.getElementById('closeSettingsModal').addEventListener('click', closeSettings);
    document.getElementById('cancelSettings').addEventListener('click', closeSettings);
    settingsModal.addEventListener('click', e => { if (e.target === settingsModal) closeSettings(); });

    document.querySelectorAll('.settings-tab').forEach(tab => {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.settings-tab').forEach(t => t.classList.remove('active-tab'));
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active-panel'));
            this.classList.add('active-tab');
            document.getElementById(this.dataset.tab).classList.add('active-panel');
            activeTab = this.dataset.tab === 'edit-panel' ? 'edit' : 'pay';
            refreshSettingsSaveButton();
        });
    });

    document.getElementById('saveSettingsBtn').addEventListener('click', function () {
        if (activeTab === 'edit') {
            document.getElementById('editServiceForm').submit();
        } else {
            if (paymentSettingsLocked) return;
            document.getElementById('paymentForm').submit();
        }
    });

    document.querySelectorAll('.settings-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const id       = this.dataset.id;
            const name     = this.dataset.name;
            const price    = this.dataset.price;
            const desc     = this.dataset.desc;
            const payType  = this.dataset.payType  || 'full';
            const dpMode   = this.dataset.dpMode   || 'percent';
            const dpPct    = this.dataset.dpPercent || 50;
            const dpFixed  = this.dataset.dpFixed  || 0;
            const payLocked = this.dataset.payLocked === '1';
            const pendingBookingCount = this.dataset.pendingBookingCount || 0;
            const pestName  = this.dataset.pestName  || '';
            const pestBrand = this.dataset.pestBrand || '';
            const pestType  = this.dataset.pestType  || '';
            const pestNotes = this.dataset.pestNotes || '';
            const assignedStaffId = this.dataset.assignedStaffId || '0';
            const requiresInspection = this.dataset.requiresInspection === '1';
            const categoryId = this.dataset.categoryId || '';
            const pricingType = this.dataset.pricingType || 'fixed';
            let equipmentIds = [];
            try { equipmentIds = JSON.parse(this.dataset.equipmentIds || '[]'); } catch (e) { equipmentIds = []; }
            let equipmentQty = {};
            try { equipmentQty = JSON.parse(this.dataset.equipmentQty || '{}'); } catch (e) { equipmentQty = {}; }

            // Edit fields
            document.getElementById('edit_id').value                = id;
            document.getElementById('edit_service_name').value      = name;
            document.getElementById('edit_price').value             = price.replace(',','');
            document.getElementById('edit_description').value       = desc;
            document.getElementById('edit_pesticide_name').value    = pestName;
            document.getElementById('edit_pesticide_brand').value   = pestBrand;
            document.getElementById('edit_pesticide_notes').value   = pestNotes;
            document.getElementById('edit_category').value = categoryId;
            document.getElementById('edit_pricing_type').value = pricingType;
            document.getElementById('edit_requires_inspection').checked = requiresInspection;
            onPricingTypeChange('edit');
            const editStaffSelect = document.getElementById('edit_assigned_staff_id');
            if (editStaffSelect) editStaffSelect.value = assignedStaffId;
            document.querySelectorAll('.edit-equipment-checkbox').forEach(function (cb) {
                const itemId = parseInt(cb.value, 10);
                const isChecked = equipmentIds.includes(itemId);
                cb.checked = isChecked;
                const qtyInput = document.getElementById('edit_eq_qty_' + itemId);
                if (qtyInput) {
                    qtyInput.disabled = !isChecked;
                    qtyInput.value = equipmentQty[itemId] || 1;
                }
            });

            // Reset and pre-select pest type
            document.getElementById('edit-pest-type-grid').querySelectorAll('.pest-type-btn').forEach(b => b.classList.remove('selected'));
            document.getElementById('edit-pesticide-type').value = '';
            if (pestType) preselectPestType('edit', pestType);

            // Payment fields
            document.getElementById('pay_service_id').value = id;
            document.getElementById('pay-service-label').textContent = '"' + name + '"';
            document.getElementById('dp_percent').value = dpPct;
            document.getElementById('dp_fixed').value   = dpFixed;

            setPayType(payType);
            setDpMode(dpMode);
            updatePreview();
            applyPaymentLockState(payLocked, pendingBookingCount);

            // Reset to Edit tab
            document.querySelectorAll('.settings-tab').forEach(t => t.classList.remove('active-tab'));
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active-panel'));
            document.querySelector('[data-tab="edit-panel"]').classList.add('active-tab');
            document.getElementById('edit-panel').classList.add('active-panel');
            activeTab = 'edit';
            refreshSettingsSaveButton();

            openSettings();
        });
    });

    // Payment type toggle
    function setPayType(type) {
        document.getElementById('payment_type').value = type;
        document.getElementById('btn-full').classList.toggle('active-pay', type === 'full');
        document.getElementById('btn-down').classList.toggle('active-pay', type === 'downpayment');
        document.getElementById('dp-section').style.display  = type === 'downpayment' ? 'block' : 'none';
        document.getElementById('full-info').style.display   = type === 'full'        ? 'block' : 'none';
        if (type === 'downpayment') updatePreview();
    }

    function setDpMode(mode) {
        document.getElementById('dp_mode').value = mode;
        document.getElementById('btn-pct').classList.toggle('active-mode',   mode === 'percent');
        document.getElementById('btn-fixed').classList.toggle('active-mode', mode === 'fixed');
        document.getElementById('pct-input').style.display   = mode === 'percent' ? 'block' : 'none';
        document.getElementById('fixed-input').style.display = mode === 'fixed'   ? 'block' : 'none';
        updatePreview();
    }

    function updatePreview() {
        const mode    = document.getElementById('dp_mode').value;
        const pct     = parseFloat(document.getElementById('dp_percent').value) || 0;
        const fixed   = parseFloat(document.getElementById('dp_fixed').value)   || 0;
        const price   = parseFloat(document.getElementById('edit_price').value) || 0;
        let label = '';
        if (mode === 'percent') {
            const amt = price > 0 ? ' (\u20B1' + (price * pct / 100).toFixed(2) + ')' : '';
            label = pct + '%' + amt;
        } else {
            label = '\u20B1' + fixed.toFixed(2);
        }
        document.getElementById('dp-preview-val').textContent = label;
    }
</script>
<?php include appPath('includes/provider-guide.php'); ?>
</body>
</html>
