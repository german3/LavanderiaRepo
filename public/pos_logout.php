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

$auth->logoutPos();

header("Location: pos_login.php");
exit;
