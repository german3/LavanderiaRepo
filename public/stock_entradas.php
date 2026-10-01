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

// Middleware de autenticación
if (!$auth->checkAuth()) {
    header("Location: login.php");
    exit;
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$mensaje = "";
$error = "";

// Obtener productos disponibles para entradas (NORMAL)
$productosNormales = $productoModel->obtenerProductosNormales();

// Procesar registro de Entrada de Stock
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($_POST['action'] ?? '') == 'registrar_entrada') {
    $productoId = trim($_POST['producto_id'] ?? '');
    $cantidad   = floatval($_POST['cantidad'] ?? 0);
    $costoUnit  = floatval($_POST['costo_unitario'] ?? 0);
    $motivo     = trim($_POST['motivo'] ?? 'Compra / Entrada de Stock');

    if (empty($productoId) || $cantidad <= 0) {
        $error = "Selecciona un producto e ingresa una cantidad válida mayor a 0.";
    } else {
        try {
            // Actualizar stock y costo del producto
            $stmt = $db->prepare("UPDATE productos SET stock_cantidad = stock_cantidad + :cant, costo = IF(:costo > 0, :costo, costo) WHERE id = :id");
            $stmt->bindParam(':cant', $cantidad);
            $stmt->bindParam(':costo', $costoUnit);
            $stmt->bindParam(':id', $productoId);
            
            if ($stmt->execute()) {
                $mensaje = "Entrada de stock registrada exitosamente (+{$cantidad}).";
                $productosNormales = $productoModel->obtenerProductosNormales();
            } else {
                $error = "No se pudo registrar la entrada de stock.";
            }
        } catch (Exception $e) {
            $error = "Error en base de datos: " . $e->getMessage();
        }
    }
}

// Obtener inventario actual de todos los productos
$queryStock = "SELECT id, codigo_barras, codigo_interno_sku, descripcion, tipo, unidad_medida, stock_cantidad, costo, precio_venta 
               FROM productos 
               WHERE tipo = 'NORMAL' AND estado = 'ACTIVO' 
               ORDER BY descripcion ASC";
$stmtStock = $db->query($queryStock);
$inventario = $stmtStock->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entradas de Stock | Lavandería</title>
    <link rel="stylesheet" href="css/index.css">
    <style>
        .table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; color: var(--text-main); }
        .table th { padding: 0.75rem 1rem; text-align: left; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); border-bottom: 2px solid var(--border); }
        .table td { padding: 1rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        .table tr:hover td { background: rgba(255,255,255,0.02); }
        .badge { padding: 0.25rem 0.75rem; border-radius: 99px; font-size: 0.75rem; font-weight: 600; }
        .badge-normal { background: rgba(59, 130, 246, 0.2); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.3); }

        /* Modal Styles */
        .modal-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
            display: none; align-items: center; justify-content: center;
            z-index: 9999; animation: fadeIn 0.2s ease;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: #1e293b; border: 1px solid var(--border);
            border-radius: 20px; padding: 2rem; max-width: 500px; width: 90%;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); animation: slideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    </style>
</head>
<body>
<div class="app-container">
    <aside class="sidebar">
        <h2 style="font-size: 1.25rem; color: #fff; margin-bottom: 2rem;">Lavandería App</h2>
        <nav>
            <a href="dashboard.php" style="display:block;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);">Dashboard</a>
            
            <!-- Productos -->
            <div class="menu-dropdown">
                <a href="javascript:void(0)" onclick="toggleSubmenu('submenu-productos')" style="display:flex;justify-content:space-between;align-items:center;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);cursor:pointer;">
                    <span>Productos</span>
                    <span style="font-size:0.75rem;">▾</span>
                </a>
                <div id="submenu-productos" class="submenu">
                    <a href="productos_registro.php">Registro</a>
                    <a href="productos.php">Catálogo</a>
                </div>
            </div>

            <!-- Stock -->
            <div class="menu-dropdown">
                <a href="javascript:void(0)" onclick="toggleSubmenu('submenu-stock')" style="display:flex;justify-content:space-between;align-items:center;color:var(--primary);font-weight:600;text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);cursor:pointer;">
                    <span>Stock</span>
                    <span style="font-size:0.75rem;">▾</span>
                </a>
                <div id="submenu-stock" class="submenu open">
                    <a href="stock_entradas.php" style="color:var(--primary);font-weight:600;">Entradas</a>
                </div>
            </div>

            <!-- Clientes -->
            <div class="menu-dropdown">
                <a href="javascript:void(0)" onclick="toggleSubmenu('submenu-clientes')" style="display:flex;justify-content:space-between;align-items:center;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);cursor:pointer;">
                    <span>Clientes</span>
                    <span style="font-size:0.75rem;">▾</span>
                </a>
                <div id="submenu-clientes" class="submenu">
                    <a href="clientes_registro.php">Registro</a>
                    <a href="clientes.php">Listado</a>
                </div>
            </div>

            <?php if($auth->isAdmin()): ?>
            <!-- Usuarios y Roles -->
            <div class="menu-dropdown">
                <a href="javascript:void(0)" onclick="toggleSubmenu('submenu-usuarios')" style="display:flex;justify-content:space-between;align-items:center;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);cursor:pointer;">
                    <span>Usuarios y Roles</span>
                    <span style="font-size:0.75rem;">▾</span>
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
        <header style="margin-bottom: 2.5rem; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 style="font-size: 2rem;">Entradas de Stock</h1>
                <p style="color: var(--text-muted);">Recepción de insumos y control de existencias en almacén.</p>
            </div>
            <button type="button" class="btn-primary" style="width:auto; padding:0.6rem 1.25rem; font-size:0.9rem;" onclick="abrirModalEntrada()">
                ➕ Nueva Entrada
            </button>
        </header>

        <?php if ($mensaje): ?>
            <div class="alert" style="background: rgba(16, 185, 129, 0.1); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.2); padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem;">
                <?= htmlspecialchars($mensaje) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error" style="margin-bottom: 1.5rem;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="auth-card" style="width: 100%; max-width: 100%; padding: 2rem; overflow-x: auto;">
            <h3 style="margin-bottom: 0;">Existencias Actuales</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Cód / SKU</th>
                        <th>Descripción</th>
                        <th>Unidad</th>
                        <th>Stock Actual</th>
                        <th>Costo Unit. ($)</th>
                        <th>Precio Venta ($)</th>
                        <th style="text-align: right;">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($inventario)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2.5rem 1rem;">
                            No hay insumos registrados. <a href="productos_registro.php" style="color:var(--primary);text-decoration:none;">Registrar producto →</a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach($inventario as $inv): ?>
                    <tr>
                        <td style="font-size: 0.85rem; color: var(--text-muted);">
                            <?= htmlspecialchars($inv['codigo_barras'] ?? '') ?><br>
                            <small><?= htmlspecialchars($inv['codigo_interno_sku'] ?? '') ?></small>
                        </td>
                        <td style="font-weight: 500;"><?= htmlspecialchars($inv['descripcion']) ?></td>
                        <td>
                            <span class="badge badge-normal"><?= htmlspecialchars($inv['unidad_medida']) ?></span>
                        </td>
                        <td style="font-weight: 700; font-size: 1.05rem; color: <?= ($inv['stock_cantidad'] <= 5) ? '#f87171' : '#34d399' ?>;">
                            <?= number_format($inv['stock_cantidad'], 2) ?>
                        </td>
                        <td style="color: var(--text-muted);">$<?= number_format($inv['costo'], 2) ?></td>
                        <td style="font-weight: 600;">$<?= number_format($inv['precio_venta'], 2) ?></td>
                        <td style="text-align: right;">
                            <button type="button" class="btn-primary" style="padding: 0.4rem 0.9rem; font-size: 0.8rem; width: auto;"
                                    onclick="abrirModalEntrada('<?= htmlspecialchars($inv['id'], ENT_QUOTES) ?>')">
                                + Entrada
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>

<!-- Modal de Nueva Entrada de Stock -->
<div id="modal-entrada" class="modal-overlay" onclick="cerrarModalEntrada(event)">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem;">
            <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(34, 197, 94, 0.15); display: flex; align-items: center; justify-content: center; color: #22c55e; font-size: 1.3rem;">
                📦
            </div>
            <div>
                <h3 style="font-size: 1.25rem; color: #fff; margin-bottom: 0.2rem;">Registrar Entrada</h3>
                <p style="color: var(--text-muted); font-size: 0.85rem;">Incremento de existencias de insumo</p>
            </div>
        </div>

        <form method="POST" id="form-entrada">
            <input type="hidden" name="action" value="registrar_entrada">

            <div class="form-group" style="margin-bottom: 1.15rem;">
                <label class="form-label">Producto / Insumo *</label>
                <select name="producto_id" id="entrada-producto-id" class="form-control" required style="appearance: none;">
                    <option value="">— Selecciona un producto —</option>
                    <?php foreach ($productosNormales as $p): ?>
                        <option value="<?= htmlspecialchars($p['id']) ?>">
                            <?= htmlspecialchars($p['descripcion']) ?> (<?= htmlspecialchars($p['unidad_medida']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.15rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Cantidad a Recibir *</label>
                    <input type="number" step="0.01" min="0.01" name="cantidad" id="entrada-cantidad" class="form-control" required placeholder="0.00">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Costo Unitario ($)</label>
                    <input type="number" step="0.01" min="0" name="costo_unitario" id="entrada-costo" class="form-control" placeholder="0.00">
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label">Motivo / Proveedor</label>
                <input type="text" name="motivo" class="form-control" placeholder="Ej: Compra a proveedor / Reposición">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn-action" style="background: rgba(255,255,255,0.06); color: var(--text-main); padding: 0.65rem 1.25rem; border-radius: 8px;" onclick="cerrarModalEntrada()">Cancelar</button>
                <button type="submit" class="btn-primary" style="padding: 0.65rem 1.5rem; width: auto; font-size: 0.88rem; border-radius: 8px;">Guardar Entrada</button>
            </div>
        </form>
    </div>
</div>

<script>
    function toggleSubmenu(id) {
        const el = document.getElementById(id);
        if (el) el.classList.toggle('open');
    }

    function abrirModalEntrada(productoId = '') {
        if (productoId) {
            document.getElementById('entrada-producto-id').value = productoId;
        }
        document.getElementById('modal-entrada').classList.add('active');
    }

    function cerrarModalEntrada(event) {
        if (!event || event.target.id === 'modal-entrada' || event.type === 'click') {
            document.getElementById('modal-entrada').classList.remove('active');
        }
    }
</script>
</body>
</html>
