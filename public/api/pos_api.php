<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../src/config/Database.php';
require_once __DIR__ . '/../../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../../src/modules/auth/AuthService.php';
require_once __DIR__ . '/../../src/modules/catalog/Producto.php';
require_once __DIR__ . '/../../src/modules/customers/Cliente.php';
require_once __DIR__ . '/../../src/modules/inventory/MovimientoInventario.php';
require_once __DIR__ . '/../../src/modules/laundry/Carga.php';
require_once __DIR__ . '/../../src/modules/pos/Venta.php';
require_once __DIR__ . '/../../src/modules/pos/CajaCorte.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;
use App\Modules\Auth\Usuario;
use App\Modules\Catalog\Producto;
use App\Modules\Customers\Cliente;
use App\Modules\Pos\Venta;
use App\Modules\Pos\CajaCorte;

$db = (new Database())->getConnection();
$auth = new AuthService($db);

// Verificar sesión
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesión expirada o no iniciada']);
    exit;
}

$currentUserId = $_SESSION['usuario_id'];
$currentUserName = $_SESSION['nombre'] ?? 'Usuario';
$currentUserRole = $_SESSION['rol'] ?? 'EMPLEADO_CAJERO';
$isAdmin = $auth->isAdmin();

$ventaModel = new Venta($db);
$cajaModel = new CajaCorte($db);
$clienteModel = new Cliente($db);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Si los datos vienen por json body
$jsonInput = json_decode(file_get_contents('php://input'), true);
if (is_array($jsonInput)) {
    if (empty($action) && isset($jsonInput['action'])) {
        $action = $jsonInput['action'];
    }
    $_POST = array_merge($_POST, $jsonInput);
}

try {
    switch ($action) {

        // ─────────────────────────────────────────────────────────────
        // 1. Datos iniciales del POS
        // ─────────────────────────────────────────────────────────────
        case 'init_data':
            $cajaAbierta = $cajaModel->obtenerCajaAbierta($currentUserId);
            if (!$cajaAbierta && $isAdmin) {
                // Si es admin y no tiene caja propia, revisar si hay alguna abierta
                $cajaAbierta = $cajaModel->obtenerCajaAbierta(null);
            }

            // Obtener productos activos
            $stmtProd = $db->query(
                "SELECT id, codigo_barras, codigo_interno_sku, descripcion, tipo,
                        unidad_medida, stock_cantidad, stock_minimo, ropa_kg, precio_venta, costo
                 FROM productos
                 WHERE estado = 'ACTIVO'
                 ORDER BY tipo = 'SERVICIO' DESC, descripcion ASC"
            );
            $productos = $stmtProd->fetchAll(PDO::FETCH_ASSOC);

            // Obtener lista inicial de clientes (top 50)
            $stmtCli = $db->query("SELECT id, nombre, telefono, whatsapp_disponible, direccion FROM clientes WHERE estado = 'ACTIVO' ORDER BY nombre ASC LIMIT 50");
            $clientes = $stmtCli->fetchAll(PDO::FETCH_ASSOC);

            // Ventas recientes del día
            $ventasRecientes = $ventaModel->obtenerVentasRecientes(25);

            // Conteo pendientes
            $pendientes = $ventaModel->obtenerVentasPendientes();

            echo json_encode([
                'success' => true,
                'user' => [
                    'id'      => $currentUserId,
                    'nombre'  => $currentUserName,
                    'rol'     => $currentUserRole,
                    'isAdmin' => $isAdmin
                ],
                'caja_abierta'      => $cajaAbierta,
                'productos'         => $productos,
                'clientes'          => $clientes,
                'ventas_recientes'  => $ventasRecientes,
                'total_pendientes'  => count($pendientes)
            ]);
            break;

        // ─────────────────────────────────────────────────────────────
        // 2. Buscar clientes
        // ─────────────────────────────────────────────────────────────
        case 'search_clients':
            $term = trim($_GET['q'] ?? '');
            if ($term === '') {
                $stmt = $db->query("SELECT id, nombre, telefono, whatsapp_disponible, direccion FROM clientes WHERE estado = 'ACTIVO' ORDER BY nombre ASC LIMIT 50");
                $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $clientes = $clienteModel->buscar($term);
            }
            echo json_encode(['success' => true, 'clientes' => $clientes]);
            break;

        // ─────────────────────────────────────────────────────────────
        // 3. Crear cliente express
        // ─────────────────────────────────────────────────────────────
        case 'create_client':
            $nombre = trim($_POST['nombre'] ?? '');
            $telefono = trim($_POST['telefono'] ?? '');
            $direccion = trim($_POST['direccion'] ?? '');
            $correo = trim($_POST['correo'] ?? '');
            $observaciones = trim($_POST['observaciones'] ?? '');
            $whatsapp = !empty($_POST['whatsapp_disponible']) ? 1 : 0;

            if ($nombre === '' || $telefono === '') {
                echo json_encode(['success' => false, 'message' => 'Nombre y teléfono son obligatorios.']);
                exit;
            }

            $clienteModel->nombre = $nombre;
            $clienteModel->telefono = $telefono;
            $clienteModel->whatsapp_disponible = $whatsapp;
            $clienteModel->correo = $correo ?: null;
            $clienteModel->direccion = $direccion ?: null;
            $clienteModel->observaciones = $observaciones ?: null;
            $clienteModel->estado = 'ACTIVO';

            if ($clienteModel->crear()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Cliente registrado con éxito.',
                    'cliente' => [
                        'id'                  => $clienteModel->id,
                        'nombre'              => $nombre,
                        'telefono'            => $telefono,
                        'whatsapp_disponible' => $whatsapp,
                        'direccion'           => $direccion
                    ]
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al guardar cliente.']);
            }
            break;

        // ─────────────────────────────────────────────────────────────
        // 4. Apertura de Caja
        // ─────────────────────────────────────────────────────────────
        case 'abrir_caja':
            $montoInicial = floatval($_POST['monto_inicial'] ?? 0);
            $observaciones = trim($_POST['observaciones'] ?? '');

            if ($montoInicial < 0) {
                echo json_encode(['success' => false, 'message' => 'El monto inicial no puede ser negativo.']);
                exit;
            }

            $res = $cajaModel->abrirCaja($currentUserId, $montoInicial, $observaciones);
            echo json_encode($res);
            break;

        // ─────────────────────────────────────────────────────────────
        // 5. Resumen para Corte de Caja
        // ─────────────────────────────────────────────────────────────
        case 'resumen_corte':
            $cajaId = $_GET['caja_id'] ?? $_POST['caja_id'] ?? '';
            if (empty($cajaId)) {
                $caja = $cajaModel->obtenerCajaAbierta($currentUserId);
                $cajaId = $caja ? $caja['id'] : '';
            }

            if (empty($cajaId)) {
                echo json_encode(['success' => false, 'message' => 'No hay caja abierta para realizar el corte.']);
                exit;
            }

            $resumen = $cajaModel->obtenerResumenTurno($cajaId);
            echo json_encode($resumen);
            break;

        // ─────────────────────────────────────────────────────────────
        // 6. Cerrar Caja / Corte
        // ─────────────────────────────────────────────────────────────
        case 'cerrar_caja':
            $cajaId = $_POST['caja_id'] ?? '';
            $efectivoDeclarado = floatval($_POST['efectivo_declarado'] ?? 0);
            $observaciones = trim($_POST['observaciones'] ?? '');

            if (empty($cajaId)) {
                $caja = $cajaModel->obtenerCajaAbierta($currentUserId);
                $cajaId = $caja ? $caja['id'] : '';
            }

            if (empty($cajaId)) {
                echo json_encode(['success' => false, 'message' => 'No hay una caja abierta para cerrar.']);
                exit;
            }

            $res = $cajaModel->cerrarCaja($cajaId, $efectivoDeclarado, $observaciones);
            echo json_encode($res);
            break;

        // ─────────────────────────────────────────────────────────────
        // 7. Procesar Venta
        // ─────────────────────────────────────────────────────────────
        case 'procesar_venta':
            $items = $_POST['items'] ?? [];
            if (is_string($items)) {
                $items = json_decode($items, true);
            }

            if (empty($items) || !is_array($items)) {
                echo json_encode(['success' => false, 'message' => 'El carrito está vacío.']);
                exit;
            }

            // Obtener caja abierta para ligarla
            $caja = $cajaModel->obtenerCajaAbierta($currentUserId);
            $cajaId = $caja ? $caja['id'] : null;

            $datosVenta = [
                'usuario_id'     => $currentUserId,
                'cliente_id'     => !empty($_POST['cliente_id']) ? $_POST['cliente_id'] : null,
                'caja_id'        => $cajaId,
                'items'          => $items,
                'metodo_pago'    => $_POST['metodo_pago'] ?? 'EFECTIVO',
                'pago_posterior' => !empty($_POST['pago_posterior']),
                'monto_recibido' => floatval($_POST['monto_recibido'] ?? 0),
                'observaciones'  => trim($_POST['observaciones'] ?? '')
            ];

            $resultado = $ventaModel->procesarVenta($datosVenta);

            if ($resultado['success']) {
                // Obtener datos listos para el ticket
                $ticket = $ventaModel->obtenerVentaConDetalle($resultado['venta_id']);
                $resultado['ticket'] = $ticket;
            }

            echo json_encode($resultado);
            break;

        // ─────────────────────────────────────────────────────────────
        // 8. Obtener Ticket por ID o Folio
        // ─────────────────────────────────────────────────────────────
        case 'obtener_ticket':
            $ventaId = trim($_GET['venta_id'] ?? $_GET['id'] ?? '');
            if ($ventaId === '') {
                echo json_encode(['success' => false, 'message' => 'Identificador de venta no proporcionado.']);
                exit;
            }

            $ticket = $ventaModel->obtenerVentaConDetalle($ventaId);
            if (!$ticket) {
                echo json_encode(['success' => false, 'message' => 'Venta no encontrada.']);
                exit;
            }

            echo json_encode(['success' => true, 'ticket' => $ticket]);
            break;

        // ─────────────────────────────────────────────────────────────
        // 9. Ventas Pendientes de Pago (Pago Posterior)
        // ─────────────────────────────────────────────────────────────
        case 'ventas_pendientes':
            $q = trim($_GET['q'] ?? '');
            $lista = $ventaModel->obtenerVentasPendientes($q);
            echo json_encode(['success' => true, 'pendientes' => $lista]);
            break;

        // ─────────────────────────────────────────────────────────────
        // 10. Liquidar / Pagar Venta Pendiente
        // ─────────────────────────────────────────────────────────────
        case 'pagar_pendiente':
            $ventaId = trim($_POST['venta_id'] ?? '');
            $metodoPago = $_POST['metodo_pago'] ?? 'EFECTIVO';
            $montoRecibido = floatval($_POST['monto_recibido'] ?? 0);

            if ($ventaId === '') {
                echo json_encode(['success' => false, 'message' => 'ID de venta requerido.']);
                exit;
            }

            $resultado = $ventaModel->registrarPagoPosterior($ventaId, $metodoPago, $montoRecibido);
            if ($resultado['success']) {
                $resultado['ticket'] = $ventaModel->obtenerVentaConDetalle($ventaId);
            }
            echo json_encode($resultado);
            break;

        // ─────────────────────────────────────────────────────────────
        // 11. Cancelar Venta (Spec 05 §2 & Fase 12)
        // ─────────────────────────────────────────────────────────────
        case 'cancelar_venta':
            $ventaId = trim($_POST['venta_id'] ?? '');
            $motivo = trim($_POST['motivo'] ?? '');

            if ($ventaId === '' || $motivo === '') {
                echo json_encode(['success' => false, 'message' => 'La venta y el motivo son obligatorios.']);
                exit;
            }

            // Permisos: Si no es Admin, requiere autorización de administrador
            if (!$isAdmin) {
                $adminCorreo = trim($_POST['admin_correo'] ?? '');
                $adminPassword = $_POST['admin_password'] ?? '';

                if ($adminPassword === '') {
                    echo json_encode(['success' => false, 'message' => 'Se requiere autorización de Administrador para cancelar una venta.']);
                    exit;
                }

                // Buscar un admin y verificar contraseña
                $usuarioModel = new Usuario($db);
                $stmtAdmin = $db->prepare("SELECT id, password_hash FROM usuarios WHERE (correo = :c OR rol = 'ADMINISTRADOR') AND rol = 'ADMINISTRADOR' AND estado = 'ACTIVO' LIMIT 1");
                $stmtAdmin->execute([':c' => $adminCorreo]);
                $adminUser = $stmtAdmin->fetch(PDO::FETCH_ASSOC);

                if (!$adminUser || !password_verify($adminPassword, $adminUser['password_hash'])) {
                    echo json_encode(['success' => false, 'message' => 'Contraseña de administrador incorrecta. Autorización denegada.']);
                    exit;
                }
            }

            $resultado = $ventaModel->cancelarVenta($ventaId, $motivo, $currentUserId);
            echo json_encode($resultado);
            break;

        // ─────────────────────────────────────────────────────────────
        // 12. Ventas recientes
        // ─────────────────────────────────────────────────────────────
        case 'ventas_recientes':
            $ventas = $ventaModel->obtenerVentasRecientes(50);
            echo json_encode(['success' => true, 'ventas' => $ventas]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Acción no reconocida.']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error en el servidor: ' . $e->getMessage()]);
}
