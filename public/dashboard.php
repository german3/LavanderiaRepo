<?php
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../src/modules/auth/AuthService.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;

$db = (new Database())->getConnection();
$auth = new AuthService($db);

if (!$auth->canAccessBackoffice()) {
    header("Location: login.php");
    exit;
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (isset($_GET['logout'])) {
    $auth->logout();
    header("Location: login.php");
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. OBTENCIÓN DE DATOS PARA EL DASHBOARD
// ─────────────────────────────────────────────────────────────────────────────

// A. KPI Métricas de Hoy
$kpiHoy = [
    'total_tickets'       => 0,
    'total_ingresos'      => 0.0,
    'ticket_promedio'     => 0.0,
    'total_pagado'        => 0.0,
    'total_pendiente'     => 0.0,
    'clientes_distintos'  => 0
];

try {
    $stmtKpi = $db->query("
        SELECT 
            COUNT(v.id) AS total_tickets,
            COALESCE(SUM(v.total), 0) AS total_ingresos,
            COALESCE(AVG(v.total), 0) AS ticket_promedio,
            COALESCE(SUM(CASE WHEN v.estado_pago = 'PAGADO' THEN v.total ELSE 0 END), 0) AS total_pagado,
            COALESCE(SUM(CASE WHEN v.estado_pago = 'PENDIENTE' THEN v.total ELSE 0 END), 0) AS total_pendiente,
            COUNT(DISTINCT v.cliente_id) AS clientes_distintos
        FROM ventas v
        WHERE v.estado = 'ACTIVA' AND DATE(v.fecha_venta) = CURDATE()
    ");
    if ($row = $stmtKpi->fetch(PDO::FETCH_ASSOC)) {
        $kpiHoy = [
            'total_tickets'      => (int)$row['total_tickets'],
            'total_ingresos'     => (float)$row['total_ingresos'],
            'ticket_promedio'    => (float)$row['ticket_promedio'],
            'total_pagado'       => (float)$row['total_pagado'],
            'total_pendiente'    => (float)$row['total_pendiente'],
            'clientes_distintos' => (int)$row['clientes_distintos']
        ];
    }
} catch (\Throwable $e) {
    error_log("Dashboard KPI Hoy Error: " . $e->getMessage());
}

// B. Cargas de Lavandería Hoy
$kpiCargas = [
    'total'      => 0,
    'total_kg'   => 0.0,
    'en_espera'  => 0,
    'en_proceso' => 0,
    'listas'     => 0,
    'entregadas' => 0
];

try {
    $stmtCg = $db->query("
        SELECT 
            COUNT(cg.id) AS total,
            COALESCE(SUM(cg.ropa_kg), 0) AS total_kg,
            SUM(CASE WHEN cg.estado = 'RECIBIDA' THEN 1 ELSE 0 END) AS en_espera,
            SUM(CASE WHEN cg.estado = 'EN_PROCESO' THEN 1 ELSE 0 END) AS en_proceso,
            SUM(CASE WHEN cg.estado = 'LISTA_CONCLUIDA' THEN 1 ELSE 0 END) AS listas,
            SUM(CASE WHEN cg.estado = 'ENTREGADA' THEN 1 ELSE 0 END) AS entregadas
        FROM cargas cg
        WHERE DATE(cg.fecha_recepcion) = CURDATE()
    ");
    if ($row = $stmtCg->fetch(PDO::FETCH_ASSOC)) {
        $kpiCargas = [
            'total'      => (int)$row['total'],
            'total_kg'   => (float)$row['total_kg'],
            'en_espera'  => (int)$row['en_espera'],
            'en_proceso' => (int)$row['en_proceso'],
            'listas'     => (int)$row['listas'],
            'entregadas' => (int)$row['entregadas']
        ];
    }
} catch (\Throwable $e) {
    error_log("Dashboard Cargas Error: " . $e->getMessage());
}

// C. Top 10 Clientes Más Frecuentes
$topClientes = [];
try {
    $stmtTopCli = $db->query("
        SELECT 
            COALESCE(c.id, 'MOSTRADOR') AS cliente_id,
            COALESCE(c.nombre, 'Público General') AS cliente_nombre,
            COALESCE(c.telefono, '') AS cliente_telefono,
            COUNT(v.id) AS total_compras,
            COALESCE(SUM(v.total), 0) AS monto_total,
            MAX(v.fecha_venta) AS ultima_compra
        FROM ventas v
        LEFT JOIN clientes c ON v.cliente_id = c.id
        WHERE v.estado = 'ACTIVA'
        GROUP BY COALESCE(c.id, 'MOSTRADOR'), COALESCE(c.nombre, 'Público General'), COALESCE(c.telefono, '')
        ORDER BY total_compras DESC, monto_total DESC
        LIMIT 10
    ");
    $topClientes = $stmtTopCli->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log("Dashboard Top Clientes Error: " . $e->getMessage());
}

// D. Top 5 Productos Más Vendidos
$topProductos = [];
try {
    $stmtTopProd = $db->query("
        SELECT 
            p.id AS producto_id,
            p.descripcion AS producto_nombre,
            p.tipo AS producto_tipo,
            p.unidad_medida,
            COALESCE(SUM(dv.cantidad), 0) AS total_vendido,
            COALESCE(SUM(dv.subtotal), 0) AS total_ingresos
        FROM detalle_ventas dv
        JOIN productos p ON dv.producto_id = p.id
        JOIN ventas v ON dv.venta_id = v.id
        WHERE v.estado = 'ACTIVA'
        GROUP BY p.id, p.descripcion, p.tipo, p.unidad_medida
        ORDER BY total_vendido DESC, total_ingresos DESC
        LIMIT 5
    ");
    $topProductos = $stmtTopProd->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log("Dashboard Top Productos Error: " . $e->getMessage());
}

// E. Ventas del Día por Hora (08:00 a 20:00)
$ventasHorasRaw = [];
try {
    $stmtHoras = $db->query("
        SELECT 
            HOUR(v.fecha_venta) AS hora,
            COUNT(v.id) AS tickets,
            COALESCE(SUM(v.total), 0) AS monto
        FROM ventas v
        WHERE v.estado = 'ACTIVA' AND DATE(v.fecha_venta) = CURDATE()
        GROUP BY HOUR(v.fecha_venta)
        ORDER BY hora ASC
    ");
    while ($r = $stmtHoras->fetch(PDO::FETCH_ASSOC)) {
        $ventasHorasRaw[(int)$r['hora']] = [
            'tickets' => (int)$r['tickets'],
            'monto'   => (float)$r['monto']
        ];
    }
} catch (\Throwable $e) {
    error_log("Dashboard Horas Error: " . $e->getMessage());
}

// Llenar rango completo de 8:00 a 20:00 para gráfico continuo
$horasLabels = [];
$horasMontos = [];
$horasTickets = [];
for ($h = 8; $h <= 20; $h++) {
    $label = sprintf("%02d:00", $h);
    $horasLabels[] = $label;
    $horasMontos[] = isset($ventasHorasRaw[$h]) ? $ventasHorasRaw[$h]['monto'] : 0.0;
    $horasTickets[] = isset($ventasHorasRaw[$h]) ? $ventasHorasRaw[$h]['tickets'] : 0;
}

// F. Ventas de los Últimos 7 Días
$ventas7DiasRaw = [];
try {
    $stmt7d = $db->query("
        SELECT 
            DATE(v.fecha_venta) AS fecha,
            COUNT(v.id) AS tickets,
            COALESCE(SUM(v.total), 0) AS monto
        FROM ventas v
        WHERE v.estado = 'ACTIVA' AND v.fecha_venta >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(v.fecha_venta)
        ORDER BY fecha ASC
    ");
    while ($r = $stmt7d->fetch(PDO::FETCH_ASSOC)) {
        $ventas7DiasRaw[$r['fecha']] = [
            'tickets' => (int)$r['tickets'],
            'monto'   => (float)$r['monto']
        ];
    }
} catch (\Throwable $e) {
    error_log("Dashboard 7 Dias Error: " . $e->getMessage());
}

$diasLabels = [];
$diasMontos = [];
$diasTickets = [];
$diasNombres = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
for ($i = 6; $i >= 0; $i--) {
    $dateObj = new DateTime("-$i days");
    $fKey = $dateObj->format('Y-m-d');
    $diaSemana = $diasNombres[(int)$dateObj->format('w')];
    $diasLabels[] = $diaSemana . ' ' . $dateObj->format('d/m');
    $diasMontos[] = isset($ventas7DiasRaw[$fKey]) ? $ventas7DiasRaw[$fKey]['monto'] : 0.0;
    $diasTickets[] = isset($ventas7DiasRaw[$fKey]) ? $ventas7DiasRaw[$fKey]['tickets'] : 0;
}

// G. Ventas de Hoy por Método de Pago
$metodosPago = [];
try {
    $stmtMet = $db->query("
        SELECT 
            v.metodo_pago,
            COUNT(v.id) AS tickets,
            COALESCE(SUM(v.total), 0) AS monto
        FROM ventas v
        WHERE v.estado = 'ACTIVA' AND DATE(v.fecha_venta) = CURDATE()
        GROUP BY v.metodo_pago
    ");
    $metodosPago = $stmtMet->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log("Dashboard Metodos Error: " . $e->getMessage());
}

// Formateador de fecha en español
$diasEsp = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$mesesEsp = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$hoyTexto = $diasEsp[(int)date('w')] . ', ' . date('d') . ' de ' . $mesesEsp[(int)date('n')] . ' de ' . date('Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard General | Lavandería Vera</title>
    <link rel="stylesheet" href="css/index.css">
    <script src="js/theme.js"></script>
    <link rel="icon" type="image/jpeg" href="img/Logo.jpeg">
    <!-- Chart.js 4 (dist UMD para navegadores) -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        /* Estilos avanzados del Dashboard */
        .dash-container {
            display: flex;
            flex-direction: column;
            gap: 2rem;
            width: 100%;
        }

        /* Header con badge y acciones */
        .dash-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border);
        }

        .dash-title-group h1 {
            font-size: 2.1rem;
            font-weight: 800;
            background: linear-gradient(135deg, #60a5fa 0%, #a78bfa 50%, #f472b6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.35rem;
            letter-spacing: -0.02em;
        }

        .dash-title-group p {
            color: var(--text-muted);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .dash-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .dash-btn-pos {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            padding: 0.65rem 1.25rem;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.88rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35);
            transition: all 0.2s;
        }
        .dash-btn-pos:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.45);
        }

        .dash-btn-refresh {
            background: rgba(30, 41, 59, 0.8);
            border: 1px solid var(--border);
            color: var(--text-main);
            padding: 0.65rem 1rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.88rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            transition: all 0.2s;
        }
        .dash-btn-refresh:hover {
            background: rgba(51, 65, 85, 0.9);
            border-color: rgba(255, 255, 255, 0.2);
        }

        /* Grid de KPIs */
        .dash-kpis-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.25rem;
        }

        .dash-kpi-card {
            background: var(--bg-card);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .dash-kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 30px -10px rgba(0, 0, 0, 0.5);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .dash-kpi-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
        }
        .kpi-emerald::before { background: linear-gradient(90deg, #10b981, #34d399); }
        .kpi-blue::before    { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
        .kpi-purple::before  { background: linear-gradient(90deg, #8b5cf6, #c084fc); }
        .kpi-amber::before   { background: linear-gradient(90deg, #f59e0b, #fbbf24); }

        .kpi-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }

        .kpi-label {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
        }

        .kpi-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }
        .kpi-emerald .kpi-icon { background: rgba(16, 185, 129, 0.15); color: #34d399; }
        .kpi-blue .kpi-icon    { background: rgba(59, 130, 246, 0.15); color: #60a5fa; }
        .kpi-purple .kpi-icon  { background: rgba(139, 92, 246, 0.15); color: #c084fc; }
        .kpi-amber .kpi-icon   { background: rgba(245, 158, 11, 0.15); color: #fbbf24; }

        .kpi-value {
            font-size: 2.1rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.02em;
            margin-bottom: 0.4rem;
        }

        .kpi-subtext {
            font-size: 0.78rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.4rem;
            flex-wrap: wrap;
        }
        .kpi-subtext strong {
            color: #e2e8f0;
        }

        /* Gráficos Grid */
        .dash-charts-grid {
            display: grid;
            grid-template-columns: 1.6fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 1100px) {
            .dash-charts-grid {
                grid-template-columns: 1fr;
            }
        }

        .dash-card {
            background: var(--bg-card);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 1.75rem;
            box-shadow: 0 15px 35px -10px rgba(0, 0, 0, 0.4);
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            position: relative;
        }

        .dash-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .dash-card-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .dash-card-subtitle {
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-top: 0.2rem;
        }

        .dash-toggle-group {
            display: inline-flex;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0.25rem;
            gap: 0.2rem;
        }

        .dash-toggle-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 0.78rem;
            font-weight: 600;
            padding: 0.35rem 0.85rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .dash-toggle-btn.active {
            background: var(--primary);
            color: #fff;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.4);
        }

        .dash-chart-wrapper {
            position: relative;
            width: 100%;
            min-height: 290px;
        }

        /* Lista y Ranking de Clientes */
        .dash-ranking-list {
            display: flex;
            flex-direction: column;
            gap: 0.65rem;
            max-height: 380px;
            overflow-y: auto;
            padding-right: 0.3rem;
        }

        .dash-ranking-item {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            transition: background 0.2s, transform 0.2s;
        }
        .dash-ranking-item:hover {
            background: rgba(30, 41, 59, 0.8);
            transform: translateX(3px);
            border-color: rgba(59, 130, 246, 0.3);
        }

        .dash-rank-badge {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.82rem;
            flex-shrink: 0;
        }
        .rank-1 { background: linear-gradient(135deg, #fbbf24, #d97706); color: #000; box-shadow: 0 0 12px rgba(251, 191, 36, 0.4); }
        .rank-2 { background: linear-gradient(135deg, #94a3b8, #64748b); color: #fff; }
        .rank-3 { background: linear-gradient(135deg, #b45309, #78350f); color: #fff; }
        .rank-other { background: rgba(255, 255, 255, 0.08); color: var(--text-muted); }

        .dash-rank-info {
            flex: 1;
            overflow: hidden;
        }

        .dash-rank-name {
            font-size: 0.9rem;
            font-weight: 700;
            color: #f1f5f9;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dash-rank-tel {
            font-size: 0.72rem;
            color: var(--text-muted);
        }

        .dash-rank-metrics {
            text-align: right;
            flex-shrink: 0;
        }

        .dash-rank-orders {
            font-size: 0.85rem;
            font-weight: 800;
            color: #60a5fa;
        }

        .dash-rank-total {
            font-size: 0.72rem;
            color: #34d399;
            font-weight: 600;
        }

        /* Mini tabla o desglose de productos */
        .dash-prod-breakdown {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }
        .dash-prod-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.82rem;
            padding: 0.4rem 0.6rem;
            border-radius: 8px;
            background: rgba(15, 23, 42, 0.4);
        }
        .dash-prod-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 0.5rem;
        }

        /* Resumen de estado de lavado */
        .dash-lavado-pill-row {
            display: flex;
            gap: 0.6rem;
            flex-wrap: wrap;
            margin-top: 0.5rem;
        }
        .dash-lavado-pill {
            flex: 1 1 calc(50% - 0.6rem);
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .dash-lavado-pill-val {
            font-size: 1.2rem;
            font-weight: 800;
        }

        .empty-chart-note {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--text-muted);
            font-size: 0.85rem;
            text-align: center;
            padding: 2rem 1rem;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $activePage = 'dashboard'; require_once __DIR__ . '/partials/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="dash-container">
                
                <!-- ══════════════════════════════════════════════════════════
                     Header Principal
                     ══════════════════════════════════════════════════════════ -->
                <header class="dash-header">
                    <div class="dash-title-group">
                        <h1>Hola, <?= htmlspecialchars($_SESSION['nombre']) ?> 👋</h1>
                        <p>
                            <span>📅 <?= htmlspecialchars($hoyTexto) ?></span>
                            <span>•</span>
                            <span>Rol: <strong><?= htmlspecialchars($_SESSION['rol']) ?></strong></span>
                        </p>
                    </div>
                    <div class="dash-actions">
                        <button class="dash-btn-refresh" onclick="location.reload()" title="Actualizar datos en tiempo real">
                            <svg style="width:16px;height:16px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            <span>Actualizar</span>
                        </button>
                        <a href="pos_login.php?cambiar=1" target="_blank" class="dash-btn-pos" title="Abrir Punto de Venta">
                            <svg style="width:18px;height:18px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M8 4v4m8-4v4M2 12h20M7 16h.01M12 16h.01M17 16h.01"/></svg>
                            <span>Ir al POS</span>
                        </a>
                    </div>
                </header>

                <!-- ══════════════════════════════════════════════════════════
                     1. KPI Metric Cards
                     ══════════════════════════════════════════════════════════ -->
                <section class="dash-kpis-grid">
                    <!-- KPI 1: Ventas del Día -->
                    <div class="dash-kpi-card kpi-emerald">
                        <div class="kpi-head">
                            <span class="kpi-label">Ventas de Hoy</span>
                            <div class="kpi-icon">💰</div>
                        </div>
                        <div class="kpi-value">$<?= number_format($kpiHoy['total_ingresos'], 2) ?></div>
                        <div class="kpi-subtext">
                            <span><strong><?= $kpiHoy['total_tickets'] ?></strong> tickets emitidos</span>
                            <span>•</span>
                            <span style="color:#34d399;">$<?= number_format($kpiHoy['total_pagado'], 2) ?> pagado</span>
                            <?php if ($kpiHoy['total_pendiente'] > 0): ?>
                                <span>•</span>
                                <span style="color:#fbbf24;">$<?= number_format($kpiHoy['total_pendiente'], 2) ?> pendiente</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- KPI 2: Ticket Promedio -->
                    <div class="dash-kpi-card kpi-blue">
                        <div class="kpi-head">
                            <span class="kpi-label">Ticket Promedio</span>
                            <div class="kpi-icon">📊</div>
                        </div>
                        <div class="kpi-value">$<?= number_format($kpiHoy['ticket_promedio'], 2) ?></div>
                        <div class="kpi-subtext">
                            <span>Efectividad de venta hoy</span>
                            <span>•</span>
                            <span><strong><?= $kpiHoy['clientes_distintos'] ?></strong> clientes únicos</span>
                        </div>
                    </div>

                    <!-- KPI 3: Cargas de Lavado Hoy -->
                    <div class="dash-kpi-card kpi-purple">
                        <div class="kpi-head">
                            <span class="kpi-label">Cargas de Lavado</span>
                            <div class="kpi-icon">🧺</div>
                        </div>
                        <div class="kpi-value"><?= $kpiCargas['total'] ?> <span style="font-size:1.1rem;font-weight:600;color:var(--text-muted);">cargas</span></div>
                        <div class="kpi-subtext">
                            <span><strong><?= number_format($kpiCargas['total_kg'], 1) ?> kg</strong> de ropa procesada hoy</span>
                        </div>
                    </div>

                    <!-- KPI 4: Estado de Lavado Hoy -->
                    <div class="dash-kpi-card kpi-amber">
                        <div class="kpi-head">
                            <span class="kpi-label">Flujo de Lavado</span>
                            <div class="kpi-icon">⚙️</div>
                        </div>
                        <div class="kpi-value" style="display:flex;align-items:center;gap:0.75rem;font-size:1.6rem;">
                            <span title="En Proceso" style="color:#38bdf8;"><?= $kpiCargas['en_proceso'] ?> <small style="font-size:0.75rem;color:var(--text-muted);">proc</small></span>
                            <span style="color:var(--text-muted);font-size:1rem;">|</span>
                            <span title="Listas para Entrega" style="color:#34d399;"><?= $kpiCargas['listas'] ?> <small style="font-size:0.75rem;color:var(--text-muted);">listas</small></span>
                            <span style="color:var(--text-muted);font-size:1rem;">|</span>
                            <span title="Entregadas" style="color:#a78bfa;"><?= $kpiCargas['entregadas'] ?> <small style="font-size:0.75rem;color:var(--text-muted);">entr</small></span>
                        </div>
                        <div class="kpi-subtext">
                            <span><?= $kpiCargas['en_espera'] ?> cargas en espera de lavado</span>
                        </div>
                    </div>
                </section>

                <!-- ══════════════════════════════════════════════════════════
                     2. Gráficos Principales Fila 1:
                        - Ventas del Día (Curva / Barras)
                        - Top 5 Productos Más Vendidos (Doughnut)
                     ══════════════════════════════════════════════════════════ -->
                <section class="dash-charts-grid">
                    
                    <!-- Gráfico 1: Ventas del Día / Evolución Horaria -->
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <div>
                                <h3 class="dash-card-title">
                                    <svg style="width:20px;height:20px;color:#38bdf8;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                                    <span>Ventas del Día & Evolución</span>
                                </h3>
                                <p class="dash-card-subtitle" id="label-sub-ventas">Ingresos por hora registrados durante la jornada de hoy</p>
                            </div>
                            <div class="dash-toggle-group">
                                <button type="button" class="dash-toggle-btn active" id="btn-vista-hoy" onclick="cambiarVistaVentas('hoy')">Hoy (Horas)</button>
                                <button type="button" class="dash-toggle-btn" id="btn-vista-7d" onclick="cambiarVistaVentas('7d')">Últimos 7 Días</button>
                            </div>
                        </div>

                        <div class="dash-chart-wrapper">
                            <canvas id="chartVentasDia"></canvas>
                        </div>
                    </div>

                    <!-- Gráfico 2: Top 5 Productos Más Vendidos -->
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <div>
                                <h3 class="dash-card-title">
                                    <svg style="width:20px;height:20px;color:#10b981;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                                    <span>Top 5 Productos Más Vendidos</span>
                                </h3>
                                <p class="dash-card-subtitle">Volumen total de unidades / servicios comercializados</p>
                            </div>
                        </div>

                        <?php if (!empty($topProductos)): ?>
                            <div class="dash-chart-wrapper" style="min-height:220px;">
                                <canvas id="chartTopProductos"></canvas>
                            </div>
                            <!-- Desglose de lista de productos -->
                            <div class="dash-prod-breakdown">
                                <?php 
                                $coloresProd = ['#10b981', '#3b82f6', '#8b5cf6', '#f59e0b', '#06b6d4'];
                                foreach ($topProductos as $idx => $tp): 
                                    $cDot = $coloresProd[$idx % count($coloresProd)];
                                ?>
                                    <div class="dash-prod-item">
                                        <div style="display:flex;align-items:center;overflow:hidden;flex:1;">
                                            <span class="dash-prod-dot" style="background:<?= $cDot ?>;"></span>
                                            <span style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($tp['producto_nombre']) ?>">
                                                <?= htmlspecialchars($tp['producto_nombre']) ?>
                                            </span>
                                        </div>
                                        <div style="display:flex;gap:1rem;flex-shrink:0;">
                                            <span style="font-weight:700;color:#93c5fd;"><?= number_format($tp['total_vendido'], 1) ?> <?= htmlspecialchars(strtolower($tp['unidad_medida'] ?? 'pza')) ?></span>
                                            <span style="color:#34d399;font-weight:700;">$<?= number_format($tp['total_ingresos'], 2) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-chart-note">
                                <svg style="width:48px;height:48px;opacity:0.3;margin-bottom:0.75rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                                <strong>Sin ventas de productos registradas</strong>
                                <span style="font-size:0.75rem;margin-top:0.25rem;">Las ventas que realices en el POS aparecerán aquí.</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- ══════════════════════════════════════════════════════════
                     3. Gráficos Principales Fila 2:
                        - Top 10 Clientes Más Frecuentes (Barras Horizontales & Ranking)
                        - Métodos de Pago y Distribución
                     ══════════════════════════════════════════════════════════ -->
                <section class="dash-charts-grid" style="grid-template-columns: 1.4fr 1fr;">
                    
                    <!-- Gráfico 3: Top 10 Clientes Más Frecuentes -->
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <div>
                                <h3 class="dash-card-title">
                                    <svg style="width:20px;height:20px;color:#a855f7;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                                    <span>Top 10 Clientes Más Frecuentes</span>
                                </h3>
                                <p class="dash-card-subtitle">Ranking de clientes por número de visitas y compras realizadas</p>
                            </div>
                        </div>

                        <?php if (!empty($topClientes)): ?>
                            <!-- Gráfico de barras horizontales -->
                            <div class="dash-chart-wrapper" style="min-height:280px;">
                                <canvas id="chartTopClientes"></canvas>
                            </div>

                            <!-- Lista / Ranking estilizado con medallas -->
                            <div class="dash-ranking-list">
                                <?php foreach ($topClientes as $idx => $cli): 
                                    $pos = $idx + 1;
                                    $rankClass = $pos === 1 ? 'rank-1' : ($pos === 2 ? 'rank-2' : ($pos === 3 ? 'rank-3' : 'rank-other'));
                                    $medalla = $pos === 1 ? '🥇' : ($pos === 2 ? '🥈' : ($pos === 3 ? '🥉' : '#' . $pos));
                                ?>
                                    <div class="dash-ranking-item">
                                        <div class="dash-rank-badge <?= $rankClass ?>"><?= $medalla ?></div>
                                        <div class="dash-rank-info">
                                            <div class="dash-rank-name"><?= htmlspecialchars($cli['cliente_nombre']) ?></div>
                                            <div class="dash-rank-tel">
                                                <?= !empty($cli['cliente_telefono']) ? '📞 ' . htmlspecialchars($cli['cliente_telefono']) : 'Sin teléfono' ?>
                                                <?php if (!empty($cli['ultima_compra'])): ?>
                                                    • Última: <?= substr($cli['ultima_compra'], 0, 10) ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="dash-rank-metrics">
                                            <div class="dash-rank-orders"><?= $cli['total_compras'] ?> <?= $cli['total_compras'] == 1 ? 'compra' : 'compras' ?></div>
                                            <div class="dash-rank-total">$<?= number_format($cli['monto_total'], 2) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-chart-note">
                                <svg style="width:48px;height:48px;opacity:0.3;margin-bottom:0.75rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                                <strong>Sin registros de clientes frecuentes aún</strong>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Gráfico 4: Métodos de Pago y Estado Operativo -->
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <div>
                                <h3 class="dash-card-title">
                                    <svg style="width:20px;height:20px;color:#f59e0b;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/></svg>
                                    <span>Métodos de Pago de Hoy</span>
                                </h3>
                                <p class="dash-card-subtitle">Distribución de cobros por forma de pago</p>
                            </div>
                        </div>

                        <?php if (!empty($metodosPago)): ?>
                            <div class="dash-chart-wrapper" style="min-height:220px;">
                                <canvas id="chartMetodosPago"></canvas>
                            </div>
                        <?php else: ?>
                            <div class="empty-chart-note" style="min-height:220px;">
                                <svg style="width:40px;height:40px;opacity:0.3;margin-bottom:0.5rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
                                <strong>Sin cobros registrados hoy</strong>
                                <span style="font-size:0.75rem;margin-top:0.25rem;">Al cobrar ventas en el POS se mostrará el desglose.</span>
                            </div>
                        <?php endif; ?>

                        <!-- Resumen Cargas de Lavandería -->
                        <div style="border-top: 1px solid var(--border); padding-top: 1rem;">
                            <div style="font-size:0.85rem;font-weight:700;color:#fff;margin-bottom:0.5rem;display:flex;align-items:center;gap:0.4rem;">
                                <span>🧺 Estado de Cargas Hoy:</span>
                                <strong style="color:#60a5fa;"><?= $kpiCargas['total'] ?> totales</strong>
                            </div>
                            <div class="dash-lavado-pill-row">
                                <div class="dash-lavado-pill" style="border-left: 3px solid #f59e0b;">
                                    <span style="font-size:0.75rem;color:var(--text-muted);">⏳ En Espera</span>
                                    <span class="dash-lavado-pill-val" style="color:#fbbf24;"><?= $kpiCargas['en_espera'] ?></span>
                                </div>
                                <div class="dash-lavado-pill" style="border-left: 3px solid #3b82f6;">
                                    <span style="font-size:0.75rem;color:var(--text-muted);">⚙️ En Proceso</span>
                                    <span class="dash-lavado-pill-val" style="color:#60a5fa;"><?= $kpiCargas['en_proceso'] ?></span>
                                </div>
                                <div class="dash-lavado-pill" style="border-left: 3px solid #10b981;">
                                    <span style="font-size:0.75rem;color:var(--text-muted);">✅ Listas</span>
                                    <span class="dash-lavado-pill-val" style="color:#34d399;"><?= $kpiCargas['listas'] ?></span>
                                </div>
                                <div class="dash-lavado-pill" style="border-left: 3px solid #8b5cf6;">
                                    <span style="font-size:0.75rem;color:var(--text-muted);">📦 Entregadas</span>
                                    <span class="dash-lavado-pill-val" style="color:#c084fc;"><?= $kpiCargas['entregadas'] ?></span>
                                </div>
                            </div>
                        </div>

                    </div>
                </section>

            </div>
        </main>
    </div>

    <script src="js/sidebar.js"></script>
    <script>
    // Configuración Global de Chart.js para Dark Mode
    if (window.Chart) {
        Chart.defaults.color = '#94a3b8';
        Chart.defaults.font.family = "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
        Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(15, 23, 42, 0.95)';
        Chart.defaults.plugins.tooltip.titleColor = '#f8fafc';
        Chart.defaults.plugins.tooltip.bodyColor = '#e2e8f0';
        Chart.defaults.plugins.tooltip.borderColor = 'rgba(255, 255, 255, 0.15)';
        Chart.defaults.plugins.tooltip.borderWidth = 1;
        Chart.defaults.plugins.tooltip.padding = 10;
        Chart.defaults.plugins.tooltip.boxPadding = 6;
        Chart.defaults.plugins.tooltip.usePointStyle = true;
        Chart.defaults.plugins.tooltip.cornerRadius = 8;
    }

    // Datos desde PHP
    const datosVentasHoy = {
        labels: <?= json_encode($horasLabels) ?>,
        montos: <?= json_encode($horasMontos) ?>,
        tickets: <?= json_encode($horasTickets) ?>
    };

    const datosVentas7d = {
        labels: <?= json_encode($diasLabels) ?>,
        montos: <?= json_encode($diasMontos) ?>,
        tickets: <?= json_encode($diasTickets) ?>
    };

    const datosTopProductos = <?= json_encode(array_map(function($p) {
        return [
            'nombre'   => $p['producto_nombre'],
            'vendido'  => (float)$p['total_vendido'],
            'ingresos' => (float)$p['total_ingresos']
        ];
    }, $topProductos), JSON_UNESCAPED_UNICODE) ?>;

    const datosTopClientes = <?= json_encode(array_map(function($c) {
        return [
            'nombre'  => $c['cliente_nombre'],
            'compras' => (int)$c['total_compras'],
            'monto'   => (float)$c['monto_total']
        ];
    }, $topClientes), JSON_UNESCAPED_UNICODE) ?>;

    const datosMetodosPago = <?= json_encode(array_map(function($m) {
        return [
            'metodo'  => $m['metodo_pago'],
            'tickets' => (int)$m['tickets'],
            'monto'   => (float)$m['monto']
        ];
    }, $metodosPago), JSON_UNESCAPED_UNICODE) ?>;

    // ─────────────────────────────────────────────────────────────
    // 1. Gráfico: Ventas del Día / 7 Días (Línea / Spline Suave)
    // ─────────────────────────────────────────────────────────────
    let chartVentas = null;
    const ctxVentas = document.getElementById('chartVentasDia');
    if (ctxVentas && window.Chart) {
        const ctx = ctxVentas.getContext('2d');
        const gradiente = ctx.createLinearGradient(0, 0, 0, 300);
        gradiente.addColorStop(0, 'rgba(59, 130, 246, 0.45)');
        gradiente.addColorStop(1, 'rgba(59, 130, 246, 0.0)');

        chartVentas = new Chart(ctx, {
            type: 'line',
            data: {
                labels: datosVentasHoy.labels,
                datasets: [{
                    label: 'Ventas ($)',
                    data: datosVentasHoy.montos,
                    borderColor: '#3b82f6',
                    borderWidth: 3,
                    backgroundColor: gradiente,
                    fill: true,
                    tension: 0.38,
                    pointBackgroundColor: '#60a5fa',
                    pointBorderColor: '#1e293b',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 7,
                    pointHoverBackgroundColor: '#93c5fd'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return ' Ventas: $' + context.parsed.y.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(255, 255, 255, 0.04)' },
                        ticks: { color: '#94a3b8', font: { size: 11 } }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(255, 255, 255, 0.06)' },
                        ticks: {
                            color: '#94a3b8',
                            font: { size: 11 },
                            callback: function(val) {
                                return '$' + val.toLocaleString('es-MX');
                            }
                        }
                    }
                }
            }
        });
    }

    function cambiarVistaVentas(tipo) {
        if (!chartVentas) return;
        const btnHoy = document.getElementById('btn-vista-hoy');
        const btn7d = document.getElementById('btn-vista-7d');
        const sub = document.getElementById('label-sub-ventas');

        if (tipo === 'hoy') {
            btnHoy.classList.add('active');
            btn7d.classList.remove('active');
            if (sub) sub.textContent = 'Ingresos por hora registrados durante la jornada de hoy';
            chartVentas.data.labels = datosVentasHoy.labels;
            chartVentas.data.datasets[0].data = datosVentasHoy.montos;
        } else {
            btn7d.classList.add('active');
            btnHoy.classList.remove('active');
            if (sub) sub.textContent = 'Ingresos diarios registrados en los últimos 7 días';
            chartVentas.data.labels = datosVentas7d.labels;
            chartVentas.data.datasets[0].data = datosVentas7d.montos;
        }
        chartVentas.update();
    }

    // ─────────────────────────────────────────────────────────────
    // 2. Gráfico: Top 5 Productos Más Vendidos (Doughnut)
    // ─────────────────────────────────────────────────────────────
    const ctxProd = document.getElementById('chartTopProductos');
    if (ctxProd && datosTopProductos.length > 0 && window.Chart) {
        new Chart(ctxProd, {
            type: 'doughnut',
            data: {
                labels: datosTopProductos.map(p => p.nombre),
                datasets: [{
                    data: datosTopProductos.map(p => p.vendido),
                    backgroundColor: [
                        '#10b981', '#3b82f6', '#8b5cf6', '#f59e0b', '#06b6d4'
                    ],
                    borderColor: '#1e293b',
                    borderWidth: 2,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            padding: 14,
                            color: '#cbd5e1',
                            font: { size: 11, weight: '600' }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const prod = datosTopProductos[context.dataIndex];
                                return ` Cantidad: ${context.parsed} | Total: $${prod.ingresos.toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;
                            }
                        }
                    }
                }
            }
        });
    }

    // ─────────────────────────────────────────────────────────────
    // 3. Gráfico: Top 10 Clientes Más Frecuentes (Barras Horizontales)
    // ─────────────────────────────────────────────────────────────
    const ctxCli = document.getElementById('chartTopClientes');
    if (ctxCli && datosTopClientes.length > 0 && window.Chart) {
        const ctxC = ctxCli.getContext('2d');
        const gradienteBarra = ctxC.createLinearGradient(0, 0, 400, 0);
        gradienteBarra.addColorStop(0, '#8b5cf6');
        gradienteBarra.addColorStop(1, '#3b82f6');

        new Chart(ctxCli, {
            type: 'bar',
            data: {
                labels: datosTopClientes.map(c => c.nombre.length > 18 ? c.nombre.substring(0, 16) + '...' : c.nombre),
                datasets: [{
                    label: 'Compras Realizadas',
                    data: datosTopClientes.map(c => c.compras),
                    backgroundColor: gradienteBarra,
                    borderRadius: 8,
                    borderSkipped: false,
                    barPercentage: 0.72
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function(items) {
                                const idx = items[0].dataIndex;
                                return datosTopClientes[idx].nombre;
                            },
                            label: function(context) {
                                const c = datosTopClientes[context.dataIndex];
                                return [
                                    ` Compras: ${c.compras}`,
                                    ` Total Consumido: $${c.monto.toLocaleString('es-MX', { minimumFractionDigits: 2 })}`
                                ];
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: 'rgba(255, 255, 255, 0.04)' },
                        ticks: {
                            color: '#94a3b8',
                            precision: 0,
                            font: { size: 11 }
                        }
                    },
                    y: {
                        grid: { display: false },
                        ticks: {
                            color: '#e2e8f0',
                            font: { size: 11, weight: '600' }
                        }
                    }
                }
            }
        });
    }

    // ─────────────────────────────────────────────────────────────
    // 4. Gráfico: Métodos de Pago de Hoy (Doughnut)
    // ─────────────────────────────────────────────────────────────
    const ctxMet = document.getElementById('chartMetodosPago');
    if (ctxMet && datosMetodosPago.length > 0 && window.Chart) {
        const coloresMetodo = {
            'EFECTIVO': '#10b981',
            'TARJETA': '#3b82f6',
            'TRANSFERENCIA': '#8b5cf6',
            'PENDIENTE': '#f59e0b'
        };

        new Chart(ctxMet, {
            type: 'doughnut',
            data: {
                labels: datosMetodosPago.map(m => m.metodo),
                datasets: [{
                    data: datosMetodosPago.map(m => m.monto),
                    backgroundColor: datosMetodosPago.map(m => coloresMetodo[m.metodo] || '#64748b'),
                    borderColor: '#1e293b',
                    borderWidth: 2,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            padding: 10,
                            color: '#cbd5e1',
                            font: { size: 11, weight: '600' }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const m = datosMetodosPago[context.dataIndex];
                                return ` $${context.parsed.toLocaleString('es-MX', { minimumFractionDigits: 2 })} (${m.tickets} tickets)`;
                            }
                        }
                    }
                }
            }
        });
    }

    // Evitar que el historial regrese a la pantalla de login o al POS
    if (window.history && window.history.replaceState) {
        window.history.replaceState(null, document.title, window.location.href);
    }
    history.pushState(null, document.title, location.href);
    window.addEventListener('popstate', function () {
        history.pushState(null, document.title, location.href);
    });
    </script>
</body>
</html>
