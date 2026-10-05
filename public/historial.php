<?php
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../src/modules/auth/AuthService.php';
require_once __DIR__ . '/../src/modules/catalog/Historial.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;
use App\Modules\Catalog\Historial;

$db = (new Database())->getConnection();
$auth = new AuthService($db);
$historialModel = new Historial($db);

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
    </style>
</head>
<body>
    <div class="app-container">
        <?php $activePage = 'historial'; require_once __DIR__ . '/partials/sidebar.php'; ?>

        <main class="main-content">
            <header style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 2rem;">
                <div>
                    <h1 style="font-size: 1.8rem; color: #fff;">Historial de Movimientos</h1>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Registro global de altas, entradas, salidas y bajas de productos.</p>
                </div>
                <div style="display:flex; align-items:center; gap: 1rem;">
                    <span style="color: var(--text-muted); font-size: 0.9rem;">
                        Usuario: <strong style="color: #fff;"><?php echo htmlspecialchars($_SESSION['nombre'] ?? 'Usuario'); ?></strong>
                        <span class="badge badge-normal" style="margin-left: 0.5rem; text-transform: uppercase;">
                            <?php echo htmlspecialchars($_SESSION['rol'] ?? 'ROL'); ?>
                        </span>
                    </span>
                    <a href="logout.php" style="color: #ef4444; text-decoration: none; font-size: 0.85rem; font-weight: 600; padding: 0.4rem 0.8rem; border: 1px solid rgba(239,68,68,0.3); border-radius: 6px; background: rgba(239,68,68,0.1);">Cerrar Sesión</a>
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
                    <div style="display: flex; gap: 0.75rem; flex: 1; min-width: 300px;">
                        <div style="position: relative; flex: 1;">
                            <input type="text" id="searchInput" class="form-control" placeholder="Buscar por SKU, producto, tipo o usuario..." style="width: 100%; padding: 0.4rem 0.75rem 0.4rem 2.2rem; background: rgba(15, 23, 42, 0.6); color: var(--text-main); border: 1px solid var(--border); border-radius: 8px; height: 38px; font-size: 0.85rem; line-height: 1.2; box-sizing: border-box;">
                            <span style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); opacity: 0.5;">🔍</span>
                        </div>
                        <select id="tipoFilter" class="form-control" style="width: 210px; padding: 0.4rem 0.75rem; height: 38px; background: #1e293b; color: #f1f5f9; border: 1px solid var(--border); border-radius: 8px; font-size: 0.85rem; line-height: 1.2; cursor: pointer; box-sizing: border-box;">
                            <option value="" style="background:#1e293b; color:#f1f5f9;">Todos los movimientos</option>
                            <option value="ALTA PRODUCTO" style="background:#1e293b; color:#f1f5f9;">Alta de Producto</option>
                            <option value="ENTRADA STOCK" style="background:#1e293b; color:#f1f5f9;">Entrada de Stock</option>
                            <option value="SALIDA STOCK" style="background:#1e293b; color:#f1f5f9;">Salida de Stock</option>
                            <option value="ELIMINACION PRODUCTO" style="background:#1e293b; color:#f1f5f9;">Eliminación</option>
                            <option value="EDICION PRODUCTO" style="background:#1e293b; color:#f1f5f9;">Edición</option>
                        </select>
                    </div>

                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <span style="font-size: 0.85rem; color: var(--text-muted);">Mostrar:</span>
                        <select id="pageSizeSelect" class="form-control" style="width: 80px; padding: 0.4rem 0.5rem; height: 38px; background: #1e293b; color: #f1f5f9; border: 1px solid var(--border); border-radius: 8px; font-size: 0.85rem; text-align: center; cursor: pointer; box-sizing: border-box; line-height: 1.2;">
                            <option value="10" selected style="background:#1e293b; color:#f1f5f9;">10</option>
                            <option value="15" style="background:#1e293b; color:#f1f5f9;">15</option>
                            <option value="20" style="background:#1e293b; color:#f1f5f9;">20</option>
                            <option value="25" style="background:#1e293b; color:#f1f5f9;">25</option>
                            <option value="30" style="background:#1e293b; color:#f1f5f9;">30</option>
                        </select>
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
                                    Tipo <span class="sort-icon" id="sort-tipo_movimiento">⇅</span>
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
                                <th>Motivo / Notas</th>
                                <th class="sort-header" onclick="sortData('usuario_nombre')">
                                    Usuario <span class="sort-icon" id="sort-usuario_nombre">⇅</span>
                                </th>
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
                case 'ALTA PRODUCTO': return 'badge-alta';
                case 'ENTRADA STOCK': return 'badge-entrada';
                case 'SALIDA STOCK': return 'badge-salida';
                case 'ELIMINACION PRODUCTO': return 'badge-eliminacion';
                case 'EDICION PRODUCTO': return 'badge-edicion';
                default: return 'badge-alta';
            }
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
                const matchTipoFilter = !tipoFilter || item.tipo_movimiento === tipoFilter;

                const sku     = (item.producto_sku         || '').toLowerCase();
                const desc    = (item.producto_descripcion || '').toLowerCase();
                const tipoMov = (item.tipo_movimiento      || '').toLowerCase();
                const usuario = (item.usuario_nombre       || '').toLowerCase();
                const motivo  = (item.motivo               || '').toLowerCase();

                const matchQuery = !query ||
                    sku.includes(query)     ||
                    desc.includes(query)    ||
                    tipoMov.includes(query) ||
                    usuario.includes(query) ||
                    motivo.includes(query);

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

                return matchTipoFilter && matchQuery && matchFecha;
            });

            // Actualizar indicador de rango
            const infoRango = document.getElementById('fecha-rango-info');
            if (infoRango) {
                if (fechaInicioVal || fechaFinVal) {
                    const desde = fechaInicioVal ? fechaInicioVal.split('-').reverse().join('/') : '…';
                    const hasta = fechaFinVal    ? fechaFinVal.split('-').reverse().join('/')    : 'hoy';
                    infoRango.textContent = `${filteredData.length} registro(s) entre ${desde} y ${hasta}`;
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
            ['fecha_hora', 'tipo_movimiento', 'producto_descripcion', 'cantidad', 'costo_unitario', 'usuario_nombre'].forEach(col => {
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
                tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; padding: 2rem; color: var(--text-muted);">No se encontraron movimientos.</td></tr>`;
            } else {
                pageData.forEach(item => {
                    const badgeClass = getBadgeClass(item.tipo_movimiento);
                    const cantidadFormatted = parseFloat(item.cantidad || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    const costoFormatted = '$' + parseFloat(item.costo_unitario || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    const stockAntesFormatted = parseFloat(item.stock_antes || 0).toFixed(2);
                    const stockDespuesFormatted = parseFloat(item.stock_despues || 0).toFixed(2);
                    
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td style="white-space: nowrap; font-size: 0.85rem; color: var(--text-muted);">
                            ${formatFecha(item.fecha_hora)}
                        </td>
                        <td>
                            <span class="badge ${badgeClass}">${item.tipo_movimiento}</span>
                        </td>
                        <td>
                            <div style="font-weight: 600; color: #fff;">${item.producto_descripcion}</div>
                            ${item.producto_sku ? `<span class="sku-tag">SKU: ${item.producto_sku}</span>` : ''}
                        </td>
                        <td style="text-align: right; font-weight: 600;">
                            ${item.tipo_movimiento === 'SALIDA STOCK' ? `<span style="color:#fda4af;">-${cantidadFormatted}</span>` : `<span style="color:#6ee7b7;">+${cantidadFormatted}</span>`}
                        </td>
                        <td style="text-align: center; font-size: 0.85rem; color: var(--text-muted);">
                            <span>${stockAntesFormatted}</span>
                            <span style="margin: 0 0.3rem; opacity: 0.5;">→</span>
                            <strong style="color: #fff;">${stockDespuesFormatted}</strong>
                        </td>
                        <td style="text-align: right; font-size: 0.85rem;">
                            ${costoFormatted}
                        </td>
                        <td style="font-size: 0.85rem; color: var(--text-muted); max-width: 200px;">
                            ${item.motivo || '-'}
                        </td>
                        <td style="font-size: 0.85rem; font-weight: 500;">
                            ${item.usuario_nombre || 'Sistema'}
                        </td>
                    `;
                    tbody.appendChild(tr);
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

        document.getElementById('searchInput').addEventListener('input', filterData);
        document.getElementById('tipoFilter').addEventListener('change', filterData);
        document.getElementById('pageSizeSelect').addEventListener('change', (e) => {
            pageSize = parseInt(e.target.value) || 10;
            currentPage = 1;
            renderTable();
        });

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
