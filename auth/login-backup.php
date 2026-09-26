<?php
// auth/login-backup.php — pre-centralized-login standalone page, superseded
// by auth/login.php (see CLAUDE.md's "Centralized login" section). The old
// body below this comment suffered the same character-corruption incident
// documented in __corrupt_backup_2026-04-03/ (an unclosed brace made the
// whole file fail to parse), so — unlike admin/admin-login.php, which kept
// its still-valid legacy code below an unreachable shim for reference —
// there's nothing salvageable to preserve here. Trimmed to match
// provider-portal/login.php's equivalent shim-only pattern instead.
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/config.php';
header('Location: ' . appUrl('auth/login.php'));
exit();
