<?php
require_once __DIR__ . '/../../../../src/config/Database.php';
require_once __DIR__ . '/../../../../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../../../../src/modules/auth/AuthService.php';

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
if (!$auth->isAdmin()) {
    die("Acceso denegado. Solo administradores.");
}

$mensaje = "";

// Lógica para crear usuario
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'crear') {
    $usuarioModel->nombre = $_POST['nombre'];
    $usuarioModel->correo = $_POST['correo'];
    $usuarioModel->password_hash = $_POST['password'];
    $usuarioModel->rol = $_POST['rol'];
    $usuarioModel->estado = 'ACTIVO';
    
    if($usuarioModel->crear()) {
        $mensaje = "Usuario creado exitosamente.";
    } else {
        $mensaje = "Error al crear el usuario. Posible correo duplicado.";
    }
}

// Obtener lista
$usuarios = $usuarioModel->obtenerTodos();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Usuarios</title>
    <link rel="stylesheet" href="../../../../public/css/index.css">
    <style>
        .table { width: 100%; border-collapse: collapse; margin-top: 2rem; color: var(--text-main); }
        .table th, .table td { padding: 1rem; text-align: left; border-bottom: 1px solid var(--border); }
        .badge { padding: 0.25rem 0.75rem; border-radius: 99px; font-size: 0.75rem; font-weight: 600; }
        .badge-admin { background: rgba(139, 92, 246, 0.2); color: #c4b5fd; border: 1px solid rgba(139, 92, 246, 0.3); }
        .badge-cajero { background: rgba(59, 130, 246, 0.2); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.3); }
    </style>
</head>
<body>
    <div class="app-container">
        <aside class="sidebar">
            <h2 style="font-size: 1.25rem; color: #fff; margin-bottom: 2rem;">Lavandería App</h2>
            <nav>
                <a href="../../../../public/dashboard.php" style="display:block;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);">Dashboard</a>
                <a href="../../../../src/modules/catalog/views/gestion_productos.php" style="display:block;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);">Catálogo de Productos</a>
                <a href="../../../../src/modules/customers/views/gestion_clientes.php" style="display:block;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);">Clientes</a>
                <a href="#" style="display:block;color:var(--primary);font-weight:600;text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);">Usuarios y Roles</a>
                <a href="../../../../public/dashboard.php?logout=1" style="display:block;color:#f87171;text-decoration:none;padding:0.75rem 0;margin-top:2rem;">Cerrar Sesión</a>
            </nav>
        </aside>
        
        <main class="main-content">
            <header style="margin-bottom: 3rem; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1 style="font-size: 2rem;">Gestión de Usuarios</h1>
                    <p style="color: var(--text-muted);">Administra accesos y roles del sistema.</p>
                </div>
            </header>

            <?php if ($mensaje): ?>
                <div class="alert alert-error" style="background: rgba(16, 185, 129, 0.1); color: #34d399; border-color: rgba(16, 185, 129, 0.2);">
                    <?= htmlspecialchars($mensaje) ?>
                </div>
            <?php endif; ?>

            <div style="display: grid; grid-template-columns: 350px 1fr; gap: 2rem;">
                <!-- Formulario -->
                <div class="auth-card" style="width: 100%; max-width: 100%; padding: 2rem;">
                    <h3 style="margin-bottom: 1.5rem;">Nuevo Usuario</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="crear">
                        <div class="form-group">
                            <label class="form-label">Nombre</label>
                            <input type="text" name="nombre" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Correo</label>
                            <input type="email" name="correo" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Contraseña</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Rol</label>
                            <select name="rol" class="form-control" required style="appearance: none;">
                                <option value="EMPLEADO_CAJERO">Empleado / Cajero</option>
                                <option value="ADMINISTRADOR">Administrador</option>
                            </select>
                        </div>
                        <button type="submit" class="btn-primary" style="padding: 0.75rem;">Crear Usuario</button>
                    </form>
                </div>

                <!-- Lista -->
                <div class="auth-card" style="width: 100%; max-width: 100%; padding: 2rem;">
                    <h3 style="margin-bottom: 0;">Usuarios Activos</h3>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Correo</th>
                                <th>Rol</th>
                                <th>Última Act.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($usuarios as $u): ?>
                            <tr>
                                <td><?= htmlspecialchars($u['nombre']) ?></td>
                                <td style="color: var(--text-muted);"><?= htmlspecialchars($u['correo']) ?></td>
                                <td>
                                    <span class="badge <?= $u['rol'] === 'ADMINISTRADOR' ? 'badge-admin' : 'badge-cajero' ?>">
                                        <?= htmlspecialchars($u['rol']) ?>
                                    </span>
                                </td>
                                <td style="font-size: 0.85rem; color: var(--text-muted);">
                                    <?= $u['ultima_actividad'] ?? 'Nunca' ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
