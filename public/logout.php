<?php
/**
 * logout.php — Cierre de sesión centralizado.
 * Funciona desde cualquier página del sistema.
 */
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../src/modules/auth/AuthService.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;

$db   = (new Database())->getConnection();
$auth = new AuthService($db);

$auth->logout();

header("Location: login.php");
exit;
