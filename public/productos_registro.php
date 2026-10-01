<?php
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../src/modules/auth/AuthService.php';
require_once __DIR__ . '/../src/modules/catalog/Producto.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;
use App\Modules\Catalog\Producto;

$db = (new Database())->getConnection();
$auth = new AuthService($db);
$productoModel = new Producto($db);

if (!$auth->checkAuth()) { header("Location: login.php"); exit; }

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$mensaje = "";
$error   = "";

// Productos NORMAL disponibles para armar Kits (se pasan a JS)
$productosNormales = $productoModel->obtenerProductosNormales();
$productosNormalesJson = json_encode($productosNormales);

// Siguiente SKU a mostrar
$siguienteSku = $productoModel->obtenerSiguienteSku();

// ── Procesar formulario ──────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($_POST['action'] ?? '') == 'crear') {
    if (!$auth->isAdmin()) {
        $error = "Solo los administradores pueden registrar nuevos productos.";
    } else {
        $tipo = $_POST['tipo'];

        // Para Kit, unidad_medida / stock_cantidad / costo fijos
        $unidadMedida  = ($tipo === 'KIT') ? 'PIEZA'  : ($_POST['unidad_medida']  ?? 'PIEZA');
        $stockCantidad = ($tipo === 'KIT') ? 0        : ($_POST['stock_cantidad'] ?? 0);
        $costo         = ($tipo === 'KIT') ? 0        : ($_POST['costo']          ?? 0);
        $precioVenta   = floatval($_POST['precio_venta'] ?? 0);
        $ropaKg        = ($tipo === 'KIT') ? floatval($_POST['ropa_kg'] ?? 0)     : 0;

        $productoModel->descripcion         = trim($_POST['descripcion'] ?? '');
        $productoModel->codigo_barras       = trim($_POST['codigo_barras'] ?? '');
        $productoModel->tipo                = $tipo;
        $productoModel->unidad_medida       = $unidadMedida;
        $productoModel->stock_cantidad      = $stockCantidad;
        $productoModel->ropa_kg             = $ropaKg;
        $productoModel->costo               = $costo;
        $productoModel->precio_venta        = $precioVenta;
        $productoModel->estado              = 'ACTIVO';
        $productoModel->usuario_registro_id = $_SESSION['usuario_id'];

        if ($productoModel->crear()) {
            // Si es Kit, guardar insumos en productos_kits
            if ($tipo === 'KIT') {
                $insumos = [];
                $insumosIds      = $_POST['kit_insumo_id']       ?? [];
                $insumosCant     = $_POST['kit_insumo_cantidad']  ?? [];
                $insumosUnidades = $_POST['kit_insumo_unidad']    ?? [];
                $insumosRopaKg   = $_POST['kit_insumo_ropa_kg']  ?? [];

                foreach ($insumosIds as $i => $insumoId) {
                    if (!empty($insumoId) && isset($insumosCant[$i])) {
                        $insumos[] = [
                            'id'       => $insumoId,
                            'cantidad' => floatval($insumosCant[$i]),
                            'unidad'   => $insumosUnidades[$i] ?? 'PIEZA',
                            'ropa_kg'  => floatval($insumosRopaKg[$i] ?? $ropaKg),
                        ];
                    }
                }
                if (!empty($insumos)) {
                    $productoModel->guardarInsumosKit($productoModel->id, $insumos);
                }
            }
            $skuAsignado  = htmlspecialchars($productoModel->codigo_interno_sku);
            $mensaje = "Producto registrado exitosamente con SKU: <strong>{$skuAsignado}</strong>";
            $siguienteSku = $productoModel->obtenerSiguienteSku();
            // Refrescar lista de normales
            $productosNormales = $productoModel->obtenerProductosNormales();
            $productosNormalesJson = json_encode($productosNormales);
        } else {
            $error = "Error al crear el producto. Revisa los datos.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Producto | Lavandería</title>
    <link rel="stylesheet" href="css/index.css">
    <style>
        /* SKU display */
        .sku-display {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.875rem 1rem;
            background: rgba(99,102,241,0.08);
            border: 1px solid rgba(99,102,241,0.35);
            border-radius: 12px;
            color: #a5b4fc; font-weight: 700; font-size: 1.05rem;
            letter-spacing: 0.05em; cursor: default; user-select: none;
        }
        .sku-badge {
            background: rgba(99,102,241,0.2); border: 1px solid rgba(99,102,241,0.4);
            color: #818cf8; padding: 0.2rem 0.55rem; border-radius: 6px;
            font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em;
        }

        /* Kit section */
        #kit-section {
            display: none;
            margin-top: 1.5rem;
            background: rgba(15,23,42,0.5);
            border: 1px solid rgba(99,102,241,0.2);
            border-radius: 16px;
            padding: 1.5rem 1.75rem;
            animation: slideDown 0.25s ease;
        }
        @keyframes slideDown {
            from { opacity:0; transform:translateY(-8px); }
            to   { opacity:1; transform:translateY(0);    }
        }
        .kit-title {
            font-size: 0.82rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.1em; color: #818cf8; margin-bottom: 1rem;
            display: flex; align-items: center; gap: 0.5rem;
        }

        /* Chips de insumos */
        #kit-chips {
            display: flex; flex-wrap: wrap; gap: 0.6rem;
            min-height: 2.5rem; margin-bottom: 1rem;
        }
        .kit-chip {
            display: inline-flex; align-items: center; gap: 0.5rem;
            background: rgba(139,92,246,0.2);
            border: 1px solid rgba(139,92,246,0.4);
            color: #c4b5fd;
            padding: 0.35rem 0.85rem;
            border-radius: 99px;
            font-size: 0.82rem; font-weight: 600;
            transition: all 0.15s ease;
        }
        .kit-chip .chip-remove {
            cursor: pointer; color: #f87171; font-size: 0.9rem; font-weight: 700;
            line-height: 1; padding: 0 0.1rem;
            transition: color 0.15s;
        }
        .kit-chip .chip-remove:hover { color: #fff; }

        /* Kit add row */
        .kit-add-row {
            display: grid;
            grid-template-columns: auto 1fr 130px 120px;
            gap: 0.75rem;
            align-items: end;
        }
        .btn-kit-add {
            padding: 0.75rem 1.25rem;
            background: transparent;
            border: 2px solid #22c55e;
            color: #22c55e;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.88rem;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .btn-kit-add:hover { background: rgba(34,197,94,0.1); }

        /* Campos deshabilitados */
        .form-control:disabled {
            opacity: 0.35;
            cursor: not-allowed;
            filter: grayscale(0.5);
        }
        select:disabled { opacity: 0.35; cursor: not-allowed; }

        /* Unidad display junto al campo cantidad de kit */
        .kit-unidad-label {
            font-size: 0.78rem;
            color: #94a3b8;
            margin-top: 0.4rem;
            min-height: 1.1rem;
            letter-spacing: 0.04em;
        }
    </style>
</head>
<body>
<div class="app-container">
    <aside class="sidebar">
        <h2 style="font-size:1.25rem;color:#fff;margin-bottom:2rem;">Lavandería App</h2>
        <nav>
            <a href="dashboard.php" style="display:block;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);">Dashboard</a>
            <div class="menu-dropdown">
                <a href="javascript:void(0)" onclick="toggleSubmenu('submenu-productos')" style="display:flex;justify-content:space-between;align-items:center;color:var(--primary);font-weight:600;text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);cursor:pointer;">
                    <span>Productos</span><span style="font-size:0.75rem;">▾</span>
                </a>
                <div id="submenu-productos" class="submenu open">
                    <a href="productos_registro.php" style="color:var(--primary);font-weight:600;">Registro</a>
                    <a href="productos.php">Catálogo</a>
                </div>
            </div>
            <div class="menu-dropdown">
                <a href="javascript:void(0)" onclick="toggleSubmenu('submenu-stock')" style="display:flex;justify-content:space-between;align-items:center;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);cursor:pointer;">
                    <span>Stock</span><span style="font-size:0.75rem;">▾</span>
                </a>
                <div id="submenu-stock" class="submenu">
                    <a href="stock_entradas.php">Entradas</a>
                </div>
            </div>
            <div class="menu-dropdown">
                <a href="javascript:void(0)" onclick="toggleSubmenu('submenu-clientes')" style="display:flex;justify-content:space-between;align-items:center;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);cursor:pointer;">
                    <span>Clientes</span><span style="font-size:0.75rem;">▾</span>
                </a>
                <div id="submenu-clientes" class="submenu">
                    <a href="clientes_registro.php">Registro</a>
                    <a href="clientes.php">Listado</a>
                </div>
            </div>
            <?php if($auth->isAdmin()): ?>
            <div class="menu-dropdown">
                <a href="javascript:void(0)" onclick="toggleSubmenu('submenu-usuarios')" style="display:flex;justify-content:space-between;align-items:center;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);cursor:pointer;">
                    <span>Usuarios y Roles</span><span style="font-size:0.75rem;">▾</span>
                </a>
                <div id="submenu-usuarios" class="submenu">
                    <a href="usuarios_alta.php">Altas</a>
                    <a href="usuarios.php">Listado</a>
                </div>
            </div>
            <?php endif; ?>
            <a href="dashboard.php?logout=1" style="display:block;color:#f87171;text-decoration:none;padding:0.75rem 0;margin-top:2rem;">Cerrar Sesión</a>
        </nav>
    </aside>

    <main class="main-content">
        <header style="margin-bottom:2.5rem;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <h1 style="font-size:2rem;">Registro de Producto</h1>
                <p style="color:var(--text-muted);">Alta de nuevos elementos en el catálogo del sistema.</p>
            </div>
            <a href="productos.php" class="btn-primary" style="text-decoration:none;display:inline-block;width:auto;padding:0.6rem 1.25rem;font-size:0.9rem;">Ver Catálogo</a>
        </header>

        <?php if ($mensaje): ?>
            <div class="alert" style="background:rgba(16,185,129,0.1);color:#34d399;border:1px solid rgba(16,185,129,0.2);padding:1rem;border-radius:12px;margin-bottom:1.5rem;">
                <?= $mensaje ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error" style="margin-bottom:1.5rem;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="auth-card" style="width:100%;max-width:100%;padding:2.5rem;border-radius:20px;">
            <h3 style="margin-bottom:1.75rem;font-size:1.35rem;color:#fff;display:flex;align-items:center;gap:0.75rem;">
                <span style="color:var(--primary);">➕</span> Nuevo Elemento
            </h3>

            <form method="POST" id="form-producto">
                <input type="hidden" name="action" value="crear">
                <!-- Hidden fields de insumos Kit (se llenan por JS) -->
                <div id="kit-hidden-inputs"></div>

                <!-- ── Fila 1: SKU + Descripción + Código de Barras ── -->
                <div style="display:grid;grid-template-columns:180px 1fr 220px;gap:1.25rem;margin-bottom:1.25rem;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label" style="display:flex;align-items:center;gap:0.5rem;">
                            SKU Interno <span class="sku-badge">Auto</span>
                        </label>
                        <div class="sku-display" title="El SKU se asigna automáticamente al registrar">
                            🏷️ <?= htmlspecialchars($siguienteSku) ?>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Descripción *</label>
                        <input type="text" name="descripcion" id="inp-descripcion" class="form-control" required
                               placeholder="Ej: Jabón Líquido / Carga de Ropa Grande">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Código de Barras</label>
                        <input type="text" name="codigo_barras" class="form-control"
                               placeholder="(Auto si se deja vacío)">
                    </div>
                </div>

                <!-- ── Fila 2: Tipo + Unidad + Cantidad + Costo + Precio ── -->
                <div style="display:grid;grid-template-columns:1fr 1fr 160px 160px 160px;gap:1.25rem;margin-bottom:0;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Tipo *</label>
                        <select name="tipo" id="sel-tipo" class="form-control" required style="appearance:none;"
                                onchange="onTipoChange(this.value)">
                            <option value="NORMAL">Normal</option>
                            <option value="SERVICIO">Servicio</option>
                            <option value="KIT">Kit / Paquete</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Unidad de Medida *</label>
                        <select name="unidad_medida" id="sel-unidad" class="form-control" required style="appearance:none;">
                            <option value="PIEZA">Pieza</option>
                            <option value="LITRO">Litro</option>
                            <option value="MILILITRO">Mililitro</option>
                            <option value="KG">Kilogramo</option>
                            <option value="GRAMO">Gramo</option>
                            <option value="METRO">Metro</option>
                            <option value="CARGA">Carga</option>
                            <option value="SERVICIO">Servicio</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Cantidad *</label>
                        <input type="number" step="0.01" min="0" name="stock_cantidad" id="inp-cantidad"
                               class="form-control" value="0" required>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Costo ($) *</label>
                        <input type="number" step="0.01" min="0" name="costo" id="inp-costo"
                               class="form-control" value="0.00" required>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Precio Venta ($) *</label>
                        <input type="number" step="0.01" min="0" name="precio_venta" id="inp-precio"
                               class="form-control" value="0.00" required>
                    </div>
                </div>

                <!-- ══════════════════════════════════════════════════
                     SECCIÓN KIT — se muestra solo cuando tipo = KIT
                ═══════════════════════════════════════════════════ -->
                <div id="kit-section">
                    <div class="kit-title">
                        <span>📦</span> Insumos del Kit
                    </div>

                    <!-- Chips de elementos ya agregados -->
                    <div id="kit-chips"></div>

                    <!-- Fila para agregar insumo -->
                    <div class="kit-add-row">
                        <!-- Botón Agregar -->
                        <button type="button" class="btn-kit-add" onclick="kitAgregar()">
                            + Agregar
                        </button>

                        <!-- Select de productos NORMAL -->
                        <div>
                            <label class="form-label" style="font-size:0.78rem;">Elemento disponible</label>
                            <select id="kit-sel-producto" class="form-control" style="appearance:none;"
                                    onchange="kitOnSelectProducto(this.value)">
                                <option value="">— Seleccionar producto —</option>
                                <?php foreach ($productosNormales as $pn): ?>
                                    <option value="<?= htmlspecialchars($pn['id']) ?>"
                                            data-nombre="<?= htmlspecialchars($pn['descripcion']) ?>"
                                            data-unidad="<?= htmlspecialchars($pn['unidad_medida']) ?>">
                                        <?= htmlspecialchars($pn['descripcion']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Cantidad del insumo -->
                        <div>
                            <label class="form-label" style="font-size:0.78rem;">Cantidad</label>
                            <input type="number" id="kit-inp-cantidad" class="form-control"
                                   step="0.01" min="0" value="1" placeholder="0">
                            <div class="kit-unidad-label" id="kit-unidad-label">—</div>
                        </div>

                        <!-- Ropa en Kg. -->
                        <div>
                            <label class="form-label" style="font-size:0.78rem;">Ropa en Kg.</label>
                            <input type="number" id="kit-inp-ropa-kg" name="ropa_kg" class="form-control"
                                   step="0.01" min="0" value="1" placeholder="0.00">
                        </div>
                    </div>

                    <?php if (empty($productosNormales)): ?>
                        <p style="color:#f87171;font-size:0.82rem;margin-top:1rem;">
                            ⚠️ No hay productos de tipo <strong>Normal</strong> registrados aún.
                            <a href="productos_registro.php" style="color:#93c5fd;">Registra uno primero.</a>
                        </p>
                    <?php endif; ?>
                </div>
                <!-- ── FIN KIT SECTION ── -->

                <!-- Botones -->
                <div style="display:flex;gap:1rem;align-items:center;margin-top:1.75rem;">
                    <button type="submit" class="btn-primary" style="padding:0.95rem 2rem;">
                        Registrar en Catálogo
                    </button>
                    <a href="productos.php" style="padding:0.95rem 1.5rem;border-radius:12px;color:var(--text-muted);text-decoration:none;border:1px solid var(--border);display:inline-flex;align-items:center;justify-content:center;">
                        Ver Catálogo
                    </a>
                    <span style="margin-left:auto;color:var(--text-muted);font-size:0.82rem;">
                        🏷️ Próximo SKU: <strong style="color:#a5b4fc;"><?= htmlspecialchars($siguienteSku) ?></strong>
                    </span>
                </div>
            </form>
        </div>
    </main>
</div>

<script>
// ── Catálogo de productos normales disponibles ────────────────────
const productosNormales = <?= $productosNormalesJson ?>;

// ── Estado interno del Kit ────────────────────────────────────────
let kitInsumos = []; // [{ id, nombre, cantidad, unidad, ropa_kg }]

// ── Toggle Tipo ───────────────────────────────────────────────────
function onTipoChange(tipo) {
    const kitSection   = document.getElementById('kit-section');
    const selUnidad    = document.getElementById('sel-unidad');
    const inpCantidad  = document.getElementById('inp-cantidad');
    const inpCosto     = document.getElementById('inp-costo');
    const inpPrecio    = document.getElementById('inp-precio');

    if (tipo === 'KIT') {
        kitSection.style.display = 'block';
        // Deshabilitar campos que no aplican al Kit (Unidad, Cantidad y Costo directo)
        selUnidad.disabled   = true;
        inpCantidad.disabled = true;
        inpCosto.disabled    = true;
        inpPrecio.disabled   = false;
    } else {
        kitSection.style.display = 'none';
        selUnidad.disabled   = false;
        inpCantidad.disabled = false;
        inpCosto.disabled    = false;
        inpPrecio.disabled   = false;
        kitInsumos = [];
        renderChips();
    }
}

// ── Al seleccionar un producto en el kit, actualizar unidad ───────
function kitOnSelectProducto(id) {
    const labelEl = document.getElementById('kit-unidad-label');
    if (!id) {
        if (labelEl) labelEl.textContent = '—';
        return;
    }
    const prod = productosNormales.find(p => p.id === id);
    if (prod && labelEl) {
        labelEl.textContent = prod.unidad_medida;
    }
}

// ── Agregar insumo al Kit ─────────────────────────────────────────
function kitAgregar() {
    const sel      = document.getElementById('kit-sel-producto');
    const inpCant  = document.getElementById('kit-inp-cantidad');
    const inpRopa  = document.getElementById('kit-inp-ropa-kg');
    const id       = sel.value;
    const cantidad = parseFloat(inpCant.value);
    const ropaKg   = parseFloat(inpRopa ? inpRopa.value : 0) || 0;

    if (!id) {
        alert('Selecciona un producto antes de agregar.');
        return;
    }
    if (isNaN(cantidad) || cantidad <= 0) {
        alert('Ingresa una cantidad válida mayor a 0.');
        return;
    }

    // Evitar duplicados
    if (kitInsumos.find(i => i.id === id)) {
        alert('Este producto ya fue agregado al Kit. Modifica la cantidad en el chip o elimínalo primero.');
        return;
    }

    const prod = productosNormales.find(p => p.id === id);
    kitInsumos.push({
        id:       id,
        nombre:   prod ? prod.descripcion : id,
        cantidad: cantidad,
        unidad:   prod ? prod.unidad_medida : '—',
        ropa_kg:  ropaKg
    });

    renderChips();

    // Limpiar selector
    sel.value = '';
    inpCant.value = 1;
    const labelEl = document.getElementById('kit-unidad-label');
    if (labelEl) labelEl.textContent = '—';
}

// ── Eliminar insumo del Kit ───────────────────────────────────────
function kitEliminar(id) {
    kitInsumos = kitInsumos.filter(i => i.id !== id);
    renderChips();
}

// ── Renderizar chips visuales ─────────────────────────────────────
function renderChips() {
    const container = document.getElementById('kit-chips');
    container.innerHTML = '';

    if (kitInsumos.length === 0) {
        container.innerHTML = '<span style="color:var(--text-muted);font-size:0.82rem;font-style:italic;">Sin insumos agregados aún…</span>';
        return;
    }

    kitInsumos.forEach(ins => {
        const chip = document.createElement('span');
        chip.className = 'kit-chip';
        chip.innerHTML = `
            <span>${escHtml(ins.nombre)}</span>
            <span style="color:#94a3b8;font-size:0.78rem;">${ins.cantidad} ${ins.unidad}</span>
            <span class="chip-remove" onclick="kitEliminar('${escHtml(ins.id)}')" title="Eliminar">✕</span>
        `;
        container.appendChild(chip);
    });
}

// ── Serializar insumos en campos hidden antes de enviar ───────────
document.getElementById('form-producto').addEventListener('submit', function(e) {
    const tipo = document.getElementById('sel-tipo').value;
    const hiddenContainer = document.getElementById('kit-hidden-inputs');
    hiddenContainer.innerHTML = '';

    if (tipo === 'KIT') {
        if (kitInsumos.length === 0) {
            e.preventDefault();
            alert('Un Kit debe tener al menos un insumo agregado.');
            return;
        }
        const ropaKgGlobal = document.getElementById('kit-inp-ropa-kg') ? document.getElementById('kit-inp-ropa-kg').value : 0;
        kitInsumos.forEach((ins, i) => {
            hiddenContainer.innerHTML += `
                <input type="hidden" name="kit_insumo_id[]"       value="${escHtml(ins.id)}">
                <input type="hidden" name="kit_insumo_cantidad[]"  value="${ins.cantidad}">
                <input type="hidden" name="kit_insumo_unidad[]"    value="${escHtml(ins.unidad)}">
                <input type="hidden" name="kit_insumo_ropa_kg[]"   value="${ins.ropa_kg || ropaKgGlobal || 0}">
            `;
        });
    }
});

// ── Utilidades ────────────────────────────────────────────────────
function escHtml(str) {
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function toggleSubmenu(id) {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('open');
}

// Inicializar chips vacíos
renderChips();
</script>
</body>
</html>
