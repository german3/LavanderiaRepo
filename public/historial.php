<?php
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../src/modules/auth/AuthService.php';
require_once __DIR__ . '/../src/modules/catalog/Historial.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;
use App\Modules\Auth\Usuario;
use App\Modules\Catalog\Historial;

$db = (new Database())->getConnection();
$auth = new AuthService($db);
$historialModel = new Historial($db);
$usuarioModel = new Usuario($db);

// Middleware
if (!$auth->checkAuth()) {
    header("Location: login.php");
    exit;
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$movimientos = $historialModel->obtenerTodos();
$movimientosJson = json_encode($movimientos);

// Obtener lista de usuarios dados de alta
$usuarios = $usuarioModel->obtenerTodos();
usort($usuarios, function($a, $b) {
    return strcasecmp($a['nombre'] ?? '', $b['nombre'] ?? '');
});

// Filtro por producto vía GET
$filtroSku = trim($_GET['sku'] ?? '');
$filtroSkuJs = json_encode($filtroSku);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Movimientos | Lavandería</title>
    <link rel="stylesheet" href="css/index.css">
    <style>
        .table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; color: var(--text-main); }
        .table th { padding: 0.75rem 1rem; text-align: left; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); border-bottom: 2px solid var(--border); user-select: none; }
        .table td { padding: 1rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        .table tr:hover td { background: rgba(255,255,255,0.02); }
        
        .badge { padding: 0.35rem 0.75rem; border-radius: 99px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.35rem; text-transform: uppercase; }
        .badge-alta       { background: rgba(59, 130, 246, 0.2); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.3); }
        .badge-entrada    { background: rgba(16, 185, 129, 0.2); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-salida     { background: rgba(244, 63, 94, 0.2); color: #fda4af; border: 1px solid rgba(244, 63, 94, 0.3); }
        .badge-eliminacion{ background: rgba(245, 158, 11, 0.2); color: #fde68a; border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-edicion    { background: rgba(168, 85, 247, 0.2); color: #d8b4fe; border: 1px solid rgba(168, 85, 247, 0.3); }
        .badge-venta      { background: rgba(16, 185, 129, 0.25); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.45); }
        .badge-kit        { background: rgba(139, 92, 246, 0.25); color: #c4b5fd; border: 1px solid rgba(139, 92, 246, 0.4); }
        .badge-tipo-normal   { background: rgba(100, 116, 139, 0.2); color: #cbd5e1; border: 1px solid rgba(100, 116, 139, 0.35); }
        .badge-tipo-insumo   { background: rgba(234, 179, 8, 0.18); color: #fde68a; border: 1px solid rgba(234, 179, 8, 0.3); }
        .badge-tipo-servicio { background: rgba(14, 165, 233, 0.18); color: #7dd3fc; border: 1px solid rgba(14, 165, 233, 0.3); }
        .badge-tipo-kit      { background: rgba(139, 92, 246, 0.22); color: #c4b5fd; border: 1px solid rgba(139, 92, 246, 0.38); }

        .kit-inline-box {
            margin-top: 0.55rem;
            padding: 0.6rem 0.75rem;
            background: rgba(139, 92, 246, 0.08);
            border: 1px solid rgba(139, 92, 246, 0.3);
            border-radius: 8px;
            font-size: 0.78rem;
            max-width: 420px;
        }
        .kit-inline-header {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.72rem;
            color: #c4b5fd;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 700;
            margin-bottom: 0.4rem;
        }
        .kit-insumo-pill {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(139, 92, 246, 0.2);
            border-radius: 6px;
            padding: 0.25rem 0.5rem;
            margin-top: 0.25rem;
            font-size: 0.77rem;
            transition: all 0.15s;
        }
        .kit-insumo-pill:hover {
            border-color: rgba(139, 92, 246, 0.45);
            background: rgba(15, 23, 42, 0.85);
        }
        .kit-insumo-qty {
            font-weight: 700;
            color: #c4b5fd;
            background: rgba(139, 92, 246, 0.25);
            padding: 0.1rem 0.4rem;
            border-radius: 4px;
            font-size: 0.72rem;
            white-space: nowrap;
        }
        .kit-insumo-name {
            color: #f1f5f9;
            font-weight: 500;
        }
        .kit-insumo-sku {
            color: #94a3b8;
            font-size: 0.7rem;
            font-family: monospace;
            margin-left: auto;
        }

        .sort-header {
            cursor: pointer;
            transition: color 0.2s;
        }
        .sort-header:hover {
            color: #fff;
        }
        .sort-icon {
            display: inline-block;
            margin-left: 0.4rem;
            font-size: 0.75rem;
            opacity: 0.6;
        }
        .sort-header:hover .sort-icon {
            opacity: 1;
        }

        .sku-tag {
            font-family: monospace;
            background: rgba(255,255,255,0.06);
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
            font-size: 0.75rem;
            color: var(--text-muted);
            border: 1px solid rgba(255,255,255,0.1);
        }

        select.form-control option {
            background: #1e293b;
            color: #f1f5f9;
        }

        /* ── Modal de Detalles ── */
        .modal-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.65);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            animation: fadeInOverlay 0.2s ease;
        }
        .modal-overlay.active { display: flex; }
        @keyframes fadeInOverlay { from { opacity:0; } to { opacity:1; } }

        .modal-box {
            background: linear-gradient(145deg, #1e2d3d, #0f172a);
            border: 1px solid rgba(99,102,241,0.35);
            border-radius: 16px;
            width: 100%;
            max-width: 620px;
            max-height: 88vh;
            overflow-y: auto;
            box-shadow: 0 25px 60px rgba(0,0,0,0.6), 0 0 0 1px rgba(255,255,255,0.04);
            animation: slideUpModal 0.25s cubic-bezier(0.34,1.56,0.64,1);
        }
        @keyframes slideUpModal { from { transform: translateY(24px) scale(0.97); opacity:0; } to { transform: none; opacity:1; } }

        .modal-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 1.25rem 1.5rem 1rem;
            border-bottom: 1px solid rgba(255,255,255,0.07);
        }
        .modal-header h2 {
            font-size: 1.05rem; font-weight: 700; color: #fff; margin: 0;
            display: flex; align-items: center; gap: 0.6rem;
        }
        .modal-close {
            width: 32px; height: 32px;
            border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);
            background: rgba(255,255,255,0.05);
            color: #94a3b8; font-size: 1rem; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.15s;
        }
        .modal-close:hover { background: rgba(239,68,68,0.2); border-color: rgba(239,68,68,0.4); color: #fca5a5; }

        .modal-body { padding: 1.25rem 1.5rem 1.5rem; }

        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem 1rem;
        }
        .detail-item {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 10px;
            padding: 0.65rem 0.9rem;
        }
        .detail-item.full { grid-column: 1 / -1; }
        .detail-label {
            font-size: 0.7rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.07em; color: #64748b; margin-bottom: 0.3rem;
        }
        .detail-value {
            font-size: 0.9rem; color: #e2e8f0; font-weight: 500;
            word-break: break-word;
        }
        .detail-value.mono { font-family: monospace; color: #a5b4fc; }

        .modal-kit-section {
            margin-top: 1rem;
            padding: 0.85rem 1rem;
            background: rgba(139,92,246,0.07);
            border: 1px solid rgba(139,92,246,0.25);
            border-radius: 10px;
        }
        .modal-kit-title {
            font-size: 0.72rem; font-weight: 700; color: #c4b5fd;
            text-transform: uppercase; letter-spacing: 0.06em;
            margin-bottom: 0.6rem;
            display: flex; align-items: center; gap: 0.4rem;
        }
        .modal-kit-pill {
            display: flex; align-items: center; gap: 0.5rem;
            background: rgba(15,23,42,0.5);
            border: 1px solid rgba(139,92,246,0.2);
            border-radius: 7px;
            padding: 0.3rem 0.65rem;
            margin-top: 0.3rem;
            font-size: 0.82rem;
        }
        .btn-detalle {
            display: inline-flex; align-items: center; gap: 0.3rem;
            padding: 0.3rem 0.7rem;
            background: rgba(99,102,241,0.12);
            border: 1px solid rgba(99,102,241,0.3);
            color: #a5b4fc;
            border-radius: 7px;
            font-size: 0.75rem; font-weight: 600;
            cursor: pointer;
            transition: all 0.18s;
            white-space: nowrap;
        }
        .btn-detalle:hover {
            background: rgba(99,102,241,0.28);
            border-color: rgba(99,102,241,0.6);
            color: #fff;
            transform: translateY(-1px);
        }
        .btn-ticket {
            display: inline-flex; align-items: center; gap: 0.25rem;
            padding: 0.2rem 0.55rem;
            background: rgba(56,189,248,0.1);
            border: 1px solid rgba(56,189,248,0.3);
            color: #38bdf8;
            border-radius: 6px;
            font-size: 0.7rem; font-weight: 600;
            cursor: pointer;
            transition: all 0.18s;
            white-space: nowrap;
        }
        .btn-ticket:hover {
            background: rgba(56,189,248,0.25);
            border-color: rgba(56,189,248,0.6);
            color: #fff;
            transform: translateY(-1px);
        }

        /* ── Ticket Térmico ── */
        .hist-ticket-paper {
            background: #fff;
            color: #111;
            padding: 1.5rem;
            border-radius: 8px;
            font-family: 'JetBrains Mono', 'Courier New', monospace;
            font-size: 0.78rem;
            line-height: 1.4;
            max-width: 340px;
            margin: 0 auto;
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
        }
        .hist-ticket-center { text-align: center; }
        .hist-ticket-divider { border-top: 1px dashed #666; margin: 0.75rem 0; }
        .hist-ticket-table { width: 100%; border-collapse: collapse; }
        .hist-ticket-table th, .hist-ticket-table td { padding: 0.2rem 0; font-size: 0.75rem; }
        .hist-ticket-table th { border-bottom: 1px dashed #444; }

        @media print {
            .sidebar, .no-print, #banner-filtro-sku, #paginationControls, #pageSizeSelect, #btn-limpiar-fechas, .select-arrow-btn, .btn-detalle, .btn-ticket { display: none !important; }
            .main-content { margin-left: 0 !important; width: 100% !important; padding: 0 !important; background: #fff !important; color: #000 !important; }
            body { background: #fff !important; color: #000 !important; }
            .card { background: none !important; border: none !important; padding: 0 !important; box-shadow: none !important; }
            .table { color: #000 !important; border-collapse: collapse !important; width: 100% !important; margin-top: 1rem !important; }
            .table th, .table td { border: 1px solid #cbd5e1 !important; color: #000 !important; padding: 6px 8px !important; font-size: 9pt !important; }
            .badge { border: 1px solid #94a3b8 !important; color: #000 !important; background: #f1f5f9 !important; }
            .sku-tag { border: 1px solid #cbd5e1 !important; color: #334155 !important; background: #f8fafc !important; }
            .kit-inline-box { background: #f8fafc !important; border: 1px solid #cbd5e1 !important; color: #000 !important; }
            .kit-insumo-pill { background: #fff !important; border: 1px solid #e2e8f0 !important; color: #000 !important; }
            .kit-insumo-qty { background: #f1f5f9 !important; color: #000 !important; }
            .kit-insumo-name { color: #000 !important; }
            .kit-insumo-sku { color: #64748b !important; }
            h1 { color: #000 !important; font-size: 1.4rem !important; }
            p { color: #475569 !important; }
            input, select { border: none !important; background: none !important; color: #000 !important; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $activePage = 'historial'; require_once __DIR__ . '/partials/sidebar.php'; ?>

        <main class="main-content">
            <header style="margin-bottom: 2rem;">
                <div>
                    <h1 style="font-size: 1.8rem; color: #fff;">Historial de Movimientos</h1>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Registro global de altas, entradas, salidas y bajas de productos.</p>
                </div>
            </header>

            <?php if ($filtroSku): ?>
            <!-- Banner: filtro activo por producto -->
            <div id="banner-filtro-sku" style="
                display: flex; align-items: center; justify-content: space-between;
                gap: 1rem; flex-wrap: wrap;
                padding: 0.85rem 1.25rem;
                margin-bottom: 1.25rem;
                background: linear-gradient(135deg, rgba(99,102,241,0.15), rgba(139,92,246,0.1));
                border: 1px solid rgba(99,102,241,0.4);
                border-radius: 12px;
            ">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#a5b4fc" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                    </svg>
                    <div>
                        <span style="font-size: 0.78rem; color: #818cf8; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em;">Filtro activo</span><br>
                        <span style="color: #e2e8f0; font-weight: 600; font-size: 0.95rem;">
                            Historial del producto: <span style="color: #a5b4fc; font-family: monospace;"><?= htmlspecialchars($filtroSku) ?></span>
                        </span>
                    </div>
                </div>
                <a href="historial.php"
                   style="display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.45rem 1rem; background: rgba(255,255,255,0.06); border: 1px solid var(--border); color: var(--text-muted); border-radius: 8px; font-size: 0.82rem; font-weight: 600; text-decoration: none; transition: all 0.2s;"
                   onmouseover="this.style.color='#fff'; this.style.borderColor='rgba(255,255,255,0.3)';"
                   onmouseout="this.style.color=''; this.style.borderColor='';"
                >✕ Ver todos los movimientos</a>
            </div>
            <?php endif; ?>

            <div class="card" style="padding: 1.5rem; border-radius: 12px; background: rgba(30, 41, 59, 0.7); border: 1px solid var(--border);">
                
                <!-- Barra de Búsqueda y Filtros -->
                <div style="display: flex; gap: 1rem; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-bottom: 0.75rem;">
                    <div style="display: flex; gap: 0.75rem; flex: 1; min-width: 300px; flex-wrap: wrap; align-items: center;">
                        <div style="position: relative; flex: 1; min-width: 200px;">
                            <input type="text" id="searchInput" class="form-control" placeholder="Buscar por SKU, producto, movimiento o usuario..." style="width: 100%; padding: 0.4rem 0.75rem 0.4rem 2.2rem; background: rgba(15, 23, 42, 0.6); color: var(--text-main); border: 1px solid var(--border); border-radius: 8px; height: 38px; font-size: 0.85rem; line-height: 1.2; box-sizing: border-box;">
                            <span style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); opacity: 0.5;">🔍</span>
                        </div>

                        <!-- Botones de Acción: a la derecha del buscador y a la izquierda de 'Todos los movimientos' -->
                        <div style="display: flex; gap: 0.5rem; align-items: center;" class="no-print">
                            <button type="button" onclick="exportarExcel()" style="display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.4rem 0.85rem; height: 38px; background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #6ee7b7; border-radius: 8px; font-size: 0.82rem; font-weight: 600; cursor: pointer; transition: all 0.2s; white-space: nowrap;" onmouseover="this.style.background='rgba(16,185,129,0.3)'; this.style.color='#fff';" onmouseout="this.style.background='rgba(16,185,129,0.15)'; this.style.color='#6ee7b7';">
                                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                                Exportar a Excel
                            </button>
                            <button type="button" onclick="imprimirHistorial()" style="display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.4rem 0.85rem; height: 38px; background: rgba(99, 102, 241, 0.15); border: 1px solid rgba(99, 102, 241, 0.3); color: #a5b4fc; border-radius: 8px; font-size: 0.82rem; font-weight: 600; cursor: pointer; transition: all 0.2s; white-space: nowrap;" onmouseover="this.style.background='rgba(99,102,241,0.3)'; this.style.color='#fff';" onmouseout="this.style.background='rgba(99,102,241,0.15)'; this.style.color='#a5b4fc';">
                                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                Imprimir
                            </button>
                        </div>

                        <div class="select-wrapper" style="width: 210px;">
                            <select id="tipoFilter" class="form-control" style="width: 100%; padding: 0.4rem 2.2rem 0.4rem 0.75rem; height: 38px; background: #1e293b; color: #f1f5f9; border: 1px solid var(--border); border-radius: 8px; font-size: 0.85rem; line-height: 1.2; cursor: pointer; box-sizing: border-box;">
                                <option value="" style="background:#1e293b; color:#f1f5f9;">Todos los movimientos</option>
                                <option value="KIT" style="background:#1e293b; color:#c4b5fd; font-weight:600;">📦 Solo Kits / Paquetes</option>
                                <option value="VENTA POS" style="background:#1e293b; color:#f1f5f9;">Ventas POS / Cobro</option>
                                <option value="ALTA PRODUCTO" style="background:#1e293b; color:#f1f5f9;">Alta de Producto</option>
                                <option value="ENTRADA STOCK" style="background:#1e293b; color:#f1f5f9;">Entrada de Stock</option>
                                <option value="SALIDA STOCK" style="background:#1e293b; color:#f1f5f9;">Salida de Stock</option>
                                <option value="ELIMINACION PRODUCTO" style="background:#1e293b; color:#f1f5f9;">Eliminación</option>
                                <option value="EDICION PRODUCTO" style="background:#1e293b; color:#f1f5f9;">Edición</option>
                            </select>
                            <div class="select-arrow-btn">▼</div>
                        </div>

                        <!-- Filtro por Tipo de Producto -->
                        <div class="select-wrapper" style="width: 175px;">
                            <select id="tipoProductoFilter" class="form-control" style="width: 100%; padding: 0.4rem 2.2rem 0.4rem 0.75rem; height: 38px; background: #1e293b; color: #f1f5f9; border: 1px solid var(--border); border-radius: 8px; font-size: 0.85rem; line-height: 1.2; cursor: pointer; box-sizing: border-box;">
                                <option value="" style="background:#1e293b; color:#f1f5f9;">Tipo de producto</option>
                                <option value="NORMAL" style="background:#1e293b; color:#cbd5e1;">Normal</option>
                                <option value="INSUMO" style="background:#1e293b; color:#fde68a;">Insumo</option>
                                <option value="SERVICIO" style="background:#1e293b; color:#7dd3fc;">Servicio</option>
                                <option value="KIT" style="background:#1e293b; color:#c4b5fd;">Kit / Paquete</option>
                            </select>
                            <div class="select-arrow-btn">▼</div>
                        </div>
                    </div>

                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <span style="font-size: 0.85rem; color: var(--text-muted);">Mostrar:</span>
                        <div class="select-wrapper" style="width: 80px;">
                            <select id="pageSizeSelect" class="form-control" style="width: 100%; padding: 0.4rem 1.8rem 0.4rem 0.5rem; height: 38px; background: #1e293b; color: #f1f5f9; border: 1px solid var(--border); border-radius: 8px; font-size: 0.85rem; text-align: center; cursor: pointer; box-sizing: border-box; line-height: 1.2;">
                                <option value="10" selected style="background:#1e293b; color:#f1f5f9;">10</option>
                                <option value="15" style="background:#1e293b; color:#f1f5f9;">15</option>
                                <option value="20" style="background:#1e293b; color:#f1f5f9;">20</option>
                                <option value="25" style="background:#1e293b; color:#f1f5f9;">25</option>
                                <option value="30" style="background:#1e293b; color:#f1f5f9;">30</option>
                            </select>
                            <div class="select-arrow-btn">▼</div>
                        </div>
                    </div>
                </div>

                <!-- Filtro por Rango de Fechas -->
                <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1rem; padding: 0.75rem 1rem; background: rgba(15,23,42,0.4); border: 1px solid var(--border); border-radius: 10px;">
                    <!-- Icono calendario -->
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8" y1="2" x2="8" y2="6"/>
                        <line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    <span style="font-size: 0.82rem; color: var(--text-muted); font-weight: 600; white-space: nowrap;">Rango de fechas:</span>

                    <!-- Fecha Inicial -->
                    <div style="display: flex; align-items: center; gap: 0.45rem;">
                        <label for="fechaInicio" style="font-size: 0.8rem; color: var(--text-muted); white-space: nowrap;">Del:</label>
                        <input type="date" id="fechaInicio"
                               style="
                                   height: 36px; padding: 0.3rem 0.65rem;
                                   background: rgba(15,23,42,0.7);
                                   color: var(--text-main);
                                   border: 1px solid var(--border);
                                   border-radius: 8px;
                                   font-size: 0.83rem;
                                   cursor: pointer;
                                   outline: none;
                                   transition: border-color 0.2s;
                                   color-scheme: dark;
                               "
                               oninput="filterData()"
                               onfocus="this.style.borderColor='rgba(99,102,241,0.6)';"
                               onblur="this.style.borderColor='var(--border)';"
                        >
                    </div>

                    <!-- Separador -->
                    <span style="color: var(--text-muted); font-size: 0.85rem;">—</span>

                    <!-- Fecha Final -->
                    <div style="display: flex; align-items: center; gap: 0.45rem;">
                        <label for="fechaFin" style="font-size: 0.8rem; color: var(--text-muted); white-space: nowrap;">Al:</label>
                        <input type="date" id="fechaFin"
                               style="
                                   height: 36px; padding: 0.3rem 0.65rem;
                                   background: rgba(15,23,42,0.7);
                                   color: var(--text-main);
                                   border: 1px solid var(--border);
                                   border-radius: 8px;
                                   font-size: 0.83rem;
                                   cursor: pointer;
                                   outline: none;
                                   transition: border-color 0.2s;
                                   color-scheme: dark;
                               "
                               oninput="filterData()"
                               onfocus="this.style.borderColor='rgba(99,102,241,0.6)';"
                               onblur="this.style.borderColor='var(--border)';"
                        >
                    </div>

                    <!-- Botón limpiar fechas -->
                    <button type="button" id="btn-limpiar-fechas" onclick="limpiarFechas()"
                            style="
                                display: none;
                                align-items: center; gap: 0.35rem;
                                padding: 0.3rem 0.75rem; height: 36px;
                                background: rgba(239,68,68,0.1);
                                border: 1px solid rgba(239,68,68,0.3);
                                color: #fca5a5;
                                border-radius: 8px;
                                font-size: 0.78rem; font-weight: 600;
                                cursor: pointer;
                                white-space: nowrap;
                                transition: all 0.2s;
                            "
                            onmouseover="this.style.background='rgba(239,68,68,0.2)'; this.style.color='#fff';"
                            onmouseout="this.style.background='rgba(239,68,68,0.1)'; this.style.color='#fca5a5';"
                    >✕ Limpiar fechas</button>

                    <!-- Divisor visual -->
                    <div style="height: 24px; width: 1px; background: var(--border); margin: 0 0.25rem;" class="no-print"></div>

                    <!-- Filtro por Usuario (al lado derecho del rango de fechas) -->
                    <div style="display: flex; align-items: center; gap: 0.45rem;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                        <label for="usuarioFilter" style="font-size: 0.82rem; color: var(--text-muted); font-weight: 600; white-space: nowrap;">Usuario:</label>
                        <div class="select-wrapper" style="width: 210px;">
                            <select id="usuarioFilter" class="form-control" onchange="filterData()" style="width: 100%; padding: 0.35rem 2.2rem 0.35rem 0.75rem; height: 36px; background: #1e293b; color: #f1f5f9; border: 1px solid var(--border); border-radius: 8px; font-size: 0.83rem; line-height: 1.2; cursor: pointer; box-sizing: border-box;">
                                <option value="" style="background:#1e293b; color:#f1f5f9;">Todos los usuarios</option>
                                <?php foreach ($usuarios as $u): ?>
                                    <option value="<?= htmlspecialchars($u['nombre']) ?>" style="background:#1e293b; color:#f1f5f9;"><?= htmlspecialchars($u['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="select-arrow-btn">▼</div>
                        </div>
                    </div>

                    <!-- Indicador de registros en rango -->
                    <span id="fecha-rango-info" style="margin-left: auto; font-size: 0.78rem; color: var(--text-muted);"></span>
                </div>

                <!-- Tabla de Historial -->
                <div style="overflow-x: auto;">
                    <table class="table" id="historialTable">
                        <thead>
                            <tr>
                                <th class="sort-header" onclick="sortData('fecha_hora')">
                                    Fecha / Hora <span class="sort-icon" id="sort-fecha_hora">▼</span>
                                </th>
                                <th class="sort-header" onclick="sortData('tipo_movimiento')">
                                    Movimiento <span class="sort-icon" id="sort-tipo_movimiento">⇅</span>
                                </th>
                                <th class="sort-header" onclick="sortData('tipo_producto')">
                                    Tipo <span class="sort-icon" id="sort-tipo_producto">⇅</span>
                                </th>
                                <th class="sort-header" onclick="sortData('producto_descripcion')">
                                    Producto / SKU <span class="sort-icon" id="sort-producto_descripcion">⇅</span>
                                </th>
                                <th class="sort-header" onclick="sortData('cantidad')" style="text-align: right;">
                                    Cantidad <span class="sort-icon" id="sort-cantidad">⇅</span>
                                </th>
                                <th style="text-align: center;">Stock (Antes → Después)</th>
                                <th class="sort-header" onclick="sortData('costo_unitario')" style="text-align: right;">
                                    Costo Unit. <span class="sort-icon" id="sort-costo_unitario">⇅</span>
                                </th>
                                <th style="text-align: center;">Notas</th>
                                <th class="sort-header" onclick="sortData('usuario_nombre')">
                                    Usuario <span class="sort-icon" id="sort-usuario_nombre">⇅</span>
                                </th>
                                <th style="text-align: center;" class="no-print">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="historialTbody">
                            <!-- Inyectado dinámicamente con JS -->
                        </tbody>
                    </table>
                </div>

                <!-- Paginación -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem; flex-wrap: wrap; gap: 1rem;">
                    <div style="font-size: 0.85rem; color: var(--text-muted);" id="paginationInfo">
                        Mostrando 0 registros
                    </div>
                    <div style="display: flex; gap: 0.4rem;" id="paginationControls">
                        <!-- Botones de páginas -->
                    </div>
                </div>

            </div>
        </main>
    </div>

    <!-- ── Modal de Detalles del Movimiento ── -->
    <div id="detallesModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-header">
                <h2>
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#a5b4fc" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    Detalle del Movimiento
                </h2>
                <button class="modal-close" onclick="cerrarModal()" title="Cerrar">✕</button>
            </div>
            <div class="modal-body">
                <div id="modalContent"></div>
            </div>
        </div>
    </div>

    <!-- ── Modal de Ticket Térmico (Historial) ── -->
    <div id="ticketModal" class="modal-overlay">
        <div class="modal-box" style="max-width:440px;">
            <div class="modal-header">
                <h2>
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#38bdf8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Ticket de Venta
                </h2>
                <button class="modal-close" onclick="cerrarModalTicket()" title="Cerrar">✕</button>
            </div>
            <div class="modal-body" style="background:#1f2937; padding:1.25rem;">
                <div id="ticketContent"></div>
            </div>
            <div style="display:flex; gap:0.75rem; padding:1rem 1.5rem; border-top:1px solid rgba(255,255,255,0.07);">
                <button onclick="cerrarModalTicket()" style="flex:1; padding:0.55rem; background:rgba(255,255,255,0.06); border:1px solid var(--border); color:var(--text-muted); border-radius:8px; font-size:0.85rem; font-weight:600; cursor:pointer; transition:all 0.15s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color=''">Cerrar</button>
                <button onclick="imprimirTicketHistorial()" style="flex:1; padding:0.55rem; background:rgba(56,189,248,0.15); border:1px solid rgba(56,189,248,0.35); color:#38bdf8; border-radius:8px; font-size:0.85rem; font-weight:600; cursor:pointer; transition:all 0.15s; display:flex; align-items:center; justify-content:center; gap:0.4rem;" onmouseover="this.style.background='rgba(56,189,248,0.3)'; this.style.color='#fff';" onmouseout="this.style.background='rgba(56,189,248,0.15)'; this.style.color='#38bdf8';">🖨️ Imprimir Ticket</button>
            </div>
        </div>
    </div>

    <script>
        const rawData = <?php echo $movimientosJson; ?> || [];
        let filteredData = [...rawData];
        
        const filtroSkuInicial = <?= $filtroSkuJs ?>;
        
        let currentPage = 1;
        let pageSize = 10;
        let sortColumn = 'fecha_hora';
        let sortAsc = false; // Descendente por defecto (más recientes primero)

        function toggleSubmenu(id) {
            const el = document.getElementById(id);
            if (el) el.classList.toggle('open');
        }

        function getBadgeClass(tipo) {
            switch (tipo) {
                case 'VENTA POS':
                case 'KIT_CONSUMO':
                case 'CONSUMO KIT': return 'badge-venta';
                case 'ALTA PRODUCTO': return 'badge-alta';
                case 'ENTRADA STOCK': return 'badge-entrada';
                case 'SALIDA STOCK': return 'badge-salida';
                case 'ELIMINACION PRODUCTO': return 'badge-eliminacion';
                case 'EDICION PRODUCTO': return 'badge-edicion';
                default: return 'badge-alta';
            }
        }

        function getTipoBadge(tipo) {
            const t = (tipo || '').toUpperCase();
            switch (t) {
                case 'NORMAL':
                    return `<span class="badge badge-tipo-normal">Normal</span>`;
                case 'INSUMO':
                    return `<span class="badge badge-tipo-insumo">Insumo</span>`;
                case 'SERVICIO':
                    return `<span class="badge badge-tipo-servicio">Servicio</span>`;
                case 'KIT':
                case 'KIT_PAQUETE':
                    return `<span class="badge badge-tipo-kit">Kit / Paquete</span>`;
                default:
                    return `<span style="color: var(--text-muted); font-size: 0.8rem;">—</span>`;
            }
        }

        function renderKitDetalle(item, rowId) {
            let kitDetalle = null;
            try {
                kitDetalle = typeof item.kit_detalle === 'string' ? JSON.parse(item.kit_detalle) : item.kit_detalle;
            } catch(e) { kitDetalle = null; }

            if (!kitDetalle || !Array.isArray(kitDetalle) || kitDetalle.length === 0) return '';

            const isVenta = (item.tipo_movimiento === 'VENTA POS');
            const tituloSeccion = isVenta ? 'Insumos consumidos en el Kit' : 'Insumos que contiene el Kit';

            const pillsHtml = kitDetalle.map(ins => {
                const qty = parseFloat(ins.cantidad_total || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                const unidad = (ins.unidad || '').toUpperCase();
                const skuHtml = ins.insumo_sku ? `<span class="kit-insumo-sku">SKU: ${ins.insumo_sku}</span>` : '';
                return `
                    <div class="kit-insumo-pill" title="${ins.insumo_nombre}">
                        <span style="color:#a78bfa;font-weight:bold;font-size:0.75rem;">↳</span>
                        <span class="kit-insumo-qty">🧪 ${qty} ${unidad}</span>
                        <span class="kit-insumo-name">${ins.insumo_nombre}</span>
                        ${skuHtml}
                    </div>`;
            }).join('');

            return `
                <div class="kit-inline-box">
                    <div class="kit-inline-header">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/></svg>
                        <span>${tituloSeccion}</span>
                        <span style="opacity:0.75;font-size:0.7rem;font-weight:700;">(${kitDetalle.length})</span>
                    </div>
                    ${pillsHtml}
                </div>`;
        }

        function formatNotasFolio(motivo) {
            if (!motivo) return '-';
            const text = String(motivo).trim();

            // 1. Extraer Folio de textos tipo "Cobro Venta Folio: VTA-000003 - Cliente: ..." o "Folio: XXX"
            const match = text.match(/Folio:\s*([A-Za-z0-9\-_]+)/i) || 
                          text.match(/Folio\s*[:#]?\s*([A-Za-z0-9\-_]+)/i);
            if (match && match[1]) {
                return match[1];
            }

            // 2. Si el texto en sí es directamente un folio (ej. "VTA-000003", "COM-00001", etc.)
            if (/^[A-Za-z0-9]{2,10}-[A-Za-z0-9\-]+$/i.test(text)) {
                return text;
            }

            return '-';
        }

        function formatFecha(fechaStr) {
            if (!fechaStr) return '-';
            const d = new Date(fechaStr);
            if (isNaN(d.getTime())) return fechaStr;
            const day = String(d.getDate()).padStart(2, '0');
            const month = String(d.getMonth() + 1).padStart(2, '0');
            const year = d.getFullYear();
            let hours = d.getHours();
            const minutes = String(d.getMinutes()).padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            hours = hours ? hours : 12;
            return `${day}/${month}/${year} ${hours}:${minutes} ${ampm}`;
        }

        function filterData() {
            const query = document.getElementById('searchInput').value.toLowerCase().trim();
            const tipoFilter = document.getElementById('tipoFilter').value;
            const tipoProductoFilter = (document.getElementById('tipoProductoFilter')?.value || '').trim().toUpperCase();
            const usuarioFilter = (document.getElementById('usuarioFilter')?.value || '').trim().toLowerCase();

            // Rango de fechas: fecha inicio = 00:00:00 del día, fecha fin = 23:59:59 del día
            const fechaInicioVal = document.getElementById('fechaInicio').value;
            const fechaFinVal    = document.getElementById('fechaFin').value;

            let fechaInicio = null;
            let fechaFin    = null;

            if (fechaInicioVal) {
                // Interpreta la fecha en hora local, arrancando a las 00:00:00
                const [y, m, d] = fechaInicioVal.split('-').map(Number);
                fechaInicio = new Date(y, m - 1, d, 0, 0, 0, 0);
            }
            if (fechaFinVal) {
                // Fin del día: 23:59:59.999
                const [y, m, d] = fechaFinVal.split('-').map(Number);
                fechaFin = new Date(y, m - 1, d, 23, 59, 59, 999);
            }

            // Mostrar / ocultar botón limpiar fechas
            const btnLimpiar = document.getElementById('btn-limpiar-fechas');
            if (btnLimpiar) {
                btnLimpiar.style.display = (fechaInicioVal || fechaFinVal) ? 'inline-flex' : 'none';
            }

            filteredData = rawData.filter(item => {
                const esKitConsumo = (item.tipo_movimiento === 'KIT_CONSUMO' || item.tipo_movimiento === 'CONSUMO KIT');
                const esKitItem = (item.tipo_producto === 'KIT' || esKitConsumo || (item.kit_detalle && item.kit_detalle !== 'null' && item.kit_detalle !== '[]'));
                const matchTipoFilter = !tipoFilter ? true : 
                    (tipoFilter === 'KIT' ? esKitItem : (tipoFilter === 'VENTA POS' ? (item.tipo_movimiento === 'VENTA POS' || esKitConsumo) : item.tipo_movimiento === tipoFilter));

                // Filtro por tipo de producto (Normal, Insumo, Servicio, Kit/Paquete)
                const itemTipoProd = (item.tipo_producto || '').toUpperCase();
                const matchTipoProducto = !tipoProductoFilter ||
                    (tipoProductoFilter === 'KIT'
                        ? (itemTipoProd === 'KIT' || itemTipoProd === 'KIT_PAQUETE' || esKitItem)
                        : itemTipoProd === tipoProductoFilter);

                const sku     = (item.producto_sku         || '').toLowerCase();
                const desc    = (item.producto_descripcion || '').toLowerCase();
                const tipoMov = (item.tipo_movimiento      || '').toLowerCase();
                const usuario = (item.usuario_nombre       || '').toLowerCase();
                const motivo  = (item.motivo               || '').toLowerCase();
                const kitStr  = (item.kit_detalle ? (typeof item.kit_detalle === 'string' ? item.kit_detalle : JSON.stringify(item.kit_detalle)) : '').toLowerCase();

                const tipoMovDisplay = esKitConsumo ? 'venta pos' : tipoMov;
                const matchQuery = !query ||
                    sku.includes(query)     ||
                    desc.includes(query)    ||
                    tipoMov.includes(query) ||
                    tipoMovDisplay.includes(query) ||
                    usuario.includes(query) ||
                    motivo.includes(query)  ||
                    kitStr.includes(query);

                // Filtro de rango de fechas
                let matchFecha = true;
                if (fechaInicio || fechaFin) {
                    const itemFecha = item.fecha_hora ? new Date(item.fecha_hora) : null;
                    if (!itemFecha || isNaN(itemFecha.getTime())) {
                        matchFecha = false;
                    } else {
                        if (fechaInicio && itemFecha < fechaInicio) matchFecha = false;
                        if (fechaFin    && itemFecha > fechaFin)    matchFecha = false;
                    }
                }

                const itemUsuario = (item.usuario_nombre || '').trim().toLowerCase();
                const matchUsuarioFilter = !usuarioFilter || (itemUsuario === usuarioFilter);

                // Suprimir siempre las filas VENTA POS de kits: los insumos KIT_CONSUMO ya aparecen como filas propias
                const esVentaPosKit = (item.tipo_movimiento === 'VENTA POS' && (item.tipo_producto === 'KIT' || item.tipo_producto === 'KIT_PAQUETE'));
                if (esVentaPosKit) return false;

                return matchTipoFilter && matchTipoProducto && matchUsuarioFilter && matchQuery && matchFecha;
            });

            // Actualizar indicador de rango y usuario
            const infoRango = document.getElementById('fecha-rango-info');
            if (infoRango) {
                const detalles = [];
                if (fechaInicioVal || fechaFinVal) {
                    const desde = fechaInicioVal ? fechaInicioVal.split('-').reverse().join('/') : '…';
                    const hasta = fechaFinVal    ? fechaFinVal.split('-').reverse().join('/')    : 'hoy';
                    detalles.push(`entre ${desde} y ${hasta}`);
                }
                if (usuarioFilter) {
                    const userSelect = document.getElementById('usuarioFilter');
                    const userText = userSelect && userSelect.selectedIndex >= 0 ? userSelect.options[userSelect.selectedIndex].text : usuarioFilter;
                    detalles.push(`usuario: ${userText}`);
                }
                if (detalles.length > 0) {
                    infoRango.textContent = `${filteredData.length} registro(s) (${detalles.join(', ')})`;
                    infoRango.style.color = '#a5b4fc';
                } else {
                    infoRango.textContent = '';
                }
            }

            currentPage = 1;
            applySort();
        }

        function limpiarFechas() {
            document.getElementById('fechaInicio').value = '';
            document.getElementById('fechaFin').value    = '';
            filterData();
        }

        function sortData(col) {
            if (sortColumn === col) {
                sortAsc = !sortAsc;
            } else {
                sortColumn = col;
                sortAsc = true;
            }
            applySort();
        }

        function applySort() {
            filteredData.sort((a, b) => {
                let valA = a[sortColumn];
                let valB = b[sortColumn];

                if (sortColumn === 'cantidad' || sortColumn === 'costo_unitario' || sortColumn === 'stock_antes' || sortColumn === 'stock_despues') {
                    valA = parseFloat(valA || 0);
                    valB = parseFloat(valB || 0);
                } else {
                    valA = (valA || '').toString().toLowerCase();
                    valB = (valB || '').toString().toLowerCase();
                }

                if (valA < valB) return sortAsc ? -1 : 1;
                if (valA > valB) return sortAsc ? 1 : -1;
                return 0;
            });

            updateSortIcons();
            renderTable();
        }

        function updateSortIcons() {
            ['fecha_hora', 'tipo_movimiento', 'tipo_producto', 'producto_descripcion', 'cantidad', 'costo_unitario', 'usuario_nombre'].forEach(col => {
                const icon = document.getElementById(`sort-${col}`);
                if (icon) {
                    if (sortColumn === col) {
                        icon.textContent = sortAsc ? '▲' : '▼';
                        icon.style.opacity = '1';
                    } else {
                        icon.textContent = '⇅';
                        icon.style.opacity = '0.4';
                    }
                }
            });
        }

        function renderTable() {
            const tbody = document.getElementById('historialTbody');
            tbody.innerHTML = '';

            const total = filteredData.length;
            const totalPages = Math.ceil(total / pageSize) || 1;
            if (currentPage > totalPages) currentPage = totalPages;

            const startIdx = (currentPage - 1) * pageSize;
            const pageData = filteredData.slice(startIdx, startIdx + pageSize);

            if (pageData.length === 0) {
                tbody.innerHTML = `<tr><td colspan="10" style="text-align: center; padding: 2rem; color: var(--text-muted);">No se encontraron movimientos.</td></tr>`;
            } else {
                pageData.forEach((item, idx) => {
                    // ── Suprimir la fila padre VENTA POS de un Kit: los insumos KIT_CONSUMO ya aparecen como filas propias ──
                    if (item.tipo_movimiento === 'VENTA POS' && (item.tipo_producto === 'KIT' || item.tipo_producto === 'KIT_PAQUETE')) {
                        return;
                    }

                    const rowId = `row-${item.id || idx}`;
                    const badgeClass = getBadgeClass(item.tipo_movimiento);
                    const cantidadFormatted = parseFloat(item.cantidad || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    const costoFormatted = '$' + parseFloat(item.costo_unitario || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    const stockAntesNum = parseFloat(item.stock_antes ?? 0);
                    const stockDespuesNum = parseFloat(item.stock_despues ?? 0);
                    const stockAntesFormatted = isNaN(stockAntesNum) ? '0.00' : stockAntesNum.toFixed(2);
                    const stockDespuesFormatted = isNaN(stockDespuesNum) ? '0.00' : stockDespuesNum.toFixed(2);

                    const folioVal = formatNotasFolio(item.motivo);
                    const folioHtml = (folioVal !== '-')
                        ? `<span style="font-family: monospace; font-weight: 700; color: #38bdf8; background: rgba(56, 189, 248, 0.1); padding: 0.2rem 0.5rem; border-radius: 6px; border: 1px solid rgba(56, 189, 248, 0.25); white-space: nowrap;">${folioVal}</span>`
                        : `<span style="color: var(--text-muted); opacity: 0.4;">-</span>`;

                    // ── Detectar y parsear insumos del Kit ──────────────────────
                    const isKit = (item.tipo_producto === 'KIT' || (item.kit_detalle && item.kit_detalle !== 'null' && item.kit_detalle !== '[]'));
                    const kitBadgeExtra = isKit ? ` <span class="badge badge-kit" style="font-size:0.65rem;padding:0.2rem 0.5rem;margin-left:0.3rem;">📦 Kit</span>` : '';

                    let kitDetalleArr = null;
                    if (isKit && item.kit_detalle) {
                        try {
                            kitDetalleArr = typeof item.kit_detalle === 'string' ? JSON.parse(item.kit_detalle) : item.kit_detalle;
                            if (!Array.isArray(kitDetalleArr) || kitDetalleArr.length === 0) kitDetalleArr = null;
                        } catch(e) { kitDetalleArr = null; }
                    }

                    // ── Fila principal del Kit (sin expansión inline) ────────────
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td style="white-space: nowrap; font-size: 0.85rem; color: var(--text-muted);">
                            ${formatFecha(item.fecha_hora)}
                        </td>
                        <td>
                            <span class="badge ${badgeClass}">${(item.tipo_movimiento === "KIT_CONSUMO" || item.tipo_movimiento === "CONSUMO KIT" ? "VENTA POS" : item.tipo_movimiento)}</span>${kitBadgeExtra}
                        </td>
                        <td>${getTipoBadge(item.tipo_producto)}</td>
                        <td>
                            <div style="font-weight: 600; color: #fff;">${item.producto_descripcion}</div>
                            ${item.producto_sku ? `<span class="sku-tag">SKU: ${item.producto_sku}</span>` : ''}
                        </td>
                        <td style="text-align: right; font-weight: 600;">
                            ${(item.tipo_movimiento === 'SALIDA STOCK' || item.tipo_movimiento === 'VENTA POS' || item.tipo_movimiento === 'KIT_CONSUMO' || item.tipo_movimiento === 'CONSUMO KIT') ? `<span style="color:#fda4af; font-weight:700;">-${cantidadFormatted}</span>` :
                              `<span style="color:#6ee7b7;">+${cantidadFormatted}</span>`}
                        </td>
                        <td style="text-align: center; font-size: 0.85rem;">
                            ${(() => {
                                const tipoProd = (item.tipo_producto || '').toUpperCase();
                                const esServicio = tipoProd === 'SERVICIO';
                                const ambosEnCero = stockAntesNum === 0 && stockDespuesNum === 0;

                                // Servicio sin stock real → N/A
                                if (esServicio && ambosEnCero) {
                                    return `<span style="font-size:0.73rem; color:#94a3b8; font-style:italic;">Servicio (N/A)</span>`;
                                }

                                // Determinar color flecha y del valor final según si subió, bajó o no cambió
                                const diff = stockDespuesNum - stockAntesNum;
                                let colorDespues, flecha;
                                if (diff > 0) {
                                    colorDespues = '#6ee7b7'; // verde
                                    flecha = '<span style="color:#6ee7b7;opacity:0.8;">→</span>';
                                } else if (diff < 0) {
                                    colorDespues = '#fda4af'; // rojo
                                    flecha = '<span style="color:#fda4af;opacity:0.8;">→</span>';
                                } else {
                                    colorDespues = '#94a3b8'; // gris neutro
                                    flecha = '<span style="color:#64748b;opacity:0.7;">→</span>';
                                }

                                return `<span style="color:var(--text-muted);">${stockAntesFormatted}</span>
                                        <span style="margin:0 0.3rem;">${flecha}</span>
                                        <strong style="color:${colorDespues};">${stockDespuesFormatted}</strong>`;
                            })()}
                        </td>
                        <td style="text-align: right; font-size: 0.85rem;">${costoFormatted}</td>
                        <td style="font-size: 0.85rem; text-align: center; white-space: nowrap;">
                            ${folioHtml}
                        </td>
                        <td style="font-size: 0.85rem; font-weight: 500;">${item.usuario_nombre || 'Sistema'}</td>
                        <td style="text-align: center;" class="no-print">
                            <button class="btn-detalle" onclick="mostrarDetalles(${item.id || idx})" title="Ver detalles del movimiento">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                Detalles
                            </button>
                        </td>
                    `;
                    tbody.appendChild(tr);

                    // ── Filas separadas por cada insumo del Kit (solo altas/edición de catálogo) ──
                    if (kitDetalleArr && item.tipo_movimiento !== 'VENTA POS' && item.tipo_movimiento !== 'KIT_CONSUMO') {
                        kitDetalleArr.forEach(ins => {
                            const insQty = parseFloat(ins.cantidad_total || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                            const insUnidad = (ins.unidad || '').toUpperCase();

                            const insTr = document.createElement('tr');
                            insTr.style.cssText = 'background: rgba(139,92,246,0.04); border-left: 3px solid rgba(139,92,246,0.35);';
                            insTr.innerHTML = `
                                <td style="white-space: nowrap; font-size: 0.8rem; color: var(--text-muted); padding-left: 1.8rem;">
                                    <span style="opacity:0.45;">↳</span>
                                </td>
                                <td>
                                    <span class="badge ${badgeClass}" style="opacity:0.65; font-size:0.7rem; padding:0.25rem 0.55rem;">${item.tipo_movimiento === 'ALTA PRODUCTO' ? 'Insumo Agregado' : item.tipo_movimiento}</span>
                                </td>
                                <td>${getTipoBadge('INSUMO')}</td>
                                <td>
                                    <div style="font-weight:500; color:#e2e8f0; font-size:0.88rem;">${ins.insumo_nombre}</div>
                                    ${ins.insumo_sku ? `<span class="sku-tag">SKU: ${ins.insumo_sku}</span>` : ''}
                                </td>
                                <td style="text-align:right; font-size:0.85rem; color:#c4b5fd; font-weight:600;">
                                    ${insQty} <span style="font-size:0.75rem; opacity:0.7;">${insUnidad}</span>
                                </td>
                                <td style="text-align:center; font-size:0.8rem; color:var(--text-muted);">
                                    <span style="font-style:italic; opacity:0.5;">—</span>
                                </td>
                                <td style="text-align:right; font-size:0.8rem; color:var(--text-muted);">
                                    <span style="opacity:0.5;">—</span>
                                </td>
                                <td style="font-size:0.82rem; text-align:center; white-space:nowrap;">
                                    ${(() => {
                                        const ticketFolio = formatNotasFolio(item.motivo);
                                        if (ticketFolio !== '-') {
                                            return `<span style="font-family:monospace; font-weight:700; color:#38bdf8; background:rgba(56,189,248,0.1); padding:0.2rem 0.5rem; border-radius:6px; border:1px solid rgba(56,189,248,0.25); white-space:nowrap;">🎫 ${ticketFolio}</span>`;
                                        }
                                        const kitSku = item.producto_sku || '';
                                        if (kitSku) {
                                            return `<span class="sku-tag" style="font-weight:600;">📦 ${kitSku}</span>`;
                                        }
                                        return `<span style="color:var(--text-muted); opacity:0.4;">-</span>`;
                                    })()}
                                </td>
                                <td style="font-size:0.82rem; color:var(--text-muted);">${item.usuario_nombre || 'Sistema'}</td>
                                <td class="no-print"></td>
                            `;
                            tbody.appendChild(insTr);
                        });
                    }
                });
            }

            // Pagination info
            const endIdx = Math.min(startIdx + pageSize, total);
            const infoText = total === 0 
                ? 'Mostrando 0 registros' 
                : `Mostrando ${startIdx + 1} a ${endIdx} de ${total} registros`;
            document.getElementById('paginationInfo').textContent = infoText;

            // Pagination controls
            const controls = document.getElementById('paginationControls');
            controls.innerHTML = '';

            if (totalPages > 1) {
                // Prev button
                const prevBtn = document.createElement('button');
                prevBtn.textContent = '«';
                prevBtn.style.cssText = `padding: 0.3rem 0.6rem; border-radius: 6px; border: 1px solid var(--border); background: ${currentPage === 1 ? 'rgba(255,255,255,0.02)' : 'rgba(255,255,255,0.08)'}; color: ${currentPage === 1 ? 'var(--text-muted)' : '#fff'}; cursor: ${currentPage === 1 ? 'default' : 'pointer'};`;
                prevBtn.disabled = currentPage === 1;
                prevBtn.onclick = () => { if (currentPage > 1) { currentPage--; renderTable(); } };
                controls.appendChild(prevBtn);

                // Page numbers
                for (let i = 1; i <= totalPages; i++) {
                    const pageBtn = document.createElement('button');
                    pageBtn.textContent = i;
                    const isActive = i === currentPage;
                    pageBtn.style.cssText = `padding: 0.3rem 0.65rem; border-radius: 6px; border: 1px solid ${isActive ? 'var(--primary)' : 'var(--border)'}; background: ${isActive ? 'var(--primary)' : 'rgba(255,255,255,0.05)'}; color: ${isActive ? '#fff' : 'var(--text-muted)'}; font-weight: ${isActive ? '600' : 'normal'}; cursor: pointer;`;
                    pageBtn.onclick = () => { currentPage = i; renderTable(); };
                    controls.appendChild(pageBtn);
                }

                // Next button
                const nextBtn = document.createElement('button');
                nextBtn.textContent = '»';
                nextBtn.style.cssText = `padding: 0.3rem 0.6rem; border-radius: 6px; border: 1px solid var(--border); background: ${currentPage === totalPages ? 'rgba(255,255,255,0.02)' : 'rgba(255,255,255,0.08)'}; color: ${currentPage === totalPages ? 'var(--text-muted)' : '#fff'}; cursor: ${currentPage === totalPages ? 'default' : 'pointer'};`;
                nextBtn.disabled = currentPage === totalPages;
                nextBtn.onclick = () => { if (currentPage < totalPages) { currentPage++; renderTable(); } };
                controls.appendChild(nextBtn);
            }
        }

        // ── Índice rápido para buscar por id ──────────────────────────────────────
        function getItemById(id) {
            return rawData.find(item => String(item.id) === String(id)) || null;
        }

        // ── Modal de Detalles ─────────────────────────────────────────────────────
        function mostrarDetalles(id) {
            const item = getItemById(id);
            if (!item) return;

            const costoFmt = '$' + parseFloat(item.costo_unitario || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const cantFmt  = parseFloat(item.cantidad || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const sAFmt    = parseFloat(item.stock_antes   || 0).toFixed(2);
            const sDFmt    = parseFloat(item.stock_despues || 0).toFixed(2);
            const badgeClass = getBadgeClass(item.tipo_movimiento);
            const folioVal = formatNotasFolio(item.motivo);

            // Kit insumos
            let kitSection = '';
            let kitDetalleArr = null;
            try {
                kitDetalleArr = typeof item.kit_detalle === 'string' ? JSON.parse(item.kit_detalle) : item.kit_detalle;
                if (!Array.isArray(kitDetalleArr) || kitDetalleArr.length === 0) kitDetalleArr = null;
            } catch(e) { kitDetalleArr = null; }

            if (kitDetalleArr) {
                const pills = kitDetalleArr.map(ins => {
                    const qty = parseFloat(ins.cantidad_total || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    const unidad = (ins.unidad || '').toUpperCase();
                    return `<div class="modal-kit-pill">
                        <span style="color:#a78bfa;font-weight:bold;">↳</span>
                        <span style="background:rgba(139,92,246,0.25);color:#c4b5fd;font-weight:700;padding:0.1rem 0.4rem;border-radius:4px;font-size:0.75rem;">${qty} ${unidad}</span>
                        <span style="color:#f1f5f9;font-weight:500;">${ins.insumo_nombre}</span>
                        ${ins.insumo_sku ? `<span style="color:#94a3b8;font-size:0.72rem;font-family:monospace;margin-left:auto;">SKU: ${ins.insumo_sku}</span>` : ''}
                    </div>`;
                }).join('');
                kitSection = `<div class="modal-kit-section">
                    <div class="modal-kit-title">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                        Insumos del Kit (${kitDetalleArr.length})
                    </div>
                    ${pills}
                </div>`;
            }

            // Motivo completo (si hay y no solo folio)
            const motivoCompleto = (item.motivo && item.motivo.trim()) ? item.motivo.trim() : '—';

            document.getElementById('modalContent').innerHTML = `
                <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.1rem;">
                    <span class="badge ${badgeClass}" style="font-size:0.8rem;">${(item.tipo_movimiento === 'KIT_CONSUMO' || item.tipo_movimiento === 'CONSUMO KIT') ? 'VENTA POS' : item.tipo_movimiento}</span>
                    ${getTipoBadge(item.tipo_producto)}
                    ${item.tipo_producto === 'KIT' || kitDetalleArr ? `<span class="badge badge-kit" style="font-size:0.72rem;">📦 Kit</span>` : ''}
                </div>
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="detail-label">Fecha y Hora</div>
                        <div class="detail-value">${formatFecha(item.fecha_hora)}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">ID Movimiento</div>
                        <div class="detail-value mono">#${item.id || '—'}</div>
                    </div>
                    <div class="detail-item full">
                        <div class="detail-label">Producto</div>
                        <div class="detail-value" style="font-weight:700;color:#fff;">${item.producto_descripcion || '—'}</div>
                        ${item.producto_sku ? `<div class="detail-value mono" style="font-size:0.8rem;margin-top:0.25rem;">SKU: ${item.producto_sku}</div>` : ''}
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Cantidad</div>
                        <div class="detail-value" style="font-size:1.05rem;font-weight:700;color:${(item.tipo_movimiento==='SALIDA STOCK'||item.tipo_movimiento==='VENTA POS'||item.tipo_movimiento==='KIT_CONSUMO')?'#fda4af':'#6ee7b7'};">${(item.tipo_movimiento === 'SALIDA STOCK' || item.tipo_movimiento === 'VENTA POS' || item.tipo_movimiento === 'KIT_CONSUMO') ? '-' : '+'}${cantFmt}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Costo Unitario</div>
                        <div class="detail-value" style="color:#38bdf8;font-weight:700;">${costoFmt}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Stock Antes</div>
                        <div class="detail-value" style="color:#94a3b8;">${sAFmt}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Stock Después</div>
                        <div class="detail-value" style="color:${parseFloat(item.stock_despues||0)>=parseFloat(item.stock_antes||0)?'#6ee7b7':'#fda4af'};font-weight:700;">${sDFmt}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Folio / Referencia</div>
                        <div class="detail-value mono">${folioVal !== '-' ? folioVal : '—'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Usuario</div>
                        <div class="detail-value" style="color:#e2e8f0;">${item.usuario_nombre || 'Sistema'}</div>
                    </div>
                    <div class="detail-item full">
                        <div class="detail-label">Notas / Motivo completo</div>
                        <div class="detail-value" style="font-size:0.85rem;color:#cbd5e1;line-height:1.5;">${motivoCompleto}</div>
                    </div>
                </div>
                ${kitSection}
                ${folioVal !== '-' ? `
                <div style="margin-top:1.1rem;">
                    <button onclick="verTicketHistorial('${folioVal}')"
                        style="width:100%; display:flex; align-items:center; justify-content:center; gap:0.5rem;
                               padding:0.65rem 1rem;
                               background:rgba(56,189,248,0.12);
                               border:1px solid rgba(56,189,248,0.35);
                               color:#38bdf8;
                               border-radius:10px;
                               font-size:0.88rem; font-weight:700; cursor:pointer;
                               transition:all 0.18s;"
                        onmouseover="this.style.background='rgba(56,189,248,0.25)';this.style.color='#fff';"
                        onmouseout="this.style.background='rgba(56,189,248,0.12)';this.style.color='#38bdf8';">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                        Ver Ticket de Venta &mdash; ${folioVal}
                    </button>
                </div>` : ''}
            `;

            document.getElementById('detallesModal').classList.add('active');
        }

        function cerrarModal() {
            document.getElementById('detallesModal').classList.remove('active');
        }

        // Cerrar modal al hacer clic en el overlay
        document.getElementById('detallesModal').addEventListener('click', function(e) {
            if (e.target === this) cerrarModal();
        });

        // Cerrar modal con Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') cerrarModal();
        });

        // ── Ticket Térmico desde Historial ────────────────────────────────────
        async function verTicketHistorial(folio) {
            const contentEl = document.getElementById('ticketContent');
            contentEl.innerHTML = '<div style="text-align:center; padding:2rem; color:#94a3b8;">Cargando ticket...</div>';
            document.getElementById('ticketModal').classList.add('active');

            try {
                const res = await fetch(`api/pos_api.php?action=obtener_ticket&venta_id=${encodeURIComponent(folio)}`);
                const data = await res.json();
                if (data.success && data.ticket) {
                    renderTicketHistorial(data.ticket);
                } else {
                    contentEl.innerHTML = `<div style="text-align:center; padding:2rem; color:#fca5a5;">No se encontró el ticket para el folio: ${folio}</div>`;
                }
            } catch (e) {
                contentEl.innerHTML = '<div style="text-align:center; padding:2rem; color:#fca5a5;">Error al obtener el ticket.</div>';
            }
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str || '';
            return div.innerHTML;
        }

        function renderTicketHistorial(ticket) {
            const isPagado = ticket.estado_pago === 'PAGADO';
            const detalles = ticket.detalles || [];

            document.getElementById('ticketContent').innerHTML = `
                <div class="hist-ticket-paper" id="hist-ticket-print-wrapper">
                    <div class="hist-ticket-center">
                        <h2 style="font-size:1.15rem; font-weight:800; letter-spacing:0.02em;">LAVANDERÍA VERA</h2>
                        <p style="font-size:0.7rem; color:#444;">Tu ropa limpia, fresca y a tiempo</p>
                        <p style="font-size:0.7rem; color:#444;">Tel: (999) 000-0000</p>
                        <div class="hist-ticket-divider"></div>
                        <div style="font-weight:700; font-size:0.95rem;">FOLIO: ${ticket.folio}</div>
                        ${ticket.numero_carga ? `<div style="font-weight:800; color:#000; font-size:0.9rem; background:#f0f0f0; padding:2px 4px; margin:3px 0; border:1px solid #ccc;">CARGA DE LAVADO: ${ticket.numero_carga}</div>` : ''}
                        <p style="font-size:0.7rem; color:#555;">Fecha: ${ticket.fecha_venta}</p>
                        <p style="font-size:0.7rem; color:#555;">Cajero: ${escapeHtml(ticket.cajero_nombre)}</p>
                    </div>

                    <div class="hist-ticket-divider"></div>
                    <div style="font-size:0.73rem;">
                        <div><strong>Cliente:</strong> ${escapeHtml(ticket.cliente_nombre || 'Público General')}</div>
                        ${ticket.cliente_telefono ? `<div><strong>Teléfono:</strong> ${escapeHtml(ticket.cliente_telefono)}</div>` : ''}
                    </div>

                    <div class="hist-ticket-divider"></div>
                    <table class="hist-ticket-table">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Cant</th>
                                <th style="text-align:left;">Descripción</th>
                                <th style="text-align:right;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${detalles.map(d => `
                                <tr>
                                    <td style="vertical-align:top;">${parseFloat(d.cantidad)}</td>
                                    <td style="vertical-align:top;">
                                        ${escapeHtml(d.descripcion)}
                                        ${d.ropa_kg > 0 ? `<div style="font-size:0.65rem; color:#555;">(${d.ropa_kg} kg)</div>` : ''}
                                        ${d.notas ? `<div style="font-size:0.65rem; color:#555;">* ${escapeHtml(d.notas)}</div>` : ''}
                                    </td>
                                    <td style="text-align:right; vertical-align:top;">$${parseFloat(d.subtotal).toFixed(2)}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>

                    <div class="hist-ticket-divider"></div>
                    <div style="display:flex; justify-content:space-between; font-size:1.15rem; font-weight:800;">
                        <span>TOTAL:</span>
                        <span>$${parseFloat(ticket.total).toFixed(2)}</span>
                    </div>

                    <div style="margin-top:0.5rem; font-size:0.75rem;">
                        <div style="display:flex; justify-content:space-between;">
                            <span>Estado:</span>
                            <strong style="color:${isPagado ? '#059669' : '#d97706'}; font-size:0.85rem;">
                                ${isPagado ? 'PAGADO' : 'PENDIENTE DE PAGO'}
                            </strong>
                        </div>
                        <div style="display:flex; justify-content:space-between;">
                            <span>Método:</span>
                            <span>${ticket.metodo_pago}</span>
                        </div>
                        ${isPagado && ticket.metodo_pago === 'EFECTIVO' ? `
                        <div style="display:flex; justify-content:space-between;">
                            <span>Recibido:</span>
                            <span>$${parseFloat(ticket.monto_recibido || 0).toFixed(2)}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between;">
                            <span>Cambio:</span>
                            <span>$${parseFloat(ticket.cambio || 0).toFixed(2)}</span>
                        </div>
                        ` : ''}
                    </div>

                    <div class="hist-ticket-divider"></div>
                    <div class="hist-ticket-center" style="font-size:0.68rem; color:#444;">
                        <p style="font-weight:700;">¡Gracias por su preferencia!</p>
                        <p>Conserve este ticket para recoger sus prendas.</p>
                        <p style="margin-top:0.3rem;">No nos hacemos responsables por prendas no recogidas después de 30 días.</p>
                    </div>
                </div>
            `;
        }

        function cerrarModalTicket() {
            document.getElementById('ticketModal').classList.remove('active');
        }

        function imprimirTicketHistorial() {
            const ticketEl = document.getElementById('hist-ticket-print-wrapper');
            if (!ticketEl) return;
            const printWin = window.open('', '_blank', 'width=400,height=600');
            printWin.document.write(`
                <html><head><title>Ticket</title>
                <style>
                    @page { size: 80mm auto; margin: 0; }
                    body { margin: 0; padding: 8px; font-family: 'JetBrains Mono', 'Courier New', monospace; font-size: 12px; color: #000; }
                    h2 { margin: 0; } p { margin: 2px 0; }
                    table { width: 100%; border-collapse: collapse; }
                    th, td { padding: 2px 0; font-size: 11px; }
                    th { border-bottom: 1px dashed #000; }
                    .divider { border-top: 1px dashed #000; margin: 8px 0; }
                    .center { text-align: center; }
                </style></head><body>
                ${ticketEl.innerHTML.replace(/hist-ticket-divider/g, 'divider').replace(/hist-ticket-center/g, 'center')}
                </body></html>
            `);
            printWin.document.close();
            printWin.onload = () => { printWin.print(); printWin.close(); };
        }

        // Cerrar modal ticket al clic en overlay
        document.getElementById('ticketModal').addEventListener('click', function(e) {
            if (e.target === this) cerrarModalTicket();
        });

        document.getElementById('searchInput').addEventListener('input', filterData);
        document.getElementById('tipoFilter').addEventListener('change', filterData);
        document.getElementById('tipoProductoFilter')?.addEventListener('change', filterData);
        document.getElementById('usuarioFilter')?.addEventListener('change', filterData);
        document.getElementById('pageSizeSelect').addEventListener('change', (e) => {
            pageSize = parseInt(e.target.value) || 10;
            currentPage = 1;
            renderTable();
        });

        // Exportación a Excel (CSV UTF-8 BOM para compatibilidad con Excel)
        function exportarExcel() {
            if (!filteredData || filteredData.length === 0) {
                alert('No hay movimientos para exportar.');
                return;
            }

            const headers = [
                'Fecha y Hora',
                'Tipo Movimiento',
                'SKU',
                'Producto',
                'Cantidad',
                'Stock Antes',
                'Stock Después',
                'Costo Unitario ($)',
                'Notas',
                'Usuario'
            ];

            let csvContent = '\uFEFF'; // BOM UTF-8
            csvContent += headers.map(h => `"${h}"`).join(',') + '\n';

            filteredData.forEach(item => {
                const folioDisplay = formatNotasFolio(item.motivo);

                let descProductoExport = item.producto_descripcion || '';
                let kitDetalleArr = null;
                try {
                    kitDetalleArr = typeof item.kit_detalle === 'string' ? JSON.parse(item.kit_detalle) : item.kit_detalle;
                } catch(e){}
                if (Array.isArray(kitDetalleArr) && kitDetalleArr.length > 0) {
                    const insumosTxt = kitDetalleArr.map(ins => `${ins.cantidad_total} ${ins.unidad} de ${ins.insumo_nombre}`).join('; ');
                    descProductoExport += ` [Kit contiene: ${insumosTxt}]`;
                }

                const row = [
                    formatFecha(item.fecha_hora),
                    item.tipo_movimiento || '',
                    item.producto_sku || '',
                    descProductoExport,
                    parseFloat(item.cantidad || 0).toFixed(2),
                    parseFloat(item.stock_antes || 0).toFixed(2),
                    parseFloat(item.stock_despues || 0).toFixed(2),
                    parseFloat(item.costo_unitario || 0).toFixed(2),
                    folioDisplay,
                    item.usuario_nombre || 'Sistema'
                ];

                csvContent += row.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',') + '\n';
            });

            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            const dateStr = new Date().toISOString().slice(0, 10);
            link.setAttribute('href', url);
            link.setAttribute('download', `historial_movimientos_${dateStr}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function imprimirHistorial() {
            window.print();
        }

        // Si llega un filtro por SKU, pre-cargarlo en el buscador
        if (filtroSkuInicial) {
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.value = filtroSkuInicial;
            }
            filterData(); // aplica el filtro y renderiza
        } else {
            // Initial render
            applySort();
        }
    </script>
    <script src="js/sidebar.js"></script>
</body>
</html>
