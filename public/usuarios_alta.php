<?php
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../src/modules/auth/AuthService.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;
use App\Modules\Auth\Usuario;

$db = (new Database())->getConnection();
$auth = new AuthService($db);
$usuarioModel = new Usuario($db);

// Middleware básico
if (!$auth->checkAuth()) {
    header("Location: login.php");
    exit;
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!$auth->isAdmin()) {
    die("Acceso denegado. Solo administradores.");
}

$mensaje = "";
$error = "";

// Lógica para crear usuario
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'crear') {
    $usuarioModel->nombre = trim($_POST['nombre'] ?? '');
    $usuarioModel->correo = trim($_POST['correo'] ?? '');
    $usuarioModel->password_hash = $_POST['password'] ?? '';
    $usuarioModel->rol = $_POST['rol'] ?? 'EMPLEADO_CAJERO';
    $usuarioModel->estado = 'ACTIVO';
    
    if ($usuarioModel->crear()) {
        $mensaje = "Usuario creado exitosamente.";
    } else {
        $error = "Error al crear el usuario. Posible correo duplicado.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuevo Usuario | Lavandería</title>
    <link rel="stylesheet" href="css/index.css">
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
                        <span style="font-size:0.75rem;">&#9662;</span>
                    </a>
                    <div id="submenu-productos" class="submenu">
                        <a href="productos_registro.php">Registro</a>
                        <a href="productos.php">Cat&aacute;logo</a>
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
                <div class="menu-dropdown">
                    <a href="javascript:void(0)" onclick="toggleSubmenu('submenu-usuarios')" style="display:flex;justify-content:space-between;align-items:center;color:var(--primary);font-weight:600;text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);cursor:pointer;">
                        <span>Usuarios y Roles</span>
                        <span style="font-size:0.75rem;">▾</span>
                    </a>
                    <div id="submenu-usuarios" class="submenu open">
                        <a href="usuarios_alta.php" style="color:var(--primary);font-weight:600;">Altas</a>
                        <a href="usuarios.php">Listado</a>
                    </div>
                </div>
                <a href="dashboard.php?logout=1" style="display:block;color:#f87171;text-decoration:none;padding:0.75rem 0;margin-top:2rem;">Cerrar Sesión</a>
            </nav>
        </aside>
        
        <main class="main-content">
            <header style="margin-bottom: 2.5rem; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1 style="font-size: 2rem;">Alta de Usuario</h1>
                    <p style="color: var(--text-muted);">Registra un nuevo usuario con su rol correspondiente.</p>
                </div>
                <a href="usuarios.php" class="btn-primary" style="text-decoration:none; display:inline-block; width:auto; padding:0.6rem 1.25rem; font-size:0.9rem;">Ver Listado</a>
            </header>

            <?php if ($mensaje): ?>
                <div class="alert" style="background: rgba(16, 185, 129, 0.1); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.2); padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem; max-width: 700px;">
                    <?= htmlspecialchars($mensaje) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error" style="max-width: 700px; margin-bottom: 1.5rem;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div style="max-width: 700px;">
                <div class="auth-card" style="width: 100%; max-width: 100%; padding: 2.5rem; border-radius: 20px;">
                    <h3 style="margin-bottom: 1.75rem; font-size: 1.35rem; color: #fff;">Nuevo Usuario</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="crear">
                        
                        <div class="form-group">
                            <label class="form-label">Nombre</label>
                            <input type="text" name="nombre" class="form-control" placeholder="Nombre completo" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Correo</label>
                            <input type="email" name="correo" class="form-control" placeholder="admin@lavanderia.com" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Contraseña</label>
                            <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Rol</label>
                            <select name="rol" class="form-control" required style="appearance: none;">
                                <option value="EMPLEADO_CAJERO">Empleado / Cajero</option>
                                <option value="ADMINISTRADOR">Administrador</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn-primary" style="margin-top: 1rem; padding: 0.95rem;">Crear Usuario</button>
                    </form>
                </div>
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
