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

// Middleware
if (!$auth->checkAuth()) {
    header("Location: login.php");
    exit;
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Obtener catálogo completo
$productos = $productoModel->obtenerTodos();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo de Productos | Lavandería</title>
    <link rel="stylesheet" href="css/index.css">
    <style>
        .table { width: 100%; border-collapse: collapse; margin-top: 2rem; color: var(--text-main); }
        .table th { padding: 0.75rem 1rem; text-align: left; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); border-bottom: 2px solid var(--border); }
        .table td { padding: 1rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        .table tr:hover td { background: rgba(255,255,255,0.02); }
        .badge { padding: 0.25rem 0.75rem; border-radius: 99px; font-size: 0.75rem; font-weight: 600; }
        .badge-normal   { background: rgba(59, 130, 246, 0.2); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.3); }
        .badge-kit      { background: rgba(139, 92, 246, 0.2); color: #c4b5fd; border: 1px solid rgba(139, 92, 246, 0.3); }
        .badge-servicio { background: rgba(16, 185, 129, 0.2); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.3); }
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
                    <a href="javascript:void(0)" onclick="toggleSubmenu('submenu-productos')" style="display:flex;justify-content:space-between;align-items:center;color:var(--primary);font-weight:600;text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);cursor:pointer;">
                        <span>Productos</span>
                        <span style="font-size:0.75rem;">▾</span>
                    </a>
                    <div id="submenu-productos" class="submenu open">
                        <a href="productos_registro.php">Registro</a>
                        <a href="productos.php" style="color:var(--primary);font-weight:600;">Catálogo</a>
                    </div>
                </div>
                <!-- Stock -->
                <div class="menu-dropdown">
                    <a href="javascript:void(0)" onclick="toggleSubmenu('submenu-stock')" style="display:flex;justify-content:space-between;align-items:center;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);cursor:pointer;">
                        <span>Stock</span>
                        <span style="font-size:0.75rem;">▾</span>
                    </a>
                    <div id="submenu-stock" class="submenu">
                        <a href="stock_entradas.php">Entradas</a>
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
            <header style="margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1 style="font-size: 2rem;">Catálogo de Productos</h1>
                    <p style="color: var(--text-muted);">Listado de productos, servicios y kits registrados.</p>
                </div>
                <a href="productos_registro.php" class="btn-primary" style="text-decoration:none; display:inline-block; width:auto; padding:0.6rem 1.25rem; font-size:0.9rem;">➕ Nuevo Elemento</a>
            </header>

            <div class="auth-card" style="width: 100%; max-width: 100%; padding: 2rem; overflow-x: auto;">
                <h3 style="margin-bottom: 0;">Elementos Registrados</h3>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Cód / SKU</th>
                            <th>Descripción</th>
                            <th>Tipo</th>
                            <th>Cantidad</th>
                            <th>Costo</th>
                            <th>Precio Venta</th>
                            <th>Unidad de Medida</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($productos)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2.5rem 1rem;">
                                No hay elementos en el catálogo aún.
                                <a href="productos_registro.php" style="color:var(--primary);text-decoration:none;margin-left:0.5rem;">Registrar primero →</a>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach($productos as $p): ?>
                        <tr>
                            <td style="font-size: 0.85rem; color: var(--text-muted);">
                                <?= htmlspecialchars($p['codigo_barras'] ?? '') ?><br>
                                <small><?= htmlspecialchars($p['codigo_interno_sku'] ?? '') ?></small>
                            </td>
                            <td style="font-weight: 500;"><?= htmlspecialchars($p['descripcion']) ?></td>
                            <td>
                                <?php
                                    $badgeClass = 'badge-normal';
                                    if ($p['tipo'] == 'KIT')      $badgeClass = 'badge-kit';
                                    if ($p['tipo'] == 'SERVICIO') $badgeClass = 'badge-servicio';
                                ?>
                                <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($p['tipo']) ?></span>
                            </td>
                            <td style="font-weight: 600; color: <?= ($p['tipo'] === 'KIT') ? '#c4b5fd' : (($p['stock_cantidad'] <= 0) ? '#f87171' : 'var(--text-main)') ?>">
                                <?= ($p['tipo'] === 'KIT') ? (number_format($p['ropa_kg'] ?? 0, 2) . ' Kg') : number_format($p['stock_cantidad'], 2) ?>
                            </td>
                            <td style="color: var(--text-muted); font-size: 0.9rem;">$<?= number_format($p['costo'], 2) ?></td>
                            <td style="font-weight: 600;">$<?= number_format($p['precio_venta'], 2) ?></td>
                            <td style="font-size: 0.85rem; color: var(--text-muted); font-weight: 500;">
                                <?= htmlspecialchars($p['unidad_medida'] ?? '') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
    <script>
        function toggleSubmenu(id) {
            const el = document.getElementById(id);
            if (el) el.classList.toggle('open');
        }
    </script>
</body>
</html>
