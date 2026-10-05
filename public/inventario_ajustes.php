<?php
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../src/modules/auth/AuthService.php';
require_once __DIR__ . '/../src/modules/catalog/Producto.php';
require_once __DIR__ . '/../src/modules/inventory/MovimientoInventario.php';
require_once __DIR__ . '/../src/modules/inventory/AjusteInventario.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;
use App\Modules\Catalog\Producto;
use App\Modules\Inventory\AjusteInventario;
use App\Modules\Inventory\MovimientoInventario;

$db            = (new Database())->getConnection();
$auth          = new AuthService($db);
$productoModel = new Producto($db);

// Middleware de autenticación
if (!$auth->checkAuth()) { header("Location: login.php"); exit; }

// Regla 6: solo Administrador puede acceder
if (!$auth->isAdmin()) {
    header("Location: dashboard.php");
    exit;
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$mensaje = "";
$error   = "";

// ── Procesar ajuste ──────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['action'] ?? '') === 'realizar_ajuste') {
    $ajusteModel = new AjusteInventario($db);
    $resultado   = $ajusteModel->realizar(
        $_SESSION['rol'] ?? '',
        [
            'producto_id' => trim($_POST['producto_id'] ?? ''),
            'tipo'        => trim($_POST['tipo'] ?? 'AJUSTE'),
            'subtipo'     => trim($_POST['subtipo'] ?? 'SALIDA'),
            'cantidad'    => floatval($_POST['cantidad'] ?? 0),
            'motivo'      => trim($_POST['motivo'] ?? ''),
            'usuario_id'  => $_SESSION['usuario_id'] ?? null,
        ]
    );
    if ($resultado['ok']) {
        $mensaje = $resultado['mensaje'] ?? 'Ajuste registrado.';
    } else {
        $error = $resultado['error'] ?? 'Error al realizar ajuste.';
    }
}

// ── Datos para la vista ─────────────────────────────────────────────────────
$productosNormales = $productoModel->obtenerProductosNormales();
$movModel          = new MovimientoInventario($db);
$movimientos       = $movModel->listarTodos(200, '');   // todos los tipos
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajustes de Inventario | Lavandería</title>
    <meta name="description" content="Ajustes manuales de inventario restringidos al Administrador — Regla 6.">
    <link rel="stylesheet" href="css/index.css">
    <style>
        .table { width:100%; border-collapse:collapse; color:var(--text-main); }
        .table th { padding:0.75rem 1rem; text-align:left; font-size:0.78rem; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted); border-bottom:2px solid var(--border); }
        .table td { padding:0.85rem 1rem; border-bottom:1px solid var(--border); vertical-align:middle; font-size:0.87rem; }
        .table tbody tr:hover td { background:rgba(255,255,255,0.025); }

        .badge { padding:0.22rem 0.7rem; border-radius:99px; font-size:0.72rem; font-weight:600; }
        .badge-ajuste { background:rgba(59,130,246,0.15); color:#93c5fd; border:1px solid rgba(59,130,246,0.3); }
        .badge-merma  { background:rgba(239,68,68,0.15); color:#fca5a5; border:1px solid rgba(239,68,68,0.3); }
        .badge-compra { background:rgba(34,197,94,0.15); color:#86efac; border:1px solid rgba(34,197,94,0.3); }
        .badge-venta  { background:rgba(168,85,247,0.15); color:#d8b4fe; border:1px solid rgba(168,85,247,0.3); }
        .badge-kit    { background:rgba(245,158,11,0.15); color:#fcd34d; border:1px solid rgba(245,158,11,0.3); }

        .modal-overlay { position:fixed; inset:0; background:rgba(15,23,42,0.8); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); display:none; align-items:center; justify-content:center; z-index:9999; }
        .modal-overlay.active { display:flex; animation:fadeIn 0.2s ease; }
        .modal-box { background:#1e293b; border:1px solid var(--border); border-radius:20px; padding:2rem; max-width:520px; width:95%; box-shadow:0 25px 60px -12px rgba(0,0,0,0.7); animation:slideUp 0.3s cubic-bezier(0.16,1,0.3,1); }
        @keyframes fadeIn  { from{opacity:0}to{opacity:1} }
        @keyframes slideUp { from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)} }

        .tipo-btn { flex:1; padding:0.65rem; border-radius:10px; border:1px solid var(--border); background:rgba(255,255,255,0.04); color:var(--text-muted); cursor:pointer; font-size:0.85rem; font-weight:500; transition:all 0.2s; text-align:center; }
        .tipo-btn:hover { border-color:rgba(59,130,246,0.5); color:var(--text-main); }
        .tipo-btn.selected-ajuste { border-color:#60a5fa; background:rgba(59,130,246,0.15); color:#60a5fa; font-weight:600; }
        .tipo-btn.selected-merma  { border-color:#f87171; background:rgba(239,68,68,0.15); color:#f87171; font-weight:600; }

        .subtipo-btn { flex:1; padding:0.5rem; border-radius:8px; border:1px solid var(--border); background:rgba(255,255,255,0.04); color:var(--text-muted); cursor:pointer; font-size:0.82rem; transition:all 0.2s; text-align:center; }
        .subtipo-btn.selected { border-color:#34d399; background:rgba(34,197,94,0.12); color:#34d399; font-weight:600; }

        .warning-admin {
            display:flex; align-items:center; gap:0.75rem;
            background:rgba(245,158,11,0.08); border:1px solid rgba(245,158,11,0.25);
            border-radius:12px; padding:0.9rem 1.1rem; margin-bottom:1.75rem;
            font-size:0.84rem; color:#fcd34d;
        }

        .stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:1rem; margin-bottom:2rem; }
        .stat-card { background:var(--bg-card); border:1px solid var(--border); border-radius:16px; padding:1.1rem 1.4rem; }
        .stat-label { font-size:0.74rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); }
        .stat-value { font-size:1.5rem; font-weight:700; display:block; margin:0.2rem 0; }
        .stat-sub   { font-size:0.76rem; color:var(--text-muted); }

        .pag-btn { cursor:pointer; border:1px solid var(--border); border-radius:7px; padding:0.3rem 0.65rem; font-size:0.8rem; font-weight:600; transition:all 0.18s; background:rgba(255,255,255,0.05); color:var(--text-muted); min-width:2.1rem; text-align:center; }
        .pag-btn.active { background:var(--primary); color:#fff; }

        /* Filtro tipo */
        .filter-chip { padding:0.3rem 0.85rem; border-radius:99px; border:1px solid var(--border); background:transparent; color:var(--text-muted); cursor:pointer; font-size:0.78rem; font-weight:500; transition:all 0.18s; }
        .filter-chip.active { background:rgba(59,130,246,0.2); border-color:rgba(59,130,246,0.5); color:#93c5fd; }
    </style>
</head>
<body>
<div class="app-container">
    <?php $activePage = 'inventario_ajustes'; require_once __DIR__ . '/partials/sidebar.php'; ?>

    <main class="main-content">
        <header style="margin-bottom:2rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
            <div>
                <h1 style="font-size:2rem;">Ajustes de Inventario</h1>
                <p style="color:var(--text-muted);">Correcciones, mermas y ajustes manuales — Regla 6, solo Administrador.</p>
            </div>
            <button type="button" class="btn-primary" style="width:auto;padding:0.6rem 1.4rem;font-size:0.9rem;" onclick="abrirModal()">
                ⚙️ Nuevo Ajuste
            </button>
        </header>

        <!-- Aviso admin -->
        <div class="warning-admin">
            <span style="font-size:1.3rem;">🔒</span>
            <span>Esta sección está <strong>restringida al Administrador</strong>. Los cajeros no pueden realizar ajustes manuales ni ver este módulo.</span>
        </div>

        <?php if ($mensaje): ?>
        <div style="background:rgba(16,185,129,0.1);color:#34d399;border:1px solid rgba(16,185,129,0.2);padding:1rem 1.25rem;border-radius:12px;margin-bottom:1.5rem;">
            <?= htmlspecialchars($mensaje) ?>
        </div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert-error" style="margin-bottom:1.5rem;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <?php
        $cntTotal  = count($movimientos);
        $cntAjuste = count(array_filter($movimientos, fn($m) => $m['tipo_movimiento'] === 'AJUSTE'));
        $cntMerma  = count(array_filter($movimientos, fn($m) => $m['tipo_movimiento'] === 'MERMA'));
        $cntCompra = count(array_filter($movimientos, fn($m) => $m['tipo_movimiento'] === 'COMPRA'));
        ?>
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-label">Movimientos</span>
                <span class="stat-value" style="color:#60a5fa;"><?= $cntTotal ?></span>
                <span class="stat-sub">en total</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Ajustes</span>
                <span class="stat-value" style="color:#93c5fd;"><?= $cntAjuste ?></span>
                <span class="stat-sub">manuales</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Mermas</span>
                <span class="stat-value" style="color:#f87171;"><?= $cntMerma ?></span>
                <span class="stat-sub">registradas</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Compras</span>
                <span class="stat-value" style="color:#86efac;"><?= $cntCompra ?></span>
                <span class="stat-sub">entradas</span>
            </div>
        </div>

        <!-- Historial de movimientos -->
        <div class="auth-card" style="width:100%;max-width:100%;padding:2rem;overflow-x:auto;">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;margin-bottom:1.25rem;">
                <h3 style="margin:0;">Historial de Movimientos</h3>
                <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                    <!-- Filtros por tipo -->
                    <button class="filter-chip active" id="chip-todos" onclick="filtrarTipo('', this)">Todos</button>
                    <button class="filter-chip" id="chip-AJUSTE"  onclick="filtrarTipo('AJUSTE',  this)">Ajuste</button>
                    <button class="filter-chip" id="chip-MERMA"   onclick="filtrarTipo('MERMA',   this)">Merma</button>
                    <button class="filter-chip" id="chip-COMPRA"  onclick="filtrarTipo('COMPRA',  this)">Compra</button>
                    <button class="filter-chip" id="chip-VENTA"   onclick="filtrarTipo('VENTA',   this)">Venta</button>
                    <!-- Buscador -->
                    <div style="display:flex;align-items:center;gap:0.5rem;background:rgba(255,255,255,0.05);border:1px solid var(--border);border-radius:10px;padding:0.4rem 0.8rem;min-width:210px;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted);flex-shrink:0;"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input id="input-busqueda-mov" type="text" placeholder="Buscar producto…"
                            oninput="filtrarMov(this.value)"
                            style="background:transparent;border:none;outline:none;color:var(--text-main);font-size:0.83rem;width:100%;min-width:0;" autocomplete="off">
                    </div>
                </div>
            </div>

            <table class="table" id="tabla-mov">
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>Tipo</th>
                        <th style="text-align:right;">Cantidad</th>
                        <th style="text-align:right;">Stock Antes</th>
                        <th style="text-align:right;">Stock Después</th>
                        <th>Motivo</th>
                        <th>Usuario</th>
                        <th>Fecha</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($movimientos)): ?>
                    <tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:2.5rem;">
                        Sin movimientos registrados.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($movimientos as $m): ?>
                    <?php
                        $tipo = $m['tipo_movimiento'];
                        $esDescuento = in_array($tipo, ['MERMA','VENTA','KIT_CONSUMO']);
                        $cant = floatval($m['cantidad']);
                        $signo = $esDescuento ? '-' : '+';
                        $colorCant = $esDescuento ? '#f87171' : '#4ade80';
                        $badgeClass = match($tipo) {
                            'AJUSTE'     => 'badge-ajuste',
                            'MERMA'      => 'badge-merma',
                            'COMPRA'     => 'badge-compra',
                            'VENTA'      => 'badge-venta',
                            'KIT_CONSUMO'=> 'badge-kit',
                            default      => 'badge-ajuste',
                        };
                    ?>
                    <tr class="fila-mov"
                        data-tipo="<?= htmlspecialchars($tipo) ?>"
                        data-producto="<?= strtolower(htmlspecialchars($m['producto_descripcion'] ?? '')) ?>"
                        data-filtrado="1">
                        <td>
                            <div style="font-weight:500;"><?= htmlspecialchars($m['producto_descripcion'] ?? '—') ?></div>
                            <div style="font-size:0.76rem;color:var(--text-muted);"><?= htmlspecialchars($m['codigo_interno_sku'] ?? '') ?></div>
                        </td>
                        <td><span class="badge <?= $badgeClass ?>"><?= $tipo ?></span></td>
                        <td style="text-align:right;font-weight:700;color:<?= $colorCant ?>;">
                            <?= $signo . number_format($cant, 2) ?>
                        </td>
                        <td style="text-align:right;color:var(--text-muted);"><?= number_format($m['stock_antes'], 2) ?></td>
                        <td style="text-align:right;font-weight:600;"><?= number_format($m['stock_despues'], 2) ?></td>
                        <td style="color:var(--text-muted);font-size:0.82rem;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($m['motivo'] ?? '') ?>">
                            <?= htmlspecialchars($m['motivo'] ?? '—') ?>
                        </td>
                        <td style="color:var(--text-muted);"><?= htmlspecialchars($m['usuario_nombre'] ?? '—') ?></td>
                        <td style="color:var(--text-muted);font-size:0.79rem;white-space:nowrap;"><?= date('d/m/Y H:i', strtotime($m['fecha'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <div id="mov-sin-resultados" style="display:none;text-align:center;color:var(--text-muted);padding:2.5rem;">
                🔍 No se encontraron movimientos.
            </div>

            <!-- Paginador -->
            <div id="paginador-mov" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;margin-top:1.25rem;padding:0.85rem 1.1rem;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:12px;">
                <div id="pag-mov-info" style="font-size:0.82rem;color:var(--text-muted);"></div>
                <div id="pag-mov-botones" style="display:flex;gap:0.35rem;flex-wrap:wrap;align-items:center;"></div>
                <div style="display:flex;align-items:center;gap:0.5rem;font-size:0.82rem;color:var(--text-muted);">
                    <span>Mostrar</span>
                    <select id="sel-mov-pagina" onchange="cambiarMovPorPagina(this.value)"
                        style="background:var(--sidebar-bg);color:var(--text-main);border:1px solid var(--border);border-radius:7px;padding:0.3rem 0.5rem;font-size:0.82rem;cursor:pointer;outline:none;">
                        <option value="15" selected style="background:#1e293b;color:#f1f5f9;">15</option>
                        <option value="25" style="background:#1e293b;color:#f1f5f9;">25</option>
                        <option value="50" style="background:#1e293b;color:#f1f5f9;">50</option>
                    </select>
                    <span>por página</span>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- ══ Modal: Ajuste Manual ════════════════════════════════════════════════ -->
<div id="modal-ajuste" class="modal-overlay" onclick="if(event.target===this)cerrarModal()">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;">
            <div id="modal-icon" style="width:44px;height:44px;border-radius:12px;background:rgba(59,130,246,0.15);display:flex;align-items:center;justify-content:center;font-size:1.3rem;">⚙️</div>
            <div>
                <h3 style="font-size:1.2rem;color:#fff;margin-bottom:0.1rem;">Ajuste Manual de Inventario</h3>
                <p style="color:var(--text-muted);font-size:0.83rem;">Solo Administrador — Regla 6.</p>
            </div>
        </div>

        <form method="POST" id="form-ajuste" onsubmit="return validarAjuste()">
            <input type="hidden" name="action" value="realizar_ajuste">
            <input type="hidden" name="tipo"   id="ajuste-tipo"   value="AJUSTE">
            <input type="hidden" name="subtipo" id="ajuste-subtipo" value="SALIDA">

            <!-- Selector Tipo -->
            <div style="margin-bottom:1.15rem;">
                <label class="form-label" style="margin-bottom:0.5rem;display:block;">Tipo de Ajuste *</label>
                <div style="display:flex;gap:0.65rem;">
                    <button type="button" id="btn-tipo-ajuste" class="tipo-btn selected-ajuste" onclick="selTipo('AJUSTE')">
                        🔧 Ajuste (entrada / salida / corrección)
                    </button>
                    <button type="button" id="btn-tipo-merma"  class="tipo-btn" onclick="selTipo('MERMA')">
                        🗑️ Merma (pérdida / daño)
                    </button>
                </div>
            </div>

            <!-- Subtipo (solo AJUSTE) -->
            <div id="container-subtipo" style="margin-bottom:1.15rem;">
                <label class="form-label" style="margin-bottom:0.5rem;display:block;">Subtipo *</label>
                <div style="display:flex;gap:0.65rem;">
                    <button type="button" id="btn-sub-entrada" class="subtipo-btn" onclick="selSubtipo('ENTRADA')">
                        ⬆️ Entrada / Corrección (+)
                    </button>
                    <button type="button" id="btn-sub-salida" class="subtipo-btn selected" onclick="selSubtipo('SALIDA')">
                        ⬇️ Salida / Descuento (−)
                    </button>
                </div>
            </div>

            <!-- Producto -->
            <div class="form-group" style="margin-bottom:1.15rem;">
                <label class="form-label">Producto *</label>
                <div class="select-wrapper">
                    <select name="producto_id" id="ajuste-producto" class="form-control" required>
                        <option value="">— Seleccionar producto —</option>
                        <?php foreach ($productosNormales as $p): ?>
                        <option value="<?= htmlspecialchars($p['id']) ?>"
                                data-stock="<?= htmlspecialchars($p['costo'] ?? 0) ?>">
                            <?= htmlspecialchars($p['descripcion']) ?> (<?= htmlspecialchars($p['unidad_medida']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="select-arrow-btn">▼</div>
                </div>
            </div>

            <!-- Cantidad -->
            <div class="form-group" style="margin-bottom:1.15rem;">
                <label class="form-label" id="label-cantidad-ajuste">Cantidad a Descontar *</label>
                <input type="number" step="0.01" min="0.01" name="cantidad" id="ajuste-cantidad" class="form-control"
                       required placeholder="0.00">
            </div>

            <!-- Motivo -->
            <div class="form-group" style="margin-bottom:1.5rem;">
                <label class="form-label">Motivo / Descripción</label>
                <input type="text" name="motivo" id="ajuste-motivo" class="form-control"
                       placeholder="Ej: Conteo físico / Producto dañado / Corrección de error">
            </div>

            <div style="display:flex;justify-content:flex-end;gap:0.75rem;">
                <button type="button" onclick="cerrarModal()"
                        style="background:rgba(255,255,255,0.06);color:var(--text-main);padding:0.65rem 1.25rem;border-radius:8px;border:1px solid var(--border);cursor:pointer;">
                    Cancelar
                </button>
                <button type="submit" id="btn-guardar-ajuste" class="btn-primary"
                        style="padding:0.65rem 1.5rem;width:auto;font-size:0.88rem;border-radius:8px;background:rgba(59,130,246,0.8);">
                    ✔ Guardar Ajuste
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Tipo / Subtipo toggles ────────────────────────────────────────────────────
function selTipo(tipo) {
    document.getElementById('ajuste-tipo').value = tipo;
    const btnAj = document.getElementById('btn-tipo-ajuste');
    const btnMr = document.getElementById('btn-tipo-merma');
    const ctSub = document.getElementById('container-subtipo');
    const icon  = document.getElementById('modal-icon');
    const btnG  = document.getElementById('btn-guardar-ajuste');
    const labelCant = document.getElementById('label-cantidad-ajuste');

    if (tipo === 'MERMA') {
        btnAj.classList.remove('selected-ajuste');
        btnMr.classList.add('selected-merma');
        ctSub.style.display = 'none';
        icon.innerHTML = '🗑️';
        icon.style.background = 'rgba(239,68,68,0.15)';
        btnG.style.background = 'rgba(239,68,68,0.8)';
        labelCant.textContent = 'Cantidad de Merma *';
        // MERMA siempre es descuento
        document.getElementById('ajuste-subtipo').value = 'SALIDA';
    } else {
        btnMr.classList.remove('selected-merma');
        btnAj.classList.add('selected-ajuste');
        ctSub.style.display = '';
        icon.innerHTML = '⚙️';
        icon.style.background = 'rgba(59,130,246,0.15)';
        btnG.style.background = 'rgba(59,130,246,0.8)';
        const sub = document.getElementById('ajuste-subtipo').value || 'SALIDA';
        labelCant.textContent = sub === 'ENTRADA' ? 'Cantidad a Agregar *' : 'Cantidad a Descontar *';
    }
}

function selSubtipo(subtipo) {
    document.getElementById('ajuste-subtipo').value = subtipo;
    document.getElementById('btn-sub-entrada').classList.toggle('selected', subtipo === 'ENTRADA');
    document.getElementById('btn-sub-salida').classList.toggle('selected',  subtipo === 'SALIDA');
    document.getElementById('label-cantidad-ajuste').textContent = subtipo === 'ENTRADA' ? 'Cantidad a Agregar *' : 'Cantidad a Descontar *';
}

function abrirModal() {
    // Reset
    selTipo('AJUSTE');
    selSubtipo('SALIDA');
    document.getElementById('ajuste-producto').value = '';
    document.getElementById('ajuste-cantidad').value = '';
    document.getElementById('ajuste-motivo').value   = '';
    document.getElementById('modal-ajuste').classList.add('active');
    setTimeout(() => document.getElementById('ajuste-producto').focus(), 100);
}

function cerrarModal() {
    document.getElementById('modal-ajuste').classList.remove('active');
}

function validarAjuste() {
    const prod = document.getElementById('ajuste-producto').value;
    const cant = parseFloat(document.getElementById('ajuste-cantidad').value || 0);
    if (!prod) { alert('Selecciona un producto.'); return false; }
    if (cant <= 0) { alert('La cantidad debe ser mayor a 0.'); return false; }
    return true;
}

// ── Filtros tipo + búsqueda ───────────────────────────────────────────────────
let tipoActivo = '', terminoActivo = '';

function filtrarTipo(tipo, btn) {
    tipoActivo = tipo;
    document.querySelectorAll('.filter-chip').forEach(c => c.classList.remove('active'));
    if (btn) btn.classList.add('active');
    aplicarFiltros();
}

function filtrarMov(termino) {
    terminoActivo = (termino || '').trim().toLowerCase();
    aplicarFiltros();
}

function aplicarFiltros() {
    document.querySelectorAll('.fila-mov').forEach(f => {
        const tipo    = f.dataset.tipo || '';
        const prod    = f.dataset.producto || '';
        const matchT  = !tipoActivo || tipo === tipoActivo;
        const matchP  = !terminoActivo || prod.includes(terminoActivo);
        f.dataset.filtrado = (matchT && matchP) ? '1' : '0';
    });
    renderPagina(1);
}

// ── Paginador ─────────────────────────────────────────────────────────────────
let pagActual = 1, porPagina = 15;

function getFiltradas() {
    return Array.from(document.querySelectorAll('.fila-mov')).filter(f => f.dataset.filtrado !== '0');
}

function renderPagina(pag) {
    const filas = getFiltradas();
    const total = filas.length;
    const totalPags = Math.max(1, Math.ceil(total / porPagina));
    pagActual = Math.min(Math.max(1, pag), totalPags);
    const inicio = (pagActual-1)*porPagina, fin = inicio+porPagina;

    document.querySelectorAll('.fila-mov').forEach(f => f.style.display='none');
    filas.forEach((f,i) => f.style.display = (i>=inicio && i<fin) ? '' : 'none');

    const sinRes = document.getElementById('mov-sin-resultados');
    if (sinRes) sinRes.style.display = total===0 ? '' : 'none';

    const infoEl = document.getElementById('pag-mov-info');
    if (infoEl) infoEl.textContent = total===0 ? 'Sin resultados'
        : `Mostrando ${inicio+1}–${Math.min(fin,total)} de ${total} registro${total!==1?'s':''}`;

    const botsEl = document.getElementById('pag-mov-botones');
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

function cambiarMovPorPagina(val) { porPagina = parseInt(val,10)||15; renderPagina(1); }

function toggleSubmenu(id) { document.getElementById(id)?.classList.toggle('open'); }

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.fila-mov').forEach(f => { if(!f.dataset.filtrado) f.dataset.filtrado='1'; });
    renderPagina(1);
});
</script>
<script src="js/sidebar.js"></script>
</body>
</html>
