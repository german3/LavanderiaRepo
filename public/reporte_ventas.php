<?php
date_default_timezone_set('America/Mexico_City');
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../src/modules/auth/AuthService.php';
require_once __DIR__ . '/../src/modules/reports/ReporteVentas.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;
use App\Modules\Reports\ReporteVentas;

$db = (new Database())->getConnection();
$auth = new AuthService($db);

// Middleware de Autenticación
if (!$auth->checkAuth()) {
    header("Location: login.php");
    exit;
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$reporteModel = new ReporteVentas($db);

// Filtros iniciales opcionales por GET
$fechaInicio = $_GET['fecha_inicio'] ?? '';
$fechaFin    = $_GET['fecha_fin'] ?? '';
$estadoVenta = $_GET['estado_venta'] ?? 'ACTIVA'; // Por defecto ventas activas

$filtros = [];
if (!empty($fechaInicio)) $filtros['fecha_inicio'] = $fechaInicio;
if (!empty($fechaFin))    $filtros['fecha_fin']    = $fechaFin;
if ($estadoVenta !== '')  $filtros['estado_venta'] = $estadoVenta;

$ventasData = $reporteModel->obtenerReporteVentas($filtros);
$kpis = $reporteModel->calcularKpis($ventasData);

$ventasJson = json_encode($ventasData, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de Ventas & Utilidades | Lavandería Vera</title>
    <link rel="stylesheet" href="css/index.css">
    <script src="js/theme.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 1.1rem;
            margin-bottom: 1.75rem;
        }

        .stat-card {
            background: var(--bg-card);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.15rem 1.35rem;
            display: flex;
            align-items: center;
            gap: 1.1rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
            transition: transform 0.2s ease, border-color 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .stat-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stat-icon-ventas {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .stat-icon-costos {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .stat-icon-utilidad {
            background: rgba(59, 130, 246, 0.15);
            color: #38bdf8;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .stat-icon-margen {
            background: rgba(168, 85, 247, 0.15);
            color: #c084fc;
            border: 1px solid rgba(168, 85, 247, 0.3);
        }

        .stat-info .stat-value {
            font-size: 1.55rem;
            font-weight: 700;
            color: #fff;
            line-height: 1.2;
        }

        .stat-info .stat-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 0.2rem;
            font-weight: 500;
        }

        /* ── Toolbar de Filtros y Fechas ── */
        .toolbar-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.15rem 1.35rem;
            margin-bottom: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.2);
        }

        .toolbar-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .filters-group {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            flex: 1;
        }

        .input-search {
            flex: 1;
            min-width: 260px;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0.6rem 1rem;
            color: #fff;
            font-size: 0.88rem;
            outline: none;
            transition: all 0.2s;
        }

        .input-search:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.25);
        }

        .select-filter, .input-date {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0.6rem 0.85rem;
            color: #fff;
            font-size: 0.85rem;
            outline: none;
            cursor: pointer;
            transition: border-color 0.2s;
        }

        .select-filter:focus, .input-date:focus {
            border-color: var(--primary);
        }

        .toolbar-actions {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            flex-wrap: wrap;
        }

        .btn-tool {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.55rem 0.95rem;
            border-radius: 10px;
            font-size: 0.84rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid transparent;
        }

        .btn-excel {
            background: rgba(16, 185, 129, 0.15);
            border-color: rgba(16, 185, 129, 0.35);
            color: #34d399;
        }
        .btn-excel:hover {
            background: rgba(16, 185, 129, 0.28);
            color: #fff;
            transform: translateY(-1px);
        }

        .btn-print {
            background: rgba(99, 102, 241, 0.15);
            border-color: rgba(99, 102, 241, 0.35);
            color: #a5b4fc;
        }
        .btn-print:hover {
            background: rgba(99, 102, 241, 0.28);
            color: #fff;
            transform: translateY(-1px);
        }

        .btn-quick-filter {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--text-muted);
            border-radius: 8px;
            padding: 0.35rem 0.7rem;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-quick-filter:hover, .btn-quick-filter.active {
            background: rgba(59, 130, 246, 0.2);
            border-color: rgba(59, 130, 246, 0.5);
            color: #93c5fd;
        }

        /* ── Tabla de Datos ── */
        .table-container {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.3);
            margin-bottom: 2rem;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.85rem;
        }

        .table thead th {
            background: rgba(15, 23, 42, 0.85);
            padding: 0.85rem 1rem;
            font-weight: 600;
            color: #cbd5e1;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
            letter-spacing: 0.02em;
        }

        .table thead th.text-right {
            text-align: right;
        }

        .table thead th.text-center {
            text-align: center;
        }

        .table tbody td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            color: #e2e8f0;
            vertical-align: middle;
        }

        .table tbody tr:hover {
            background: rgba(255, 255, 255, 0.03);
        }

        .table tbody td.text-right {
            text-align: right;
        }

        .table tbody td.text-center {
            text-align: center;
        }

        /* ── Badges & Tags ── */
        .badge-sku {
            font-family: 'JetBrains Mono', monospace;
            background: rgba(148, 163, 184, 0.12);
            color: #94a3b8;
            padding: 0.2rem 0.45rem;
            border-radius: 6px;
            font-size: 0.76rem;
            border: 1px solid rgba(148, 163, 184, 0.2);
            white-space: nowrap;
        }

        .badge-barcode {
            font-family: 'JetBrains Mono', monospace;
            color: #cbd5e1;
            font-size: 0.76rem;
            white-space: nowrap;
        }

        .badge-ticket {
            font-family: 'JetBrains Mono', monospace;
            background: rgba(56, 189, 248, 0.12);
            color: #38bdf8;
            padding: 0.22rem 0.55rem;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 700;
            border: 1px solid rgba(56, 189, 248, 0.25);
            white-space: nowrap;
        }

        .badge-utilidad-alta {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.78rem;
            border: 1px solid rgba(16, 185, 129, 0.3);
            white-space: nowrap;
        }

        .badge-utilidad-media {
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.78rem;
            border: 1px solid rgba(59, 130, 246, 0.3);
            white-space: nowrap;
        }

        .badge-utilidad-baja {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.78rem;
            border: 1px solid rgba(245, 158, 11, 0.3);
            white-space: nowrap;
        }

        .badge-utilidad-negativa {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.78rem;
            border: 1px solid rgba(239, 68, 68, 0.3);
            white-space: nowrap;
        }

        .btn-ver-ticket {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.7rem;
            background: rgba(56, 189, 248, 0.12);
            border: 1px solid rgba(56, 189, 248, 0.35);
            color: #38bdf8;
            border-radius: 8px;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.18s ease;
            white-space: nowrap;
        }

        .btn-ver-ticket:hover {
            background: rgba(56, 189, 248, 0.25);
            border-color: rgba(56, 189, 248, 0.65);
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(56, 189, 248, 0.2);
        }

        /* ── Paginador ── */
        .pagination-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.9rem 1.25rem;
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
            gap: 0.75rem;
            background: rgba(15, 23, 42, 0.4);
        }

        .pagination-info {
            font-size: 0.82rem;
            color: var(--text-muted);
        }

        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .btn-page {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: #cbd5e1;
            padding: 0.35rem 0.65rem;
            border-radius: 6px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.15s;
        }

        .btn-page:hover:not(:disabled) {
            background: rgba(59, 130, 246, 0.2);
            border-color: var(--primary);
            color: #fff;
        }

        .btn-page.active {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
            font-weight: 700;
        }

        .btn-page:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        /* ── Modal Ticket Térmico ── */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1050;
            padding: 1rem;
        }

        .modal-overlay.active {
            display: flex;
            animation: fadeIn 0.2s ease-out;
        }

        .modal-box {
            background: #0f172a;
            border: 1px solid var(--border);
            border-radius: 16px;
            width: 100%;
            max-width: 440px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
        }

        .modal-header {
            padding: 1rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border);
            background: rgba(15, 23, 42, 0.8);
        }

        .modal-header h2 {
            font-size: 1.05rem;
            font-weight: 700;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 1.35rem;
            cursor: pointer;
            padding: 0;
            line-height: 1;
            transition: color 0.15s;
        }
        .modal-close:hover { color: #fff; }

        .modal-body {
            padding: 1.25rem;
            overflow-y: auto;
            background: #1e293b;
        }

        .modal-footer {
            padding: 0.9rem 1.25rem;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 0.75rem;
            background: rgba(15, 23, 42, 0.8);
        }

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

        /* ── Estilos de Impresión Formal (A4 / Reporte) ── */
        .print-header, .print-kpis, .print-signature-footer {
            display: none;
        }

        @media print {
            html, body {
                background: #ffffff !important;
                color: #0f172a !important;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important;
                margin: 0 !important;
                padding: 0 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .sidebar, .toolbar-card, .pagination-bar, .modal-overlay, #theme-toggle, .btn-ver-ticket, .no-print {
                display: none !important;
            }

            .main-content {
                padding: 0 !important;
                margin: 0 !important;
                width: 100% !important;
            }

            .print-header {
                display: flex !important;
                justify-content: space-between;
                align-items: center;
                border-bottom: 2px solid #0f172a;
                padding-bottom: 12px;
                margin-bottom: 16px;
            }

            .print-header-brand {
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .print-logo {
                width: 65px;
                height: 65px;
                object-fit: cover;
                border-radius: 8px;
            }

            .print-header-titles h1 {
                margin: 0;
                font-size: 1.4rem;
                font-weight: 800;
                color: #0f172a;
            }

            .print-header-titles p {
                margin: 2px 0 0;
                font-size: 0.85rem;
                color: #475569;
            }

            .print-meta-box {
                text-align: right;
                font-size: 0.78rem;
                color: #475569;
                line-height: 1.4;
            }

            .print-kpis {
                display: flex !important;
                gap: 12px;
                margin-bottom: 16px;
            }

            .print-kpi-item {
                flex: 1;
                border: 1px solid #cbd5e1;
                border-radius: 6px;
                padding: 8px 12px;
                background: #f8fafc;
                text-align: center;
            }

            .print-kpi-label {
                display: block;
                font-size: 0.75rem;
                color: #64748b;
                font-weight: 600;
                text-transform: uppercase;
            }

            .print-kpi-val {
                display: block;
                font-size: 1.25rem;
                font-weight: 800;
                color: #0f172a;
                margin-top: 2px;
            }

            .table-container {
                border: 1px solid #cbd5e1 !important;
                box-shadow: none !important;
                border-radius: 0 !important;
            }

            .table {
                font-size: 8pt !important;
            }

            .table thead th {
                background: #f1f5f9 !important;
                color: #0f172a !important;
                border-bottom: 2px solid #94a3b8 !important;
                padding: 6px 8px !important;
            }

            .table tbody td {
                padding: 6px 8px !important;
                color: #0f172a !important;
                border-bottom: 1px solid #e2e8f0 !important;
            }

            .table th:last-child, .table td:last-child {
                display: none !important; /* Ocultar columna Acciones en impresión */
            }

            .print-signature-footer {
                display: flex !important;
                justify-content: space-between;
                align-items: flex-end;
                margin-top: 30px;
                padding-top: 15px;
                border-top: 1px solid #cbd5e1;
                font-size: 0.8rem;
                color: #64748b;
            }

            .print-signature-box {
                border-top: 1px solid #0f172a;
                width: 200px;
                text-align: center;
                padding-top: 6px;
                font-weight: 600;
                color: #0f172a;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $activePage = 'reporte_ventas'; require_once __DIR__ . '/partials/sidebar.php'; ?>

        <main class="main-content">
            <!-- Encabezado de Impresión Formal -->
            <div class="print-header">
                <div class="print-header-brand">
                    <img src="img/Logo.jpeg" alt="Logo Lavandería" class="print-logo">
                    <div class="print-header-titles">
                        <h1>REPORTE DE VENTAS & RENTABILIDAD</h1>
                        <p>Desglose por Producto, Costo de Compra y Margen de Utilidad</p>
                    </div>
                </div>
                <div class="print-meta-box">
                    <div><strong>LAVANDERÍA VERA</strong></div>
                    <div><strong>Fecha:</strong> <?= date('d/m/Y H:i') ?></div>
                    <div><strong>Generado por:</strong> <?= htmlspecialchars($_SESSION['nombre'] ?? 'Administrador') ?></div>
                </div>
            </div>

            <!-- KPIs de Impresión -->
            <div class="print-kpis">
                <div class="print-kpi-item">
                    <span class="print-kpi-label">Total Ventas</span>
                    <span class="print-kpi-val" id="print-kpi-ventas">$<?= number_format($kpis['total_ventas'], 2) ?></span>
                </div>
                <div class="print-kpi-item">
                    <span class="print-kpi-label">Costo Total</span>
                    <span class="print-kpi-val" id="print-kpi-costos">$<?= number_format($kpis['total_costo'], 2) ?></span>
                </div>
                <div class="print-kpi-item">
                    <span class="print-kpi-label">Utilidad Neta</span>
                    <span class="print-kpi-val" id="print-kpi-utilidad">$<?= number_format($kpis['utilidad_neta'], 2) ?></span>
                </div>
                <div class="print-kpi-item">
                    <span class="print-kpi-label">Margen Promedio</span>
                    <span class="print-kpi-val" id="print-kpi-margen"><?= number_format($kpis['margen_promedio'], 2) ?>%</span>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════
                 KPI CARDS (PANTALLA)
            ═══════════════════════════════════════════════════ -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon stat-icon-ventas">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="1" x2="12" y2="23"/>
                            <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value" id="kpi-total-ventas">$<?= number_format($kpis['total_ventas'], 2) ?></div>
                        <div class="stat-label">Total en Ventas</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon stat-icon-costos">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="7" width="20" height="14" rx="2" ry="2"/>
                            <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value" style="color: #fbbf24;" id="kpi-total-costos">$<?= number_format($kpis['total_costo'], 2) ?></div>
                        <div class="stat-label">Costo de Compra Total</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon stat-icon-utilidad">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/>
                            <polyline points="17 6 23 6 23 12"/>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value" style="color: #38bdf8;" id="kpi-utilidad-neta">$<?= number_format($kpis['utilidad_neta'], 2) ?></div>
                        <div class="stat-label">Utilidad Neta Generada</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon stat-icon-margen">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="m4.93 4.93 4.24 4.24"/>
                            <path d="m14.83 14.83 4.24 4.24"/>
                            <circle cx="9" cy="9" r="1"/>
                            <circle cx="15" cy="15" r="1"/>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value" style="color: #c084fc;" id="kpi-margen-promedio"><?= number_format($kpis['margen_promedio'], 2) ?>%</div>
                        <div class="stat-label">Margen de Utilidad Prom.</div>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════
                 TOOLBAR DE FILTROS & ACCIONES
            ═══════════════════════════════════════════════════ -->
            <div class="toolbar-card">
                <!-- Fila 1: Filtros de fechas y accesos rápidos -->
                <div class="toolbar-row">
                    <div class="filters-group">
                        <span style="font-size:0.82rem; font-weight:600; color:var(--text-muted); display:flex; align-items:center; gap:0.3rem;">
                            📅 Fechas:
                        </span>
                        <input type="date" id="filtro-fecha-inicio" class="input-date" title="Fecha Desde" value="<?= htmlspecialchars($fechaInicio) ?>" onchange="aplicarFiltros()">
                        <span style="color:var(--text-muted); font-size:0.85rem;">a</span>
                        <input type="date" id="filtro-fecha-fin" class="input-date" title="Fecha Hasta" value="<?= htmlspecialchars($fechaFin) ?>" onchange="aplicarFiltros()">

                        <div style="display:flex; gap:0.35rem; margin-left:0.3rem;">
                            <button type="button" class="btn-quick-filter" onclick="setRangoFecha('hoy')">Hoy</button>
                            <button type="button" class="btn-quick-filter" onclick="setRangoFecha('semana')">Esta Semana</button>
                            <button type="button" class="btn-quick-filter" onclick="setRangoFecha('mes')">Este Mes</button>
                            <button type="button" class="btn-quick-filter" onclick="setRangoFecha('todo')">Todo</button>
                        </div>
                    </div>

                    <div class="toolbar-actions">
                        <button type="button" class="btn-tool btn-excel" onclick="exportarExcel()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                                <line x1="8" y1="13" x2="16" y2="13"/>
                                <line x1="8" y1="17" x2="16" y2="17"/>
                            </svg>
                            Exportar Excel
                        </button>
                        <button type="button" class="btn-tool btn-print" onclick="window.print()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="6 9 6 2 18 2 18 9"/>
                                <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                                <rect x="6" y="14" width="12" height="8"/>
                            </svg>
                            Imprimir Reporte
                        </button>
                    </div>
                </div>

                <!-- Fila 2: Búsqueda y Selectores de Estado -->
                <div class="toolbar-row">
                    <input type="text" id="input-search" class="input-search" placeholder="🔍 Buscar por SKU, Código de Barras, Descripción, Ticket o Cliente..." oninput="filtrarTabla()">
                    
                    <div style="display:flex; gap:0.6rem; flex-wrap:wrap;">
                        <select id="select-estado-venta" class="select-filter" onchange="filtrarTabla()">
                            <option value="">Todas las Ventas</option>
                            <option value="ACTIVA" selected>Ventas Activas</option>
                            <option value="CANCELADA">Ventas Canceladas</option>
                        </select>

                        <select id="select-estado-pago" class="select-filter" onchange="filtrarTabla()">
                            <option value="">Todos los Pagos</option>
                            <option value="PAGADO">Pagado</option>
                            <option value="PENDIENTE">Pendiente de Pago</option>
                        </select>

                        <select id="select-tipo" class="select-filter" onchange="filtrarTabla()">
                            <option value="">Todos los Tipos</option>
                            <option value="NORMAL">Normal / Venta Directa</option>
                            <option value="SERVICIO">Servicios</option>
                            <option value="KIT">Kits de Cargas</option>
                            <option value="INSUMO">Insumos</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════
                 TABLA DE RESULTADOS
            ═══════════════════════════════════════════════════ -->
            <div class="table-container">
                <div class="table-responsive">
                    <table class="table" id="tabla-reporte-ventas">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Código de Barras</th>
                                <th>Descripción</th>
                                <th class="text-right">Costo Compra</th>
                                <th class="text-right">Precio Venta</th>
                                <th class="text-center">% Utilidad</th>
                                <th class="text-right">Utilidad Neta</th>
                                <th class="text-right">Total</th>
                                <th>Cliente</th>
                                <th class="text-center">N° Ticket</th>
                                <th class="text-center" style="min-width: 110px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-reporte-ventas">
                            <!-- Generado dinámicamente vía JavaScript -->
                        </tbody>
                    </table>
                </div>

                <!-- Estado Vacío -->
                <div id="empty-state" class="empty-state" style="display:none; text-align:center; padding:3rem 1.5rem; color:var(--text-muted);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4; margin-bottom:0.75rem;">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="8" y1="12" x2="16" y2="12"/>
                    </svg>
                    <h3 style="color:#fff; font-size:1.1rem; margin-bottom:0.25rem;">No se encontraron registros de ventas</h3>
                    <p style="font-size:0.85rem;">Prueba modificando el rango de fechas o los términos de búsqueda.</p>
                </div>

                <!-- Barra de Paginación -->
                <div class="pagination-bar" id="pagination-bar">
                    <div class="pagination-info">
                        Mostrando <span id="pag-start">0</span> a <span id="pag-end">0</span> de <span id="pag-total">0</span> registros
                        <span id="pag-filtered-info" style="display:none; color:#38bdf8; margin-left:0.5rem;">(filtrados de <span id="pag-total-raw">0</span>)</span>
                    </div>

                    <div style="display:flex; align-items:center; gap:0.75rem;">
                        <div style="display:flex; align-items:center; gap:0.4rem; font-size:0.82rem; color:var(--text-muted);">
                            <span>Mostrar:</span>
                            <select id="select-page-size" class="select-filter" style="padding:0.25rem 0.5rem; font-size:0.8rem;" onchange="cambiarPageSize(this.value)">
                                <option value="15">15</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                                <option value="all">Todos</option>
                            </select>
                        </div>

                        <div class="pagination-controls" id="pagination-controls">
                            <!-- Botones numéricos generados dinámicamente -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pie de Impresión Formal -->
            <div class="print-signature-footer">
                <div>Documento generado por el Sistema de Control & Gestión &mdash; Lavandería Vera</div>
                <div class="print-signature-box">Firma / Auditoría</div>
            </div>
        </main>
    </div>

    <!-- ══════════════════════════════════════════════════
         MODAL DE TICKET TÉRMICO
    ═══════════════════════════════════════════════════ -->
    <div id="ticketModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-header">
                <h2>
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#38bdf8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="6 9 6 2 18 2 18 9"/>
                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                        <rect x="6" y="14" width="12" height="8"/>
                    </svg>
                    Ticket de Venta
                </h2>
                <button class="modal-close" onclick="cerrarModalTicket()" title="Cerrar">✕</button>
            </div>
            <div class="modal-body">
                <div id="ticketContent"></div>
            </div>
            <div class="modal-footer">
                <button onclick="cerrarModalTicket()" style="flex:1; padding:0.6rem; background:rgba(255,255,255,0.06); border:1px solid var(--border); color:var(--text-muted); border-radius:8px; font-size:0.85rem; font-weight:600; cursor:pointer; transition:all 0.15s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color=''">Cerrar</button>
                <button onclick="imprimirTicketDirecto()" style="flex:1; padding:0.6rem; background:rgba(56,189,248,0.18); border:1px solid rgba(56,189,248,0.45); color:#38bdf8; border-radius:8px; font-size:0.85rem; font-weight:700; cursor:pointer; transition:all 0.15s; display:flex; align-items:center; justify-content:center; gap:0.4rem;" onmouseover="this.style.background='rgba(56,189,248,0.3)'; this.style.color='#fff';" onmouseout="this.style.background='rgba(56,189,248,0.18)'; this.style.color='#38bdf8';">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Imprimir Ticket
                </button>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════
         JAVASCRIPT DE CONTROL Y RENDER
    ═══════════════════════════════════════════════════ -->
    <script>
        const rawData = <?= $ventasJson ?> || [];
        let filteredData = [...rawData];

        let currentPage = 1;
        let pageSize = 15;

        // Inicialización
        document.addEventListener('DOMContentLoaded', () => {
            filtrarTabla();
        });

        // ── Filtrado General ──
        function filtrarTabla() {
            const searchVal   = document.getElementById('input-search').value.toLowerCase().trim();
            const estadoVenta = document.getElementById('select-estado-venta').value;
            const estadoPago  = document.getElementById('select-estado-pago').value;
            const tipoProd    = document.getElementById('select-tipo').value;

            filteredData = rawData.filter(item => {
                // Filtro Estado Venta
                if (estadoVenta !== '' && item.venta_estado !== estadoVenta) return false;

                // Filtro Estado Pago
                if (estadoPago !== '' && item.estado_pago !== estadoPago) return false;

                // Filtro Tipo Producto
                if (tipoProd !== '' && item.producto_tipo !== tipoProd) return false;

                // Filtro Buscador
                if (searchVal !== '') {
                    const sku     = (item.sku || '').toLowerCase();
                    const barcode = (item.codigo_barras || '').toLowerCase();
                    const desc    = (item.descripcion || '').toLowerCase();
                    const ticket  = (item.numero_ticket || '').toLowerCase();
                    const cli     = (item.cliente_nombre || '').toLowerCase();

                    const match = sku.includes(searchVal) ||
                                  barcode.includes(searchVal) ||
                                  desc.includes(searchVal) ||
                                  ticket.includes(searchVal) ||
                                  cli.includes(searchVal);
                    if (!match) return false;
                }

                return true;
            });

            currentPage = 1;
            renderTabla();
            actualizarKpisVisuales();
        }

        // ── Renderizado de la Tabla ──
        function renderTabla() {
            const tbody = document.getElementById('tbody-reporte-ventas');
            const empty = document.getElementById('empty-state');
            const pagBar = document.getElementById('pagination-bar');

            if (filteredData.length === 0) {
                tbody.innerHTML = '';
                empty.style.display = 'block';
                pagBar.style.display = 'none';
                return;
            }

            empty.style.display = 'none';
            pagBar.style.display = 'flex';

            const total = filteredData.length;
            const limit = pageSize === 'all' ? total : parseInt(pageSize);
            const totalPages = Math.ceil(total / limit) || 1;

            if (currentPage > totalPages) currentPage = totalPages;

            const startIndex = (currentPage - 1) * limit;
            const endIndex   = Math.min(startIndex + limit, total);
            const pageData   = filteredData.slice(startIndex, endIndex);

            let html = '';
            pageData.forEach(item => {
                const sku = escapeHtml(item.sku || 'S/SKU');
                const barcode = escapeHtml(item.codigo_barras || 'S/CÓDIGO');
                const desc = escapeHtml(item.descripcion || '');
                const costo = parseFloat(item.costo_compra || 0);
                const precio = parseFloat(item.precio_venta || 0);
                const pct = parseFloat(item.porcentaje_utilidad || 0);
                const util = parseFloat(item.utilidad_neta || 0);
                const totalRow = parseFloat(item.total || 0);
                const cliente = escapeHtml(item.cliente_nombre || 'Público General');
                const ticket = escapeHtml(item.numero_ticket || '');

                // Badge Porcentaje de Utilidad
                let badgePctClass = 'badge-utilidad-alta';
                if (pct < 0) {
                    badgePctClass = 'badge-utilidad-negativa';
                } else if (pct < 15) {
                    badgePctClass = 'badge-utilidad-baja';
                } else if (pct <= 35) {
                    badgePctClass = 'badge-utilidad-media';
                }

                // Utilidad Neta estilo
                const utilStyle = util < 0 ? 'color:#f87171; font-weight:700;' : 'color:#38bdf8; font-weight:700;';

                html += `
                    <tr>
                        <td><span class="badge-sku">${sku}</span></td>
                        <td><span class="badge-barcode">${barcode}</span></td>
                        <td>
                            <div style="font-weight:600; color:#fff;">${desc}</div>
                            ${item.cantidad > 1 ? `<div style="font-size:0.72rem; color:var(--text-muted);">${item.cantidad} unidades @ $${precio.toFixed(2)}</div>` : ''}
                        </td>
                        <td class="text-right" style="color:#94a3b8; font-family:'JetBrains Mono',monospace;">$${costo.toFixed(2)}</td>
                        <td class="text-right" style="color:#e2e8f0; font-family:'JetBrains Mono',monospace; font-weight:600;">$${precio.toFixed(2)}</td>
                        <td class="text-center">
                            <span class="${badgePctClass}">${pct.toFixed(2)}%</span>
                        </td>
                        <td class="text-right" style="font-family:'JetBrains Mono',monospace; ${utilStyle}">
                            $${util.toFixed(2)}
                        </td>
                        <td class="text-right" style="color:#4ade80; font-family:'JetBrains Mono',monospace; font-weight:700;">
                            $${totalRow.toFixed(2)}
                        </td>
                        <td>
                            <div style="font-weight:500;">${cliente}</div>
                            ${item.venta_estado === 'CANCELADA' ? '<span style="font-size:0.68rem; color:#f87171; font-weight:700;">[CANCELADA]</span>' : ''}
                        </td>
                        <td class="text-center">
                            <span class="badge-ticket">${ticket}</span>
                        </td>
                        <td class="text-center">
                            <button type="button" class="btn-ver-ticket" onclick="verTicketVenta('${item.numero_ticket}')" title="Ver e imprimir ticket ${ticket}">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="6 9 6 2 18 2 18 9"/>
                                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                                    <rect x="6" y="14" width="12" height="8"/>
                                </svg>
                                Ver Ticket
                            </button>
                        </td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;

            // Info Paginación
            document.getElementById('pag-start').textContent = startIndex + 1;
            document.getElementById('pag-end').textContent   = endIndex;
            document.getElementById('pag-total').textContent = total;

            const totalRaw = rawData.length;
            const filteredInfo = document.getElementById('pag-filtered-info');
            if (total !== totalRaw) {
                filteredInfo.style.display = 'inline';
                document.getElementById('pag-total-raw').textContent = totalRaw;
            } else {
                filteredInfo.style.display = 'none';
            }

            renderPaginacion(totalPages);
        }

        // ── Controles de Paginación ──
        function renderPaginacion(totalPages) {
            const container = document.getElementById('pagination-controls');
            if (totalPages <= 1) {
                container.innerHTML = '';
                return;
            }

            let html = '';
            html += `<button class="btn-page" onclick="irPagina(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}>&laquo; Ant</button>`;

            let start = Math.max(1, currentPage - 2);
            let end   = Math.min(totalPages, currentPage + 2);

            for (let i = start; i <= end; i++) {
                html += `<button class="btn-page ${i === currentPage ? 'active' : ''}" onclick="irPagina(${i})">${i}</button>`;
            }

            html += `<button class="btn-page" onclick="irPagina(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}>Sig &raquo;</button>`;
            container.innerHTML = html;
        }

        function irPagina(page) {
            currentPage = page;
            renderTabla();
        }

        function cambiarPageSize(val) {
            pageSize = val;
            currentPage = 1;
            renderTabla();
        }

        // ── Actualizar KPIs Superiores según Filtro Activo ──
        function actualizarKpisVisuales() {
            let totalVentas = 0;
            let totalCostos = 0;
            let utilidadNeta = 0;

            filteredData.forEach(item => {
                if (item.venta_estado === 'ACTIVA') {
                    totalVentas  += parseFloat(item.total || 0);
                    totalCostos  += parseFloat(item.costo_total || 0);
                    utilidadNeta += parseFloat(item.utilidad_neta || 0);
                }
            });

            const margenPromedio = totalVentas > 0 ? (utilidadNeta / totalVentas) * 100 : 0;

            // Formato moneda
            const fmt = (num) => '$' + num.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const fmtPct = (num) => num.toFixed(2) + '%';

            document.getElementById('kpi-total-ventas').textContent   = fmt(totalVentas);
            document.getElementById('kpi-total-costos').textContent   = fmt(totalCostos);
            document.getElementById('kpi-utilidad-neta').textContent  = fmt(utilidadNeta);
            document.getElementById('kpi-margen-promedio').textContent = fmtPct(margenPromedio);

            // También para la vista de impresión
            document.getElementById('print-kpi-ventas').textContent   = fmt(totalVentas);
            document.getElementById('print-kpi-costos').textContent   = fmt(totalCostos);
            document.getElementById('print-kpi-utilidad').textContent = fmt(utilidadNeta);
            document.getElementById('print-kpi-margen').textContent   = fmtPct(margenPromedio);
        }

        // ── Rango de Fechas Rápidos ──
        function setRangoFecha(tipo) {
            const hoy = new Date();
            const format = (d) => d.toISOString().split('T')[0];

            let fInicio = '';
            let fFin = format(hoy);

            if (tipo === 'hoy') {
                fInicio = fFin;
            } else if (tipo === 'semana') {
                const day = hoy.getDay() || 7;
                const lunes = new Date(hoy);
                lunes.setDate(hoy.getDate() - day + 1);
                fInicio = format(lunes);
            } else if (tipo === 'mes') {
                const primerDia = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
                fInicio = format(primerDia);
            } else if (tipo === 'todo') {
                fInicio = '';
                fFin = '';
            }

            document.getElementById('filtro-fecha-inicio').value = fInicio;
            document.getElementById('filtro-fecha-fin').value = fFin;

            aplicarFiltros();
        }

        function aplicarFiltros() {
            const fInicio = document.getElementById('filtro-fecha-inicio').value;
            const fFin    = document.getElementById('filtro-fecha-fin').value;
            const estado  = document.getElementById('select-estado-venta').value;

            const params = new URLSearchParams();
            if (fInicio) params.set('fecha_inicio', fInicio);
            if (fFin)    params.set('fecha_fin', fFin);
            if (estado)  params.set('estado_venta', estado);

            window.location.href = 'reporte_ventas.php?' + params.toString();
        }

        // ── Modal de Ticket Térmico ──
        async function verTicketVenta(folio) {
            const contentEl = document.getElementById('ticketContent');
            contentEl.innerHTML = '<div style="text-align:center; padding:2rem; color:#94a3b8;">Cargando ticket de venta...</div>';
            document.getElementById('ticketModal').classList.add('active');

            try {
                const res = await fetch(`api/pos_api.php?action=obtener_ticket&venta_id=${encodeURIComponent(folio)}`);
                const data = await res.json();
                if (data.success && data.ticket) {
                    renderTicketModal(data.ticket);
                } else {
                    contentEl.innerHTML = `<div style="text-align:center; padding:2rem; color:#fca5a5;">No se encontró información del ticket <strong>${escapeHtml(folio)}</strong>.</div>`;
                }
            } catch (e) {
                contentEl.innerHTML = '<div style="text-align:center; padding:2rem; color:#fca5a5;">Error al comunicarse con el servidor.</div>';
            }
        }

        function renderTicketModal(ticket) {
            const isPagado = ticket.estado_pago === 'PAGADO';
            const detalles = ticket.detalles || [];

            document.getElementById('ticketContent').innerHTML = `
                <div class="hist-ticket-paper" id="ticket-print-wrapper">
                    <div class="hist-ticket-center">
                        <h2 style="font-size:1.15rem; font-weight:800; letter-spacing:0.02em;">LAVANDERÍA VERA</h2>
                        <p style="font-size:0.7rem; color:#444;">Tu ropa limpia, fresca y a tiempo</p>
                        <p style="font-size:0.7rem; color:#444;">Tel: (999) 000-0000</p>
                        <div class="hist-ticket-divider"></div>
                        <div style="font-weight:700; font-size:0.95rem;">FOLIO: ${ticket.folio}</div>
                        ${ticket.numero_carga ? `<div style="font-weight:800; color:#000; font-size:0.85rem; background:#f0f0f0; padding:2px 4px; margin:3px 0; border:1px solid #ccc;">CARGA DE LAVADO: ${ticket.numero_carga}</div>` : ''}
                        <p style="font-size:0.7rem; color:#555;">Fecha: ${ticket.fecha_venta}</p>
                        <p style="font-size:0.7rem; color:#555;">Cajero: ${escapeHtml(ticket.cajero_nombre || 'General')}</p>
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

        document.getElementById('ticketModal').addEventListener('click', function(e) {
            if (e.target === this) cerrarModalTicket();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') cerrarModalTicket();
        });

        // ── Impresión Térmica Directa del Ticket ──
        function imprimirTicketDirecto() {
            const ticketEl = document.getElementById('ticket-print-wrapper');
            if (!ticketEl) return;

            const printWin = window.open('', '_blank', 'width=400,height=620');
            printWin.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Ticket de Venta</title>
                    <style>
                        @page { size: 80mm auto; margin: 0; }
                        body {
                            margin: 0;
                            padding: 10px 8px;
                            font-family: 'JetBrains Mono', 'Courier New', monospace;
                            font-size: 11px;
                            color: #000;
                            line-height: 1.35;
                        }
                        h2 { margin: 0; font-size: 13px; text-align: center; }
                        p { margin: 2px 0; }
                        table { width: 100%; border-collapse: collapse; }
                        th, td { padding: 2px 0; font-size: 10px; }
                        th { border-bottom: 1px dashed #000; }
                        .divider { border-top: 1px dashed #000; margin: 6px 0; }
                        .center { text-align: center; }
                    </style>
                </head>
                <body>
                    ${ticketEl.innerHTML.replace(/hist-ticket-divider/g, 'divider').replace(/hist-ticket-center/g, 'center')}
                </body>
                </html>
            `);
            printWin.document.close();
            printWin.onload = () => {
                printWin.print();
                printWin.close();
            };
        }

        // ── Exportación a Excel con SheetJS ──
        function exportarExcel() {
            if (filteredData.length === 0) {
                alert('No hay datos para exportar.');
                return;
            }

            const exportData = filteredData.map(item => ({
                "SKU":                  item.sku || 'S/SKU',
                "Código de Barras":     item.codigo_barras || 'S/CÓDIGO',
                "Descripción":          item.descripcion || '',
                "Tipo":                 item.producto_tipo || '',
                "Costo de Compra ($)":  parseFloat(item.costo_compra || 0),
                "Precio de Venta ($)":  parseFloat(item.precio_venta || 0),
                "Porcentaje Utilidad":  parseFloat(item.porcentaje_utilidad || 0) + '%',
                "Utilidad Neta ($)":    parseFloat(item.utilidad_neta || 0),
                "Total Venta ($)":      parseFloat(item.total || 0),
                "Cantidad":             parseFloat(item.cantidad || 0),
                "Cliente":              item.cliente_nombre || 'Público General',
                "N° Ticket":            item.numero_ticket || '',
                "Fecha Venta":          item.fecha_venta || '',
                "Estado Venta":         item.venta_estado || '',
                "Estado Pago":          item.estado_pago || ''
            }));

            const ws = XLSX.utils.json_to_sheet(exportData);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Reporte de Ventas");

            const fechaStr = new Date().toISOString().slice(0, 10);
            XLSX.writeFile(wb, `Reporte_Ventas_Lavanderia_${fechaStr}.xlsx`);
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str || '';
            return div.innerHTML;
        }
    </script>
    <script src="js/sidebar.js"></script>
</body>
</html>

