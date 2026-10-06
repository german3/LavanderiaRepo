<?php
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../src/modules/auth/AuthService.php';
require_once __DIR__ . '/../src/modules/reports/ReporteStock.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;
use App\Modules\Reports\ReporteStock;

$db = (new Database())->getConnection();
$auth = new AuthService($db);
$reporteModel = new ReporteStock($db);

// Middleware de Autenticación
if (!$auth->checkAuth()) {
    header("Location: login.php");
    exit;
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$productos = $reporteModel->obtenerProductosStockMinimo();
$estadisticas = $reporteModel->obtenerEstadisticas();

$productosJson = json_encode($productos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de Stock Mínimo | Lavandería</title>
    <link rel="stylesheet" href="css/index.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--bg-card);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1.25rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
            transition: transform 0.2s ease, border-color 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stat-icon-alerta {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .stat-icon-agotado {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .stat-icon-minimo {
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .stat-info .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: #fff;
            line-height: 1.2;
        }

        .stat-info .stat-label {
            font-size: 0.825rem;
            color: var(--text-muted);
            font-weight: 500;
            margin-top: 0.2rem;
        }

        .toolbar-card {
            background: var(--bg-card);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
            justify-content: space-between;
        }

        .toolbar-search {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            flex: 1;
            min-width: 280px;
        }

        .input-search {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0.6rem 1rem;
            color: #fff;
            font-size: 0.875rem;
            flex: 1;
            min-width: 220px;
            transition: all 0.2s ease;
        }

        .input-search:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }

        .select-filter {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0.6rem 1rem;
            color: #fff;
            font-size: 0.875rem;
            outline: none;
            cursor: pointer;
        }

        .select-filter option {
            background: #1e293b;
            color: #f8fafc;
        }

        .toolbar-actions {
            display: flex;
            gap: 0.75rem;
        }

        .btn-tool {
            padding: 0.6rem 1.2rem;
            border-radius: 10px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn-excel {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .btn-excel:hover {
            background: rgba(16, 185, 129, 0.3);
            color: #fff;
            transform: translateY(-1px);
        }

        .btn-print {
            background: rgba(139, 92, 246, 0.15);
            color: #a78bfa;
            border: 1px solid rgba(139, 92, 246, 0.3);
        }

        .btn-print:hover {
            background: rgba(139, 92, 246, 0.3);
            color: #fff;
            transform: translateY(-1px);
        }

        .table-container {
            background: var(--bg-card);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            color: var(--text-main);
        }

        .table th {
            padding: 0.85rem 1rem;
            text-align: left;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            border-bottom: 2px solid var(--border);
        }

        .table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
            font-size: 0.9rem;
        }

        .table tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }

        .sku-tag {
            font-family: monospace;
            background: rgba(255, 255, 255, 0.06);
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            font-size: 0.8rem;
            color: #cbd5e1;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .badge {
            padding: 0.35rem 0.75rem;
            border-radius: 99px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            text-transform: uppercase;
        }

        .badge-agotado {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.35);
        }

        .badge-minimo {
            background: rgba(245, 158, 11, 0.2);
            color: #fde68a;
            border: 1px solid rgba(245, 158, 11, 0.35);
        }

        .badge-tipo-normal {
            background: rgba(59, 130, 246, 0.15);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .badge-tipo-kit {
            background: rgba(139, 92, 246, 0.15);
            color: #c4b5fd;
            border: 1px solid rgba(139, 92, 246, 0.3);
        }

        .btn-action-entradas {
            padding: 0.4rem 0.75rem;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            background: rgba(59, 130, 246, 0.15);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.3);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            transition: all 0.2s ease;
        }

        .btn-action-entradas:hover {
            background: rgba(59, 130, 246, 0.3);
            color: #fff;
            transform: translateY(-1px);
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            color: var(--text-muted);
        }

        .empty-state svg {
            width: 56px;
            height: 56px;
            margin-bottom: 1rem;
            color: #34d399;
            opacity: 0.8;
        }

        .empty-state h3 {
            color: #fff;
            font-size: 1.2rem;
            margin-bottom: 0.4rem;
        }

        /* Estilos de Impresión */
        @media print {
            body {
                background: #fff !important;
                color: #000 !important;
            }

            .sidebar, .toolbar-card, .btn-action-entradas, .sidebar-toggle, button {
                display: none !important;
            }

            .main-content {
                margin-left: 0 !important;
                padding: 1rem !important;
                width: 100% !important;
            }

            .table-container, .stat-card {
                background: #fff !important;
                border: 1px solid #ccc !important;
                box-shadow: none !important;
                color: #000 !important;
            }

            .table th {
                color: #000 !important;
                border-bottom: 2px solid #000 !important;
            }

            .table td {
                color: #000 !important;
                border-bottom: 1px solid #eee !important;
            }

            .sku-tag {
                background: #f1f5f9 !important;
                color: #000 !important;
                border: 1px solid #cbd5e1 !important;
            }

            .badge {
                border: 1px solid #000 !important;
                color: #000 !important;
                background: transparent !important;
            }

            .print-header {
                display: block !important;
                margin-bottom: 1.5rem;
                border-bottom: 2px solid #000;
                padding-bottom: 0.75rem;
            }
        }

        .print-header {
            display: none;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $activePage = 'reporte_stock_minimo'; require_once __DIR__ . '/partials/sidebar.php'; ?>

        <main class="main-content">
            <!-- Encabezado exclusivo de Impresión -->
            <div class="print-header">
                <h1 style="font-size: 1.6rem; font-weight: 700; margin-bottom: 0.2rem;">LAVANDERÍA VERA — REPORTE DE STOCK MÍNIMO</h1>
                <p style="font-size: 0.85rem;">Fecha de emisión: <?= date('d/m/Y H:i') ?> | Total de productos en alerta: <?= count($productos) ?></p>
            </div>

            <!-- Encabezado Pantalla -->
            <!-- <header style="margin-bottom: 2rem;">
                <div>
                    <h1 style="font-size: 1.8rem; color: #fff; display: flex; align-items: center; gap: 0.6rem;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                            <line x1="12" y1="9" x2="12" y2="13"/>
                            <line x1="12" y1="17" x2="12.01" y2="17"/>
                        </svg>
                        Reporte de Productos en Stock Mínimo
                    </h1>
                    <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 0.3rem;">
                        Muestra todos los productos cuyo inventario actual es menor o igual al stock mínimo registrado para reposición inmediata.
                    </p>
                </div>
            </header> -->

            <!-- Tarjetas de Métricas -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon stat-icon-alerta">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                            <line x1="12" y1="9" x2="12" y2="13"/>
                            <line x1="12" y1="17" x2="12.01" y2="17"/>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $estadisticas['total_alerta'] ?></div>
                        <div class="stat-label">Total en Alerta de Stock</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon stat-icon-agotado">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="15" y1="9" x2="9" y2="15"/>
                            <line x1="9" y1="9" x2="15" y2="15"/>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value" style="color: #f87171;"><?= $estadisticas['agotados'] ?></div>
                        <div class="stat-label">Productos Agotados (Stock 0)</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon stat-icon-minimo">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 9v4"/>
                            <path d="M12 17h.01"/>
                            <rect x="3" y="3" width="18" height="18" rx="2"/>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value" style="color: #60a5fa;"><?= $estadisticas['en_minimo'] ?></div>
                        <div class="stat-label">En Nivel Mínimo Crítico</div>
                    </div>
                </div>
            </div>

            <!-- Toolbar de Filtros y Acciones -->
            <div class="toolbar-card">
                <div class="toolbar-search">
                    <input type="text" id="input-search" class="input-search" placeholder="🔍 Buscar por SKU, Producto o Código de Barras..." oninput="filtrarTabla()">
                    <select id="select-estado" class="select-filter" onchange="filtrarTabla()">
                        <option value="">Todos los estados</option>
                        <option value="AGOTADO">Agotados (Stock <= 0)</option>
                        <option value="MINIMO">En Stock Mínimo (> 0)</option>
                    </select>
                    <!-- <select id="select-tipo" class="select-filter" onchange="filtrarTabla()">
                        <option value="">Todos los tipos</option>
                        <option value="NORMAL">Insumos / Normal</option>
                        <option value="KIT">Kits de Servicio</option>
                    </select> -->
                </div>
                <div class="toolbar-actions">
                    <button class="btn-tool btn-excel" onclick="exportarExcel()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                            <line x1="8" y1="13" x2="16" y2="13"/>
                            <line x1="8" y1="17" x2="16" y2="17"/>
                        </svg>
                        Exportar Excel
                    </button>
                    <button class="btn-tool btn-print" onclick="window.print()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="6 9 6 2 18 2 18 9"/>
                            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                            <rect x="6" y="14" width="12" height="8"/>
                        </svg>
                        Imprimir Reporte
                    </button>
                </div>
            </div>

            <!-- Tabla de Resultados -->
            <div class="table-container">
                <table class="table" id="tabla-reporte">
                    <thead>
                        <tr>
                            <th>SKU / Cód. Barras</th>
                            <th>Producto / Descripción</th>
                            <th>Tipo</th>
                            <th>U. Medida</th>
                            <th>Stock Actual</th>
                            <th>Stock Mínimo</th>
                            <th>Faltante Sugerido</th>
                            <th>Estado</th>
                            <!-- <th style="text-align: right;">Acciones</th> -->
                        </tr>
                    </thead>
                    <tbody id="tbody-reporte">
                        <!-- Generado dinámicamente vía JavaScript -->
                    </tbody>
                </table>

                <div id="empty-state" class="empty-state" style="display: none;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <h3>¡Inventario en óptimo estado!</h3>
                    <p>No se encontraron productos con stock igual o menor a su límite mínimo configurado.</p>
                </div>
            </div>
        </main>
    </div>

    <script src="js/sidebar.js"></script>
    <script>
        const productosData = <?= $productosJson ?>;

        function renderTabla(datos) {
            const tbody = document.getElementById('tbody-reporte');
            const emptyState = document.getElementById('empty-state');
            const tabla = document.getElementById('tabla-reporte');

            tbody.innerHTML = '';

            if (!datos || datos.length === 0) {
                tabla.style.display = 'none';
                emptyState.style.display = 'block';
                return;
            }

            tabla.style.display = 'table';
            emptyState.style.display = 'none';

            datos.forEach(p => {
                const tr = document.createElement('tr');

                const stockActual = parseFloat(p.stock_cantidad || 0);
                const stockMinimo = parseFloat(p.stock_minimo || 0);
                const faltante = Math.max(0, stockMinimo - stockActual);

                const esAgotado = stockActual <= 0;
                const badgeEstado = esAgotado 
                    ? `<span class="badge badge-agotado"><svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="10"/></svg> AGOTADO</span>`
                    : `<span class="badge badge-minimo"><svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="10"/></svg> STOCK MÍNIMO</span>`;

                const badgeTipo = p.tipo === 'KIT'
                    ? `<span class="badge badge-tipo-kit">KIT</span>`
                    : `<span class="badge badge-tipo-normal">NORMAL</span>`;

                const codigoBarrasHtml = p.codigo_barras ? `<br><small style="color: var(--text-muted); font-size: 0.75rem;">EAN: ${escapeHtml(p.codigo_barras)}</small>` : '';

                tr.innerHTML = `
                    <td>
                        <span class="sku-tag">${escapeHtml(p.codigo_interno_sku || 'N/A')}</span>
                        ${codigoBarrasHtml}
                    </td>
                    <td style="font-weight: 600; color: #fff;">
                        ${escapeHtml(p.descripcion)}
                    </td>
                    <td>${badgeTipo}</td>
                    <td><span style="font-weight: 500; color: var(--text-muted);">${escapeHtml(p.unidad_medida || 'PIEZA')}</span></td>
                    <td style="font-weight: 700; color: ${esAgotado ? '#f87171' : '#fbbf24'}; font-size: 0.95rem;">
                        ${stockActual.toFixed(2)}
                    </td>
                    <td style="font-weight: 600; color: #cbd5e1;">
                        ${stockMinimo.toFixed(2)}
                    </td>
                    <td style="font-weight: 600; color: #60a5fa;">
                        +${faltante.toFixed(2)}
                    </td>
                    <td>${badgeEstado}</td>
                    
                `;
                tbody.appendChild(tr);
            });
        }

        function filtrarTabla() {
            const query = document.getElementById('input-search').value.toLowerCase().trim();
            const estado = document.getElementById('select-estado').value;
            const selectTipoElem = document.getElementById('select-tipo');
            const tipo = selectTipoElem ? selectTipoElem.value : '';

            const filtrados = productosData.filter(p => {
                const matchQuery = !query || 
                    (p.descripcion && p.descripcion.toLowerCase().includes(query)) ||
                    (p.codigo_interno_sku && p.codigo_interno_sku.toLowerCase().includes(query)) ||
                    (p.codigo_barras && p.codigo_barras.toLowerCase().includes(query));

                const stock = parseFloat(p.stock_cantidad || 0);
                const matchEstado = !estado || 
                    (estado === 'AGOTADO' && stock <= 0) ||
                    (estado === 'MINIMO' && stock > 0);

                const matchTipo = !tipo || p.tipo === tipo;

                return matchQuery && matchEstado && matchTipo;
            });

            renderTabla(filtrados);
        }

        function escapeHtml(text) {
            if (!text) return '';
            return String(text)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        function exportarExcel() {
            if (!productosData || productosData.length === 0) {
                alert("No hay productos en la lista para exportar.");
                return;
            }

            const dataExport = productosData.map(p => {
                const stock = parseFloat(p.stock_cantidad || 0);
                const minimo = parseFloat(p.stock_minimo || 0);
                return {
                    "SKU": p.codigo_interno_sku || '',
                    "Código Barras": p.codigo_barras || '',
                    "Producto": p.descripcion || '',
                    "Tipo": p.tipo || 'NORMAL',
                    "Unidad Medida": p.unidad_medida || 'PIEZA',
                    "Stock Actual": stock,
                    "Stock Mínimo": minimo,
                    "Faltante Sugerido": Math.max(0, minimo - stock),
                    "Estado": stock <= 0 ? 'AGOTADO' : 'STOCK MÍNIMO'
                };
            });

            const ws = XLSX.utils.json_to_sheet(dataExport);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Stock Mínimo");

            const fecha = new Date().toISOString().slice(0,10);
            XLSX.writeFile(wb, `Reporte_Stock_Minimo_${fecha}.xlsx`);
        }

        // Render inicial
        document.addEventListener('DOMContentLoaded', () => {
            renderTabla(productosData);
        });
    </script>
</body>
</html>
