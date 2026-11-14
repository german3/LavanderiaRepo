<?php
/**
 * pos_logout.php — Cierre de sesión exclusivo para el FrontOffice POS.
 * Mantiene intacta la sesión de BackOffice si el usuario tenía una abierta.
 */
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../src/modules/auth/AuthService.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;

$db   = (new Database())->getConnection();
$auth = new AuthService($db);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUserPosId = $_SESSION['pos_usuario_id'] ?? null;
if (!empty($currentUserPosId)) {
    // Si hay una caja abierta en el sistema, asociarla al usuario que estaba activo operando el POS y que está saliendo
    try {
        $stmtUpdate = $db->prepare("UPDATE caja_cortes SET usuario_id = :uid WHERE estado = 'ABIERTA'");
        $stmtUpdate->execute([':uid' => $currentUserPosId]);
    } catch (\Throwable $e) {}
}

$auth->logoutPos();

$redirect = "pos_login.php";
if (!empty($_GET['temporal'])) {
    $redirect .= "?temporal=1";
}

header("Location: " . $redirect);
exit;
