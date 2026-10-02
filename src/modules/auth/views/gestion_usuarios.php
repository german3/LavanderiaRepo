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
$error = "";

// Lógica para dar de baja usuario
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'baja') {
    $idUsuario = $_POST['id'] ?? '';
    if (isset($_SESSION['usuario_id']) && $idUsuario === $_SESSION['usuario_id']) {
        $error = "No puedes dar de baja tu propia cuenta mientras estás en sesión.";
    } else {
        if ($usuarioModel->desactivar($idUsuario)) {
            $mensaje = "El usuario ha sido dado de baja exitosamente.";
        } else {
            $error = "Error al intentar dar de baja al usuario.";
        }
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
        
        /* Botones de acción */
        .btn-action { padding: 0.4rem 0.85rem; border-radius: 8px; font-size: 0.82rem; font-weight: 600; cursor: pointer; border: none; text-decoration: none; display: inline-flex; align-items: center; gap: 0.35rem; transition: all 0.2s ease; }
        .btn-edit { background: rgba(59,130,246,0.15); color: #93c5fd; border: 1px solid rgba(59,130,246,0.3); margin-right: 0.4rem; }
        .btn-edit:hover { background: rgba(59,130,246,0.3); color: #fff; }
        .btn-baja { background: rgba(239,68,68,0.15); color: #fca5a5; border: 1px solid rgba(239,68,68,0.3); }
        .btn-baja:hover { background: rgba(239,68,68,0.3); color: #fff; }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            animation: fadeIn 0.2s ease;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-box {
            background: #1e293b;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 2rem;
            max-width: 480px;
            width: 90%;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6);
            animation: slideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <aside class="sidebar">
            <a href="../../../../public/dashboard.php" style="display:block;margin-bottom:2rem;text-align:center;"><img src="../../../../public/img/Logo.jpeg" alt="Lavandería Vera" style="max-width:100%;height:auto;max-height:70px;"></a>
            <nav>
                <a href="../../../../public/dashboard.php" style="display:block;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);">Dashboard</a>
                <a href="../../../../src/modules/catalog/views/gestion_productos.php" style="display:block;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);">Catálogo de Productos</a>
                <a href="../../../../src/modules/customers/views/gestion_clientes.php" style="display:block;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);">Clientes</a>
                <div class="menu-dropdown">
                    <a href="javascript:void(0)" onclick="toggleSubmenu('submenu-usuarios')" style="display:flex;justify-content:space-between;align-items:center;color:var(--primary);font-weight:600;text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);cursor:pointer;">
                        <span>Usuarios y Roles</span>
                        <span style="font-size:0.75rem;">▾</span>
                    </a>
                    <div id="submenu-usuarios" class="submenu open">
                        <a href="usuario_alta.php">Altas</a>
                        <a href="gestion_usuarios.php" style="color:var(--primary);font-weight:600;">Listado</a>
                    </div>
                </div>
                <a href="../../../../public/dashboard.php?logout=1" style="display:block;color:#f87171;text-decoration:none;padding:0.75rem 0;margin-top:2rem;">Cerrar Sesión</a>
            </nav>
        </aside>
        
        <main class="main-content">
            <header style="margin-bottom: 2.5rem; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1 style="font-size: 2rem;">Gestión de Usuarios</h1>
                    <p style="color: var(--text-muted);">Listado y administración de accesos del sistema.</p>
                </div>
                <a href="usuario_alta.php" class="btn-primary" style="text-decoration:none; display:inline-block; width:auto; padding:0.6rem 1.25rem; font-size:0.9rem;">➕ Nuevo Usuario</a>
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

            <div>
                <!-- Lista (Listado) -->
                <div id="listado" class="auth-card" style="width: 100%; max-width: 100%; padding: 2rem;">
                    <h3 style="margin-bottom: 0;">Usuarios Activos</h3>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Correo</th>
                                <th>Rol</th>
                                <th>Última Act.</th>
                                <th style="text-align: right;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($usuarios)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 2.5rem 1rem;">
                                    No hay usuarios activos en el sistema.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach($usuarios as $u): ?>
                            <tr>
                                <td style="font-weight: 500;"><?= htmlspecialchars($u['nombre']) ?></td>
                                <td style="color: var(--text-muted);"><?= htmlspecialchars($u['correo']) ?></td>
                                <td>
                                    <span class="badge <?= $u['rol'] === 'ADMINISTRADOR' ? 'badge-admin' : 'badge-cajero' ?>">
                                        <?= htmlspecialchars($u['rol']) ?>
                                    </span>
                                </td>
                                <td style="font-size: 0.85rem; color: var(--text-muted);">
                                    <?= $u['ultima_actividad'] ?? 'Nunca' ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <button type="button" class="btn-action btn-edit" title="Editar usuario">Editar</button>
                                    <button type="button" class="btn-action btn-baja" onclick="abrirModalBaja('<?= htmlspecialchars($u['id'], ENT_QUOTES) ?>', '<?= htmlspecialchars($u['nombre'], ENT_QUOTES) ?>')">Baja</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal de confirmación de Baja -->
    <div id="modal-baja" class="modal-overlay" onclick="cerrarModalBaja(event)">
        <div class="modal-box" onclick="event.stopPropagation()">
            <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;">
                <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(239, 68, 68, 0.15); display: flex; align-items: center; justify-content: center; color: #f87171; font-size: 1.3rem;">
                    ⚠️
                </div>
                <div>
                    <h3 style="font-size: 1.2rem; color: #fff;">Confirmar Baja</h3>
                    <p style="color: var(--text-muted); font-size: 0.85rem;">Acción de desactivación de cuenta</p>
                </div>
            </div>
            <p style="color: var(--text-main); font-size: 0.95rem; line-height: 1.5; margin-bottom: 1.5rem;">
                ¿Estás seguro de que deseas dar de baja al usuario <strong id="modal-usuario-nombre" style="color: #60a5fa;"></strong>?
                <span style="display: block; color: var(--text-muted); font-size: 0.85rem; margin-top: 0.5rem;">
                    El usuario no será eliminado de la base de datos, pero quedará marcado como inactivo y no aparecerá en la lista de activos ni podrá iniciar sesión.
                </span>
            </p>
            <form method="POST" id="form-baja">
                <input type="hidden" name="action" value="baja">
                <input type="hidden" name="id" id="baja-usuario-id" value="">
                <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <button type="button" class="btn-action" style="background: rgba(255,255,255,0.06); color: var(--text-main); padding: 0.6rem 1.25rem;" onclick="cerrarModalBaja()">Cancelar</button>
                    <button type="submit" class="btn-action btn-baja" style="padding: 0.6rem 1.25rem; font-weight: 600;">Sí, dar de baja</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleSubmenu(id) {
            const el = document.getElementById(id);
            if (el) el.classList.toggle('open');
        }

        function abrirModalBaja(id, nombre) {
            document.getElementById('baja-usuario-id').value = id;
            document.getElementById('modal-usuario-nombre').textContent = nombre;
            document.getElementById('modal-baja').classList.add('active');
        }

        function cerrarModalBaja(event) {
            if (!event || event.target.id === 'modal-baja' || event.type === 'click') {
                document.getElementById('modal-baja').classList.remove('active');
            }
        }
    </script>
</body>
</html>
