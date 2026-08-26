<?php

function ensurePaymentReceiptColumns(PDO $db): void
{
    try {
        $db->exec("ALTER TABLE payment_transactions ADD COLUMN IF NOT EXISTS receipt_number VARCHAR(80) DEFAULT NULL");
    } catch (Throwable $e) {
    }

    try {
        $db->exec("ALTER TABLE payment_transactions ADD COLUMN IF NOT EXISTS receipt_issued_at DATETIME DEFAULT NULL");
    } catch (Throwable $e) {
    }
}

function paymentReceiptTypeLabel(?string $type): string
{
    $type = strtolower(trim((string)$type));
    return match ($type) {
        'downpayment' => 'Downpayment',
        'remaining' => 'Remaining Balance',
        'full', 'full_payment' => 'Full Payment',
        default => ucwords(str_replace('_', ' ', $type !== '' ? $type : 'payment')),
    };
}

function paymentReceiptMethodLabel(?string $method): string
{
    $method = strtolower(trim((string)$method));
    return match ($method) {
        'paymongo_checkout' => 'PayMongo Checkout',
        'gcash' => 'GCash',
        'paymaya', 'maya' => 'Maya',
        'card' => 'Card',
        default => ucwords(str_replace('_', ' ', $method !== '' ? $method : 'gateway')),
    };
}

function paymentReceiptStatusLabel(?string $status): string
{
    $status = strtolower(trim((string)$status));
    return $status === '' ? 'Unknown' : ucfirst($status);
}

function buildPaymentReceiptNumber(array $transaction): string
{
    $paymentType = strtolower(trim((string)($transaction['payment_type'] ?? 'payment')));
    $prefix = match ($paymentType) {
        'downpayment' => 'DP',
        'remaining' => 'RB',
        'full', 'full_payment' => 'FP',
        default => 'PM',
    };

    $bookingId = (int)($transaction['availed_service_id'] ?? 0);
    $txId = (int)($transaction['id'] ?? 0);
    $baseDate = trim((string)($transaction['updated_at'] ?? $transaction['created_at'] ?? ''));
    $datePart = $baseDate !== '' ? date('Ymd', strtotime($baseDate)) : date('Ymd');

    return sprintf('PST-%s-%s-%05d-%04d', $datePart, $prefix, $bookingId, $txId);
}

function issuePaymentReceipt(PDO $db, int $transactionId): ?array
{
    if ($transactionId <= 0) {
        return null;
    }

    ensurePaymentReceiptColumns($db);

    $stmt = $db->prepare(
        "SELECT id, availed_service_id, seeker_id, provider_id, amount, payment_type, payment_method,
                transaction_id, status, receipt_number, receipt_issued_at, created_at, updated_at
         FROM payment_transactions
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $transactionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || strtolower(trim((string)($row['status'] ?? ''))) !== 'completed') {
        return null;
    }

    $receiptNumber = trim((string)($row['receipt_number'] ?? ''));
    $receiptIssuedAt = trim((string)($row['receipt_issued_at'] ?? ''));
    if ($receiptNumber === '') {
        $receiptNumber = buildPaymentReceiptNumber($row);
    }
    if ($receiptIssuedAt === '' || $receiptIssuedAt === '0000-00-00 00:00:00') {
        $receiptIssuedAt = trim((string)($row['updated_at'] ?? $row['created_at'] ?? ''));
        if ($receiptIssuedAt === '') {
            $receiptIssuedAt = date('Y-m-d H:i:s');
        }
    }

    try {
        $db->prepare(
            "UPDATE payment_transactions
             SET receipt_number = :receipt_number,
                 receipt_issued_at = :receipt_issued_at
             WHERE id = :id"
        )->execute([
            ':receipt_number' => $receiptNumber,
            ':receipt_issued_at' => $receiptIssuedAt,
            ':id' => $transactionId,
        ]);
    } catch (Throwable $e) {
    }

    $row['receipt_number'] = $receiptNumber;
    $row['receipt_issued_at'] = $receiptIssuedAt;
    $row['paid_at'] = $receiptIssuedAt;

    return $row;
}

function syncCompletedReceiptsForBooking(PDO $db, int $bookingId): void
{
    if ($bookingId <= 0) {
        return;
    }

    ensurePaymentReceiptColumns($db);

    $stmt = $db->prepare(
        "SELECT id
         FROM payment_transactions
         WHERE availed_service_id = :booking_id
           AND status = 'completed'"
    );
    $stmt->execute([':booking_id' => $bookingId]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    foreach ($ids as $id) {
        issuePaymentReceipt($db, (int)$id);
    }
}

function fetchReceiptsForBookings(PDO $db, array $bookingIds): array
{
    $bookingIds = array_values(array_unique(array_map('intval', $bookingIds)));
    $bookingIds = array_values(array_filter($bookingIds, static fn($id) => $id > 0));
    if (empty($bookingIds)) {
        return [];
    }

    ensurePaymentReceiptColumns($db);

    $placeholders = [];
    $params = [];
    foreach ($bookingIds as $index => $bookingId) {
        $key = ':booking_' . $index;
        $placeholders[] = $key;
        $params[$key] = $bookingId;
    }

    $stmt = $db->prepare(
        "SELECT id, availed_service_id, seeker_id, provider_id, amount, payment_type, payment_method,
                transaction_id, status, receipt_number, receipt_issued_at, created_at, updated_at
         FROM payment_transactions
         WHERE status = 'completed'
           AND availed_service_id IN (" . implode(',', $placeholders) . ")
         ORDER BY COALESCE(receipt_issued_at, updated_at, created_at) DESC, id DESC"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $grouped = [];
    foreach ($rows as $row) {
        if (trim((string)($row['receipt_number'] ?? '')) === '') {
            $issued = issuePaymentReceipt($db, (int)$row['id']);
            if ($issued) {
                $row = array_merge($row, $issued);
            }
        }

        $row['paid_at'] = trim((string)($row['receipt_issued_at'] ?? '')) !== ''
            ? (string)$row['receipt_issued_at']
            : (string)($row['updated_at'] ?? $row['created_at'] ?? '');

        $bookingId = (int)($row['availed_service_id'] ?? 0);
        if (!isset($grouped[$bookingId])) {
            $grouped[$bookingId] = [];
        }
        $grouped[$bookingId][] = $row;
    }

    return $grouped;
}
