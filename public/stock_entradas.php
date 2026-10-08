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

// ══════════════════════════════════════════════════════════════
// Procesar registro de Movimientos de Stock (múltiples filas)
// ══════════════════════════════════════════════════════════════
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($_POST['action'] ?? '') == 'registrar_entrada') {
    $tipoMovimiento = trim($_POST['tipo_movimiento'] ?? 'ENTRADA');
    $motivoGlobal   = trim($_POST['motivo'] ?? '');

    // Los campos vienen como arrays: producto_id[], presentacion[], cantidad[], etc.
    $productoIds         = $_POST['producto_id']         ?? [];
    $presentaciones      = $_POST['presentacion']        ?? [];
    $cantidades          = $_POST['cantidad']            ?? [];
    $unidadesLiquido     = $_POST['unidad_liquido']      ?? [];
    $permitirEditar      = $_POST['permitir_editar_costo'] ?? [];
    $editarCostos        = $_POST['editar_costo']        ?? [];

    $totalFilas = count($productoIds);
    if ($totalFilas === 0) {
        $error = "No hay filas de productos para procesar.";
    } else {
        $mensajes = [];
        $errores  = [];

        for ($i = 0; $i < $totalFilas; $i++) {
            $productoId          = trim($productoIds[$i] ?? '');
            $presentacion        = floatval($presentaciones[$i] ?? 0);
            $factorCantidad      = floatval($cantidades[$i]     ?? 1);
            if ($factorCantidad <= 0) $factorCantidad = 1;
            $cantidadRaw         = $presentacion * $factorCantidad;
            $unidadLiquido       = trim($unidadesLiquido[$i] ?? 'LITRO');
            $permitirEditarCosto = isset($permitirEditar[$i]) && $permitirEditar[$i] == '1';
            $editarCosto         = floatval($editarCostos[$i] ?? 0);

            if (empty($productoId) || $cantidadRaw <= 0) {
                $errores[] = "Fila " . ($i + 1) . ": selecciona un producto e ingresa presentación y cantidad válidas.";
                continue;
            }

            try {
                $stmtCheck = $db->prepare("SELECT id, codigo_interno_sku, descripcion, tipo, unidad_medida, stock_cantidad, costo FROM productos WHERE id = :id");
                $stmtCheck->execute([':id' => $productoId]);
                $prodData = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                if (!$prodData) { $errores[] = "Fila " . ($i+1) . ": producto no encontrado."; continue; }

                $cantidad    = $cantidadRaw;
                if ($prodData['unidad_medida'] === 'LITRO') {
                    $cantidad = convertirALitros($cantidadRaw, $unidadLiquido);
                }

                $stockAntes  = floatval($prodData['stock_cantidad']);
                $costoActual = floatval($prodData['costo'] ?? 0);

                if ($tipoMovimiento === 'SALIDA') {
                    if ($stockAntes < $cantidad) {
                        $etiq = ($prodData['unidad_medida'] === 'LITRO') ? ' L' : '';
                        $errores[] = "Fila " . ($i+1) . " ({$prodData['descripcion']}): stock insuficiente. Existencias: " . number_format($stockAntes, 2) . $etiq;
                        continue;
                    }
                    $stmt = $db->prepare("UPDATE productos SET stock_cantidad = stock_cantidad - :cant WHERE id = :id");
                    $stmt->bindParam(':cant', $cantidad); $stmt->bindParam(':id', $productoId);
                    if ($stmt->execute()) {
                        $stockDespues = $stockAntes - $cantidad;
                        $historialModel->registrar(['tipo_movimiento'=>'SALIDA STOCK','producto_id'=>$prodData['id'],'producto_sku'=>$prodData['codigo_interno_sku'],'producto_descripcion'=>$prodData['descripcion'],'tipo_producto'=>$prodData['tipo'],'cantidad'=>$cantidad,'costo_unitario'=>$costoActual,'stock_antes'=>$stockAntes,'stock_despues'=>$stockDespues,'motivo'=>$motivoGlobal ?: 'Salida de inventario','usuario_id'=>$_SESSION['usuario_id']??null,'usuario_nombre'=>$_SESSION['nombre']??'Usuario']);
                        $detalleMult = ($factorCantidad > 1) ? " ({$factorCantidad}×" . number_format($presentacion, 2) . ")" : "";
                        $mensajes[] = "✔ {$prodData['descripcion']}: -" . number_format($cantidad, 2) . ($prodData['unidad_medida']==='LITRO'?' L':'') . "{$detalleMult}";
                    } else { $errores[] = "Fila " . ($i+1) . ": no se pudo registrar la salida."; }
                } else {
                    $nuevoCosto = ($permitirEditarCosto && $editarCosto >= 0) ? $editarCosto : $costoActual;
                    if ($permitirEditarCosto && $editarCosto >= 0) {
                        $stmt = $db->prepare("UPDATE productos SET stock_cantidad = stock_cantidad + :cant, costo = :costo WHERE id = :id");
                        $stmt->bindParam(':cant', $cantidad); $stmt->bindParam(':costo', $nuevoCosto); $stmt->bindParam(':id', $productoId);
                    } else {
                        $stmt = $db->prepare("UPDATE productos SET stock_cantidad = stock_cantidad + :cant WHERE id = :id");
                        $stmt->bindParam(':cant', $cantidad); $stmt->bindParam(':id', $productoId);
                    }
                    if ($stmt->execute()) {
                        $stockDespues = $stockAntes + $cantidad;
                        $historialModel->registrar(['tipo_movimiento'=>'ENTRADA STOCK','producto_id'=>$prodData['id'],'producto_sku'=>$prodData['codigo_interno_sku'],'producto_descripcion'=>$prodData['descripcion'],'tipo_producto'=>$prodData['tipo'],'cantidad'=>$cantidad,'costo_unitario'=>$nuevoCosto,'stock_antes'=>$stockAntes,'stock_despues'=>$stockDespues,'motivo'=>$motivoGlobal ?: 'Entrada de inventario','usuario_id'=>$_SESSION['usuario_id']??null,'usuario_nombre'=>$_SESSION['nombre']??'Usuario']);
                        $detalleMult = ($factorCantidad > 1) ? " ({$factorCantidad}×" . number_format($presentacion, 2) . ")" : "";
                        $costoMsg = ($permitirEditarCosto && $editarCosto >= 0) ? " | Costo actualizado a $" . number_format($nuevoCosto, 2) : "";
                        $mensajes[] = "✔ {$prodData['descripcion']}: +" . number_format($cantidad, 2) . ($prodData['unidad_medida']==='LITRO'?' L':'') . "{$detalleMult}{$costoMsg}";
                    } else { $errores[] = "Fila " . ($i+1) . ": no se pudo registrar la entrada."; }
                }
            } catch (Exception $e) {
                $errores[] = "Fila " . ($i+1) . ": error BD — " . $e->getMessage();
            }
        }

        $productosNormales = $productoModel->obtenerProductosNormales();
        if (!empty($mensajes)) {
            $tipoLabel = ($tipoMovimiento === 'SALIDA') ? 'Salida' : 'Entrada';
            $mensaje = "{$tipoLabel} procesada " . count($mensajes) . " de {$totalFilas} producto(s):\n" . implode("\n", $mensajes);
        }
        if (!empty($errores)) {
            $error = implode("\n", $errores);
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
            display: none; align-items: flex-start; justify-content: center;
            z-index: 9999; animation: fadeIn 0.2s ease;
            padding: 1.5rem 1rem; overflow-y: auto;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: #1e293b; border: 1px solid var(--border);
            border-radius: 20px; padding: 2rem; max-width: 860px; width: 100%;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); animation: slideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            margin: auto;
        }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        /* Fila de producto en el modal multi-entrada */
        .entrada-row {
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.1rem 1.2rem 1rem;
            margin-bottom: 0.85rem;
            position: relative;
            transition: border-color 0.2s;
        }
        .entrada-row:hover { border-color: rgba(99,102,241,0.35); }
        .entrada-row-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 0.85rem;
        }
        .entrada-row-num {
            font-size: 0.72rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.08em; color: var(--text-muted);
            background: rgba(255,255,255,0.06); border-radius: 6px;
            padding: 0.2rem 0.55rem;
        }
        .btn-remove-row {
            background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.25);
            color: #f87171; border-radius: 8px; padding: 0.3rem 0.65rem;
            font-size: 0.78rem; font-weight: 600; cursor: pointer;
            transition: all 0.18s;
        }
        .btn-remove-row:hover { background: rgba(239,68,68,0.25); border-color: rgba(239,68,68,0.5); }
        .btn-add-row {
            display: flex; align-items: center; gap: 0.5rem;
            background: rgba(99,102,241,0.12); border: 1.5px dashed rgba(99,102,241,0.4);
            color: #a5b4fc; border-radius: 12px; padding: 0.7rem 1.2rem;
            font-size: 0.88rem; font-weight: 600; cursor: pointer; width: 100%;
            justify-content: center; transition: all 0.2s; margin-bottom: 1.25rem;
        }
        .btn-add-row:hover { background: rgba(99,102,241,0.22); border-color: rgba(99,102,241,0.65); color: #c7d2fe; }
        .row-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem; }
        .row-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
        .liq-panel {
            background: rgba(56,189,248,0.05); border: 1px solid rgba(56,189,248,0.2);
            border-radius: 10px; padding: 0.75rem 0.9rem; margin-top: 0.65rem; display: none;
        }
        .edit-costo-panel {
            background: rgba(15,23,42,0.4); border: 1px solid var(--border);
            border-radius: 10px; padding: 0.75rem 0.9rem; margin-top: 0.65rem;
        }
        .resumen-fila {
            font-size: 0.78rem; color: #a5b4fc; font-weight: 500;
            margin-top: 0.5rem; display: none;
        }
        .separador-global {
            border: none; border-top: 1px solid var(--border);
            margin: 1.25rem 0;
        }
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
                                    Litros
                                </span>
                            <?php else: ?>
                                <span class="badge badge-normal"><?= htmlspecialchars($inv['unidad_medida']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight: 700; font-size: 1.05rem; color: <?= ($inv['stock_cantidad'] <= 5) ? '#f87171' : '#34d399' ?>;">
                            <?php if ($inv['unidad_medida'] === 'LITRO'): 
                                $stkL = floatval($inv['stock_cantidad']);
                            ?>
                                <?= number_format($stkL, 2) ?> L
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

<!-- ══════════════════════════════════════════════════
     MODAL MULTI-ENTRADA / SALIDA DE STOCK
═══════════════════════════════════════════════════ -->
<div id="modal-entrada" class="modal-overlay" onclick="cerrarModalEntrada(event)">
    <div class="modal-box" onclick="event.stopPropagation()">

        <!-- Cabecera del modal -->
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1.4rem;">
            <div style="display:flex; align-items:center; gap:1rem;">
                <div id="modal-icon-container" style="width:44px;height:44px;border-radius:12px;background:rgba(34,197,94,0.15);display:flex;align-items:center;justify-content:center;color:#22c55e;font-size:1.3rem;">📥</div>
                <div>
                    <h3 id="modal-titulo" style="font-size:1.2rem;color:#fff;margin-bottom:0.15rem;">Registrar Entradas</h3>
                    <p id="modal-subtitulo" style="color:var(--text-muted);font-size:0.82rem;margin:0;">Puedes agregar múltiples productos en una sola operación</p>
                </div>
            </div>
            <button type="button" onclick="cerrarModalEntrada()" style="background:none;border:none;color:var(--text-muted);font-size:1.4rem;cursor:pointer;line-height:1;padding:0.2rem 0.5rem;border-radius:6px;transition:color 0.2s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='var(--text-muted)'">✕</button>
        </div>

        <form method="POST" id="form-entrada" onsubmit="return solicitarGuardarEntrada(event)">
            <input type="hidden" name="action" value="registrar_entrada">

            <!-- Tipo de movimiento + Motivo global -->
            <div style="display:grid;grid-template-columns:1fr 2fr;gap:0.85rem;margin-bottom:1.15rem;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Tipo de Movimiento *</label>
                    <div class="select-wrapper">
                        <select name="tipo_movimiento" id="entrada-tipo-movimiento" class="form-control" required onchange="onTipoMovimientoChange(this.value)">
                            <option value="ENTRADA">Entrada</option>
                            <option value="SALIDA">Salida</option>
                        </select>
                        <div class="select-arrow-btn">▼</div>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" id="label-motivo">Motivo / Proveedor</label>
                    <input type="text" name="motivo" id="entrada-motivo" class="form-control" placeholder="Ej: Compra a proveedor / Reposición">
                </div>
            </div>

            <hr class="separador-global">

            <!-- Contenedor de filas de productos -->
            <div id="contenedor-filas-entrada"></div>

            <!-- Botón Agregar Otra Entrada -->
            <button type="button" class="btn-add-row" id="btn-agregar-fila" onclick="agregarFilaEntrada()">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Agregar Otra Entrada
            </button>

            <!-- Resumen total (solo entradas) -->
            <div id="resumen-total-costo" style="display:none;background:rgba(74,222,128,0.08);border:1px solid rgba(74,222,128,0.2);border-radius:12px;padding:0.75rem 1.1rem;margin-bottom:1.2rem;font-size:0.9rem;color:#4ade80;font-weight:600;"></div>

            <!-- Botones de acción -->
            <div style="display:flex;justify-content:space-between;align-items:center;gap:0.75rem;margin-top:0.5rem;">
                <button type="button" class="btn-action" style="background:rgba(255,255,255,0.06);color:var(--text-main);padding:0.65rem 1.25rem;border-radius:8px;" onclick="cerrarModalEntrada()">Cancelar</button>
                <button type="submit" id="btn-guardar-movimiento" class="btn-primary" style="padding:0.65rem 1.75rem;width:auto;font-size:0.9rem;border-radius:8px;">💾 Guardar Entradas</button>
            </div><div id="modal-confirmar-entrada" class="modal-overlay" style="z-index:10050;" onclick="cerrarModalConfirmarEntrada(event)">
    <div class="modal-box" style="max-width:480px;" onclick="event.stopPropagation()">
        <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1rem;">
            <div style="width:42px;height:42px;border-radius:12px;background:rgba(59,130,246,0.15);display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:#60a5fa;">📦</div>
            <div>
                <h3 id="conf-entrada-titulo" style="font-size:1.15rem;color:#fff;margin:0;">Confirmar Movimiento</h3>
                <p style="color:var(--text-muted);font-size:0.82rem;margin-top:0.15rem;">Revisa el resumen antes de guardar</p>
            </div>
        </div>
        <div id="conf-entrada-mensaje" style="color:var(--text-muted);font-size:0.88rem;line-height:1.6;margin:1rem 0;max-height:300px;overflow-y:auto;"></div>
        <div style="display:flex;justify-content:flex-end;gap:0.75rem;margin-top:1.5rem;">
            <button type="button" class="btn-action" style="background:rgba(255,255,255,0.06);color:var(--text-main);padding:0.65rem 1.25rem;border-radius:8px;" onclick="cerrarModalConfirmarEntrada()">Cancelar</button>
            <button type="button" class="btn-primary" style="width:auto;padding:0.65rem 1.5rem;border-radius:8px;" onclick="ejecutarGuardarEntrada()">Sí, Guardar</button>
        </div>
    </div>
</div>

<script>
    let entradaFormConfirmado = false;
    const FACTOR_ML_A_LITRO  = 0.001;
    const FACTOR_GAL_A_LITRO = 3.78541;

    function stepNumberInput(id, dir) {
        const input = document.getElementById(id);
        if (!input || input.disabled) return;
        const step = parseFloat(input.getAttribute('step')) || 1;
        const min  = input.hasAttribute('min') ? parseFloat(input.getAttribute('min')) : -Infinity;
        const max  = input.hasAttribute('max') ? parseFloat(input.getAttribute('max')) : Infinity;
        let val = parseFloat(input.value) || 0;
        val = val + (step * dir);
        if (val < min) val = min;
        if (val > max) val = max;
        input.value = step < 1 ? val.toFixed(2) : val;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function stepRowInput(rowEl, field, dir) {
        const inp = rowEl.querySelector('[data-field="' + field + '"]');
        if (!inp) return;
        const step = parseFloat(inp.getAttribute('step')) || 1;
        const min  = inp.hasAttribute('min') ? parseFloat(inp.getAttribute('min')) : -Infinity;
        let val = parseFloat(inp.value) || 0;
        val = Math.max(min, val + step * dir);
        inp.value = step < 1 ? val.toFixed(2) : val;
        inp.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function getFactorLitros(u) {
        if (u === 'MILILITRO') return FACTOR_ML_A_LITRO;
        if (u === 'GALON')     return FACTOR_GAL_A_LITRO;
        return 1.0;
    }

    // Datos de productos inyectados desde PHP
    const PRODUCTOS_DATA = <?php
        $pdata = [];
        foreach ($productosNormales as $p) {
            $pdata[] = [
                'id'    => $p['id'],
                'label' => $p['descripcion'] . ' (' . (($p['unidad_medida'] === 'LITRO') ? 'Litros' : $p['unidad_medida']) . ')',
                'costo' => floatval($p['costo'] ?? 0),
                'unidad'=> $p['unidad_medida'] ?? ''
            ];
        }
        echo json_encode($pdata);
    ?>;

    let filaCounter = 0;

    function buildSelectOpts(selectedId) {
        selectedId = selectedId || '';
        let html = '<option value="">\u2014 Selecciona un producto \u2014</option>';
        PRODUCTOS_DATA.forEach(function(p) {
            var sel = (p.id == selectedId) ? ' selected' : '';
            html += '<option value="' + p.id + '" data-costo="' + p.costo + '" data-unidad="' + p.unidad + '"' + sel + '>' + escapeHtml(p.label) + '</option>';
        });
        return html;
    }

    function filtrarInsumosRow(inputEl, idx) {
        var query = inputEl.value.toLowerCase().trim();
        var row = document.getElementById('fila-entrada-' + idx);
        if (!row) return;
        var selectEl = row.querySelector('select[data-field="producto_id"]');
        if (!selectEl) return;

        var currentVal = selectEl.value;
        selectEl.innerHTML = '';

        var defaultOpt = document.createElement('option');
        defaultOpt.value = '';
        defaultOpt.textContent = '— Selecciona un producto —';
        selectEl.appendChild(defaultOpt);

        PRODUCTOS_DATA.forEach(function(p) {
            var matchesQuery = !query || p.label.toLowerCase().includes(query);
            var isCurrent = (currentVal && p.id == currentVal);

            if (matchesQuery || isCurrent) {
                var opt = document.createElement('option');
                opt.value = p.id;
                opt.setAttribute('data-costo', p.costo);
                opt.setAttribute('data-unidad', p.unidad);
                opt.textContent = p.label;
                if (isCurrent) {
                    opt.selected = true;
                }
                selectEl.appendChild(opt);
            }
        });

        if (currentVal && selectEl.value != currentVal) {
            selectEl.value = currentVal;
        }
    }

    function agregarFilaEntrada(productoId) {
        productoId = productoId || '';
        filaCounter++;
        var idx = filaCounter;
        var tipo = document.getElementById('entrada-tipo-movimiento').value;
        var esSalida = (tipo === 'SALIDA');

        var div = document.createElement('div');
        div.className = 'entrada-row';
        div.id = 'fila-entrada-' + idx;
        var numActual = document.querySelectorAll('.entrada-row').length + 1;
        var displayEditCosto = esSalida ? 'display:none;' : '';

        div.innerHTML =
            '<div class="entrada-row-header">' +
                '<span class="entrada-row-num">Producto #' + numActual + '</span>' +
                '<button type="button" class="btn-remove-row" onclick="eliminarFila(' + idx + ')">\u2715 Quitar</button>' +
            '</div>' +
            '<div class="form-group" style="margin-bottom:0.65rem;">' +
                '<label class="form-label" style="font-size:0.82rem;">Producto / Insumo *</label>' +
                '<div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">' +
                    '<div class="select-wrapper" style="flex:1;min-width:200px;">' +
                        '<select name="producto_id[]" class="form-control" required onchange="onRowProductoChange(this,' + idx + ')" data-field="producto_id">' +
                            buildSelectOpts(productoId) +
                        '</select>' +
                        '<div class="select-arrow-btn">\u25bc</div>' +
                    '</div>' +
                    '<div style="width:180px;position:relative;">' +
                        '<input type="text" class="form-control" placeholder="\ud83d\udd0d Buscar insumo..." style="font-size:0.82rem;height:38px;padding:0.4rem 0.65rem;background:rgba(15,23,42,0.6);" oninput="filtrarInsumosRow(this,' + idx + ')">' +
                    '</div>' +
                    '<span class="tag-costo-row" style="font-size:0.75rem;font-weight:600;color:#60a5fa;background:rgba(59,130,246,0.12);border:1px solid rgba(59,130,246,0.25);padding:0.2rem 0.55rem;border-radius:7px;white-space:nowrap;display:none;"></span>' +
                '</div>' +
            '</div>' +
            '<div class="liq-panel" id="liq-panel-' + idx + '">' +
                '<label class="form-label" style="font-size:0.8rem;color:#38bdf8;margin-bottom:0.4rem;">\ud83e\uddea Unidad de la presentaci\u00f3n</label>' +
                '<div class="select-wrapper">' +
                    '<select name="unidad_liquido[]" class="form-control" onchange="calcularFila(' + idx + ')" data-field="unidad_liquido">' +
                        '<option value="LITRO" selected>Litros (L)</option>' +
                        '<option value="MILILITRO">Mililitros (mL)</option>' +
                        '<option value="GALON">Galones (gal \u2248 3.7854 L)</option>' +
                    '</select>' +
                    '<div class="select-arrow-btn">\u25bc</div>' +
                '</div>' +
                '<div class="lbl-conv-row" style="margin-top:0.35rem;font-size:0.78rem;color:#38bdf8;font-weight:500;display:none;"></div>' +
            '</div>' +
            '<div class="row-grid-3" style="margin-top:0.65rem;">' +
                '<div class="form-group" style="margin-bottom:0;">' +
                    '<label class="form-label" style="font-size:0.82rem;">' + (esSalida ? 'Presentaci\u00f3n a Retirar *' : 'Presentaci\u00f3n *') + '</label>' +
                    '<div class="number-input-wrapper">' +
                        '<input type="number" step="0.01" min="0.01" name="presentacion[]" class="form-control" required placeholder="0.00" data-field="presentacion" oninput="calcularFila(' + idx + ')">' +
                        '<div class="spin-buttons">' +
                            '<button type="button" class="spin-btn" onclick="stepRowInput(document.getElementById(\'fila-entrada-' + idx + '\'),\'presentacion\',1)">\u25b2</button>' +
                            '<button type="button" class="spin-btn" onclick="stepRowInput(document.getElementById(\'fila-entrada-' + idx + '\'),\'presentacion\',-1)">\u25bc</button>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="form-group" style="margin-bottom:0;">' +
                    '<label class="form-label" style="font-size:0.82rem;">Cantidad *</label>' +
                    '<div class="number-input-wrapper">' +
                        '<input type="number" step="0.01" min="0.01" name="cantidad[]" class="form-control" required value="1.00" placeholder="1.00" data-field="cantidad" oninput="calcularFila(' + idx + ')">' +
                        '<div class="spin-buttons">' +
                            '<button type="button" class="spin-btn" onclick="stepRowInput(document.getElementById(\'fila-entrada-' + idx + '\'),\'cantidad\',1)">\u25b2</button>' +
                            '<button type="button" class="spin-btn" onclick="stepRowInput(document.getElementById(\'fila-entrada-' + idx + '\'),\'cantidad\',-1)">\u25bc</button>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="form-group" style="margin-bottom:0;">' +
                    '<label class="form-label" style="font-size:0.82rem;">Costo Total ($)</label>' +
                    '<input type="number" step="0.01" min="0" name="costo_total[]" class="form-control" placeholder="0.00" readonly data-field="costo_total" style="font-weight:700;color:#4ade80;">' +
                '</div>' +
            '</div>' +
            '<div class="resumen-fila" id="resumen-fila-' + idx + '"></div>' +
            '<div class="edit-costo-panel" id="edit-costo-panel-' + idx + '" style="margin-top:0.65rem;' + displayEditCosto + '">' +
                '<div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;margin-bottom:0.4rem;">' +
                    '<label style="font-size:0.82rem;font-weight:600;color:var(--text-main);display:flex;align-items:center;gap:0.4rem;cursor:pointer;user-select:none;">' +
                        '<input type="checkbox" name="permitir_editar_costo[' + idx + ']" value="1" onchange="toggleEditarCostoRow(' + idx + ')" style="width:1rem;height:1rem;accent-color:var(--primary);cursor:pointer;">' +
                        '<span>Actualizar Costo Unitario ($)</span>' +
                    '</label>' +
                    '<span style="font-size:0.72rem;color:var(--text-muted);">Actualiza precio de compra</span>' +
                '</div>' +
                '<input type="number" step="0.01" min="0" name="editar_costo[]" class="form-control" placeholder="0.00" disabled data-field="editar_costo" oninput="calcularFila(' + idx + ')" style="font-size:0.88rem;">' +
            '</div>';

        document.getElementById('contenedor-filas-entrada').appendChild(div);
        renumerarFilas();
        if (productoId) {
            onRowProductoChange(div.querySelector('select[data-field="producto_id"]'), idx);
        }
        actualizarResumenTotal();
    }

    function eliminarFila(idx) {
        var el = document.getElementById('fila-entrada-' + idx);
        if (el) el.remove();
        renumerarFilas();
        actualizarResumenTotal();
        if (document.querySelectorAll('.entrada-row').length === 0) agregarFilaEntrada();
    }

    function renumerarFilas() {
        document.querySelectorAll('.entrada-row').forEach(function(row, i) {
            var lbl = row.querySelector('.entrada-row-num');
            if (lbl) lbl.textContent = 'Producto #' + (i + 1);
        });
    }

    function onRowProductoChange(selectEl, idx) {
        var opt   = selectEl.options[selectEl.selectedIndex];
        var row   = document.getElementById('fila-entrada-' + idx);
        if (!row) return;
        var costo  = parseFloat((opt && opt.getAttribute('data-costo')) || 0);
        var unidad = (opt && opt.getAttribute('data-unidad')) || '';

        var tag = row.querySelector('.tag-costo-row');
        if (tag) {
            if (opt && opt.value) { tag.textContent = '\ud83d\udcb5 $' + costo.toFixed(2) + ' / ' + (unidad === 'LITRO' ? 'L' : unidad); tag.style.display = ''; }
            else tag.style.display = 'none';
        }
        var liqPanel = document.getElementById('liq-panel-' + idx);
        if (liqPanel) liqPanel.style.display = (unidad === 'LITRO') ? 'block' : 'none';

        var inpEditar = row.querySelector('[data-field="editar_costo"]');
        if (inpEditar && inpEditar.disabled) inpEditar.value = costo > 0 ? costo.toFixed(2) : '';

        calcularFila(idx);
    }

    function toggleEditarCostoRow(idx) {
        var row = document.getElementById('fila-entrada-' + idx);
        if (!row) return;
        var chk = row.querySelector('input[name="permitir_editar_costo[' + idx + ']"]');
        var inp = row.querySelector('[data-field="editar_costo"]');
        var sel = row.querySelector('[data-field="producto_id"]');
        var opt = sel ? sel.options[sel.selectedIndex] : null;
        var costoBase = parseFloat((opt && opt.getAttribute('data-costo')) || 0);
        if (chk && chk.checked) {
            if (inp) { inp.disabled = false; if (!inp.value || parseFloat(inp.value) <= 0) inp.value = costoBase > 0 ? costoBase.toFixed(2) : ''; inp.focus(); }
        } else {
            if (inp) { inp.disabled = true; inp.value = costoBase > 0 ? costoBase.toFixed(2) : '0.00'; }
        }
        calcularFila(idx);
    }

    function calcularFila(idx) {
        var row = document.getElementById('fila-entrada-' + idx);
        if (!row) return;
        var sel    = row.querySelector('[data-field="producto_id"]');
        var opt    = sel ? sel.options[sel.selectedIndex] : null;
        var unidad = (opt && opt.getAttribute('data-unidad')) || '';
        var costoBase = parseFloat((opt && opt.getAttribute('data-costo')) || 0);

        var inpPres = row.querySelector('[data-field="presentacion"]');
        var inpCant = row.querySelector('[data-field="cantidad"]');
        var pres = parseFloat((inpPres && inpPres.value) || 0) || 0;
        var cant = parseFloat((inpCant && inpCant.value) || 0) || 0;
        var totalCant = pres * cant;

        var esLiquido = (unidad === 'LITRO');
        var liqPanel  = document.getElementById('liq-panel-' + idx);
        if (liqPanel) liqPanel.style.display = esLiquido ? 'block' : 'none';

        var selULiq = row.querySelector('[data-field="unidad_liquido"]');
        var uLiq    = (esLiquido && selULiq) ? selULiq.value : 'LITRO';
        var factor  = esLiquido ? getFactorLitros(uLiq) : 1.0;
        var cantL   = totalCant * factor;

        var lblConv = row.querySelector('.lbl-conv-row');
        if (lblConv) {
            if (esLiquido && totalCant > 0) {
                if (uLiq === 'MILILITRO')
                    lblConv.innerHTML = '\ud83d\udd04 <strong>' + cant.toFixed(2) + ' \u00d7 ' + pres.toLocaleString() + ' mL = ' + totalCant.toLocaleString() + ' mL</strong> = <strong>' + cantL.toFixed(2) + ' L</strong>';
                else if (uLiq === 'GALON')
                    lblConv.innerHTML = '\ud83d\udd04 <strong>' + cant.toFixed(2) + ' \u00d7 ' + pres.toFixed(2) + ' Gal = ' + totalCant.toFixed(2) + ' Gal</strong> = <strong>' + cantL.toFixed(2) + ' L</strong>';
                else
                    lblConv.innerHTML = '\ud83d\udd04 <strong>' + cant.toFixed(2) + ' \u00d7 ' + pres.toFixed(2) + ' L = ' + cantL.toFixed(2) + ' Litros</strong>';
                lblConv.style.display = 'block';
            } else { lblConv.style.display = 'none'; }
        }

        var resumen = document.getElementById('resumen-fila-' + idx);
        if (resumen) {
            if (!esLiquido && totalCant > 0 && cant > 1) {
                resumen.innerHTML = '\ud83d\udce6 Total: <strong>' + cant.toFixed(2) + ' \u00d7 ' + pres.toFixed(2) + ' = ' + totalCant.toFixed(2) + ' ' + (unidad || 'uds.') + '</strong>';
                resumen.style.display = 'block';
            } else { resumen.style.display = 'none'; }
        }

        var tipo = document.getElementById('entrada-tipo-movimiento').value;
        var chk  = row.querySelector('input[name="permitir_editar_costo[' + idx + ']"]');
        var inpE = row.querySelector('[data-field="editar_costo"]');
        var costoAplicar = costoBase;
        if (chk && chk.checked && inpE && !inpE.disabled) {
            var cv = parseFloat(inpE.value);
            if (!isNaN(cv) && cv >= 0) costoAplicar = cv;
        }
        var inpCT = row.querySelector('[data-field="costo_total"]');
        if (inpCT) {
            if (tipo === 'ENTRADA' && totalCant > 0 && costoAplicar > 0) {
                inpCT.value = (esLiquido ? cantL * costoAplicar : totalCant * costoAplicar).toFixed(2);
            } else { inpCT.value = '0.00'; }
        }
        actualizarResumenTotal();
    }

    function actualizarResumenTotal() {
        var tipo  = document.getElementById('entrada-tipo-movimiento').value;
        var resEl = document.getElementById('resumen-total-costo');
        if (!resEl) return;
        if (tipo !== 'ENTRADA') { resEl.style.display = 'none'; return; }
        var total = 0;
        document.querySelectorAll('[data-field="costo_total"]').forEach(function(inp) { total += parseFloat(inp.value || 0) || 0; });
        var n = document.querySelectorAll('.entrada-row').length;
        if (total > 0) {
            resEl.innerHTML = '\ud83d\udcb0 Costo total (' + n + ' producto' + (n !== 1 ? 's' : '') + '): <strong>$' + total.toFixed(2) + '</strong>';
            resEl.style.display = 'block';
        } else { resEl.style.display = 'none'; }
    }

    function solicitarGuardarEntrada(e) {
        if (entradaFormConfirmado) { entradaFormConfirmado = false; return true; }
        if (e) e.preventDefault();
        var form = document.getElementById('form-entrada');
        if (!form.checkValidity()) { form.reportValidity(); return false; }

        var tipoMov = document.getElementById('entrada-tipo-movimiento').value;
        var txtTipo = tipoMov === 'SALIDA' ? 'Salida' : 'Entrada';
        var lineas  = [];

        document.querySelectorAll('.entrada-row').forEach(function(row) {
            var sel    = row.querySelector('[data-field="producto_id"]');
            var opt    = sel ? sel.options[sel.selectedIndex] : null;
            var nombre = opt ? escapeHtml(opt.text) : '\u2014';
            var pres   = parseFloat((row.querySelector('[data-field="presentacion"]') || {}).value || 0) || 0;
            var cant   = parseFloat((row.querySelector('[data-field="cantidad"]')    || {}).value || 0) || 0;
            var total  = pres * cant;
            var ct     = parseFloat((row.querySelector('[data-field="costo_total"]') || {}).value || 0) || 0;
            var unidad = (opt && opt.getAttribute('data-unidad')) || '';
            var det    = cant.toFixed(2) + ' \u00d7 ' + pres.toFixed(2) + ' = <strong>' + total.toFixed(2) + ' ' + (unidad === 'LITRO' ? 'L' : (unidad || 'uds.')) + '</strong>';
            if (tipoMov === 'ENTRADA' && ct > 0) det += ' | Costo: <strong>$' + ct.toFixed(2) + '</strong>';
            lineas.push('<li style="margin-bottom:0.4rem;padding:0.35rem 0.5rem;background:rgba(255,255,255,0.04);border-radius:7px;"><span style="color:#fff;">' + nombre + '</span><br><small>' + det + '</small></li>');
        });

        document.getElementById('conf-entrada-titulo').textContent = 'Confirmar ' + txtTipo;
        document.getElementById('conf-entrada-mensaje').innerHTML =
            '<p style="margin-bottom:0.6rem;">Se registrar\u00e1 la siguiente <strong>' + txtTipo + '</strong> de stock:</p>' +
            '<ul style="list-style:none;padding:0;margin:0;">' + lineas.join('') + '</ul>';

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
        return (str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function toggleSubmenu(id) {
        var el = document.getElementById(id);
        if (el) el.classList.toggle('open');
    }

    function onTipoMovimientoChange(tipo) {
        var titulo     = document.getElementById('modal-titulo');
        var subtitulo  = document.getElementById('modal-subtitulo');
        var iconCont   = document.getElementById('modal-icon-container');
        var btnGuardar = document.getElementById('btn-guardar-movimiento');
        var inpMotivo  = document.getElementById('entrada-motivo');
        var btnAgregar = document.getElementById('btn-agregar-fila');

        if (tipo === 'SALIDA') {
            if (titulo)    titulo.textContent    = 'Registrar Salidas de Stock';
            if (subtitulo) subtitulo.textContent = 'Disminuci\u00f3n o merma de existencias';
            if (iconCont)  { iconCont.innerHTML = '\ud83d\udce4'; iconCont.style.background = 'rgba(239,68,68,0.15)'; iconCont.style.color = '#f87171'; }
            if (btnGuardar) btnGuardar.innerHTML = '\ud83d\udcbe Guardar Salidas';
            if (btnAgregar) btnAgregar.lastChild.textContent = ' Agregar Otra Salida';
            if (inpMotivo)  inpMotivo.placeholder = 'Ej: Merma / Uso interno / Ajuste';
            document.querySelectorAll('[id^="edit-costo-panel-"]').forEach(function(p) { p.style.display = 'none'; });
        } else {
            if (titulo)    titulo.textContent    = 'Registrar Entradas de Stock';
            if (subtitulo) subtitulo.textContent = 'Puedes agregar m\u00faltiples productos en una sola operaci\u00f3n';
            if (iconCont)  { iconCont.innerHTML = '\ud83d\udce5'; iconCont.style.background = 'rgba(34,197,94,0.15)'; iconCont.style.color = '#22c55e'; }
            if (btnGuardar) btnGuardar.innerHTML = '\ud83d\udcbe Guardar Entradas';
            if (btnAgregar) btnAgregar.lastChild.textContent = ' Agregar Otra Entrada';
            if (inpMotivo)  inpMotivo.placeholder = 'Ej: Compra a proveedor / Reposici\u00f3n';
            document.querySelectorAll('[id^="edit-costo-panel-"]').forEach(function(p) { p.style.display = 'block'; });
        }
        document.querySelectorAll('.entrada-row').forEach(function(row) {
            var m = row.id.match(/fila-entrada-(\d+)/);
            if (m) calcularFila(parseInt(m[1]));
        });
        actualizarResumenTotal();
    }

    function abrirModalEntrada(productoId) {
        productoId = productoId || '';
        document.getElementById('contenedor-filas-entrada').innerHTML = '';
        filaCounter = 0;
        document.getElementById('entrada-tipo-movimiento').value = 'ENTRADA';
        document.getElementById('entrada-motivo').value = '';
        onTipoMovimientoChange('ENTRADA');
        agregarFilaEntrada(productoId);
        document.getElementById('modal-entrada').classList.add('active');
    }

    function cerrarModalEntrada(event) {
        if (!event || event.target.id === 'modal-entrada' || event.type === 'click') {
            document.getElementById('modal-entrada').classList.remove('active');
        }
    }

    // BUSQUEDA, ORDENAMIENTO Y PAGINACION
    function filtrarInventario(termino) {
        var q = (termino || '').trim().toLowerCase();
        var btnLimpiar = document.getElementById('btn-limpiar-inv');
        if (btnLimpiar) btnLimpiar.style.display = q.length > 0 ? 'block' : 'none';
        document.querySelectorAll('.fila-inv').forEach(function(f) {
            var sku  = f.dataset.sku || '';
            var desc = f.dataset.descripcion || '';
            f.dataset.filtrado = (!q || sku.includes(q) || desc.includes(q)) ? '1' : '0';
        });
        renderInvPagina(1);
    }

    function limpiarBusquedaInv() {
        var input = document.getElementById('input-busqueda-inv');
        if (input) { input.value = ''; input.focus(); filtrarInventario(''); }
    }

    var invSortState  = { stock: 0, costo: 0, precio: 0 };
    var invSortColors = { stock: '#38bdf8', costo: '#4ade80', precio: '#a78bfa' };

    function sortInv(col) {
        Object.keys(invSortState).forEach(function(k) {
            if (k !== col) {
                invSortState[k] = 0;
                var th  = document.getElementById('th-inv-' + k);
                var ico = document.getElementById('ico-inv-' + k);
                if (th)  th.style.color = '';
                if (ico) ico.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg>';
            }
        });
        invSortState[col] = (invSortState[col] % 2) + 1;
        var tbody = document.querySelector('#tabla-inventario tbody');
        var filas = Array.from(document.querySelectorAll('.fila-inv'));
        filas.sort(function(a, b) {
            var av = parseFloat(a.dataset[col]) || 0;
            var bv = parseFloat(b.dataset[col]) || 0;
            return invSortState[col] === 1 ? av - bv : bv - av;
        });
        filas.forEach(function(f) { tbody.appendChild(f); });
        var color = invSortColors[col];
        var th    = document.getElementById('th-inv-' + col);
        var ico   = document.getElementById('ico-inv-' + col);
        if (th)  th.style.color = color;
        if (ico) {
            var arrow = invSortState[col] === 1 ? '\u25b2' : '\u25bc';
            var label = col === 'stock' ? (invSortState[col] === 1 ? '0\u21929' : '9\u21920') : (invSortState[col] === 1 ? 'menor' : 'mayor');
            ico.innerHTML = '<span style="display:inline-flex;align-items:center;gap:3px;color:' + color + ';font-size:0.75rem;font-weight:bold;background:' + color + '22;border:1px solid ' + color + '55;padding:0.1rem 0.4rem;border-radius:6px;">' + arrow + ' ' + label + '</span>';
        }
        renderInvPagina(1);
    }

    var invPagActual = 1;
    var invPorPagina = 10;

    function getInvFiltradas() {
        return Array.from(document.querySelectorAll('.fila-inv')).filter(function(f) { return f.dataset.filtrado !== '0'; });
    }

    function renderInvPagina(pagina) {
        var filas     = getInvFiltradas();
        var total     = filas.length;
        var totalPags = Math.max(1, Math.ceil(total / invPorPagina));
        invPagActual  = Math.min(Math.max(1, pagina), totalPags);
        var inicio = (invPagActual - 1) * invPorPagina;
        var fin    = inicio + invPorPagina;
        document.querySelectorAll('.fila-inv').forEach(function(f) { f.style.display = 'none'; });
        filas.forEach(function(f, i) { f.style.display = (i >= inicio && i < fin) ? '' : 'none'; });
        var sinRes = document.getElementById('inv-sin-resultados');
        if (sinRes) sinRes.style.display = total === 0 ? '' : 'none';
        var infoEl = document.getElementById('pag-inv-info');
        if (infoEl) infoEl.textContent = total === 0 ? 'Sin resultados' : 'Mostrando ' + (inicio + 1) + '\u2013' + Math.min(fin, total) + ' de ' + total + ' registro' + (total !== 1 ? 's' : '');
        var botsEl = document.getElementById('pag-inv-botones');
        if (!botsEl) return;
        botsEl.innerHTML = '';
        var btnS = function(activo) { return 'cursor:pointer;border:1px solid var(--border);border-radius:7px;padding:0.3rem 0.65rem;font-size:0.8rem;font-weight:600;transition:all 0.18s;background:' + (activo ? 'var(--primary)' : 'rgba(255,255,255,0.05)') + ';color:' + (activo ? '#fff' : 'var(--text-muted)') + ';min-width:2.1rem;text-align:center;'; };
        var bPrev = document.createElement('button');
        bPrev.innerHTML = '&#8249;'; bPrev.title = 'Anterior';
        bPrev.style.cssText = btnS(false) + (invPagActual === 1 ? 'opacity:0.35;cursor:default;' : '');
        bPrev.disabled = invPagActual === 1;
        bPrev.onclick = function() { renderInvPagina(invPagActual - 1); };
        botsEl.appendChild(bPrev);
        var ven = 2, pS = Math.max(1, invPagActual - ven), pE = Math.min(totalPags, invPagActual + ven);
        if (pS > 1) {
            var b0 = document.createElement('button'); b0.textContent = '1'; b0.style.cssText = btnS(false);
            b0.onclick = function() { renderInvPagina(1); }; botsEl.appendChild(b0);
            if (pS > 2) { var d0 = document.createElement('span'); d0.textContent = '\u2026'; d0.style.cssText = 'padding:0 0.3rem;color:var(--text-muted);font-size:0.8rem;'; botsEl.appendChild(d0); }
        }
        for (var p = pS; p <= pE; p++) {
            (function(pg) {
                var b = document.createElement('button'); b.textContent = pg; b.style.cssText = btnS(pg === invPagActual);
                b.onclick = function() { renderInvPagina(pg); }; botsEl.appendChild(b);
            })(p);
        }
        if (pE < totalPags) {
            if (pE < totalPags - 1) { var d1 = document.createElement('span'); d1.textContent = '\u2026'; d1.style.cssText = 'padding:0 0.3rem;color:var(--text-muted);font-size:0.8rem;'; botsEl.appendChild(d1); }
            var bLast = document.createElement('button'); bLast.textContent = totalPags; bLast.style.cssText = btnS(false);
            bLast.onclick = function() { renderInvPagina(totalPags); }; botsEl.appendChild(bLast);
        }
        var bNext = document.createElement('button');
        bNext.innerHTML = '&#8250;'; bNext.title = 'Siguiente';
        bNext.style.cssText = btnS(false) + (invPagActual === totalPags ? 'opacity:0.35;cursor:default;' : '');
        bNext.disabled = invPagActual === totalPags;
        bNext.onclick = function() { renderInvPagina(invPagActual + 1); };
        botsEl.appendChild(bNext);
    }

    function cambiarInvPorPagina(val) {
        invPorPagina = parseInt(val, 10) || 10;
        renderInvPagina(1);
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.fila-inv').forEach(function(f) { if (!f.dataset.filtrado) f.dataset.filtrado = '1'; });
        renderInvPagina(1);
    });
</script>

<script src="js/sidebar.js"></script>
</body>
</html>
