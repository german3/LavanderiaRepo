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
    $motivosItem    = $_POST['motivo_item'] ?? [];

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
                    $motivoFila = trim($motivosItem[$i] ?? '') ?: ($motivoGlobal ?: ($tipoMovimiento === 'SALIDA' ? 'Salida de inventario' : 'Entrada de inventario'));

                    if ($stmt->execute()) {
                        $stockDespues = $stockAntes - $cantidad;
                        $historialModel->registrar(['tipo_movimiento'=>'SALIDA STOCK','producto_id'=>$prodData['id'],'producto_sku'=>$prodData['codigo_interno_sku'],'producto_descripcion'=>$prodData['descripcion'],'tipo_producto'=>$prodData['tipo'],'cantidad'=>$cantidad,'costo_unitario'=>$costoActual,'stock_antes'=>$stockAntes,'stock_despues'=>$stockDespues,'motivo'=>$motivoFila,'usuario_id'=>$_SESSION['usuario_id']??null,'usuario_nombre'=>$_SESSION['nombre']??'Usuario']);
                        $detalleMult = ($factorCantidad > 1) ? " ({$factorCantidad}×" . number_format($presentacion, 2) . ")" : "";
                        $mensajes[] = "✔ {$prodData['descripcion']}: -" . number_format($cantidad, 2) . ($prodData['unidad_medida']==='LITRO'?' L':'') . "{$detalleMult}";
                    } else { $errores[] = "Fila " . ($i+1) . ": no se pudo registrar la salida."; }
                } else {
                    $nuevoCosto = ($permitirEditarCosto && $editarCosto >= 0) ? $editarCosto : $costoActual;
                    $motivoFila = trim($motivosItem[$i] ?? '') ?: ($motivoGlobal ?: 'Entrada de inventario');
                    if ($permitirEditarCosto && $editarCosto >= 0) {
                        $stmt = $db->prepare("UPDATE productos SET stock_cantidad = stock_cantidad + :cant, costo = :costo WHERE id = :id");
                        $stmt->bindParam(':cant', $cantidad); $stmt->bindParam(':costo', $nuevoCosto); $stmt->bindParam(':id', $productoId);
                    } else {
                        $stmt = $db->prepare("UPDATE productos SET stock_cantidad = stock_cantidad + :cant WHERE id = :id");
                        $stmt->bindParam(':cant', $cantidad); $stmt->bindParam(':id', $productoId);
                    }
                    if ($stmt->execute()) {
                        $stockDespues = $stockAntes + $cantidad;
                        $historialModel->registrar(['tipo_movimiento'=>'ENTRADA STOCK','producto_id'=>$prodData['id'],'producto_sku'=>$prodData['codigo_interno_sku'],'producto_descripcion'=>$prodData['descripcion'],'tipo_producto'=>$prodData['tipo'],'cantidad'=>$cantidad,'costo_unitario'=>$nuevoCosto,'stock_antes'=>$stockAntes,'stock_despues'=>$stockDespues,'motivo'=>$motivoFila,'usuario_id'=>$_SESSION['usuario_id']??null,'usuario_nombre'=>$_SESSION['nombre']??'Usuario']);
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

        /* Panel de captura y tabla de registros pendientes */
        .captura-card {
            background: rgba(255,255,255,0.02);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.15rem 1.25rem;
            margin-bottom: 1.15rem;
            transition: border-color 0.2s;
        }
        .captura-card:hover { border-color: rgba(99,102,241,0.35); }
        .item-row-fade { animation: fadeInRow 0.22s ease-out; }
        @keyframes fadeInRow { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
        .btn-del-item {
            background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.25);
            color: #f87171; border-radius: 6px; padding: 0.25rem 0.55rem;
            font-size: 0.75rem; font-weight: 600; cursor: pointer; transition: all 0.15s;
        }
        .btn-del-item:hover { background: rgba(239, 68, 68, 0.28); color: #fff; border-color: rgba(239, 68, 68, 0.5); }
        .btn-add-row {
            display: flex; align-items: center; gap: 0.5rem;
            background: rgba(99,102,241,0.15); border: 1.5px dashed rgba(99,102,241,0.45);
            color: #c7d2fe; border-radius: 12px; padding: 0.75rem 1.2rem;
            font-size: 0.9rem; font-weight: 600; cursor: pointer; width: 100%;
            justify-content: center; transition: all 0.2s;
        }
        .btn-add-row:hover { background: rgba(99,102,241,0.25); border-color: rgba(99,102,241,0.7); color: #fff; }
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

        /* Combobox unificado estilo Select2 / jQuery */
        .combobox-wrapper { position: relative; }
        .combobox-dropdown {
            position: absolute; top: calc(100% + 4px); left: 0; right: 0;
            max-height: 250px; overflow-y: auto; background: #1e293b;
            border: 1px solid rgba(99,102,241,0.35); border-radius: 10px;
            z-index: 10050; box-shadow: 0 14px 30px rgba(0,0,0,0.6);
            display: none; animation: fadeIn 0.15s ease-out;
        }
        .combobox-item {
            padding: 0.65rem 0.9rem; display: flex; align-items: center;
            justify-content: space-between; gap: 0.6rem; cursor: pointer;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            font-size: 0.84rem; color: var(--text-main); transition: all 0.12s;
        }
        .combobox-item:last-child { border-bottom: none; }
        .combobox-item:hover, .combobox-item.active-item {
            background: rgba(99,102,241,0.18); color: #fff;
        }
        .combobox-item.selected-item {
            background: rgba(99,102,241,0.28); color: #c7d2fe; font-weight: 600;
        }
        .combobox-item-badge {
            font-size: 0.72rem; padding: 0.15rem 0.5rem; border-radius: 6px;
            background: rgba(255,255,255,0.06); color: var(--text-muted);
            white-space: nowrap; font-weight: 500;
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

            <!-- Tipo de movimiento -->
            <div style="margin-bottom:1.15rem;">
                <div class="form-group" style="margin-bottom:0; max-width:280px;">
                    <label class="form-label">Tipo de Movimiento *</label>
                    <div class="select-wrapper">
                        <select name="tipo_movimiento" id="entrada-tipo-movimiento" class="form-control" required onchange="onTipoMovimientoChange(this.value)">
                            <option value="ENTRADA">Entrada</option>
                            <option value="SALIDA">Salida</option>
                        </select>
                        <div class="select-arrow-btn">▼</div>
                    </div>
                </div>
            </div>

            <hr class="separador-global">

            <!-- Panel de Captura de Entrada / Insumo -->
            <div class="captura-card" id="panel-captura-entrada">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.85rem;">
                    <span style="font-size:0.82rem; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:#38bdf8; display:flex; align-items:center; gap:0.4rem;">
                        <span>📦</span> <span id="lbl-captura-titulo">Ingreso de Entrada</span>
                    </span>
                    <span id="alerta-validacion-captura" style="display:none; font-size:0.78rem; color:#f87171; background:rgba(239,68,68,0.12); border:1px solid rgba(239,68,68,0.25); padding:0.25rem 0.65rem; border-radius:6px; font-weight:500;"></span>
                </div>

                <!-- Selección Unificada de Producto / Insumo (Combobox con búsqueda) -->
                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label class="form-label" style="font-size:0.82rem;">Producto / Insumo *</label>
                    <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                        <div class="combobox-wrapper" id="combobox-producto-wrapper" style="flex:1; min-width:240px; position:relative;">
                            <input type="hidden" id="captura-producto-id" value="">
                            
                            <div style="position:relative; display:flex; align-items:center;">
                                <span style="position:absolute; left:0.75rem; color:var(--text-muted); font-size:0.88rem; pointer-events:none;">🔍</span>
                                <input type="text" id="captura-producto-input" class="form-control"
                                    placeholder="Escribe para buscar o despliega la lista..."
                                    autocomplete="off"
                                    style="padding-left:2.25rem; padding-right:3.25rem; cursor:pointer;"
                                    onclick="abrirDropdownProductos()"
                                    onfocus="abrirDropdownProductos()"
                                    oninput="onInputBusquedaProducto(this.value)"
                                    onkeydown="onKeydownProductoCombobox(event)">
                                
                                <div style="position:absolute; right:0.4rem; display:flex; align-items:center; gap:0.15rem;">
                                    <button type="button" id="btn-limpiar-producto" onclick="limpiarSeleccionProducto(event)" title="Limpiar selección" style="display:none; background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:0.9rem; padding:0.25rem 0.4rem; line-height:1; border-radius:4px; transition:color 0.15s;" onmouseover="this.style.color='#f87171'" onmouseout="this.style.color='var(--text-muted)'">✕</button>
                                    <button type="button" id="btn-toggle-dropdown" onclick="toggleDropdownProductos(event)" title="Desplegar lista" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:0.75rem; padding:0.35rem 0.5rem; line-height:1; border-radius:4px; transition:color 0.15s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='var(--text-muted)'">▼</button>
                                </div>
                            </div>

                            <!-- Menú flotante desplegable -->
                            <div id="captura-producto-dropdown" class="combobox-dropdown"></div>
                        </div>

                        <span id="captura-tag-costo" class="tag-costo-row" style="font-size:0.75rem; font-weight:600; color:#60a5fa; background:rgba(59,130,246,0.12); border:1px solid rgba(59,130,246,0.25); padding:0.25rem 0.6rem; border-radius:7px; white-space:nowrap; display:none;"></span>
                    </div>
                </div>

                <!-- Panel de Unidad Líquida -->
                <div class="liq-panel" id="captura-liq-panel" style="margin-bottom:0.75rem;">
                    <label class="form-label" style="font-size:0.8rem; color:#38bdf8; margin-bottom:0.4rem;">🧪 Unidad de la presentación</label>
                    <div class="select-wrapper">
                        <select id="captura-unidad-liquido" class="form-control" onchange="onUnidadLiquidoChange()">
                            <option value="LITRO" selected>Litros (L)</option>
                            <option value="MILILITRO">Mililitros (mL)</option>
                            <option value="GALON">Galones (gal ≈ 3.7854 L)</option>
                        </select>
                        <div class="select-arrow-btn">▼</div>
                    </div>
                    <div id="captura-lbl-conv" class="lbl-conv-row" style="margin-top:0.35rem; font-size:0.78rem; color:#38bdf8; font-weight:500; display:none;"></div>
                </div>

                <!-- Grid de Presentación, Cantidad y Costo Total -->
                <div class="row-grid-3" id="grid-captura-cantidades">
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label" id="lbl-captura-presentacion" style="font-size:0.82rem;">Presentación *</label>
                        <div class="number-input-wrapper">
                            <input type="number" step="0.01" min="0.01" id="captura-presentacion" class="form-control" placeholder="0.00" oninput="calcularCaptura()">
                            <div class="spin-buttons">
                                <button type="button" class="spin-btn" onclick="stepCapturaInput('presentacion', 1)">▲</button>
                                <button type="button" class="spin-btn" onclick="stepCapturaInput('presentacion', -1)">▼</button>
                            </div>
                        </div>
                    </div>
                    <div class="form-group" id="grupo-captura-cantidad" style="margin-bottom:0; display:none;">
                        <label class="form-label" style="font-size:0.82rem;">Cantidad *</label>
                        <div class="number-input-wrapper">
                            <input type="number" step="0.01" min="0.01" id="captura-cantidad" class="form-control" value="1.00" placeholder="1.00" oninput="calcularCaptura()">
                            <div class="spin-buttons">
                                <button type="button" class="spin-btn" onclick="stepCapturaInput('cantidad', 1)">▲</button>
                                <button type="button" class="spin-btn" onclick="stepCapturaInput('cantidad', -1)">▼</button>
                            </div>
                        </div>
                    </div>
                    <div class="form-group" id="grupo-captura-costo-total" style="margin-bottom:0;">
                        <label class="form-label" style="font-size:0.82rem;">Costo Total ($)</label>
                        <input type="number" step="0.01" min="0" id="captura-costo-total" class="form-control" placeholder="0.00" readonly style="font-weight:700; color:#4ade80;">
                    </div>
                </div>

                <div class="resumen-fila" id="captura-resumen-fila"></div>

                <!-- Campo Motivo / Proveedor -->
                <div class="form-group" style="margin-top:0.75rem; margin-bottom:0;">
                    <label class="form-label" id="label-motivo" style="font-size:0.82rem;">Motivo / Proveedor</label>
                    <input type="text" name="motivo" id="entrada-motivo" class="form-control" placeholder="Ej: Compra a proveedor / Reposición">
                </div>

                <!-- Panel editar costo unitario (solo en Entrada) -->
                <div class="edit-costo-panel" id="captura-edit-costo-panel" style="margin-top:0.75rem;">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:0.5rem; margin-bottom:0.4rem;">
                        <label style="font-size:0.82rem; font-weight:600; color:var(--text-main); display:flex; align-items:center; gap:0.4rem; cursor:pointer; user-select:none;">
                            <input type="checkbox" id="captura-chk-editar-costo" onchange="toggleEditarCostoCaptura()" style="width:1rem; height:1rem; accent-color:var(--primary); cursor:pointer;">
                            <span>Actualizar Costo Unitario ($)</span>
                        </label>
                        <span style="font-size:0.72rem; color:var(--text-muted);">Actualiza precio de compra</span>
                    </div>
                    <input type="number" step="0.01" min="0" id="captura-editar-costo" class="form-control" placeholder="0.00" disabled oninput="calcularCaptura()" style="font-size:0.88rem;">
                </div>

                <!-- Botón Agregar Otra Entrada -->
                <div style="margin-top:1rem;">
                    <button type="button" class="btn-add-row" id="btn-agregar-fila" onclick="agregarEntradaALista()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <span id="btn-agregar-texto">Agregar Otra Entrada</span>
                    </button>
                </div>
            </div>

            <!-- SECCIÓN: REGISTROS A GUARDAR (TABLA DE PENDIENTES) -->
            <div id="seccion-registros-pendientes" style="margin-bottom:1.25rem;">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:0.6rem;">
                    <h4 style="margin:0; font-size:0.92rem; color:#cbd5e1; display:flex; align-items:center; gap:0.5rem;">
                        <span>📋 Registros a Guardar</span>
                        <span id="badge-contador-registros" style="background:rgba(99,102,241,0.2); color:#a5b4fc; border:1px solid rgba(99,102,241,0.4); border-radius:99px; font-size:0.75rem; padding:0.15rem 0.55rem; font-weight:700;">0</span>
                    </h4>
                    <span id="txt-sub-contador" style="font-size:0.78rem; color:var(--text-muted);">Listos para procesar</span>
                </div>

                <div style="border:1px solid var(--border); border-radius:12px; overflow:hidden; background:rgba(15,23,42,0.4);">
                    <div id="sin-registros-msg" style="padding:1.4rem 1rem; text-align:center; color:var(--text-muted); font-size:0.85rem;">
                        Aún no has agregado registros. Completa los datos arriba y presiona <strong style="color:#a5b4fc;">"Agregar Otra Entrada"</strong>.
                    </div>
                    <table id="tabla-registros-pendientes" style="width:100%; border-collapse:collapse; display:none; font-size:0.83rem;">
                        <thead>
                            <tr style="background:rgba(255,255,255,0.04); border-bottom:1px solid var(--border); color:var(--text-muted); text-transform:uppercase; font-size:0.72rem; letter-spacing:0.05em;">
                                <th style="padding:0.6rem 0.8rem; text-align:center; width:35px;">#</th>
                                <th style="padding:0.6rem 0.8rem; text-align:left;">Producto / Insumo</th>
                                <th style="padding:0.6rem 0.8rem; text-align:left;">Cantidad / Detalle</th>
                                <th style="padding:0.6rem 0.8rem; text-align:right;" class="col-costo-header">Costo Unit.</th>
                                <th style="padding:0.6rem 0.8rem; text-align:right;" class="col-costo-header">Total ($)</th>
                                <th style="padding:0.6rem 0.8rem; text-align:center; width:45px;">Quitar</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-registros-pendientes">
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Contenedor oculto donde se inyectan los inputs hidden al momento de enviar -->
            <div id="contenedor-hidden-inputs"></div>

            <!-- Resumen total (solo entradas) -->
            <div id="resumen-total-costo" style="display:none; background:rgba(74,222,128,0.08); border:1px solid rgba(74,222,128,0.2); border-radius:12px; padding:0.75rem 1.1rem; margin-bottom:1.2rem; font-size:0.9rem; color:#4ade80; font-weight:600;"></div>

            <!-- Botones de acción -->
            <div style="display:flex; justify-content:space-between; align-items:center; gap:0.75rem; margin-top:0.5rem;">
                <button type="button" class="btn-action" style="background:rgba(255,255,255,0.06); color:var(--text-main); padding:0.65rem 1.25rem; border-radius:8px;" onclick="cerrarModalEntrada()">Cancelar</button>
                <button type="submit" id="btn-guardar-movimiento" class="btn-primary" style="padding:0.65rem 1.75rem; width:auto; font-size:0.9rem; border-radius:8px;">💾 Guardar Entradas</button>
            </div>
        </form>
    </div>
</div>

<div id="modal-confirmar-entrada" class="modal-overlay" style="z-index:10050;" onclick="cerrarModalConfirmarEntrada(event)">
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
    let listaEntradas = [];
    const FACTOR_ML_A_LITRO  = 0.001;
    const FACTOR_GAL_A_LITRO = 3.78541;

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

    function getProductoCapturaSeleccionado() {
        var id = (document.getElementById('captura-producto-id') || {}).value;
        if (!id) return null;
        return PRODUCTOS_DATA.find(function(p) { return String(p.id) === String(id); }) || null;
    }

    let productoFocusIndex = -1;

    function renderDropdownProductos(filtroTexto) {
        filtroTexto = (filtroTexto || '').toLowerCase().trim();
        var dropdown = document.getElementById('captura-producto-dropdown');
        if (!dropdown) return;

        var actualId = (document.getElementById('captura-producto-id') || {}).value;
        var filtrados = PRODUCTOS_DATA.filter(function(p) {
            return !filtroTexto || p.label.toLowerCase().includes(filtroTexto);
        });

        if (filtrados.length === 0) {
            dropdown.innerHTML = '<div style="padding:0.85rem; text-align:center; color:var(--text-muted); font-size:0.82rem;">🔍 No se encontraron productos coincidentes</div>';
            return;
        }

        var html = '';
        filtrados.forEach(function(p, i) {
            var esSel = (String(p.id) === String(actualId));
            var unidadLabel = p.unidad === 'LITRO' ? 'Litros' : (p.unidad === 'MILILITRO' ? 'mL' : p.unidad);
            var badgeCosto = p.costo > 0 ? ('<span class="combobox-item-badge">$' + p.costo.toFixed(2) + '</span>') : '';
            var badgeUnidad = '<span class="combobox-item-badge" style="color:#38bdf8;background:rgba(56,189,248,0.1);">' + escapeHtml(unidadLabel) + '</span>';

            html += '<div class="combobox-item ' + (esSel ? 'selected-item' : '') + '" data-id="' + p.id + '" data-index="' + i + '" onclick="seleccionarProductoItem(\'' + p.id + '\')">' +
                '<div style="display:flex;align-items:center;gap:0.45rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' +
                    '<span style="font-weight:500;">' + escapeHtml(p.label) + '</span>' +
                '</div>' +
                '<div style="display:flex;align-items:center;gap:0.35rem;flex-shrink:0;">' +
                    badgeUnidad +
                    badgeCosto +
                '</div>' +
            '</div>';
        });

        dropdown.innerHTML = html;
        productoFocusIndex = -1;
    }

    function abrirDropdownProductos() {
        var dropdown = document.getElementById('captura-producto-dropdown');
        if (!dropdown) return;
        dropdown.style.display = 'block';
        var inputVal = (document.getElementById('captura-producto-input') || {}).value || '';
        var prod = getProductoCapturaSeleccionado();
        var filtro = (prod && inputVal === prod.label) ? '' : inputVal;
        renderDropdownProductos(filtro);
    }

    function cerrarDropdownProductos() {
        var dropdown = document.getElementById('captura-producto-dropdown');
        if (dropdown) dropdown.style.display = 'none';
        productoFocusIndex = -1;
    }

    function toggleDropdownProductos(e) {
        if (e) e.stopPropagation();
        var dropdown = document.getElementById('captura-producto-dropdown');
        if (!dropdown) return;
        if (dropdown.style.display === 'block') {
            cerrarDropdownProductos();
        } else {
            abrirDropdownProductos();
            var inp = document.getElementById('captura-producto-input');
            if (inp) inp.focus();
        }
    }

    function onInputBusquedaProducto(val) {
        var dropdown = document.getElementById('captura-producto-dropdown');
        if (dropdown && dropdown.style.display !== 'block') {
            dropdown.style.display = 'block';
        }

        var btnLimpiar = document.getElementById('btn-limpiar-producto');
        if (btnLimpiar) btnLimpiar.style.display = val.trim() ? 'block' : 'none';

        var prod = getProductoCapturaSeleccionado();
        if (prod && val !== prod.label) {
            document.getElementById('captura-producto-id').value = '';
            actualizarEstadoProductoSeleccionado(null);
        }

        renderDropdownProductos(val);
    }

    function onKeydownProductoCombobox(e) {
        var dropdown = document.getElementById('captura-producto-dropdown');
        if (!dropdown) return;

        var items = dropdown.querySelectorAll('.combobox-item');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (dropdown.style.display !== 'block') {
                abrirDropdownProductos();
                return;
            }
            if (items.length > 0) {
                productoFocusIndex = (productoFocusIndex + 1) % items.length;
                resaltarItemCombobox(items);
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (dropdown.style.display !== 'block') {
                abrirDropdownProductos();
                return;
            }
            if (items.length > 0) {
                productoFocusIndex = (productoFocusIndex - 1 + items.length) % items.length;
                resaltarItemCombobox(items);
            }
        } else if (e.key === 'Enter') {
            if (dropdown.style.display === 'block') {
                e.preventDefault();
                if (productoFocusIndex >= 0 && items[productoFocusIndex]) {
                    var id = items[productoFocusIndex].getAttribute('data-id');
                    seleccionarProductoItem(id);
                } else if (items.length === 1) {
                    var id = items[0].getAttribute('data-id');
                    seleccionarProductoItem(id);
                }
            }
        } else if (e.key === 'Escape') {
            cerrarDropdownProductos();
        }
    }

    function resaltarItemCombobox(items) {
        items.forEach(function(item, idx) {
            if (idx === productoFocusIndex) {
                item.classList.add('active-item');
                item.scrollIntoView({ block: 'nearest' });
            } else {
                item.classList.remove('active-item');
            }
        });
    }

    function seleccionarProductoItem(id) {
        var prod = PRODUCTOS_DATA.find(function(p) { return String(p.id) === String(id); });
        if (!prod) return;

        document.getElementById('captura-producto-id').value = prod.id;
        var inp = document.getElementById('captura-producto-input');
        if (inp) inp.value = prod.label;

        var btnLimpiar = document.getElementById('btn-limpiar-producto');
        if (btnLimpiar) btnLimpiar.style.display = 'block';

        cerrarDropdownProductos();
        actualizarEstadoProductoSeleccionado(prod);

        var inpPres = document.getElementById('captura-presentacion');
        if (inpPres) inpPres.focus();
    }

    function limpiarSeleccionProducto(e) {
        if (e) e.stopPropagation();
        document.getElementById('captura-producto-id').value = '';
        var inp = document.getElementById('captura-producto-input');
        if (inp) { inp.value = ''; inp.focus(); }

        var btnLimpiar = document.getElementById('btn-limpiar-producto');
        if (btnLimpiar) btnLimpiar.style.display = 'none';

        actualizarEstadoProductoSeleccionado(null);
        abrirDropdownProductos();
    }

    function actualizarEstadoProductoSeleccionado(prod) {
        var tag = document.getElementById('captura-tag-costo');
        var liqPanel = document.getElementById('captura-liq-panel');
        var inpEditar = document.getElementById('captura-editar-costo');

        if (prod) {
            var costo = prod.costo;
            var unidad = prod.unidad || '';
            var esLiquido = (unidad === 'LITRO' || unidad === 'MILILITRO');
            var etiquetaUnidad = (unidad === 'LITRO') ? 'L' : ((unidad === 'MILILITRO') ? 'mL' : unidad);

            if (tag) {
                tag.textContent = '💵 $' + costo.toFixed(2) + ' / ' + etiquetaUnidad;
                tag.style.display = '';
            }
            if (liqPanel) liqPanel.style.display = esLiquido ? 'block' : 'none';
            if (inpEditar && inpEditar.disabled) inpEditar.value = costo > 0 ? costo.toFixed(2) : '';
        } else {
            if (tag) tag.style.display = 'none';
            if (liqPanel) liqPanel.style.display = 'none';
            if (inpEditar && inpEditar.disabled) inpEditar.value = '';
        }

        actualizarVisibilidadCantidad();
        ocultarAlertaCaptura();
        calcularCaptura();
    }

    // Cerrar dropdown al hacer clic fuera del combobox
    document.addEventListener('click', function(e) {
        var wrap = document.getElementById('combobox-producto-wrapper');
        if (wrap && !wrap.contains(e.target)) {
            cerrarDropdownProductos();
        }
    });

    function onUnidadLiquidoChange() {
        actualizarVisibilidadCantidad();
        calcularCaptura();
    }

    function actualizarVisibilidadCantidad() {
        var prod = getProductoCapturaSeleccionado();
        var unidadProd = prod ? prod.unidad : '';
        var esLiquidoProd = (unidadProd === 'LITRO' || unidadProd === 'MILILITRO');

        var unidadActiva = unidadProd;
        if (esLiquidoProd) {
            var selULiq = document.getElementById('captura-unidad-liquido');
            unidadActiva = (selULiq && selULiq.value) ? selULiq.value : 'LITRO';
        }

        // El campo Cantidad solo debe estar disponible cuando la unidad sea LITRO o MILILITRO (no Galones ni otras unidades).
        // Desaparecerlo (display: none), no deshabilitarlo.
        var disponible = (unidadActiva === 'LITRO' || unidadActiva === 'MILILITRO');

        var grupoCant = document.getElementById('grupo-captura-cantidad');
        var inpCant   = document.getElementById('captura-cantidad');
        var gridCant  = document.getElementById('grid-captura-cantidades');
        var tipo      = document.getElementById('entrada-tipo-movimiento').value;
        var esSalida  = (tipo === 'SALIDA');

        if (grupoCant) {
            if (disponible) {
                grupoCant.style.display = 'block';
            } else {
                grupoCant.style.display = 'none';
                if (inpCant) inpCant.value = '1.00';
            }
        }

        if (gridCant) {
            var cols = 1; // Presentación
            if (disponible) cols++;
            if (!esSalida) cols++; // Costo Total en Entrada
            if (cols === 3) gridCant.style.gridTemplateColumns = '1fr 1fr 1fr';
            else if (cols === 2) gridCant.style.gridTemplateColumns = '1fr 1fr';
            else gridCant.style.gridTemplateColumns = '1fr';
        }
    }

    function toggleEditarCostoCaptura() {
        var chk = document.getElementById('captura-chk-editar-costo');
        var inp = document.getElementById('captura-editar-costo');
        var prod = getProductoCapturaSeleccionado();
        var costoBase = prod ? prod.costo : 0;

        if (chk && chk.checked) {
            if (inp) {
                inp.disabled = false;
                if (!inp.value || parseFloat(inp.value) <= 0) {
                    inp.value = costoBase > 0 ? costoBase.toFixed(2) : '';
                }
                inp.focus();
            }
        } else {
            if (inp) {
                inp.disabled = true;
                inp.value = costoBase > 0 ? costoBase.toFixed(2) : '0.00';
            }
        }
        calcularCaptura();
    }

    function stepCapturaInput(field, dir) {
        var inp = document.getElementById('captura-' + field);
        if (!inp || inp.disabled) return;
        var step = parseFloat(inp.getAttribute('step')) || 1;
        var min = inp.hasAttribute('min') ? parseFloat(inp.getAttribute('min')) : 0.01;
        var val = parseFloat(inp.value) || 0;
        val = Math.max(min, val + (step * dir));
        inp.value = step < 1 ? val.toFixed(2) : val;
        calcularCaptura();
    }

    function calcularCaptura() {
        var prod = getProductoCapturaSeleccionado();
        var unidad = prod ? prod.unidad : '';
        var costoBase = prod ? prod.costo : 0;

        var grupoCant = document.getElementById('grupo-captura-cantidad');
        var cantDisponible = (grupoCant && grupoCant.style.display !== 'none');

        var pres = parseFloat(document.getElementById('captura-presentacion').value || 0) || 0;
        var cant = cantDisponible ? (parseFloat(document.getElementById('captura-cantidad').value || 0) || 0) : 1.0;
        var totalCant = pres * cant;

        var esLiquido = (unidad === 'LITRO' || unidad === 'MILILITRO');
        var selULiq = document.getElementById('captura-unidad-liquido');
        var uLiq = (esLiquido && selULiq) ? selULiq.value : 'LITRO';
        var factor = esLiquido ? getFactorLitros(uLiq) : 1.0;
        var cantL = totalCant * factor;

        var lblConv = document.getElementById('captura-lbl-conv');
        if (lblConv) {
            if (esLiquido && totalCant > 0) {
                var detalleCantStr = cantDisponible && cant > 1 ? (cant.toFixed(2) + ' × ') : '';
                if (uLiq === 'MILILITRO')
                    lblConv.innerHTML = '🔄 <strong>' + detalleCantStr + pres.toLocaleString() + ' mL = ' + totalCant.toLocaleString() + ' mL</strong> = <strong>' + cantL.toFixed(2) + ' L</strong>';
                else if (uLiq === 'GALON')
                    lblConv.innerHTML = '🔄 <strong>' + pres.toFixed(2) + ' Gal = ' + cantL.toFixed(2) + ' L</strong>';
                else
                    lblConv.innerHTML = '🔄 <strong>' + detalleCantStr + pres.toFixed(2) + ' L = ' + cantL.toFixed(2) + ' Litros</strong>';
                lblConv.style.display = 'block';
            } else {
                lblConv.style.display = 'none';
            }
        }

        var resumen = document.getElementById('captura-resumen-fila');
        if (resumen) {
            if (!esLiquido && totalCant > 0 && cantDisponible && cant > 1) {
                resumen.innerHTML = '📦 Total: <strong>' + cant.toFixed(2) + ' × ' + pres.toFixed(2) + ' = ' + totalCant.toFixed(2) + ' ' + (unidad || 'uds.') + '</strong>';
                resumen.style.display = 'block';
            } else {
                resumen.style.display = 'none';
            }
        }

        var tipo = document.getElementById('entrada-tipo-movimiento').value;
        var chk = document.getElementById('captura-chk-editar-costo');
        var inpE = document.getElementById('captura-editar-costo');
        var costoAplicar = costoBase;
        if (chk && chk.checked && inpE && !inpE.disabled) {
            var cv = parseFloat(inpE.value);
            if (!isNaN(cv) && cv >= 0) costoAplicar = cv;
        }

        var inpCT = document.getElementById('captura-costo-total');
        if (inpCT) {
            if (tipo === 'ENTRADA' && totalCant > 0 && costoAplicar > 0) {
                inpCT.value = (esLiquido ? cantL * costoAplicar : totalCant * costoAplicar).toFixed(2);
            } else {
                inpCT.value = '0.00';
            }
        }
    }

    function mostrarAlertaCaptura(msg) {
        var el = document.getElementById('alerta-validacion-captura');
        if (el) {
            el.textContent = '⚠️ ' + msg;
            el.style.display = 'inline-block';
        }
    }

    function ocultarAlertaCaptura() {
        var el = document.getElementById('alerta-validacion-captura');
        if (el) el.style.display = 'none';
    }

    function agregarEntradaALista() {
        var prod = getProductoCapturaSeleccionado();
        if (!prod) {
            mostrarAlertaCaptura('Selecciona un producto o insumo.');
            var inp = document.getElementById('captura-producto-input');
            if (inp) inp.focus();
            abrirDropdownProductos();
            return false;
        }

        var inpPres = document.getElementById('captura-presentacion');
        var pres = parseFloat((inpPres && inpPres.value) || 0) || 0;
        if (pres <= 0) {
            mostrarAlertaCaptura('Ingresa una presentación válida mayor a 0.');
            if (inpPres) inpPres.focus();
            return false;
        }

        var grupoCant = document.getElementById('grupo-captura-cantidad');
        var cantDisponible = (grupoCant && grupoCant.style.display !== 'none');
        var inpCant = document.getElementById('captura-cantidad');
        var cant = cantDisponible ? (parseFloat((inpCant && inpCant.value) || 0) || 0) : 1.0;

        if (cantDisponible && cant <= 0) {
            mostrarAlertaCaptura('Ingresa una cantidad válida mayor a 0.');
            if (inpCant) inpCant.focus();
            return false;
        }

        var productoId = prod.id;
        var nombreProducto = prod.label;
        var unidad = prod.unidad || '';
        var costoBase = prod.costo;

        var esLiquido = (unidad === 'LITRO' || unidad === 'MILILITRO');
        var selULiq = document.getElementById('captura-unidad-liquido');
        var uLiq = (esLiquido && selULiq) ? selULiq.value : 'LITRO';
        var factor = esLiquido ? getFactorLitros(uLiq) : 1.0;
        var totalCant = pres * cant;
        var totalEnUnidadBase = esLiquido ? (totalCant * factor) : totalCant;

        var chkEditar = document.getElementById('captura-chk-editar-costo');
        var inpEditar = document.getElementById('captura-editar-costo');
        var permitirEditarCosto = chkEditar && chkEditar.checked;
        var nuevoCosto = permitirEditarCosto ? (parseFloat(inpEditar ? inpEditar.value : 0) || 0) : costoBase;

        var inpCT = document.getElementById('captura-costo-total');
        var costoTotal = parseFloat((inpCT && inpCT.value) || 0) || 0;

        var inpMotivo = document.getElementById('entrada-motivo');
        var motivoItem = (inpMotivo && inpMotivo.value) ? inpMotivo.value.trim() : '';

        var tipo = document.getElementById('entrada-tipo-movimiento').value;

        // Agregar al arreglo
        listaEntradas.push({
            producto_id: productoId,
            producto_nombre: nombreProducto,
            unidad_medida: unidad,
            presentacion: pres,
            cantidad: cant,
            total_cant: totalCant,
            unidad_liquido: uLiq,
            total_base: totalEnUnidadBase,
            permitir_editar_costo: permitirEditarCosto,
            editar_costo: nuevoCosto,
            costo_unitario: nuevoCosto,
            costo_total: costoTotal,
            motivo: motivoItem,
            tipo_movimiento: tipo,
            cantidad_visible: cantDisponible
        });

        // Limpiar campos del formulario de captura para la siguiente entrada
        limpiarCamposCaptura();

        // Renderizar registros pendientes
        renderListaPendientes();

        return true;
    }

    function limpiarCamposCaptura() {
        document.getElementById('captura-producto-id').value = '';
        var inp = document.getElementById('captura-producto-input');
        if (inp) inp.value = '';
        var btnLimpiar = document.getElementById('btn-limpiar-producto');
        if (btnLimpiar) btnLimpiar.style.display = 'none';
        cerrarDropdownProductos();

        var tag = document.getElementById('captura-tag-costo');
        if (tag) tag.style.display = 'none';

        var liqPanel = document.getElementById('captura-liq-panel');
        if (liqPanel) liqPanel.style.display = 'none';
        var selULiq = document.getElementById('captura-unidad-liquido');
        if (selULiq) selULiq.value = 'LITRO';
        var lblConv = document.getElementById('captura-lbl-conv');
        if (lblConv) lblConv.style.display = 'none';

        var inpPres = document.getElementById('captura-presentacion');
        if (inpPres) inpPres.value = '';
        var inpCant = document.getElementById('captura-cantidad');
        if (inpCant) inpCant.value = '1.00';
        var inpCT = document.getElementById('captura-costo-total');
        if (inpCT) inpCT.value = '';

        var resFila = document.getElementById('captura-resumen-fila');
        if (resFila) resFila.style.display = 'none';

        var chkEditar = document.getElementById('captura-chk-editar-costo');
        if (chkEditar) chkEditar.checked = false;
        var inpEditar = document.getElementById('captura-editar-costo');
        if (inpEditar) { inpEditar.value = ''; inpEditar.disabled = true; }

        actualizarVisibilidadCantidad();
        ocultarAlertaCaptura();

        if (inp) inp.focus();
    }

    function renderListaPendientes() {
        var tbody = document.getElementById('tbody-registros-pendientes');
        var sinMsg = document.getElementById('sin-registros-msg');
        var tabla = document.getElementById('tabla-registros-pendientes');
        var badge = document.getElementById('badge-contador-registros');
        var tipo = document.getElementById('entrada-tipo-movimiento').value;
        var esSalida = (tipo === 'SALIDA');

        if (badge) badge.textContent = listaEntradas.length;

        // Ocultar o mostrar encabezados de costo según tipo
        document.querySelectorAll('.col-costo-header').forEach(function(th) {
            th.style.display = esSalida ? 'none' : '';
        });

        if (listaEntradas.length === 0) {
            if (sinMsg) sinMsg.style.display = 'block';
            if (tabla) tabla.style.display = 'none';
            if (tbody) tbody.innerHTML = '';
            actualizarResumenTotal();
            return;
        }

        if (sinMsg) sinMsg.style.display = 'none';
        if (tabla) tabla.style.display = 'table';

        var html = '';
        listaEntradas.forEach(function(item, idx) {
            var esLiq = (item.unidad_medida === 'LITRO' || item.unidad_medida === 'MILILITRO');
            var detalleTexto = '';
            var etiquetaULiq = item.unidad_liquido === 'MILILITRO' ? 'mL' : (item.unidad_liquido === 'GALON' ? 'Gal' : 'L');
            if (esLiq) {
                if (item.cantidad_visible && item.cantidad > 1) {
                    detalleTexto = item.cantidad.toFixed(2) + ' × ' + item.presentacion.toFixed(2) + ' ' + etiquetaULiq + ' = <strong style="color:#38bdf8;">' + item.total_base.toFixed(2) + ' L</strong>';
                } else {
                    detalleTexto = item.presentacion.toFixed(2) + ' ' + etiquetaULiq + (item.unidad_liquido !== 'LITRO' ? ' = <strong style="color:#38bdf8;">' + item.total_base.toFixed(2) + ' L</strong>' : '');
                }
            } else {
                if (item.cantidad_visible && item.cantidad > 1) {
                    detalleTexto = item.cantidad.toFixed(2) + ' × ' + item.presentacion.toFixed(2) + ' = <strong style="color:#a5b4fc;">' + item.total_cant.toFixed(2) + ' ' + (item.unidad_medida || 'uds.') + '</strong>';
                } else {
                    detalleTexto = item.presentacion.toFixed(2) + ' <strong style="color:#a5b4fc;">' + (item.unidad_medida || 'uds.') + '</strong>';
                }
            }

            var costoUnitTxt = item.permitir_editar_costo
                ? '<span style="color:#4ade80;font-weight:600;">$' + item.editar_costo.toFixed(2) + '</span> <span style="font-size:0.7rem;color:#a3e635;">(nuevo)</span>'
                : '$' + item.costo_unitario.toFixed(2);

            var estiloCostoTD = esSalida ? 'display:none;' : '';

            var nombreProdCol = '<div style="font-weight:600;color:#fff;">' + escapeHtml(item.producto_nombre) + '</div>';
            if (item.motivo) {
                nombreProdCol += '<div style="font-size:0.73rem;color:#94a3b8;margin-top:0.2rem;display:flex;align-items:center;gap:0.3rem;"><span>📝</span><span>' + escapeHtml(item.motivo) + '</span></div>';
            }

            html += '<tr class="item-row-fade" style="border-bottom:1px solid rgba(255,255,255,0.05);">' +
                '<td style="padding:0.6rem 0.8rem;text-align:center;color:var(--text-muted);font-weight:600;">' + (idx + 1) + '</td>' +
                '<td style="padding:0.6rem 0.8rem;">' + nombreProdCol + '</td>' +
                '<td style="padding:0.6rem 0.8rem;color:var(--text-muted);">' + detalleTexto + '</td>' +
                '<td style="padding:0.6rem 0.8rem;text-align:right;' + estiloCostoTD + '">' + costoUnitTxt + '</td>' +
                '<td style="padding:0.6rem 0.8rem;text-align:right;font-weight:700;color:#4ade80;' + estiloCostoTD + '">$' + item.costo_total.toFixed(2) + '</td>' +
                '<td style="padding:0.6rem 0.8rem;text-align:center;">' +
                    '<button type="button" class="btn-del-item" onclick="eliminarEntradaLista(' + idx + ')" title="Quitar este registro">✕</button>' +
                '</td>' +
            '</tr>';
        });

        if (tbody) tbody.innerHTML = html;
        actualizarResumenTotal();
    }

    function eliminarEntradaLista(idx) {
        listaEntradas.splice(idx, 1);
        renderListaPendientes();
    }

    function actualizarResumenTotal() {
        var tipo = document.getElementById('entrada-tipo-movimiento').value;
        var resEl = document.getElementById('resumen-total-costo');
        if (!resEl) return;
        if (tipo !== 'ENTRADA') {
            resEl.style.display = 'none';
            return;
        }

        var total = 0;
        listaEntradas.forEach(function(item) {
            total += parseFloat(item.costo_total || 0) || 0;
        });

        var n = listaEntradas.length;
        if (total > 0 && n > 0) {
            resEl.innerHTML = '💰 Costo total acumulado (' + n + ' registro' + (n !== 1 ? 's' : '') + '): <strong>$' + total.toFixed(2) + '</strong>';
            resEl.style.display = 'block';
        } else {
            resEl.style.display = 'none';
        }
    }

    function solicitarGuardarEntrada(e) {
        if (entradaFormConfirmado) {
            entradaFormConfirmado = false;
            return true;
        }
        if (e) e.preventDefault();

        // Si el usuario tiene datos válidos en el formulario de captura actual y aún no pulsó "Agregar Otra Entrada"
        var prod = getProductoCapturaSeleccionado();
        var pres = parseFloat((document.getElementById('captura-presentacion') || {}).value || 0) || 0;
        var cant = parseFloat((document.getElementById('captura-cantidad') || {}).value || 0) || 0;

        if (prod && pres > 0 && cant > 0) {
            agregarEntradaALista();
        }

        // Si no hay registros acumulados
        if (listaEntradas.length === 0) {
            mostrarAlertaCaptura('Agrega al menos una entrada antes de guardar.');
            var inp = document.getElementById('captura-producto-input');
            if (inp) inp.focus();
            return false;
        }

        var tipoMov = document.getElementById('entrada-tipo-movimiento').value;
        var txtTipo = tipoMov === 'SALIDA' ? 'Salida' : 'Entrada';
        var lineas = [];

        listaEntradas.forEach(function(item) {
            var esLiq = (item.unidad_medida === 'LITRO' || item.unidad_medida === 'MILILITRO');
            var uLiq = item.unidad_liquido === 'MILILITRO' ? 'mL' : (item.unidad_liquido === 'GALON' ? 'Gal' : 'L');
            var det = '';
            if (esLiq) {
                if (item.cantidad_visible && item.cantidad > 1) {
                    det = item.cantidad.toFixed(2) + ' × ' + item.presentacion.toFixed(2) + ' ' + uLiq + ' = <strong>' + item.total_base.toFixed(2) + ' L</strong>';
                } else {
                    det = item.presentacion.toFixed(2) + ' ' + uLiq + (item.unidad_liquido !== 'LITRO' ? ' = <strong>' + item.total_base.toFixed(2) + ' L</strong>' : '');
                }
            } else {
                if (item.cantidad_visible && item.cantidad > 1) {
                    det = item.cantidad.toFixed(2) + ' × ' + item.presentacion.toFixed(2) + ' = <strong>' + item.total_cant.toFixed(2) + ' ' + (item.unidad_medida || 'uds.') + '</strong>';
                } else {
                    det = item.presentacion.toFixed(2) + ' <strong>' + (item.unidad_medida || 'uds.') + '</strong>';
                }
            }
            if (tipoMov === 'ENTRADA' && item.costo_total > 0) {
                det += ' | Costo: <strong>$' + item.costo_total.toFixed(2) + '</strong>';
            }
            var subMotivo = item.motivo ? ('<br><span style="font-size:0.75rem;color:#94a3b8;">📝 ' + escapeHtml(item.motivo) + '</span>') : '';
            lineas.push('<li style="margin-bottom:0.4rem;padding:0.35rem 0.5rem;background:rgba(255,255,255,0.04);border-radius:7px;"><span style="color:#fff;">' + escapeHtml(item.producto_nombre) + '</span>' + subMotivo + '<br><small>' + det + '</small></li>');
        });

        document.getElementById('conf-entrada-titulo').textContent = 'Confirmar ' + txtTipo + 's';
        document.getElementById('conf-entrada-mensaje').innerHTML =
            '<p style="margin-bottom:0.6rem;">Se registrarán <strong>' + listaEntradas.length + ' ' + txtTipo.toLowerCase() + '(s)</strong> de stock:</p>' +
            '<ul style="list-style:none;padding:0;margin:0;">' + lineas.join('') + '</ul>';

        document.getElementById('modal-confirmar-entrada').classList.add('active');
        return false;
    }

    function cerrarModalConfirmarEntrada(e) {
        if (e && e.target !== e.currentTarget) return;
        document.getElementById('modal-confirmar-entrada').classList.remove('active');
    }

    function ejecutarGuardarEntrada() {
        var container = document.getElementById('contenedor-hidden-inputs');
        if (!container) return;
        container.innerHTML = '';

        listaEntradas.forEach(function(item, i) {
            function crearHidden(name, val) {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = name;
                inp.value = val;
                return inp;
            }
            container.appendChild(crearHidden('producto_id[' + i + ']', item.producto_id));
            container.appendChild(crearHidden('presentacion[' + i + ']', item.presentacion));
            container.appendChild(crearHidden('cantidad[' + i + ']', item.cantidad));
            container.appendChild(crearHidden('unidad_liquido[' + i + ']', item.unidad_liquido));
            container.appendChild(crearHidden('permitir_editar_costo[' + i + ']', item.permitir_editar_costo ? '1' : '0'));
            container.appendChild(crearHidden('editar_costo[' + i + ']', item.editar_costo));
            if (item.motivo) {
                container.appendChild(crearHidden('motivo_item[' + i + ']', item.motivo));
            }
        });

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
        var lblMotivo  = document.getElementById('label-motivo');
        var btnAgregarTxt = document.getElementById('btn-agregar-texto');
        var lblTituloCaptura = document.getElementById('lbl-captura-titulo');
        var lblPres = document.getElementById('lbl-captura-presentacion');
        var grupoCT = document.getElementById('grupo-captura-costo-total');
        var panelEditCosto = document.getElementById('captura-edit-costo-panel');

        if (tipo === 'SALIDA') {
            if (titulo)    titulo.textContent    = 'Registrar Salidas de Stock';
            if (subtitulo) subtitulo.textContent = 'Disminución o merma de existencias';
            if (iconCont)  { iconCont.innerHTML = '📤'; iconCont.style.background = 'rgba(239,68,68,0.15)'; iconCont.style.color = '#f87171'; }
            if (btnGuardar) btnGuardar.innerHTML = '💾 Guardar Salidas';
            if (btnAgregarTxt) btnAgregarTxt.textContent = 'Agregar Otra Salida';
            if (lblTituloCaptura) lblTituloCaptura.textContent = 'Retiro de Salida';
            if (lblPres) lblPres.textContent = 'Presentación a Retirar *';
            if (lblMotivo) lblMotivo.textContent = 'Motivo de Salida';
            if (inpMotivo)  inpMotivo.placeholder = 'Ej: Merma / Uso interno / Ajuste';
            if (panelEditCosto) panelEditCosto.style.display = 'none';
            if (grupoCT) grupoCT.style.display = 'none';
        } else {
            if (titulo)    titulo.textContent    = 'Registrar Entradas de Stock';
            if (subtitulo) subtitulo.textContent = 'Puedes agregar múltiples productos en una sola operación';
            if (iconCont)  { iconCont.innerHTML = '📥'; iconCont.style.background = 'rgba(34,197,94,0.15)'; iconCont.style.color = '#22c55e'; }
            if (btnGuardar) btnGuardar.innerHTML = '💾 Guardar Entradas';
            if (btnAgregarTxt) btnAgregarTxt.textContent = 'Agregar Otra Entrada';
            if (lblTituloCaptura) lblTituloCaptura.textContent = 'Ingreso de Entrada';
            if (lblPres) lblPres.textContent = 'Presentación *';
            if (lblMotivo) lblMotivo.textContent = 'Motivo / Proveedor';
            if (inpMotivo)  inpMotivo.placeholder = 'Ej: Compra a proveedor / Reposición';
            if (panelEditCosto) panelEditCosto.style.display = 'block';
            if (grupoCT) grupoCT.style.display = 'block';
        }

        actualizarVisibilidadCantidad();
        renderListaPendientes();
        calcularCaptura();
    }

    function abrirModalEntrada(productoId) {
        productoId = productoId || '';
        listaEntradas = [];
        var container = document.getElementById('contenedor-hidden-inputs');
        if (container) container.innerHTML = '';

        document.getElementById('entrada-tipo-movimiento').value = 'ENTRADA';
        document.getElementById('entrada-motivo').value = '';
        onTipoMovimientoChange('ENTRADA');

        limpiarCamposCaptura();

        if (productoId) {
            seleccionarProductoItem(productoId);
        }

        renderListaPendientes();
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
