<?php
// ══════════════════════════════════════════════════════
// superadmin/middleware/superadmin_guard.php
// Auth guard khusus Super Admin — terpisah dari tenant auth
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', dirname(__DIR__));
require_once SA_ROOT . '/../master/config/db.php';
require_once SA_ROOT . '/../core/Database.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['superadmin_id'])) {
    if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Sesi habis.', 'redirect' => '/ERP/harpy/superadmin/login.php']);
    } else {
        header('Location: /ERP/harpy/superadmin/login.php');
    }
    exit;
}

function saCurrentAdmin(): array {
    return $_SESSION['sa_user'] ?? [];
}

function logSuperAdminAction(string $action, ?int $tenantId, string $desc): void {
    try {
        Database::get()->prepare(
            "INSERT INTO superadmin_logs (superadmin_id, action, target_tenant_id, description, ip_address)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([
            $_SESSION['superadmin_id'],
            $action,
            $tenantId,
            $desc,
            $_SERVER['REMOTE_ADDR'] ?? '-'
        ]);
    } catch (Throwable) {}
}

function saGetCsrf(): string {
    if (empty($_SESSION['sa_csrf'])) {
        $_SESSION['sa_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['sa_csrf'];
}

function saVerifyCsrf(): void {
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(saGetCsrf(), $token)) {
        if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'CSRF invalid.']);
        } else {
            die('Request tidak valid.');
        }
        exit;
    }
}
