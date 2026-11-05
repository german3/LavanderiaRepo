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
            this.updateLavadoBadge(data.total_lavado_activo || 0);

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
    updateLavadoBadge(count) {
        const badge = document.getElementById('pos-lavado-badge');
        if (badge) {
            badge.textContent = count;
            badge.style.display = count > 0 ? 'inline-block' : 'none';
        }
    },

    async actualizarBadgeLavado() {
        try {
            const res = await fetch('api/pos_api.php?action=cargas_activas');
            const data = await res.json();
            if (data.success && data.cargas) {
                this.updateLavadoBadge(data.cargas.length);
            }
        } catch (e) {
            // Silencioso
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
                const uMed = p.unidad_medida ? ` ${p.unidad_medida}` : '';
                if (stock <= 0) {
                    stockBadge = `<span class="pos-prod-stock out">Agotado (0${uMed})</span>`;
                } else if (stock <= stockMin) {
                    stockBadge = `<span class="pos-prod-stock low">Stock Bajo (${stock}${uMed})</span>`;
                } else {
                    stockBadge = `<span class="pos-prod-stock">Stock: ${stock}${uMed}</span>`;
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

            const currentSelectedProdId = document.getElementById('pos-selected-producto-id')?.value;

            // Kit seleccionado: aplica en categoría KIT y en ALL
            const isKitSelected = (p.tipo === 'KIT' && this.selectedKit && this.selectedKit.id === p.id);

            // Insumo seleccionado
            const isInsumoSelected = (p.tipo === 'INSUMO' && (
                (this.selectedInsumo && this.selectedInsumo.id === p.id) ||
                (currentSelectedProdId && currentSelectedProdId === p.id)
            ));

            // Producto normal seleccionado
            const isNormalSelected = (p.tipo === 'NORMAL' && currentSelectedProdId && currentSelectedProdId === p.id);

            // Servicio seleccionado
            const isServicioSelected = (p.tipo === 'SERVICIO' && currentSelectedProdId && currentSelectedProdId === p.id);

            let cardSelectedClass = '';
            let selectedBadge = '';
            if (isKitSelected) {
                cardSelectedClass = 'pos-kit-selected';
                selectedBadge = '<span style="font-size:0.65rem; font-weight:700; color:#c084fc; background:rgba(168,85,247,0.25); padding:0.1rem 0.45rem; border-radius:4px;">✓ SELECCIONADO</span>';
            } else if (isInsumoSelected) {
                cardSelectedClass = 'pos-insumo-selected';
                selectedBadge = '<span style="font-size:0.65rem; font-weight:700; color:#fbbf24; background:rgba(245,158,11,0.25); border:1px solid rgba(245,158,11,0.4); padding:0.1rem 0.45rem; border-radius:4px;">✓ SELECCIONADO</span>';
            } else if (isNormalSelected) {
                cardSelectedClass = 'pos-normal-selected';
                selectedBadge = '<span style="font-size:0.65rem; font-weight:700; color:#22c55e; background:rgba(34,197,94,0.20); border:1px solid rgba(34,197,94,0.4); padding:0.1rem 0.45rem; border-radius:4px;">✓ SELECCIONADO</span>';
            } else if (isServicioSelected) {
                cardSelectedClass = 'pos-servicio-selected';
                selectedBadge = '<span style="font-size:0.65rem; font-weight:700; color:#38bdf8; background:rgba(56,189,248,0.20); border:1px solid rgba(56,189,248,0.4); padding:0.1rem 0.45rem; border-radius:4px;">✓ SELECCIONADO</span>';
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
            this.actualizarCampoCantidad(null);
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

    // ─────────────────────────────────────────────────────────────
    // Gestión Dinámica de Unidades para Insumos (Volumen / Peso / Pieza)
    // ─────────────────────────────────────────────────────────────
    actualizarCampoCantidad(prod) {
        const label = document.getElementById('pos-label-cantidad');
        const selectUnidad = document.getElementById('pos-select-unidad-insumo');
        const inputKg = document.getElementById('pos-input-kilos');
        const grid = document.getElementById('pos-entry-grid');

        if (!prod) {
            if (grid) grid.classList.remove('has-unit-select');
            if (label) label.textContent = '3. Cantidad de Kilos';
            if (selectUnidad) {
                selectUnidad.style.display = 'none';
                selectUnidad.innerHTML = '';
            }
            if (inputKg) {
                inputKg.step = '0.05';
                inputKg.min = '0.01';
                inputKg.placeholder = 'Ej. 4.5';
                inputKg.value = '1.0';
            }
            return;
        }

        const uMed = (prod.unidad_medida || '').toUpperCase().trim();

        // 1. Caso Conteo / Pieza (ej. "PIEZA", "PIEZAS", "PZA", "PZ")
        if (uMed === 'PIEZA' || uMed === 'PIEZAS' || uMed === 'PZA' || uMed === 'PZ') {
            if (grid) grid.classList.remove('has-unit-select');
            if (label) label.textContent = '3. Cantidad de Piezas';
            if (selectUnidad) {
                selectUnidad.style.display = 'none';
                selectUnidad.innerHTML = '';
            }
            if (inputKg) {
                inputKg.step = '1';
                inputKg.min = '1';
                inputKg.placeholder = 'Ej. 1';
                inputKg.value = '1';
            }
        }
        // 2. Caso Líquidos / Volumen: Litros, Mililitros, Galones
        else if (uMed === 'LITRO' || uMed === 'LITROS' || uMed === 'L' || uMed === 'ML' || uMed === 'MILILITRO' || uMed === 'GALON' || uMed === 'GAL') {
            // El título debe decir '3. Cantidad' solamente y al lado derecho selector de unidades
            if (label) label.textContent = '3. Cantidad';
            if (selectUnidad) {
                selectUnidad.innerHTML = `
                    <option value="LITRO">Litros</option>
                    <option value="ML">Mililitros</option>
                    <option value="GALON">Galones</option>
                `;
                // Por defecto Litros si la unidad base es Litro o Galón; Mililitros si es ML
                if (uMed === 'ML' || uMed === 'MILILITRO') {
                    selectUnidad.value = 'ML';
                } else if (uMed === 'GALON' || uMed === 'GAL') {
                    selectUnidad.value = 'GALON';
                } else {
                    selectUnidad.value = 'LITRO';
                }
                selectUnidad.style.display = 'block';
                if (grid) grid.classList.add('has-unit-select');
            }
            if (inputKg) {
                if (selectUnidad && selectUnidad.value === 'ML') {
                    inputKg.step = '10';
                    inputKg.min = '1';
                    inputKg.placeholder = 'Ej. 250';
                    inputKg.value = '250';
                } else {
                    inputKg.step = '0.1';
                    inputKg.min = '0.01';
                    inputKg.placeholder = 'Ej. 1.0';
                    inputKg.value = '1.0';
                }
            }
        }
        // 3. Caso Peso / Masa: Kilogramos, Gramos
        else if (uMed === 'KG' || uMed === 'KILO' || uMed === 'KILOGRAMO' || uMed === 'KILOGRAMOS' || uMed === 'GR' || uMed === 'G' || uMed === 'GRAMO' || uMed === 'GRAMOS') {
            if (prod.tipo === 'INSUMO') {
                if (label) label.textContent = '3. Cantidad';
                if (selectUnidad) {
                    selectUnidad.innerHTML = `
                        <option value="GR">Gramos</option>
                        <option value="KG">Kilogramos</option>
                    `;
                    selectUnidad.value = (uMed === 'GR' || uMed === 'G' || uMed === 'GRAMO' || uMed === 'GRAMOS') ? 'GR' : 'KG';
                    selectUnidad.style.display = 'block';
                    if (grid) grid.classList.add('has-unit-select');
                }
                if (inputKg) {
                    if (selectUnidad && selectUnidad.value === 'GR') {
                        inputKg.step = '10';
                        inputKg.min = '1';
                        inputKg.placeholder = 'Ej. 250';
                        inputKg.value = '250';
                    } else {
                        inputKg.step = '0.1';
                        inputKg.min = '0.05';
                        inputKg.placeholder = 'Ej. 1.0';
                        inputKg.value = '1.0';
                    }
                }
            } else {
                if (grid) grid.classList.remove('has-unit-select');
                if (label) label.textContent = '3. Cantidad de Kilos';
                if (selectUnidad) {
                    selectUnidad.style.display = 'none';
                    selectUnidad.innerHTML = '';
                }
                if (inputKg) {
                    inputKg.step = '0.05';
                    inputKg.min = '0.01';
                    inputKg.placeholder = 'Ej. 1.0';
                    inputKg.value = prod.ropa_kg > 0 ? parseFloat(prod.ropa_kg).toFixed(1) : '1.0';
                }
            }
        }
        // 4. Servicios o cualquier otra unidad
        else {
            if (grid) grid.classList.remove('has-unit-select');
            if (label) {
                label.textContent = uMed ? `3. Cantidad de ${prod.unidad_medida}` : '3. Cantidad de Kilos';
            }
            if (selectUnidad) {
                selectUnidad.style.display = 'none';
                selectUnidad.innerHTML = '';
            }
            if (inputKg) {
                inputKg.step = '1';
                inputKg.min = '1';
                inputKg.placeholder = 'Ej. 1';
                inputKg.value = '1';
            }
        }
    },

    onUnidadInsumoChange() {
        const selectUnidad = document.getElementById('pos-select-unidad-insumo');
        const inputKg = document.getElementById('pos-input-kilos');
        if (!selectUnidad || !inputKg) return;

        const val = selectUnidad.value;
        const currentNum = parseFloat(inputKg.value) || 0;

        if (val === 'LITRO' || val === 'KG' || val === 'GALON') {
            if (currentNum >= 50) {
                inputKg.value = '1.0';
            }
            inputKg.step = (val === 'GALON' || val === 'LITRO' || val === 'KG') ? '0.1' : '1';
            inputKg.placeholder = 'Ej. 1.0';
        } else if (val === 'ML' || val === 'GR') {
            if (currentNum <= 5) {
                inputKg.value = '250';
            }
            inputKg.step = '10';
            inputKg.placeholder = 'Ej. 250';
        }

        this.calcPreviewCosto();
        inputKg.focus();
        inputKg.select();
    },

    obtenerFactorConversion(uBase, uSeleccionada) {
        const base = (uBase || '').toUpperCase().trim();
        const sel = (uSeleccionada || '').toUpperCase().trim();

        if (!sel || base === sel) return { factor: 1, textoUnidad: base };

        // Conversiones de volumen
        if (base === 'LITRO' || base === 'L' || base === 'LITROS') {
            if (sel === 'ML') return { factor: 0.001, textoUnidad: 'ml' };
            if (sel === 'LITRO' || sel === 'L') return { factor: 1, textoUnidad: 'L' };
            if (sel === 'GALON' || sel === 'GAL') return { factor: 3.78541, textoUnidad: 'gal' };
        }
        if (base === 'ML' || base === 'MILILITRO' || base === 'MILILITROS') {
            if (sel === 'ML') return { factor: 1, textoUnidad: 'ml' };
            if (sel === 'LITRO' || sel === 'L') return { factor: 1000, textoUnidad: 'L' };
            if (sel === 'GALON' || sel === 'GAL') return { factor: 3785.41, textoUnidad: 'gal' };
        }
        if (base === 'GALON' || base === 'GAL' || base === 'GALONES') {
            if (sel === 'GALON') return { factor: 1, textoUnidad: 'gal' };
            if (sel === 'LITRO') return { factor: 1 / 3.78541, textoUnidad: 'L' };
            if (sel === 'ML') return { factor: 0.001 / 3.78541, textoUnidad: 'ml' };
        }

        // Conversiones de peso / masa
        if (base === 'KG' || base === 'KILO' || base === 'KILOGRAMO' || base === 'KILOGRAMOS') {
            if (sel === 'GR' || sel === 'G' || sel === 'GRAMOS') return { factor: 0.001, textoUnidad: 'g' };
            if (sel === 'KG') return { factor: 1, textoUnidad: 'kg' };
        }
        if (base === 'GR' || base === 'G' || base === 'GRAMOS') {
            if (sel === 'GR' || sel === 'G') return { factor: 1, textoUnidad: 'g' };
            if (sel === 'KG') return { factor: 1000, textoUnidad: 'kg' };
        }

        return { factor: 1, textoUnidad: sel || base };
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
        this.actualizarCampoCantidad(prod);
        if (inputSearch) inputSearch.value = `${prod.descripcion} ($${parseFloat(prod.precio_venta).toFixed(2)})`;
        if (clearBtn) clearBtn.style.display = 'flex';
        if (dropdown) dropdown.style.display = 'none';

        // Si el producto tiene ropa_kg asignado explícito, sugerir sus kilos
        if (inputKg && prod.ropa_kg > 0) {
            const uMedBase = (prod.unidad_medida || '').toUpperCase().trim();
            const esPieza = (uMedBase === 'PIEZA' || uMedBase === 'PIEZAS' || uMedBase === 'PZA' || uMedBase === 'PZ');
            if (!esPieza) {
                inputKg.value = parseFloat(prod.ropa_kg).toFixed(1);
            }
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
        this.actualizarCampoCantidad(null);
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
        const selectUnidad = document.getElementById('pos-select-unidad-insumo');
        const isVisible = selectUnidad && selectUnidad.style.display !== 'none';
        const uSel = isVisible ? selectUnidad.value : '';

        const inputHidden = document.getElementById('pos-selected-producto-id');
        const prodId = inputHidden ? inputHidden.value : '';
        const prod = prodId ? this.products.find(p => p.id === prodId) : null;
        const uMedProd = (prod?.unidad_medida || '').toUpperCase().trim();
        const esPieza = (uMedProd === 'PIEZA' || uMedProd === 'PIEZAS' || uMedProd === 'PZA' || uMedProd === 'PZ');

        let step = delta;
        if (uSel === 'ML' || uSel === 'GR') {
            step = delta > 0 ? 50 : -50;
        } else if (esPieza || uSel === 'PIEZA') {
            step = delta > 0 ? 1 : -1;
        }

        let val = (parseFloat(inputKg.value) || 0) + step;
        if (val < 0.01) val = (uSel === 'ML' || uSel === 'GR') ? 10 : (esPieza ? 1 : 0.05);
        inputKg.value = (uSel === 'ML' || uSel === 'GR' || esPieza) ? Math.round(val) : val.toFixed(2);
        this.calcPreviewCosto();
    },

    calcPreviewCosto() {
        const inputKg = document.getElementById('pos-input-kilos');
        const previewEl = document.getElementById('pos-entry-preview');
        if (!previewEl) return;

        const kilos = inputKg ? (parseFloat(inputKg.value) || 0) : 0;

        if (this.selectedKit || this.currentCategory === 'KIT') {
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

        // Cálculo con conversión de unidades (para Insumos o Productos Líquidos/Litros con selector visible)
        const selectUnidad = document.getElementById('pos-select-unidad-insumo');
        const isSelectVisible = selectUnidad && selectUnidad.style.display !== 'none';

        if (isSelectVisible || prod.tipo === 'INSUMO') {
            const uSel = isSelectVisible ? selectUnidad.value : prod.unidad_medida;
            const { factor, textoUnidad } = this.obtenerFactorConversion(prod.unidad_medida, uSel);
            const cantIngresada = parseFloat(inputKg ? inputKg.value : 0) || 0;
            const cantBase = cantIngresada * factor;
            const precioUnit = parseFloat(prod.precio_venta) || 0;
            const subtotal = +(cantBase * precioUnit).toFixed(2);

            previewEl.textContent = `Subtotal: $` + subtotal.toFixed(2) + ` (` + cantIngresada + ` ` + textoUnidad + ` x $` + precioUnit.toFixed(2) + `/` + (prod.unidad_medida || 'u') + `)`;
            previewEl.style.background = 'rgba(245, 158, 11, 0.18)';
            previewEl.style.color = '#fbbf24';
            previewEl.style.borderColor = 'rgba(245, 158, 11, 0.35)';
            return;
        }

        const precio = parseFloat(prod.precio_venta) || 0;
        const subtotal = (kilos > 0 ? kilos : 1) * precio;
        const uMedProd = (prod.unidad_medida || '').toUpperCase().trim();
        const esPieza = (uMedProd === 'PIEZA' || uMedProd === 'PIEZAS' || uMedProd === 'PZA' || uMedProd === 'PZ');
        const unidadTexto = esPieza ? 'pza' : (prod.tipo === 'SERVICIO' ? 'kg' : (prod.unidad_medida || 'kg').toLowerCase());
        const cantTexto = esPieza ? Math.round(kilos) : kilos.toFixed(2);

        previewEl.textContent = `Subtotal: $${subtotal.toFixed(2)} (${cantTexto} ${unidadTexto} x $${precio.toFixed(2)})`;
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

        // Manejo especial cuando estamos en un Kit (pestaña Kits o Kit seleccionado en Todos)
        if (this.selectedKit || this.currentCategory === 'KIT') {
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

        // Manejo para productos con selección de unidades (Litros, Insumos, etc.)
        const selectUnidad = document.getElementById('pos-select-unidad-insumo');
        const isSelectVisible = selectUnidad && selectUnidad.style.display !== 'none';

        if (isSelectVisible || prod.tipo === 'INSUMO') {
            const uSel = isSelectVisible ? selectUnidad.value : prod.unidad_medida;
            const { factor, textoUnidad } = this.obtenerFactorConversion(prod.unidad_medida, uSel);
            const cantIngresada = parseFloat(inputKg ? inputKg.value : 0);

            if (isNaN(cantIngresada) || cantIngresada <= 0) {
                alert('Por favor ingresa una cantidad válida mayor a 0 en el campo de cantidad.');
                inputKg?.focus();
                return;
            }

            const cantEnBase = +(cantIngresada * factor).toFixed(4);
            const precioUnitario = parseFloat(prod.precio_venta) || 0;
            const subtotalCalculado = +(cantEnBase * precioUnitario).toFixed(2);

            const descVisible = (isSelectVisible && uSel !== prod.unidad_medida)
                ? `${prod.descripcion} (${cantIngresada} ${textoUnidad})`
                : (isSelectVisible ? `${prod.descripcion} (${cantIngresada} ${textoUnidad})` : prod.descripcion);

            const nuevoRegistro = {
                id_registro: Date.now() + Math.random().toString(36).substr(2, 5),
                producto_id: prod.id,
                descripcion: descVisible,
                tipo: prod.tipo,
                unidad_medida: prod.unidad_medida, // Unidad base en inventario
                unidad_seleccionada: uSel,
                unidad_etiqueta: textoUnidad,
                factor_conversion: factor,
                cantidad_ingresada: cantIngresada, // Cantidad en la unidad elegida (ej. 1, 250)
                precio_unitario: precioUnitario,
                cantidad: cantEnBase, // Descuenta la cantidad proporcional exacta en unidad base de inventario
                ropa_kg: 0,
                subtotal: subtotalCalculado,
                cliente_id: clienteObj ? clienteObj.id : null,
                cliente_nombre: clienteObj ? clienteObj.nombre : 'Público General',
                cliente_telefono: clienteObj ? (clienteObj.telefono || '') : '',
                notas: `${cantIngresada} ${textoUnidad} de ${prod.descripcion}`
            };

            this.cart.push(nuevoRegistro);
            this.renderCart();
            this.playBeep();

            this.clearProductoSeleccionado(false);
            if (inputKg) inputKg.value = '1.0';
            inputSearch?.focus();
            return;
        }

        const precioUnitario = parseFloat(prod.precio_venta) || 0;
        const subtotalCalculado = +(kilos * precioUnitario).toFixed(2);

        const uMedProd = (prod.unidad_medida || '').toUpperCase().trim();
        const esPiezaProd = (uMedProd === 'PIEZA' || uMedProd === 'PIEZAS' || uMedProd === 'PZA' || uMedProd === 'PZ');

        // Crear registro asociado al cliente seleccionado
        const nuevoRegistro = {
            id_registro: Date.now() + Math.random().toString(36).substr(2, 5),
            producto_id: prod.id,
            descripcion: prod.descripcion,
            tipo: prod.tipo,
            unidad_medida: prod.unidad_medida,
            unidad_seleccionada: prod.unidad_medida,
            unidad_etiqueta: esPiezaProd ? 'pza' : (prod.tipo === 'SERVICIO' ? 'kg' : (prod.unidad_medida || 'u').toLowerCase()),
            factor_conversion: 1,
            cantidad_ingresada: kilos,
            precio_unitario: precioUnitario,
            cantidad: kilos,
            ropa_kg: (prod.tipo === 'SERVICIO' || (prod.tipo === 'NORMAL' && !esPiezaProd && prod.ropa_kg > 0)) ? kilos : 0,
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

            // Ocultar campo "2. PRODUCTO O SERVICIO" (igual que en pestaña Kits y Paquetes)
            const prodCol = document.getElementById('pos-field-producto-col') || document.querySelector('.pos-prod-search-col');
            const entryGrid = document.getElementById('pos-entry-grid') || document.querySelector('.pos-entry-grid');
            if (prodCol) prodCol.classList.add('pos-hidden');
            if (entryGrid) entryGrid.classList.add('hide-producto');
            this.clearProductoSeleccionado(false);

            this.renderProducts();

            const titleText = document.getElementById('pos-entry-title-text');
            if (titleText) {
                titleText.textContent = `Kits y Paquetes: ${prod.descripcion}`;
            }

            const inputKg = document.getElementById('pos-input-kilos');
            if (inputKg) {
                // Siempre poner los kilos asignados al kit seleccionado
                if (prod.ropa_kg > 0) {
                    inputKg.value = parseFloat(prod.ropa_kg).toFixed(1);
                } else {
                    inputKg.value = '1';
                }
                inputKg.focus();
                inputKg.select();
            }
            this.calcPreviewCosto();
            return;
        }

        // Para servicios o productos normales: restaurar campo "2. PRODUCTO O SERVICIO"
        // si estaba oculto por un kit seleccionado anteriormente en la pestaña Todos
        if (this.currentCategory === 'ALL' && this.selectedKit) {
            this.selectedKit = null;
            const prodCol = document.getElementById('pos-field-producto-col') || document.querySelector('.pos-prod-search-col');
            const entryGrid = document.getElementById('pos-entry-grid') || document.querySelector('.pos-entry-grid');
            if (prodCol) prodCol.classList.remove('pos-hidden');
            if (entryGrid) entryGrid.classList.remove('hide-producto');
            const titleText = document.getElementById('pos-entry-title-text');
            if (titleText) titleText.textContent = 'Registro de Servicio / Venta por Kilos';
        }

        // Seleccionar el producto en el buscador superior para capturar cantidad
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
        } else if (it.factor_conversion && it.cantidad_ingresada !== undefined) {
            it.cantidad_ingresada += delta;
            if (it.cantidad_ingresada <= 0) {
                this.removeFromCart(index);
                return;
            }
            it.cantidad = +(it.cantidad_ingresada * it.factor_conversion).toFixed(4);
            it.subtotal = +(it.cantidad * it.precio_unitario).toFixed(2);
        } else {
            it.cantidad += delta;
            if (it.cantidad <= 0) {
                this.removeFromCart(index);
                return;
            }
            it.cantidad_ingresada = it.cantidad;
            if (it.ropa_kg > 0) it.ropa_kg = it.cantidad;
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
        if (it.tipo === 'KIT') {
            it.cantidad = val;
            it.subtotal = +(val * it.precio_unitario).toFixed(2);
        } else if (it.factor_conversion && it.cantidad_ingresada !== undefined) {
            it.cantidad_ingresada = val;
            it.cantidad = +(val * it.factor_conversion).toFixed(4);
            it.subtotal = +(it.cantidad * it.precio_unitario).toFixed(2);
        } else {
            it.cantidad = val;
            it.cantidad_ingresada = val;
            if (it.ropa_kg > 0) it.ropa_kg = val;
            it.subtotal = +(val * it.precio_unitario).toFixed(2);
        }
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
        const clientHeaderEl = document.getElementById('pos-cart-client-header');
        const clientNameEl = document.getElementById('pos-cart-client-name');
        const clientTelEl = document.getElementById('pos-cart-client-tel');
        if (!container) return;

        if (this.cart.length === 0) {
            if (clientHeaderEl) clientHeaderEl.style.display = 'none';
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

        if (clientHeaderEl) {
            clientHeaderEl.style.display = 'block';
            const cliNombre = this.cart[0]?.cliente_nombre || 'Público General / Mostrador';
            const cliTel = this.cart[0]?.cliente_telefono || '';
            if (clientNameEl) clientNameEl.textContent = cliNombre;
            if (clientTelEl) {
                clientTelEl.textContent = cliTel ? ` · 📞 ${cliTel}` : '';
            }
        }

        container.innerHTML = this.cart.map((item, idx) => {
            const isKit = item.tipo === 'KIT';

            // Derivar la etiqueta de unidad real según tipo y unidad_medida del item
            let unitLabel = item.unidad_etiqueta;
            let stepVal = '1';
            let deltaVal = 1;

            if (isKit) {
                unitLabel = 'paq';
                stepVal   = '1';
                deltaVal  = 1;
            } else if (!unitLabel) {
                const uRaw = (item.unidad_medida || '').toUpperCase().trim();
                if (uRaw === 'ML' || uRaw === 'MILILITRO' || uRaw === 'MILILITROS') {
                    unitLabel = 'ml';  stepVal = '10';  deltaVal = 50;
                } else if (uRaw === 'LITRO' || uRaw === 'LITROS' || uRaw === 'L') {
                    unitLabel = 'L';   stepVal = '0.1'; deltaVal = 0.5;
                } else if (uRaw === 'GALON' || uRaw === 'GAL' || uRaw === 'GALONES') {
                    unitLabel = 'gal'; stepVal = '0.1'; deltaVal = 0.5;
                } else if (uRaw === 'GR' || uRaw === 'G' || uRaw === 'GRAMO' || uRaw === 'GRAMOS') {
                    unitLabel = 'g';   stepVal = '10';  deltaVal = 50;
                } else if (uRaw === 'KG' || uRaw === 'KILO' || uRaw === 'KILOGRAMO' || uRaw === 'KILOGRAMOS') {
                    unitLabel = 'kg';  stepVal = '0.1'; deltaVal = 0.5;
                } else if (uRaw === 'PIEZA' || uRaw === 'PIEZAS' || uRaw === 'PZA' || uRaw === 'PZ') {
                    unitLabel = 'pza'; stepVal = '1';   deltaVal = 1;
                } else if (item.tipo === 'SERVICIO') {
                    unitLabel = 'kg';  stepVal = '0.1'; deltaVal = 0.5;
                } else {
                    unitLabel = uRaw.toLowerCase() || 'u'; stepVal = '0.1'; deltaVal = 0.5;
                }
            } else {
                if (unitLabel === 'ml' || unitLabel === 'g') {
                    stepVal = '10'; deltaVal = 50;
                } else if (unitLabel === 'pza' || unitLabel === 'paq') {
                    stepVal = '1'; deltaVal = 1;
                } else {
                    stepVal = '0.1'; deltaVal = 0.5;
                }
            }

            // Cantidad mostrada: si tiene cantidad_ingresada explícita usarla
            const displayQty = isKit 
                ? item.cantidad 
                : (item.cantidad_ingresada !== undefined ? item.cantidad_ingresada : parseFloat(item.ropa_kg || item.cantidad || 0));

            const displayQtyFormatted = (unitLabel === 'pza' || unitLabel === 'paq' || unitLabel === 'ml' || unitLabel === 'g')
                ? Math.round(displayQty)
                : parseFloat(displayQty).toFixed(2);

            const unitPricing = isKit 
                ? `$${item.precio_unitario.toFixed(2)} / paq <span style="font-size:0.65rem; color:#a5b4fc;">(${item.ropa_kg} kg)</span>` 
                : `$${item.precio_unitario.toFixed(2)} / ${item.unidad_medida ? item.unidad_medida.toLowerCase() : unitLabel}`;

            return `
                <div class="pos-cart-item">
                    <div class="pos-item-header">
                        <div style="flex:1; overflow:hidden;">
                            <div class="pos-item-name">${this.escapeHtml(item.descripcion)}</div>
                        </div>
                        <button class="pos-item-remove" onclick="POS.removeFromCart(${idx})" title="Eliminar este registro">
                            <svg style="width:16px;height:16px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    </div>
                    <div class="pos-item-controls">
                        <div class="pos-qty-stepper">
                            <button class="pos-qty-btn" onclick="POS.updateQty(${idx}, -${deltaVal})">-</button>
                            <input type="number" step="${stepVal}" min="${isKit ? '1' : '0.1'}" class="pos-qty-input" style="width:58px;" value="${displayQtyFormatted}" onchange="POS.setQty(${idx}, this.value)">
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

        // Sumar kilos solo de Kits, Servicios de lavandería, y elementos en Kilogramos o Gramos
        // NO sumar elementos de unidad Pieza, Litros, etc.
        let tieneElementosConPeso = false;
        let totalKg = 0;

        this.cart.forEach(it => {
            const uMed = (it.unidad_medida || '').toUpperCase().trim();
            const uSel = (it.unidad_seleccionada || '').toUpperCase().trim();

            if (it.tipo === 'KIT') {
                tieneElementosConPeso = true;
                totalKg += parseFloat(it.ropa_kg || 0);
            } else if (it.tipo === 'SERVICIO') {
                tieneElementosConPeso = true;
                totalKg += parseFloat(it.ropa_kg || it.cantidad || 0);
            } else if (uMed === 'KG' || uMed === 'KILO' || uMed === 'KILOGRAMO' || uMed === 'KILOGRAMOS' || uSel === 'KG') {
                tieneElementosConPeso = true;
                totalKg += parseFloat(it.cantidad || 0);
            } else if (uMed === 'GR' || uMed === 'G' || uMed === 'GRAMO' || uMed === 'GRAMOS' || uSel === 'GR') {
                tieneElementosConPeso = true;
                totalKg += parseFloat(it.cantidad || 0);
            }
        });

        const granTotal = this.cart.reduce((acc, it) => acc + parseFloat(it.subtotal || 0), 0);

        const itemsEl = document.getElementById('pos-summary-items');
        const kgEl = document.getElementById('pos-summary-kg');
        const rowKg = document.getElementById('pos-row-summary-kg') || kgEl?.closest('.pos-summary-row');
        const totalEl = document.getElementById('pos-total-display');
        const payBtn = document.getElementById('pos-btn-pay');
        const pendBtn = document.getElementById('pos-btn-pending');

        if (itemsEl) itemsEl.textContent = `${this.cart.length} registro(s)`;

        // Mostrar fila de peso total únicamente cuando hay elementos de tipo Kit, Kilogramos o Gramos
        if (rowKg) {
            if (tieneElementosConPeso && totalKg > 0) {
                rowKg.style.display = 'flex';
                if (kgEl) kgEl.textContent = `${totalKg.toFixed(2)} kg`;
            } else {
                rowKg.style.display = 'none';
                if (kgEl) kgEl.textContent = '0 kg';
            }
        } else if (kgEl) {
            kgEl.textContent = totalKg > 0 ? `${totalKg.toFixed(2)} kg` : '0 kg';
        }

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
        const hiddenInp = document.getElementById('pos-selected-cliente-id');
        const q = (query || '').trim();

        if (clearBtn) clearBtn.style.display = (query !== '') ? 'flex' : 'none';

        if (q === '') {
            if (hiddenInp) hiddenInp.value = '';
            this._renderClienteDropdown('');
            return;
        }

        // Al escribir, deseleccionar cliente previo para forzar nueva selección
        if (hiddenInp) {
            hiddenInp.value = '';
        }

        this._renderClienteDropdown(q);
    },

    onSearchClienteFocus() {
        const inp = document.getElementById('pos-cliente-search-input');
        const clearBtn = document.getElementById('pos-btn-clear-cliente');
        if (inp) {
            inp.select(); // Seleccionar todo el texto para reemplazo inmediato
            if (clearBtn) clearBtn.style.display = inp.value ? 'flex' : 'none';
        }
        // Desplegar siempre los resultados (dropdown tipo combobox)
        this._renderClienteDropdown('');
    },

    onSearchClienteKeydown(event) {
        const dropdown = document.getElementById('pos-cliente-dropdown-results');
        if (!dropdown || dropdown.style.display === 'none') {
            if (event.key === 'ArrowDown') {
                this._renderClienteDropdown('');
            }
            return;
        }
        const items = Array.from(dropdown.querySelectorAll('.pos-prod-dropdown-item'));
        if (!items.length) return;
        const active = dropdown.querySelector('.pos-prod-dropdown-item.active');
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
            if (active) {
                active.click();
            } else if (items.length > 0) {
                items[0].click();
            }
        } else if (event.key === 'Escape') {
            dropdown.style.display = 'none';
        }
    },

    _renderClienteDropdown(q) {
        const dropdown = document.getElementById('pos-cliente-dropdown-results');
        if (!dropdown) return;

        // Siempre incluir Mostrador como primera opción
        const mostrador = { id: 'MOSTRADOR', nombre: 'Público General / Mostrador', telefono: '', direccion: '' };
        const pool = [mostrador, ...this.clients];

        const ql = (q || '').toLowerCase().trim();
        const matches = ql
            ? pool.filter(c => c.nombre.toLowerCase().includes(ql) || (c.telefono && c.telefono.includes(ql))).slice(0, 20)
            : pool.slice(0, 20);

        if (matches.length === 0) {
            dropdown.innerHTML = `
                <div style="padding:1rem; text-align:center; color:var(--pos-text-muted); font-size:0.83rem;">
                    🔍 No se encontraron clientes con "${this.escapeHtml(q)}".
                </div>
            `;
        } else {
            dropdown.innerHTML = matches.map((c, idx) => {
                const cId = this.escapeHtml(String(c.id || 'MOSTRADOR'));
                const cNombre = this.escapeHtml(c.nombre || '');
                const esMostrador = (c.id === 'MOSTRADOR');
                const badgeTipo = esMostrador
                    ? '<span style="font-size:0.65rem; padding:0.1rem 0.4rem; border-radius:4px; background:rgba(99,102,241,0.2); color:#a5b4fc; font-weight:700;">MOSTRADOR</span>'
                    : '<span style="font-size:0.65rem; padding:0.1rem 0.4rem; border-radius:4px; background:rgba(59,130,246,0.2); color:#60a5fa; font-weight:700;">CLIENTE</span>';
                
                const telInfo = c.telefono ? `<span> | 📞 ${this.escapeHtml(c.telefono)}</span>` : '';
                const dirInfo = c.direccion ? `<span> | 📍 ${this.escapeHtml(c.direccion)}</span>` : '';

                return `
                    <div class="pos-prod-dropdown-item ${idx === 0 ? 'active' : ''}" data-cliente-id="${cId}" data-cliente-nombre="${cNombre}">
                        <div class="pos-prod-dropdown-info">
                            <div class="pos-prod-dropdown-title">${cNombre}</div>
                            <div class="pos-prod-dropdown-meta">
                                ${badgeTipo}
                                ${telInfo}
                                ${dirInfo}
                            </div>
                        </div>
                        <div class="pos-prod-dropdown-price" style="font-size:0.75rem; color:#60a5fa; font-weight:700; font-family:inherit;">
                            ${esMostrador ? 'Mostrador' : 'Seleccionar ↵'}
                        </div>
                    </div>
                `;
            }).join('');

            // Asociar eventos click
            dropdown.querySelectorAll('.pos-prod-dropdown-item').forEach(item => {
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
        if (clearBtn) clearBtn.style.display = (inp && inp.value) ? 'flex' : 'none';

        this.onClienteSelectChange(clienteId);
    },

    clearClienteSeleccionado() {
        const inp = document.getElementById('pos-cliente-search-input');
        const hiddenInp = document.getElementById('pos-selected-cliente-id');
        const clearBtn = document.getElementById('pos-btn-clear-cliente');

        if (inp) {
            inp.value = '';
            inp.focus();
        }
        if (hiddenInp) hiddenInp.value = '';
        if (clearBtn) clearBtn.style.display = 'none';

        this.selectedClient = null;
        // Abrir inmediatamente la lista de clientes desplegada para elegir o escribir
        this._renderClienteDropdown('');
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
        const nombre = (formData.get('nombre') || '').trim();
        const telefono = (formData.get('telefono') || '').trim();

        if (!nombre || !telefono) {
            alert('Por favor completa el nombre y teléfono del cliente.');
            return;
        }

        this.mostrarConfirmacion({
            titulo: '¿Registrar Cliente?',
            mensaje: `¿Deseas registrar a <strong>${this.escapeHtml(nombre)}</strong> (${this.escapeHtml(telefono)}) en el sistema?`,
            tipo: 'info',
            btnConfirmar: 'Sí, Guardar',
            colorConfirmar: '#3b82f6',
            onConfirm: async () => {
                try {
                    const res = await fetch('api/pos_api.php?action=create_client', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await res.json();
                    if (data.success) {
                        if (data.cliente) {
                            this.clients.push(data.cliente);
                            this.populateClientesSelect();
                            this.setClient(data.cliente);
                        }
                        this.closeModal('modal-nuevo-cliente');
                        form.reset();
                    } else {
                        alert(data.message || 'Error al registrar cliente');
                    }
                } catch (e) {
                    alert('Error de conexión');
                }
            }
        });
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

        const submitBtn = document.getElementById('pos-pay-confirm-btn');
        if (submitBtn) {
            if (method === 'PENDIENTE') {
                submitBtn.innerHTML = '🕒 Registrar Venta (Pendiente de Pago)';
            } else {
                submitBtn.innerHTML = 'Confirmar Cobro e Imprimir Ticket';
            }
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

        if (isPagoPosterior || metodo === 'PENDIENTE') {
            isPagoPosterior = true;
            metodo = 'PENDIENTE';
            montoRecibido = 0;
            if (!this.selectedClient) {
                const conf = confirm('No has seleccionado un cliente para esta orden pendiente de cobro. ¿Deseas asignarlo para facilitar el reclamo y entrega?');
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

        this.clientePrevioInfo = this.selectedClient ? { ...this.selectedClient } : null;
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
                    if (this.clientePrevioInfo && this.clientePrevioInfo.telefono && !data.ticket.cliente_telefono) {
                        data.ticket.cliente_telefono = this.clientePrevioInfo.telefono;
                    }
                    if (this.clientePrevioInfo && this.clientePrevioInfo.nombre && (!data.ticket.cliente_nombre || data.ticket.cliente_nombre === 'Público General')) {
                        data.ticket.cliente_nombre = this.clientePrevioInfo.nombre;
                    }
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
        this.ultimoTicket = ticket;
        const bodyEl = document.getElementById('pos-ticket-content');
        if (!bodyEl) return;

        // Actualizar estado de botón Enviar Ticket (WhatsApp)
        const btnWsp = document.getElementById('pos-btn-send-whatsapp');
        if (btnWsp) {
            const clienteNombre = (ticket.cliente_nombre || '').trim();
            const esPublicoGeneral = !ticket.cliente_id || 
                                     ticket.cliente_id === 'MOSTRADOR' || 
                                     !clienteNombre || 
                                     clienteNombre.toLowerCase().includes('público general') || 
                                     clienteNombre.toLowerCase().includes('publico general') || 
                                     clienteNombre.toLowerCase().includes('mostrador');
            
            const telLimpio = (ticket.cliente_telefono || '').replace(/\D/g, '');
            const tieneTelefono = telLimpio.length >= 10;

            if (esPublicoGeneral) {
                btnWsp.disabled = true;
                btnWsp.title = "No disponible para Público General";
            } else if (!tieneTelefono) {
                btnWsp.disabled = true;
                btnWsp.title = "El cliente no tiene teléfono registrado";
            } else {
                btnWsp.disabled = false;
                btnWsp.title = `Enviar ticket por WhatsApp al ${ticket.cliente_telefono}`;
            }
        }

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
        const ticketWrapper = document.getElementById('ticket-print-wrapper');
        if (!ticketWrapper) {
            alert('No hay un ticket cargado para imprimir.');
            return;
        }

        // Crear o reutilizar un iframe oculto dedicado exclusivamente para impresión térmica 80mm
        let iframe = document.getElementById('pos-ticket-print-frame');
        if (iframe) {
            iframe.remove();
        }
        iframe = document.createElement('iframe');
        iframe.id = 'pos-ticket-print-frame';
        iframe.style.position = 'fixed';
        iframe.style.right = '0';
        iframe.style.bottom = '0';
        iframe.style.width = '0';
        iframe.style.height = '0';
        iframe.style.border = 'none';
        iframe.style.zIndex = '-9999';
        document.body.appendChild(iframe);

        const folio = (this.ultimoTicket && this.ultimoTicket.folio) ? this.escapeHtml(this.ultimoTicket.folio) : '';
        const ticketHtml = ticketWrapper.outerHTML;

        const doc = iframe.contentWindow.document;
        doc.open();
        doc.write('<!DOCTYPE html>' +
            '<html lang="es">' +
            '<head>' +
            '<meta charset="UTF-8">' +
            '<title>Ticket ' + folio + '</title>' +
            '<style>' +
            '@page { size: 80mm auto; margin: 0mm; } ' +
            '* { box-sizing: border-box; margin: 0; padding: 0; } ' +
            'html, body { width: 80mm; margin: 0 auto; padding: 0; background: #ffffff !important; color: #000000 !important; font-family: "JetBrains Mono", "Courier New", Courier, monospace; font-size: 11px; line-height: 1.35; -webkit-print-color-adjust: exact; print-color-adjust: exact; } ' +
            '.pos-ticket-paper { width: 76mm; max-width: 76mm; margin: 0 auto; padding: 4mm 2mm 8mm 2mm; background: #ffffff !important; color: #000000 !important; box-shadow: none !important; border: none !important; } ' +
            '.pos-ticket-center { text-align: center; } ' +
            '.pos-ticket-divider { border-top: 1px dashed #000000 !important; margin: 6px 0; } ' +
            '.pos-ticket-table { width: 100%; border-collapse: collapse; margin: 4px 0; } ' +
            '.pos-ticket-table th { border-bottom: 1px dashed #000000 !important; padding: 3px 0; font-size: 10px; font-weight: 700; text-align: left; color: #000000 !important; } ' +
            '.pos-ticket-table td { padding: 3px 0; font-size: 10.5px; color: #000000 !important; vertical-align: top; } ' +
            'h2 { font-size: 14px; font-weight: 800; margin-bottom: 2px; color: #000000 !important; } ' +
            'p { margin: 1px 0; font-size: 10px; color: #000000 !important; } ' +
            'strong { color: #000000 !important; font-weight: 700; } ' +
            'div { color: #000000 !important; } ' +
            'span { color: #000000 !important; } ' +
            '</style>' +
            '</head>' +
            '<body>' +
            ticketHtml +
            '</body>' +
            '</html>'
        );
        doc.close();

        // Esperar a que el iframe procese el contenido y llamar a print
        setTimeout(() => {
            try {
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
            } catch (e) {
                console.error('Error al imprimir desde iframe:', e);
                window.print();
            }
        }, 250);
    },
    enviarTicketWhatsapp() {
        if (!this.ultimoTicket) {
            alert('No hay información de ticket disponible.');
            return;
        }

        const ticket = this.ultimoTicket;
        let tel = (ticket.cliente_telefono || '').replace(/\D/g, '');
        if (!tel || tel.length < 10) {
            alert('El cliente no tiene un número telefónico válido registrado.');
            return;
        }

        // Si tiene 10 dígitos (formato estándar de México), prefijar con 52
        if (tel.length === 10) {
            tel = '52' + tel;
        }

        const isPagado = ticket.estado_pago === 'PAGADO';
        const detalles = ticket.detalles || [];

        // Generar líneas de detalle
        const lineasDetalle = detalles.map(d => {
            const cant = parseFloat(d.cantidad || 1);
            const desc = d.descripcion || '';
            const subt = parseFloat(d.subtotal || 0).toFixed(2);
            let extra = '';
            if (d.ropa_kg > 0) extra += ` (${d.ropa_kg} kg)`;
            return `• ${cant}x ${desc}${extra} - $${subt}`;
        }).join('\n');

        // Construir mensaje automático
        let msg = `🧺 *LAVANDERÍA VERA*\n`;
        msg += `✨ *Su Carga de Ropa fue Recibida*\n\n`;
        msg += `📄 *Folio:* ${ticket.folio}\n`;
        if (ticket.numero_carga) {
            msg += `🏷️ *Carga de Lavado:* ${ticket.numero_carga}\n`;
        }
        msg += `📅 *Fecha:* ${ticket.fecha_venta}\n`;
        msg += `👤 *Cliente:* ${ticket.cliente_nombre || 'Cliente'}\n`;
        if (ticket.cajero_nombre) {
            msg += `🧑‍💼 *Atendido por:* ${ticket.cajero_nombre}\n`;
        }
        msg += `\n*Detalle del Servicio:*\n`;
        msg += lineasDetalle ? lineasDetalle : '• Servicios varios';
        msg += `\n\n`;
        msg += `💰 *TOTAL:* $${parseFloat(ticket.total || 0).toFixed(2)}\n`;
        msg += `📌 *Estado:* ${isPagado ? '✅ PAGADO' : '⏳ PENDIENTE DE PAGO'}\n`;
        msg += `💳 *Método:* ${ticket.metodo_pago || 'EFECTIVO'}\n`;
        if (isPagado && ticket.metodo_pago === 'EFECTIVO' && ticket.monto_recibido) {
            msg += `💵 *Recibido:* $${parseFloat(ticket.monto_recibido).toFixed(2)}\n`;
            if (ticket.cambio) {
                msg += `🪙 *Cambio:* $${parseFloat(ticket.cambio).toFixed(2)}\n`;
            }
        }
        msg += `\n¡Gracias por su preferencia!\nConserve este mensaje para recoger sus prendas.`;

        const wspUrl = `https://api.whatsapp.com/send?phone=${tel}&text=${encodeURIComponent(msg)}`;
        window.open(wspUrl, '_blank');
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
                    if (this.clientePrevioInfo && this.clientePrevioInfo.telefono && !data.ticket.cliente_telefono) {
                        data.ticket.cliente_telefono = this.clientePrevioInfo.telefono;
                    }
                    if (this.clientePrevioInfo && this.clientePrevioInfo.nombre && (!data.ticket.cliente_nombre || data.ticket.cliente_nombre === 'Público General')) {
                        data.ticket.cliente_nombre = this.clientePrevioInfo.nombre;
                    }
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
    // ─────────────────────────────────────────────────────────────
    // Panel de Seguimiento de Lavado de Cargas
    // ─────────────────────────────────────────────────────────────
    async abrirModalLavado() {
        this.openModal('modal-lavado');
        await this.cargarPanelLavado();
                        this.actualizarBadgeLavado();
        // Auto-refresh cada 60 segundos mientras el modal está abierto
        clearInterval(this._lavadoTimer);
        this._lavadoTimer = setInterval(() => {
            if (document.getElementById('modal-lavado').classList.contains('active')) {
                this.cargarPanelLavado();
            }
        }, 60000);
    },

    currentLavadoTab: 'ALL',
    filtroLavadoQuery: '',
    cargasEspera: [],
    cargasProceso: [],
    cargasFinalizadasHoy: [],
    cargasEntregadas: [],

    setLavadoTab(tab, btn) {
        this.currentLavadoTab = tab;
        document.querySelectorAll('#pos-lavado-filter-tabs .pos-tab').forEach(b => {
            b.classList.toggle('active', b === btn || b.dataset.lavadoTab === tab);
        });
        this.renderLavadoGrid();
    },

    filtrarCargasLavado(query) {
        this.filtroLavadoQuery = (query || '').trim();
        const clearBtn = document.getElementById('pos-btn-clear-lavado-search');
        if (clearBtn) {
            clearBtn.style.display = this.filtroLavadoQuery !== '' ? 'block' : 'none';
        }

        // Búsqueda general: si el usuario escribe y está en una pestaña específica que no tiene coincidencias,
        // o para ver la búsqueda general, cambiar a 'ALL' para mostrar dónde se encuentra la carga
        if (this.filtroLavadoQuery !== '' && this.currentLavadoTab !== 'ALL') {
            this.currentLavadoTab = 'ALL';
            document.querySelectorAll('#pos-lavado-filter-tabs .pos-tab').forEach(b => {
                b.classList.toggle('active', b.dataset.lavadoTab === 'ALL');
            });
        }

        this.renderLavadoGrid();
    },

    limpiarFiltroLavado() {
        const inp = document.getElementById('pos-search-lavado-input');
        if (inp) inp.value = '';
        this.filtroLavadoQuery = '';
        const clearBtn = document.getElementById('pos-btn-clear-lavado-search');
        if (clearBtn) clearBtn.style.display = 'none';
        this.renderLavadoGrid();
    },

    async cargarPanelLavado() {
        const grid = document.getElementById('pos-lavado-cards-grid');
        const lastUpdate = document.getElementById('pos-lavado-last-update');
        const badge = document.getElementById('pos-lavado-badge');

        if (grid && (!this.cargasEspera || this.cargasEspera.length === 0)) {
            grid.innerHTML = '<div class="pos-lavado-loading" style="grid-column:1/-1;text-align:center;padding:3rem;">Cargando cargas de lavado...</div>';
        }

        try {
            // 1. Cargas activas (RECIBIDA + EN_PROCESO)
            const res = await fetch('api/pos_api.php?action=cargas_activas');
            const data = await res.json();
            if (!data.success) throw new Error(data.message || 'Error al cargar');

            const cargas = data.cargas || [];
            this.cargasEspera  = cargas.filter(c => c.estado === 'RECIBIDA');
            this.cargasProceso = cargas.filter(c => c.estado === 'EN_PROCESO');

            // 2. Cargas finalizadas hoy
            const resHoy = await fetch('api/pos_api.php?action=cargas_finalizadas_hoy');
            const dataHoy = await resHoy.json();
            this.cargasFinalizadasHoy = (dataHoy.success && dataHoy.cargas) ? dataHoy.cargas : [];

            // 3. Cargas entregadas
            const resEnt = await fetch('api/pos_api.php?action=cargas_entregadas');
            const dataEnt = await resEnt.json();
            this.cargasEntregadas = (dataEnt.success && dataEnt.cargas) ? dataEnt.cargas : [];

            // Actualizar badge del header
            const totalActivas = this.cargasEspera.length + this.cargasProceso.length;
            if (badge) {
                badge.textContent = totalActivas;
                badge.style.display = totalActivas > 0 ? 'inline-block' : 'none';
            }

            // Actualizar DropDownLists de selección rápida
            const selectEspera = document.getElementById('pos-select-carga-espera');
            if (selectEspera) {
                selectEspera.innerHTML = '<option value="">-- Cargas en Espera (' + this.cargasEspera.length + ') --</option>' +
                    this.cargasEspera.map(c => `<option value="${c.id}" data-numero="${c.numero_carga}">${c.numero_carga} - ${c.cliente_nombre || 'Público General'} (${parseFloat(c.ropa_kg || 0).toFixed(2)} kg)</option>`).join('');
            }
            const selectProceso = document.getElementById('pos-select-carga-proceso');
            if (selectProceso) {
                selectProceso.innerHTML = '<option value="">-- Cargas en Proceso (' + this.cargasProceso.length + ') --</option>' +
                    this.cargasProceso.map(c => `<option value="${c.id}" data-numero="${c.numero_carga}">${c.numero_carga} - ${c.cliente_nombre || 'Público General'} (${parseFloat(c.ropa_kg || 0).toFixed(2)} kg)</option>`).join('');
            }

            // Renderizar cuadrícula general
            this.renderLavadoGrid();

            if (lastUpdate) {
                const now = new Date().toLocaleTimeString('es-MX', { hour12: false });
                lastUpdate.textContent = `Última actualización: ${now}`;
            }

        } catch (err) {
            console.error(err);
            if (grid) {
                grid.innerHTML = '<div class="pos-lavado-empty" style="grid-column:1/-1;color:#ef4444;text-align:center;padding:3rem;">Error al cargar datos de cargas.</div>';
            }
        }
    },

    renderLavadoGrid() {
        const grid = document.getElementById('pos-lavado-cards-grid');
        if (!grid) return;

        // Normalizador para búsqueda sin importar acentos ni mayúsculas
        const normalizeStr = (str) => (str || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
        const qNorm = normalizeStr(this.filtroLavadoQuery);

        const matchItem = (c) => {
            if (!qNorm) return true;
            const corpus = [
                c.numero_carga,
                c.cliente_nombre,
                c.cliente_telefono,
                c.venta_folio,
                c.observaciones
            ].map(normalizeStr).join(' ');
            return corpus.includes(qNorm);
        };

        // Filtrar cada sección de forma independiente para búsqueda general
        const esperaMatches   = (this.cargasEspera || []).filter(matchItem);
        const procesoMatches  = (this.cargasProceso || []).filter(matchItem);
        const finalizMatches  = (this.cargasFinalizadasHoy || []).filter(matchItem);
        const entregMatches   = (this.cargasEntregadas || []).filter(matchItem);

        // Actualizar los contadores de cada pestaña con los resultados en tiempo real
        const totalMatches = esperaMatches.length + procesoMatches.length + finalizMatches.length + entregMatches.length;
        const elAll = document.getElementById('lavado-tab-count-all');
        const elEsp = document.getElementById('lavado-tab-count-espera');
        const elPro = document.getElementById('lavado-tab-count-proceso');
        const elFin = document.getElementById('lavado-tab-count-finalizado');
        const elEnt = document.getElementById('lavado-tab-count-entregados');
        if (elAll) elAll.textContent = totalMatches;
        if (elEsp) elEsp.textContent = esperaMatches.length;
        if (elPro) elPro.textContent = procesoMatches.length;
        if (elFin) elFin.textContent = finalizMatches.length;
        if (elEnt) elEnt.textContent = entregMatches.length;

        // Si no hay ninguna coincidencia general
        if (totalMatches === 0) {
            const msg = this.filtroLavadoQuery
                ? `No se encontraron cargas con "<strong>${this.escapeHtml(this.filtroLavadoQuery)}</strong>" en ningún estado.`
                : 'No hay cargas registradas en el sistema.';
            grid.innerHTML = `
                <div class="pos-lavado-empty" style="grid-column:1/-1; text-align:center; padding:3.5rem 1rem; color:var(--pos-text-muted);">
                    <svg style="width:50px;height:50px;opacity:0.35;margin-bottom:0.75rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <div style="font-size:1.05rem; font-weight:700;">${msg}</div>
                    <div style="font-size:0.82rem; margin-top:0.4rem; opacity:0.8;">Verifica el número de ticket, folio, cliente o teléfono.</div>
                    ${this.filtroLavadoQuery ? `<button class="pos-btn-clear" style="margin-top:1rem;padding:0.4rem 1rem;" onclick="POS.limpiarFiltroLavado()">Limpiar búsqueda</button>` : ''}
                </div>
            `;
            return;
        }

        const tab = this.currentLavadoTab || 'ALL';
        let html = '';

        // Función auxiliar para renderizar una sección con su cabecera y tarjetas
        const renderSection = (title, tipo, itemsList, cssClass, dotClass, badgeClass) => {
            if (itemsList.length === 0) return '';
            let s = `
                <div class="pos-lavado-section-header ${cssClass}">
                    <div style="display:flex;align-items:center;gap:0.5rem;">
                        <span class="pos-lavado-col-dot ${dotClass}"></span>
                        <span style="font-weight:800;font-size:0.95rem;letter-spacing:0.02em;">${title}</span>
                    </div>
                    <span class="pos-tab-badge ${badgeClass}" style="font-size:0.75rem;padding:2px 8px;">${itemsList.length} ${itemsList.length === 1 ? 'carga' : 'cargas'}</span>
                </div>
            `;
            s += itemsList.map(c => this._renderCargaCardGrande(c, tipo)).join('');
            return s;
        };

        // Si tab es ALL o búsqueda general activa: mostrar todas las secciones que tengan resultados
        if (tab === 'ALL' || tab === 'RECIBIDA') {
            html += renderSection('⏳ En Espera', 'espera', esperaMatches, 'pos-lavado-section-espera', 'pos-lavado-dot-espera', 'badge-espera');
        }
        if (tab === 'ALL' || tab === 'EN_PROCESO') {
            html += renderSection('⚙️ En Proceso', 'proceso', procesoMatches, 'pos-lavado-section-proceso', 'pos-lavado-dot-proceso', 'badge-proceso');
        }
        if (tab === 'ALL' || tab === 'LISTA_CONCLUIDA') {
            html += renderSection('✅ Finalizados Hoy (Listos para Entrega)', 'finalizado', finalizMatches, 'pos-lavado-section-finalizado', 'pos-lavado-dot-finalizado', 'badge-finalizado');
        }
        if (tab === 'ALL' || tab === 'ENTREGADA') {
            html += renderSection('📦 Entregados', 'entregado', entregMatches, 'pos-lavado-section-entregado', 'pos-lavado-dot-entregados', 'badge-entregados');
        }

        // Si la pestaña elegida específicamente no tuvo resultados pero otras sí
        if (html === '') {
            const nombresTab = {
                RECIBIDA: 'En Espera',
                EN_PROCESO: 'En Proceso',
                LISTA_CONCLUIDA: 'Finalizados Hoy',
                ENTREGADA: 'Entregados'
            };
            grid.innerHTML = `
                <div class="pos-lavado-empty" style="grid-column:1/-1; text-align:center; padding:3rem 1rem; color:var(--pos-text-muted);">
                    <div style="font-size:1rem; font-weight:700;">No hay coincidencias en la sección <em>${nombresTab[tab] || tab}</em>.</div>
                    <div style="font-size:0.82rem; margin-top:0.4rem;">Se encontraron <strong>${totalMatches}</strong> coincidencias en otras categorías.</div>
                    <button class="pos-btn-clear" style="margin-top:0.8rem;padding:0.4rem 1rem;" onclick="POS.setLavadoTab('ALL')">Ver todas las coincidencias (${totalMatches})</button>
                </div>
            `;
            return;
        }

        grid.innerHTML = html;
    },

    _renderCargaCardGrande(c, tipo) {
        const clienteNombre = this.escapeHtml(c.cliente_nombre || 'Público General');
        const numCarga = this.escapeHtml(c.numero_carga);
        const kg = parseFloat(c.ropa_kg || 0).toFixed(2);
        const fecha = c.fecha_recepcion ? c.fecha_recepcion.substring(0, 16) : '';
        const fechaInicio = c.fecha_inicio_lavado ? c.fecha_inicio_lavado.substring(0, 16) : null;
        const total = parseFloat(c.total || 0).toFixed(2);
        const isPagado = c.estado_pago === 'PAGADO';

        // Badge de Estado de Lavado
        const statusMap = {
            espera: '<span class="pos-lavado-status-pill pos-lavado-status-espera">⏳ En Espera</span>',
            proceso: '<span class="pos-lavado-status-pill pos-lavado-status-proceso">⚙️ En Proceso</span>',
            finalizado: '<span class="pos-lavado-status-pill pos-lavado-status-finalizado">✅ Lista para Entrega</span>',
            entregado: '<span class="pos-lavado-status-pill pos-lavado-status-entregado">📦 Entregada</span>'
        };
        const statusBadge = statusMap[tipo] || '';

        // Badge de Estado de Pago
        const pagoBadge = isPagado
            ? '<span style="color:#10b981;font-weight:700;font-size:0.8rem;">✅ PAGADO</span>'
            : `<div class="pos-lavado-badge-pago-pendiente">💳 PAGO PENDIENTE — $${total}</div>`;

        let accionBtn = '';
        if (tipo === 'espera') {
            accionBtn = `
                <div style="font-size:0.72rem;color:var(--pos-text-muted);margin-top:auto;">Recibida: ${fecha}</div>
                <button type="button" class="pos-lavado-btn-iniciar" style="width:100%;padding:0.65rem 1rem;font-size:0.9rem;" onclick="POS.iniciarLavado('${this.escapeHtml(c.id)}', '${numCarga}')">
                    🚀 Iniciar Lavado
                </button>`;
        } else if (tipo === 'proceso') {
            const tiempoInicio = fechaInicio
                ? `<div style="font-size:0.72rem;color:#94a3b8;margin-bottom:0.25rem;">Iniciado: ${fechaInicio}</div>`
                : '';
            accionBtn = `
                <div style="margin-top:auto;">${tiempoInicio}</div>
                <button type="button" class="pos-lavado-btn-finalizar" style="width:100%;padding:0.65rem 1rem;font-size:0.9rem;" onclick="POS.finalizarLavado('${this.escapeHtml(c.id)}', '${numCarga}')">
                    ✅ Marcar como Finalizado
                </button>`;
        } else if (tipo === 'finalizado') {
            const fechaFin = c.fecha_conclusion ? c.fecha_conclusion.substring(0, 16) : '';
            const telLimpio = (c.cliente_telefono || '').replace(/\D/g, '');
            const cliTrim = (c.cliente_nombre || '').trim().toLowerCase();
            const esPublicoGeneral = !c.cliente_id || c.cliente_id === 'MOSTRADOR' || !cliTrim || cliTrim.includes('público general') || cliTrim.includes('publico general') || cliTrim.includes('mostrador');
            const tieneTelefono = !esPublicoGeneral && telLimpio.length >= 10;

            let btnWspHtml = '';
            if (tieneTelefono) {
                btnWspHtml = `
                    <button type="button" class="pos-lavado-btn-whatsapp" style="width:100%;padding:0.5rem;" onclick="POS.notificarWhatsappCarga('${this.escapeHtml(c.id)}')" title="Enviar WhatsApp al ${this.escapeHtml(c.cliente_telefono)}">
                        <svg style="width:15px;height:15px;fill:currentColor;flex-shrink:0;" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                        <span>Notificar por WhatsApp</span>
                    </button>
                    <div class="pos-lavado-no-tel" style="margin:0.25rem 0;">Sin Teléfono Registrado</div>
                `;
            }

            accionBtn = `
                <div style="font-size:0.72rem;color:#6ee7b7;margin-top:auto;">Lista desde: ${fechaFin}</div>
                ${btnWspHtml}
                <button type="button" class="pos-lavado-btn-entregar" style="width:100%;padding:0.65rem 1rem;font-size:0.9rem;" onclick="POS.entregarCarga('${this.escapeHtml(c.id)}')">
                    📦 Entregar Carga
                </button>
            `;
        } else if (tipo === 'entregado') {
            const fechaEnt = c.fecha_entrega ? c.fecha_entrega.substring(0, 16) : '';
            const ticketFolio = c.venta_folio ? `<div style="font-size:0.75rem;color:#93c5fd;font-weight:700;">Ticket: ${this.escapeHtml(c.venta_folio)}</div>` : '';
            accionBtn = `
                <div style="margin-top:auto;">
                    ${ticketFolio}
                    <div style="font-size:0.72rem;color:#94a3b8;margin-top:0.2rem;">Entregado: ${fechaEnt}</div>
                </div>
                <div class="pos-lavado-badge-entregado" style="text-align:center;padding:0.6rem;font-size:0.85rem;">✓ ENTREGADA</div>
            `;
        }

        return `
        <div class="pos-lavado-card pos-lavado-card-${tipo}">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.5rem;">
                <span class="pos-lavado-numero" style="font-size:1.15rem;font-weight:900;">${numCarga}</span>
                ${statusBadge}
            </div>
            <div style="display:flex;flex-direction:column;gap:0.25rem;">
                <div class="pos-lavado-cliente" style="font-size:0.92rem;font-weight:700;">👤 ${clienteNombre}</div>
                ${c.cliente_telefono ? `<div style="font-size:0.75rem;color:var(--pos-text-muted);">📞 ${this.escapeHtml(c.cliente_telefono)}</div>` : ''}
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:0.35rem 0.6rem;background:rgba(0,0,0,0.15);border-radius:8px;">
                ${kg > 0 ? `<div class="pos-lavado-kg" style="font-size:0.85rem;font-weight:700;">⚖️ ${kg} kg</div>` : '<div></div>'}
                <div class="pos-lavado-pago">${pagoBadge}</div>
            </div>
            ${accionBtn}
        </div>`;
    },
    iniciarLavadoDesdeSelect() {
        const sel = document.getElementById('pos-select-carga-espera');
        if (!sel || !sel.value) {
            alert('Por favor selecciona una carga de la lista en espera.');
            return;
        }
        const opt = sel.options[sel.selectedIndex];
        const num = opt.getAttribute('data-numero') || opt.textContent;
        this.iniciarLavado(sel.value, num);
    },

    finalizarLavadoDesdeSelect() {
        const sel = document.getElementById('pos-select-carga-proceso');
        if (!sel || !sel.value) {
            alert('Por favor selecciona una carga de la lista en proceso.');
            return;
        }
        const opt = sel.options[sel.selectedIndex];
        const num = opt.getAttribute('data-numero') || opt.textContent;
        this.finalizarLavado(sel.value, num);
    },

    iniciarLavado(cargaId, numeroCarga) {
        this.mostrarConfirmacion({
            tipo: 'warning',
            titulo: '🚀 Iniciar Lavado',
            mensaje: `¿Confirmas el inicio del proceso de lavado para la carga ${numeroCarga}?`,
            btnConfirmar: 'Sí, Iniciar Lavado',
            colorConfirmar: '#f59e0b',
            onConfirm: async () => {
                try {
                    const res = await fetch('api/pos_api.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'iniciar_lavado', carga_id: cargaId })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.mostrarNotificacion('success', `✅ Lavado iniciado: ${numeroCarga}`);
                        await this.cargarPanelLavado();
                        this.actualizarBadgeLavado();
                    } else {
                        this.mostrarNotificacion('error', data.message || 'Error al iniciar lavado');
                    }
                } catch (e) {
                    this.mostrarNotificacion('error', 'Error de conexión');
                }
            }
        });
    },

    finalizarLavado(cargaId, numeroCarga) {
        this.mostrarConfirmacion({
            tipo: 'info',
            titulo: '✅ Finalizar Carga',
            mensaje: `¿Confirmas que la carga ${numeroCarga} está lista para entrega?`,
            btnConfirmar: 'Sí, Marcar como Lista',
            colorConfirmar: '#10b981',
            onConfirm: async () => {
                try {
                    const res = await fetch('api/pos_api.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'finalizar_carga', carga_id: cargaId })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.mostrarNotificacion('success', `🎉 Carga lista: ${numeroCarga}`);
                        await this.cargarPanelLavado();
                        this.actualizarBadgeLavado();
                    } else {
                        this.mostrarNotificacion('error', data.message || 'Error al finalizar');
                    }
                } catch (e) {
                    this.mostrarNotificacion('error', 'Error de conexión');
                }
            }
        });
    },

        filtroFinalizados: '',

    filtrarFinalizados(query) {
        this.filtroFinalizados = query || '';
        const clearBtn = document.getElementById('pos-search-finalizados-clear');
        if (clearBtn) {
            clearBtn.style.display = (query || '').trim() !== '' ? 'block' : 'none';
        }
        const colFinalizado = document.getElementById('col-finalizado');
        if (!colFinalizado || !this.cargasFinalizadasHoy) return;

        let visibles = this.cargasFinalizadasHoy;
        if ((query || '').trim() !== '') {
            const q = query.toLowerCase().trim();
            visibles = visibles.filter(c => {
                const cli = (c.cliente_nombre || '').toLowerCase();
                const folio = (c.venta_folio || '').toLowerCase();
                const num = (c.numero_carga || '').toLowerCase();
                return cli.includes(q) || folio.includes(q) || num.includes(q);
            });
        }

        colFinalizado.innerHTML = this._renderCargaCards(visibles, 'finalizado');
        const countEl = document.getElementById('count-finalizado');
        if (countEl) countEl.textContent = visibles.length;
    },

    limpiarFiltroFinalizados() {
        const input = document.getElementById('pos-search-finalizados');
        if (input) input.value = '';
        this.filtrarFinalizados('');
    },

    entregarCarga(cargaId) {
        const carga = (this.cargasFinalizadasHoy || []).find(c => c.id === cargaId);
        if (!carga) {
            alert('No se encontró la información de la carga.');
            return;
        }

        const numeroCarga = carga.numero_carga || 'CRG';
        const clienteNombre = carga.cliente_nombre || 'Público General';
        const isPagado = carga.estado_pago === 'PAGADO';

        if (!isPagado) {
            // Tiene saldo pendiente: abrir modal de cobro para liquidar antes de entregar
            this.abrirModalCobroParaCarga(carga);
            return;
        }

        // Ya está pagada: confirmar entrega directamente
        this.mostrarConfirmacion({
            tipo: 'info',
            titulo: '📦 Entregar Carga de Ropa',
            mensaje: '¿Deseas marcar como <strong>Entregada</strong> la carga <strong>' + this.escapeHtml(numeroCarga) + '</strong> al cliente <strong>' + this.escapeHtml(clienteNombre) + '</strong>?',
            btnConfirmar: 'Sí, Entregar Carga',
            colorConfirmar: '#3b82f6',
            onConfirm: async () => {
                await this._ejecutarEntregaCarga(cargaId, numeroCarga);
            }
        });
    },

    async _ejecutarEntregaCarga(cargaId, numeroCarga) {
        try {
            const res = await fetch('api/pos_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'entregar_carga', carga_id: cargaId })
            });
            const data = await res.json();
            if (data.success) {
                this.mostrarNotificacion('success', 'Carga ' + numeroCarga + ' marcada como entregada.');
                await this.cargarPanelLavado();
                this.actualizarBadgeLavado();
            } else {
                this.mostrarNotificacion('error', data.message || 'Error al entregar carga');
            }
        } catch (e) {
            this.mostrarNotificacion('error', 'Error de conexión');
        }
    },

    // Abre el modal de cobro reutilizable para liquidar una carga pendiente de pago
    // La opción "Pendiente de Pago" queda deshabilitada
    abrirModalCobroParaCarga(carga) {
        this._pendingCarga = carga;
        this._cargaSelectedMethod = 'EFECTIVO';

        const total = parseFloat(carga.total || 0);
        const numeroCarga = carga.numero_carga || 'CRG';

        // Titulo
        const titulo = document.getElementById('modal-cobro-carga-titulo');
        if (titulo) titulo.textContent = '💳 Cobrar y Entregar Carga ' + numeroCarga;

        // Total
        const totalEl = document.getElementById('carga-pay-total');
        if (totalEl) totalEl.textContent = '$' + total.toFixed(2);

        // Seleccionar EFECTIVO
        this.setCargaPayMethod('EFECTIVO');

        // Monto recibido por defecto = total
        const inp = document.getElementById('carga-pay-received');
        if (inp) { inp.value = total.toFixed(2); }
        this.calcCambioCarga();

        // Habilitar botón confirmar
        const btn = document.getElementById('carga-pay-confirm-btn');
        if (btn) btn.disabled = false;

        // Abrir modal (z-index 1100, encima del modal-lavado)
        const modal = document.getElementById('modal-cobro-carga');
        if (modal) {
            modal.classList.add('active');
        }
        setTimeout(() => { if (inp) { inp.focus(); inp.select(); } }, 120);
    },

    cerrarModalCobroCarga() {
        const modal = document.getElementById('modal-cobro-carga');
        if (modal) modal.classList.remove('active');
        this._pendingCarga = null;
        this._cargaSelectedMethod = 'EFECTIVO';
    },

    setCargaPayMethod(method) {
        this._cargaSelectedMethod = method;
        document.querySelectorAll('[data-carga-method]').forEach(opt => {
            opt.classList.toggle('selected', opt.dataset.cargaMethod === method);
        });
        const cashDiv = document.getElementById('carga-pay-cash-details');
        if (cashDiv) cashDiv.style.display = method === 'EFECTIVO' ? 'block' : 'none';
        this.calcCambioCarga();
    },

    setCargaDenom(amount) {
        const carga = this._pendingCarga;
        const total = parseFloat(carga ? carga.total : 0);
        const inp = document.getElementById('carga-pay-received');
        if (!inp) return;
        inp.value = amount === 'exact' ? total.toFixed(2) : parseFloat(amount).toFixed(2);
        this.calcCambioCarga();
    },

    calcCambioCarga() {
        const carga = this._pendingCarga;
        const total = parseFloat(carga ? carga.total : 0);
        const inp = document.getElementById('carga-pay-received');
        const cambioEl = document.getElementById('carga-pay-change');
        const btn = document.getElementById('carga-pay-confirm-btn');

        if (this._cargaSelectedMethod === 'EFECTIVO') {
            const recibido = parseFloat(inp ? inp.value : 0) || 0;
            const cambio = Math.max(0, recibido - total);
            if (cambioEl) cambioEl.textContent = '$' + cambio.toFixed(2);
            if (btn) btn.disabled = recibido < total;
        } else {
            if (cambioEl) cambioEl.textContent = '$0.00';
            if (btn) btn.disabled = false;
        }
    },

    async confirmarCobroCarga() {
        const carga = this._pendingCarga;
        if (!carga) return;

        const total = parseFloat(carga.total || 0);
        const metodo = this._cargaSelectedMethod || 'EFECTIVO';
        const cargaId = carga.id;
        const numeroCarga = carga.numero_carga || 'CRG';
        const ventaId = carga.venta_id;

        let montoRecibido = total;
        if (metodo === 'EFECTIVO') {
            const inp = document.getElementById('carga-pay-received');
            montoRecibido = parseFloat(inp ? inp.value : total) || total;
            if (montoRecibido < total) {
                alert('El monto recibido ($' + montoRecibido.toFixed(2) + ') no puede ser menor al total ($' + total.toFixed(2) + ').');
                return;
            }
        }

        if (!ventaId) {
            this.mostrarNotificacion('error', 'No se encontró el ID de venta para esta carga.');
            return;
        }

        const btn = document.getElementById('carga-pay-confirm-btn');
        if (btn) { btn.disabled = true; btn.innerHTML = 'Procesando...'; }

        try {
            // 1. Liquidar venta
            const resCobro = await fetch('api/pos_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'pagar_pendiente',
                    venta_id: ventaId,
                    metodo_pago: metodo,
                    monto_recibido: montoRecibido
                })
            });
            const dataCobro = await resCobro.json();

            if (!dataCobro.success) {
                alert(dataCobro.message || 'Error al registrar el cobro.');
                if (btn) { btn.disabled = false; btn.innerHTML = '💳 Cobrar y Entregar'; }
                return;
            }

            // 2. Marcar como entregada
            this.cerrarModalCobroCarga();
            await this._ejecutarEntregaCarga(cargaId, numeroCarga);

            // 3. Mostrar ticket
            if (dataCobro.ticket) {
                this.mostrarTicket(dataCobro.ticket);
            }
        } catch (err) {
            this.mostrarNotificacion('error', 'Error de conexión al cobrar la carga.');
            console.error(err);
            if (btn) { btn.disabled = false; btn.innerHTML = '💳 Cobrar y Entregar'; }
        }
    },
    async _procesarCobroYEntrega(cargaId, numeroCarga, total) {
        const metodo = this.selectedPayMethod;
        if (metodo === 'PENDIENTE') {
            this.mostrarNotificacion('error', 'Debes seleccionar un método de pago válido para cobrar.');
            return;
        }

        let montoRecibido = total;
        if (metodo === 'EFECTIVO') {
            montoRecibido = parseFloat(document.getElementById('pos-pay-received').value) || total;
            if (montoRecibido < total) {
                alert('El monto recibido no puede ser menor al total ($' + total.toFixed(2) + ').');
                return;
            }
        }

        // Buscar la carga para obtener el venta_id
        const carga = (this.cargasFinalizadasHoy || []).find(c => c.id === cargaId);
        const ventaId = carga ? carga.venta_id : null;

        if (!ventaId) {
            this.mostrarNotificacion('error', 'No se pudo obtener el ID de venta para esta carga.');
            return;
        }

        try {
            // 1. Liquidar la venta
            const resCobro = await fetch('api/pos_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'pagar_pendiente',
                    venta_id: ventaId,
                    metodo_pago: metodo,
                    monto_recibido: montoRecibido
                })
            });
            const dataCobro = await resCobro.json();
            if (!dataCobro.success) {
                alert(dataCobro.message || 'Error al registrar el cobro.');
                return;
            }

            // 2. Marcar como entregada
            this.closeModal('modal-cobro');
            if (this._cobrarCargaCleanup) this._cobrarCargaCleanup();
            await this._ejecutarEntregaCarga(cargaId, numeroCarga);

            // 3. Mostrar ticket
            if (dataCobro.ticket) {
                this.mostrarTicket(dataCobro.ticket);
            }
        } catch (err) {
            this.mostrarNotificacion('error', 'Error de conexión al cobrar la carga.');
            console.error(err);
        }
    },

    notificarWhatsappCarga(cargaId) {
        const carga = (this.cargasFinalizadasHoy || []).find(c => c.id === cargaId);
        if (!carga) {
            alert('No se encontró la información de la carga.');
            return;
        }

        let tel = (carga.cliente_telefono || '').replace(/\D/g, '');
        if (!tel || tel.length < 10) {
            this.mostrarMensaje({
                tipo: 'warning',
                titulo: 'Sin Teléfono Registrado',
                mensaje: 'Esta carga no tiene un número telefónico registrado para notificaciones.'
            });
            return;
        }

        if (tel.length === 10) {
            tel = '52' + tel;
        }

        const clienteNombre = carga.cliente_nombre || 'Estimado(a) Cliente';
        const numCarga = carga.numero_carga || 'CRG';
        const kg = parseFloat(carga.ropa_kg || 0).toFixed(2);
        const isPagado = carga.estado_pago === 'PAGADO';
        const total = parseFloat(carga.total || 0).toFixed(2);

        // Mensaje automático requerido con la frase solicitada
        let msg = `🧺 *LAVANDERÍA VERA*\n`;
        msg += `✨ *Su Ropa se Termino de Lavar, puede pasar por ella*\n\n`;
        msg += `🏷️ *Carga de Lavado:* ${numCarga}\n`;
        msg += `👤 *Cliente:* ${clienteNombre}\n`;
        if (kg > 0) {
            msg += `⚖️ *Peso:* ${kg} kg de ropa\n`;
        }
        msg += `💰 *Estado:* ${isPagado ? '✅ PAGADO' : `⏳ PENDIENTE DE PAGO ($` + total + `)`}\n\n`;
        msg += `¡Esperamos su visita para recoger sus prendas!\n`;
        msg += `_Tu ropa limpia, fresca y a tiempo._`;

        const wspUrl = `https://api.whatsapp.com/send?phone=${tel}&text=${encodeURIComponent(msg)}`;
        window.open(wspUrl, '_blank');

        // Notificación llamativa requerida
        this.mostrarMensaje({
            tipo: 'success',
            titulo: 'Notificación Enviada',
            mensaje: `El mensaje de WhatsApp ha sido generado para <strong>${this.escapeHtml(clienteNombre)}</strong> (📞 ${this.escapeHtml(carga.cliente_telefono)}) exitosamente.`
        });
    },

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
        // Restaurar estado del modal-cobro si fue usado para cobro de carga
        if (id === 'modal-cobro' && this._cobrarCargaCleanup) {
            this._cobrarCargaCleanup();
        }
    },

    closeAllModals() {
        document.querySelectorAll('.pos-modal-overlay').forEach(m => m.classList.remove('active'));
        this.activeModal = null;
    },

    notifCallback: null,


    mostrarNotificacion(tipo, mensaje, titulo) {
        this.mostrarMensaje({
            tipo: tipo || 'info',
            titulo: titulo || (tipo === 'success' ? 'Éxito' : (tipo === 'error' ? 'Error' : 'Aviso')),
            mensaje: mensaje
        });
    },
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
        if (msgEl) msgEl.innerHTML = mensaje;

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
        if (msgEl) msgEl.innerHTML = mensaje;

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
