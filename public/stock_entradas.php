<?php
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../src/modules/auth/AuthService.php';
require_once __DIR__ . '/../src/modules/catalog/Producto.php';
require_once __DIR__ . '/../src/modules/catalog/Historial.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;
use App\Modules\Catalog\Producto;
use App\Modules\Catalog\Historial;

$db = (new Database())->getConnection();
$auth = new AuthService($db);
$productoModel = new Producto($db);
$historialModel = new Historial($db);

// Middleware de autenticación
if (!$auth->checkAuth()) {
    header("Location: login.php");
    exit;
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$mensaje = "";
$error = "";

// Obtener productos disponibles para entradas (NORMAL)
$productosNormales = $productoModel->obtenerProductosNormales();

// Factores de conversión a LITROS (unidad base para líquidos)
define('ML_A_LITRO',  0.001);
define('GAL_A_LITRO', 3.78541);

function convertirALitros(float $cantidad, string $unidadLiquido): float {
    switch ($unidadLiquido) {
        case 'MILILITRO': return $cantidad * ML_A_LITRO;
        case 'GALON':     return $cantidad * GAL_A_LITRO;
        default:          return $cantidad; // ya está en LITROS
    }
}

// Procesar registro de Movimiento de Stock (Entrada / Salida)
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($_POST['action'] ?? '') == 'registrar_entrada') {
    $productoId          = trim($_POST['producto_id'] ?? '');
    $tipoMovimiento      = trim($_POST['tipo_movimiento'] ?? 'ENTRADA');
    $cantidadRaw         = floatval($_POST['cantidad'] ?? 0);
    $unidadLiquido       = trim($_POST['unidad_liquido'] ?? 'LITRO'); // solo aplica si prod es LITRO
    $costoTotal          = floatval($_POST['costo_total'] ?? 0);
    $permitirEditarCosto = isset($_POST['permitir_editar_costo']) && $_POST['permitir_editar_costo'] == '1';
    $editarCosto         = floatval($_POST['editar_costo'] ?? 0);
    $motivo              = trim($_POST['motivo'] ?? '');

    // Convertir a litros si el producto es de tipo LITRO
    // (la conversion solo aplica en ENTRADA, en SALIDA el usuario elige cuántos litros retirar)
    $cantidad = $cantidadRaw;

    if (empty($productoId) || $cantidadRaw <= 0) {
        $error = "Selecciona un producto e ingresa una cantidad válida mayor a 0.";
    } else {
        try {
            // Obtener datos del producto antes de modificarlo
            $stmtCheck = $db->prepare("SELECT id, codigo_interno_sku, descripcion, tipo, unidad_medida, stock_cantidad, costo FROM productos WHERE id = :id");
            $stmtCheck->execute([':id' => $productoId]);
            $prodData = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            // Convertir si es producto líquido
            if ($prodData && $prodData['unidad_medida'] === 'LITRO') {
                $cantidad = convertirALitros($cantidadRaw, $unidadLiquido);
            }

            if (!$prodData) {
                $error = "Producto no encontrado.";
            } else {
                $stockAntes = floatval($prodData['stock_cantidad']);
                $costoActual = floatval($prodData['costo'] ?? 0);

                if ($tipoMovimiento === 'SALIDA') {
                    if ($stockAntes < $cantidad) {
                        $etiquetaUnid = ($prodData['unidad_medida'] === 'LITRO') ? ' L (litros)' : '';
                        $error = "Stock insuficiente para realizar la salida. Existencias actuales: " . number_format($stockAntes, 4) . $etiquetaUnid;
                    } else {
                        $stmt = $db->prepare("UPDATE productos SET stock_cantidad = stock_cantidad - :cant WHERE id = :id");
                        $stmt->bindParam(':cant', $cantidad);
                        $stmt->bindParam(':id', $productoId);

                        if ($stmt->execute()) {
                            $stockDespues = $stockAntes - $cantidad;
                            $historialModel->registrar([
                                'tipo_movimiento'      => 'SALIDA STOCK',
                                'producto_id'          => $prodData['id'],
                                'producto_sku'         => $prodData['codigo_interno_sku'],
                                'producto_descripcion' => $prodData['descripcion'],
                                'tipo_producto'        => $prodData['tipo'],
                                'cantidad'             => $cantidad,
                                'costo_unitario'       => $costoActual,
                                'stock_antes'          => $stockAntes,
                                'stock_despues'        => $stockDespues,
                                'motivo'               => $motivo ?: 'Salida de inventario',
                                'usuario_id'           => $_SESSION['usuario_id'] ?? null,
                                'usuario_nombre'       => $_SESSION['nombre'] ?? 'Usuario'
                            ]);

                            if ($prodData['unidad_medida'] === 'LITRO' && $unidadLiquido !== 'LITRO') {
                                $etiqOrig = ($unidadLiquido === 'MILILITRO') ? 'mL' : 'gal';
                                $mensaje = "Salida registrada: -" . number_format($cantidadRaw, 2) . " {$etiqOrig} = -" . number_format($cantidad, 4) . " L del stock.";
                            } else {
                                $mensaje = "Salida de stock registrada exitosamente (-" . number_format($cantidad, 4) . ($prodData['unidad_medida'] === 'LITRO' ? ' L' : '') . ").";
                            }
                            $productosNormales = $productoModel->obtenerProductosNormales();
                        } else {
                            $error = "No se pudo registrar la salida de stock.";
                        }
                    }
                } else {
                    // ENTRADA
                    $nuevoCostoUnitario = ($permitirEditarCosto && $editarCosto >= 0) ? $editarCosto : $costoActual;

                    if ($permitirEditarCosto && $editarCosto >= 0) {
                        $stmt = $db->prepare("UPDATE productos SET stock_cantidad = stock_cantidad + :cant, costo = :costo WHERE id = :id");
                        $stmt->bindParam(':cant', $cantidad);
                        $stmt->bindParam(':costo', $nuevoCostoUnitario);
                        $stmt->bindParam(':id', $productoId);
                    } else {
                        $stmt = $db->prepare("UPDATE productos SET stock_cantidad = stock_cantidad + :cant WHERE id = :id");
                        $stmt->bindParam(':cant', $cantidad);
                        $stmt->bindParam(':id', $productoId);
                    }

                    if ($stmt->execute()) {
                        $stockDespues = $stockAntes + $cantidad;
                        $historialModel->registrar([
                            'tipo_movimiento'      => 'ENTRADA STOCK',
                            'producto_id'          => $prodData['id'],
                            'producto_sku'         => $prodData['codigo_interno_sku'],
                            'producto_descripcion' => $prodData['descripcion'],
                            'tipo_producto'        => $prodData['tipo'],
                            'cantidad'             => $cantidad,
                            'costo_unitario'       => $nuevoCostoUnitario,
                            'stock_antes'          => $stockAntes,
                            'stock_despues'        => $stockDespues,
                            'motivo'               => $motivo ?: 'Entrada de inventario',
                            'usuario_id'           => $_SESSION['usuario_id'] ?? null,
                            'usuario_nombre'       => $_SESSION['nombre'] ?? 'Usuario'
                        ]);

                        // Construir mensaje con informacion de conversion si aplica
                        if ($prodData['unidad_medida'] === 'LITRO' && $unidadLiquido !== 'LITRO') {
                            $etiqOrig = ($unidadLiquido === 'MILILITRO') ? 'mL' : 'gal';
                            $mensaje = "Entrada registrada: +" . number_format($cantidadRaw, 2) . " {$etiqOrig} = +" . number_format($cantidad, 4) . " L al stock.";
                        } else {
                            $mensaje = "Entrada de stock registrada exitosamente (+" . number_format($cantidad, 4) . ($prodData['unidad_medida'] === 'LITRO' ? ' L' : '') . ").";
                        }
                        if ($permitirEditarCosto && $editarCosto >= 0) {
                            $mensaje .= " El costo por unidad fue actualizado a $" . number_format($nuevoCostoUnitario, 2) . ".";
                        }
                        $productosNormales = $productoModel->obtenerProductosNormales();
                    } else {
                        $error = "No se pudo registrar la entrada de stock.";
                    }
                }
            }
        } catch (Exception $e) {
            $error = "Error en base de datos: " . $e->getMessage();
        }
    }
}

// Obtener inventario actual de todos los productos
$queryStock = "SELECT id, codigo_barras, codigo_interno_sku, descripcion, tipo, unidad_medida, stock_cantidad, costo, precio_venta 
               FROM productos 
               WHERE tipo = 'NORMAL' AND estado = 'ACTIVO' 
               ORDER BY descripcion ASC";
$stmtStock = $db->query($queryStock);
$inventario = $stmtStock->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entradas / Salidas | Lavandería</title>
    <link rel="stylesheet" href="css/index.css">
    <style>
        .table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; color: var(--text-main); }
        .table th { padding: 0.75rem 1rem; text-align: left; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); border-bottom: 2px solid var(--border); }
        .table td { padding: 1rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        .table tr:hover td { background: rgba(255,255,255,0.02); }
        .badge { padding: 0.25rem 0.75rem; border-radius: 99px; font-size: 0.75rem; font-weight: 600; }
        .badge-normal { background: rgba(59, 130, 246, 0.2); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.3); }

        /* Modal Styles */
        .modal-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
            display: none; align-items: center; justify-content: center;
            z-index: 9999; animation: fadeIn 0.2s ease;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: #1e293b; border: 1px solid var(--border);
            border-radius: 20px; padding: 2rem; max-width: 500px; width: 90%;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); animation: slideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    </style>
</head>
<body>
<div class="app-container">
        <?php $activePage = 'stock_entradas'; require_once __DIR__ . '/partials/sidebar.php'; ?>

    <main class="main-content">
        <header style="margin-bottom: 2.5rem; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 style="font-size: 2rem;">Entradas / Salidas de Stock</h1>
                <p style="color: var(--text-muted);">Recepción y salida de insumos y control de existencias en almacén.</p>
            </div>
            <button type="button" class="btn-primary" style="width:auto; padding:0.6rem 1.25rem; font-size:0.9rem;" onclick="abrirModalEntrada()">
                ➕ Nuevo Movimiento
            </button>
        </header>

        <?php if ($mensaje): ?>
            <div class="alert" style="background: rgba(16, 185, 129, 0.1); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.2); padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem;">
                <?= htmlspecialchars($mensaje) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error" style="margin-bottom: 1.5rem;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="auth-card" style="width: 100%; max-width: 100%; padding: 2rem; overflow-x: auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
                <h3 style="margin: 0;">Existencias Actuales</h3>
                <!-- Buscador -->
                <div style="display: flex; align-items: center; gap: 0.5rem; background: rgba(255,255,255,0.05); border: 1px solid var(--border); border-radius: 10px; padding: 0.45rem 0.85rem; min-width: 260px;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted); flex-shrink:0;">
                        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                    </svg>
                    <input id="input-busqueda-inv" type="text" placeholder="Buscar por SKU o descripción…"
                        oninput="filtrarInventario(this.value)"
                        style="background:transparent; border:none; outline:none; color:var(--text-main); font-size:0.85rem; width:100%; min-width:0;"
                        autocomplete="off">
                    <button id="btn-limpiar-inv" onclick="limpiarBusquedaInv()" title="Limpiar búsqueda"
                        style="display:none; background:none; border:none; cursor:pointer; color:var(--text-muted); font-size:1rem; padding:0; line-height:1;">✕</button>
                </div>
            </div>
            <table class="table" id="tabla-inventario">
                <thead>
                    <tr>
                        <th>Cód / SKU</th>
                        <th>Descripción</th>
                        <th>Unidad</th>
                        <th id="th-inv-stock" onclick="sortInv('stock')" style="cursor:pointer; user-select:none; transition:all 0.2s;" title="Ordenar por Stock Actual">
                            <div style="display:inline-flex; align-items:center; gap:0.45rem;">
                                <span>Stock Actual</span>
                                <span id="ico-inv-stock" style="display:inline-flex; align-items:center;">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg>
                                </span>
                            </div>
                        </th>
                        <th id="th-inv-costo" onclick="sortInv('costo')" style="cursor:pointer; user-select:none; transition:all 0.2s;" title="Ordenar por Costo Unitario">
                            <div style="display:inline-flex; align-items:center; gap:0.45rem;">
                                <span>Costo Unit. ($)</span>
                                <span id="ico-inv-costo" style="display:inline-flex; align-items:center;">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg>
                                </span>
                            </div>
                        </th>
                        <th id="th-inv-precio" onclick="sortInv('precio')" style="cursor:pointer; user-select:none; transition:all 0.2s;" title="Ordenar por Precio Venta">
                            <div style="display:inline-flex; align-items:center; gap:0.45rem;">
                                <span>Precio Venta ($)</span>
                                <span id="ico-inv-precio" style="display:inline-flex; align-items:center;">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg>
                                </span>
                            </div>
                        </th>
                        <th style="text-align: right;">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($inventario)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2.5rem 1rem;">
                            No hay insumos registrados. <a href="productos_registro.php" style="color:var(--primary);text-decoration:none;">Registrar producto →</a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach($inventario as $inv): ?>
                    <tr class="fila-inv"
                        data-sku="<?= strtolower(htmlspecialchars($inv['codigo_interno_sku'] ?? '')) ?>"
                        data-descripcion="<?= strtolower(htmlspecialchars($inv['descripcion'] ?? '')) ?>"
                        data-stock="<?= floatval($inv['stock_cantidad'] ?? 0) ?>"
                        data-costo="<?= floatval($inv['costo'] ?? 0) ?>"
                        data-precio="<?= floatval($inv['precio_venta'] ?? 0) ?>"
                        data-filtrado="1">
                        <td style="font-size: 0.85rem; color: var(--text-muted);">
                            <?= htmlspecialchars($inv['codigo_barras'] ?? '') ?><br>
                            <small><?= htmlspecialchars($inv['codigo_interno_sku'] ?? '') ?></small>
                        </td>
                        <td style="font-weight: 500;"><?= htmlspecialchars($inv['descripcion']) ?></td>
                        <td>
                            <?php if ($inv['unidad_medida'] === 'LITRO'): ?>
                                <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); font-size: 0.78rem; font-weight: 600; padding: 0.25rem 0.6rem; border-radius: 6px;">
                                    Litros / mL / Gal
                                </span>
                            <?php else: ?>
                                <span class="badge badge-normal"><?= htmlspecialchars($inv['unidad_medida']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight: 700; font-size: 1.05rem; color: <?= ($inv['stock_cantidad'] <= 5) ? '#f87171' : '#34d399' ?>;">
                            <?php if ($inv['unidad_medida'] === 'LITRO'): 
                                $stkL = floatval($inv['stock_cantidad']);
                                $stkML = $stkL * 1000;
                                $stkGal = $stkL / 3.78541;
                            ?>
                                <div>
                                    <span><?= number_format($stkL, 4) ?> L</span>
                                    <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 500; margin-top: 2px;">
                                        ≈ <?= number_format($stkML, 0) ?> mL &bull; <?= number_format($stkGal, 2) ?> gal
                                    </div>
                                </div>
                            <?php else: ?>
                                <?= number_format($inv['stock_cantidad'], 2) ?>
                            <?php endif; ?>
                        </td>
                        <td style="color: var(--text-muted);">$<?= number_format($inv['costo'], 2) ?></td>
                        <td style="font-weight: 600;">$<?= number_format($inv['precio_venta'], 2) ?></td>
                        <td style="text-align: right;">
                            <button type="button" class="btn-primary" style="padding: 0.4rem 0.9rem; font-size: 0.8rem; width: auto;"
                                    onclick="abrirModalEntrada('<?= htmlspecialchars($inv['id'], ENT_QUOTES) ?>')">
                                Movimientos
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Fila sin resultados -->
            <div id="inv-sin-resultados" style="display:none; text-align:center; color:var(--text-muted); padding:2.5rem 1rem;">
                🔍 No se encontraron registros que coincidan con la búsqueda.
            </div>

            <!-- ══ PAGINADOR INVENTARIO ══════════════════════════════════ -->
            <div id="paginador-inv" style="
                display: flex; align-items: center; justify-content: space-between;
                flex-wrap: wrap; gap: 0.75rem; margin-top: 1.25rem;
                padding: 0.85rem 1.1rem;
                background: rgba(255,255,255,0.04);
                border: 1px solid var(--border); border-radius: 12px;
            ">
                <div id="pag-inv-info" style="font-size:0.82rem; color:var(--text-muted);"></div>
                <div id="pag-inv-botones" style="display:flex; gap:0.35rem; flex-wrap:wrap; align-items:center;"></div>
                <div style="display:flex; align-items:center; gap:0.5rem; font-size:0.82rem; color:var(--text-muted);">
                    <span>Mostrar</span>
                    <select id="sel-inv-pagina" onchange="cambiarInvPorPagina(this.value)" style="
                        background:var(--sidebar-bg); color:var(--text-main);
                        border:1px solid var(--border); border-radius:7px;
                        padding:0.3rem 0.5rem; font-size:0.82rem; cursor:pointer; outline:none;">
                        <option value="10" selected style="background:#1e293b;color:#f1f5f9;">10</option>
                        <option value="15" style="background:#1e293b;color:#f1f5f9;">15</option>
                        <option value="20" style="background:#1e293b;color:#f1f5f9;">20</option>
                        <option value="25" style="background:#1e293b;color:#f1f5f9;">25</option>
                        <option value="30" style="background:#1e293b;color:#f1f5f9;">30</option>
                    </select>
                    <span>por página</span>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Modal de Movimiento de Stock -->
<div id="modal-entrada" class="modal-overlay" onclick="cerrarModalEntrada(event)">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem;">
            <div id="modal-icon-container" style="width: 44px; height: 44px; border-radius: 12px; background: rgba(34, 197, 94, 0.15); display: flex; align-items: center; justify-content: center; color: #22c55e; font-size: 1.3rem;">
                📥
            </div>
            <div>
                <h3 id="modal-titulo" style="font-size: 1.25rem; color: #fff; margin-bottom: 0.2rem;">Registrar Movimiento</h3>
                <p id="modal-subtitulo" style="color: var(--text-muted); font-size: 0.85rem;">Incremento de existencias de insumo</p>
            </div>
        </div>

        <form method="POST" id="form-entrada" onsubmit="return solicitarGuardarEntrada(event)">
            <input type="hidden" name="action" value="registrar_entrada">

            <div class="form-group" style="margin-bottom: 1.15rem;">
                <label class="form-label">Tipo de Movimiento *</label>
                <div class="select-wrapper">
                    <select name="tipo_movimiento" id="entrada-tipo-movimiento" class="form-control" required onchange="onTipoMovimientoChange(this.value)">
                        <option value="ENTRADA">Entrada</option>
                        <option value="SALIDA">Salida</option>
                    </select>
                    <div class="select-arrow-btn">▼</div>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 1.15rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.45rem;">
                    <label class="form-label" style="margin-bottom: 0;">Producto / Insumo *</label>
                    <span id="tag-costo-unitario" style="font-size: 0.8rem; font-weight: 600; color: #60a5fa; background: rgba(59, 130, 246, 0.15); border: 1px solid rgba(59, 130, 246, 0.3); padding: 0.25rem 0.65rem; border-radius: 8px; display: none; align-items: center; gap: 0.35rem;">
                        💵 Costo por Unidad: <strong>$0.00</strong>
                    </span>
                </div>
                <div class="select-wrapper">
                    <select name="producto_id" id="entrada-producto-id" class="form-control" required onchange="calcularCostoTotal()">
                        <option value="">— Selecciona un producto —</option>
                        <?php foreach ($productosNormales as $p): ?>
                            <option value="<?= htmlspecialchars($p['id']) ?>" data-costo="<?= htmlspecialchars($p['costo'] ?? 0) ?>" data-unidad="<?= htmlspecialchars($p['unidad_medida'] ?? '') ?>">
                                <?= htmlspecialchars($p['descripcion']) ?> (<?= ($p['unidad_medida'] === 'LITRO') ? 'Litros / Mililitros / Galones' : htmlspecialchars($p['unidad_medida']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="select-arrow-btn">▼</div>
                </div>
            </div>

            <!-- Sub-unidad para líquidos (visible solo cuando el producto es LITRO) -->
            <div class="form-group" id="group-unidad-liquido" style="margin-bottom: 1.15rem; display: none; background: rgba(56, 189, 248, 0.05); border: 1px solid rgba(56, 189, 248, 0.2); border-radius: 12px; padding: 0.85rem 1rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.45rem;">
                    <label class="form-label" style="margin-bottom: 0; color: #38bdf8; font-weight: 600;">
                        🧪 Unidad Específica de la Cantidad *
                    </label>
                    <span style="font-size: 0.75rem; color: var(--text-muted);">Conversión automática</span>
                </div>
                <div class="select-wrapper">
                    <select name="unidad_liquido" id="entrada-unidad-liquido" class="form-control" onchange="calcularCostoTotal()">
                        <option value="LITRO" selected>Litros (L)</option>
                        <option value="MILILITRO">Mililitros (mL)</option>
                        <option value="GALON">Galones (gal ≈ 3.7854 L)</option>
                    </select>
                    <div class="select-arrow-btn">▼</div>
                </div>
                <div id="lbl-conversion-liquido" style="margin-top: 0.45rem; font-size: 0.8rem; color: #38bdf8; font-weight: 500; display: none;"></div>
            </div>

            <!-- Campo Editar Costo (Disponible solo cuando Tipo Movimiento == ENTRADA) -->
            <div id="container-editar-costo" style="margin-bottom: 1.15rem; background: rgba(15, 23, 42, 0.4); border: 1px solid var(--border); border-radius: 12px; padding: 0.85rem 1rem;">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.5rem;">
                    <label for="chk-editar-costo" style="font-size: 0.85rem; font-weight: 600; color: var(--text-main); display: flex; align-items: center; gap: 0.5rem; cursor: pointer; user-select: none;">
                        <input type="checkbox" name="permitir_editar_costo" id="chk-editar-costo" value="1" onchange="toggleEditarCosto()" style="width: 1.1rem; height: 1.1rem; accent-color: var(--primary); cursor: pointer;">
                        <span>Editar Costo ($)</span>
                    </label>
                    <span style="font-size: 0.75rem; color: var(--text-muted);">Actualizar costo por unidad del insumo</span>
                </div>
                <input type="number" step="0.01" min="0" name="editar_costo" id="entrada-editar-costo" class="form-control" placeholder="0.00" disabled oninput="calcularCostoTotal()">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.15rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" id="label-cantidad">Cantidad a Recibir *</label>
                    <input type="number" step="0.01" min="0.01" name="cantidad" id="entrada-cantidad" class="form-control" required placeholder="0.00" oninput="calcularCostoTotal()">
                </div>
                <div class="form-group" style="margin-bottom: 0;" id="group-costo-total">
                    <label class="form-label" id="label-costo">Costo Total ($)</label>
                    <input type="number" step="0.01" min="0" name="costo_total" id="entrada-costo-total" class="form-control" placeholder="0.00" readonly>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" id="label-motivo">Motivo / Proveedor</label>
                <input type="text" name="motivo" id="entrada-motivo" class="form-control" placeholder="Ej: Compra a proveedor / Reposición">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn-action" style="background: rgba(255,255,255,0.06); color: var(--text-main); padding: 0.65rem 1.25rem; border-radius: 8px;" onclick="cerrarModalEntrada()">Cancelar</button>
                <button type="submit" id="btn-guardar-movimiento" class="btn-primary" style="padding: 0.65rem 1.5rem; width: auto; font-size: 0.88rem; border-radius: 8px;">Guardar Entrada</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════
     MODAL CONFIRMAR MOVIMIENTO ENTRADA/SALIDA
═══════════════════════════════════════════════════ -->
<div id="modal-confirmar-entrada" class="modal-overlay" style="z-index: 10050;" onclick="cerrarModalConfirmarEntrada(event)">
    <div class="modal-box" style="max-width: 440px;" onclick="event.stopPropagation()">
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
            <div style="width: 42px; height: 42px; border-radius: 12px; background: rgba(59, 130, 246, 0.15); display: flex; align-items: center; justify-content: center; font-size: 1.3rem; color: #60a5fa;">
                📦
            </div>
            <div>
                <h3 id="conf-entrada-titulo" style="font-size: 1.2rem; color: #fff; margin: 0;">Confirmar Movimiento</h3>
                <p style="color: var(--text-muted); font-size: 0.82rem; margin-top: 0.15rem;">Registro de inventario</p>
            </div>
        </div>

        <p id="conf-entrada-mensaje" style="color: var(--text-muted); font-size: 0.9rem; line-height: 1.5; margin: 1rem 0;">
            ¿Estás seguro de que deseas registrar este movimiento de stock?
        </p>

        <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
            <button type="button" class="btn-action" style="background: rgba(255,255,255,0.06); color: var(--text-main); padding: 0.65rem 1.25rem; border-radius: 8px;" onclick="cerrarModalConfirmarEntrada()">Cancelar</button>
            <button type="button" class="btn-primary" style="width: auto; padding: 0.65rem 1.5rem; border-radius: 8px;" onclick="ejecutarGuardarEntrada()">Sí, Guardar</button>
        </div>
    </div>
</div>

<script>
    let entradaFormConfirmado = false;

    const FACTOR_ML_A_LITRO = 0.001;
    const FACTOR_GAL_A_LITRO = 3.78541;

    function getFactorLitros(unidadLiquido) {
        if (unidadLiquido === 'MILILITRO') return FACTOR_ML_A_LITRO;
        if (unidadLiquido === 'GALON') return FACTOR_GAL_A_LITRO;
        return 1.0;
    }

    function solicitarGuardarEntrada(e) {
        if (entradaFormConfirmado) {
            entradaFormConfirmado = false;
            return true;
        }
        if (e) e.preventDefault();

        const form = document.getElementById('form-entrada');
        if (!form.checkValidity()) {
            form.reportValidity();
            return false;
        }

        const tipoMov = document.getElementById('entrada-tipo-movimiento').value;
        const selProd = document.getElementById('entrada-producto-id');
        const optSelected = selProd && selProd.options[selProd.selectedIndex];
        const prodNombre = optSelected ? optSelected.text : '';
        const unidadMed = optSelected ? (optSelected.getAttribute('data-unidad') || '') : '';
        const cantidadInput = parseFloat(document.getElementById('entrada-cantidad').value || 0);

        let textoCantidad = `${cantidadInput.toFixed(2)} unidades`;
        if (unidadMed === 'LITRO') {
            const selUnidadLiq = document.getElementById('entrada-unidad-liquido');
            const uLiq = selUnidadLiq ? selUnidadLiq.value : 'LITRO';
            const factor = getFactorLitros(uLiq);
            const cantL = cantidadInput * factor;
            if (uLiq === 'MILILITRO') {
                textoCantidad = `${cantidadInput.toLocaleString()} mL (${cantL.toFixed(4)} L)`;
            } else if (uLiq === 'GALON') {
                textoCantidad = `${cantidadInput} Galones (${cantL.toFixed(4)} L)`;
            } else {
                textoCantidad = `${cantL.toFixed(4)} Litros`;
            }
        } else if (unidadMed) {
            textoCantidad = `${cantidadInput.toFixed(2)} ${unidadMed}`;
        }

        const txtTipo = tipoMov === 'SALIDA' ? 'Salida' : 'Entrada';
        document.getElementById('conf-entrada-titulo').textContent = `Confirmar ${txtTipo}`;
        document.getElementById('conf-entrada-mensaje').innerHTML = `¿Estás seguro de que deseas registrar esta <strong>${txtTipo}</strong> de <strong>${textoCantidad}</strong> para <strong>${escapeHtml(prodNombre)}</strong>?`;

        document.getElementById('modal-confirmar-entrada').classList.add('active');
        return false;
    }

    function cerrarModalConfirmarEntrada(e) {
        if (e && e.target !== e.currentTarget) return;
        document.getElementById('modal-confirmar-entrada').classList.remove('active');
    }

    function ejecutarGuardarEntrada() {
        entradaFormConfirmado = true;
        document.getElementById('modal-confirmar-entrada').classList.remove('active');
        document.getElementById('form-entrada').submit();
    }

    function escapeHtml(str) {
        return (str || '').replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    function toggleSubmenu(id) {
        const el = document.getElementById(id);
        if (el) el.classList.toggle('open');
    }

    function toggleEditarCosto() {
        const chk = document.getElementById('chk-editar-costo');
        const inpEditar = document.getElementById('entrada-editar-costo');
        const selProd = document.getElementById('entrada-producto-id');
        const selectedOption = selProd ? selProd.options[selProd.selectedIndex] : null;
        const costoBase = selectedOption ? (parseFloat(selectedOption.getAttribute('data-costo')) || 0) : 0;

        if (chk && chk.checked) {
            if (inpEditar) {
                inpEditar.disabled = false;
                if (!inpEditar.value || parseFloat(inpEditar.value) <= 0) {
                    inpEditar.value = costoBase > 0 ? costoBase.toFixed(2) : '';
                }
                inpEditar.focus();
            }
        } else {
            if (inpEditar) {
                inpEditar.disabled = true;
                inpEditar.value = costoBase > 0 ? costoBase.toFixed(2) : '0.00';
            }
        }
        calcularCostoTotal();
    }

    function calcularCostoTotal() {
        const tipo = document.getElementById('entrada-tipo-movimiento').value;
        const selProd = document.getElementById('entrada-producto-id');
        const inpCant = document.getElementById('entrada-cantidad');
        const inpCostoTotal = document.getElementById('entrada-costo-total');
        const tagCostoUnit = document.getElementById('tag-costo-unitario');
        const chkEditar = document.getElementById('chk-editar-costo');
        const inpEditar = document.getElementById('entrada-editar-costo');
        const grpUnidadLiq = document.getElementById('group-unidad-liquido');
        const selUnidadLiq = document.getElementById('entrada-unidad-liquido');
        const lblConv = document.getElementById('lbl-conversion-liquido');

        const selectedOption = selProd ? selProd.options[selProd.selectedIndex] : null;
        const costoBase = selectedOption ? (parseFloat(selectedOption.getAttribute('data-costo')) || 0) : 0;
        const unidad = selectedOption ? (selectedOption.getAttribute('data-unidad') || '') : '';
        const cant = parseFloat(inpCant.value) || 0;

        // Mostrar / ocultar subselector de líquidos
        const esLiquido = (unidad === 'LITRO');
        if (grpUnidadLiq) {
            grpUnidadLiq.style.display = esLiquido ? 'block' : 'none';
        }

        const unidadLiq = (esLiquido && selUnidadLiq) ? selUnidadLiq.value : 'LITRO';
        const factor = esLiquido ? getFactorLitros(unidadLiq) : 1.0;
        const cantEnLitros = cant * factor;

        // Mostrar info de conversión en tiempo real
        if (lblConv) {
            if (esLiquido && cant > 0) {
                if (unidadLiq === 'MILILITRO') {
                    lblConv.innerHTML = `🔄 <strong>${cant.toLocaleString()} mL</strong> equivalen a <strong>${cantEnLitros.toFixed(4)} Litros</strong> base.`;
                    lblConv.style.display = 'block';
                } else if (unidadLiq === 'GALON') {
                    lblConv.innerHTML = `🔄 <strong>${cant.toLocaleString()} Galones</strong> equivalen a <strong>${cantEnLitros.toFixed(4)} Litros</strong> base.`;
                    lblConv.style.display = 'block';
                } else {
                    lblConv.innerHTML = `🔄 <strong>${cantEnLitros.toFixed(2)} Litros</strong> base.`;
                    lblConv.style.display = 'block';
                }
            } else {
                lblConv.style.display = 'none';
            }
        }

        let costoAplicar = costoBase;

        if (chkEditar && chkEditar.checked && inpEditar && !inpEditar.disabled) {
            const valCustom = parseFloat(inpEditar.value);
            if (!isNaN(valCustom) && valCustom >= 0) {
                costoAplicar = valCustom;
            }
        } else if (inpEditar && inpEditar.disabled) {
            inpEditar.value = selectedOption && selectedOption.value ? (costoBase > 0 ? costoBase.toFixed(2) : '0.00') : '';
        }

        if (selectedOption && selectedOption.value) {
            if (tagCostoUnit) {
                const esEditado = (chkEditar && chkEditar.checked && costoAplicar !== costoBase);
                const etiquetaUnidad = (unidad === 'LITRO') ? 'LITRO' : unidad;
                tagCostoUnit.innerHTML = `💵 Costo por Unidad: <strong>$${costoAplicar.toFixed(2)}</strong>${esEditado ? ' <span style="color:#f59e0b;font-size:0.75rem;font-weight:bold;">(Editado)</span>' : ''}${etiquetaUnidad ? ' <span style="opacity:0.8;font-weight:normal;">/ ' + etiquetaUnidad + '</span>' : ''}`;
                tagCostoUnit.style.display = 'inline-flex';
            }
        } else {
            if (tagCostoUnit) {
                tagCostoUnit.style.display = 'none';
            }
        }

        if (tipo !== 'ENTRADA') {
            if (inpCostoTotal) inpCostoTotal.value = '0.00';
            return;
        }

        if (cant > 0 && costoAplicar > 0) {
            const total = esLiquido ? (cantEnLitros * costoAplicar) : (cant * costoAplicar);
            inpCostoTotal.value = total.toFixed(2);
        } else {
            inpCostoTotal.value = '0.00';
        }
    }

    function onTipoMovimientoChange(tipo) {
        const titulo = document.getElementById('modal-titulo');
        const subtitulo = document.getElementById('modal-subtitulo');
        const iconContainer = document.getElementById('modal-icon-container');
        const labelCant = document.getElementById('label-cantidad');
        const inpMotivo = document.getElementById('entrada-motivo');
        const groupCosto = document.getElementById('group-costo-total');
        const btnGuardar = document.getElementById('btn-guardar-movimiento');
        const containerEditar = document.getElementById('container-editar-costo');
        const chkEditar = document.getElementById('chk-editar-costo');
        const inpEditar = document.getElementById('entrada-editar-costo');

        if (tipo === 'SALIDA') {
            if (titulo) titulo.textContent = 'Registrar Salida';
            if (subtitulo) subtitulo.textContent = 'Disminución o merma de existencias';
            if (iconContainer) {
                iconContainer.innerHTML = '📤';
                iconContainer.style.background = 'rgba(239, 68, 68, 0.15)';
                iconContainer.style.color = '#f87171';
            }
            if (labelCant) labelCant.textContent = 'Cantidad a Retirar *';
            if (inpMotivo) inpMotivo.placeholder = 'Ej: Merma / Uso interno / Ajuste';
            if (groupCosto) groupCosto.style.opacity = '0.4';
            if (btnGuardar) {
                btnGuardar.textContent = 'Guardar Salida';
            }
            if (containerEditar) containerEditar.style.display = 'none';
            if (chkEditar) chkEditar.checked = false;
            if (inpEditar) {
                inpEditar.disabled = true;
                inpEditar.value = '';
            }
            calcularCostoTotal();
        } else {
            if (titulo) titulo.textContent = 'Registrar Entrada';
            if (subtitulo) subtitulo.textContent = 'Incremento de existencias de insumo';
            if (iconContainer) {
                iconContainer.innerHTML = '📥';
                iconContainer.style.background = 'rgba(34, 197, 94, 0.15)';
                iconContainer.style.color = '#22c55e';
            }
            if (labelCant) labelCant.textContent = 'Cantidad a Recibir *';
            if (inpMotivo) inpMotivo.placeholder = 'Ej: Compra a proveedor / Reposición';
            if (groupCosto) groupCosto.style.opacity = '1';
            if (btnGuardar) {
                btnGuardar.textContent = 'Guardar Entrada';
            }
            if (containerEditar) containerEditar.style.display = 'block';
            calcularCostoTotal();
        }
    }

    function abrirModalEntrada(productoId = '') {
        document.getElementById('entrada-tipo-movimiento').value = 'ENTRADA';
        document.getElementById('entrada-cantidad').value = '';
        document.getElementById('entrada-costo-total').value = '0.00';
        document.getElementById('entrada-motivo').value = '';
        const chkEditar = document.getElementById('chk-editar-costo');
        const inpEditar = document.getElementById('entrada-editar-costo');
        const selUnidadLiq = document.getElementById('entrada-unidad-liquido');
        if (selUnidadLiq) selUnidadLiq.value = 'LITRO';
        if (chkEditar) chkEditar.checked = false;
        if (inpEditar) {
            inpEditar.disabled = true;
            inpEditar.value = '';
        }
        if (productoId) {
            document.getElementById('entrada-producto-id').value = productoId;
        } else {
            document.getElementById('entrada-producto-id').value = '';
        }
        onTipoMovimientoChange('ENTRADA');
        calcularCostoTotal();
        document.getElementById('modal-entrada').classList.add('active');
    }

    function cerrarModalEntrada(event) {
        if (!event || event.target.id === 'modal-entrada' || event.type === 'click') {
            document.getElementById('modal-entrada').classList.remove('active');
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  BÚSQUEDA, ORDENAMIENTO Y PAGINACIÓN — INVENTARIO
    // ══════════════════════════════════════════════════════════════════════

    // ─ Búsqueda ─────────────────────────────────────────────────────
    function filtrarInventario(termino) {
        const q = (termino || '').trim().toLowerCase();
        const btnLimpiar = document.getElementById('btn-limpiar-inv');
        if (btnLimpiar) btnLimpiar.style.display = q.length > 0 ? 'block' : 'none';

        document.querySelectorAll('.fila-inv').forEach(f => {
            const sku  = f.dataset.sku || '';
            const desc = f.dataset.descripcion || '';
            f.dataset.filtrado = (!q || sku.includes(q) || desc.includes(q)) ? '1' : '0';
        });
        renderInvPagina(1);
    }

    function limpiarBusquedaInv() {
        const input = document.getElementById('input-busqueda-inv');
        if (input) { input.value = ''; input.focus(); filtrarInventario(''); }
    }

    // ─ Ordenamiento (2 estados por columna) ──────────────────────────
    const invSortState = { stock: 0, costo: 0, precio: 0 };
    const invSortColors = { stock: '#38bdf8', costo: '#4ade80', precio: '#a78bfa' };

    function sortInv(col) {
        // Reiniciar las otras columnas
        Object.keys(invSortState).forEach(k => {
            if (k !== col) {
                invSortState[k] = 0;
                const th  = document.getElementById('th-inv-' + k);
                const ico = document.getElementById('ico-inv-' + k);
                if (th)  th.style.color = '';
                if (ico) ico.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg>`;
            }
        });

        invSortState[col] = (invSortState[col] % 2) + 1; // toggle 1↔2

        const tbody = document.querySelector('#tabla-inventario tbody');
        const filas = Array.from(document.querySelectorAll('.fila-inv'));

        filas.sort((a, b) => {
            const av = parseFloat(a.dataset[col]) || 0;
            const bv = parseFloat(b.dataset[col]) || 0;
            return invSortState[col] === 1 ? av - bv : bv - av;
        });

        filas.forEach(f => tbody.appendChild(f));

        const color = invSortColors[col];
        const th    = document.getElementById('th-inv-' + col);
        const ico   = document.getElementById('ico-inv-' + col);
        if (th)  th.style.color = color;
        if (ico) {
            const arrow = invSortState[col] === 1 ? '▲' : '▼';
            const label = col === 'stock' ? (invSortState[col] === 1 ? '0→9' : '9→0')
                        : col === 'costo'  ? (invSortState[col] === 1 ? 'menor' : 'mayor')
                        :                   (invSortState[col] === 1 ? 'menor' : 'mayor');
            ico.innerHTML = `<span style="display:inline-flex;align-items:center;gap:3px;color:${color};font-size:0.75rem;font-weight:bold;background:${color}22;border:1px solid ${color}55;padding:0.1rem 0.4rem;border-radius:6px;">${arrow} ${label}</span>`;
        }

        renderInvPagina(1);
    }

    // ─ Motor de paginación ──────────────────────────────────────────
    let invPagActual = 1;
    let invPorPagina = 10;

    function getInvFiltradas() {
        return Array.from(document.querySelectorAll('.fila-inv'))
                    .filter(f => f.dataset.filtrado !== '0');
    }

    function renderInvPagina(pagina) {
        const filas       = getInvFiltradas();
        const total       = filas.length;
        const totalPags   = Math.max(1, Math.ceil(total / invPorPagina));
        invPagActual      = Math.min(Math.max(1, pagina), totalPags);

        const inicio = (invPagActual - 1) * invPorPagina;
        const fin    = inicio + invPorPagina;

        document.querySelectorAll('.fila-inv').forEach(f => f.style.display = 'none');
        filas.forEach((f, i) => { f.style.display = (i >= inicio && i < fin) ? '' : 'none'; });

        const sinRes = document.getElementById('inv-sin-resultados');
        if (sinRes) sinRes.style.display = total === 0 ? '' : 'none';

        const infoEl = document.getElementById('pag-inv-info');
        if (infoEl) {
            infoEl.textContent = total === 0 ? 'Sin resultados'
                : `Mostrando ${inicio + 1}–${Math.min(fin, total)} de ${total} registro${total !== 1 ? 's' : ''}`;
        }

        const botsEl = document.getElementById('pag-inv-botones');
        if (!botsEl) return;
        botsEl.innerHTML = '';

        const btnS = (activo) => `cursor:pointer;border:1px solid var(--border);border-radius:7px;padding:0.3rem 0.65rem;font-size:0.8rem;font-weight:600;transition:all 0.18s;background:${activo ? 'var(--primary)' : 'rgba(255,255,255,0.05)'};color:${activo ? '#fff' : 'var(--text-muted)'};min-width:2.1rem;text-align:center;`;

        const bPrev = document.createElement('button');
        bPrev.innerHTML = '&#8249;'; bPrev.title = 'Anterior';
        bPrev.style.cssText = btnS(false) + (invPagActual === 1 ? 'opacity:0.35;cursor:default;' : '');
        bPrev.disabled = invPagActual === 1;
        bPrev.onclick = () => renderInvPagina(invPagActual - 1);
        botsEl.appendChild(bPrev);

        const ven = 2;
        let pS = Math.max(1, invPagActual - ven);
        let pE = Math.min(totalPags, invPagActual + ven);

        if (pS > 1) {
            const b = document.createElement('button'); b.textContent = '1'; b.style.cssText = btnS(false);
            b.onclick = () => renderInvPagina(1); botsEl.appendChild(b);
            if (pS > 2) { const d = document.createElement('span'); d.textContent = '…'; d.style.cssText = 'padding:0 0.3rem;color:var(--text-muted);font-size:0.8rem;'; botsEl.appendChild(d); }
        }
        for (let p = pS; p <= pE; p++) {
            const b = document.createElement('button'); b.textContent = p; b.style.cssText = btnS(p === invPagActual);
            b.onclick = () => renderInvPagina(p); botsEl.appendChild(b);
        }
        if (pE < totalPags) {
            if (pE < totalPags - 1) { const d = document.createElement('span'); d.textContent = '…'; d.style.cssText = 'padding:0 0.3rem;color:var(--text-muted);font-size:0.8rem;'; botsEl.appendChild(d); }
            const b = document.createElement('button'); b.textContent = totalPags; b.style.cssText = btnS(false);
            b.onclick = () => renderInvPagina(totalPags); botsEl.appendChild(b);
        }

        const bNext = document.createElement('button');
        bNext.innerHTML = '&#8250;'; bNext.title = 'Siguiente';
        bNext.style.cssText = btnS(false) + (invPagActual === totalPags ? 'opacity:0.35;cursor:default;' : '');
        bNext.disabled = invPagActual === totalPags;
        bNext.onclick = () => renderInvPagina(invPagActual + 1);
        botsEl.appendChild(bNext);
    }

    function cambiarInvPorPagina(val) {
        invPorPagina = parseInt(val, 10) || 10;
        renderInvPagina(1);
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.fila-inv').forEach(f => { if (!f.dataset.filtrado) f.dataset.filtrado = '1'; });
        renderInvPagina(1);
    });
</script>
<script src="js/sidebar.js"></script>
</body>
</html>
