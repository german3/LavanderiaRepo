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

// Middleware
if (!$auth->checkAuth()) {
    header("Location: login.php");
    exit;
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$mensaje = "";
$error   = "";

// ── Procesar Acciones (Editar / Eliminar) ─────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';

    if ($action === 'editar') {
        if (!$auth->isAdmin()) {
            $error = "Solo los administradores pueden modificar productos del catálogo.";
        } else {
            $id           = $_POST['id'] ?? '';
            $descripcion  = trim($_POST['descripcion'] ?? '');
            $codigoBarras = trim($_POST['codigo_barras'] ?? '');
            $unidadMedida = trim($_POST['unidad_medida'] ?? 'PIEZA');
            $stockMinimo  = floatval($_POST['stock_minimo'] ?? 0);
            $costo        = floatval($_POST['costo'] ?? 0);
            $precioVenta  = floatval($_POST['precio_venta'] ?? 0);
            $ropaKg       = floatval($_POST['ropa_kg'] ?? 0);

            if (empty($id) || empty($descripcion)) {
                $error = "La descripción del producto es obligatoria.";
            } else {
                $prodActual = $productoModel->obtenerPorId($id);
                $datos = [
                    'descripcion'   => $descripcion,
                    'codigo_barras' => $codigoBarras,
                    'unidad_medida' => $unidadMedida,
                    'stock_minimo'  => $stockMinimo,
                    'costo'         => $costo,
                    'precio_venta'  => $precioVenta,
                    'ropa_kg'       => $ropaKg,
                ];

                if ($productoModel->actualizar($id, $datos)) {
                    if ($prodActual) {
                        $historialModel->registrar([
                            'tipo_movimiento'      => 'EDICION PRODUCTO',
                            'producto_id'          => $prodActual['id'],
                            'producto_sku'         => $prodActual['codigo_interno_sku'],
                            'producto_descripcion' => $descripcion,
                            'tipo_producto'        => $prodActual['tipo'],
                            'cantidad'             => floatval($prodActual['stock_cantidad']),
                            'costo_unitario'      => $costo,
                            'stock_antes'          => floatval($prodActual['stock_cantidad']),
                            'stock_despues'        => floatval($prodActual['stock_cantidad']),
                            'motivo'               => 'Actualización de datos de producto',
                            'usuario_id'           => $_SESSION['usuario_id'] ?? null,
                            'usuario_nombre'       => $_SESSION['nombre'] ?? 'Usuario'
                        ]);
                    }
                    $mensaje = "Producto actualizado exitosamente.";
                } else {
                    $error = "No se pudo actualizar el producto.";
                }
            }
        }
    } elseif ($action === 'eliminar') {
        if (!$auth->isAdmin()) {
            $error = "Solo los administradores pueden eliminar productos del catálogo.";
        } else {
            $id = $_POST['id'] ?? '';
            if (empty($id)) {
                $error = "ID de producto inválido.";
            } else {
                $prodEliminar = $productoModel->obtenerPorId($id);
                if ($prodEliminar) {
                    if ($productoModel->eliminar($id)) {
                        $historialModel->registrar([
                            'tipo_movimiento'      => 'ELIMINACION PRODUCTO',
                            'producto_id'          => $prodEliminar['id'],
                            'producto_sku'         => $prodEliminar['codigo_interno_sku'],
                            'producto_descripcion' => $prodEliminar['descripcion'],
                            'tipo_producto'        => $prodEliminar['tipo'],
                            'cantidad'             => floatval($prodEliminar['stock_cantidad']),
                            'costo_unitario'      => floatval($prodEliminar['costo']),
                            'stock_antes'          => floatval($prodEliminar['stock_cantidad']),
                            'stock_despues'        => 0,
                            'motivo'               => 'Producto eliminado del catálogo',
                            'usuario_id'           => $_SESSION['usuario_id'] ?? null,
                            'usuario_nombre'       => $_SESSION['nombre'] ?? 'Usuario'
                        ]);
                        $mensaje = "Producto eliminado exitosamente del catálogo.";
                    } else {
                        $error = "No se pudo eliminar el producto.";
                    }
                } else {
                    $error = "El producto que se intenta eliminar no existe.";
                }
            }
        }
    }
}

// Obtener catálogo completo con insumos de kit
$productos = $productoModel->obtenerTodos();
$productosJson = json_encode($productos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo de Productos | Lavandería</title>
    <link rel="stylesheet" href="css/index.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        .table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; color: var(--text-main); }
        .table th { padding: 0.75rem 1rem; text-align: left; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); border-bottom: 2px solid var(--border); }
        .table td { padding: 1rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        .table tr:hover td { background: rgba(255,255,255,0.02); }
        .badge { padding: 0.25rem 0.75rem; border-radius: 99px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.35rem; }
        .badge-normal   { background: rgba(59, 130, 246, 0.2); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.3); }
        .badge-kit      { background: rgba(139, 92, 246, 0.2); color: #c4b5fd; border: 1px solid rgba(139, 92, 246, 0.3); }
        .badge-servicio { background: rgba(16, 185, 129, 0.2); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.3); }

        /* Botones de acción */
        .btn-action {
            padding: 0.4rem 0.75rem;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            transition: all 0.2s ease;
            margin-left: 0.3rem;
        }
        .btn-detalles {
            background: rgba(14, 165, 233, 0.15);
            color: #38bdf8;
            border: 1px solid rgba(14, 165, 233, 0.3);
        }
        .btn-detalles:hover {
            background: rgba(14, 165, 233, 0.3);
            color: #fff;
            transform: translateY(-1px);
        }
        .btn-edit {
            background: rgba(59, 130, 246, 0.15);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        .btn-edit:hover {
            background: rgba(59, 130, 246, 0.3);
            color: #fff;
            transform: translateY(-1px);
        }
        .btn-delete {
            background: rgba(239, 68, 68, 0.15);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        .btn-delete:hover {
            background: rgba(239, 68, 68, 0.3);
            color: #fff;
            transform: translateY(-1px);
        }

        /* Modales */
        .modal-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.78);
            backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
            display: none; align-items: center; justify-content: center;
            z-index: 9999; animation: fadeIn 0.2s ease;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: #1e293b; border: 1px solid var(--border);
            border-radius: 20px; padding: 2rem; max-width: 580px; width: 92%;
            max-height: 90vh; overflow-y: auto;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); animation: slideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin: 1.25rem 0;
        }
        .details-card {
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0.85rem 1rem;
        }
        .details-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.3rem;
        }
        .details-val {
            font-size: 1rem;
            font-weight: 600;
            color: #fff;
        }
        .kit-insumos-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0.75rem;
            font-size: 0.85rem;
        }
        .kit-insumos-table th {
            text-align: left;
            padding: 0.4rem 0.6rem;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
            font-size: 0.75rem;
        }
        .kit-insumos-table td {
            padding: 0.5rem 0.6rem;
            border-bottom: 1px solid rgba(255,255,255,0.04);
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $activePage = 'productos'; require_once __DIR__ . '/partials/sidebar.php'; ?>

        <main class="main-content">
            <header style="margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1 style="font-size: 2rem;">Catálogo de Productos</h1>
                    <p style="color: var(--text-muted);">Listado de productos, servicios y kits registrados.</p>
                </div>
                <a href="productos_registro.php" class="btn-primary" style="text-decoration:none; display:inline-block; width:auto; padding:0.6rem 1.25rem; font-size:0.9rem;">➕ Nuevo Elemento</a>
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
                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                        <h3 style="margin-bottom: 0;">Elementos Registrados</h3>
                        <span id="badge-total-items" style="font-size: 0.78rem; font-weight: 600; background: rgba(255,255,255,0.06); border: 1px solid var(--border); color: var(--text-muted); padding: 0.2rem 0.65rem; border-radius: 99px;">
                            <?= count($productos) ?> elementos
                        </span>
                    </div>

                    <!-- Controles derechos: Búsqueda + Exportar -->
                    <div style="display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0;">
                        <!-- Cuadro de Búsqueda -->
                        <div style="position: relative; width: 300px;">
                            <span style="position: absolute; left: 0.9rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.95rem; pointer-events: none;">🔍</span>
                            <input type="text" id="input-busqueda" class="form-control" placeholder="Buscar por SKU, descripción o código..."
                                   style="padding-left: 2.5rem; padding-right: 2.2rem; border-radius: 10px; font-size: 0.85rem; height: 38px;"
                                   oninput="filtrarProductos(this.value)">
                            <button type="button" id="btn-limpiar-busqueda" onclick="limpiarBusqueda()"
                                    style="display: none; position: absolute; right: 0.75rem; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 0.9rem;"
                                    title="Limpiar búsqueda">✕</button>
                        </div>

                        <!-- Botón Exportar Excel (página actual) -->
                        <button type="button" id="btn-exportar-excel" onclick="exportarExcel()"
                                title="Descargar los registros de la página actual en Excel"
                                style="
                                    display: inline-flex; align-items: center; gap: 0.45rem;
                                    padding: 0 1.1rem; height: 38px;
                                    background: linear-gradient(135deg, rgba(34,197,94,0.18), rgba(16,185,129,0.12));
                                    border: 1px solid rgba(34,197,94,0.45);
                                    color: #4ade80;
                                    border-radius: 10px;
                                    font-size: 0.83rem; font-weight: 700;
                                    cursor: pointer;
                                    white-space: nowrap;
                                    flex-shrink: 0;
                                    transition: all 0.2s ease;
                                    letter-spacing: 0.02em;
                                "
                                onmouseover="this.style.background='linear-gradient(135deg,rgba(34,197,94,0.32),rgba(16,185,129,0.24))'; this.style.color='#fff'; this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(34,197,94,0.25)';"
                                onmouseout="this.style.background='linear-gradient(135deg,rgba(34,197,94,0.18),rgba(16,185,129,0.12))'; this.style.color='#4ade80'; this.style.transform=''; this.style.boxShadow='';"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="7 10 12 15 17 10"/>
                                <line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                            Exportar Excel
                        </button>
                    </div>
                </div>

                <table class="table" id="tabla-productos">
                    <thead>
                        <tr>
                            <th>Cód / SKU</th>
                            <th>Descripción</th>
                            <th>Tipo</th>
                            <th id="th-sort-stock" onclick="ordenarPorStock()" style="cursor: pointer; user-select: none; transition: all 0.2s;" title="Clic para ordenar: Menor a Mayor -> Mayor a Menor -> N/A primero">
                                <div style="display: inline-flex; align-items: center; gap: 0.45rem;">
                                    <span>Stock</span>
                                    <span id="icono-sort-stock" style="display: inline-flex; align-items: center;">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.4;">
                                            <path d="m7 15 5 5 5-5"/>
                                            <path d="m7 9 5-5 5 5"/>
                                        </svg>
                                    </span>
                                </div>
                            </th>
                            <th id="th-sort-costo" onclick="ordenarPorCosto()" style="cursor: pointer; user-select: none; transition: all 0.2s;" title="Clic para ordenar por Costo">
                                <div style="display: inline-flex; align-items: center; gap: 0.45rem;">
                                    <span>Costo</span>
                                    <span id="icono-sort-costo" style="display: inline-flex; align-items: center;">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.4;">
                                            <path d="m7 15 5 5 5-5"/>
                                            <path d="m7 9 5-5 5 5"/>
                                        </svg>
                                    </span>
                                </div>
                            </th>
                            <th id="th-sort-precio" onclick="ordenarPorPrecio()" style="cursor: pointer; user-select: none; transition: all 0.2s;" title="Clic para ordenar por Precio Venta">
                                <div style="display: inline-flex; align-items: center; gap: 0.45rem;">
                                    <span>Precio Venta</span>
                                    <span id="icono-sort-precio" style="display: inline-flex; align-items: center;">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.4;">
                                            <path d="m7 15 5 5 5-5"/>
                                            <path d="m7 9 5-5 5 5"/>
                                        </svg>
                                    </span>
                                </div>
                            </th>
                            <th>Unidad de Medida</th>
                            <th style="text-align: right;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($productos)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 2.5rem 1rem;">
                                No hay elementos en el catálogo aún.
                                <a href="productos_registro.php" style="color:var(--primary);text-decoration:none;margin-left:0.5rem;">Registrar primero →</a>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach($productos as $p): ?>
                        <tr class="fila-producto" 
                            data-sku="<?= strtolower(htmlspecialchars($p['codigo_interno_sku'] ?? '')) ?>" 
                            data-descripcion="<?= strtolower(htmlspecialchars($p['descripcion'] ?? '')) ?>" 
                            data-codigo="<?= strtolower(htmlspecialchars($p['codigo_barras'] ?? '')) ?>"
                            data-tipo="<?= strtolower(htmlspecialchars($p['tipo'] ?? '')) ?>"
                            data-is-na="<?= ($p['tipo'] === 'KIT') ? '1' : '0' ?>"
                            data-stock="<?= ($p['tipo'] === 'KIT') ? '-1' : floatval($p['stock_cantidad'] ?? 0) ?>"
                            data-costo="<?= floatval($p['costo'] ?? 0) ?>"
                            data-precio="<?= floatval($p['precio_venta'] ?? 0) ?>">
                            <td style="font-size: 0.85rem; color: var(--text-muted);">
                                <?= htmlspecialchars($p['codigo_barras'] ?? '') ?><br>
                                <small><?= htmlspecialchars($p['codigo_interno_sku'] ?? '') ?></small>
                            </td>
                            <td style="font-weight: 500;"><?= htmlspecialchars($p['descripcion']) ?></td>
                            <td>
                                <?php
                                    $badgeClass = 'badge-normal';
                                    if ($p['tipo'] == 'KIT')      $badgeClass = 'badge-kit';
                                    if ($p['tipo'] == 'SERVICIO') $badgeClass = 'badge-servicio';
                                ?>
                                <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($p['tipo']) ?></span>
                            </td>
                            <td style="font-weight: 600; color: <?= ($p['tipo'] === 'KIT') ? 'var(--text-muted)' : (($p['stock_cantidad'] <= 0) ? '#f87171' : 'var(--text-main)') ?>">
                                <?= ($p['tipo'] === 'KIT') ? '<span style="color: var(--text-muted); font-size: 0.85rem; font-weight: 500;">N/A</span>' : number_format($p['stock_cantidad'], 2) ?>
                                <?php if ($p['tipo'] === 'NORMAL' && isset($p['stock_minimo'])): ?>
                                    <small style="display:block;font-size:0.75rem;color:var(--text-muted);font-weight:normal;">Mín: <?= number_format($p['stock_minimo'], 2) ?></small>
                                <?php endif; ?>
                            </td>
                            <td style="color: var(--text-muted); font-size: 0.9rem;">$<?= number_format($p['costo'], 2) ?></td>
                            <td style="font-weight: 600;">$<?= number_format($p['precio_venta'], 2) ?></td>
                            <td style="font-size: 0.85rem; color: var(--text-muted); font-weight: 500;">
                                <?= htmlspecialchars($p['unidad_medida'] ?? '') ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <button type="button" class="btn-action btn-detalles" title="Ver Detalles" onclick="abrirModalDetalles('<?= htmlspecialchars($p['id'], ENT_QUOTES) ?>')">
                                    👁️ Detalles
                                </button>
                                <button type="button" class="btn-action btn-edit" title="Editar Producto" onclick="abrirModalEditar('<?= htmlspecialchars($p['id'], ENT_QUOTES) ?>')">
                                    ✏️ Editar
                                </button>
                                <button type="button" class="btn-action btn-delete" title="Eliminar Producto" onclick="abrirModalEliminar('<?= htmlspecialchars($p['id'], ENT_QUOTES) ?>', '<?= htmlspecialchars($p['descripcion'], ENT_QUOTES) ?>', '<?= htmlspecialchars($p['codigo_interno_sku'], ENT_QUOTES) ?>')">
                                    🗑️ Eliminar
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr id="fila-sin-resultados" style="display: none;">
                            <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 2.5rem 1rem;">
                                🔍 No se encontraron productos o insumos que coincidan con la búsqueda.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- ══ PAGINADOR ══════════════════════════════════════════════ -->
            <div id="paginador-wrapper" style="
                display: flex;
                align-items: center;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 0.75rem;
                margin-top: 1.25rem;
                padding: 0.85rem 1.1rem;
                background: rgba(255,255,255,0.04);
                border: 1px solid var(--border);
                border-radius: 12px;
            ">
                <!-- Info izquierda -->
                <div id="pag-info" style="font-size: 0.82rem; color: var(--text-muted);"></div>

                <!-- Botones de página -->
                <div id="pag-botones" style="display: flex; gap: 0.35rem; flex-wrap: wrap; align-items: center;"></div>

                <!-- Selector de registros por página -->
                <div style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.82rem; color: var(--text-muted);">
                    <span>Mostrar</span>
                    <select id="sel-por-pagina" onchange="cambiarRegistrosPorPagina(this.value)" style="
                        background: var(--sidebar-bg);
                        color: var(--text-main);
                        border: 1px solid var(--border);
                        border-radius: 7px;
                        padding: 0.3rem 0.5rem;
                        font-size: 0.82rem;
                        cursor: pointer;
                        outline: none;
                        transition: border-color 0.2s;
                    ">
                        <option value="10"  selected style="background:#1e293b; color:#f1f5f9;">10</option>
                        <option value="15"           style="background:#1e293b; color:#f1f5f9;">15</option>
                        <option value="20"           style="background:#1e293b; color:#f1f5f9;">20</option>
                        <option value="25"           style="background:#1e293b; color:#f1f5f9;">25</option>
                        <option value="30"           style="background:#1e293b; color:#f1f5f9;">30</option>
                    </select>
                    <span>por página</span>
                </div>
            </div>
        </main>
    </div>

    <!-- ══════════════════════════════════════════════════
         MODAL DETALLES
    ═══════════════════════════════════════════════════ -->
    <div id="modal-detalles" class="modal-overlay" onclick="cerrarModales(event)">
        <div class="modal-box" onclick="event.stopPropagation()">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.25rem;">
                <div>
                    <div id="det-tipo-badge" style="margin-bottom: 0.4rem;"></div>
                    <h3 id="det-descripcion" style="font-size: 1.35rem; color: #fff; margin: 0;"></h3>
                    <p id="det-sku" style="color: var(--text-muted); font-size: 0.85rem; margin-top: 0.2rem;"></p>
                </div>
                <button type="button" style="background: none; border: none; color: var(--text-muted); font-size: 1.4rem; cursor: pointer;" onclick="cerrarModales()">✕</button>
            </div>

            <div class="details-grid">
                <div class="details-card">
                    <div class="details-label">Código de Barras</div>
                    <div class="details-val" id="det-codigo-barras" style="font-family: monospace; font-size: 0.95rem;"></div>
                </div>
                <div class="details-card">
                    <div class="details-label">Unidad de Medida</div>
                    <div class="details-val" id="det-unidad"></div>
                </div>
                <div class="details-card" id="card-det-stock">
                    <div class="details-label">Stock Actual</div>
                    <div class="details-val" id="det-stock"></div>
                </div>
                <div class="details-card" id="card-det-stock-minimo">
                    <div class="details-label">Stock Mínimo</div>
                    <div class="details-val" id="det-stock-minimo"></div>
                </div>
                <div class="details-card">
                    <div class="details-label">Costo ($)</div>
                    <div class="details-val" id="det-costo" style="color: #94a3b8;"></div>
                </div>
                <div class="details-card">
                    <div class="details-label">Precio de Venta ($)</div>
                    <div class="details-val" id="det-precio" style="color: #4ade80;"></div>
                </div>
                <div class="details-card" id="card-det-margen">
                    <div class="details-label">Margen / Ganancia</div>
                    <div class="details-val" id="det-margen" style="color: #60a5fa;"></div>
                </div>
                <div class="details-card" id="card-det-ropa-kg" style="display:none;">
                    <div class="details-label">Kilos de Ropa</div>
                    <div class="details-val" id="det-ropa-kg"></div>
                </div>
                <div class="details-card">
                    <div class="details-label">Registrado Por</div>
                    <div class="details-val" id="det-usuario" style="font-size: 0.9rem; font-weight: 500;"></div>
                </div>
                <div class="details-card">
                    <div class="details-label">Fecha de Registro</div>
                    <div class="details-val" id="det-fecha" style="font-size: 0.85rem; font-weight: 500; color: var(--text-muted);"></div>
                </div>
            </div>

            <!-- Insumos del Kit -->
            <div id="det-kit-section" style="display:none; margin-top: 1rem; border-top: 1px solid var(--border); padding-top: 1rem;">
                <h4 style="font-size: 0.95rem; color: #c4b5fd; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.4rem;">
                    📦 Insumos del Kit
                </h4>
                <div id="det-kit-lista"></div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border);">
                <a id="btn-ver-historial" href="historial.php"
                   style="
                       display: inline-flex; align-items: center; gap: 0.45rem;
                       padding: 0.6rem 1.2rem;
                       background: linear-gradient(135deg, rgba(99,102,241,0.18), rgba(139,92,246,0.12));
                       border: 1px solid rgba(99,102,241,0.45);
                       color: #a5b4fc;
                       border-radius: 10px;
                       font-size: 0.83rem; font-weight: 700;
                       text-decoration: none;
                       white-space: nowrap;
                       transition: all 0.2s ease;
                       letter-spacing: 0.02em;
                   "
                   onmouseover="this.style.background='linear-gradient(135deg,rgba(99,102,241,0.32),rgba(139,92,246,0.24))'; this.style.color='#fff'; this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(99,102,241,0.3)';"
                   onmouseout="this.style.background='linear-gradient(135deg,rgba(99,102,241,0.18),rgba(139,92,246,0.12))'; this.style.color='#a5b4fc'; this.style.transform=''; this.style.boxShadow='';">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    Historial de Movimientos
                </a>
                <button type="button" class="btn-action" style="background: rgba(255,255,255,0.06); color: var(--text-main); padding: 0.6rem 1.25rem;" onclick="cerrarModales()">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════
         MODAL EDITAR PRODUCTO
    ═══════════════════════════════════════════════════ -->
    <div id="modal-editar" class="modal-overlay" onclick="cerrarModales(event)">
        <div class="modal-box" onclick="event.stopPropagation()">
            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem;">
                <div style="width: 40px; height: 40px; border-radius: 10px; background: rgba(59, 130, 246, 0.15); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; color: #60a5fa;">
                    ✏️
                </div>
                <div>
                    <h3 style="font-size: 1.25rem; color: #fff; margin: 0;">Editar Elemento</h3>
                    <p id="edit-subtitulo" style="color: var(--text-muted); font-size: 0.82rem; margin-top: 0.15rem;"></p>
                </div>
            </div>

            <form method="POST" id="form-editar" onsubmit="return solicitarGuardarEditar(event)">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="id" id="edit-id">

                <div class="form-group" style="margin-bottom: 1rem;">
                    <label class="form-label">Descripción / Nombre *</label>
                    <input type="text" name="descripcion" id="edit-descripcion" class="form-control" required>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Código de Barras</label>
                        <input type="text" name="codigo_barras" id="edit-codigo-barras" class="form-control">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Unidad de Medida</label>
                        <div class="select-wrapper">
                            <select name="unidad_medida" id="edit-unidad" class="form-control">
                                <option value="PIEZA">Pieza</option>
                                <option value="LITRO">Litro</option>
                                <option value="MILILITRO">Mililitro</option>
                                <option value="KG">Kilogramo</option>
                                <option value="GRAMO">Gramo</option>
                                <option value="METRO">Metro</option>
                                <option value="CARGA">Carga</option>
                                <option value="SERVICIO">Servicio</option>
                            </select>
                            <div class="select-arrow-btn">▼</div>
                        </div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div class="form-group" style="margin-bottom: 0;" id="group-edit-costo">
                        <label class="form-label">Costo ($) *</label>
                        <input type="number" step="0.01" min="0" name="costo" id="edit-costo" class="form-control" required>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Precio Venta ($) *</label>
                        <input type="number" step="0.01" min="0" name="precio_venta" id="edit-precio" class="form-control" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div class="form-group" style="margin-bottom: 0;" id="group-edit-stock-minimo">
                        <label class="form-label">Stock Mínimo</label>
                        <input type="number" step="0.01" min="0" name="stock_minimo" id="edit-stock-minimo" class="form-control">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;" id="group-edit-ropa-kg">
                        <label class="form-label">Kilos de Ropa (Kg)</label>
                        <input type="number" step="0.01" min="0" name="ropa_kg" id="edit-ropa-kg" class="form-control">
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                    <button type="button" class="btn-action" style="background: rgba(255,255,255,0.06); color: var(--text-main); padding: 0.65rem 1.25rem;" onclick="cerrarModales()">Cancelar</button>
                    <button type="submit" class="btn-primary" style="width: auto; padding: 0.65rem 1.5rem;">💾 Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════
         MODAL CONFIRMAR GUARDAR EDICIÓN
    ═══════════════════════════════════════════════════ -->
    <div id="modal-confirmar-guardar" class="modal-overlay" style="z-index: 10050;" onclick="cerrarModalConfirmarGuardar(event)">
        <div class="modal-box" style="max-width: 440px;" onclick="event.stopPropagation()">
            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
                <div style="width: 42px; height: 42px; border-radius: 12px; background: rgba(59, 130, 246, 0.15); display: flex; align-items: center; justify-content: center; font-size: 1.3rem; color: #60a5fa;">
                    💾
                </div>
                <div>
                    <h3 style="font-size: 1.2rem; color: #fff; margin: 0;">Confirmar Cambios</h3>
                    <p style="color: var(--text-muted); font-size: 0.82rem; margin-top: 0.15rem;">Guardar edición del producto</p>
                </div>
            </div>

            <p style="color: var(--text-muted); font-size: 0.9rem; line-height: 1.5; margin: 1rem 0;">
                ¿Estás seguro de que deseas guardar los cambios realizados en este producto?
            </p>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn-action" style="background: rgba(255,255,255,0.06); color: var(--text-main); padding: 0.65rem 1.25rem;" onclick="cerrarModalConfirmarGuardar()">Cancelar</button>
                <button type="button" class="btn-primary" style="width: auto; padding: 0.65rem 1.5rem;" onclick="ejecutarGuardarEditar()">Sí, Guardar</button>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════
         MODAL SIN CAMBIOS DETECTADOS
    ═══════════════════════════════════════════════════ -->
    <div id="modal-sin-cambios" class="modal-overlay" style="z-index: 10050;" onclick="cerrarModalSinCambios(event)">
        <div class="modal-box" style="max-width: 440px;" onclick="event.stopPropagation()">
            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
                <div style="width: 42px; height: 42px; border-radius: 12px; background: rgba(245, 158, 11, 0.15); display: flex; align-items: center; justify-content: center; font-size: 1.3rem; color: #fbbf24;">
                    ⚠️
                </div>
                <div>
                    <h3 style="font-size: 1.2rem; color: #fff; margin: 0;">Sin Cambios</h3>
                    <p style="color: var(--text-muted); font-size: 0.82rem; margin-top: 0.15rem;">Aviso del sistema</p>
                </div>
            </div>

            <p style="color: var(--text-main); font-size: 0.92rem; line-height: 1.5; margin: 1rem 0;">
                No se ha modificado ningún dato a guardar.
            </p>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn-primary" style="width: auto; padding: 0.65rem 1.5rem;" onclick="cerrarModalSinCambios()">Aceptar</button>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════
         MODAL ELIMINAR PRODUCTO
    ═══════════════════════════════════════════════════ -->
    <div id="modal-eliminar" class="modal-overlay" onclick="cerrarModales(event)">
        <div class="modal-box" style="max-width: 460px;" onclick="event.stopPropagation()">
            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
                <div style="width: 42px; height: 42px; border-radius: 12px; background: rgba(239, 68, 68, 0.15); display: flex; align-items: center; justify-content: center; font-size: 1.3rem; color: #f87171;">
                    🗑️
                </div>
                <div>
                    <h3 style="font-size: 1.2rem; color: #fff; margin: 0;">Eliminar Elemento</h3>
                    <p style="color: var(--text-muted); font-size: 0.82rem; margin-top: 0.15rem;">Esta acción no se puede deshacer</p>
                </div>
            </div>

            <p style="color: var(--text-muted); font-size: 0.9rem; line-height: 1.5; margin: 1rem 0;">
                ¿Estás seguro de que deseas eliminar permanentemente <strong id="del-nombre" style="color:#fff;"></strong> (<span id="del-sku" style="color:var(--primary);"></span>) del catálogo?
            </p>

            <form method="POST" id="form-eliminar">
                <input type="hidden" name="action" value="eliminar">
                <input type="hidden" name="id" id="del-id">

                <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                    <button type="button" class="btn-action" style="background: rgba(255,255,255,0.06); color: var(--text-main); padding: 0.65rem 1.25rem;" onclick="cerrarModales()">Cancelar</button>
                    <button type="submit" class="btn-action btn-delete" style="padding: 0.65rem 1.35rem; font-size: 0.9rem;">🗑️ Confirmar Eliminación</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const productosData = <?= $productosJson ?: '[]' ?>;

        function toggleSubmenu(id) {
            const el = document.getElementById(id);
            if (el) el.classList.toggle('open');
        }

        function cerrarModales(e) {
            if (e && e.target !== e.currentTarget) return;
            document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('active'));
        }

        // ── Modal Detalles ────────────────────────────────────────────────
        function abrirModalDetalles(id) {
            const prod = productosData.find(p => p.id === id);
            if (!prod) return;

            // Badges
            let badgeHtml = '';
            if (prod.tipo === 'NORMAL') {
                badgeHtml = '<span class="badge badge-normal">Normal / Insumo</span>';
            } else if (prod.tipo === 'KIT') {
                badgeHtml = '<span class="badge badge-kit">Kit / Paquete</span>';
            } else {
                badgeHtml = '<span class="badge badge-servicio">Servicio</span>';
            }

            document.getElementById('det-tipo-badge').innerHTML = badgeHtml;
            document.getElementById('det-descripcion').textContent = prod.descripcion;
            document.getElementById('det-sku').textContent = `SKU: ${prod.codigo_interno_sku || '—'}`;
            document.getElementById('det-codigo-barras').textContent = prod.codigo_barras || '—';
            document.getElementById('det-unidad').textContent = prod.unidad_medida || '—';

            // Stock & Stock Mínimo
            const cardStock = document.getElementById('card-det-stock');
            const cardStockMinimo = document.getElementById('card-det-stock-minimo');
            if (prod.tipo === 'KIT') {
                document.getElementById('det-stock').textContent = 'N/A';
                cardStockMinimo.style.display = 'none';
            } else if (prod.tipo === 'SERVICIO') {
                document.getElementById('det-stock').textContent = parseFloat(prod.stock_cantidad || 0).toFixed(2);
                cardStockMinimo.style.display = 'none';
            } else {
                document.getElementById('det-stock').textContent = parseFloat(prod.stock_cantidad || 0).toFixed(2);
                document.getElementById('det-stock-minimo').textContent = parseFloat(prod.stock_minimo || 0).toFixed(2);
                cardStockMinimo.style.display = 'block';
            }

            // Costo y Precio
            const costo = parseFloat(prod.costo || 0);
            const precio = parseFloat(prod.precio_venta || 0);
            document.getElementById('det-costo').textContent = `$${costo.toFixed(2)}`;
            document.getElementById('det-precio').textContent = `$${precio.toFixed(2)}`;

            // Margen
            const margen = precio - costo;
            const margenPct = (costo > 0) ? ((margen / costo) * 100).toFixed(1) : (precio > 0 ? '100' : '0');
            document.getElementById('det-margen').textContent = `$${margen.toFixed(2)} (${margenPct}%)`;

            // Ropa Kg
            const cardRopa = document.getElementById('card-det-ropa-kg');
            if (prod.tipo === 'KIT' && parseFloat(prod.ropa_kg) > 0) {
                document.getElementById('det-ropa-kg').textContent = `${parseFloat(prod.ropa_kg).toFixed(2)} Kg`;
                cardRopa.style.display = 'block';
            } else {
                cardRopa.style.display = 'none';
            }

            // Usuario y Fecha
            document.getElementById('det-usuario').textContent = prod.usuario_nombre || 'Sistema';
            document.getElementById('det-fecha').textContent = prod.fecha_registro || '—';

            // Insumos de Kit
            const kitSec = document.getElementById('det-kit-section');
            const kitLista = document.getElementById('det-kit-lista');
            if (prod.tipo === 'KIT' && Array.isArray(prod.insumos) && prod.insumos.length > 0) {
                kitSec.style.display = 'block';
                let tableHtml = `
                    <table class="kit-insumos-table">
                        <thead>
                            <tr>
                                <th>Insumo</th>
                                <th>Cantidad Consumida</th>
                                <th>Unidad</th>
                            </tr>
                        </thead>
                        <tbody>
                `;
                prod.insumos.forEach(ins => {
                    tableHtml += `
                        <tr>
                            <td style="font-weight: 500; color: #fff;">${escHtml(ins.insumo_descripcion || ins.insumo_id)}</td>
                            <td style="color: #c4b5fd; font-weight: 600;">${parseFloat(ins.cantidad_consumida).toFixed(2)}</td>
                            <td style="color: var(--text-muted);">${escHtml(ins.unidad || 'PIEZA')}</td>
                        </tr>
                    `;
                });
                tableHtml += '</tbody></table>';
                kitLista.innerHTML = tableHtml;
            } else {
                kitSec.style.display = 'none';
                kitLista.innerHTML = '';
            }

            document.getElementById('modal-detalles').classList.add('active');

            // Actualizar enlace al historial de este producto
            const btnHistorial = document.getElementById('btn-ver-historial');
            if (btnHistorial) {
                btnHistorial.href = `historial.php?sku=${encodeURIComponent(prod.codigo_interno_sku || '')}`;
            }
        }

        // ── Modal Editar ──────────────────────────────────────────────────
        let prodOriginalValores = null;
        let editFormConfirmado = false;

        function abrirModalEditar(id) {
            const prod = productosData.find(p => p.id === id);
            if (!prod) return;

            const desc = prod.descripcion || '';
            const cod = prod.codigo_barras || '';
            const unidad = prod.unidad_medida || 'PIEZA';
            const costo = parseFloat(prod.costo || 0).toFixed(2);
            const precio = parseFloat(prod.precio_venta || 0).toFixed(2);
            const stockMin = parseFloat(prod.stock_minimo || 0).toFixed(2);
            const ropaKg = parseFloat(prod.ropa_kg || 0).toFixed(2);

            document.getElementById('edit-id').value = prod.id;
            document.getElementById('edit-subtitulo').textContent = `SKU: ${prod.codigo_interno_sku} • Tipo: ${prod.tipo}`;
            document.getElementById('edit-descripcion').value = desc;
            document.getElementById('edit-codigo-barras').value = cod;
            document.getElementById('edit-unidad').value = unidad;
            document.getElementById('edit-costo').value = costo;
            document.getElementById('edit-precio').value = precio;
            document.getElementById('edit-stock-minimo').value = stockMin;
            document.getElementById('edit-ropa-kg').value = ropaKg;

            prodOriginalValores = {
                tipo: prod.tipo,
                descripcion: desc,
                codigo_barras: cod,
                unidad_medida: unidad,
                costo: costo,
                precio_venta: precio,
                stock_minimo: stockMin,
                ropa_kg: ropaKg
            };
            editFormConfirmado = false;

            const groupStockMinimo = document.getElementById('group-edit-stock-minimo');
            const groupRopaKg = document.getElementById('group-edit-ropa-kg');
            const groupCosto = document.getElementById('group-edit-costo');
            const selUnidad = document.getElementById('edit-unidad');

            if (prod.tipo === 'KIT') {
                groupStockMinimo.style.display = 'none';
                groupRopaKg.style.display = 'block';
                groupCosto.style.display = 'none';
                selUnidad.disabled = true;
            } else if (prod.tipo === 'SERVICIO') {
                groupStockMinimo.style.display = 'none';
                groupRopaKg.style.display = 'none';
                groupCosto.style.display = 'block';
                selUnidad.disabled = false;
            } else { // NORMAL
                groupStockMinimo.style.display = 'block';
                groupRopaKg.style.display = 'none';
                groupCosto.style.display = 'block';
                selUnidad.disabled = false;
            }

            document.getElementById('modal-editar').classList.add('active');
        }

        function solicitarGuardarEditar(e) {
            if (editFormConfirmado) {
                editFormConfirmado = false;
                return true;
            }
            if (e) e.preventDefault();

            if (!prodOriginalValores) {
                document.getElementById('modal-confirmar-guardar').classList.add('active');
                return false;
            }

            const curDesc = (document.getElementById('edit-descripcion').value || '').trim();
            const curCod = (document.getElementById('edit-codigo-barras').value || '').trim();
            const curUnidad = document.getElementById('edit-unidad').value;
            const curCosto = parseFloat(document.getElementById('edit-costo').value || 0).toFixed(2);
            const curPrecio = parseFloat(document.getElementById('edit-precio').value || 0).toFixed(2);
            const curStockMin = parseFloat(document.getElementById('edit-stock-minimo').value || 0).toFixed(2);
            const curRopaKg = parseFloat(document.getElementById('edit-ropa-kg').value || 0).toFixed(2);

            let hayCambios = false;

            if (curDesc !== prodOriginalValores.descripcion) hayCambios = true;
            if (curCod !== prodOriginalValores.codigo_barras) hayCambios = true;
            if (curPrecio !== prodOriginalValores.precio_venta) hayCambios = true;

            if (prodOriginalValores.tipo !== 'KIT') {
                if (curUnidad !== prodOriginalValores.unidad_medida) hayCambios = true;
                if (curCosto !== prodOriginalValores.costo) hayCambios = true;
            }
            if (prodOriginalValores.tipo === 'NORMAL') {
                if (curStockMin !== prodOriginalValores.stock_minimo) hayCambios = true;
            }
            if (prodOriginalValores.tipo === 'KIT' || prodOriginalValores.tipo === 'SERVICIO') {
                if (curRopaKg !== prodOriginalValores.ropa_kg) hayCambios = true;
            }

            if (!hayCambios) {
                document.getElementById('modal-sin-cambios').classList.add('active');
                return false;
            }

            document.getElementById('modal-confirmar-guardar').classList.add('active');
            return false;
        }

        function cerrarModalConfirmarGuardar(e) {
            if (e && e.target !== e.currentTarget) return;
            document.getElementById('modal-confirmar-guardar').classList.remove('active');
        }

        function cerrarModalSinCambios(e) {
            if (e && e.target !== e.currentTarget) return;
            document.getElementById('modal-sin-cambios').classList.remove('active');
        }

        function ejecutarGuardarEditar() {
            editFormConfirmado = true;
            document.getElementById('modal-confirmar-guardar').classList.remove('active');
            document.getElementById('form-editar').submit();
        }

        // ── Modal Eliminar ────────────────────────────────────────────────
        function abrirModalEliminar(id, descripcion, sku) {
            document.getElementById('del-id').value = id;
            document.getElementById('del-nombre').textContent = descripcion;
            document.getElementById('del-sku').textContent = `SKU: ${sku}`;
            document.getElementById('modal-eliminar').classList.add('active');
        }

        // ── Filtrado y Búsqueda en Tiempo Real ───────────────────────────
        function filtrarProductos(termino) {
            const q = (termino || '').trim().toLowerCase();
            const filas = document.querySelectorAll('.fila-producto');
            const btnLimpiar = document.getElementById('btn-limpiar-busqueda');

            if (btnLimpiar) {
                btnLimpiar.style.display = q.length > 0 ? 'block' : 'none';
            }

            filas.forEach(fila => {
                const sku  = fila.dataset.sku || '';
                const desc = fila.dataset.descripcion || '';
                const cod  = fila.dataset.codigo || '';
                const tipo = fila.dataset.tipo || '';
                const coincide = !q || sku.includes(q) || desc.includes(q) || cod.includes(q) || tipo.includes(q);
                // Usamos data-filtrado para que el paginador lo respete
                fila.dataset.filtrado = coincide ? '1' : '0';
            });

            renderPagina(1);
        }

        function limpiarBusqueda() {
            const input = document.getElementById('input-busqueda');
            if (input) {
                input.value = '';
                input.focus();
                filtrarProductos('');
            }
        }

        // ── Ordenamiento de Stock (3 Estados) ─────────────────────────────
        let sortStockState = 0; // 0: Inicial, 1: Menor a Mayor (N/A al final), 2: Mayor a Menor (N/A al final), 3: N/A Primero

        function ordenarPorStock() {
            sortStockState = (sortStockState % 3) + 1; // Ciclo: 1 -> 2 -> 3 -> 1

            const tbody = document.querySelector('#tabla-productos tbody');
            const filas = Array.from(document.querySelectorAll('.fila-producto'));
            const filaSinResultados = document.getElementById('fila-sin-resultados');
            const icono = document.getElementById('icono-sort-stock');
            const thStock = document.getElementById('th-sort-stock');

            filas.sort((a, b) => {
                const aIsNa = a.dataset.isNa === '1';
                const bIsNa = b.dataset.isNa === '1';
                const aStock = parseFloat(a.dataset.stock) || 0;
                const bStock = parseFloat(b.dataset.stock) || 0;

                if (sortStockState === 1) {
                    // 1er click: Menor a mayor, N/A al final
                    if (aIsNa && !bIsNa) return 1;
                    if (!aIsNa && bIsNa) return -1;
                    if (aIsNa && bIsNa) return 0;
                    return aStock - bStock;
                } else if (sortStockState === 2) {
                    // 2do click: Mayor a menor, N/A al final
                    if (aIsNa && !bIsNa) return 1;
                    if (!aIsNa && bIsNa) return -1;
                    if (aIsNa && bIsNa) return 0;
                    return bStock - aStock;
                } else if (sortStockState === 3) {
                    // 3er click: N/A primero, luego numéricos de menor a mayor
                    if (aIsNa && !bIsNa) return -1;
                    if (!aIsNa && bIsNa) return 1;
                    if (aIsNa && bIsNa) return 0;
                    return aStock - bStock;
                }
                return 0;
            });

            // Reinsertar filas ordenadas en el DOM
            filas.forEach(f => tbody.appendChild(f));
            if (filaSinResultados) tbody.appendChild(filaSinResultados);

            renderPagina(1);
            // Actualizar indicador visual
            if (icono && thStock) {
                if (sortStockState === 1) {
                    thStock.style.color = '#38bdf8';
                    icono.innerHTML = `
                        <span style="display:inline-flex; align-items:center; gap:3px; color:#38bdf8; font-size:0.75rem; font-weight:bold; background:rgba(56,189,248,0.15); border:1px solid rgba(56,189,248,0.3); padding:0.1rem 0.4rem; border-radius:6px;" title="Orden: Menor a Mayor (N/A al final)">
                            ▲ 0→9
                        </span>
                    `;
                } else if (sortStockState === 2) {
                    thStock.style.color = '#38bdf8';
                    icono.innerHTML = `
                        <span style="display:inline-flex; align-items:center; gap:3px; color:#38bdf8; font-size:0.75rem; font-weight:bold; background:rgba(56,189,248,0.15); border:1px solid rgba(56,189,248,0.3); padding:0.1rem 0.4rem; border-radius:6px;" title="Orden: Mayor a Menor (N/A al final)">
                            ▼ 9→0
                        </span>
                    `;
                } else if (sortStockState === 3) {
                    thStock.style.color = '#c4b5fd';
                    icono.innerHTML = `
                        <span style="display:inline-flex; align-items:center; gap:3px; color:#c4b5fd; font-size:0.75rem; font-weight:bold; background:rgba(196,181,253,0.15); border:1px solid rgba(196,181,253,0.3); padding:0.1rem 0.4rem; border-radius:6px;" title="Orden: N/A Primero">
                            ★ N/A
                        </span>
                    `;
                }
            }
        }

        // ── Ordenamiento de Costo (2 Estados) ─────────────────────────────────
        let sortCostoState = 0; // 0: Inicial, 1: Menor a Mayor, 2: Mayor a Menor

        function ordenarPorCosto() {
            sortCostoState = (sortCostoState % 2) + 1;

            const tbody = document.querySelector('#tabla-productos tbody');
            const filas = Array.from(document.querySelectorAll('.fila-producto'));
            const filaSinResultados = document.getElementById('fila-sin-resultados');
            const icono = document.getElementById('icono-sort-costo');
            const thCosto = document.getElementById('th-sort-costo');

            // Reset icono de stock
            const thStock = document.getElementById('th-sort-stock');
            const iconoStock = document.getElementById('icono-sort-stock');
            if (thStock) thStock.style.color = '';
            if (iconoStock) iconoStock.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.4;"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg>`;
            sortStockState = 0;

            filas.sort((a, b) => {
                const aCosto = parseFloat(a.dataset.costo) || 0;
                const bCosto = parseFloat(b.dataset.costo) || 0;
                return sortCostoState === 1 ? aCosto - bCosto : bCosto - aCosto;
            });

            filas.forEach(f => tbody.appendChild(f));
            if (filaSinResultados) tbody.appendChild(filaSinResultados);

            renderPagina(1);

            if (icono && thCosto) {
                if (sortCostoState === 1) {
                    thCosto.style.color = '#4ade80';
                    icono.innerHTML = `<span style="display:inline-flex; align-items:center; gap:3px; color:#4ade80; font-size:0.75rem; font-weight:bold; background:rgba(74,222,128,0.12); border:1px solid rgba(74,222,128,0.3); padding:0.1rem 0.4rem; border-radius:6px;" title="Costo: Menor a Mayor">▲ $</span>`;
                } else {
                    thCosto.style.color = '#4ade80';
                    icono.innerHTML = `<span style="display:inline-flex; align-items:center; gap:3px; color:#4ade80; font-size:0.75rem; font-weight:bold; background:rgba(74,222,128,0.12); border:1px solid rgba(74,222,128,0.3); padding:0.1rem 0.4rem; border-radius:6px;" title="Costo: Mayor a Menor">▼ $</span>`;
                }
            }
        }

        // ── Ordenamiento de Precio Venta (2 Estados) ──────────────────────
        let sortPrecioState = 0; // 0: Inicial, 1: Menor a Mayor, 2: Mayor a Menor

        function ordenarPorPrecio() {
            sortPrecioState = (sortPrecioState % 2) + 1;

            const tbody = document.querySelector('#tabla-productos tbody');
            const filas = Array.from(document.querySelectorAll('.fila-producto'));
            const filaSinResultados = document.getElementById('fila-sin-resultados');
            const icono = document.getElementById('icono-sort-precio');
            const thPrecio = document.getElementById('th-sort-precio');

            // Reset icono de stock
            const thStock = document.getElementById('th-sort-stock');
            const iconoStock = document.getElementById('icono-sort-stock');
            if (thStock) thStock.style.color = '';
            if (iconoStock) iconoStock.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.4;"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg>`;
            sortStockState = 0;

            // Reset icono de costo
            const thCosto = document.getElementById('th-sort-costo');
            const iconoCosto = document.getElementById('icono-sort-costo');
            if (thCosto) thCosto.style.color = '';
            if (iconoCosto) iconoCosto.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.4;"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg>`;
            sortCostoState = 0;

            filas.sort((a, b) => {
                const aPrecio = parseFloat(a.dataset.precio) || 0;
                const bPrecio = parseFloat(b.dataset.precio) || 0;
                return sortPrecioState === 1 ? aPrecio - bPrecio : bPrecio - aPrecio;
            });

            filas.forEach(f => tbody.appendChild(f));
            if (filaSinResultados) tbody.appendChild(filaSinResultados);

            renderPagina(1);

            if (icono && thPrecio) {
                if (sortPrecioState === 1) {
                    thPrecio.style.color = '#38bdf8';
                    icono.innerHTML = `<span style="display:inline-flex; align-items:center; gap:3px; color:#38bdf8; font-size:0.75rem; font-weight:bold; background:rgba(56,189,248,0.12); border:1px solid rgba(56,189,248,0.3); padding:0.1rem 0.4rem; border-radius:6px;" title="Precio Venta: Menor a Mayor">▲ $</span>`;
                } else {
                    thPrecio.style.color = '#38bdf8';
                    icono.innerHTML = `<span style="display:inline-flex; align-items:center; gap:3px; color:#38bdf8; font-size:0.75rem; font-weight:bold; background:rgba(56,189,248,0.12); border:1px solid rgba(56,189,248,0.3); padding:0.1rem 0.4rem; border-radius:6px;" title="Precio Venta: Mayor a Menor">▼ $</span>`;
                }
            }
        }

        function escHtml(str) {
            return String(str || '')
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // ══════════════════════════════════════════════════════════════════
        //  MOTOR DE PAGINACIÓN
        // ══════════════════════════════════════════════════════════════════
        let paginaActual  = 1;
        let porPagina     = 10;

        function getFilasFiltradas() {
            // Devuelve TODAS las filas que pasaron el filtro de búsqueda,
            // en el orden DOM actual (respeta cualquier ordenamiento previo).
            return Array.from(document.querySelectorAll('.fila-producto'))
                        .filter(f => f.dataset.filtrado !== '0');
        }

        function renderPagina(pagina) {
            const filasFiltradas  = getFilasFiltradas();
            const total           = filasFiltradas.length;
            const totalPaginas    = Math.max(1, Math.ceil(total / porPagina));
            paginaActual          = Math.min(Math.max(1, pagina), totalPaginas);

            const inicio = (paginaActual - 1) * porPagina;
            const fin    = inicio + porPagina;

            // Mostrar / ocultar filas según la página
            document.querySelectorAll('.fila-producto').forEach(f => {
                f.style.display = 'none';
            });
            filasFiltradas.forEach((f, i) => {
                f.style.display = (i >= inicio && i < fin) ? '' : 'none';
            });

            // Fila de sin resultados
            const filaSinRes = document.getElementById('fila-sin-resultados');
            if (filaSinRes) filaSinRes.style.display = total === 0 ? '' : 'none';

            // Badge total
            const badgeTotal = document.getElementById('badge-total-items');
            const inputVal   = (document.getElementById('input-busqueda')?.value || '').trim();
            const totalTodos = document.querySelectorAll('.fila-producto').length;
            if (badgeTotal) {
                if (inputVal.length > 0) {
                    badgeTotal.textContent = `${total} de ${totalTodos}`;
                    badgeTotal.style.color        = '#38bdf8';
                    badgeTotal.style.borderColor  = 'rgba(56,189,248,0.3)';
                    badgeTotal.style.background   = 'rgba(56,189,248,0.1)';
                } else {
                    badgeTotal.textContent = `${totalTodos} elementos`;
                    badgeTotal.style.color        = 'var(--text-muted)';
                    badgeTotal.style.borderColor  = 'var(--border)';
                    badgeTotal.style.background   = 'rgba(255,255,255,0.06)';
                }
            }

            // Info izquierda
            const infoEl = document.getElementById('pag-info');
            if (infoEl) {
                if (total === 0) {
                    infoEl.textContent = 'Sin resultados';
                } else {
                    const desde = inicio + 1;
                    const hasta = Math.min(fin, total);
                    infoEl.textContent = `Mostrando ${desde}–${hasta} de ${total} registro${total !== 1 ? 's' : ''}`;
                }
            }

            // Botones de página
            const botsEl = document.getElementById('pag-botones');
            if (!botsEl) return;
            botsEl.innerHTML = '';

            const btnStyle = (activo) => `
                cursor:pointer; border:1px solid var(--border); border-radius:7px;
                padding:0.3rem 0.65rem; font-size:0.8rem; font-weight:600;
                transition: all 0.18s;
                background:${activo ? 'var(--primary)' : 'rgba(255,255,255,0.05)'};
                color:${activo ? '#fff' : 'var(--text-muted)'};
                min-width:2.1rem; text-align:center;
            `;

            // Botón « Anterior
            const btnPrev = document.createElement('button');
            btnPrev.innerHTML = '&#8249;';
            btnPrev.title = 'Página anterior';
            btnPrev.style.cssText = btnStyle(false) + (paginaActual === 1 ? 'opacity:0.35;cursor:default;' : '');
            btnPrev.disabled = paginaActual === 1;
            btnPrev.onclick = () => renderPagina(paginaActual - 1);
            botsEl.appendChild(btnPrev);

            // Páginas con ventana deslizante ±2
            const ventana = 2;
            let pStart = Math.max(1, paginaActual - ventana);
            let pEnd   = Math.min(totalPaginas, paginaActual + ventana);

            if (pStart > 1) {
                const b = document.createElement('button');
                b.textContent = '1'; b.style.cssText = btnStyle(false);
                b.onclick = () => renderPagina(1); botsEl.appendChild(b);
                if (pStart > 2) {
                    const dots = document.createElement('span');
                    dots.textContent = '…';
                    dots.style.cssText = 'padding:0 0.3rem; color:var(--text-muted); font-size:0.8rem;';
                    botsEl.appendChild(dots);
                }
            }

            for (let p = pStart; p <= pEnd; p++) {
                const b = document.createElement('button');
                b.textContent = p;
                b.style.cssText = btnStyle(p === paginaActual);
                if (p === paginaActual) b.style.boxShadow = '0 0 0 2px rgba(var(--primary-rgb),0.35)';
                b.onclick = () => renderPagina(p);
                botsEl.appendChild(b);
            }

            if (pEnd < totalPaginas) {
                if (pEnd < totalPaginas - 1) {
                    const dots = document.createElement('span');
                    dots.textContent = '…';
                    dots.style.cssText = 'padding:0 0.3rem; color:var(--text-muted); font-size:0.8rem;';
                    botsEl.appendChild(dots);
                }
                const b = document.createElement('button');
                b.textContent = totalPaginas; b.style.cssText = btnStyle(false);
                b.onclick = () => renderPagina(totalPaginas); botsEl.appendChild(b);
            }

            // Botón » Siguiente
            const btnNext = document.createElement('button');
            btnNext.innerHTML = '&#8250;';
            btnNext.title = 'Página siguiente';
            btnNext.style.cssText = btnStyle(false) + (paginaActual === totalPaginas ? 'opacity:0.35;cursor:default;' : '');
            btnNext.disabled = paginaActual === totalPaginas;
            btnNext.onclick = () => renderPagina(paginaActual + 1);
            botsEl.appendChild(btnNext);
        }

        function goToPage(p) { renderPagina(p); }

        function cambiarRegistrosPorPagina(val) {
            porPagina = parseInt(val, 10) || 10;
            renderPagina(1);
        }

        // ── Exportar a Excel (página actual) ─────────────────────────────
        function exportarExcel() {
            // Obtener filas visibles (sólo las de la página actual)
            const filasVisibles = Array.from(document.querySelectorAll('.fila-producto'))
                .filter(f => f.style.display !== 'none' && f.dataset.filtrado !== '0');

            if (filasVisibles.length === 0) {
                alert('No hay registros visibles para exportar en la página actual.');
                return;
            }

            // Construir datos desde productosData usando los IDs de las filas visibles
            const idsVisibles = new Set(filasVisibles.map(f => {
                // El ID está en el onclick del botón Detalles; lo obtenemos del dataset o buscamos en productosData por SKU
                const skuFila = f.dataset.sku;
                return skuFila;
            }));

            // Mapear filas visibles a sus registros en productosData
            const registros = filasVisibles.map(f => {
                const sku = f.dataset.sku;
                const prod = productosData.find(p => (p.codigo_interno_sku || '').toLowerCase() === sku);
                if (!prod) {
                    // Fallback: extraer desde el DOM
                    const celdas = f.querySelectorAll('td');
                    return {
                        'SKU / Código Interno': celdas[0]?.innerText?.replace(/\n/g, ' | ').trim() || '',
                        'Descripción':          celdas[1]?.innerText?.trim() || '',
                        'Tipo':                 celdas[2]?.innerText?.trim() || '',
                        'Stock Actual':         celdas[3]?.innerText?.trim() || '',
                        'Costo ($)':            celdas[4]?.innerText?.trim() || '',
                        'Precio Venta ($)':     celdas[5]?.innerText?.trim() || '',
                        'Unidad de Medida':     celdas[6]?.innerText?.trim() || '',
                    };
                }
                return {
                    'SKU Interno':          prod.codigo_interno_sku || '',
                    'Código de Barras':     prod.codigo_barras || '',
                    'Descripción':          prod.descripcion || '',
                    'Tipo':                 prod.tipo || '',
                    'Unidad de Medida':     prod.unidad_medida || '',
                    'Stock Actual':         prod.tipo === 'KIT' ? 'N/A' : parseFloat(prod.stock_cantidad || 0).toFixed(2),
                    'Stock Mínimo':         prod.tipo === 'KIT' ? 'N/A' : parseFloat(prod.stock_minimo || 0).toFixed(2),
                    'Costo ($)':            parseFloat(prod.costo || 0).toFixed(2),
                    'Precio Venta ($)':     parseFloat(prod.precio_venta || 0).toFixed(2),
                    'Margen ($)':           (parseFloat(prod.precio_venta || 0) - parseFloat(prod.costo || 0)).toFixed(2),
                    'Ropa Kg':              parseFloat(prod.ropa_kg || 0).toFixed(2),
                    'Registrado Por':       prod.usuario_nombre || '',
                    'Fecha de Registro':    prod.fecha_registro || '',
                };
            });

            // Crear hoja de trabajo
            const ws = XLSX.utils.json_to_sheet(registros);

            // Ajustar ancho de columnas automáticamente
            const colWidths = [];
            if (registros.length > 0) {
                const headers = Object.keys(registros[0]);
                headers.forEach((h, i) => {
                    let max = h.length;
                    registros.forEach(r => {
                        const val = String(r[h] || '');
                        if (val.length > max) max = val.length;
                    });
                    colWidths.push({ wch: Math.min(max + 2, 45) });
                });
                ws['!cols'] = colWidths;
            }

            // Crear libro y descargar
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Catálogo');

            const ahora = new Date();
            const fecha = `${ahora.getFullYear()}${String(ahora.getMonth()+1).padStart(2,'0')}${String(ahora.getDate()).padStart(2,'0')}`;
            const hora  = `${String(ahora.getHours()).padStart(2,'0')}${String(ahora.getMinutes()).padStart(2,'0')}`;
            XLSX.writeFile(wb, `catalogo_productos_${fecha}_${hora}.xlsx`);
        }

        // Inicializar paginado al cargar la página
        document.addEventListener('DOMContentLoaded', () => {
            // Marcar todas como visibles para el filtro
            document.querySelectorAll('.fila-producto').forEach(f => {
                if (!f.dataset.filtrado) f.dataset.filtrado = '1';
            });
            renderPagina(1);
        });
    </script>
    <script src="js/sidebar.js"></script>
</body>
</html>
