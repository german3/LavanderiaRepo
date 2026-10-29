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
    selectedKit: null,
    selectedInsumo: null,
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
            if (res.status === 401) {
                window.location.href = 'pos_login.php';
                return;
            }
            const data = await res.json();

            if (!data.success) {
                alert(data.message || 'Error al conectar con el servidor.');
                return;
            }

            this.user = data.user;
            this.caja = data.caja_abierta;
            this.products = data.productos || data.products || [];
            this.clients = data.clientes || data.clients || [];

            this.populateClientesSelect();
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
            } else if (p.tipo === 'INSUMO') {
                const uMed = p.unidad_medida ? ` ${p.unidad_medida}` : '';
                if (stock <= 0) {
                    stockBadge = `<span class="pos-prod-stock out">Sin Stock (0${uMed})</span>`;
                } else if (stock <= stockMin) {
                    stockBadge = `<span class="pos-prod-stock low">Stock Bajo (${stock}${uMed})</span>`;
                } else {
                    stockBadge = `<span class="pos-prod-stock">Stock: ${stock}${uMed}</span>`;
                }
            } else {
                stockBadge = `<span class="pos-prod-stock">Servicio</span>`;
            }

            const kgBadge = ropaKg > 0 ? `<span class="pos-prod-kg">⚖️ ${ropaKg} kg</span>` : '';

            const isKitSelected = (this.currentCategory === 'KIT' && this.selectedKit && this.selectedKit.id === p.id);
            const currentSelectedProdId = document.getElementById('pos-selected-producto-id')?.value;
            const isInsumoSelected = (p.tipo === 'INSUMO' && (
                (this.selectedInsumo && this.selectedInsumo.id === p.id) ||
                (currentSelectedProdId && currentSelectedProdId === p.id)
            ));

            let cardSelectedClass = '';
            let selectedBadge = '';
            if (isKitSelected) {
                cardSelectedClass = 'pos-kit-selected';
                selectedBadge = '<span style="font-size:0.65rem; font-weight:700; color:#c084fc; background:rgba(168,85,247,0.25); padding:0.1rem 0.45rem; border-radius:4px;">✓ SELECCIONADO</span>';
            } else if (isInsumoSelected) {
                cardSelectedClass = 'pos-insumo-selected';
                selectedBadge = '<span style="font-size:0.65rem; font-weight:700; color:#fbbf24; background:rgba(245,158,11,0.25); border:1px solid rgba(245,158,11,0.4); padding:0.1rem 0.45rem; border-radius:4px;">✓ SELECCIONADO</span>';
            }

            return `
                <div class="pos-prod-card ${cardSelectedClass}" onclick="POS.addToCart('${p.id}')" data-id="${p.id}">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.25rem;">
                        <span class="pos-prod-badge ${p.tipo}">${p.tipo === 'NORMAL' ? 'Producto' : p.tipo}</span>
                        ${selectedBadge}
                    </div>
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
        this.adaptarBuscadorSegunCategoria(cat);
        this.renderProducts();
        this.toggleProductoFieldForKits(cat === 'KIT');
    },

    adaptarBuscadorSegunCategoria(cat) {
        const label = document.querySelector('label[for="pos-search-producto-input"]');
        const input = document.getElementById('pos-search-producto-input');
        const dropdown = document.getElementById('pos-producto-dropdown-results');
        if (dropdown) dropdown.style.display = 'none';

        if (cat === 'NORMAL') {
            if (label) label.textContent = '2. Buscar Producto (*)';
            if (input) input.placeholder = 'Buscar producto por nombre, SKU o código de barras...';
        } else if (cat === 'INSUMO') {
            if (label) label.textContent = '2. Buscar Insumo (*)';
            if (input) input.placeholder = 'Buscar insumo por nombre, SKU o código de barras...';
        } else if (cat === 'SERVICIO') {
            if (label) label.textContent = '2. Buscar Servicio de Lavandería (*)';
            if (input) input.placeholder = 'Buscar servicio por nombre o SKU...';
        } else {
            if (label) label.textContent = '2. Producto o Servicio (*)';
            if (input) input.placeholder = 'Buscar por nombre, SKU o código de barras...';
        }

        // Si hay un producto seleccionado previamente pero es de otra categoría, limpiarlo
        const currentProdId = document.getElementById('pos-selected-producto-id')?.value;
        if (currentProdId && cat !== 'ALL' && cat !== 'KIT') {
            const currentProd = this.products.find(p => p.id === currentProdId);
            if (currentProd && currentProd.tipo !== cat) {
                this.clearProductoSeleccionado(false);
            }
        }
    },

    toggleProductoFieldForKits(isKit) {
        const prodCol = document.getElementById('pos-field-producto-col') || document.querySelector('.pos-prod-search-col');
        const entryGrid = document.getElementById('pos-entry-grid') || document.querySelector('.pos-entry-grid');
        const previewBadge = document.getElementById('pos-entry-preview');
        const titleText = document.getElementById('pos-entry-title-text');
        const btnAdd = document.getElementById('pos-btn-add-reg');
        const inputKg = document.getElementById('pos-input-kilos');

        if (isKit) {
            // Ocultar campo de 2. PRODUCTOS O SERVICIO
            if (prodCol) prodCol.classList.add('pos-hidden');
            if (entryGrid) entryGrid.classList.add('hide-producto');

            // Limpiar cualquier producto que estuviese seleccionado previamente en el buscador
            this.clearProductoSeleccionado(false);

            // Auto-seleccionar el primer kit si aún no hay uno seleccionado
            const kits = this.products.filter(p => p.tipo === 'KIT');
            if (kits.length > 0 && !this.selectedKit) {
                this.selectedKit = kits[0];
            }

            if (this.selectedKit && this.selectedKit.ropa_kg > 0 && inputKg) {
                if (!inputKg.value || parseFloat(inputKg.value) <= 1) {
                    inputKg.value = parseFloat(this.selectedKit.ropa_kg).toFixed(1);
                }
            }

            if (titleText) {
                titleText.textContent = this.selectedKit 
                    ? `Kits y Paquetes: ${this.selectedKit.descripcion}`
                    : 'Selección de Kit / Paquete por Kilos';
            }
            if (btnAdd) {
                btnAdd.setAttribute('title', 'Agregar este paquete según los kilos ingresados');
            }
            this.renderProducts();
            this.calcPreviewCosto();
        } else {
            this.selectedKit = null;
            if (prodCol) prodCol.classList.remove('pos-hidden');
            if (entryGrid) entryGrid.classList.remove('hide-producto');

            if (titleText) {
                titleText.textContent = 'Registro de Servicio / Venta por Kilos';
            }
            if (previewBadge) {
                previewBadge.textContent = 'Subtotal: $0.00';
                previewBadge.style.background = '';
                previewBadge.style.color = '';
                previewBadge.style.borderColor = '';
            }
            if (btnAdd) {
                btnAdd.setAttribute('title', 'Agregar a Lista');
            }
            this.renderProducts();
            this.calcPreviewCosto();
        }
    },

    // ─────────────────────────────────────────────────────────────
    // Poblar Selectores de Captura Rápida (FrontOffice)
    // ─────────────────────────────────────────────────────────────
    populateClientesSelect() {
        // Inicializar el combobox de cliente con el valor por defecto: Público General
        this.selectCliente('MOSTRADOR', 'Público General / Mostrador');
    },

    // ─────────────────────────────────────────────────────────────
    // Búsqueda Interactiva de Producto o Servicio (Nombre o Código de Barras)
    // ─────────────────────────────────────────────────────────────
    getSearchPool() {
        if (this.currentCategory && this.currentCategory !== 'ALL') {
            return this.products.filter(p => p.tipo === this.currentCategory);
        }
        return this.products;
    },

    onSearchProductoInput(query) {
        const dropdown = document.getElementById('pos-producto-dropdown-results');
        const clearBtn = document.getElementById('pos-btn-clear-prod');
        if (!dropdown) return;

        const q = (query || '').trim().toLowerCase();

        if (clearBtn) {
            clearBtn.style.display = q !== '' ? 'flex' : 'none';
        }

        if (q === '') {
            dropdown.style.display = 'none';
            this.searchQuery = '';
            this.renderProducts();
            this.clearProductoSeleccionado(false);
            return;
        }

        // Sincronizar catálogo visual en cuadrícula
        this.searchQuery = q;
        this.renderProducts();

        const pool = this.getSearchPool();

        // Detección automática de escaneo de código de barras exacto dentro de la categoría activa
        const exactBarcode = pool.find(p => 
            p.codigo_barras && p.codigo_barras.toLowerCase() === q
        );
        if (exactBarcode && q.length >= 3) {
            this.seleccionarProducto(exactBarcode.id);
            return;
        }

        // Filtrar productos por nombre, SKU o código de barras según la categoría activa
        const matches = pool.filter(p => 
            (p.descripcion && p.descripcion.toLowerCase().includes(q)) ||
            (p.codigo_barras && p.codigo_barras.toLowerCase().includes(q)) ||
            (p.codigo_interno_sku && p.codigo_interno_sku.toLowerCase().includes(q))
        ).slice(0, 20);

        this.renderProductoDropdownResults(matches);
    },

    onSearchProductoFocus() {
        const input = document.getElementById('pos-search-producto-input');
        const currentProdId = document.getElementById('pos-selected-producto-id')?.value;
        const query = (input?.value || '').trim().toLowerCase();

        // Si no hay un producto seleccionado o si el usuario enfoca para buscar
        if (!currentProdId) {
            const pool = this.getSearchPool();
            const matches = query !== '' 
                ? pool.filter(p => 
                    (p.descripcion && p.descripcion.toLowerCase().includes(query)) ||
                    (p.codigo_barras && p.codigo_barras.toLowerCase().includes(query)) ||
                    (p.codigo_interno_sku && p.codigo_interno_sku.toLowerCase().includes(query))
                  ).slice(0, 20)
                : pool.slice(0, 15); // Mostrar sugerencias de la categoría activa
            this.renderProductoDropdownResults(matches);
        }
    },

    onSearchProductoKeydown(e) {
        const dropdown = document.getElementById('pos-producto-dropdown-results');
        if (!dropdown || dropdown.style.display === 'none') {
            if (e.key === 'Enter') {
                e.preventDefault();
                // Pasar el foco a kilos si ya hay producto
                const prodId = document.getElementById('pos-selected-producto-id')?.value;
                if (prodId) {
                    document.getElementById('pos-input-kilos')?.focus();
                }
            }
            return;
        }

        const items = dropdown.querySelectorAll('.pos-prod-dropdown-item');
        if (items.length === 0) return;

        let activeIdx = Array.from(items).findIndex(el => el.classList.contains('active'));

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (activeIdx >= 0) items[activeIdx].classList.remove('active');
            activeIdx = (activeIdx + 1) % items.length;
            items[activeIdx].classList.add('active');
            items[activeIdx].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (activeIdx >= 0) items[activeIdx].classList.remove('active');
            activeIdx = (activeIdx - 1 + items.length) % items.length;
            items[activeIdx].classList.add('active');
            items[activeIdx].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIdx >= 0 && items[activeIdx]) {
                const prodId = items[activeIdx].dataset.id;
                this.seleccionarProducto(prodId);
            } else if (items.length > 0) {
                // Seleccionar el primer resultado
                const prodId = items[0].dataset.id;
                this.seleccionarProducto(prodId);
            }
        } else if (e.key === 'Escape') {
            dropdown.style.display = 'none';
        }
    },

    renderProductoDropdownResults(matches) {
        const dropdown = document.getElementById('pos-producto-dropdown-results');
        if (!dropdown) return;

        if (matches.length === 0) {
            let catName = 'productos ni servicios';
            if (this.currentCategory === 'NORMAL') catName = 'productos';
            else if (this.currentCategory === 'INSUMO') catName = 'insumos';
            else if (this.currentCategory === 'SERVICIO') catName = 'servicios';

            dropdown.innerHTML = `
                <div style="padding:1rem; text-align:center; color:var(--pos-text-muted); font-size:0.82rem;">
                    🔍 No se encontraron ${catName} con ese término o código.
                </div>
            `;
            dropdown.style.display = 'block';
            return;
        }

        dropdown.innerHTML = matches.map((p, idx) => {
            const precio = parseFloat(p.precio_venta).toFixed(2);
            const unit = p.tipo === 'SERVICIO' ? 'kg' : (p.unidad_medida || 'pza');
            const typeBadge = p.tipo === 'SERVICIO' 
                ? '<span style="font-size:0.65rem; padding:0.1rem 0.4rem; border-radius:4px; background:rgba(56,189,248,0.2); color:#38bdf8; font-weight:700;">SERVICIO</span>'
                : (p.tipo === 'KIT' ? '<span style="font-size:0.65rem; padding:0.1rem 0.4rem; border-radius:4px; background:rgba(168,85,247,0.2); color:#c084fc; font-weight:700;">KIT</span>' 
                                   : (p.tipo === 'INSUMO' ? '<span style="font-size:0.65rem; padding:0.1rem 0.4rem; border-radius:4px; background:rgba(245,158,11,0.2); color:#fbbf24; font-weight:700;">INSUMO</span>' : '<span style="font-size:0.65rem; padding:0.1rem 0.4rem; border-radius:4px; background:rgba(16,185,129,0.2); color:#34d399; font-weight:700;">PRODUCTO</span>'));
            const codeInfo = p.codigo_barras ? ` | 🏷️ ${this.escapeHtml(p.codigo_barras)}` : (p.codigo_interno_sku ? ` | SKU: ${this.escapeHtml(p.codigo_interno_sku)}` : '');

            return `
                <div class="pos-prod-dropdown-item ${idx === 0 ? 'active' : ''}" data-id="${p.id}" onclick="POS.seleccionarProducto('${p.id}')">
                    <div class="pos-prod-dropdown-info">
                        <div class="pos-prod-dropdown-title">${this.escapeHtml(p.descripcion)}</div>
                        <div class="pos-prod-dropdown-meta">
                            ${typeBadge}
                            <span>${codeInfo}</span>
                        </div>
                    </div>
                    <div class="pos-prod-dropdown-price">
                        $${precio} <span style="font-size:0.68rem; color:var(--pos-text-muted); font-weight:400;">/ ${unit}</span>
                    </div>
                </div>
            `;
        }).join('');

        dropdown.style.display = 'block';
    },

    seleccionarProducto(prodId) {
        const prod = this.products.find(p => p.id === prodId);
        if (!prod) return;

        const inputHidden = document.getElementById('pos-selected-producto-id');
        const inputSearch = document.getElementById('pos-search-producto-input');
        const clearBtn = document.getElementById('pos-btn-clear-prod');
        const dropdown = document.getElementById('pos-producto-dropdown-results');
        const inputKg = document.getElementById('pos-input-kilos');

        if (inputHidden) inputHidden.value = prod.id;
        this.selectedInsumo = (prod.tipo === 'INSUMO') ? prod : null;
        if (inputSearch) inputSearch.value = `${prod.descripcion} ($${parseFloat(prod.precio_venta).toFixed(2)})`;
        if (clearBtn) clearBtn.style.display = 'flex';
        if (dropdown) dropdown.style.display = 'none';

        // Sugerir kilos si el producto tiene sugerencia de peso
        if (inputKg && (!inputKg.value || parseFloat(inputKg.value) <= 0)) {
            inputKg.value = prod.ropa_kg > 0 ? parseFloat(prod.ropa_kg).toFixed(1) : '1.0';
        }

        this.calcPreviewCosto();
        this.searchQuery = '';
        this.renderProducts();

        // Enfocar el campo de kilos para agilizar la captura
        if (inputKg) {
            inputKg.focus();
            inputKg.select();
        }
    },

    clearProductoSeleccionado(focusInput = true) {
        this.selectedInsumo = null;
        const inputHidden = document.getElementById('pos-selected-producto-id');
        const inputSearch = document.getElementById('pos-search-producto-input');
        const clearBtn = document.getElementById('pos-btn-clear-prod');
        const dropdown = document.getElementById('pos-producto-dropdown-results');

        if (inputHidden) inputHidden.value = '';
        if (inputSearch) inputSearch.value = '';
        if (clearBtn) clearBtn.style.display = 'none';
        if (dropdown) dropdown.style.display = 'none';

        this.searchQuery = '';
        this.renderProducts();
        this.calcPreviewCosto();

        if (focusInput && inputSearch) {
            inputSearch.focus();
        }
    },

    onClienteSelectChange(clienteId) {
        const hintEl = document.getElementById('pos-selected-client-hint');
        if (!clienteId || clienteId === '') {
            this.selectedClient = null;
            if (hintEl) hintEl.textContent = '';
            return;
        }

        if (clienteId === 'MOSTRADOR') {
            this.selectedClient = { id: null, nombre: 'Público General / Mostrador', telefono: '' };
            if (hintEl) hintEl.textContent = '👤 Cliente: Público General';
            return;
        }

        const client = this.clients.find(c => c.id === clienteId);
        if (client) {
            this.selectedClient = client;
            if (hintEl) {
                hintEl.innerHTML = `👤 Seleccionado: <strong>${this.escapeHtml(client.nombre)}</strong> (📞 ${this.escapeHtml(client.telefono || 'Sin tel')})`;
            }
        }
    },

    setKilosInput(val) {
        const inputKg = document.getElementById('pos-input-kilos');
        if (inputKg) {
            inputKg.value = val;
            this.calcPreviewCosto();
            inputKg.focus();
        }
    },

    stepKilos(delta) {
        const inputKg = document.getElementById('pos-input-kilos');
        if (!inputKg) return;
        let current = parseFloat(inputKg.value) || 0;
        current = +(current + delta).toFixed(2);
        if (current < 0.05) current = 0.05;
        inputKg.value = current;
        this.calcPreviewCosto();
    },

    calcPreviewCosto() {
        const inputKg = document.getElementById('pos-input-kilos');
        const previewEl = document.getElementById('pos-entry-preview');
        if (!previewEl) return;

        const kilos = inputKg ? (parseFloat(inputKg.value) || 0) : 0;

        if (this.currentCategory === 'KIT') {
            const kit = this.selectedKit || this.products.find(p => p.tipo === 'KIT');
            if (!kit) {
                previewEl.textContent = '🎁 Modo Kits: Selecciona un kit del catálogo';
                return;
            }
            const kitKilos = parseFloat(kit.ropa_kg) || 0;
            const precioUnit = parseFloat(kit.precio_venta) || 0;

            // Regla de Kilos:
            // <= kitKilos: x1
            // > kitKilos y <= 2*kitKilos: x2
            // > 2*kitKilos y <= 3*kitKilos: x3 ...
            let multiplicador = 1;
            if (kitKilos > 0 && kilos > 0) {
                multiplicador = Math.ceil(kilos / kitKilos);
                if (multiplicador < 1) multiplicador = 1;
            }

            const subtotal = +(multiplicador * precioUnit).toFixed(2);
            previewEl.textContent = `🎁 Subtotal: $${subtotal.toFixed(2)} (${multiplicador} paquete${multiplicador > 1 ? 's' : ''} ${kit.descripcion} para ${kilos.toFixed(1)} kg)`;
            previewEl.style.background = 'rgba(168, 85, 247, 0.2)';
            previewEl.style.color = '#c084fc';
            previewEl.style.borderColor = 'rgba(168, 85, 247, 0.4)';
            return;
        }

        const inputHidden = document.getElementById('pos-selected-producto-id');
        const prodId = inputHidden ? inputHidden.value : '';

        if (!prodId) {
            previewEl.textContent = 'Subtotal: $0.00';
            previewEl.style.background = '';
            previewEl.style.color = '';
            previewEl.style.borderColor = '';
            return;
        }

        const prod = this.products.find(p => p.id === prodId);
        if (!prod) {
            previewEl.textContent = 'Subtotal: $0.00';
            previewEl.style.background = '';
            previewEl.style.color = '';
            previewEl.style.borderColor = '';
            return;
        }

        const precio = parseFloat(prod.precio_venta) || 0;
        const subtotal = (kilos > 0 ? kilos : 1) * precio;
        previewEl.textContent = `Subtotal: $${subtotal.toFixed(2)} (${kilos.toFixed(2)} kg x $${precio.toFixed(2)})`;
        previewEl.style.background = '';
        previewEl.style.color = '';
        previewEl.style.borderColor = '';
    },

    agregarRegistroFormulario() {
        const selectCli = document.getElementById('pos-selected-cliente-id');
        const inputKg = document.getElementById('pos-input-kilos');
        const kilos = parseFloat(inputKg ? inputKg.value : 0);

        if (isNaN(kilos) || kilos <= 0) {
            alert('Por favor ingresa una cantidad de kilos válida mayor a 0 en el campo "3. Cantidad de Kilos".');
            inputKg?.focus();
            return;
        }

        // Manejo especial cuando estamos en la categoría de Kits y Paquetes
        if (this.currentCategory === 'KIT') {
            const kit = this.selectedKit || this.products.find(p => p.tipo === 'KIT');
            if (!kit) {
                alert('No hay ningún Kit disponible en el catálogo.');
                return;
            }
            this.agregarKitPorKilos(kit, kilos);
            return;
        }

        const clienteId = selectCli ? selectCli.value : '';
        if (!clienteId || clienteId === '') {
            alert('Por favor selecciona un cliente de los registrados (o elige Público General) para relacionarlo a este registro.');
            document.getElementById('pos-cliente-search-input')?.focus();
            return;
        }

        const inputProdId = document.getElementById('pos-selected-producto-id');
        const inputSearch = document.getElementById('pos-search-producto-input');
        const prodId = inputProdId ? inputProdId.value : '';
        if (!prodId || prodId === '') {
            alert('Por favor busca y selecciona un producto o servicio por su nombre o código de barras.');
            inputSearch?.focus();
            return;
        }

        const prod = this.products.find(p => p.id === prodId);
        if (!prod) {
            alert('Producto o servicio no encontrado.');
            return;
        }

        let clienteObj = null;
        if (clienteId === 'MOSTRADOR') {
            clienteObj = { id: null, nombre: 'Público General / Mostrador', telefono: '' };
        } else {
            clienteObj = this.clients.find(c => c.id === clienteId);
        }

        const precioUnitario = parseFloat(prod.precio_venta) || 0;
        const subtotalCalculado = +(kilos * precioUnitario).toFixed(2);

        // Crear registro asociado al cliente seleccionado
        const nuevoRegistro = {
            id_registro: Date.now() + Math.random().toString(36).substr(2, 5),
            producto_id: prod.id,
            descripcion: prod.descripcion,
            tipo: prod.tipo,
            unidad_medida: prod.unidad_medida,
            precio_unitario: precioUnitario,
            cantidad: kilos,
            ropa_kg: kilos,
            subtotal: subtotalCalculado,
            cliente_id: clienteObj ? clienteObj.id : null,
            cliente_nombre: clienteObj ? clienteObj.nombre : 'Público General',
            cliente_telefono: clienteObj ? (clienteObj.telefono || '') : '',
            notas: ''
        };

        this.cart.push(nuevoRegistro);
        this.renderCart();
        this.playBeep();

        // Limpiar producto para siguiente búsqueda pero mantener cliente activo para rapidez
        this.clearProductoSeleccionado(false);
        if (inputKg) inputKg.value = '1.0';
        inputSearch?.focus();
    },

    agregarKitPorKilos(kit, kilos) {
        const selectCli = document.getElementById('pos-selected-cliente-id');
        const clienteId = selectCli ? selectCli.value : '';

        let clienteObj = null;
        if (clienteId && clienteId !== 'MOSTRADOR') {
            clienteObj = this.clients.find(c => c.id === clienteId);
        } else if (clienteId === 'MOSTRADOR') {
            clienteObj = { id: null, nombre: 'Público General / Mostrador', telefono: '' };
        } else if (this.selectedClient) {
            clienteObj = this.selectedClient;
        }

        const kitKilos = parseFloat(kit.ropa_kg) || 0;
        const precioUnitario = parseFloat(kit.precio_venta) || 0;

        // Regla solicitada:
        // Si cantidad de kilos <= cantidad de kilos asignada al paquete (ej. 5 kg) -> x1
        // Si cantidad de kilos > paquete pero <= doble (ej. >5 y <=10 kg) -> x2
        // Si cantidad de kilos > doble pero <= triple (ej. >10 y <=15 kg) -> x3
        // y así sucesivamente: Math.ceil(kilos / kitKilos)
        let multiplicador = 1;
        if (kitKilos > 0 && kilos > 0) {
            multiplicador = Math.ceil(kilos / kitKilos);
            if (multiplicador < 1) multiplicador = 1;
        }

        const subtotalCalculado = +(multiplicador * precioUnitario).toFixed(2);

        const nuevoRegistro = {
            id_registro: Date.now() + Math.random().toString(36).substr(2, 5),
            producto_id: kit.id,
            descripcion: multiplicador > 1 
                ? `${kit.descripcion} (x${multiplicador} paquetes)` 
                : `${kit.descripcion} (1 paquete)`,
            tipo: 'KIT',
            unidad_medida: 'PAQ',
            precio_unitario: precioUnitario,
            cantidad: multiplicador,
            ropa_kg: kilos,
            subtotal: subtotalCalculado,
            cliente_id: clienteObj ? clienteObj.id : null,
            cliente_nombre: clienteObj ? clienteObj.nombre : (clienteId === 'MOSTRADOR' ? 'Público General / Mostrador' : 'Público General'),
            cliente_telefono: clienteObj ? (clienteObj.telefono || '') : '',
            notas: `${kilos.toFixed(2)} kg de ropa (${multiplicador} carga(s) de ${kitKilos} kg)`
        };

        this.cart.push(nuevoRegistro);
        this.renderCart();
        this.playBeep();

        // Destello visual en la tarjeta
        const card = document.querySelector(`.pos-prod-card[data-id="${kit.id}"]`);
        if (card) {
            card.classList.add('flash-added');
            setTimeout(() => card.classList.remove('flash-added'), 350);
        }
    },

    // ─────────────────────────────────────────────────────────────
    // Carrito de Compras / Lista de Registros
    // ─────────────────────────────────────────────────────────────
    addToCart(productId, qty = 1) {
        const prod = this.products.find(p => p.id === productId);
        if (!prod) return;

        // Si es un KIT o estamos en la pestaña Kits y Paquetes
        if (prod.tipo === 'KIT' || this.currentCategory === 'KIT') {
            this.selectedKit = prod;
            this.renderProducts();

            const titleText = document.getElementById('pos-entry-title-text');
            if (titleText) {
                titleText.textContent = `Kits y Paquetes: ${prod.descripcion}`;
            }

            const inputKg = document.getElementById('pos-input-kilos');
            if (inputKg) {
                // Sugerir los kilos base del paquete si el input está en 1 o vacío
                if (!inputKg.value || parseFloat(inputKg.value) <= 1) {
                    if (prod.ropa_kg > 0) inputKg.value = parseFloat(prod.ropa_kg).toFixed(1);
                }
                inputKg.focus();
                inputKg.select();
            }
            this.calcPreviewCosto();
            return;
        }

        // Para servicios o productos normales, seleccionarlo en el buscador superior para capturar kilos
        this.seleccionarProducto(productId);
    },

    updateQty(index, delta) {
        if (!this.cart[index]) return;
        const it = this.cart[index];
        if (it.tipo === 'KIT') {
            const step = delta > 0 ? 1 : -1;
            it.cantidad += step;
            if (it.cantidad <= 0) {
                this.removeFromCart(index);
                return;
            }
            it.subtotal = +(it.cantidad * it.precio_unitario).toFixed(2);
        } else {
            it.cantidad += delta;
            if (it.cantidad <= 0) {
                this.removeFromCart(index);
                return;
            }
            it.ropa_kg = it.cantidad;
            it.subtotal = +(it.cantidad * it.precio_unitario).toFixed(2);
        }
        this.renderCart();
    },

    setQty(index, value) {
        const val = parseFloat(value);
        if (isNaN(val) || val <= 0) {
            this.removeFromCart(index);
            return;
        }
        const it = this.cart[index];
        it.cantidad = val;
        if (it.tipo !== 'KIT') {
            it.ropa_kg = val;
        }
        it.subtotal = +(val * it.precio_unitario).toFixed(2);
        this.renderCart();
    },

    updateItemExtra(index, field, value) {
        if (!this.cart[index]) return;
        this.cart[index][field] = value;
        if (field === 'ropa_kg') {
            const kg = parseFloat(value) || 0;
            this.cart[index].cantidad = kg;
            this.cart[index].subtotal = +(kg * this.cart[index].precio_unitario).toFixed(2);
        }
        this.renderCart();
    },

    removeFromCart(index) {
        this.cart.splice(index, 1);
        this.renderCart();
    },

    clearCart() {
        if (this.cart.length === 0) return;
        if (confirm('¿Vaciar la lista de registros acumulados?')) {
            this.cart = [];
            this.renderCart();
        }
    },

    renderCart() {
        const container = document.getElementById('pos-cart-items');
        const custNameEl = document.getElementById('pos-cust-name');
        if (!container) return;

        if (this.cart.length === 0) {
            container.innerHTML = `
                <div class="pos-empty-cart">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                    <p style="font-weight:600; font-size:1.05rem;">Sin Registros Agregados</p>
                    <p style="font-size:0.8rem; margin-top:0.25rem;">Selecciona un cliente, producto/servicio y cantidad de kilos, y haz clic en "Agregar a Lista".</p>
                </div>
            `;
            if (custNameEl) custNameEl.textContent = '0 registros acumulados';
            this.renderCartSummaryOnly();
            return;
        }

        if (custNameEl) {
            custNameEl.textContent = `${this.cart.length} registro(s) listos para cobro`;
        }

        container.innerHTML = this.cart.map((item, idx) => {
            const isKit = item.tipo === 'KIT';
            const kilosVal = parseFloat(item.ropa_kg || item.cantidad || 0);
            const displayQty = isKit ? item.cantidad : kilosVal;
            const unitLabel = isKit ? 'paq' : 'kg';
            const stepVal = isKit ? '1' : '0.1';
            const deltaVal = isKit ? 1 : 0.5;
            const unitPricing = isKit 
                ? `$${item.precio_unitario.toFixed(2)} / paq <span style="font-size:0.65rem; color:#a5b4fc;">(${item.ropa_kg} kg)</span>` 
                : `$${item.precio_unitario.toFixed(2)} / kg`;

            return `
                <div class="pos-cart-item">
                    <div class="pos-item-header">
                        <div style="flex:1; overflow:hidden;">
                            <div class="pos-item-client-badge">
                                👤 <strong>${this.escapeHtml(item.cliente_nombre || 'Público General')}</strong>
                                ${item.cliente_telefono ? `<span style="opacity:0.85; margin-left:0.25rem;">📞 ${this.escapeHtml(item.cliente_telefono)}</span>` : ''}
                            </div>
                            <div class="pos-item-name">${this.escapeHtml(item.descripcion)}</div>
                        </div>
                        <button class="pos-item-remove" onclick="POS.removeFromCart(${idx})" title="Eliminar este registro">
                            <svg style="width:16px;height:16px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    </div>
                    <div class="pos-item-controls">
                        <div class="pos-qty-stepper">
                            <button class="pos-qty-btn" onclick="POS.updateQty(${idx}, -${deltaVal})">-</button>
                            <input type="number" step="${stepVal}" min="${isKit ? '1' : '0.1'}" class="pos-qty-input" style="width:58px;" value="${isKit ? displayQty : displayQty.toFixed(2)}" onchange="POS.setQty(${idx}, this.value)">
                            <button class="pos-qty-btn" onclick="POS.updateQty(${idx}, ${deltaVal})">+</button>
                            <span style="font-size:0.72rem; color:var(--pos-text-muted); padding-right:0.4rem; font-weight:700;">${unitLabel}</span>
                        </div>
                        <div class="pos-item-pricing">
                            <div class="pos-item-unit">${unitPricing}</div>
                            <div class="pos-item-subtotal">$${item.subtotal.toFixed(2)}</div>
                        </div>
                    </div>
                    <div class="pos-item-extra">
                        <input type="text" placeholder="Observaciones / prendas del cliente..." style="flex:1;" value="${this.escapeHtml(item.notas || '')}" onchange="POS.updateItemExtra(${idx}, 'notas', this.value)" title="Notas específicas para este registro">
                    </div>
                </div>
            `;
        }).join('');

        this.renderCartSummaryOnly();
    },

    renderCartSummaryOnly() {
        const totalItems = this.cart.reduce((acc, it) => acc + parseFloat(it.cantidad || 0), 0);
        const totalKg = this.cart.reduce((acc, it) => acc + (parseFloat(it.ropa_kg || it.cantidad || 0)), 0);
        const granTotal = this.cart.reduce((acc, it) => acc + parseFloat(it.subtotal || 0), 0);

        const itemsEl = document.getElementById('pos-summary-items');
        const kgEl = document.getElementById('pos-summary-kg');
        const totalEl = document.getElementById('pos-total-display');
        const payBtn = document.getElementById('pos-btn-pay');
        const pendBtn = document.getElementById('pos-btn-pending');

        if (itemsEl) itemsEl.textContent = `${this.cart.length} registro(s)`;
        if (kgEl) kgEl.textContent = totalKg > 0 ? `${totalKg.toFixed(2)} kg` : '0 kg';
        if (totalEl) totalEl.textContent = `$${granTotal.toFixed(2)}`;

        const hasItems = this.cart.length > 0;
        if (payBtn) {
            payBtn.disabled = !hasItems;
            payBtn.innerHTML = `
                <svg style="width:22px;height:22px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                <span>CONFIRMAR COBRO $${granTotal.toFixed(2)}</span>
            `;
        }
        if (pendBtn) pendBtn.disabled = !hasItems;
    },

    // ─────────────────────────────────────────────────────────────
    // Clientes
    // ─────────────────────────────────────────────────────────────
    setClient(client) {
        if (client && client.id) {
            this.selectCliente(client.id, client.nombre || '');
        } else {
            this.selectCliente('MOSTRADOR', 'Público General / Mostrador');
        }
        this.closeModal('modal-buscar-cliente');
    },

    // ─────────────────────────────────────────────────────────────
    // Combobox de búsqueda de Cliente (igual que Buscar Producto)
    // ─────────────────────────────────────────────────────────────
    onSearchClienteInput(query) {
        const dropdown = document.getElementById('pos-cliente-dropdown-results');
        const clearBtn = document.getElementById('pos-btn-clear-cliente');
        const q = (query || '').trim();

        if (clearBtn) clearBtn.style.display = q !== '' ? 'flex' : 'none';

        if (q === '') {
            // Al borrar todo, volver a Mostrador
            this.selectCliente('MOSTRADOR', 'Público General / Mostrador');
            if (dropdown) dropdown.style.display = 'none';
            return;
        }
        this._renderClienteDropdown(q);
    },

    onSearchClienteFocus() {
        const inp = document.getElementById('pos-cliente-search-input');
        const q = (inp ? inp.value : '').trim();
        this._renderClienteDropdown(q);
    },

    onSearchClienteKeydown(event) {
        const dropdown = document.getElementById('pos-cliente-dropdown-results');
        if (!dropdown || dropdown.style.display === 'none') return;
        const items = Array.from(dropdown.querySelectorAll('.pos-prod-drop-item'));
        if (!items.length) return;
        const active = dropdown.querySelector('.pos-prod-drop-item.active');
        let idx = items.indexOf(active);
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            idx = (idx + 1) % items.length;
            items.forEach(i => i.classList.remove('active'));
            items[idx].classList.add('active');
            items[idx].scrollIntoView({ block: 'nearest' });
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            idx = (idx - 1 + items.length) % items.length;
            items.forEach(i => i.classList.remove('active'));
            items[idx].classList.add('active');
            items[idx].scrollIntoView({ block: 'nearest' });
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (active) active.click();
        } else if (event.key === 'Escape') {
            dropdown.style.display = 'none';
        }
    },

    _renderClienteDropdown(q) {
        const dropdown = document.getElementById('pos-cliente-dropdown-results');
        if (!dropdown) return;

        // Siempre incluir Mostrador como primera opción
        const mostrador = { id: 'MOSTRADOR', nombre: 'Público General / Mostrador', telefono: '' };
        const pool = [mostrador, ...this.clients];

        const ql = q.toLowerCase();
        const matches = q
            ? pool.filter(c => c.nombre.toLowerCase().includes(ql) || (c.telefono && c.telefono.includes(ql))).slice(0, 15)
            : pool.slice(0, 15);

        if (matches.length === 0) {
            dropdown.innerHTML = '<div style="padding:0.75rem; text-align:center; color:#64748b; font-size:0.83rem;">Sin resultados</div>';
        } else {
            dropdown.innerHTML = matches.map(c => {
                const cId = this.escapeHtml(String(c.id || 'MOSTRADOR'));
                const cNombre = this.escapeHtml(c.nombre || '');
                const cTel = c.telefono ? ` <span style="font-size:0.75rem; color:#64748b; margin-left:0.5rem;">📞 ${this.escapeHtml(c.telefono)}</span>` : '';
                return `<div class="pos-prod-drop-item" data-cliente-id="${cId}" data-cliente-nombre="${cNombre}">` +
                    `<span style="font-weight:600;">${cNombre}</span>${cTel}` +
                    `</div>`;
            }).join('');

            // Asociar eventos click
            dropdown.querySelectorAll('.pos-prod-drop-item').forEach(item => {
                item.addEventListener('click', () => {
                    const id = item.getAttribute('data-cliente-id');
                    const nombre = item.getAttribute('data-cliente-nombre');
                    this.selectCliente(id, nombre);
                });
            });
        }
        dropdown.style.display = 'block';
    },

    selectCliente(clienteId, nombre) {
        const inp = document.getElementById('pos-cliente-search-input');
        const hiddenInp = document.getElementById('pos-selected-cliente-id');
        const dropdown = document.getElementById('pos-cliente-dropdown-results');
        const clearBtn = document.getElementById('pos-btn-clear-cliente');

        if (inp) inp.value = nombre || '';
        if (hiddenInp) hiddenInp.value = clienteId || '';
        if (dropdown) dropdown.style.display = 'none';
        // Mostrar botón limpiar solo si no es Mostrador
        if (clearBtn) clearBtn.style.display = (clienteId && clienteId !== 'MOSTRADOR') ? 'flex' : 'none';

        this.onClienteSelectChange(clienteId);
    },

    clearClienteSeleccionado() {
        const inp = document.getElementById('pos-cliente-search-input');
        if (inp) inp.value = '';
        this.selectCliente('MOSTRADOR', 'Público General / Mostrador');
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
                // Añadir a lista local de clientes y repoblar el select
                if (data.cliente) {
                    this.clients.push(data.cliente);
                    this.populateClientesSelect();
                    this.setClient(data.cliente);
                }
                this.closeModal('modal-nuevo-cliente');
                form.reset();
                alert('Cliente registrado y seleccionado para el registro.');
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

        let clienteIdParaVenta = this.selectedClient ? this.selectedClient.id : null;
        if (!clienteIdParaVenta && this.cart.length > 0) {
            const primerItemConCliente = this.cart.find(it => it.cliente_id);
            if (primerItemConCliente) {
                clienteIdParaVenta = primerItemConCliente.cliente_id;
            }
        }

        const payload = {
            action: 'procesar_venta',
            cliente_id: clienteIdParaVenta,
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
                this.closeModal('modal-apertura-caja');
                await this.loadInitialData();
                this.mostrarMensaje({
                    tipo: 'success',
                    titulo: '¡Caja Abierta con Éxito!',
                    mensaje: data.message
                });
            } else {
                this.mostrarMensaje({
                    tipo: 'error',
                    titulo: 'No se pudo abrir la caja',
                    mensaje: data.message || 'Error al abrir caja'
                });
            }
        } catch (e) {
            this.mostrarMensaje({
                tipo: 'error',
                titulo: 'Error de Conexión',
                mensaje: 'Error de conexión al abrir caja'
            });
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

    cerrarCajaConfirm() {
        this.mostrarConfirmacion({
            tipo: 'danger',
            titulo: '¿Cerrar Turno de Caja?',
            mensaje: '¿Estás seguro de que deseas cerrar el turno de caja actual? Se generará el corte y no se podrán registrar más ventas en este turno.',
            btnConfirmar: 'Sí, cerrar caja',
            colorConfirmar: '#ef4444',
            onConfirm: async () => {
                await this.procesarCierreCaja();
            }
        });
    },

    async procesarCierreCaja() {
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
                this.closeModal('modal-corte-caja');
                await this.loadInitialData();
                this.mostrarMensaje({
                    tipo: 'success',
                    titulo: '¡Caja Cerrada con Éxito!',
                    mensaje: `Caja cerrada exitosamente.\nDiferencia final: $${data.diferencia.toFixed(2)}`
                });
            } else {
                this.mostrarMensaje({
                    tipo: 'error',
                    titulo: 'Error al Cerrar Caja',
                    mensaje: data.message || 'Error al cerrar caja'
                });
            }
        } catch (e) {
            this.mostrarMensaje({
                tipo: 'error',
                titulo: 'Error de Conexión',
                mensaje: 'Error de conexión al cerrar caja'
            });
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

    notifCallback: null,

    mostrarMensaje({ tipo = 'success', titulo = 'Aviso', mensaje = '', onConfirm = null }) {
        this.notifCallback = onConfirm;
        const modal = document.getElementById('modal-notificacion-pos');
        const iconWrap = document.getElementById('notif-pos-icon-wrap');
        const iconSvg = document.getElementById('notif-pos-icon-svg');
        const titleEl = document.getElementById('notif-pos-titulo');
        const msgEl = document.getElementById('notif-pos-mensaje');
        const btn = document.getElementById('notif-pos-btn');

        if (!modal) {
            alert(mensaje);
            return;
        }

        if (titleEl) titleEl.textContent = titulo;
        if (msgEl) msgEl.textContent = mensaje;

        if (tipo === 'success') {
            if (iconWrap) iconWrap.style.background = 'rgba(16, 185, 129, 0.15)';
            if (iconSvg) {
                iconSvg.setAttribute('stroke', '#10b981');
                iconSvg.innerHTML = '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline>';
            }
            if (btn) {
                btn.style.background = '#10b981';
                btn.onmouseover = () => btn.style.background = '#059669';
                btn.onmouseout = () => btn.style.background = '#10b981';
            }
        } else if (tipo === 'error') {
            if (iconWrap) iconWrap.style.background = 'rgba(239, 68, 68, 0.15)';
            if (iconSvg) {
                iconSvg.setAttribute('stroke', '#ef4444');
                iconSvg.innerHTML = '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line>';
            }
            if (btn) {
                btn.style.background = '#ef4444';
                btn.onmouseover = () => btn.style.background = '#dc2626';
                btn.onmouseout = () => btn.style.background = '#ef4444';
            }
        } else {
            if (iconWrap) iconWrap.style.background = 'rgba(59, 130, 246, 0.15)';
            if (iconSvg) {
                iconSvg.setAttribute('stroke', '#3b82f6');
                iconSvg.innerHTML = '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line>';
            }
            if (btn) {
                btn.style.background = '#3b82f6';
                btn.onmouseover = () => btn.style.background = '#2563eb';
                btn.onmouseout = () => btn.style.background = '#3b82f6';
            }
        }

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    },

    cerrarMensaje() {
        const modal = document.getElementById('modal-notificacion-pos');
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
        if (typeof this.notifCallback === 'function') {
            const cb = this.notifCallback;
            this.notifCallback = null;
            cb();
        }
    },

    confirmCallback: null,

    mostrarConfirmacion({
        tipo = 'warning',
        titulo = '¿Confirmar acción?',
        mensaje = '',
        btnConfirmar = 'Sí, continuar',
        colorConfirmar = '#ef4444',
        onConfirm = null
    }) {
        this.confirmCallback = onConfirm;
        const modal = document.getElementById('modal-confirmacion-pos');
        const iconWrap = document.getElementById('confirm-pos-icon-wrap');
        const iconSvg = document.getElementById('confirm-pos-icon-svg');
        const titleEl = document.getElementById('confirm-pos-titulo');
        const msgEl = document.getElementById('confirm-pos-mensaje');
        const btnConf = document.getElementById('confirm-pos-btn-si');

        if (!modal) {
            if (confirm(mensaje)) {
                if (typeof onConfirm === 'function') onConfirm();
            }
            return;
        }

        if (titleEl) titleEl.textContent = titulo;
        if (msgEl) msgEl.textContent = mensaje;

        if (tipo === 'warning' || tipo === 'danger') {
            if (iconWrap) iconWrap.style.background = 'rgba(239, 68, 68, 0.15)';
            if (iconSvg) {
                iconSvg.setAttribute('stroke', '#ef4444');
                iconSvg.innerHTML = '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line>';
            }
            if (btnConf) {
                btnConf.textContent = btnConfirmar;
                btnConf.style.background = colorConfirmar || '#ef4444';
                btnConf.onmouseover = () => btnConf.style.background = '#dc2626';
                btnConf.onmouseout = () => btnConf.style.background = colorConfirmar || '#ef4444';
            }
        } else {
            if (iconWrap) iconWrap.style.background = 'rgba(59, 130, 246, 0.15)';
            if (iconSvg) {
                iconSvg.setAttribute('stroke', '#3b82f6');
                iconSvg.innerHTML = '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line>';
            }
            if (btnConf) {
                btnConf.textContent = btnConfirmar;
                btnConf.style.background = colorConfirmar || '#3b82f6';
                btnConf.onmouseover = () => btnConf.style.background = '#2563eb';
                btnConf.onmouseout = () => btnConf.style.background = colorConfirmar || '#3b82f6';
            }
        }

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    },

    cancelarConfirmacion() {
        const modal = document.getElementById('modal-confirmacion-pos');
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
        this.confirmCallback = null;
    },

    ejecutarConfirmacion() {
        const cb = this.confirmCallback;
        this.cancelarConfirmacion();
        if (typeof cb === 'function') {
            cb();
        }
    },

    // ─────────────────────────────────────────────────────────────
    // Eventos y Teclado / Scanner
    // ─────────────────────────────────────────────────────────────
    bindEvents() {
        // Clic fuera del buscador de productos o clientes: cerrar dropdowns
        document.addEventListener('click', (e) => {
            const prodWrapper = document.querySelector('.pos-prod-search-col');
            const prodDropdown = document.getElementById('pos-producto-dropdown-results');
            if (prodDropdown && prodWrapper && !prodWrapper.contains(e.target)) {
                prodDropdown.style.display = 'none';
            }
            // Cerrar dropdown de cliente si click fuera
            const clienteDropdown = document.getElementById('pos-cliente-dropdown-results');
            const clienteWrapper = document.getElementById('pos-cliente-search-input')?.closest('.pos-search-prod-wrapper');
            if (clienteDropdown && clienteWrapper && !clienteWrapper.contains(e.target)) {
                clienteDropdown.style.display = 'none';
            }
        });

        // Atajos de teclado globales
        window.addEventListener('keydown', (e) => {
            // Escape: cerrar modales y dropdown
            if (e.key === 'Escape') {
                const dropdown = document.getElementById('pos-producto-dropdown-results');
                if (dropdown && dropdown.style.display !== 'none') {
                    dropdown.style.display = 'none';
                } else {
                    this.closeAllModals();
                }
            }
            // F2: enfocar buscador de productos / scanner
            if (e.key === 'F2') {
                e.preventDefault();
                const prodInput = document.getElementById('pos-search-producto-input');
                if (prodInput) {
                    prodInput.focus();
                    prodInput.select();
                }
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
