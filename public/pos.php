<?php
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../src/modules/auth/AuthService.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;

$db = (new Database())->getConnection();
$auth = new AuthService($db);

if (!$auth->checkAuthPos()) {
    header("Location: pos_login.php");
    exit;
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$userName = $_SESSION['pos_nombre'] ?? 'Cajero';
$userRole = $_SESSION['pos_rol'] ?? 'EMPLEADO_CAJERO';
$isAdmin  = $auth->isPosAdmin();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Punto de Venta | FrontOffice | Lavandería Vera</title>
    <link rel="stylesheet" href="css/pos.css">
    <script src="js/theme.js"></script>
    <link rel="icon" type="image/jpeg" href="img/Logo.jpeg">
    <style>
        .pos-prod-card.pos-insumo-selected {
            border-color: #f59e0b !important;
            box-shadow: 0 0 16px rgba(245, 158, 11, 0.45) !important;
            background: rgba(245, 158, 11, 0.12) !important;
        }
        .pos-entry-grid.has-unit-select {
            grid-template-columns: 1.15fr 1.45fr 255px auto;
        }
        .pos-qty-wrapper {
            display: flex;
            gap: 0.45rem;
            align-items: center;
            width: 100%;
        }
        .pos-qty-wrapper .number-input-wrapper {
            flex: 1 1 auto;
            min-width: 90px;
        }
        .pos-unit-select {
            flex: 0 0 135px;
            width: 135px !important;
            height: 42px !important;
            font-weight: 600;
            font-size: 0.85rem;
            color: #fbbf24 !important;
            border: 1px solid rgba(245, 158, 11, 0.45) !important;
            background: rgba(15, 23, 42, 0.95) !important;
            border-radius: 10px;
            padding: 0 0.6rem !important;
            box-sizing: border-box;
            cursor: pointer;
        }
            /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
           DISEÃ‘O CLARO REFINADO PARA EL POS (Alta Legibilidad y Contraste)
           â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
        [data-theme="light"] {
            --pos-bg: #eef2f7;
            --pos-surface: #ffffff;
            --pos-surface-card: #ffffff;
            --pos-surface-hover: #f1f5f9;
            --pos-border: #cbd5e1;
            --pos-border-focus: #2563eb;
            --pos-text: #0f172a;
            --pos-text-muted: #64748b;
            --pos-primary: #2563eb;
            --pos-primary-hover: #1d4ed8;
            --pos-emerald: #059669;
            --pos-emerald-hover: #047857;
            --pos-amber: #d97706;
            --pos-rose: #dc2626;
            --pos-purple: #7c3aed;
            --pos-cyan: #0891b2;
        }

        [data-theme="light"] body {
            background-color: #eef2f7 !important;
            color: #0f172a !important;
        }

        /* â”€â”€ Header Top Bar â”€â”€ */
        [data-theme="light"] .pos-header {
            background: #ffffff !important;
            border-bottom: 1px solid #cbd5e1 !important;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04) !important;
        }
        [data-theme="light"] .pos-brand {
            color: #0f172a !important;
        }
        [data-theme="light"] .pos-brand h1 {
            color: #0f172a !important;
        }
        [data-theme="light"] .pos-logo {
            border-color: #cbd5e1 !important;
        }
        [data-theme="light"] .pos-btn-head {
            background: #f1f5f9 !important;
            border-color: #cbd5e1 !important;
            color: #1e293b !important;
        }
        [data-theme="light"] .pos-btn-head:hover {
            background: #e2e8f0 !important;
            color: #0f172a !important;
            border-color: #94a3b8 !important;
        }
        [data-theme="light"] .pos-btn-head.pos-user-badge,
        [data-theme="light"] .pos-user-badge {
            background: #f1f5f9 !important;
            border-color: #cbd5e1 !important;
            color: #0f172a !important;
        }
        [data-theme="light"] .pos-user-badge strong {
            color: #0f172a !important;
        }
        [data-theme="light"] .pos-clock {
            background: #f8fafc !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 8px !important;
            color: #0f172a !important;
            font-weight: 700 !important;
        }
        [data-theme="light"] .pos-caja-pill.abierta {
            background: #ecfdf5 !important;
            border-color: #a7f3d0 !important;
            color: #065f46 !important;
        }
        [data-theme="light"] .pos-caja-pill.cerrada {
            background: #fffbeb !important;
            border-color: #fde68a !important;
            color: #92400e !important;
        }

        /* â”€â”€ CatÃ¡logo y Panel Izquierdo â”€â”€ */
        [data-theme="light"] .pos-catalog-panel {
            background: #eef2f7 !important;
            border-right: 1px solid #cbd5e1 !important;
        }
        [data-theme="light"] .pos-catalog-controls {
            background: #ffffff !important;
            border-bottom: 1px solid #cbd5e1 !important;
        }

        /* Panel de SelecciÃ³n RÃ¡pida */
        [data-theme="light"] .pos-quick-entry-card {
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.04) !important;
        }
        [data-theme="light"] .pos-entry-header {
            border-bottom: 1px solid #f1f5f9 !important;
        }
        [data-theme="light"] #pos-entry-title-text {
            color: #0f172a !important;
            font-weight: 800 !important;
        }
        [data-theme="light"] .pos-entry-label {
            color: #334155 !important;
            font-weight: 700 !important;
        }
        [data-theme="light"] .pos-form-input,
        [data-theme="light"] .pos-search-input-field,
        [data-theme="light"] #pos-cliente-search-input,
        [data-theme="light"] #pos-search-producto-input,
        [data-theme="light"] #pos-input-kilos {
            background: #ffffff !important;
            color: #0f172a !important;
            border: 1px solid #cbd5e1 !important;
            font-weight: 600 !important;
        }
        [data-theme="light"] .pos-form-input::placeholder,
        [data-theme="light"] .pos-search-input-field::placeholder {
            color: #94a3b8 !important;
        }
        [data-theme="light"] .pos-form-input:focus,
        [data-theme="light"] .pos-search-input-field:focus {
            border-color: #2563eb !important;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15) !important;
            background: #ffffff !important;
        }
        [data-theme="light"] .pos-unit-select,
        [data-theme="light"] #pos-select-unidad-insumo {
            background: #ffffff !important;
            color: #b45309 !important;
            border: 1px solid #f59e0b !important;
        }
        [data-theme="light"] .pos-search-prod-icon {
            color: #64748b !important;
        }
        [data-theme="light"] .pos-btn-clear-prod {
            color: #94a3b8 !important;
        }
        [data-theme="light"] .pos-btn-clear-prod:hover {
            color: #ef4444 !important;
        }
        [data-theme="light"] .pos-entry-preview-badge {
            background: #ecfdf5 !important;
            border: 1px solid #a7f3d0 !important;
            color: #065f46 !important;
        }

        /* Resultados flotantes autocomplete */
        [data-theme="light"] .pos-prod-dropdown-results {
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15) !important;
        }
        [data-theme="light"] .pos-prod-dropdown-item {
            border-bottom: 1px solid #f1f5f9 !important;
            color: #0f172a !important;
        }
        [data-theme="light"] .pos-prod-dropdown-item:hover,
        [data-theme="light"] .pos-prod-dropdown-item.active {
            background: #f1f5f9 !important;
        }

        /* PestaÃ±as de categorÃ­a */
        [data-theme="light"] .pos-tab {
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            color: #475569 !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04) !important;
        }
        [data-theme="light"] .pos-tab:hover {
            background: #f8fafc !important;
            border-color: #94a3b8 !important;
            color: #0f172a !important;
        }
        [data-theme="light"] .pos-tab.active {
            background: #2563eb !important;
            border-color: #2563eb !important;
            color: #ffffff !important;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3) !important;
        }

        /* Tarjetas de Producto en CatÃ¡logo */
        [data-theme="light"] .pos-prod-card {
            background: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04) !important;
        }
        [data-theme="light"] .pos-prod-card:hover {
            border-color: #3b82f6 !important;
            box-shadow: 0 8px 24px rgba(37, 99, 235, 0.12) !important;
        }
        [data-theme="light"] .pos-prod-name {
            color: #0f172a !important;
            font-weight: 700 !important;
        }
        [data-theme="light"] .pos-prod-price {
            color: #059669 !important;
        }
        [data-theme="light"] .pos-prod-stock {
            color: #64748b !important;
        }
        [data-theme="light"] .pos-prod-card.pos-prod-selected {
            background: #f0fdf4 !important;
            border-color: #10b981 !important;
        }

        /* â”€â”€ Panel Derecho: Carrito / Ticket y Cobro â”€â”€ */
        [data-theme="light"] .pos-cart-panel {
            background: #ffffff !important;
            border-left: 1px solid #cbd5e1 !important;
            box-shadow: -4px 0 20px rgba(0, 0, 0, 0.05) !important;
        }
        [data-theme="light"] .pos-customer-bar {
            background: #f8fafc !important;
            border-bottom: 1px solid #e2e8f0 !important;
        }
        [data-theme="light"] .pos-cust-label {
            color: #64748b !important;
            font-weight: 700 !important;
        }
        [data-theme="light"] .pos-cust-name {
            color: #0f172a !important;
            font-weight: 800 !important;
        }
        [data-theme="light"] .pos-btn-cust {
            background: #e0f2fe !important;
            border: 1px solid #bae6fd !important;
            color: #0369a1 !important;
        }
        [data-theme="light"] .pos-btn-cust:hover {
            background: #bae6fd !important;
        }
        [data-theme="light"] .pos-cart-client-header {
            background: #f8fafc !important;
            border-bottom: 1px solid #e2e8f0 !important;
        }
        [data-theme="light"] #pos-cart-client-name {
            color: #0f172a !important;
        }
        [data-theme="light"] .pos-empty-cart {
            color: #64748b !important;
        }
        [data-theme="light"] .pos-empty-cart svg {
            color: #cbd5e1 !important;
        }
        [data-theme="light"] .pos-empty-cart p:first-of-type {
            color: #0f172a !important;
        }
        [data-theme="light"] .pos-items-list {
            background: #ffffff !important;
        }
        [data-theme="light"] .pos-cart-item {
            background: #f8fafc !important;
            border: 1px solid #e2e8f0 !important;
            color: #0f172a !important;
        }
        [data-theme="light"] .pos-cart-item:hover {
            background: #f1f5f9 !important;
        }
        [data-theme="light"] .pos-item-title {
            color: #0f172a !important;
            font-weight: 700 !important;
        }
        [data-theme="light"] .pos-item-subtotal {
            color: #0f172a !important;
            font-weight: 800 !important;
        }

        /* Footer Carrito / Totales */
        [data-theme="light"] .pos-cart-footer {
            background: #f8fafc !important;
            border-top: 1px solid #cbd5e1 !important;
        }
        [data-theme="light"] .pos-summary-row {
            color: #475569 !important;
        }
        [data-theme="light"] .pos-summary-row strong {
            color: #0f172a !important;
            font-weight: 800 !important;
        }
        [data-theme="light"] .pos-summary-total {
            border-top: 1px solid #e2e8f0 !important;
        }
        [data-theme="light"] .pos-total-label {
            color: #0f172a !important;
            font-weight: 800 !important;
        }
        [data-theme="light"] .pos-total-amount,
        [data-theme="light"] #pos-total-display {
            color: #059669 !important;
            text-shadow: none !important;
            font-weight: 900 !important;
        }
        [data-theme="light"] .pos-btn-pending {
            background: #fef3c7 !important;
            border: 1px solid #fde68a !important;
            color: #b45309 !important;
            font-weight: 700 !important;
        }
        [data-theme="light"] .pos-btn-pending:hover:not(:disabled) {
            background: #fde68a !important;
        }
        [data-theme="light"] .pos-btn-clear {
            background: #fee2e2 !important;
            border: 1px solid #fecaca !important;
            color: #dc2626 !important;
            font-weight: 700 !important;
        }
        [data-theme="light"] .pos-btn-clear:hover {
            background: #fecaca !important;
        }

        /* â”€â”€ MODAL DE CONTROL DE LAVADO DE CARGAS (Imagen 2) â”€â”€ */
        [data-theme="light"] .pos-lavado-modal-card {
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            color: #0f172a !important;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.18) !important;
        }
        [data-theme="light"] .pos-lavado-modal-head {
            background: #f8fafc !important;
            border-bottom: 1px solid #e2e8f0 !important;
        }
        [data-theme="light"] .pos-lavado-modal-head h3 {
            color: #0f172a !important;
            font-weight: 800 !important;
        }
        [data-theme="light"] .pos-lavado-modal-head p {
            color: #64748b !important;
        }
        [data-theme="light"] .pos-lavado-quick-bar {
            background: #f1f5f9 !important;
            border-bottom: 1px solid #e2e8f0 !important;
        }
        [data-theme="light"] .pos-lavado-quick-label {
            color: #1e293b !important;
            font-weight: 700 !important;
        }
        [data-theme="light"] .pos-lavado-select {
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            color: #0f172a !important;
        }
        [data-theme="light"] .pos-lavado-select:focus {
            border-color: #2563eb !important;
        }
        [data-theme="light"] .pos-lavado-kanban {
            background: #f8fafc !important;
        }
        [data-theme="light"] .pos-lavado-col {
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04) !important;
        }
        [data-theme="light"] .pos-lavado-col-header {
            background: #f1f5f9 !important;
            border-bottom: 1px solid #e2e8f0 !important;
            color: #0f172a !important;
        }
        [data-theme="light"] .pos-lavado-col-title {
            color: #0f172a !important;
            font-weight: 800 !important;
        }
        [data-theme="light"] .pos-lavado-col-count {
            background: #e2e8f0 !important;
            color: #1e293b !important;
            font-weight: 800 !important;
        }
        [data-theme="light"] .pos-lavado-col-search {
            background: #f8fafc !important;
            border-bottom: 1px solid #e2e8f0 !important;
        }
        [data-theme="light"] .pos-lavado-search-input {
            background: #ffffff !important;
            color: #0f172a !important;
            border: 1px solid #cbd5e1 !important;
        }
        [data-theme="light"] .pos-lavado-search-input::placeholder {
            color: #94a3b8 !important;
        }
        [data-theme="light"] .pos-lavado-empty {
            color: #64748b !important;
            font-weight: 600 !important;
            font-size: 0.85rem !important;
        }
        [data-theme="light"] .pos-lavado-card {
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06) !important;
        }
        [data-theme="light"] .pos-lavado-card:hover {
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.1) !important;
        }
        [data-theme="light"] .pos-lavado-numero {
            color: #0f172a !important;
            font-weight: 800 !important;
        }
        [data-theme="light"] .pos-lavado-cliente {
            color: #1e293b !important;
            font-weight: 700 !important;
        }
        [data-theme="light"] .pos-lavado-kg {
            color: #475569 !important;
            font-weight: 600 !important;
        }
        [data-theme="light"] .pos-lavado-btn-whatsapp:disabled {
            background: #e2e8f0 !important;
            color: #94a3b8 !important;
            border: 1px solid #cbd5e1 !important;
        }
        [data-theme="light"] .pos-lavado-no-tel {
            color: #ef4444 !important;
            font-weight: 700 !important;
        }
        [data-theme="light"] .pos-lavado-badge-entregado {
            background: #e0e7ff !important;
            color: #4338ca !important;
            border: 1px solid #c7d2fe !important;
        }
        [data-theme="light"] #pos-lavado-last-update {
            color: #64748b !important;
            font-weight: 600 !important;
        }

        /* â”€â”€ Modales Generales â”€â”€ */
        [data-theme="light"] .pos-modal-overlay {
            background: rgba(15, 23, 42, 0.45) !important;
            backdrop-filter: blur(6px) !important;
        }
        [data-theme="light"] .pos-modal-card {
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            color: #0f172a !important;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.18) !important;
        }
        [data-theme="light"] .pos-modal-head {
            background: #f8fafc !important;
            border-bottom: 1px solid #e2e8f0 !important;
            color: #0f172a !important;
        }
        [data-theme="light"] .pos-modal-head h3 {
            color: #0f172a !important;
            font-weight: 800 !important;
        }
        [data-theme="light"] .pos-modal-close {
            color: #64748b !important;
        }
        [data-theme="light"] .pos-modal-close:hover {
            color: #ef4444 !important;
            background: #fee2e2 !important;
        }
        [data-theme="light"] .pos-modal-body {
            background: #ffffff !important;
            color: #1e293b !important;
        }
        [data-theme="light"] .pos-modal-foot {
            background: #f8fafc !important;
            border-top: 1px solid #e2e8f0 !important;
        }
        [data-theme="light"] .pos-search-input,
        [data-theme="light"] textarea.pos-search-input {
            background: #ffffff !important;
            color: #0f172a !important;
            border: 1px solid #cbd5e1 !important;
        }
        [data-theme="light"] .pos-search-input:focus,
        [data-theme="light"] textarea.pos-search-input:focus {
            border-color: #2563eb !important;
            background: #ffffff !important;
        }
        [data-theme="light"] .pos-pay-opt {
            background: #f8fafc !important;
            border: 1px solid #cbd5e1 !important;
            color: #334155 !important;
        }
        [data-theme="light"] .pos-pay-opt:hover {
            background: #f1f5f9 !important;
            color: #0f172a !important;
        }
        [data-theme="light"] .pos-pay-opt.selected {
            background: #eff6ff !important;
            border-color: #2563eb !important;
            color: #1d4ed8 !important;
        }
        [data-theme="light"] .pos-pay-opt[data-method="PENDIENTE"].selected {
            background: #fffbeb !important;
            border-color: #f59e0b !important;
            color: #b45309 !important;
        }
        [data-theme="light"] .pos-denom-btn {
            background: #f1f5f9 !important;
            border: 1px solid #cbd5e1 !important;
            color: #1e293b !important;
            font-weight: 700 !important;
        }
        [data-theme="light"] .pos-denom-btn:hover {
            background: #e2e8f0 !important;
        }
        [data-theme="light"] .pos-change-box {
            background: #ecfdf5 !important;
            border: 1px solid #a7f3d0 !important;
        }
        [data-theme="light"] .pos-change-label {
            color: #065f46 !important;
        }
        [data-theme="light"] .pos-change-val {
            color: #047857 !important;
        }

        /* Modales de ConfirmaciÃ³n y NotificaciÃ³n */
        [data-theme="light"] #modal-logout-pos > div:last-child,
        [data-theme="light"] #modal-backoffice-confirm > div:last-child,
        [data-theme="light"] #modal-notificacion-pos > div:last-child,
        [data-theme="light"] #modal-confirmacion-pos > div:last-child {
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.18) !important;
        }
        [data-theme="light"] #modal-logout-pos h3,
        [data-theme="light"] #modal-backoffice-confirm h3,
        [data-theme="light"] #notif-pos-titulo,
        [data-theme="light"] #confirm-pos-titulo {
            color: #0f172a !important;
        }
        [data-theme="light"] #modal-logout-pos p,
        [data-theme="light"] #modal-backoffice-confirm p,
        [data-theme="light"] #notif-pos-mensaje,
        [data-theme="light"] #confirm-pos-mensaje {
            color: #475569 !important;
        }
        [data-theme="light"] #btn-logout-pos-cancel,
        [data-theme="light"] #modal-backoffice-confirm button[onclick="cancelarIngresoBackoffice()"],
        [data-theme="light"] #confirm-pos-btn-no {
            background: #f1f5f9 !important;
            border: 1px solid #cbd5e1 !important;
            color: #475569 !important;
        }
    </style>
</head>
<body>

    <!-- ═════════════════════════════════════════════════════════════
         POS Top Bar
         ═════════════════════════════════════════════════════════════ -->
    <header class="pos-header">
        <a href="javascript:void(0)" class="pos-brand">
            <img src="img/Logo.jpeg" alt="Logo" class="pos-logo">
            <div class="pos-title-wrap">
                <h1>Lavandería Vera <span class="pos-badge-front">FrontOffice POS</span></h1>
            </div>
        </a>

        <div class="pos-header-center">
            <div id="pos-caja-status" class="pos-caja-pill cerrada" title="Haz clic para ver arqueo o corte">
                <span class="pos-dot-pulse"></span>
                <span>Cargando turno...</span>
            </div>
        </div>

        <div class="pos-header-actions">
            <!-- Botón Cobros Pendientes (Pago Posterior) -->
            <button class="pos-btn-head" onclick="POS.abrirModalPendientes()" title="Ver entregas y cobros pendientes (F8)">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span>Cobros Pendientes</span>
                <span id="pos-pendientes-count" class="pos-badge-count" style="display:none;">0</span>
            </button>

            <!-- Botón Panel de Lavado de Cargas -->
            <button class="pos-btn-head pos-btn-lavado-head" id="pos-btn-lavado-head" onclick="POS.abrirModalLavado()" title="Panel de seguimiento de lavado de cargas">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                <span>Lavado</span>
                <span id="pos-lavado-badge" class="pos-badge-count" style="display:none; background:#3b82f6;">0</span>
            </button>

                        <!-- Botón Corte de Caja -->
            <button class="pos-btn-head" onclick="POS.abrirModalCorte()" title="Arqueo y Cierre de Turno (F9)">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M12 8v8m-4-4h8"/></svg>
                <span>Corte de Caja</span>
            </button>

            <!-- Botón Historial de Ventas -->
            <button class="pos-btn-head" onclick="POS.abrirModalHistorial()" title="Historial del día y Cancelaciones">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                <span>Historial Hoy</span>
            </button>

            <!-- Boton Tema Claro/Oscuro -->
            <button class="pos-theme-btn" id="pos-theme-btn" onclick="ThemeManager.toggle()" title="Cambiar tema claro / oscuro">
                <span class="ts-track">
                    <span class="ts-icon icon-sun">&#9728;&#65039;</span>
                    <span class="ts-knob"></span>
                    <span class="ts-icon icon-moon">&#127769;</span>
                </span>
            </button>

            <!-- Reloj Digital -->
            <div id="pos-clock" class="pos-clock">--:--:--</div>

            <!-- Cajero en turno -->
            <div class="pos-btn-head pos-user-badge" style="cursor:default;" title="Cajero autenticado en el POS">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                <span><strong><?= htmlspecialchars($userName) ?></strong></span>
            </div>

            <!-- Botón Cerrar Sesión POS -->
            <a href="javascript:void(0)" onclick="confirmarCerrarSesionPos()" class="pos-btn-head" style="background:rgba(239,68,68,0.12); border-color:rgba(239,68,68,0.3); color:#fca5a5;" title="Cerrar sesión de este cajero en el POS">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                <span>Salir POS</span>
            </a>

            <!-- Botón Acceder al Backoffice (Confirmación previa) -->
            <button type="button" class="pos-btn-head" onclick="confirmarIngresoBackoffice()" style="background:rgba(59,130,246,0.15); border-color:rgba(59,130,246,0.3); color:#93c5fd;" title="Acceder al Backoffice (requiere confirmación e inicio de sesión)">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                <span>Backoffice</span>
            </button>
        </div>
    </header>

    <!-- ═════════════════════════════════════════════════════════════
         POS Main Body
         ═════════════════════════════════════════════════════════════ -->
    <main class="pos-body">
        
        <!-- Left: Product / Services Catalog -->
        <section class="pos-catalog-panel">
            
            <!-- Panel de Selección Directa (Cliente + Producto/Servicio + Kilos) -->
            <div class="pos-quick-entry-card">
                <div class="pos-entry-header">
                    <div class="pos-entry-title">
                        <svg style="width:18px;height:18px;color:#38bdf8;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span id="pos-entry-title-text">Registro de Servicio / Venta por Kilos</span>
                    </div>
                    <span id="pos-entry-preview" class="pos-entry-preview-badge">Subtotal: $0.00</span>
                </div>

                <div id="pos-entry-grid" class="pos-entry-grid">
                    <!-- 1. Elegir Cliente de los Registrados -->
                    <div class="pos-entry-field">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <label class="pos-entry-label" for="pos-cliente-search-input">1. Cliente Registrado (*)</label>
                            <button type="button" class="pos-btn-cust" style="padding:0.15rem 0.45rem; font-size:0.7rem;" onclick="POS.openModal('modal-nuevo-cliente')" title="Registrar nuevo cliente">
                                + Nuevo
                            </button>
                        </div>
                        <div class="pos-search-prod-wrapper" style="position:relative;">
                            <svg class="pos-search-prod-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                            </svg>
                            <input type="text" id="pos-cliente-search-input" class="pos-form-input pos-search-input-field"
                                placeholder="Buscar cliente o Público General..."
                                autocomplete="off"
                                oninput="POS.onSearchClienteInput(this.value)"
                                onfocus="POS.onSearchClienteFocus()"
                                onkeydown="POS.onSearchClienteKeydown(event)">
                            <input type="hidden" id="pos-selected-cliente-id" value="MOSTRADOR">
                            <button type="button" id="pos-btn-clear-cliente" class="pos-btn-clear-prod" style="display:none;" onclick="POS.clearClienteSeleccionado()" title="Limpiar cliente">✕</button>
                            <!-- Dropdown flotante con resultados de clientes -->
                            <div id="pos-cliente-dropdown-results" class="pos-prod-dropdown-results" style="display:none;"></div>
                        </div>
                        <div id="pos-selected-client-hint" style="display:none;"></div>
                    </div>

                    <!-- 2. Buscar Producto o Servicio por Nombre o Código de Barras -->
                    <div id="pos-field-producto-col" class="pos-entry-field pos-prod-search-col">
                        <label class="pos-entry-label" for="pos-search-producto-input">2. Producto o Servicio (*)</label>
                        <div class="pos-search-prod-wrapper">
                            <svg class="pos-search-prod-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                            </svg>
                            <input type="text" id="pos-search-producto-input" class="pos-form-input pos-search-input-field" placeholder="Buscar por nombre, SKU o código de barras..." autocomplete="off" oninput="POS.onSearchProductoInput(this.value)" onfocus="POS.onSearchProductoFocus()" onkeydown="POS.onSearchProductoKeydown(event)">
                            <input type="hidden" id="pos-selected-producto-id" value="">
                            <button type="button" id="pos-btn-clear-prod" class="pos-btn-clear-prod" style="display:none;" onclick="POS.clearProductoSeleccionado()" title="Limpiar producto">✕</button>
                            
                            <!-- Dropdown flotante con resultados -->
                            <div id="pos-producto-dropdown-results" class="pos-prod-dropdown-results" style="display:none;"></div>
                        </div>
                    </div>

                                        <!-- 3. Campo de Cantidad de Kilos / Insumos -->
                    <div id="pos-field-kilos-col" class="pos-entry-field">
                        <label class="pos-entry-label" id="pos-label-cantidad" for="pos-input-kilos">3. Cantidad de Kilos</label>
                        <div class="pos-qty-wrapper">
                            <div class="number-input-wrapper">
                                <input type="number" step="0.05" min="0.01" id="pos-input-kilos" class="pos-form-input" style="font-family:'JetBrains Mono',monospace; font-weight:700;" placeholder="Ej. 4.5" value="1.0" oninput="POS.calcPreviewCosto()" onkeydown="if(event.key==='Enter'){event.preventDefault(); POS.agregarRegistroFormulario();}">
                                <div class="spin-buttons">
                                    <button type="button" class="spin-btn" onclick="POS.stepKilos(0.5)" title="Aumentar">▲</button>
                                    <button type="button" class="spin-btn" onclick="POS.stepKilos(-0.5)" title="Disminuir">▼</button>
                                </div>
                            </div>
                            <select id="pos-select-unidad-insumo" class="pos-form-select pos-unit-select" style="display:none;" onchange="POS.onUnidadInsumoChange()">
                                <!-- Opciones dinámicas para Insumos -->
                            </select>
                        </div>
                    </div>

                    <!-- 4. Botón Agregar a la Lista -->
                    <div id="pos-field-btn-col" class="pos-entry-field">
                        <button type="button" id="pos-btn-add-reg" class="pos-btn-add-reg" onclick="POS.agregarRegistroFormulario()">
                            <svg style="width:18px;height:18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                            <span>Agregar a Lista</span>
                        </button>
                    </div>
                </div>
            </div>


            <div class="pos-catalog-controls" style="padding: 0.65rem 1.25rem;">
                <div class="pos-filter-tabs">
                    <button class="pos-tab active" onclick="POS.setCategory('ALL', this)">
                        <span>✨ Todos</span>
                    </button>
                    <button class="pos-tab" onclick="POS.setCategory('SERVICIO', this)">
                        <span>🧺 Servicios de Lavandería</span>
                    </button>
                    <button class="pos-tab" onclick="POS.setCategory('NORMAL', this)">
                        <span>📦 Productos</span>
                    </button>
                    <button class="pos-tab" onclick="POS.setCategory('INSUMO', this)">
                        <span>🧴 Insumos</span>
                    </button>
                    <button class="pos-tab" onclick="POS.setCategory('KIT', this)">
                        <span>🎁 Kits y Paquetes</span>
                    </button>
                </div>
            </div>

            <!-- Product Grid -->
            <div id="pos-product-grid" class="pos-product-grid">
                <div style="grid-column: 1/-1; text-align: center; padding: 3rem; color: var(--pos-text-muted);">
                    Cargando catálogo...
                </div>
            </div>
        </section>

        <!-- Right: Order Ticket & Checkout Terminal -->
        <aside class="pos-cart-panel">
            
            <!-- Customer Bar -->
            <div class="pos-customer-bar">
                <div class="pos-cust-current">
                    <div class="pos-cust-label">Lista de Registros a Cobrar</div>
                    <div id="pos-cust-name" class="pos-cust-name">0 registros acumulados</div>
                </div>
                <div style="display:flex; gap:0.4rem;">
                    <button class="pos-btn-cust" onclick="POS.openModal('modal-buscar-cliente')" title="Buscar cliente existente">
                        <svg style="width:14px;height:14px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <span>Clientes</span>
                    </button>
                    <button class="pos-btn-cust" onclick="POS.openModal('modal-nuevo-cliente')" title="Registrar nuevo cliente">
                        <span>+ Nuevo</span>
                    </button>
                </div>
            </div>

            <!-- Encabezado de Cliente para la Orden / Carrito -->
            <div id="pos-cart-client-header" class="pos-cart-client-header" style="display: none;">
                <div class="pos-cart-client-badge" onclick="POS.openModal('modal-buscar-cliente')" style="cursor: pointer;" title="Haz clic para cambiar el cliente asignado a estos registros">
                    <span class="pos-cart-client-icon">👤</span>
                    <div class="pos-cart-client-text" style="flex: 1; min-width: 0;">
                        <span class="pos-cart-client-label">Cliente:</span>
                        <strong id="pos-cart-client-name">Público General / Mostrador</strong>
                        <span id="pos-cart-client-tel" class="pos-cart-client-tel"></span>
                    </div>
                    <button type="button" class="pos-btn-cust" style="padding: 0.25rem 0.6rem; font-size: 0.75rem; margin-left: 0.5rem; flex-shrink: 0;" onclick="event.stopPropagation(); POS.openModal('modal-buscar-cliente');" title="Cambiar cliente">
                        ✏️ Cambiar
                    </button>
                </div>
            </div>

            <!-- Scrollable Items List -->
            <div id="pos-cart-items" class="pos-items-list">
                <div class="pos-empty-cart">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                    <p style="font-weight:600; font-size:1.05rem;">Carrito Vacío</p>
                    <p style="font-size:0.8rem; margin-top:0.25rem;">Selecciona productos o escanea con el lector de código de barras.</p>
                </div>
            </div>

            <!-- Cart Summary & Action Buttons -->
            <footer class="pos-cart-footer">
                <div class="pos-summary-row">
                    <span>Artículos en orden:</span>
                    <strong id="pos-summary-items">0</strong>
                </div>
                <div class="pos-summary-row" id="pos-row-summary-kg" style="display:none;">
                    <span>Peso total de ropa:</span>
                    <strong id="pos-summary-kg">0 kg</strong>
                </div>
                <div class="pos-summary-total">
                    <span class="pos-total-label">TOTAL A COBRAR:</span>
                    <span id="pos-total-display" class="pos-total-amount">$0.00</span>
                </div>

                <div class="pos-actions-grid">
                    <!-- Botón Principal Cobrar -->
                    <button id="pos-btn-pay" class="pos-btn-pay" onclick="POS.abrirModalCobro()" disabled title="Cobro inmediato (F4)">
                        <svg style="width:22px;height:22px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                        <span>COBRAR $0.00</span>
                    </button>

                    <!-- Acciones Secundarias: Pago Posterior y Limpiar -->
                    <div class="pos-secondary-actions">
                        <button id="pos-btn-pending" class="pos-btn-pending" onclick="POS.confirmarVenta(true)" disabled title="Spec 05 / 06: Dejar ropa y cobrar al recoger">
                            <svg style="width:16px;height:16px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <span>Dejar Pendiente (Cobro al entregar)</span>
                        </button>
                        <button class="pos-btn-clear" onclick="POS.clearCart()" title="Vaciar carrito">
                            Vaciar
                        </button>
                    </div>
                </div>
            </footer>
        </aside>
    </main>

    <!-- ═════════════════════════════════════════════════════════════
         MODAL 1: Cobro Inmediato
         ═════════════════════════════════════════════════════════════ -->
    <div id="modal-cobro" class="pos-modal-overlay">
        <div class="pos-modal-card">
            <div class="pos-modal-head">
                <h3>💳 Procesar Cobro de Venta</h3>
                <button class="pos-modal-close" onclick="POS.closeModal('modal-cobro')">✕</button>
            </div>
            <div class="pos-modal-body">
                <div style="text-align:center; padding:0.5rem 0;">
                    <div style="font-size:0.85rem; color:var(--pos-text-muted); font-weight:600; text-transform:uppercase;">Total a Pagar</div>
                    <div id="pos-pay-total-due" style="font-size:2.8rem; font-weight:900; color:#10b981; font-family:'JetBrains Mono',monospace;">$0.00</div>
                </div>

                <!-- Métodos de Pago -->
                <div>
                    <label style="font-size:0.8rem; font-weight:700; color:var(--pos-text-muted); margin-bottom:0.5rem; display:block;">Método de Pago</label>
                    <div class="pos-pay-methods">
                        <div class="pos-pay-opt selected" data-method="EFECTIVO" onclick="POS.setPayMethod('EFECTIVO')">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/></svg>
                            <span>Efectivo</span>
                        </div>
                        <div class="pos-pay-opt" data-method="TARJETA" onclick="POS.setPayMethod('TARJETA')">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                            <span>Tarjeta Déb/Créd</span>
                        </div>
                        <div class="pos-pay-opt" data-method="TRANSFERENCIA" onclick="POS.setPayMethod('TRANSFERENCIA')">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            <span>Transferencia</span>
                        </div>
                        <div class="pos-pay-opt" data-method="PENDIENTE" onclick="POS.setPayMethod('PENDIENTE')" title="Dejar ropa o venta pendiente de pago para liquidar después">
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <span>Pendiente de Pago</span>
                        </div>
                    </div>
                </div>

                <!-- Detalle Efectivo -->
                <div id="pos-pay-cash-details" style="display:flex; flex-direction:column; gap:1rem;">
                    <div>
                        <label style="font-size:0.8rem; font-weight:700; color:var(--pos-text-muted); margin-bottom:0.4rem; display:block;">Monto Recibido</label>
                        <input type="number" step="0.01" id="pos-pay-received" class="pos-search-input" style="font-size:1.5rem; font-weight:800; font-family:'JetBrains Mono',monospace; text-align:center; padding:0.6rem;" oninput="POS.calcCambio()">
                    </div>

                    <!-- Botones de Billetes Rápidos -->
                    <div>
                        <div style="font-size:0.75rem; color:var(--pos-text-muted); margin-bottom:0.35rem;">Billetes rápidos:</div>
                        <div class="pos-denominations">
                            <button class="pos-denom-btn" onclick="POS.setDenomination('exact')">Exacto</button>
                            <button class="pos-denom-btn" onclick="POS.setDenomination(50)">$50</button>
                            <button class="pos-denom-btn" onclick="POS.setDenomination(100)">$100</button>
                            <button class="pos-denom-btn" onclick="POS.setDenomination(200)">$200</button>
                            <button class="pos-denom-btn" onclick="POS.setDenomination(500)">$500</button>
                            <button class="pos-denom-btn" onclick="POS.setDenomination(1000)">$1000</button>
                        </div>
                    </div>

                    <!-- Caja de Cambio -->
                    <div class="pos-change-box">
                        <span class="pos-change-label">Cambio a Entregar:</span>
                        <span id="pos-pay-change-val" class="pos-change-val">$0.00</span>
                    </div>
                </div>
            </div>
            <div class="pos-modal-foot">
                <button class="pos-btn-clear" onclick="POS.closeModal('modal-cobro')">Cancelar</button>
                <button id="pos-pay-confirm-btn" class="pos-btn-pay" style="padding:0.75rem 1.75rem; font-size:1rem;" onclick="POS.confirmarVenta(false)">
                    Confirmar Cobro e Imprimir Ticket
                </button>
            </div>
        </div>
    </div>

    <!-- ═════════════════════════════════════════════════════════════
         MODAL 2: Cobros Pendientes (Pago Posterior)
         ═════════════════════════════════════════════════════════════ -->
    <div id="modal-pendientes" class="pos-modal-overlay">
        <div class="pos-modal-card" style="max-width:720px;">
            <div class="pos-modal-head">
                <h3>🕒 Cobros y Entregas Pendientes (Pago Posterior)</h3>
                <button class="pos-modal-close" onclick="POS.closeModal('modal-pendientes')">✕</button>
            </div>
            <div class="pos-modal-body">
                <div class="pos-search-wrap">
                    <svg class="pos-search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" class="pos-search-input" placeholder="Buscar por cliente, teléfono, folio o número de carga..." oninput="POS.cargarPendientes(this.value)">
                </div>

                <div id="pos-pendientes-list" style="display:flex; flex-direction:column; gap:0.75rem; max-height:450px; overflow-y:auto;">
                    <!-- Llenado dinámico -->
                </div>
            </div>
            <div class="pos-modal-foot">
                <button class="pos-btn-clear" onclick="POS.closeModal('modal-pendientes')">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- ═════════════════════════════════════════════════════════════
         MODAL 3: Corte de Caja (Arqueo y Cierre)
         ═════════════════════════════════════════════════════════════ -->
    <div id="modal-corte-caja" class="pos-modal-overlay">
        <div class="pos-modal-card">
            <div class="pos-modal-head">
                <h3>💵 Corte y Arqueo de Caja</h3>
                <button class="pos-modal-close" onclick="POS.closeModal('modal-corte-caja')">✕</button>
            </div>
            <div class="pos-modal-body">
                <div style="background:rgba(15,23,42,0.6); border:1px solid var(--pos-border); border-radius:10px; padding:1rem; display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; font-size:0.85rem;">
                    <div>Fondo Inicial de Caja:</div>
                    <div id="corte-fondo-inicial" style="font-weight:700; text-align:right;">$0.00</div>
                    <div>Ventas en Efectivo:</div>
                    <div id="corte-efectivo-ventas" style="font-weight:700; color:#34d399; text-align:right;">$0.00</div>
                    <div>Ventas con Tarjeta:</div>
                    <div id="corte-tarjeta-ventas" style="font-weight:700; text-align:right;">$0.00</div>
                    <div>Ventas por Transferencia:</div>
                    <div id="corte-transf-ventas" style="font-weight:700; text-align:right;">$0.00</div>
                    <div>Ventas realizadas en turno:</div>
                    <div id="corte-cant-ventas" style="font-weight:700; text-align:right;">0</div>
                    <div style="border-top:1px solid rgba(255,255,255,0.1); padding-top:0.5rem; font-weight:800;">Efectivo Esperado en Caja:</div>
                    <div id="corte-esperado-efectivo" style="border-top:1px solid rgba(255,255,255,0.1); padding-top:0.5rem; font-weight:800; font-size:1.1rem; color:#34d399; text-align:right;">$0.00</div>
                </div>

                <div>
                    <label style="font-size:0.85rem; font-weight:700; color:var(--pos-text-muted); display:block; margin-bottom:0.4rem;">
                        Efectivo Real Contado en Caja ($):
                    </label>
                    <input type="number" step="0.01" id="corte-efectivo-declarado" class="pos-search-input" style="font-size:1.4rem; font-weight:800; text-align:center; font-family:'JetBrains Mono',monospace;">
                </div>

                <div class="pos-change-box" style="justify-content:space-between;">
                    <div>
                        <div class="pos-change-label">Diferencia de Caja:</div>
                        <span id="corte-diferencia-badge" class="pos-badge-front" style="margin-top:0.25rem;">Caja Cuadrada</span>
                    </div>
                    <div id="corte-diferencia-val" class="pos-change-val">$0.00</div>
                </div>

                <div>
                    <label style="font-size:0.8rem; font-weight:600; color:var(--pos-text-muted); display:block; margin-bottom:0.3rem;">Observaciones de Cierre:</label>
                    <textarea id="corte-observaciones" class="pos-search-input" rows="2" placeholder="Notas sobre el turno, incidencias o motivos de diferencia..."></textarea>
                </div>
            </div>
            <div class="pos-modal-foot">
                <button class="pos-btn-clear" onclick="POS.closeModal('modal-corte-caja')">Cancelar</button>
                <button class="pos-btn-pay" style="background:#ef4444; box-shadow:0 4px 15px rgba(239,68,68,0.4);" onclick="POS.cerrarCajaConfirm()">
                    🔒 Cerrar Turno de Caja
                </button>
            </div>
        </div>
    </div>

    <!-- ═════════════════════════════════════════════════════════════
         MODAL 4: Apertura de Turno de Caja
         ═════════════════════════════════════════════════════════════ -->
    <div id="modal-apertura-caja" class="pos-modal-overlay">
        <div class="pos-modal-card" style="max-width:440px;">
            <div class="pos-modal-head">
                <h3>🔓 Apertura de Turno de Caja</h3>
                <button class="pos-modal-close" onclick="POS.closeModal('modal-apertura-caja')">✕</button>
            </div>
            <form onsubmit="event.preventDefault(); POS.abrirCajaForm(this);">
                <div class="pos-modal-body">
                    <p style="font-size:0.85rem; color:var(--pos-text-muted);">
                        Para comenzar a registrar ventas y cobros en el FrontOffice, ingresa el monto de fondo inicial en efectivo disponible en el cajón.
                    </p>
                    <div>
                        <label style="font-size:0.85rem; font-weight:700; color:var(--pos-text-muted); display:block; margin-bottom:0.4rem;">Fondo Inicial en Efectivo ($):</label>
                        <input type="number" step="0.01" min="0" name="monto_inicial" class="pos-search-input" style="font-size:1.6rem; font-weight:800; text-align:center; font-family:'JetBrains Mono',monospace;" value="500.00" required autofocus>
                    </div>
                    <div>
                        <label style="font-size:0.8rem; font-weight:600; color:var(--pos-text-muted); display:block; margin-bottom:0.3rem;">Notas de Apertura:</label>
                        <input type="text" name="observaciones" class="pos-search-input" placeholder="Ej. Cambio en billetes de $50 y $20...">
                    </div>
                </div>
                <div class="pos-modal-foot">
                    <button type="submit" class="pos-btn-pay" style="width:100%; justify-content:center;">
                        Abrir Caja y Comenzar Turno
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ═════════════════════════════════════════════════════════════
         MODAL 5: Historial de Ventas del Día y Cancelaciones
         ═════════════════════════════════════════════════════════════ -->
    <div id="modal-historial" class="pos-modal-overlay">
        <div class="pos-modal-card" style="max-width:760px;">
            <div class="pos-modal-head">
                <h3>📜 Historial de Ventas de Hoy</h3>
                <button class="pos-modal-close" onclick="POS.closeModal('modal-historial')">✕</button>
            </div>
            <div class="pos-modal-body">
                <div id="pos-historial-list" style="display:flex; flex-direction:column; gap:0.65rem; max-height:480px; overflow-y:auto;">
                    <!-- Llenado dinámico -->
                </div>
            </div>
            <div class="pos-modal-foot">
                <button class="pos-btn-clear" onclick="POS.closeModal('modal-historial')">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- ═════════════════════════════════════════════════════════════
         MODAL 6: Cancelar Venta (Spec 05 Regla 2, 3 y Fase 12)
         ═════════════════════════════════════════════════════════════ -->
    <div id="modal-cancelar-venta" class="pos-modal-overlay">
        <div class="pos-modal-card" style="max-width:480px;">
            <div class="pos-modal-head">
                <h3 style="color:#ef4444;">⚠️ Cancelación de Venta</h3>
                <button class="pos-modal-close" onclick="POS.closeModal('modal-cancelar-venta')">✕</button>
            </div>
            <form onsubmit="event.preventDefault(); POS.confirmarCancelacion(this);">
                <input type="hidden" name="venta_id" id="cancel-venta-id">
                <div class="pos-modal-body">
                    <p style="font-size:0.85rem; color:var(--pos-text-muted);">
                        Estás a punto de cancelar la venta <strong id="cancel-folio-txt" style="color:#fff;"></strong>. Esta acción reincorporará el inventario al stock pero conservará el registro por auditoría.
                    </p>
                    <div>
                        <label style="font-size:0.85rem; font-weight:700; color:#fff; display:block; margin-bottom:0.3rem;">
                            Motivo de la Cancelación (Obligatorio):
                        </label>
                        <textarea name="motivo" id="cancel-motivo" class="pos-search-input" rows="3" placeholder="Describe claramente la razón de la cancelación..." required></textarea>
                    </div>

                    <!-- Autorización si no es Admin -->
                    <div id="cancel-admin-auth" style="display:none; background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); border-radius:8px; padding:0.85rem;">
                        <div style="font-size:0.8rem; font-weight:700; color:#f87171; margin-bottom:0.4rem;">
                            🔒 Requiere Autorización de Administrador
                        </div>
                        <input type="password" name="admin_password" id="cancel-admin-pass" class="pos-search-input" placeholder="Contraseña de Administrador...">
                    </div>
                </div>
                <div class="pos-modal-foot">
                    <button type="button" class="pos-btn-clear" onclick="POS.closeModal('modal-cancelar-venta')">Cancelar</button>
                    <button type="submit" class="pos-btn-pay" style="background:#ef4444; box-shadow:0 4px 15px rgba(239,68,68,0.4);">
                        Confirmar Cancelación
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ═════════════════════════════════════════════════════════════
         MODAL 7: Ticket Térmico Imprimible
         ═════════════════════════════════════════════════════════════ -->
    <div id="modal-ticket" class="pos-modal-overlay">
        <div class="pos-modal-card" style="max-width:480px;">
            <div class="pos-modal-head pos-no-print">
                <h3>🖨️ Ticket de Venta / Carga</h3>
                <button class="pos-modal-close" onclick="POS.closeModal('modal-ticket')">✕</button>
            </div>
            <div class="pos-modal-body" id="pos-ticket-content" style="background:#1f2937; padding:1.25rem;">
                <!-- Se llena dinámicamente con .pos-ticket-paper -->
            </div>
            <div class="pos-modal-foot pos-no-print">
                <button class="pos-btn-clear" onclick="POS.closeModal('modal-ticket')">Cerrar</button>
                <button type="button" id="pos-btn-send-whatsapp" class="pos-btn-whatsapp" onclick="POS.enviarTicketWhatsapp()" disabled title="No disponible para Público General">
                    <svg style="width:18px;height:18px;fill:currentColor;" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                    Enviar Ticket
                </button>
                <button class="pos-btn-pay" onclick="POS.imprimirTicketActual()">
                    🖨️ Imprimir Ticket (80mm)
                </button>
            </div>
        </div>
    </div>

    <!-- ═════════════════════════════════════════════════════════════
         MODAL 8: Buscar Cliente
         ═════════════════════════════════════════════════════════════ -->
    <div id="modal-buscar-cliente" class="pos-modal-overlay">
        <div class="pos-modal-card" style="max-width:520px;">
            <div class="pos-modal-head">
                <h3>🔍 Seleccionar Cliente</h3>
                <button class="pos-modal-close" onclick="POS.closeModal('modal-buscar-cliente')">✕</button>
            </div>
            <div class="pos-modal-body">
                <div class="pos-search-wrap">
                    <svg class="pos-search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" class="pos-search-input" placeholder="Buscar por nombre o teléfono..." oninput="POS.buscarClientes(this.value)">
                </div>

                <div style="margin-top:0.5rem; display:flex; justify-content:space-between; align-items:center;">
                    <button class="pos-btn-head" style="font-size:0.75rem;" onclick="POS.setClient(null)">
                        👤 Público General / Mostrador
                    </button>
                    <button class="pos-btn-cust" onclick="POS.closeModal('modal-buscar-cliente'); POS.openModal('modal-nuevo-cliente');">
                        + Registrar Nuevo Cliente
                    </button>
                </div>

                <div id="pos-clientes-result" style="max-height:300px; overflow-y:auto; border:1px solid var(--pos-border); border-radius:8px; margin-top:0.5rem;">
                    <!-- Llenado dinámico -->
                </div>
            </div>
            <div class="pos-modal-foot">
                <button class="pos-btn-clear" onclick="POS.closeModal('modal-buscar-cliente')">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- ═════════════════════════════════════════════════════════════
         MODAL 9: Nuevo Cliente Express
         ═════════════════════════════════════════════════════════════ -->
    <div id="modal-nuevo-cliente" class="pos-modal-overlay">
        <div class="pos-modal-card" style="max-width:460px;">
            <div class="pos-modal-head">
                <h3>👤 Alta Rápida de Cliente</h3>
                <button class="pos-modal-close" onclick="POS.closeModal('modal-nuevo-cliente')">✕</button>
            </div>
            <form onsubmit="event.preventDefault(); POS.guardarNuevoClienteExpress(this);">
                <div class="pos-modal-body">
                    <div>
                        <label style="font-size:0.8rem; font-weight:600; color:var(--pos-text-muted); display:block; margin-bottom:0.25rem;">Nombre Completo (*):</label>
                        <input type="text" name="nombre" class="pos-search-input" placeholder="Ej. Juan Pérez" required autofocus>
                    </div>
                    <div>
                        <label style="font-size:0.8rem; font-weight:600; color:var(--pos-text-muted); display:block; margin-bottom:0.25rem;">Teléfono (*):</label>
                        <input type="tel" name="telefono" class="pos-search-input" placeholder="Ej. 9991234567" required>
                    </div>
                    <div style="display:flex; align-items:center; gap:0.5rem;">
                        <input type="checkbox" id="cli-whatsapp" name="whatsapp_disponible" value="1" checked style="width:18px;height:18px;cursor:pointer;">
                        <label for="cli-whatsapp" style="font-size:0.82rem; color:#fff; cursor:pointer;">Tiene WhatsApp para notificaciones</label>
                    </div>
                    <div>
                        <label style="font-size:0.8rem; font-weight:600; color:var(--pos-text-muted); display:block; margin-bottom:0.25rem;">Dirección (Opcional):</label>
                        <input type="text" name="direccion" class="pos-search-input" placeholder="Calle, número, colonia...">
                    </div>
                </div>
                <div class="pos-modal-foot">
                    <button type="button" class="pos-btn-clear" onclick="POS.closeModal('modal-nuevo-cliente')">Cancelar</button>
                    <button type="submit" class="pos-btn-pay" style="padding:0.6rem 1.25rem; font-size:0.9rem;">
                        Guardar y Asignar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal de confirmación de cierre de sesión POS (Idéntico a BackOffice) -->
    <div id="modal-logout-pos" style="display:none; position:fixed; inset:0; z-index:99999; align-items:center; justify-content:center;">
        <div id="modal-logout-pos-backdrop" onclick="cancelarCerrarSesionPos()" style="position:absolute; inset:0; background:rgba(0,0,0,0.65); backdrop-filter:blur(4px);"></div>
        <div style="position:relative; background:#1e1e2e; border:1px solid rgba(255,255,255,0.1); border-radius:16px; padding:2rem 2.5rem; width:100%; max-width:380px; box-shadow:0 25px 60px rgba(0,0,0,0.5); text-align:center; animation:modalInPos .25s ease;">
            <div style="width:56px; height:56px; border-radius:50%; background:rgba(239,68,68,0.15); display:flex; align-items:center; justify-content:center; margin:0 auto 1.25rem;">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </div>
            <h3 style="font-size:1.15rem; font-weight:600; color:#e2e8f0; margin-bottom:.5rem;">¿Cerrar sesión?</h3>
            <p style="font-size:.875rem; color:#94a3b8; margin-bottom:1.75rem; line-height:1.4;">Se cerrará tu sesión actual y tendrás que volver a iniciar sesión para acceder al sistema.</p>
            <div style="display:flex; gap:.75rem; justify-content:center;">
                <button type="button" id="btn-logout-pos-cancel" onclick="cancelarCerrarSesionPos()" style="flex:1; padding:.65rem 1rem; border-radius:8px; border:1px solid rgba(255,255,255,0.12); background:transparent; color:#94a3b8; font-size:.875rem; cursor:pointer; transition:all .2s;" onmouseover="this.style.background='rgba(255,255,255,0.06)'" onmouseout="this.style.background='transparent'">Cancelar</button>
                <a id="btn-logout-pos-confirm" href="pos_logout.php" style="flex:1; padding:.65rem 1rem; border-radius:8px; border:none; background:#ef4444; color:#fff; font-size:.875rem; font-weight:600; cursor:pointer; text-decoration:none; display:flex; align-items:center; justify-content:center; transition:all .2s;" onmouseover="this.style.background='#dc2626'" onmouseout="this.style.background='#ef4444'">Sí, cerrar sesión</a>
            </div>
        </div>
    </div>

    <!-- Modal de confirmación de acceso al BackOffice -->
    <div id="modal-backoffice-confirm" style="display:none; position:fixed; inset:0; z-index:99999; align-items:center; justify-content:center;">
        <div id="modal-backoffice-backdrop" onclick="cancelarIngresoBackoffice()" style="position:absolute; inset:0; background:rgba(0,0,0,0.65); backdrop-filter:blur(4px);"></div>
        <div style="position:relative; background:#1e1e2e; border:1px solid rgba(255,255,255,0.1); border-radius:16px; padding:2rem 2.5rem; width:100%; max-width:390px; box-shadow:0 25px 60px rgba(0,0,0,0.5); text-align:center; animation:modalInPos .25s ease;">
            <div style="width:56px; height:56px; border-radius:50%; background:rgba(59,130,246,0.15); display:flex; align-items:center; justify-content:center; margin:0 auto 1.25rem;">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            </div>
            <h3 style="font-size:1.15rem; font-weight:600; color:#e2e8f0; margin-bottom:.5rem;">¿Ingresar al BackOffice?</h3>
            <p style="font-size:.875rem; color:#94a3b8; margin-bottom:1.75rem; line-height:1.4;">Accederás al panel de administración. Por seguridad se abrirá en una pestaña dedicada y se requerirá iniciar sesión con una cuenta de <strong>Administrador</strong>.</p>
            <div style="display:flex; gap:.75rem; justify-content:center;">
                <button type="button" onclick="cancelarIngresoBackoffice()" style="flex:1; padding:.65rem 1rem; border-radius:8px; border:1px solid rgba(255,255,255,0.12); background:transparent; color:#94a3b8; font-size:.875rem; cursor:pointer; transition:all .2s;" onmouseover="this.style.background='rgba(255,255,255,0.06)'" onmouseout="this.style.background='transparent'">Cancelar</button>
                <a href="login.php?from=pos" target="_blank" rel="noopener noreferrer" onclick="cancelarIngresoBackoffice()" style="flex:1; padding:.65rem 1rem; border-radius:8px; border:none; background:#3b82f6; color:#fff; font-size:.875rem; font-weight:600; cursor:pointer; text-decoration:none; display:flex; align-items:center; justify-content:center; transition:all .2s;" onmouseover="this.style.background='#2563eb'" onmouseout="this.style.background='#3b82f6'">Sí, ingresar</a>
            </div>
        </div>
    </div>

    <!-- Modal Moderno de Notificación / Alerta POS -->
    <div id="modal-notificacion-pos" style="display:none; position:fixed; inset:0; z-index:999999; align-items:center; justify-content:center;">
        <div id="modal-notificacion-pos-backdrop" onclick="POS.cerrarMensaje()" style="position:absolute; inset:0; background:rgba(0,0,0,0.65); backdrop-filter:blur(4px);"></div>
        <div style="position:relative; background:#1e1e2e; border:1px solid rgba(255,255,255,0.1); border-radius:16px; padding:2rem 2.25rem; width:100%; max-width:390px; box-shadow:0 25px 60px rgba(0,0,0,0.5); text-align:center; animation:modalInPos .25s ease;">
            <div id="notif-pos-icon-wrap" style="width:58px; height:58px; border-radius:50%; background:rgba(16,185,129,0.15); display:flex; align-items:center; justify-content:center; margin:0 auto 1.25rem;">
                <svg id="notif-pos-icon-svg" xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
            </div>
            <h3 id="notif-pos-titulo" style="font-size:1.2rem; font-weight:700; color:#f9fafb; margin-bottom:.5rem;">¡Caja Abierta con Éxito!</h3>
            <p id="notif-pos-mensaje" style="font-size:.92rem; color:#94a3b8; margin-bottom:1.75rem; line-height:1.5; white-space:pre-line;">Caja abierta correctamente.</p>
            <div style="display:flex; justify-content:center;">
                <button type="button" id="notif-pos-btn" onclick="POS.cerrarMensaje()" style="width:100%; padding:.75rem 1.5rem; border-radius:8px; border:none; background:#10b981; color:#fff; font-size:.95rem; font-weight:700; cursor:pointer; transition:all .2s; box-shadow:0 4px 12px rgba(16,185,129,0.25);" onmouseover="this.style.background='#059669'" onmouseout="this.style.background='#10b981'">Aceptar</button>
            </div>
        </div>
    </div>

    <!-- Modal Moderno de Confirmación POS -->
    <div id="modal-confirmacion-pos" style="display:none; position:fixed; inset:0; z-index:999999; align-items:center; justify-content:center;">
        <div id="modal-confirmacion-pos-backdrop" onclick="POS.cancelarConfirmacion()" style="position:absolute; inset:0; background:rgba(0,0,0,0.65); backdrop-filter:blur(4px);"></div>
        <div style="position:relative; background:#1e1e2e; border:1px solid rgba(255,255,255,0.1); border-radius:16px; padding:2rem 2.25rem; width:100%; max-width:400px; box-shadow:0 25px 60px rgba(0,0,0,0.5); text-align:center; animation:modalInPos .25s ease;">
            <div id="confirm-pos-icon-wrap" style="width:58px; height:58px; border-radius:50%; background:rgba(239,68,68,0.15); display:flex; align-items:center; justify-content:center; margin:0 auto 1.25rem;">
                <svg id="confirm-pos-icon-svg" xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                    <line x1="12" y1="9" x2="12" y2="13"></line>
                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                </svg>
            </div>
            <h3 id="confirm-pos-titulo" style="font-size:1.2rem; font-weight:700; color:#f9fafb; margin-bottom:.5rem;">¿Cerrar Turno de Caja?</h3>
            <p id="confirm-pos-mensaje" style="font-size:.92rem; color:#94a3b8; margin-bottom:1.75rem; line-height:1.5;">¿Estás seguro de que deseas cerrar el turno de caja actual? Se generará el corte y no se podrán registrar más ventas en este turno.</p>
            <div style="display:flex; gap:.75rem; justify-content:center;">
                <button type="button" id="confirm-pos-btn-no" onclick="POS.cancelarConfirmacion()" style="flex:1; padding:.65rem 1rem; border-radius:8px; border:1px solid rgba(255,255,255,0.12); background:transparent; color:#94a3b8; font-size:.875rem; cursor:pointer; transition:all .2s;" onmouseover="this.style.background='rgba(255,255,255,0.06)'" onmouseout="this.style.background='transparent'">Cancelar</button>
                <button type="button" id="confirm-pos-btn-si" onclick="POS.ejecutarConfirmacion()" style="flex:1; padding:.65rem 1rem; border-radius:8px; border:none; background:#ef4444; color:#fff; font-size:.875rem; font-weight:600; cursor:pointer; transition:all .2s; box-shadow:0 4px 12px rgba(239,68,68,0.25);" onmouseover="this.style.background='#dc2626'" onmouseout="this.style.background='#ef4444'">Sí, cerrar caja</button>
            </div>
        </div>
    </div>

    <style>
    @keyframes modalInPos {
        from { opacity:0; transform:scale(.92) translateY(12px); }
        to   { opacity:1; transform:scale(1)  translateY(0); }
    }
    </style>

    <script>
    function confirmarCerrarSesionPos() {
        const modal = document.getElementById('modal-logout-pos');
        if (modal) {
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
    }
    function cancelarCerrarSesionPos() {
        const modal = document.getElementById('modal-logout-pos');
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }

    function confirmarIngresoBackoffice() {
        const modal = document.getElementById('modal-backoffice-confirm');
        if (modal) {
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
    }
    function cancelarIngresoBackoffice() {
        const modal = document.getElementById('modal-backoffice-confirm');
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            cancelarCerrarSesionPos();
            cancelarIngresoBackoffice();
            if (window.POS && typeof POS.cerrarMensaje === 'function') {
                POS.cerrarMensaje();
            }
            if (window.POS && typeof POS.cancelarConfirmacion === 'function') {
                POS.cancelarConfirmacion();
            }
        }
    });

    // Evitar que el botón ATRAS del navegador vuelva a la pantalla de login del POS
    if (window.history && window.history.replaceState) {
        window.history.replaceState(null, document.title, window.location.href);
    }
    history.pushState(null, document.title, location.href);
    window.addEventListener('popstate', function () {
        history.pushState(null, document.title, location.href);
    });
    </script>

    <!-- ═════════════════════════════════════════════════════════════
         MODAL LAVADO: Panel Kanban de Seguimiento de Cargas
         ═════════════════════════════════════════════════════════════ -->
    <div id="modal-lavado" class="pos-modal-overlay">
        <div class="pos-modal-card pos-lavado-modal-card">
            <div class="pos-modal-head pos-lavado-modal-head">
                <div style="display:flex;align-items:center;gap:0.75rem;">
                    <svg style="width:22px;height:22px;color:#38bdf8;flex-shrink:0;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                    <div>
                        <h3 style="margin:0;font-size:1.05rem;">🧺 Control de Lavado de Cargas</h3>
                        <p style="margin:0;font-size:0.72rem;color:var(--pos-text-muted);">Seguimiento en tiempo real del proceso de lavandería</p>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    <button class="pos-btn-clear" style="padding:0.4rem 0.8rem;font-size:0.8rem;" onclick="POS.cargarPanelLavado()" title="Actualizar">
                        🔄 Actualizar
                    </button>
                    <button class="pos-modal-close" onclick="POS.closeModal('modal-lavado')">✕</button>
                </div>
            </div>

            <!-- Barra de Filtro por Categoría / Estado (Mismo estilo que Productos y Kits) -->
            <div class="pos-lavado-controls-bar">
                <div class="pos-filter-tabs" id="pos-lavado-filter-tabs">
                    <button class="pos-tab active" data-lavado-tab="ALL" onclick="POS.setLavadoTab('ALL', this)">
                        <span>✨ Todas</span>
                        <span class="pos-tab-badge" id="lavado-tab-count-all">0</span>
                    </button>
                    <button class="pos-tab" data-lavado-tab="RECIBIDA" onclick="POS.setLavadoTab('RECIBIDA', this)">
                        <span>⏳ En Espera</span>
                        <span class="pos-tab-badge badge-espera" id="lavado-tab-count-espera">0</span>
                    </button>
                    <button class="pos-tab" data-lavado-tab="EN_PROCESO" onclick="POS.setLavadoTab('EN_PROCESO', this)">
                        <span>⚙️ En Proceso</span>
                        <span class="pos-tab-badge badge-proceso" id="lavado-tab-count-proceso">0</span>
                    </button>
                    <button class="pos-tab" data-lavado-tab="LISTA_CONCLUIDA" onclick="POS.setLavadoTab('LISTA_CONCLUIDA', this)">
                        <span>✅ Finalizados Hoy</span>
                        <span class="pos-tab-badge badge-finalizado" id="lavado-tab-count-finalizado">0</span>
                    </button>
                    <button class="pos-tab" data-lavado-tab="ENTREGADA" onclick="POS.setLavadoTab('ENTREGADA', this)">
                        <span>📦 Entregados</span>
                        <span class="pos-tab-badge badge-entregados" id="lavado-tab-count-entregados">0</span>
                    </button>
                </div>

                <!-- Buscador en tiempo real para cargas -->
                <div class="pos-search-prod-wrapper" style="position:relative; width:340px; margin:0;">
                    <svg class="pos-search-prod-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="text" id="pos-search-lavado-input" class="pos-form-input pos-search-input-field"
                        placeholder="Buscar cliente, folio, carga o teléfono..."
                        autocomplete="off"
                        oninput="POS.filtrarCargasLavado(this.value)">
                    <button type="button" id="pos-btn-clear-lavado-search" class="pos-btn-clear-prod" style="display:none;" onclick="POS.limpiarFiltroLavado()" title="Limpiar búsqueda">✕</button>
                </div>
            </div>

            <!-- Grid Amplio de Tarjetas de Cargas (Expandido como Productos y Kits) -->
            <div id="pos-lavado-cards-grid" class="pos-lavado-cards-grid">
                <div class="pos-lavado-empty">Cargando cargas...</div>
            </div>

            <div class="pos-modal-foot" style="justify-content:space-between;align-items:center;">
                <span style="font-size:0.75rem;color:var(--pos-text-muted);" id="pos-lavado-last-update">
                    Presiona Actualizar para cargar datos
                </span>
                <button class="pos-btn-clear" onclick="POS.closeModal('modal-lavado')">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- ═════════════════════════════════════════════════════════════
         MODAL COBRO CARGA: Modal de pago para cargas pendientes
         (z-index 1100 para aparecer sobre el modal de lavado)
         ═════════════════════════════════════════════════════════════ -->
    <div id="modal-cobro-carga" class="pos-modal-overlay pos-modal-cobro-carga-overlay">
        <div class="pos-modal-card" style="max-width:520px;">
            <div class="pos-modal-head">
                <h3 id="modal-cobro-carga-titulo">💳 Cobrar y Entregar Carga</h3>
                <button class="pos-modal-close" onclick="POS.cerrarModalCobroCarga()">✕</button>
            </div>
            <div class="pos-modal-body" style="gap:1rem;">
                <!-- Total -->
                <div style="text-align:center; padding:0.5rem 0;">
                    <div style="font-size:0.85rem; color:var(--pos-text-muted); font-weight:600; text-transform:uppercase;">Total a Cobrar</div>
                    <div id="carga-pay-total" style="font-size:2.8rem; font-weight:900; color:#10b981; font-family:'JetBrains Mono',monospace;">$0.00</div>
                </div>

                <!-- Alerta pago pendiente -->
                <div style="display:flex;align-items:center;gap:0.6rem;padding:0.6rem 0.9rem;background:rgba(245,158,11,0.12);border:1px solid rgba(245,158,11,0.4);border-radius:8px;color:#fbbf24;font-size:0.82rem;font-weight:700;">
                    ⚠️ Esta carga tiene saldo pendiente. Selecciona el método de cobro para continuar con la entrega.
                </div>

                <!-- Métodos de pago (sin PENDIENTE) -->
                <div>
                    <label style="font-size:0.8rem; font-weight:700; color:var(--pos-text-muted); margin-bottom:0.5rem; display:block;">Método de Pago</label>
                    <div class="pos-pay-methods" style="grid-template-columns:repeat(3,1fr);">
                        <div class="pos-pay-opt selected" data-carga-method="EFECTIVO" onclick="POS.setCargaPayMethod('EFECTIVO')">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/></svg>
                            <span>Efectivo</span>
                        </div>
                        <div class="pos-pay-opt" data-carga-method="TARJETA" onclick="POS.setCargaPayMethod('TARJETA')">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                            <span>Tarjeta</span>
                        </div>
                        <div class="pos-pay-opt" data-carga-method="TRANSFERENCIA" onclick="POS.setCargaPayMethod('TRANSFERENCIA')">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            <span>Transferencia</span>
                        </div>
                    </div>
                </div>

                <!-- Monto recibido (efectivo) -->
                <div id="carga-pay-cash-details">
                    <label style="font-size:0.8rem; font-weight:700; color:var(--pos-text-muted); margin-bottom:0.4rem; display:block;">Monto Recibido</label>
                    <input type="number" step="0.01" id="carga-pay-received" class="pos-search-input"
                        style="font-size:1.5rem; font-weight:800; font-family:'JetBrains Mono',monospace; text-align:center; padding:0.6rem;"
                        oninput="POS.calcCambioCarga()">
                    <div class="pos-denominations" style="margin-top:0.5rem;">
                        <button class="pos-denom-btn" onclick="POS.setCargaDenom('exact')">Exacto</button>
                        <button class="pos-denom-btn" onclick="POS.setCargaDenom(50)">$50</button>
                        <button class="pos-denom-btn" onclick="POS.setCargaDenom(100)">$100</button>
                        <button class="pos-denom-btn" onclick="POS.setCargaDenom(200)">$200</button>
                        <button class="pos-denom-btn" onclick="POS.setCargaDenom(500)">$500</button>
                        <button class="pos-denom-btn" onclick="POS.setCargaDenom(1000)">$1000</button>
                    </div>
                    <div class="pos-change-box" style="margin-top:0.5rem;">
                        <span class="pos-change-label">Cambio:</span>
                        <span id="carga-pay-change" class="pos-change-val">$0.00</span>
                    </div>
                </div>
            </div>
            <div class="pos-modal-foot">
                <button class="pos-btn-clear" onclick="POS.cerrarModalCobroCarga()">Cancelar</button>
                <button id="carga-pay-confirm-btn" class="pos-btn-pay" style="padding:0.75rem 1.75rem; font-size:1rem;"
                        onclick="POS.confirmarCobroCarga()">
                    💳 Cobrar y Entregar
                </button>
            </div>
        </div>
    </div>
    <!-- Scripts -->
    <script src="js/pos.js"></script>
</body>
</html>
