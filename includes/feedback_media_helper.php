<?php

function ensureFeedbackImageColumn(PDO $pdo, string $table, string $column = 'feedback_image'): void
{
    try {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$column} VARCHAR(255) DEFAULT NULL");
    } catch (Throwable $e) {
    }
}

function uploadFeedbackImage(string $fieldName, int $userId, int $bookingId, string $subfolder = 'reviews')
{
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return null;
    }

    $file = $_FILES[$fieldName];
    $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($errorCode !== UPLOAD_ERR_OK) {
        return false;
    }

    $imageInfo = @getimagesize($file['tmp_name']);
    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $maxFileSize = 8 * 1024 * 1024;

    if ($imageInfo === false || !isset($allowedMimeTypes[$imageInfo['mime'] ?? ''])) {
        return false;
    }

    if ((int)($file['size'] ?? 0) <= 0 || (int)($file['size'] ?? 0) > $maxFileSize) {
        return false;
    }

    $baseDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $subfolder . DIRECTORY_SEPARATOR . 'seeker_' . $userId;
    if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true)) {
        return false;
    }

    $extension = $allowedMimeTypes[$imageInfo['mime']];
    $filename = 'feedback_' . $bookingId . '_' . date('YmdHis') . '_' . mt_rand(1000, 9999) . '.' . $extension;
    $targetPath = $baseDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return false;
    }

    return 'uploads/' . $subfolder . '/seeker_' . $userId . '/' . $filename;
}
