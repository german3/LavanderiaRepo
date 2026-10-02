<?php
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../src/modules/auth/AuthService.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;

$db = (new Database())->getConnection();
$auth = new AuthService($db);

if (!$auth->checkAuth()) {
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard | Lavandería</title>
    <link rel="stylesheet" href="css/index.css">
</head>
<body>
    <div class="app-container">
        <aside class="sidebar">
            <h2 style="font-size: 1.25rem; color: #fff; margin-bottom: 2rem;">Lavandería App</h2>
            <nav>
                <a href="dashboard.php" style="display:block;color:var(--primary);font-weight:600;text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);">Dashboard</a>
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
                <!-- Inventario -->
                <div class="menu-dropdown">
                    <a href="javascript:void(0)" onclick="toggleSubmenu('submenu-stock')" style="display:flex;justify-content:space-between;align-items:center;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);cursor:pointer;">
                        <span>Inventario</span>
                        <span style="font-size:0.75rem;">▾</span>
                    </a>
                    <div id="submenu-stock" class="submenu">
                        <a href="stock_entradas.php">Entradas / Salidas</a>
                        <a href="historial.php">Historial</a>
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
                <a href="?logout=1" style="display:block;color:#f87171;text-decoration:none;padding:0.75rem 0;margin-top:2rem;">Cerrar Sesión</a>
            </nav>
        </aside>
        
        <main class="main-content">
            <header style="margin-bottom: 3rem;">
                <h1 style="font-size: 2rem;">Hola, <?= htmlspecialchars($_SESSION['nombre']) ?> 👋</h1>
                <p style="color: var(--text-muted);">Has iniciado sesión como <strong><?= htmlspecialchars($_SESSION['rol']) ?></strong></p>
            </header>
            
            <div class="auth-card" style="width: 100%; max-width: none;">
                <h3>Resumen Rápido</h3>
                <p style="color: var(--text-muted); margin-top: 1rem;">El sistema de autenticación funciona correctamente. La información de sesión y auditoría de la última actividad han sido registradas en la base de datos.</p>
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
