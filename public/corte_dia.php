<?php
date_default_timezone_set('America/Mexico_City');
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../src/modules/auth/AuthService.php';
require_once __DIR__ . '/../src/modules/pos/CajaCorte.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;
use App\Modules\Pos\CajaCorte;

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

// Restricción estricta: Solo ADMINISTRADOR
if (!$auth->isAdmin()) {
    die("Acceso denegado: Solo los usuarios con rol ADMINISTRADOR pueden acceder a la gestión del Corte del Día.");
}

$cajaModel = new CajaCorte($db);
$currentUserId = $_SESSION['usuario_id'] ?? null;
$mensajeExito = '';
$mensajeError = '';
$nuevoCorteDia = null;

// Procesar Ejecución de Corte del Día desde formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ejecutar_corte_dia') {
    $observaciones = trim($_POST['observaciones'] ?? '');
    $res = $cajaModel->ejecutarCorteDia($currentUserId, $observaciones);
    if (!empty($res['success'])) {
        $mensajeExito = $res['message'];
        $nuevoCorteDia = $res;
    } else {
        $mensajeError = $res['message'] ?? 'Error desconocido al realizar el corte del día.';
    }
}

// Consultar estado actual y turnos pendientes
$resumen = $cajaModel->obtenerResumenCorteDia();
$historial = $cajaModel->obtenerHistorialCortesDia(50);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Corte General del Día | Lavandería Vera</title>
    <link rel="stylesheet" href="css/index.css">
    <script src="js/theme.js"></script>
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.1rem;
            margin-bottom: 1.75rem;
        }
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.25rem 1.4rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }
        .stat-card.highlight {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.18), rgba(168, 85, 247, 0.15));
            border-color: rgba(168, 85, 247, 0.45);
        }
        .stat-title {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.45rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .stat-value {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text-main);
            font-family: 'JetBrains Mono', monospace;
            line-height: 1.2;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            color: var(--text-main);
            font-size: 0.85rem;
        }
        .table th {
            background: rgba(30, 41, 59, 0.6);
            padding: 0.85rem 1rem;
            text-align: left;
            font-weight: 700;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
            text-transform: uppercase;
            font-size: 0.73rem;
            letter-spacing: 0.04em;
        }
        .table td {
            padding: 0.85rem 1rem;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        .badge-status {
            padding: 0.25rem 0.65rem;
            border-radius: 99px;
            font-size: 0.72rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }
        .badge-blocked {
            background: rgba(239, 68, 68, 0.18);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.35);
        }
        .badge-ready {
            background: rgba(16, 185, 129, 0.18);
            color: #6ee7b7;
            border: 1px solid rgba(16, 185, 129, 0.35);
        }
        .badge-empty {
            background: rgba(59, 130, 246, 0.18);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.35);
        }
        .alert-box {
            border-radius: 12px;
            padding: 1.1rem 1.35rem;
            margin-bottom: 1.75rem;
            display: flex;
            align-items: flex-start;
            gap: 0.85rem;
            animation: fadeIn 0.3s ease;
        }
        .alert-danger {
            background: rgba(239, 68, 68, 0.12);
            border: 1.5px solid rgba(239, 68, 68, 0.45);
            color: #fecaca;
        }
        .alert-info {
            background: rgba(59, 130, 246, 0.12);
            border: 1.5px solid rgba(59, 130, 246, 0.4);
            color: #bfdbfe;
        }
        .alert-success {
            background: rgba(16, 185, 129, 0.12);
            border: 1.5px solid rgba(16, 185, 129, 0.45);
            color: #a7f3d0;
        }
        .btn-corte {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
            font-weight: 700;
            padding: 0.75rem 1.6rem;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.35);
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.95rem;
        }
        .btn-corte:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.5);
        }
        .btn-corte:disabled {
            opacity: 0.45;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .btn-print {
            background: rgba(59, 130, 246, 0.15);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.3);
            padding: 0.35rem 0.75rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.78rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-print:hover {
            background: rgba(59, 130, 246, 0.3);
            color: #fff;
        }
        /* Modal Moderno Confirmación */
        .modal-corte-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 99999;
            align-items: center;
            justify-content: center;
            background: rgba(0,0,0,0.65);
            backdrop-filter: blur(4px);
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $activePage = 'corte_dia'; require_once __DIR__ . '/partials/sidebar.php'; ?>
        
        <main class="main-content">
            <!-- Header -->
            <header style="margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h1 style="font-size: 2rem; font-weight: 800; display: flex; align-items: center; gap: 0.6rem;">
                        <span>📅</span> Corte General del Día
                    </h1>
                    <p style="color: var(--text-muted); margin-top: 0.25rem;">
                        Consolidación y arqueo de turnos de caja — Panel Exclusivo para Administrador
                    </p>
                </div>
                <div>
                    <a href="pos.php" target="_blank" class="btn" style="background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3); text-decoration: none; padding: 0.6rem 1.1rem; border-radius: 10px; font-weight: 600; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.4rem;">
                        <svg style="width:16px;height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M12 8v8m-4-4h8"/></svg>
                        Ir al POS
                    </a>
                </div>
            </header>

            <!-- Alertas flash de ejecución -->
            <?php if (!empty($mensajeExito)): ?>
                <div class="alert-box alert-success">
                    <span style="font-size: 1.5rem;">🎉</span>
                    <div style="flex: 1;">
                        <strong style="font-size: 1rem; color: #10b981; display: block; margin-bottom: 0.25rem;">
                            ¡Corte del Día Realizado Exitosamente!
                        </strong>
                        <div style="font-size: 0.88rem; color: #d1fae5;">
                            <?= htmlspecialchars($mensajeExito) ?>
                        </div>
                    </div>
                    <?php if (!empty($nuevoCorteDia)): ?>
                        <button type="button" class="btn-corte" style="padding: 0.45rem 1rem; font-size: 0.85rem;" onclick="imprimirCorteDiaDirecto(<?= htmlspecialchars(json_encode($nuevoCorteDia), ENT_QUOTES, 'UTF-8') ?>)">
                            🖨️ Imprimir Comprobante
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($mensajeError)): ?>
                <div class="alert-box alert-danger">
                    <span style="font-size: 1.5rem;">⚠️</span>
                    <div>
                        <strong style="font-size: 1rem; color: #ef4444; display: block; margin-bottom: 0.25rem;">
                            No se pudo realizar el corte del día
                        </strong>
                        <div style="font-size: 0.88rem;">
                            <?= htmlspecialchars($mensajeError) ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ESTADO ACTUAL DEL CORTE DEL DÍA -->
            <?php if ($resumen['motivo_bloqueo'] === 'TURNO_ABIERTO'): ?>
                <!-- Bloqueo estricto: Hay un turno de caja abierto -->
                <div class="alert-box alert-danger">
                    <span style="font-size: 2rem; line-height: 1;">⛔</span>
                    <div style="flex: 1;">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                            <strong style="font-size: 1.05rem; color: #f87171;">
                                No es posible realizar el Corte del Día: Hay un turno de caja actualmente ABIERTO
                            </strong>
                            <span class="badge-status badge-blocked">ACCIÓN BLOQUEADA</span>
                        </div>
                        <p style="font-size: 0.88rem; margin: 0.45rem 0 0.6rem; color: #cbd5e1; line-height: 1.45;">
                            Para garantizar la integridad financiera, el corte del día no puede realizarse mientras existan sesiones o turnos de caja en operación. El usuario responsable debe realizar el corte y arqueo de su turno de caja primero.
                        </p>
                        <div style="background: rgba(0,0,0,0.25); border-radius: 8px; padding: 0.65rem 0.95rem; font-size: 0.85rem; font-weight: 700; color: #fca5a5;">
                            👤 Turno(s) Abierto(s): <?= htmlspecialchars($resumen['cajeros_texto']) ?>
                        </div>
                    </div>
                </div>
            <?php elseif ($resumen['motivo_bloqueo'] === 'SIN_TURNOS_PENDIENTES'): ?>
                <!-- Informativo: No hay turnos pendientes -->
                <div class="alert-box alert-info">
                    <span style="font-size: 1.8rem; line-height: 1;">ℹ️</span>
                    <div style="flex: 1;">
                        <strong style="font-size: 1rem; color: #60a5fa; display: block; margin-bottom: 0.25rem;">
                            No hay cortes de caja pendientes de consolidar
                        </strong>
                        <div style="font-size: 0.88rem; color: #cbd5e1;">
                            Todos los turnos de caja cerrados ya han sido consolidados en un corte del día anterior, o aún no se han registrado turnos cerrados en el sistema.
                        </div>
                        <?php if (!empty($resumen['ultimo_corte_dia'])): ?>
                            <div style="margin-top: 0.5rem; font-size: 0.82rem; color: #94a3b8;">
                                Último corte del día registrado: <strong style="color: #c4b5fd;"><?= htmlspecialchars($resumen['ultimo_corte_dia']['folio']) ?></strong> el <?= date('d/m/Y H:i', strtotime($resumen['ultimo_corte_dia']['fecha_corte'])) ?> por <?= htmlspecialchars($resumen['ultimo_corte_dia']['administrador_nombre'] ?? 'Administrador') ?>.
                            </div>
                        <?php endif; ?>
                    </div>
                    <span class="badge-status badge-empty">SIN PENDIENTES</span>
                </div>
            <?php else: ?>
                <!-- Listo para consolidar -->
                <div class="alert-box alert-success">
                    <span style="font-size: 1.8rem; line-height: 1;">✅</span>
                    <div style="flex: 1;">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                            <strong style="font-size: 1.05rem; color: #34d399;">
                                Todos los turnos están cerrados: Listo para realizar el Corte del Día
                            </strong>
                            <span class="badge-status badge-ready">LISTO PARA CONSOLIDAR</span>
                        </div>
                        <div style="font-size: 0.88rem; color: #cbd5e1; margin-top: 0.35rem;">
                            Se han detectado <strong style="color: #fff;"><?= count($resumen['turnos']) ?> turno(s) de caja cerrados</strong> listos para consolidar en el corte general del período comprendido entre el <?= date('d/m/Y H:i', strtotime($resumen['fecha_desde'])) ?> y el <?= date('d/m/Y H:i', strtotime($resumen['fecha_hasta'])) ?>.
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- RESUMEN DE TOTALES CONSOLIDADOS (KPIS) -->
            <?php 
                $tot = $resumen['totales'] ?? [
                    'total_fondo_inicial' => 0,
                    'total_efectivo' => 0,
                    'total_tarjeta' => 0,
                    'total_transferencia' => 0,
                    'total_ventas' => 0,
                    'total_efectivo_declarado' => 0,
                    'total_diferencia' => 0
                ];
            ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-title">💵 Efectivo Turnos</div>
                    <div class="stat-value" style="color: #34d399;">$<?= number_format($tot['total_efectivo'], 2) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-title">💳 Tarjetas Turnos</div>
                    <div class="stat-value" style="color: #60a5fa;">$<?= number_format($tot['total_tarjeta'], 2) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-title">📱 Transferencias Turnos</div>
                    <div class="stat-value" style="color: #c084fc;">$<?= number_format($tot['total_transferencia'], 2) ?></div>
                </div>
                <div class="stat-card highlight">
                    <div class="stat-title" style="color: #e0e7ff;">💰 Gran Total Ventas</div>
                    <div class="stat-value" style="color: #f3e8ff;">$<?= number_format($tot['total_ventas'], 2) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-title">📥 Total Arqueo Físico</div>
                    <div class="stat-value" style="color: #38bdf8;">$<?= number_format($tot['total_efectivo_declarado'], 2) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-title">⚖️ Diferencia Global Neta</div>
                    <?php $difGlobal = floatval($tot['total_diferencia']); ?>
                    <div class="stat-value" style="color: <?= $difGlobal >= 0 ? '#34d399' : '#f87171' ?>;">
                        <?= $difGlobal > 0 ? '+' : '' ?>$<?= number_format($difGlobal, 2) ?>
                    </div>
                </div>
            </div>

            <!-- SECCIÓN: TURNOS A CONSOLIDAR -->
            <div class="card" style="margin-bottom: 2rem; background: var(--bg-card); border: 1px solid var(--border); border-radius: 14px; padding: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.5rem;">
                    <div>
                        <h3 style="font-size: 1.2rem; font-weight: 700; margin: 0; color: var(--text-main);">
                            📋 Desglose de Turnos de Caja Pendientes de Consolidar
                        </h3>
                        <p style="font-size: 0.82rem; color: var(--text-muted); margin-top: 0.2rem;">
                            Turnos cerrados que se agruparán en este corte del día
                        </p>
                    </div>
                    <span class="badge-status badge-ready" style="font-size: 0.8rem; padding: 0.35rem 0.85rem;">
                        <?= count($resumen['turnos']) ?> Turno(s)
                    </span>
                </div>

                <div style="overflow-x: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th># Turno</th>
                                <th>Cajero(s)</th>
                                <th>Apertura</th>
                                <th>Cierre</th>
                                <th style="text-align: right;">Fondo Ini.</th>
                                <th style="text-align: right;">Efectivo</th>
                                <th style="text-align: right;">Tarjeta</th>
                                <th style="text-align: right;">Transf.</th>
                                <th style="text-align: right; color: #a78bfa;">Total Ventas</th>
                                <th style="text-align: right;">Arqueo Físico</th>
                                <th style="text-align: right;">Diferencia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($resumen['turnos'])): ?>
                                <tr>
                                    <td colspan="11" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                                        <?= $resumen['motivo_bloqueo'] === 'TURNO_ABIERTO' ? 'Hay un turno de caja abierto en el sistema. Todos los turnos deben cerrarse antes de consolidar.' : 'No hay turnos cerrados pendientes de corte del día.' ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($resumen['turnos'] as $idx => $t): ?>
                                    <?php 
                                        $mismo = ($t['usuario_apertura_nombre'] === $t['usuario_cierre_nombre']);
                                        $dif = floatval($t['diferencia']);
                                    ?>
                                    <tr>
                                        <td style="font-weight: 800; color: #c4b5fd;">
                                            #<?= $idx + 1 ?>
                                        </td>
                                        <td>
                                            <?php if ($mismo): ?>
                                                <strong style="color: var(--text-main);"><?= htmlspecialchars($t['usuario_apertura_nombre'] ?? 'Cajero') ?></strong>
                                            <?php else: ?>
                                                <strong style="color: #93c5fd;"><?= htmlspecialchars($t['usuario_apertura_nombre'] ?? 'Cajero') ?></strong> <small style="color:var(--text-muted);">(A)</small> / 
                                                <strong style="color: #34d399;"><?= htmlspecialchars($t['usuario_cierre_nombre'] ?? 'Cajero') ?></strong> <small style="color:var(--text-muted);">(C)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-family: 'JetBrains Mono', monospace; font-size: 0.8rem; color: var(--text-muted);">
                                            <?= date('d/m H:i', strtotime($t['fecha_apertura'])) ?>
                                        </td>
                                        <td style="font-family: 'JetBrains Mono', monospace; font-size: 0.8rem; color: var(--text-muted);">
                                            <?= !empty($t['fecha_cierre']) ? date('d/m H:i', strtotime($t['fecha_cierre'])) : '—' ?>
                                        </td>
                                        <td style="text-align: right; font-family: 'JetBrains Mono', monospace;">
                                            $<?= number_format(floatval($t['monto_inicial']), 2) ?>
                                        </td>
                                        <td style="text-align: right; font-family: 'JetBrains Mono', monospace; color: #34d399;">
                                            $<?= number_format(floatval($t['total_efectivo']), 2) ?>
                                        </td>
                                        <td style="text-align: right; font-family: 'JetBrains Mono', monospace; color: #60a5fa;">
                                            $<?= number_format(floatval($t['total_tarjeta']), 2) ?>
                                        </td>
                                        <td style="text-align: right; font-family: 'JetBrains Mono', monospace; color: #c084fc;">
                                            $<?= number_format(floatval($t['total_transferencia']), 2) ?>
                                        </td>
                                        <td style="text-align: right; font-family: 'JetBrains Mono', monospace; font-weight: 800; color: #f3e8ff;">
                                            $<?= number_format(floatval($t['total_ventas_calculado']), 2) ?>
                                        </td>
                                        <td style="text-align: right; font-family: 'JetBrains Mono', monospace; color: #38bdf8;">
                                            $<?= number_format(floatval($t['efectivo_declarado']), 2) ?>
                                        </td>
                                        <td style="text-align: right; font-family: 'JetBrains Mono', monospace; font-weight: 700; color: <?= $dif >= 0 ? '#34d399' : '#f87171' ?>;">
                                            <?= $dif > 0 ? '+' : '' ?>$<?= number_format($dif, 2) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Formulario para Realizar el Corte del Día -->
                <form method="POST" id="form-corte-dia" style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid var(--border);">
                    <input type="hidden" name="action" value="ejecutar_corte_dia">
                    <div style="margin-bottom: 1.25rem;">
                        <label style="display: block; font-size: 0.85rem; font-weight: 700; color: var(--text-muted); margin-bottom: 0.4rem;">
                            Observaciones del Corte General (Opcional):
                        </label>
                        <textarea name="observaciones" class="form-control" rows="2" placeholder="Notas sobre el balance del día, novedades o incidencias..." style="width: 100%; max-width: 800px; background: rgba(15,23,42,0.4); border: 1px solid var(--border); border-radius: 8px; color: var(--text-main); padding: 0.65rem 0.85rem; font-size: 0.88rem;"></textarea>
                    </div>

                    <div>
                        <button type="button" class="btn-corte" <?= !$resumen['puede_cortar'] ? 'disabled' : '' ?> onclick="confirmarCorteDiaBackOffice()">
                            <span>📊</span> Realizar Corte del Día
                        </button>
                        <?php if (!$resumen['puede_cortar']): ?>
                            <span style="margin-left: 1rem; font-size: 0.82rem; color: #fca5a5;">
                                ⚠️ <?= htmlspecialchars($resumen['mensaje']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- SECCIÓN: HISTORIAL DE CORTES DEL DÍA -->
            <div class="card" style="background: var(--bg-card); border: 1px solid var(--border); border-radius: 14px; padding: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
                    <div>
                        <h3 style="font-size: 1.2rem; font-weight: 700; margin: 0; color: var(--text-main);">
                            📜 Historial de Cortes del Día Realizados
                        </h3>
                        <p style="font-size: 0.82rem; color: var(--text-muted); margin-top: 0.2rem;">
                            Registro de cierres diarios consolidados y comprobantes emitidos
                        </p>
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Folio</th>
                                <th>Fecha y Hora</th>
                                <th>Período Consolidado</th>
                                <th style="text-align: center;">Turnos</th>
                                <th style="text-align: right; color: #a78bfa;">Total Ventas</th>
                                <th style="text-align: right;">Diferencia</th>
                                <th>Administrador</th>
                                <th style="text-align: center;">Comprobante</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($historial)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                                        No se han realizado cortes del día todavía.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($historial as $h): ?>
                                    <?php $difH = floatval($h['total_diferencia']); ?>
                                    <tr>
                                        <td style="font-weight: 800; color: #c4b5fd;">
                                            <?= htmlspecialchars($h['folio']) ?>
                                        </td>
                                        <td style="color: var(--text-main);">
                                            <?= date('d/m/Y H:i', strtotime($h['fecha_corte'])) ?>
                                        </td>
                                        <td style="font-size: 0.78rem; color: var(--text-muted);">
                                            <?= date('d/m/Y H:i', strtotime($h['fecha_desde'])) ?> ➔ <?= date('d/m/Y H:i', strtotime($h['fecha_hasta'])) ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <span class="badge-status badge-ready"><?= $h['total_turnos'] ?></span>
                                        </td>
                                        <td style="text-align: right; font-family: 'JetBrains Mono', monospace; font-weight: 800; color: #f3e8ff;">
                                            $<?= number_format(floatval($h['total_ventas']), 2) ?>
                                        </td>
                                        <td style="text-align: right; font-family: 'JetBrains Mono', monospace; font-weight: 700; color: <?= $difH >= 0 ? '#34d399' : '#f87171' ?>;">
                                            <?= $difH > 0 ? '+' : '' ?>$<?= number_format($difH, 2) ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($h['administrador_nombre'] ?? 'Administrador') ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <button type="button" class="btn-print" onclick="imprimirCortePorId('<?= htmlspecialchars($h['id']) ?>')">
                                                🖨️ Imprimir
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal Confirmación Ejecutar Corte -->
    <div id="modal-confirm-corte-bo" class="modal-corte-overlay">
        <div style="background: var(--bg-card, #1e1e2e); border: 1px solid var(--border, rgba(255,255,255,0.1)); border-radius: 16px; padding: 2rem 2.25rem; width: 100%; max-width: 420px; box-shadow: 0 25px 60px rgba(0,0,0,0.5); text-align: center;">
            <div style="width: 58px; height: 58px; border-radius: 50%; background: rgba(99,102,241,0.15); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem;">
                <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#818cf8" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/>
                    <line x1="8" y1="2" x2="8" y2="6"/>
                    <line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
            </div>
            <h3 style="font-size: 1.2rem; font-weight: 700; color: var(--text-main); margin-bottom: .5rem;">
                ¿Realizar Corte General del Día?
            </h3>
            <p style="font-size: .88rem; color: var(--text-muted); margin-bottom: 1.75rem; line-height: 1.5;">
                Se consolidarán <strong><?= count($resumen['turnos']) ?> turno(s) de caja</strong> con un total de ventas de <strong>$<?= number_format($tot['total_ventas'], 2) ?></strong>.<br><br>Esta acción cerrará el ciclo del día y vinculará todos los turnos. ¿Deseas proceder?
            </p>
            <div style="display: flex; gap: .75rem; justify-content: center;">
                <button type="button" onclick="cerrarConfirmCorteBo()" style="flex: 1; padding: .65rem 1rem; border-radius: 8px; border: 1px solid rgba(255,255,255,0.12); background: transparent; color: var(--text-muted); font-size: .875rem; cursor: pointer;">
                    Cancelar
                </button>
                <button type="button" onclick="document.getElementById('form-corte-dia').submit()" style="flex: 1; padding: .65rem 1rem; border-radius: 8px; border: none; background: #6366f1; color: #fff; font-size: .875rem; font-weight: 700; cursor: pointer;">
                    Sí, realizar corte
                </button>
            </div>
        </div>
    </div>

    <script>
        function confirmarCorteDiaBackOffice() {
            const m = document.getElementById('modal-confirm-corte-bo');
            if (m) m.style.display = 'flex';
        }
        function cerrarConfirmCorteBo() {
            const m = document.getElementById('modal-confirm-corte-bo');
            if (m) m.style.display = 'none';
        }

        async function imprimirCortePorId(id) {
            try {
                const res = await fetch(`api/pos_api.php?action=detalle_corte_dia&corte_diario_id=${encodeURIComponent(id)}`);
                const data = await res.json();
                if (!data.success || !data.corte_dia) {
                    alert(data.message || 'No se pudo obtener el comprobante.');
                    return;
                }
                imprimirCorteDiaDirecto(data.corte_dia);
            } catch (e) {
                alert('Error al obtener comprobante para imprimir.');
            }
        }

        function imprimirCorteDiaDirecto(cd) {
            if (!cd) return;
            const ahora = new Date().toLocaleString("es-MX");
            const folio = cd.folio || "CD-000000";
            const admin = cd.administrador_nombre || "Administrador";
            const fDesde = cd.fecha_desde ? new Date(cd.fecha_desde).toLocaleString("es-MX", { dateStyle: "short", timeStyle: "short" }) : "—";
            const fHasta = cd.fecha_hasta ? new Date(cd.fecha_hasta).toLocaleString("es-MX", { dateStyle: "short", timeStyle: "short" }) : "—";

            const totales = cd.totales || {
                total_fondo_inicial: cd.total_fondo_inicial,
                total_efectivo: cd.total_efectivo,
                total_tarjeta: cd.total_tarjeta,
                total_transferencia: cd.total_transferencia,
                total_ventas: cd.total_ventas,
                total_efectivo_declarado: cd.total_efectivo_declarado,
                total_diferencia: cd.total_diferencia,
                total_cobros_turnos_anteriores: cd.total_cobros_turnos_anteriores
            };

            const totFondoIni = parseFloat(totales.total_fondo_inicial || 0).toFixed(2);
            const totEfec = parseFloat(totales.total_efectivo || 0).toFixed(2);
            const totTarj = parseFloat(totales.total_tarjeta || 0).toFixed(2);
            const totTransf = parseFloat(totales.total_transferencia || 0).toFixed(2);
            const totVentas = parseFloat(totales.total_ventas || 0).toFixed(2);
            const totDecl = parseFloat(totales.total_efectivo_declarado || 0).toFixed(2);
            const totDif = parseFloat(totales.total_diferencia || 0).toFixed(2);
            const totCobrosAnt = parseFloat(totales.total_cobros_turnos_anteriores || 0).toFixed(2);

            const turnos = cd.turnos || cd.turnos_decodificados || [];
            let turnosHtml = "";
            if (turnos.length > 0) {
                turnosHtml = `
<div class="sec-title">Desglose de Turnos Consolidados (${turnos.length})</div>
<table>
  ${turnos.map((t, idx) => {
    const ape = t.usuario_apertura_nombre || "Cajero";
    const cie = t.usuario_cierre_nombre || ape;
    const hApe = t.fecha_apertura ? new Date(t.fecha_apertura).toLocaleTimeString("es-MX", { hour: "2-digit", minute: "2-digit" }) : "—";
    const hCie = t.fecha_cierre ? new Date(t.fecha_cierre).toLocaleTimeString("es-MX", { hour: "2-digit", minute: "2-digit" }) : "—";
    const vTot = parseFloat(t.total_ventas_calculado || (parseFloat(t.total_efectivo||0) + parseFloat(t.total_tarjeta||0) + parseFloat(t.total_transferencia||0))).toFixed(2);
    const vDif = parseFloat(t.diferencia || 0).toFixed(2);
    return `
    <tr style="border-top:1px dashed #777;">
      <td><strong>Turno #${idx+1}:</strong> ${ape} ${ape !== cie ? '➔ ' + cie : ''}</td>
      <td style="text-align:right;"><strong>$${vTot}</strong></td>
    </tr>
    <tr>
      <td colspan="2" style="font-size:10px; color:#444; padding-bottom:3px;">
        Horario: ${hApe} - ${hCie} | Efec: $${parseFloat(t.total_efectivo||0).toFixed(2)} | Tarj: $${parseFloat(t.total_tarjeta||0).toFixed(2)} | Transf: $${parseFloat(t.total_transferencia||0).toFixed(2)} | Arqueo: $${parseFloat(t.efectivo_declarado||0).toFixed(2)} (Dif: ${vDif >= 0 ? '+' : ''}$${vDif})
      </td>
    </tr>
    `;
  }).join("")}
</table>
<hr>`;
            }

            const custodia = cd.custodia || {};
            const totCustodia = custodia.total_pendientes_entrega || cd.cargas_pendientes_entrega || 0;
            const totPendPago = custodia.total_pendientes_pago || cd.cargas_pendientes_pago || 0;
            const montoPend = parseFloat(custodia.monto_pendiente_cobro || cd.monto_pendiente_cobro || 0).toFixed(2);

            const obsText = cd.observaciones ? `
<div class="sec-title">Observaciones</div>
<div style="font-size:11px; font-style:italic; padding-bottom:4px;">${cd.observaciones}</div>
<hr>` : "";

            const html = `<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>Corte del Día - ${folio}</title>
<style>
  body { font-family: monospace; font-size: 12px; padding: 18px; color: #000; max-width: 420px; margin: 0 auto; }
  h2 { text-align: center; margin-bottom: 2px; font-size: 16px; font-weight: 900; }
  .sub { text-align: center; font-size: 11px; color: #333; margin-bottom: 2px; }
  hr { border: none; border-top: 1px dashed #000; margin: 7px 0; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 2px 0; vertical-align: top; }
  td:last-child { text-align: right; font-weight: bold; }
  .sec-title { font-weight: 900; font-size: 11px; letter-spacing: .05em; margin: 6px 0 2px; text-transform: uppercase; }
  .total-row td { border-top: 1px solid #000; font-weight: 900; font-size: 14px; padding-top: 4px; }
  .signature-box { margin-top: 25px; text-align: center; font-size: 11px; }
  .signature-line { border-top: 1px solid #000; width: 65%; margin: 30px auto 4px; }
  @media print { body { margin: 0; padding: 8px; } }
</style>
</head><body>
<h2>LAVANDERÍA VERA</h2>
<div class="sub">COMPROBANTE DE CORTE GENERAL DEL DÍA</div>
<div class="sub"><strong>FOLIO: ${folio}</strong></div>
<div class="sub">Emisión: ${ahora}</div>
<hr>
<div class="sec-title">Datos del Corte</div>
<table>
  <tr><td>Administrador:</td><td>${admin}</td></tr>
  <tr><td>Período Desde:</td><td>${fDesde}</td></tr>
  <tr><td>Período Hasta:</td><td>${fHasta}</td></tr>
  <tr><td>Total Turnos:</td><td>${turnos.length} turno(s)</td></tr>
</table>
<hr>
${turnosHtml}
<div class="sec-title">Consolidado Financiero</div>
<table>
  <tr><td>Fondo Inicial Consolidado:</td><td>$${totFondoIni}</td></tr>
  <tr><td>Ventas Efectivo Recaudado:</td><td>$${totEfec}</td></tr>
  ${parseFloat(totCobrosAnt) > 0 ? `<tr><td>Cobros Turnos Ant. (Efec):</td><td>$${totCobrosAnt}</td></tr>` : ''}
  <tr><td>Ventas Tarjeta Déb/Créd:</td><td>$${totTarj}</td></tr>
  <tr><td>Ventas Transferencia:</td><td>$${totTransf}</td></tr>
  <tr class="total-row"><td>GRAN TOTAL VENTAS:</td><td>$${totVentas}</td></tr>
  <tr><td style="padding-top:4px;">Total Efectivo Contado (Arqueo):</td><td style="padding-top:4px;">$${totDecl}</td></tr>
  <tr style="border-top:1px dashed #000; font-weight:900;"><td>DIFERENCIA NETA GLOBAL:</td><td>${totDif >= 0 ? '+' : ''}$${totDif}</td></tr>
</table>
<hr>
<div class="sec-title">Custodia de Prendas al Cierre</div>
<table>
  <tr><td>Prendas en Custodia Pendientes:</td><td>${totCustodia} cargas</td></tr>
  <tr><td>Cargas Pendientes de Cobro:</td><td>${totPendPago} cargas</td></tr>
  <tr><td>Monto Pendiente de Cobro:</td><td>$${montoPend}</td></tr>
</table>
<hr>
${obsText}
<div class="signature-box">
  <div class="signature-line"></div>
  <strong>${admin}</strong><br>
  Firma del Administrador / Supervisor
</div>
<div style="text-align:center; margin-top:14px; font-size:10px; color:#555;">
  Sistema de Punto de Venta | Lavandería Vera
</div>
</body></html>`;

            const win = window.open("", "_blank", "width=480,height=650");
            if (!win) {
                alert("El navegador bloqueó la ventana emergente. Favor de permitir pop-ups para imprimir.");
                return;
            }
            win.document.open();
            win.document.write(html);
            win.document.close();
            win.focus();
            setTimeout(() => {
                win.print();
            }, 450);
        }
    </script>
</body>
</html>
