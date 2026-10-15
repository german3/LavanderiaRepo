/**
 * FrontOffice POS JavaScript — Spec 05-punto-venta-caja.md
 * Terminal de Punto de Venta y Control de Caja
 */

const POS = {
    user: null,
    caja: null,
    products: [],
    clients: [],
    currentCategory: 'ALL',
    searchQuery: '',
    selectedClient: null,
    cart: [],
    selectedPayMethod: 'EFECTIVO',
    activeModal: null,

    // ─────────────────────────────────────────────────────────────
    // Inicialización
    // ─────────────────────────────────────────────────────────────
    async init() {
        this.startClock();
        this.bindEvents();
        await this.loadInitialData();
    },

    startClock() {
        const clockEl = document.getElementById('pos-clock');
        const update = () => {
            const now = new Date();
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('es-MX', { hour12: false }) + ' | ' + now.toLocaleDateString('es-MX', { day: '2-digit', month: 'short' });
            }
        };
        update();
        setInterval(update, 1000);
    },

    async loadInitialData() {
        try {
            const res = await fetch('api/pos_api.php?action=init_data');
            const data = await res.json();

            if (!data.success) {
                alert(data.message || 'Error al conectar con el servidor.');
                return;
            }

            this.user = data.user;
            this.caja = data.caja_abierta;
            this.products = data.products || [];
            this.clients = data.clients || [];

            this.renderCajaStatus();
            this.renderProducts();
            this.updatePendingBadge(data.total_pendientes || 0);

            // Si la caja está cerrada, sugerir abrir turno
            if (!this.caja) {
                this.openModal('modal-apertura-caja');
            }

        } catch (err) {
            console.error('Error cargando POS init:', err);
        }
    },

    renderCajaStatus() {
        const pill = document.getElementById('pos-caja-status');
        if (!pill) return;

        if (this.caja) {
            pill.className = 'pos-caja-pill abierta';
            pill.innerHTML = `
                <span class="pos-dot-pulse"></span>
                <span>Turno Abierto ($${parseFloat(this.caja.monto_inicial).toFixed(2)})</span>
            `;
            pill.onclick = () => POS.abrirModalCorte();
        } else {
            pill.className = 'pos-caja-pill cerrada';
            pill.innerHTML = `
                <span style="font-size:0.9rem">⚠️</span>
                <span>Caja Cerrada (Abrir Turno)</span>
            `;
            pill.onclick = () => POS.openModal('modal-apertura-caja');
        }
    },

    updatePendingBadge(count) {
        const badge = document.getElementById('pos-pendientes-count');
        if (badge) {
            badge.textContent = count;
            badge.style.display = count > 0 ? 'inline-block' : 'none';
        }
    },

    // ─────────────────────────────────────────────────────────────
    // Renderizado de Catálogo de Productos
    // ─────────────────────────────────────────────────────────────
    renderProducts() {
        const container = document.getElementById('pos-product-grid');
        if (!container) return;

        let filtered = this.products;

        // Filtro por categoría
        if (this.currentCategory !== 'ALL') {
            filtered = filtered.filter(p => p.tipo === this.currentCategory);
        }

        // Filtro por buscador
        if (this.searchQuery.trim() !== '') {
            const q = this.searchQuery.toLowerCase().trim();
            filtered = filtered.filter(p => 
                (p.descripcion && p.descripcion.toLowerCase().includes(q)) ||
                (p.codigo_barras && p.codigo_barras.toLowerCase().includes(q)) ||
                (p.codigo_interno_sku && p.codigo_interno_sku.toLowerCase().includes(q))
            );
        }

        if (filtered.length === 0) {
            container.innerHTML = `
                <div style="grid-column: 1/-1; text-align: center; padding: 3rem; color: var(--pos-text-muted);">
                    <svg style="width:48px;height:48px;opacity:0.4;margin-bottom:0.5rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <p style="font-weight:600;">No se encontraron productos o servicios</p>
                    <p style="font-size:0.8rem; margin-top:0.25rem;">Intenta con otro término de búsqueda o categoría</p>
                </div>
            `;
            return;
        }

        container.innerHTML = filtered.map(p => {
            const stock = parseFloat(p.stock_cantidad);
            const stockMin = parseFloat(p.stock_minimo || 0);
            const precio = parseFloat(p.precio_venta).toFixed(2);
            const ropaKg = parseFloat(p.ropa_kg || 0);

            let stockBadge = '';
            if (p.tipo === 'NORMAL') {
                if (stock <= 0) {
                    stockBadge = `<span class="pos-prod-stock out">Agotado (0)</span>`;
                } else if (stock <= stockMin) {
                    stockBadge = `<span class="pos-prod-stock low">Stock Bajo (${stock})</span>`;
                } else {
                    stockBadge = `<span class="pos-prod-stock">Stock: ${stock}</span>`;
                }
            } else if (p.tipo === 'KIT') {
                stockBadge = `<span class="pos-prod-stock">Kit de Insumos</span>`;
            } else {
                stockBadge = `<span class="pos-prod-stock">Servicio</span>`;
            }

            const kgBadge = ropaKg > 0 ? `<span class="pos-prod-kg">⚖️ ${ropaKg} kg</span>` : '';

            return `
                <div class="pos-prod-card" onclick="POS.addToCart('${p.id}')" data-id="${p.id}">
                    <span class="pos-prod-badge ${p.tipo}">${p.tipo === 'NORMAL' ? 'Producto' : p.tipo}</span>
                    <div class="pos-prod-title">${this.escapeHtml(p.descripcion)}</div>
                    <div class="pos-prod-meta">
                        <div>
                            <div class="pos-prod-price">$${precio}</div>
                            ${kgBadge}
                        </div>
                        ${stockBadge}
                    </div>
                </div>
            `;
        }).join('');
    },

    setCategory(cat, btn) {
        this.currentCategory = cat;
        document.querySelectorAll('.pos-tab').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');
        this.renderProducts();
    },

    // ─────────────────────────────────────────────────────────────
    // Carrito de Compras
    // ─────────────────────────────────────────────────────────────
    addToCart(productId, qty = 1) {
        const prod = this.products.find(p => p.id === productId);
        if (!prod) return;

        // Comprobar si ya está en el carrito
        const existing = this.cart.find(item => item.producto_id === productId);
        if (existing) {
            existing.cantidad += qty;
            existing.subtotal = +(existing.cantidad * existing.precio_unitario).toFixed(2);
        } else {
            this.cart.push({
                producto_id: prod.id,
                descripcion: prod.descripcion,
                tipo: prod.tipo,
                precio_unitario: parseFloat(prod.precio_venta),
                cantidad: qty,
                subtotal: +(qty * parseFloat(prod.precio_venta)).toFixed(2),
                ropa_kg: parseFloat(prod.ropa_kg || 0),
                notas: ''
            });
        }

        this.renderCart();
        this.playBeep();
    },

    updateQty(index, delta) {
        if (!this.cart[index]) return;
        this.cart[index].cantidad += delta;
        if (this.cart[index].cantidad <= 0) {
            this.removeFromCart(index);
            return;
        }
        this.cart[index].subtotal = +(this.cart[index].cantidad * this.cart[index].precio_unitario).toFixed(2);
        this.renderCart();
    },

    setQty(index, value) {
        const val = parseFloat(value);
        if (isNaN(val) || val <= 0) {
            this.removeFromCart(index);
            return;
        }
        this.cart[index].cantidad = val;
        this.cart[index].subtotal = +(this.cart[index].cantidad * this.cart[index].precio_unitario).toFixed(2);
        this.renderCart();
    },

    updateItemExtra(index, field, value) {
        if (!this.cart[index]) return;
        this.cart[index][field] = value;
        this.renderCartSummaryOnly();
    },

    removeFromCart(index) {
        this.cart.splice(index, 1);
        this.renderCart();
    },

    clearCart() {
        if (this.cart.length === 0) return;
        if (confirm('¿Vaciar los artículos del carrito actual?')) {
            this.cart = [];
            this.renderCart();
        }
    },

    renderCart() {
        const container = document.getElementById('pos-cart-items');
        if (!container) return;

        if (this.cart.length === 0) {
            container.innerHTML = `
                <div class="pos-empty-cart">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                    <p style="font-weight:600; font-size:1.05rem;">Carrito Vacío</p>
                    <p style="font-size:0.8rem; margin-top:0.25rem;">Selecciona productos o escanea con el lector de código de barras.</p>
                </div>
            `;
            this.renderCartSummaryOnly();
            return;
        }

        container.innerHTML = this.cart.map((item, idx) => {
            const isLaundry = item.tipo === 'SERVICIO' || item.ropa_kg > 0;
            return `
                <div class="pos-cart-item">
                    <div class="pos-item-header">
                        <div class="pos-item-name">${this.escapeHtml(item.descripcion)}</div>
                        <button class="pos-item-remove" onclick="POS.removeFromCart(${idx})" title="Eliminar artículo">
                            <svg style="width:16px;height:16px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    </div>
                    <div class="pos-item-controls">
                        <div class="pos-qty-stepper">
                            <button class="pos-qty-btn" onclick="POS.updateQty(${idx}, -1)">-</button>
                            <input type="number" step="any" min="0.1" class="pos-qty-input" value="${item.cantidad}" onchange="POS.setQty(${idx}, this.value)">
                            <button class="pos-qty-btn" onclick="POS.updateQty(${idx}, 1)">+</button>
                        </div>
                        <div class="pos-item-pricing">
                            <div class="pos-item-unit">$${item.precio_unitario.toFixed(2)} c/u</div>
                            <div class="pos-item-subtotal">$${item.subtotal.toFixed(2)}</div>
                        </div>
                    </div>
                    ${isLaundry ? `
                    <div class="pos-item-extra">
                        <input type="number" step="0.1" min="0" placeholder="Kg reales" style="width:85px;" value="${item.ropa_kg || ''}" onchange="POS.updateItemExtra(${idx}, 'ropa_kg', this.value)" title="Kilos de ropa estimados o pesados">
                        <input type="text" placeholder="Observaciones / prenda..." style="flex:1;" value="${this.escapeHtml(item.notas || '')}" onchange="POS.updateItemExtra(${idx}, 'notas', this.value)" title="Notas específicas para el lavado">
                    </div>` : ''}
                </div>
            `;
        }).join('');

        this.renderCartSummaryOnly();
    },

    renderCartSummaryOnly() {
        const totalItems = this.cart.reduce((acc, it) => acc + parseFloat(it.cantidad || 0), 0);
        const totalKg = this.cart.reduce((acc, it) => acc + (parseFloat(it.ropa_kg || 0) * parseFloat(it.cantidad || 0)), 0);
        const granTotal = this.cart.reduce((acc, it) => acc + parseFloat(it.subtotal || 0), 0);

        const itemsEl = document.getElementById('pos-summary-items');
        const kgEl = document.getElementById('pos-summary-kg');
        const totalEl = document.getElementById('pos-total-display');
        const payBtn = document.getElementById('pos-btn-pay');
        const pendBtn = document.getElementById('pos-btn-pending');

        if (itemsEl) itemsEl.textContent = totalItems.toFixed(totalItems % 1 === 0 ? 0 : 2);
        if (kgEl) kgEl.textContent = totalKg > 0 ? `${totalKg.toFixed(1)} kg` : '0 kg';
        if (totalEl) totalEl.textContent = `$${granTotal.toFixed(2)}`;

        const hasItems = this.cart.length > 0;
        if (payBtn) {
            payBtn.disabled = !hasItems;
            payBtn.innerHTML = `
                <svg style="width:22px;height:22px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                <span>COBRAR $${granTotal.toFixed(2)}</span>
            `;
        }
        if (pendBtn) pendBtn.disabled = !hasItems;
    },

    // ─────────────────────────────────────────────────────────────
    // Clientes
    // ─────────────────────────────────────────────────────────────
    setClient(client) {
        this.selectedClient = client;
        const nameEl = document.getElementById('pos-cust-name');
        if (nameEl) {
            nameEl.textContent = client ? `${client.nombre} (${client.telefono})` : 'Público General / Mostrador';
        }
        this.closeModal('modal-buscar-cliente');
    },

    async buscarClientes(query) {
        try {
            const res = await fetch(`api/pos_api.php?action=search_clients&q=${encodeURIComponent(query)}`);
            const data = await res.json();
            if (data.success) {
                const listEl = document.getElementById('pos-clientes-result');
                if (!listEl) return;
                listEl.innerHTML = data.clientes.map(c => `
                    <div style="padding:0.75rem 1rem; border-bottom:1px solid var(--pos-border); cursor:pointer; display:flex; justify-content:space-between; align-items:center;"
                         class="pos-client-item" onclick='POS.setClient(${JSON.stringify(c)})'>
                        <div>
                            <div style="font-weight:700; color:#fff;">${this.escapeHtml(c.nombre)}</div>
                            <div style="font-size:0.75rem; color:var(--pos-text-muted);">📞 ${this.escapeHtml(c.telefono)} ${c.direccion ? ' | 📍 ' + this.escapeHtml(c.direccion) : ''}</div>
                        </div>
                        <button class="pos-btn-cust" style="pointer-events:none;">Seleccionar</button>
                    </div>
                `).join('');
            }
        } catch (e) {
            console.error('Error buscando clientes:', e);
        }
    },

    async guardarNuevoClienteExpress(form) {
        const formData = new FormData(form);
        try {
            const res = await fetch('api/pos_api.php?action=create_client', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                this.setClient(data.cliente);
                this.closeModal('modal-nuevo-cliente');
                form.reset();
                alert('Cliente registrado y seleccionado correctamente.');
            } else {
                alert(data.message || 'Error al registrar cliente');
            }
        } catch (e) {
            alert('Error de conexión');
        }
    },

    // ─────────────────────────────────────────────────────────────
    // Cobro Inmediato (Modal)
    // ─────────────────────────────────────────────────────────────
    abrirModalCobro() {
        if (this.cart.length === 0) return;

        const granTotal = this.cart.reduce((acc, it) => acc + parseFloat(it.subtotal || 0), 0);
        document.getElementById('pos-pay-total-due').textContent = `$${granTotal.toFixed(2)}`;
        
        // Default Efectivo
        this.setPayMethod('EFECTIVO');
        const inputRecibido = document.getElementById('pos-pay-received');
        inputRecibido.value = granTotal.toFixed(2);
        this.calcCambio();

        this.openModal('modal-cobro');
        setTimeout(() => {
            inputRecibido.focus();
            inputRecibido.select();
        }, 100);
    },

    setPayMethod(method) {
        this.selectedPayMethod = method;
        document.querySelectorAll('.pos-pay-opt').forEach(opt => {
            opt.classList.toggle('selected', opt.dataset.method === method);
        });

        const cashDetails = document.getElementById('pos-pay-cash-details');
        if (cashDetails) {
            cashDetails.style.display = method === 'EFECTIVO' ? 'flex' : 'none';
        }
        this.calcCambio();
    },

    setDenomination(amount) {
        const granTotal = this.cart.reduce((acc, it) => acc + parseFloat(it.subtotal || 0), 0);
        const inputRecibido = document.getElementById('pos-pay-received');
        if (amount === 'exact') {
            inputRecibido.value = granTotal.toFixed(2);
        } else {
            inputRecibido.value = parseFloat(amount).toFixed(2);
        }
        this.calcCambio();
    },

    calcCambio() {
        const granTotal = this.cart.reduce((acc, it) => acc + parseFloat(it.subtotal || 0), 0);
        const inputRecibido = document.getElementById('pos-pay-received');
        const cambioEl = document.getElementById('pos-pay-change-val');
        const submitBtn = document.getElementById('pos-pay-confirm-btn');

        if (this.selectedPayMethod === 'EFECTIVO') {
            const recibido = parseFloat(inputRecibido.value) || 0;
            const cambio = Math.max(0, recibido - granTotal);
            cambioEl.textContent = `$${cambio.toFixed(2)}`;
            if (submitBtn) {
                submitBtn.disabled = recibido < granTotal;
            }
        } else {
            cambioEl.textContent = '$0.00';
            if (submitBtn) submitBtn.disabled = false;
        }
    },

    async confirmarVenta(isPagoPosterior = false) {
        if (this.cart.length === 0) return;

        const granTotal = this.cart.reduce((acc, it) => acc + parseFloat(it.subtotal || 0), 0);
        let montoRecibido = granTotal;
        let metodo = this.selectedPayMethod;

        if (isPagoPosterior) {
            metodo = 'PENDIENTE';
            montoRecibido = 0;
            if (!this.selectedClient) {
                const conf = confirm('No has seleccionado un cliente para esta ropa en recepción. ¿Deseas asignarlo para facilitar el reclamo y entrega?');
                if (conf) {
                    this.openModal('modal-buscar-cliente');
                    return;
                }
            }
        } else {
            if (metodo === 'EFECTIVO') {
                montoRecibido = parseFloat(document.getElementById('pos-pay-received').value) || granTotal;
                if (montoRecibido < granTotal) {
                    alert('El monto recibido no puede ser menor al total.');
                    return;
                }
            }
        }

        const payload = {
            action: 'procesar_venta',
            cliente_id: this.selectedClient ? this.selectedClient.id : null,
            metodo_pago: metodo,
            pago_posterior: isPagoPosterior ? 1 : 0,
            monto_recibido: montoRecibido,
            items: this.cart
        };

        try {
            const res = await fetch('api/pos_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();

            if (data.success) {
                this.closeModal('modal-cobro');
                this.cart = [];
                this.renderCart();
                this.selectedClient = null;
                this.setClient(null);

                // Actualizar badge de pendientes si aplica
                if (isPagoPosterior) {
                    this.loadInitialData();
                }

                // Mostrar ticket
                if (data.ticket) {
                    this.mostrarTicket(data.ticket);
                } else {
                    alert('Venta procesada con éxito. Folio: ' + data.folio);
                }
            } else {
                alert(data.message || 'Error al procesar la venta');
            }
        } catch (err) {
            alert('Error de conexión al procesar venta.');
            console.error(err);
        }
    },

    // ─────────────────────────────────────────────────────────────
    // Ticket Térmico y Reimpresión
    // ─────────────────────────────────────────────────────────────
    mostrarTicket(ticket) {
        const bodyEl = document.getElementById('pos-ticket-content');
        if (!bodyEl) return;

        const isPagado = ticket.estado_pago === 'PAGADO';
        const detalles = ticket.detalles || [];

        bodyEl.innerHTML = `
            <div class="pos-ticket-paper" id="ticket-print-wrapper">
                <div class="pos-ticket-center">
                    <h2 style="font-size:1.15rem; font-weight:800; letter-spacing:0.02em;">LAVANDERÍA VERA</h2>
                    <p style="font-size:0.7rem; color:#444;">Tu ropa limpia, fresca y a tiempo</p>
                    <p style="font-size:0.7rem; color:#444;">Tel: (999) 000-0000</p>
                    <div class="pos-ticket-divider"></div>
                    <div style="font-weight:700; font-size:0.95rem;">FOLIO: ${ticket.folio}</div>
                    ${ticket.numero_carga ? `<div style="font-weight:800; color:#000; font-size:0.9rem; background:#f0f0f0; padding:2px 4px; margin:3px 0; border:1px solid #ccc;">CARGA DE LAVADO: ${ticket.numero_carga}</div>` : ''}
                    <p style="font-size:0.7rem; color:#555;">Fecha: ${ticket.fecha_venta}</p>
                    <p style="font-size:0.7rem; color:#555;">Cajero: ${this.escapeHtml(ticket.cajero_nombre)}</p>
                </div>

                <div class="pos-ticket-divider"></div>
                <div style="font-size:0.73rem;">
                    <div><strong>Cliente:</strong> ${this.escapeHtml(ticket.cliente_nombre || 'Público General')}</div>
                    ${ticket.cliente_telefono ? `<div><strong>Teléfono:</strong> ${this.escapeHtml(ticket.cliente_telefono)}</div>` : ''}
                </div>

                <div class="pos-ticket-divider"></div>
                <table class="pos-ticket-table">
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
                                    ${this.escapeHtml(d.descripcion)}
                                    ${d.ropa_kg > 0 ? `<div style="font-size:0.65rem; color:#555;">(${d.ropa_kg} kg)</div>` : ''}
                                    ${d.notas ? `<div style="font-size:0.65rem; color:#555;">* ${this.escapeHtml(d.notas)}</div>` : ''}
                                </td>
                                <td style="text-align:right; vertical-align:top;">$${parseFloat(d.subtotal).toFixed(2)}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>

                <div class="pos-ticket-divider"></div>
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

                <div class="pos-ticket-divider"></div>
                <div class="pos-ticket-center" style="font-size:0.68rem; color:#444;">
                    <p style="font-weight:700;">¡Gracias por su preferencia!</p>
                    <p>Conserve este ticket para recoger sus prendas.</p>
                    <p style="margin-top:0.3rem;">No nos hacemos responsables por prendas no recogidas después de 30 días.</p>
                </div>
            </div>
        `;

        this.openModal('modal-ticket');
    },

    imprimirTicketActual() {
        window.print();
    },

    // ─────────────────────────────────────────────────────────────
    // Cobros Pendientes (Pago Posterior)
    // ─────────────────────────────────────────────────────────────
    async abrirModalPendientes() {
        this.openModal('modal-pendientes');
        await this.cargarPendientes();
    },

    async cargarPendientes(busqueda = '') {
        const container = document.getElementById('pos-pendientes-list');
        if (!container) return;
        container.innerHTML = '<div style="text-align:center; padding:2rem; color:var(--pos-text-muted);">Cargando pendientes...</div>';

        try {
            const res = await fetch(`api/pos_api.php?action=ventas_pendientes&q=${encodeURIComponent(busqueda)}`);
            const data = await res.json();

            if (!data.success || !data.pendientes || data.pendientes.length === 0) {
                container.innerHTML = `
                    <div style="text-align:center; padding:3rem; color:var(--pos-text-muted);">
                        <svg style="width:48px;height:48px;opacity:0.4;margin-bottom:0.5rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <p style="font-weight:700;">No hay cobros pendientes de pago</p>
                        <p style="font-size:0.8rem; margin-top:0.25rem;">Todas las notas se encuentran al día o no coinciden con la búsqueda.</p>
                    </div>
                `;
                this.updatePendingBadge(0);
                return;
            }

            this.updatePendingBadge(data.pendientes.length);

            container.innerHTML = data.pendientes.map(item => `
                <div style="background:rgba(15,23,42,0.7); border:1px solid var(--pos-border); border-radius:10px; padding:1rem; display:flex; justify-content:space-between; align-items:center; gap:1rem;">
                    <div>
                        <div style="display:flex; align-items:center; gap:0.5rem;">
                            <span style="font-weight:800; color:#fff; font-size:0.95rem;">${item.folio}</span>
                            ${item.numero_carga ? `<span style="background:rgba(59,130,246,0.15); color:#60a5fa; border:1px solid rgba(59,130,246,0.3); font-size:0.7rem; font-weight:700; padding:0.15rem 0.4rem; border-radius:4px;">${item.numero_carga}</span>` : ''}
                            <span style="background:rgba(245,158,11,0.15); color:#f59e0b; border:1px solid rgba(245,158,11,0.3); font-size:0.7rem; font-weight:700; padding:0.15rem 0.4rem; border-radius:4px;">PENDIENTE</span>
                        </div>
                        <div style="margin-top:0.3rem; font-size:0.85rem; color:#fff;">
                            <strong>${this.escapeHtml(item.cliente_nombre)}</strong> ${item.cliente_telefono ? `(📞 ${this.escapeHtml(item.cliente_telefono)})` : ''}
                        </div>
                        <div style="font-size:0.72rem; color:var(--pos-text-muted); margin-top:0.2rem;">
                            Ingresó: ${item.fecha_venta} | Registró: ${this.escapeHtml(item.cajero_nombre)}
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:1.4rem; font-weight:800; color:#34d399; font-family:'JetBrains Mono',monospace;">
                            $${parseFloat(item.total).toFixed(2)}
                        </div>
                        <button class="pos-btn-pay" style="padding:0.45rem 0.9rem; font-size:0.82rem; margin-top:0.4rem;" onclick="POS.abrirCobroPendiente('${item.id}', '${item.folio}', ${item.total})">
                            Cobrar y Entregar
                        </button>
                    </div>
                </div>
            `).join('');

        } catch (e) {
            container.innerHTML = '<div style="color:red; text-align:center;">Error cargando notas pendientes.</div>';
        }
    },

    abrirCobroPendiente(ventaId, folio, total) {
        const metodo = prompt(`Cobrar Nota ${folio} por $${parseFloat(total).toFixed(2)}\n\nElige método de pago:\n1 = EFECTIVO\n2 = TARJETA\n3 = TRANSFERENCIA`, "1");
        if (!metodo) return;

        let metodoNombre = 'EFECTIVO';
        if (metodo === '2') metodoNombre = 'TARJETA';
        if (metodo === '3') metodoNombre = 'TRANSFERENCIA';

        this.liquidarVentaPendiente(ventaId, metodoNombre, total);
    },

    async liquidarVentaPendiente(ventaId, metodo, total) {
        try {
            const res = await fetch('api/pos_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'pagar_pendiente',
                    venta_id: ventaId,
                    metodo_pago: metodo,
                    monto_recibido: total
                })
            });
            const data = await res.json();
            if (data.success) {
                alert('Cobro registrado exitosamente.');
                this.closeModal('modal-pendientes');
                if (data.ticket) {
                    this.mostrarTicket(data.ticket);
                }
                this.loadInitialData();
            } else {
                alert(data.message || 'Error al liquidar cobro');
            }
        } catch (e) {
            alert('Error de conexión');
        }
    },

    // ─────────────────────────────────────────────────────────────
    // Control de Turno y Corte de Caja (Fase 13)
    // ─────────────────────────────────────────────────────────────
    async abrirCajaForm(form) {
        const monto = parseFloat(form.monto_inicial.value) || 0;
        const obs = form.observaciones.value;

        try {
            const res = await fetch('api/pos_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'abrir_caja',
                    monto_inicial: monto,
                    observaciones: obs
                })
            });
            const data = await res.json();
            if (data.success) {
                alert(data.message);
                this.closeModal('modal-apertura-caja');
                await this.loadInitialData();
            } else {
                alert(data.message);
            }
        } catch (e) {
            alert('Error al abrir caja');
        }
    },

    async abrirModalCorte() {
        if (!this.caja) {
            alert('No hay una caja abierta actualmente.');
            return;
        }

        try {
            const res = await fetch(`api/pos_api.php?action=resumen_corte&caja_id=${this.caja.id}`);
            const data = await res.json();

            if (!data.success) {
                alert(data.message);
                return;
            }

            document.getElementById('corte-fondo-inicial').textContent = `$${data.monto_inicial.toFixed(2)}`;
            document.getElementById('corte-efectivo-ventas').textContent = `$${data.total_efectivo.toFixed(2)}`;
            document.getElementById('corte-tarjeta-ventas').textContent = `$${data.total_tarjeta.toFixed(2)}`;
            document.getElementById('corte-transf-ventas').textContent = `$${data.total_transferencia.toFixed(2)}`;
            document.getElementById('corte-esperado-efectivo').textContent = `$${data.total_esperado_efectivo.toFixed(2)}`;
            document.getElementById('corte-cant-ventas').textContent = data.cantidad_ventas;

            const inputDeclarado = document.getElementById('corte-efectivo-declarado');
            inputDeclarado.value = data.total_esperado_efectivo.toFixed(2);
            this.calcDiferenciaCorte(data.total_esperado_efectivo);

            inputDeclarado.oninput = () => {
                this.calcDiferenciaCorte(data.total_esperado_efectivo);
            };

            this.openModal('modal-corte-caja');
        } catch (e) {
            alert('Error obteniendo resumen de corte');
        }
    },

    calcDiferenciaCorte(esperado) {
        const declarado = parseFloat(document.getElementById('corte-efectivo-declarado').value) || 0;
        const diff = declarado - esperado;
        const diffEl = document.getElementById('corte-diferencia-val');
        const badgeEl = document.getElementById('corte-diferencia-badge');

        diffEl.textContent = `${diff >= 0 ? '+' : ''}$${diff.toFixed(2)}`;

        if (Math.abs(diff) < 0.01) {
            badgeEl.className = 'pos-badge-front';
            badgeEl.textContent = 'Caja Cuadrada ✅';
            badgeEl.style.background = 'rgba(16,185,129,0.2)';
            badgeEl.style.color = '#34d399';
        } else if (diff > 0) {
            badgeEl.className = 'pos-badge-front';
            badgeEl.textContent = 'Sobrante 🟢';
            badgeEl.style.background = 'rgba(59,130,246,0.2)';
            badgeEl.style.color = '#60a5fa';
        } else {
            badgeEl.className = 'pos-badge-front';
            badgeEl.textContent = 'Faltante 🔴';
            badgeEl.style.background = 'rgba(239,68,68,0.2)';
            badgeEl.style.color = '#f87171';
        }
    },

    async cerrarCajaConfirm() {
        if (!confirm('¿Estás seguro de que deseas cerrar el turno de caja actual? Se generará el corte y no se podrán registrar más ventas en este turno.')) {
            return;
        }

        const declarado = parseFloat(document.getElementById('corte-efectivo-declarado').value) || 0;
        const obs = document.getElementById('corte-observaciones').value;

        try {
            const res = await fetch('api/pos_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'cerrar_caja',
                    caja_id: this.caja.id,
                    efectivo_declarado: declarado,
                    observaciones: obs
                })
            });
            const data = await res.json();
            if (data.success) {
                alert(`Caja cerrada exitosamente.\nDiferencia final: $${data.diferencia.toFixed(2)}`);
                this.closeModal('modal-corte-caja');
                await this.loadInitialData();
            } else {
                alert(data.message || 'Error al cerrar caja');
            }
        } catch (e) {
            alert('Error de conexión al cerrar caja');
        }
    },

    // ─────────────────────────────────────────────────────────────
    // Historial del Día y Cancelaciones (Fase 12)
    // ─────────────────────────────────────────────────────────────
    async abrirModalHistorial() {
        this.openModal('modal-historial');
        const container = document.getElementById('pos-historial-list');
        if (!container) return;
        container.innerHTML = '<div style="text-align:center; padding:2rem; color:var(--pos-text-muted);">Cargando ventas...</div>';

        try {
            const res = await fetch('api/pos_api.php?action=ventas_recientes');
            const data = await res.json();

            if (!data.success || !data.ventas || data.ventas.length === 0) {
                container.innerHTML = '<div style="text-align:center; padding:2rem; color:var(--pos-text-muted);">No hay ventas registradas hoy.</div>';
                return;
            }

            container.innerHTML = data.ventas.map(v => {
                const isCancel = v.estado === 'CANCELADA';
                const isPagada = v.estado_pago === 'PAGADO';
                return `
                    <div style="background:rgba(15,23,42,0.6); border:1px solid var(--pos-border); border-radius:10px; padding:0.85rem 1rem; display:flex; justify-content:space-between; align-items:center; gap:0.75rem;">
                        <div>
                            <div style="display:flex; align-items:center; gap:0.5rem;">
                                <strong style="font-size:0.95rem; color:#fff;">${v.folio}</strong>
                                ${v.numero_carga ? `<span style="background:rgba(59,130,246,0.15); color:#60a5fa; font-size:0.7rem; font-weight:700; padding:0.15rem 0.4rem; border-radius:4px;">${v.numero_carga}</span>` : ''}
                                <span style="font-size:0.68rem; font-weight:700; padding:0.15rem 0.45rem; border-radius:4px; ${isCancel ? 'background:rgba(239,68,68,0.2); color:#f87171;' : (isPagada ? 'background:rgba(16,185,129,0.2); color:#34d399;' : 'background:rgba(245,158,11,0.2); color:#fbbf24;')}">
                                    ${isCancel ? 'CANCELADA' : (isPagada ? 'PAGADO' : 'PENDIENTE')}
                                </span>
                            </div>
                            <div style="font-size:0.82rem; color:#fff; margin-top:0.25rem;">
                                Cliente: <strong>${this.escapeHtml(v.cliente_nombre)}</strong>
                            </div>
                            <div style="font-size:0.72rem; color:var(--pos-text-muted);">
                                ${v.fecha_venta} | Cajero: ${this.escapeHtml(v.cajero_nombre)} | Pago: ${v.metodo_pago}
                            </div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:1.25rem; font-weight:800; color:#34d399; font-family:'JetBrains Mono',monospace;">
                                $${parseFloat(v.total).toFixed(2)}
                            </div>
                            <div style="display:flex; gap:0.4rem; justify-content:flex-end; margin-top:0.35rem;">
                                <button class="pos-btn-head" style="padding:0.3rem 0.6rem; font-size:0.75rem;" onclick="POS.reimprimirTicket('${v.id}')">
                                    🖨️ Ticket
                                </button>
                                ${!isCancel ? `
                                <button class="pos-btn-head" style="padding:0.3rem 0.6rem; font-size:0.75rem; border-color:rgba(239,68,68,0.4); color:#f87171;" onclick="POS.iniciarCancelacion('${v.id}', '${v.folio}')">
                                    ❌ Cancelar
                                </button>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        } catch (e) {
            container.innerHTML = '<div style="color:red; text-align:center;">Error cargando historial.</div>';
        }
    },

    async reimprimirTicket(ventaId) {
        try {
            const res = await fetch(`api/pos_api.php?action=obtener_ticket&venta_id=${ventaId}`);
            const data = await res.json();
            if (data.success && data.ticket) {
                this.mostrarTicket(data.ticket);
            } else {
                alert(data.message || 'No se pudo cargar el ticket.');
            }
        } catch (e) {
            alert('Error al obtener ticket.');
        }
    },

    iniciarCancelacion(ventaId, folio) {
        document.getElementById('cancel-venta-id').value = ventaId;
        document.getElementById('cancel-folio-txt').textContent = folio;
        document.getElementById('cancel-motivo').value = '';

        const adminArea = document.getElementById('cancel-admin-auth');
        if (this.user && !this.user.isAdmin) {
            adminArea.style.display = 'block';
            document.getElementById('cancel-admin-pass').value = '';
        } else {
            adminArea.style.display = 'none';
        }

        this.openModal('modal-cancelar-venta');
    },

    async confirmarCancelacion(form) {
        const ventaId = form.venta_id.value;
        const motivo = form.motivo.value.trim();
        if (!motivo) {
            alert('El motivo de la cancelación es obligatorio.');
            return;
        }

        const payload = {
            action: 'cancelar_venta',
            venta_id: ventaId,
            motivo: motivo
        };

        if (this.user && !this.user.isAdmin) {
            payload.admin_correo = form.admin_correo ? form.admin_correo.value : '';
            payload.admin_password = form.admin_password.value;
            if (!payload.admin_password) {
                alert('Se requiere la contraseña del administrador para autorizar la cancelación.');
                return;
            }
        }

        try {
            const res = await fetch('api/pos_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.success) {
                alert(data.message);
                this.closeModal('modal-cancelar-venta');
                this.abrirModalHistorial();
                this.loadInitialData();
            } else {
                alert(data.message || 'Error al cancelar venta');
            }
        } catch (e) {
            alert('Error de conexión');
        }
    },

    // ─────────────────────────────────────────────────────────────
    // Manejo de Modales
    // ─────────────────────────────────────────────────────────────
    openModal(id) {
        const modal = document.getElementById(id);
        if (modal) {
            modal.classList.add('active');
            this.activeModal = id;
        }
    },

    closeModal(id) {
        const modal = document.getElementById(id);
        if (modal) {
            modal.classList.remove('active');
            if (this.activeModal === id) this.activeModal = null;
        }
    },

    closeAllModals() {
        document.querySelectorAll('.pos-modal-overlay').forEach(m => m.classList.remove('active'));
        this.activeModal = null;
    },

    // ─────────────────────────────────────────────────────────────
    // Eventos y Teclado / Scanner
    // ─────────────────────────────────────────────────────────────
    bindEvents() {
        // Buscador de productos
        const searchInput = document.getElementById('pos-search-input');
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                this.searchQuery = e.target.value;
                this.renderProducts();
            });

            // Enter en buscador: escanear código de barras o añadir coincidencia exacta
            searchInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const q = searchInput.value.trim().toLowerCase();
                    if (!q) return;

                    // Buscar coincidencia exacta por código de barras o SKU
                    const exact = this.products.find(p => 
                        (p.codigo_barras && p.codigo_barras.toLowerCase() === q) ||
                        (p.codigo_interno_sku && p.codigo_interno_sku.toLowerCase() === q)
                    );

                    if (exact) {
                        this.addToCart(exact.id);
                        searchInput.value = '';
                        this.searchQuery = '';
                        this.renderProducts();
                    } else {
                        // Si hay solo 1 coincidencia en pantalla, añadirla
                        const visibleCards = document.querySelectorAll('.pos-prod-card');
                        if (visibleCards.length === 1) {
                            const pId = visibleCards[0].dataset.id;
                            this.addToCart(pId);
                            searchInput.value = '';
                            this.searchQuery = '';
                            this.renderProducts();
                        }
                    }
                }
            });
        }

        // Atajos de teclado globales
        window.addEventListener('keydown', (e) => {
            // Escape: cerrar modales
            if (e.key === 'Escape') {
                this.closeAllModals();
            }
            // F2: enfocar buscador
            if (e.key === 'F2') {
                e.preventDefault();
                searchInput?.focus();
            }
            // F4: cobrar
            if (e.key === 'F4') {
                e.preventDefault();
                if (this.cart.length > 0) this.abrirModalCobro();
            }
            // F8: pendientes
            if (e.key === 'F8') {
                e.preventDefault();
                this.abrirModalPendientes();
            }
            // F9: corte de caja
            if (e.key === 'F9') {
                e.preventDefault();
                this.abrirModalCorte();
            }
        });
    },

    playBeep() {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.type = 'sine';
            osc.frequency.setValueAtTime(800, ctx.currentTime);
            gain.gain.setValueAtTime(0.05, ctx.currentTime);
            osc.start();
            osc.stop(ctx.currentTime + 0.08);
        } catch (e) {
            // Audio no disponible o no permitido por navegador
        }
    },

    escapeHtml(text) {
        if (!text) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.toString().replace(/[&<>"']/g, m => map[m]);
    }
};

document.addEventListener('DOMContentLoaded', () => {
    POS.init();
});
