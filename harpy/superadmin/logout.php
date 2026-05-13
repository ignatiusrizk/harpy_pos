<?php
// ══════════════════════════════════════════════════════
// superadmin/logout.php
// ══════════════════════════════════════════════════════

if (session_status() === PHP_SESSION_NONE) session_start();

// Log logout before destroying session
if (!empty($_SESSION['superadmin_id'])) {
    if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
    require_once SA_ROOT . '/../master/config/db.php';
    require_once SA_ROOT . '/../core/Database.php';
    try {
        Database::get()->prepare(
            "INSERT INTO superadmin_logs (superadmin_id, action, description, ip_address)
             VALUES (?, 'logout', 'Super admin logout', ?)"
        )->execute([$_SESSION['superadmin_id'], $_SERVER['REMOTE_ADDR'] ?? '-']);
    } catch (Throwable) {}
}

session_unset();
session_destroy();

header('Location: /ERP/harpy/superadmin/login.php?msg=logout');
exit;
