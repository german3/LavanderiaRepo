<?php
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../src/modules/auth/AuthService.php';
require_once __DIR__ . '/../src/modules/catalog/Producto.php';
require_once __DIR__ . '/../src/modules/inventory/MovimientoInventario.php';
require_once __DIR__ . '/../src/modules/inventory/EntradaCompra.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;
use App\Modules\Catalog\Producto;
use App\Modules\Inventory\EntradaCompra;

$db            = (new Database())->getConnection();
$auth          = new AuthService($db);
$productoModel = new Producto($db);
$entradaModel  = new EntradaCompra($db);

if (!$auth->checkAuth()) { header("Location: login.php"); exit; }

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$mensaje = "";
$error   = "";

// ── Procesar registro ────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['action'] ?? '') === 'registrar_compra') {
    $proveedor    = trim($_POST['proveedor'] ?? '');
    $usuarioId    = $_SESSION['usuario_id'] ?? null;
    $prodIds      = $_POST['item_producto_id'] ?? [];
    $cantidades   = $_POST['item_cantidad'] ?? [];
    $costos       = $_POST['item_costo_unitario'] ?? [];

    $items = [];
    foreach ($prodIds as $i => $pid) {
        if (empty($pid) || floatval($cantidades[$i] ?? 0) <= 0) continue;
        $items[] = [
            'producto_id'    => $pid,
            'cantidad'       => floatval($cantidades[$i]),
            'costo_unitario' => floatval($costos[$i] ?? 0),
        ];
    }

    $res = $entradaModel->registrarEntrada([
        'proveedor'  => $proveedor,
        'usuario_id' => $usuarioId,
        'items'      => $items,
    ]);

    if ($res['ok']) {
        $mensaje = "Entrada registrada exitosamente. Folio: <strong>" . htmlspecialchars($res['folio']) . "</strong>";
    } else {
        $error = $res['error'] ?? 'Error al registrar la entrada.';
    }
}

$productosNormales = $productoModel->obtenerProductosNormales();
$entradas          = $entradaModel->listarEntradas(200);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entradas de Compra | Lavandería</title>
    <meta name="description" content="Registro de entradas de compra y recepción de insumos con folio automático.">
    <link rel="stylesheet" href="css/index.css">
    <style>
        .table { width:100%; border-collapse:collapse; color:var(--text-main); }
        .table th { padding:0.75rem 1rem; text-align:left; font-size:0.78rem; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted); border-bottom:2px solid var(--border); }
        .table td { padding:0.85rem 1rem; border-bottom:1px solid var(--border); vertical-align:middle; font-size:0.88rem; }
        .table tbody tr:hover td { background:rgba(255,255,255,0.025); }

        .folio-chip { font-family:'Courier New',monospace; font-size:0.82rem; font-weight:700; background:rgba(59,130,246,0.12); color:#60a5fa; border:1px solid rgba(59,130,246,0.3); padding:0.2rem 0.65rem; border-radius:7px; letter-spacing:0.04em; }

        .modal-overlay { position:fixed; inset:0; background:rgba(15,23,42,0.8); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); display:none; align-items:center; justify-content:center; z-index:9999; }
        .modal-overlay.active { display:flex; animation:fadeIn 0.2s ease; }
        .modal-box { background:#1e293b; border:1px solid var(--border); border-radius:20px; padding:2rem; max-width:700px; width:95%; max-height:90vh; overflow-y:auto; box-shadow:0 25px 60px -12px rgba(0,0,0,0.7); animation:slideUp 0.3s cubic-bezier(0.16,1,0.3,1); }
        .det-modal-box { background:#1e293b; border:1px solid var(--border); border-radius:20px; padding:2rem; max-width:600px; width:95%; max-height:85vh; overflow-y:auto; box-shadow:0 25px 60px -12px rgba(0,0,0,0.7); animation:slideUp 0.3s cubic-bezier(0.16,1,0.3,1); }
        @keyframes fadeIn  { from{opacity:0}to{opacity:1} }
        @keyframes slideUp { from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)} }

        .item-row { display:grid; grid-template-columns:1fr 90px 105px 32px; gap:0.5rem; align-items:center; background:rgba(15,23,42,0.35); border:1px solid var(--border); border-radius:10px; padding:0.65rem 0.85rem; margin-bottom:0.5rem; transition:border-color 0.2s; }
        .item-row:hover { border-color:rgba(59,130,246,0.4); }
        .item-row select, .item-row input { font-size:0.83rem; }
        .btn-rm { background:rgba(239,68,68,0.15); color:#f87171; border:1px solid rgba(239,68,68,0.25); border-radius:7px; width:28px; height:28px; cursor:pointer; font-size:0.9rem; display:flex; align-items:center; justify-content:center; transition:all 0.2s; }
        .btn-rm:hover { background:rgba(239,68,68,0.3); }

        .subtotal-bar { display:flex; justify-content:flex-end; gap:2rem; background:rgba(59,130,246,0.06); border:1px solid rgba(59,130,246,0.2); border-radius:10px; padding:0.75rem 1.25rem; margin-top:0.75rem; }
        .subtotal-bar .lbl { font-size:0.79rem; color:var(--text-muted); }
        .subtotal-bar .val { font-size:1.05rem; font-weight:700; color:#4ade80; }

        .stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:1rem; margin-bottom:2rem; }
        .stat-card { background:var(--bg-card); border:1px solid var(--border); border-radius:16px; padding:1.25rem 1.5rem; }
        .stat-label { font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); }
        .stat-value { font-size:1.55rem; font-weight:700; display:block; margin:0.25rem 0 0.2rem; }
        .stat-sub   { font-size:0.77rem; color:var(--text-muted); }

        .pag-btn { cursor:pointer; border:1px solid var(--border); border-radius:7px; padding:0.3rem 0.65rem; font-size:0.8rem; font-weight:600; transition:all 0.18s; background:rgba(255,255,255,0.05); color:var(--text-muted); min-width:2.1rem; text-align:center; }
        .pag-btn.active { background:var(--primary); color:#fff; }
    </style>
</head>
<body>
<div class="app-container">
    <?php $activePage = 'compras'; require_once __DIR__ . '/partials/sidebar.php'; ?>

    <main class="main-content">
        <header style="margin-bottom:2rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
            <div>
                <h1 style="font-size:2rem;">Entradas de Compra</h1>
                <p style="color:var(--text-muted);">Recepción de insumos con folio automático y actualización inmediata de stock.</p>
            </div>
            <button type="button" class="btn-primary" style="width:auto;padding:0.6rem 1.4rem;font-size:0.9rem;" onclick="abrirModalCompra()">
                🛒 Nueva Entrada
            </button>
        </header>

        <?php if ($mensaje): ?>
        <div style="background:rgba(16,185,129,0.1);color:#34d399;border:1px solid rgba(16,185,129,0.2);padding:1rem 1.25rem;border-radius:12px;margin-bottom:1.5rem;">
            <?= $mensaje ?>
        </div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert-error" style="margin-bottom:1.5rem;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <?php
        $cntEntradas = count($entradas);
        $totalImp    = array_sum(array_column($entradas, 'total'));
        $provCount   = count(array_unique(array_column($entradas, 'proveedor')));
        ?>
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-label">Total Entradas</span>
                <span class="stat-value" style="color:#60a5fa;"><?= $cntEntradas ?></span>
                <span class="stat-sub">registradas</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Importe Total</span>
                <span class="stat-value" style="color:#4ade80;">$<?= number_format($totalImp, 2) ?></span>
                <span class="stat-sub">en compras</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Proveedores</span>
                <span class="stat-value" style="color:#a78bfa;"><?= $provCount ?></span>
                <span class="stat-sub">distintos</span>
            </div>
        </div>

        <!-- Tabla -->
        <div class="auth-card" style="width:100%;max-width:100%;padding:2rem;overflow-x:auto;">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;margin-bottom:1.25rem;">
                <h3 style="margin:0;">Historial de Compras</h3>
                <div style="display:flex;align-items:center;gap:0.5rem;background:rgba(255,255,255,0.05);border:1px solid var(--border);border-radius:10px;padding:0.45rem 0.85rem;min-width:250px;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted);flex-shrink:0;"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                    <input id="input-busqueda-compras" type="text" placeholder="Buscar folio o proveedor…"
                        oninput="filtrarCompras(this.value)"
                        style="background:transparent;border:none;outline:none;color:var(--text-main);font-size:0.85rem;width:100%;min-width:0;" autocomplete="off">
                    <button id="btn-limpiar-compras" onclick="limpiarBusqueda()" title="Limpiar"
                        style="display:none;background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:1rem;padding:0;line-height:1;">✕</button>
                </div>
            </div>

            <table class="table" id="tabla-compras">
                <thead>
                    <tr>
                        <th>Folio</th>
                        <th>Proveedor</th>
                        <th>Fecha</th>
                        <th>Registró</th>
                        <th style="text-align:right;">Total ($)</th>
                        <th style="text-align:center;">Detalle</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($entradas)): ?>
                    <tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:2.5rem;">
                        Sin entradas registradas. Usa "Nueva Entrada" para comenzar.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($entradas as $e): ?>
                    <tr class="fila-compra"
                        data-folio="<?= strtolower(htmlspecialchars($e['folio'])) ?>"
                        data-proveedor="<?= strtolower(htmlspecialchars($e['proveedor'])) ?>"
                        data-filtrado="1">
                        <td><span class="folio-chip"><?= htmlspecialchars($e['folio']) ?></span></td>
                        <td style="font-weight:500;"><?= htmlspecialchars($e['proveedor']) ?></td>
                        <td style="color:var(--text-muted);font-size:0.82rem;"><?= date('d/m/Y H:i', strtotime($e['fecha'])) ?></td>
                        <td style="color:var(--text-muted);"><?= htmlspecialchars($e['usuario_nombre'] ?? '—') ?></td>
                        <td style="text-align:right;font-weight:700;color:#4ade80;">$<?= number_format($e['total'], 2) ?></td>
                        <td style="text-align:center;">
                            <button type="button" class="btn-primary"
                                    style="padding:0.35rem 0.85rem;font-size:0.78rem;width:auto;"
                                    onclick="verDetalle('<?= htmlspecialchars($e['id'], ENT_QUOTES) ?>')">
                                Ver ítems
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <div id="compras-sin-resultados" style="display:none;text-align:center;color:var(--text-muted);padding:2.5rem;">
                🔍 No se encontraron registros.
            </div>

            <!-- Paginador -->
            <div id="paginador-compras" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;margin-top:1.25rem;padding:0.85rem 1.1rem;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:12px;">
                <div id="pag-compras-info" style="font-size:0.82rem;color:var(--text-muted);"></div>
                <div id="pag-compras-botones" style="display:flex;gap:0.35rem;flex-wrap:wrap;align-items:center;"></div>
                <div style="display:flex;align-items:center;gap:0.5rem;font-size:0.82rem;color:var(--text-muted);">
                    <span>Mostrar</span>
                    <select id="sel-compras-pagina" onchange="cambiarPorPagina(this.value)"
                        style="background:var(--sidebar-bg);color:var(--text-main);border:1px solid var(--border);border-radius:7px;padding:0.3rem 0.5rem;font-size:0.82rem;cursor:pointer;outline:none;">
                        <option value="10" selected style="background:#1e293b;color:#f1f5f9;">10</option>
                        <option value="20" style="background:#1e293b;color:#f1f5f9;">20</option>
                        <option value="50" style="background:#1e293b;color:#f1f5f9;">50</option>
                    </select>
                    <span>por página</span>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- ══ Modal: Nueva Compra ═════════════════════════════════════════════════ -->
<div id="modal-compra" class="modal-overlay" onclick="if(event.target===this)cerrarModal('modal-compra')">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;">
            <div style="width:44px;height:44px;border-radius:12px;background:rgba(34,197,94,0.15);display:flex;align-items:center;justify-content:center;font-size:1.4rem;">🛒</div>
            <div>
                <h3 style="font-size:1.2rem;color:#fff;margin-bottom:0.15rem;">Nueva Entrada de Compra</h3>
                <p style="color:var(--text-muted);font-size:0.83rem;">Folio asignado automáticamente al guardar.</p>
            </div>
        </div>

        <form method="POST" id="form-compra" onsubmit="return validarForm()">
            <input type="hidden" name="action" value="registrar_compra">

            <div class="form-group" style="margin-bottom:1.15rem;">
                <label class="form-label">Proveedor *</label>
                <input type="text" name="proveedor" id="comp-proveedor" class="form-control"
                       placeholder="Nombre del proveedor o empresa" required autocomplete="off">
            </div>

            <!-- Header cols -->
            <div style="display:grid;grid-template-columns:1fr 90px 105px 32px;gap:0.5rem;padding:0 0.85rem;margin-bottom:0.4rem;">
                <span style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);">Producto</span>
                <span style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);">Cantidad</span>
                <span style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);">Costo Unit. $</span>
                <span></span>
            </div>

            <div id="items-container"></div>

            <button type="button"
                    style="width:100%;padding:0.55rem;border-radius:9px;border:1px dashed rgba(59,130,246,0.4);background:rgba(59,130,246,0.06);color:#60a5fa;font-size:0.85rem;cursor:pointer;transition:all 0.2s;margin-bottom:0.75rem;"
                    onmouseenter="this.style.background='rgba(59,130,246,0.12)'"
                    onmouseleave="this.style.background='rgba(59,130,246,0.06)'"
                    onclick="agregarItem()">
                ＋ Agregar producto
            </button>

            <div class="subtotal-bar">
                <div><div class="lbl">Ítems</div><div id="total-items" style="font-weight:600;color:var(--text-main);">0</div></div>
                <div><div class="lbl">Total Compra</div><div class="val" id="total-importe">$0.00</div></div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:0.75rem;margin-top:1.5rem;">
                <button type="button" onclick="cerrarModal('modal-compra')"
                        style="background:rgba(255,255,255,0.06);color:var(--text-main);padding:0.65rem 1.25rem;border-radius:8px;border:1px solid var(--border);cursor:pointer;">
                    Cancelar
                </button>
                <button type="submit" class="btn-primary" style="padding:0.65rem 1.5rem;width:auto;font-size:0.88rem;border-radius:8px;">
                    💾 Registrar Compra
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══ Modal: Detalle Entrada ══════════════════════════════════════════════ -->
<div id="modal-detalle" class="modal-overlay" onclick="if(event.target===this)cerrarModal('modal-detalle')">
    <div class="det-modal-box" onclick="event.stopPropagation()">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:0.75rem;">
            <div>
                <h3 style="font-size:1.1rem;color:#fff;margin-bottom:0.1rem;">Detalle de Entrada</h3>
                <span id="det-folio" class="folio-chip"></span>
            </div>
            <button type="button" onclick="cerrarModal('modal-detalle')"
                    style="background:rgba(255,255,255,0.07);border:1px solid var(--border);border-radius:8px;color:var(--text-muted);padding:0.4rem 0.8rem;cursor:pointer;font-size:0.82rem;">
                ✕ Cerrar
            </button>
        </div>
        <div id="det-loading" style="text-align:center;padding:2rem;color:var(--text-muted);">Cargando…</div>
        <div id="det-contenido" style="display:none;">
            <div id="det-cabecera" style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;background:rgba(15,23,42,0.4);border:1px solid var(--border);border-radius:12px;padding:1rem;margin-bottom:1.25rem;font-size:0.85rem;"></div>
            <div style="overflow-x:auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>SKU</th>
                            <th style="text-align:right;">Cant.</th>
                            <th style="text-align:right;">Costo Unit.</th>
                            <th style="text-align:right;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody id="det-tbody"></tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" style="text-align:right;font-weight:600;padding-top:0.75rem;color:var(--text-muted);">Total:</td>
                            <td style="text-align:right;font-weight:700;font-size:1rem;color:#4ade80;padding-top:0.75rem;" id="det-total"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
const PRODUCTOS = <?= json_encode(array_values($productosNormales), JSON_HEX_TAG | JSON_HEX_QUOT) ?>;
let itemCount = 0;

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = String(str ?? '');
    return d.innerHTML;
}

function agregarItem() {
    const container = document.getElementById('items-container');
    const idx = itemCount++;
    const row = document.createElement('div');
    row.className = 'item-row';
    row.id = 'item-row-' + idx;

    const opts = PRODUCTOS.map(p =>
        `<option value="${escHtml(p.id)}" data-costo="${parseFloat(p.costo||0).toFixed(2)}">${escHtml(p.descripcion)} (${escHtml(p.unidad_medida)})</option>`
    ).join('');

    row.innerHTML = `
        <select name="item_producto_id[]" class="form-control item-producto" required onchange="onProdChange(${idx})">
            <option value="">— Seleccionar —</option>${opts}
        </select>
        <input type="number" name="item_cantidad[]" class="form-control item-cantidad"
               placeholder="0.00" step="0.01" min="0.01" required oninput="recalc()">
        <input type="number" name="item_costo_unitario[]" class="form-control item-costo"
               placeholder="0.00" step="0.01" min="0" oninput="recalc()">
        <button type="button" class="btn-rm" onclick="quitarItem(${idx})" title="Quitar ítem">✕</button>
    `;
    container.appendChild(row);
    recalc();
}

function onProdChange(idx) {
    const row = document.getElementById('item-row-' + idx);
    if (!row) return;
    const sel  = row.querySelector('.item-producto');
    const opt  = sel?.options[sel.selectedIndex];
    const costo = parseFloat(opt?.getAttribute('data-costo') || 0);
    const inpCosto = row.querySelector('.item-costo');
    if (inpCosto && costo > 0) inpCosto.value = costo.toFixed(2);
    recalc();
}

function quitarItem(idx) {
    document.getElementById('item-row-' + idx)?.remove();
    recalc();
}

function recalc() {
    let total = 0, items = 0;
    document.querySelectorAll('.item-row').forEach(r => {
        const cant  = parseFloat(r.querySelector('.item-cantidad')?.value || 0);
        const costo = parseFloat(r.querySelector('.item-costo')?.value || 0);
        if (cant > 0) { total += cant * costo; items++; }
    });
    document.getElementById('total-importe').textContent = '$' + total.toFixed(2);
    document.getElementById('total-items').textContent   = items;
}

function abrirModalCompra() {
    document.getElementById('comp-proveedor').value = '';
    document.getElementById('items-container').innerHTML = '';
    itemCount = 0;
    agregarItem();
    recalc();
    document.getElementById('modal-compra').classList.add('active');
    setTimeout(() => document.getElementById('comp-proveedor').focus(), 100);
}

function validarForm() {
    const filas = document.querySelectorAll('.item-row');
    if (!filas.length) { alert('Agrega al menos un producto.'); return false; }
    let ok = false;
    filas.forEach(r => {
        if (r.querySelector('.item-producto')?.value && parseFloat(r.querySelector('.item-cantidad')?.value||0)>0) ok = true;
    });
    if (!ok) { alert('Selecciona un producto con cantidad mayor a 0.'); return false; }
    return true;
}

function cerrarModal(id) {
    document.getElementById(id)?.classList.remove('active');
}

// ── Detalle fetch ─────────────────────────────────────────────────────────────
function verDetalle(entradaId) {
    document.getElementById('det-folio').textContent = '';
    document.getElementById('det-cabecera').innerHTML = '';
    document.getElementById('det-tbody').innerHTML = '';
    document.getElementById('det-total').textContent = '';
    document.getElementById('det-loading').style.display = '';
    document.getElementById('det-contenido').style.display = 'none';
    document.getElementById('modal-detalle').classList.add('active');

    fetch('api/compra_detalle.php?id=' + encodeURIComponent(entradaId))
        .then(r => r.json())
        .then(data => {
            document.getElementById('det-loading').style.display = 'none';
            document.getElementById('det-contenido').style.display = '';
            if (!data || data.error) {
                document.getElementById('det-tbody').innerHTML = '<tr><td colspan="5" style="text-align:center;color:#f87171;padding:1.5rem;">Error al cargar detalle.</td></tr>';
                return;
            }
            document.getElementById('det-folio').textContent = data.folio || '—';
            const fecha = data.fecha ? data.fecha.slice(0,16).replace('T',' ') : '—';
            document.getElementById('det-cabecera').innerHTML = `
                <div><div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);margin-bottom:0.2rem;">Proveedor</div><strong>${escHtml(data.proveedor||'—')}</strong></div>
                <div><div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);margin-bottom:0.2rem;">Fecha</div><strong>${escHtml(fecha)}</strong></div>
                <div><div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);margin-bottom:0.2rem;">Registró</div><strong>${escHtml(data.usuario_nombre||'—')}</strong></div>
                <div><div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);margin-bottom:0.2rem;">Total</div><strong style="color:#4ade80;">$${parseFloat(data.total||0).toFixed(2)}</strong></div>
            `;
            const tbody = document.getElementById('det-tbody');
            (data.items||[]).forEach(item => {
                const sub = (parseFloat(item.cantidad)*parseFloat(item.costo_unitario)).toFixed(2);
                tbody.insertAdjacentHTML('beforeend', `
                    <tr>
                        <td>${escHtml(item.producto_descripcion||'—')}</td>
                        <td style="color:var(--text-muted);font-size:0.79rem;">${escHtml(item.codigo_interno_sku||'—')}</td>
                        <td style="text-align:right;">${parseFloat(item.cantidad).toFixed(2)} ${escHtml(item.unidad_medida||'')}</td>
                        <td style="text-align:right;">$${parseFloat(item.costo_unitario).toFixed(2)}</td>
                        <td style="text-align:right;font-weight:600;color:#4ade80;">$${sub}</td>
                    </tr>`);
            });
            document.getElementById('det-total').textContent = '$' + parseFloat(data.total||0).toFixed(2);
        })
        .catch(() => {
            document.getElementById('det-loading').style.display = 'none';
            document.getElementById('det-contenido').style.display = '';
            document.getElementById('det-tbody').innerHTML = '<tr><td colspan="5" style="text-align:center;color:#f87171;padding:1.5rem;">Error de conexión.</td></tr>';
        });
}

// ── Búsqueda ─────────────────────────────────────────────────────────────────
function filtrarCompras(term) {
    const q = (term||'').trim().toLowerCase();
    document.getElementById('btn-limpiar-compras').style.display = q ? 'block' : 'none';
    document.querySelectorAll('.fila-compra').forEach(f => {
        f.dataset.filtrado = (!q || (f.dataset.folio||'').includes(q) || (f.dataset.proveedor||'').includes(q)) ? '1' : '0';
    });
    renderPagina(1);
}
function limpiarBusqueda() {
    const inp = document.getElementById('input-busqueda-compras');
    if (inp) { inp.value = ''; inp.focus(); filtrarCompras(''); }
}

// ── Paginador ─────────────────────────────────────────────────────────────────
let pagActual = 1, porPagina = 10;

function getFiltradas() {
    return Array.from(document.querySelectorAll('.fila-compra')).filter(f => f.dataset.filtrado !== '0');
}

function renderPagina(pag) {
    const filas = getFiltradas();
    const total = filas.length;
    const totalPags = Math.max(1, Math.ceil(total / porPagina));
    pagActual = Math.min(Math.max(1, pag), totalPags);
    const inicio = (pagActual-1)*porPagina, fin = inicio+porPagina;

    document.querySelectorAll('.fila-compra').forEach(f => f.style.display='none');
    filas.forEach((f,i) => f.style.display = (i>=inicio && i<fin) ? '' : 'none');

    const sinRes = document.getElementById('compras-sin-resultados');
    if (sinRes) sinRes.style.display = total===0 ? '' : 'none';

    const infoEl = document.getElementById('pag-compras-info');
    if (infoEl) infoEl.textContent = total===0 ? 'Sin resultados'
        : `Mostrando ${inicio+1}–${Math.min(fin,total)} de ${total} registro${total!==1?'s':''}`;

    const botsEl = document.getElementById('pag-compras-botones');
    if (!botsEl) return;
    botsEl.innerHTML = '';

    const mkBtn = (lbl, p, active, disabled) => {
        const b = document.createElement('button');
        b.innerHTML = lbl; b.className = 'pag-btn' + (active?' active':'');
        b.disabled = disabled||active; if(disabled) b.style.opacity='0.35';
        b.onclick = () => renderPagina(p);
        return b;
    };

    botsEl.appendChild(mkBtn('‹', pagActual-1, false, pagActual===1));
    let pS = Math.max(1, pagActual-2), pE = Math.min(totalPags, pagActual+2);
    if (pS>1) { botsEl.appendChild(mkBtn('1',1,false,false)); if(pS>2){const d=document.createElement('span');d.textContent='…';d.style.cssText='padding:0 0.3rem;color:var(--text-muted);font-size:0.8rem;';botsEl.appendChild(d);} }
    for(let p=pS;p<=pE;p++) botsEl.appendChild(mkBtn(p,p,p===pagActual,false));
    if(pE<totalPags){if(pE<totalPags-1){const d=document.createElement('span');d.textContent='…';d.style.cssText='padding:0 0.3rem;color:var(--text-muted);font-size:0.8rem;';botsEl.appendChild(d);}botsEl.appendChild(mkBtn(totalPags,totalPags,false,false));}
    botsEl.appendChild(mkBtn('›', pagActual+1, false, pagActual===totalPags));
}

function cambiarPorPagina(val) { porPagina = parseInt(val,10)||10; renderPagina(1); }

function toggleSubmenu(id) { document.getElementById(id)?.classList.toggle('open'); }

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.fila-compra').forEach(f => { if(!f.dataset.filtrado) f.dataset.filtrado='1'; });
    renderPagina(1);
});
</script>
<script src="js/sidebar.js"></script>
</body>
</html>
