<?php
// includes/inventory_expense_helper.php
//
// Links the Finance inventory register (inventory_items) to the actual
// expense ledger (expense_records) — previously adding stock never touched
// expenses at all, so a purchase had to be logged twice by hand (once in
// Inventory, once in Expenses) with nothing enforcing that it actually was.
//
// Only two moments create money-spent: adding a brand-new item (its
// starting quantity_available, if any) and restocking an existing item
// (the added quantity). A plain edit (fixing the name/price/reference/etc.)
// never changes quantity_available and so never touches this — see
// provider-portal/inventory.php's 'restock' action, split out from 'edit'
// specifically so a correction can never be mistaken for a purchase.

function recordInventoryPurchaseExpense(
    PDO $db,
    int $providerId,
    string $itemName,
    string $referenceNo,
    ?string $supplierName,
    int $quantity,
    float $unitPrice,
    string $recordedBy
): void {
    if ($quantity <= 0 || $unitPrice <= 0) {
        return; // nothing was actually purchased
    }

    $amount = round($quantity * $unitPrice, 2);
    $desc = "Inventory stock: {$itemName} (Ref: {$referenceNo}) — {$quantity} unit(s) @ " . number_format($unitPrice, 2) . " by {$recordedBy}";

    try {
        $db->prepare(
            "INSERT INTO expense_records (provider_id, expense_type, amount, expense_date, description, paid_to, payment_method, receipt_number, category)
             VALUES (:p, :t, :a, CURDATE(), :desc, :to, 'cash', :r, 'Inventory')"
        )->execute([
            ':p'    => $providerId,
            ':t'    => 'Inventory Purchase',
            ':a'    => $amount,
            ':desc' => $desc,
            ':to'   => $supplierName ?: null,
            ':r'    => $referenceNo ?: null,
        ]);
    } catch (Exception $e) {
        // Non-fatal — the inventory change itself already succeeded; a failed
        // expense log shouldn't block adding/restocking stock.
    }
}
