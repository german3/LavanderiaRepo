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
    <link rel="icon" type="image/jpeg" href="img/Logo.jpeg">
    <style>
        .pos-prod-card.pos-insumo-selected {
            border-color: #f59e0b !important;
            box-shadow: 0 0 16px rgba(245, 158, 11, 0.45) !important;
            background: rgba(245, 158, 11, 0.12) !important;
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

            <!-- Reloj Digital -->
            <div id="pos-clock" class="pos-clock">--:--:--</div>

            <!-- Cajero en turno -->
            <div class="pos-btn-head" style="cursor:default; background:rgba(255,255,255,0.05); border-color:rgba(255,255,255,0.1); color:#f3f4f6;" title="Cajero autenticado en el POS">
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
                        <div id="pos-selected-client-hint" style="font-size:0.75rem; color:#60a5fa; font-weight:600; margin-top:0.3rem;"></div>
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

                    <!-- 3. Campo de Cantidad de Kilos -->
                    <div id="pos-field-kilos-col" class="pos-entry-field">
                        <label class="pos-entry-label" for="pos-input-kilos">3. Cantidad de Kilos</label>
                        <div class="number-input-wrapper">
                            <input type="number" step="0.05" min="0.05" id="pos-input-kilos" class="pos-form-input" style="font-family:'JetBrains Mono',monospace; font-weight:700;" placeholder="Ej. 4.5" value="1.0" oninput="POS.calcPreviewCosto()" onkeydown="if(event.key==='Enter'){event.preventDefault(); POS.agregarRegistroFormulario();}">
                            <div class="spin-buttons">
                                <button type="button" class="spin-btn" onclick="POS.stepKilos(0.5)" title="Aumentar">▲</button>
                                <button type="button" class="spin-btn" onclick="POS.stepKilos(-0.5)" title="Disminuir">▼</button>
                            </div>
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
                <div class="pos-summary-row">
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
        <div class="pos-modal-card" style="max-width:440px;">
            <div class="pos-modal-head pos-no-print">
                <h3>🖨️ Ticket de Venta / Carga</h3>
                <button class="pos-modal-close" onclick="POS.closeModal('modal-ticket')">✕</button>
            </div>
            <div class="pos-modal-body" id="pos-ticket-content" style="background:#1f2937; padding:1.25rem;">
                <!-- Se llena dinámicamente con .pos-ticket-paper -->
            </div>
            <div class="pos-modal-foot pos-no-print">
                <button class="pos-btn-clear" onclick="POS.closeModal('modal-ticket')">Cerrar</button>
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

    <!-- Scripts -->
    <script src="js/pos.js"></script>
</body>
</html>
