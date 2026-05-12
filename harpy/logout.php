<?php
// ══════════════════════════════════════════════════════
// logout.php — Session destroy & redirect to login
// ══════════════════════════════════════════════════════

session_start();

// Log audit sebelum destroy session (jika user masih ada)
if (!empty($_SESSION['user_id']) && !empty($_SESSION['tenant_id'])) {
    define('ROOT', __DIR__);
    try {
        require_once ROOT . '/master/config/db.php';
        require_once ROOT . '/core/Database.php';
        $db = Database::get();
        $stmt = $db->prepare(
            "INSERT INTO hl_audit_log (tenant_id, user_id, action, target_type, ip_address, created_at)
             VALUES (?, ?, 'logout', 'session', ?, NOW())"
        );
        $stmt->execute([
            $_SESSION['tenant_id'],
            $_SESSION['user_id'],
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // Abaikan error log — tetap lanjut logout
    }
}

// Hapus semua session data
$_SESSION = [];

// Hapus cookie session
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

session_destroy();

// Redirect ke login
header('Location: login.php');
exit;
