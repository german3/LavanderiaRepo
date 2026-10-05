<?php
require_once __DIR__ . '/../../src/config/Database.php';
require_once __DIR__ . '/../../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../../src/modules/auth/AuthService.php';
require_once __DIR__ . '/../../src/modules/inventory/MovimientoInventario.php';
require_once __DIR__ . '/../../src/modules/inventory/EntradaCompra.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;
use App\Modules\Inventory\EntradaCompra;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$db   = (new Database())->getConnection();
$auth = new AuthService($db);

if (!$auth->checkAuth()) {
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$id = trim($_GET['id'] ?? '');
if (empty($id)) {
    echo json_encode(['error' => 'ID requerido']);
    exit;
}

$entradaModel = new EntradaCompra($db);
$detalle      = $entradaModel->obtenerDetalle($id);

if (empty($detalle)) {
    echo json_encode(['error' => 'No encontrado']);
    exit;
}

echo json_encode($detalle, JSON_UNESCAPED_UNICODE);
