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

// Lógica para editar usuario (Solo administradores)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'editar') {
    if (!$auth->isAdmin()) {
        $error = "Solo los administradores pueden modificar usuarios.";
    } else {
        $idUsuario       = trim($_POST['id'] ?? '');
        $nombre          = trim($_POST['nombre'] ?? '');
        $correo          = trim($_POST['correo'] ?? '');
        $rol             = trim($_POST['rol'] ?? 'EMPLEADO_CAJERO');
        $password        = trim($_POST['password'] ?? '');
        $confirmPassword = trim($_POST['confirm_password'] ?? '');

        if (empty($idUsuario) || empty($nombre) || empty($correo)) {
            $error = "El nombre y el correo electrónico son obligatorios.";
        } elseif (!empty($password) && $password !== $confirmPassword) {
            $error = "La nueva contraseña y la confirmación no coinciden.";
        } else {
            if (!in_array($rol, ['ADMINISTRADOR', 'EMPLEADO_CAJERO'])) {
                $rol = 'EMPLEADO_CAJERO';
            }
            if ($usuarioModel->actualizar($idUsuario, $nombre, $correo, $rol, !empty($password) ? $password : null)) {
                $mensaje = "Usuario actualizado exitosamente.";
                // Si el usuario en sesión es el modificado, actualizar datos de sesión
                if (isset($_SESSION['usuario_id']) && $_SESSION['usuario_id'] === $idUsuario) {
                    $_SESSION['usuario_rol'] = $rol;
                    $_SESSION['usuario_nombre'] = $nombre;
                }
            } else {
                $error = "Error al actualizar el usuario. Verifique si el correo ya está registrado por otro usuario.";
            }
        }
    }
}

// Lógica para reactivar usuario
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'activar') {
    $idUsuario = $_POST['id'] ?? '';
    if ($usuarioModel->activar($idUsuario)) {
        $mensaje = "El usuario ha sido reactivado exitosamente.";
    } else {
        $error = "Error al intentar reactivar al usuario.";
    }
}

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

// Obtener lista de usuarios activos e inactivos
$usuarios = $usuarioModel->obtenerTodos();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios | Lavandería</title>
    <link rel="stylesheet" href="css/index.css">
    <style>
        .table { width: 100%; border-collapse: collapse; margin-top: 2rem; color: var(--text-main); }
        .table th, .table td { padding: 1rem; text-align: left; border-bottom: 1px solid var(--border); }
        .badge { padding: 0.25rem 0.75rem; border-radius: 99px; font-size: 0.75rem; font-weight: 600; }
        .badge-admin { background: rgba(139, 92, 246, 0.2); color: #c4b5fd; border: 1px solid rgba(139, 92, 246, 0.3); }
        .badge-cajero { background: rgba(59, 130, 246, 0.2); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.3); }
        .badge-activo { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-inactivo { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.3); }
        
        /* Botones de acción */
        .btn-action { padding: 0.4rem 0.85rem; border-radius: 8px; font-size: 0.82rem; font-weight: 600; cursor: pointer; border: none; text-decoration: none; display: inline-flex; align-items: center; gap: 0.35rem; transition: all 0.2s ease; }
        .btn-edit { background: rgba(59,130,246,0.15); color: #93c5fd; border: 1px solid rgba(59,130,246,0.3); margin-right: 0.4rem; }
        .btn-edit:hover { background: rgba(59,130,246,0.3); color: #fff; }
        .btn-baja { background: rgba(239,68,68,0.15); color: #fca5a5; border: 1px solid rgba(239,68,68,0.3); }
        .btn-baja:hover { background: rgba(239,68,68,0.3); color: #fff; }
        .btn-activar { background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3); }
        .btn-activar:hover { background: rgba(16,185,129,0.3); color: #fff; }

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
        .toggle-password-btn {
            position: absolute;
            right: 0.75rem;
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.35rem;
            border-radius: 8px;
            transition: color 0.2s, background 0.2s;
        }
        .toggle-password-btn:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.08);
        }
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
                <div class="menu-dropdown">
                    <a href="javascript:void(0)" onclick="toggleSubmenu('submenu-usuarios')" style="display:flex;justify-content:space-between;align-items:center;color:var(--primary);font-weight:600;text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);cursor:pointer;">
                        <span>Usuarios y Roles</span>
                        <span style="font-size:0.75rem;">▾</span>
                    </a>
                    <div id="submenu-usuarios" class="submenu open">
                        <a href="usuarios_alta.php">Altas</a>
                        <a href="usuarios.php" style="color:var(--primary);font-weight:600;">Listado</a>
                    </div>
                </div>
                <a href="dashboard.php?logout=1" style="display:block;color:#f87171;text-decoration:none;padding:0.75rem 0;margin-top:2rem;">Cerrar Sesión</a>
            </nav>
        </aside>
        
        <main class="main-content">
            <header style="margin-bottom: 2.5rem; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1 style="font-size: 2rem;">Gestión de Usuarios</h1>
                    <p style="color: var(--text-muted);">Listado y administración de accesos del sistema.</p>
                </div>
                <a href="usuarios_alta.php" class="btn-primary" style="text-decoration:none; display:inline-block; width:auto; padding:0.6rem 1.25rem; font-size:0.9rem;">➕ Nuevo Usuario</a>
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
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:0;">
                        <h3 style="margin:0;">Usuarios Registrados</h3>
                        <!-- Buscador -->
                        <div style="display:flex; align-items:center; gap:0.5rem; background:rgba(255,255,255,0.05); border:1px solid var(--border); border-radius:10px; padding:0.45rem 0.85rem; min-width:260px;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted);flex-shrink:0;">
                                <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                            </svg>
                            <input id="input-busqueda-usr" type="text" placeholder="Buscar por nombre o usuario…"
                                oninput="filtrarUsuarios(this.value)"
                                style="background:transparent;border:none;outline:none;color:var(--text-main);font-size:0.85rem;width:100%;min-width:0;"
                                autocomplete="off">
                            <button id="btn-limpiar-usr" onclick="limpiarBusquedaUsr()" title="Limpiar búsqueda"
                                style="display:none;background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:1rem;padding:0;line-height:1;">✕</button>
                        </div>
                    </div>
                    <table class="table" id="tabla-usuarios">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Usuario</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th>Última Act.</th>
                                <th style="text-align: right;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($usuarios)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 2.5rem 1rem;">
                                    No hay usuarios registrados en el sistema.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach($usuarios as $u): ?>
                            <tr class="fila-usuario"
                                data-nombre="<?= strtolower(htmlspecialchars($u['nombre'] ?? '')) ?>"
                                data-correo="<?= strtolower(htmlspecialchars($u['correo'] ?? '')) ?>"
                                data-filtrado="1"
                                style="<?= ($u['estado'] === 'INACTIVO') ? 'opacity: 0.65;' : '' ?>">
                                <td style="font-weight: 500;"><?= htmlspecialchars($u['nombre']) ?></td>
                                <td style="color: var(--text-muted);"><?= htmlspecialchars($u['correo']) ?></td>
                                <td>
                                    <span class="badge <?= $u['rol'] === 'ADMINISTRADOR' ? 'badge-admin' : 'badge-cajero' ?>">
                                        <?= htmlspecialchars($u['rol']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($u['estado'] === 'ACTIVO'): ?>
                                        <span class="badge badge-activo">Activo</span>
                                    <?php else: ?>
                                        <span class="badge badge-inactivo">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 0.85rem; color: var(--text-muted);">
                                    <?= $u['ultima_actividad'] ?? 'Nunca' ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <button type="button" class="btn-action btn-edit" title="Editar usuario"
                                            onclick="abrirModalEditar('<?= htmlspecialchars($u['id'], ENT_QUOTES) ?>', '<?= htmlspecialchars($u['nombre'], ENT_QUOTES) ?>', '<?= htmlspecialchars($u['correo'], ENT_QUOTES) ?>', '<?= htmlspecialchars($u['rol'], ENT_QUOTES) ?>')">
                                        Editar
                                    </button>
                                    <?php if ($u['estado'] === 'ACTIVO'): ?>
                                        <button type="button" class="btn-action btn-baja" onclick="abrirModalBaja('<?= htmlspecialchars($u['id'], ENT_QUOTES) ?>', '<?= htmlspecialchars($u['nombre'], ENT_QUOTES) ?>')">Baja</button>
                                    <?php else: ?>
                                        <button type="button" class="btn-action btn-activar" onclick="abrirModalActivar('<?= htmlspecialchars($u['id'], ENT_QUOTES) ?>', '<?= htmlspecialchars($u['nombre'], ENT_QUOTES) ?>')">Reactivar</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- Sin resultados (client-side) -->
                    <div id="usr-sin-resultados" style="display:none; text-align:center; color:var(--text-muted); padding:2.5rem 1rem;">
                        🔍 No se encontraron usuarios que coincidan con la búsqueda.
                    </div>

                    <!-- ══ PAGINADOR USUARIOS ═════════════════════════════════ -->
                    <div id="paginador-usr" style="
                        display:flex; align-items:center; justify-content:space-between;
                        flex-wrap:wrap; gap:0.75rem; margin-top:1.25rem;
                        padding:0.85rem 1.1rem;
                        background:rgba(255,255,255,0.04);
                        border:1px solid var(--border); border-radius:12px;">
                        <div id="pag-usr-info" style="font-size:0.82rem; color:var(--text-muted);"></div>
                        <div id="pag-usr-botones" style="display:flex; gap:0.35rem; flex-wrap:wrap; align-items:center;"></div>
                        <div style="display:flex; align-items:center; gap:0.5rem; font-size:0.82rem; color:var(--text-muted);">
                            <span>Mostrar</span>
                            <select id="sel-usr-pagina" onchange="cambiarUsrPorPagina(this.value)" style="
                                background:var(--sidebar-bg); color:var(--text-main);
                                border:1px solid var(--border); border-radius:7px;
                                padding:0.3rem 0.5rem; font-size:0.82rem; cursor:pointer; outline:none;">
                                <option value="10" selected style="background:#1e293b;color:#f1f5f9;">10</option>
                                <option value="15" style="background:#1e293b;color:#f1f5f9;">15</option>
                                <option value="20" style="background:#1e293b;color:#f1f5f9;">20</option>
                                <option value="25" style="background:#1e293b;color:#f1f5f9;">25</option>
                                <option value="30" style="background:#1e293b;color:#f1f5f9;">30</option>
                            </select>
                            <span>por página</span>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal de Edición de Usuario -->
    <div id="modal-editar" class="modal-overlay" onclick="cerrarModalEditar(event)">
        <div class="modal-box" onclick="event.stopPropagation()" style="max-width: 500px;">
            <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem;">
                <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(59, 130, 246, 0.15); display: flex; align-items: center; justify-content: center; color: #60a5fa; font-size: 1.3rem;">
                    ✏️
                </div>
                <div>
                    <h3 style="font-size: 1.25rem; color: #fff; margin-bottom: 0.2rem;">Editar Usuario</h3>
                    <p style="color: var(--text-muted); font-size: 0.85rem;">Modificación de datos, rol y contraseña</p>
                </div>
            </div>

            <form method="POST" id="form-editar">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="id" id="edit-usuario-id" value="">

                <div class="form-group" style="margin-bottom: 1.15rem;">
                    <label class="form-label" style="font-size: 0.85rem; color: #e2e8f0; margin-bottom: 0.4rem; display: block;">Nombre Completo *</label>
                    <input type="text" name="nombre" id="edit-usuario-nombre" class="form-control" required style="width: 100%;">
                </div>

                <div class="form-group" style="margin-bottom: 1.15rem;">
                    <label class="form-label" style="font-size: 0.85rem; color: #e2e8f0; margin-bottom: 0.4rem; display: block;">Usuario *</label>
                    <input type="text" name="correo" id="edit-usuario-correo" class="form-control" required style="width: 100%;" placeholder="Nombre de usuario o correo">
                </div>

                <div class="form-group" style="margin-bottom: 1.15rem;">
                    <label class="form-label" style="font-size: 0.85rem; color: #e2e8f0; margin-bottom: 0.4rem; display: block;">Rol *</label>
                    <select name="rol" id="edit-usuario-rol" class="form-control" required style="width: 100%; appearance: none;">
                        <option value="EMPLEADO_CAJERO">Empleado / Cajero</option>
                        <option value="ADMINISTRADOR">Administrador</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 1.15rem;">
                    <label class="form-label" style="font-size: 0.85rem; color: #e2e8f0; margin-bottom: 0.4rem; display: block;">Nueva Contraseña</label>
                    <div style="position: relative; display: flex; align-items: center;">
                        <input type="password" name="password" id="edit-usuario-password" class="form-control" placeholder="••••••••" style="width: 100%; padding-right: 2.85rem;" oninput="validarContrasenasEditar()">
                        <button type="button" class="toggle-password-btn" onclick="togglePasswordVisibility('edit-usuario-password', 'eye-icon-edit-pass')" title="Mostrar / ocultar contraseña">
                            <span id="eye-icon-edit-pass">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </span>
                        </button>
                    </div>
                    <small style="color: var(--text-muted); font-size: 0.78rem; display: block; margin-top: 0.35rem;">
                        ℹ️ Dejar en blanco si deseas conservar la contraseña actual.
                    </small>
                </div>

                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label class="form-label" style="font-size: 0.85rem; color: #e2e8f0; margin-bottom: 0.4rem; display: block;">Confirmar Nueva Contraseña</label>
                    <div style="position: relative; display: flex; align-items: center;">
                        <input type="password" name="confirm_password" id="edit-usuario-confirm" class="form-control" placeholder="••••••••" style="width: 100%; padding-right: 2.85rem;" oninput="validarContrasenasEditar()">
                        <button type="button" class="toggle-password-btn" onclick="togglePasswordVisibility('edit-usuario-confirm', 'eye-icon-edit-confirm')" title="Mostrar / ocultar contraseña">
                            <span id="eye-icon-edit-confirm">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </span>
                        </button>
                    </div>
                    <small id="edit-pass-match-hint" style="display:none;font-size:0.8rem;margin-top:0.35rem;"></small>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                    <button type="button" class="btn-action" style="background: rgba(255,255,255,0.06); color: var(--text-main); padding: 0.65rem 1.25rem; border-radius: 8px;" onclick="cerrarModalEditar()">Cancelar</button>
                    <button type="submit" class="btn-primary" style="padding: 0.65rem 1.5rem; width: auto; font-size: 0.88rem; border-radius: 8px;">Guardar Cambios</button>
                </div>
            </form>
        </div>
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
                    El usuario quedará marcado como inactivo y no podrá iniciar sesión en el sistema.
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

    <!-- Modal de confirmación de Reactivación -->
    <div id="modal-activar" class="modal-overlay" onclick="cerrarModalActivar(event)">
        <div class="modal-box" onclick="event.stopPropagation()">
            <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;">
                <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(16, 185, 129, 0.15); display: flex; align-items: center; justify-content: center; color: #34d399; font-size: 1.3rem;">
                    ✅
                </div>
                <div>
                    <h3 style="font-size: 1.2rem; color: #fff;">Confirmar Reactivación</h3>
                    <p style="color: var(--text-muted); font-size: 0.85rem;">Acción de activación de cuenta</p>
                </div>
            </div>
            <p style="color: var(--text-main); font-size: 0.95rem; line-height: 1.5; margin-bottom: 1.5rem;">
                ¿Deseas reactivar al usuario <strong id="modal-activar-usuario-nombre" style="color: #34d399;"></strong>?
                <span style="display: block; color: var(--text-muted); font-size: 0.85rem; margin-top: 0.5rem;">
                    El usuario volverá a estar activo y podrá iniciar sesión normalmente en el sistema.
                </span>
            </p>
            <form method="POST" id="form-activar">
                <input type="hidden" name="action" value="activar">
                <input type="hidden" name="id" id="activar-usuario-id" value="">
                <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <button type="button" class="btn-action" style="background: rgba(255,255,255,0.06); color: var(--text-main); padding: 0.6rem 1.25rem;" onclick="cerrarModalActivar()">Cancelar</button>
                    <button type="submit" class="btn-action btn-activar" style="padding: 0.6rem 1.25rem; font-weight: 600;">Sí, reactivar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const eyeOpenSvg = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>`;
        const eyeClosedSvg = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>`;

        function togglePasswordVisibility(inputId, iconContainerId) {
            const input = document.getElementById(inputId);
            const iconContainer = document.getElementById(iconContainerId);
            if (!input || !iconContainer) return;

            if (input.type === 'password') {
                input.type = 'text';
                iconContainer.innerHTML = eyeClosedSvg;
                iconContainer.parentElement.style.color = '#60a5fa';
            } else {
                input.type = 'password';
                iconContainer.innerHTML = eyeOpenSvg;
                iconContainer.parentElement.style.color = 'var(--text-muted)';
            }
        }

        function validarContrasenasEditar() {
            const pass = document.getElementById('edit-usuario-password').value;
            const confirm = document.getElementById('edit-usuario-confirm').value;
            const confirmInput = document.getElementById('edit-usuario-confirm');
            const hint = document.getElementById('edit-pass-match-hint');

            if (!pass && !confirm) {
                confirmInput.setCustomValidity('');
                if (hint) hint.style.display = 'none';
                return true;
            }

            if (pass && !confirm) {
                confirmInput.setCustomValidity('Por favor confirma la nueva contraseña.');
                if (hint) {
                    hint.textContent = '⚠️ Ingresa la confirmación de la contraseña';
                    hint.style.color = '#f87171';
                    hint.style.display = 'block';
                }
                return false;
            }

            if (pass !== confirm) {
                confirmInput.setCustomValidity('Las contraseñas no coinciden.');
                if (hint) {
                    hint.textContent = '⚠️ Las contraseñas no coinciden';
                    hint.style.color = '#f87171';
                    hint.style.display = 'block';
                }
                return false;
            } else {
                confirmInput.setCustomValidity('');
                if (hint) {
                    hint.textContent = '✓ Las contraseñas coinciden';
                    hint.style.color = '#34d399';
                    hint.style.display = 'block';
                }
                return true;
            }
        }

        document.getElementById('form-editar').addEventListener('submit', function(e) {
            if (!validarContrasenasEditar()) {
                e.preventDefault();
                alert('La nueva contraseña y la confirmación no coinciden.');
                document.getElementById('edit-usuario-confirm').focus();
            }
        });

        function toggleSubmenu(id) {
            const el = document.getElementById(id);
            if (el) el.classList.toggle('open');
        }

        function abrirModalEditar(id, nombre, correo, rol) {
            document.getElementById('edit-usuario-id').value = id;
            document.getElementById('edit-usuario-nombre').value = nombre;
            document.getElementById('edit-usuario-correo').value = correo;
            document.getElementById('edit-usuario-rol').value = rol;

            const passInput = document.getElementById('edit-usuario-password');
            passInput.value = '';
            passInput.type = 'password';
            const passIcon = document.getElementById('eye-icon-edit-pass');
            if (passIcon) {
                passIcon.innerHTML = eyeOpenSvg;
                passIcon.parentElement.style.color = 'var(--text-muted)';
            }

            const confirmInput = document.getElementById('edit-usuario-confirm');
            confirmInput.value = '';
            confirmInput.type = 'password';
            confirmInput.setCustomValidity('');
            const confirmIcon = document.getElementById('eye-icon-edit-confirm');
            if (confirmIcon) {
                confirmIcon.innerHTML = eyeOpenSvg;
                confirmIcon.parentElement.style.color = 'var(--text-muted)';
            }

            const hint = document.getElementById('edit-pass-match-hint');
            if (hint) hint.style.display = 'none';

            document.getElementById('modal-editar').classList.add('active');
        }

        function cerrarModalEditar(event) {
            if (!event || event.target.id === 'modal-editar' || event.type === 'click') {
                document.getElementById('modal-editar').classList.remove('active');
            }
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

        function abrirModalActivar(id, nombre) {
            document.getElementById('activar-usuario-id').value = id;
            document.getElementById('modal-activar-usuario-nombre').textContent = nombre;
            document.getElementById('modal-activar').classList.add('active');
        }

        function cerrarModalActivar(event) {
            if (!event || event.target.id === 'modal-activar' || event.type === 'click') {
                document.getElementById('modal-activar').classList.remove('active');
            }
        }

        // ══════════════════════════════════════════════════════════════════
        //  BÚSQUEDA + PAGINACIÓN USUARIOS
        // ══════════════════════════════════════════════════════════════════
        function filtrarUsuarios(termino) {
            const q = (termino || '').trim().toLowerCase();
            const btnLimpiar = document.getElementById('btn-limpiar-usr');
            if (btnLimpiar) btnLimpiar.style.display = q.length > 0 ? 'block' : 'none';

            document.querySelectorAll('.fila-usuario').forEach(f => {
                const nombre  = f.dataset.nombre  || '';
                const correo  = f.dataset.correo  || '';
                f.dataset.filtrado = (!q || nombre.includes(q) || correo.includes(q)) ? '1' : '0';
            });
            renderUsrPagina(1);
        }

        function limpiarBusquedaUsr() {
            const input = document.getElementById('input-busqueda-usr');
            if (input) { input.value = ''; input.focus(); filtrarUsuarios(''); }
        }

        let usrPagActual = 1;
        let usrPorPagina = 10;

        function getUsrFilas() {
            return Array.from(document.querySelectorAll('.fila-usuario'))
                        .filter(f => f.dataset.filtrado !== '0');
        }

        function renderUsrPagina(pagina) {
            const filas     = getUsrFilas();
            const total     = filas.length;
            const totalPags = Math.max(1, Math.ceil(total / usrPorPagina));
            usrPagActual    = Math.min(Math.max(1, pagina), totalPags);

            const inicio = (usrPagActual - 1) * usrPorPagina;
            const fin    = inicio + usrPorPagina;

            document.querySelectorAll('.fila-usuario').forEach(f => f.style.display = 'none');
            filas.forEach((f, i) => { f.style.display = (i >= inicio && i < fin) ? '' : 'none'; });

            const sinRes = document.getElementById('usr-sin-resultados');
            if (sinRes) sinRes.style.display = total === 0 ? '' : 'none';

            const infoEl = document.getElementById('pag-usr-info');
            if (infoEl) {
                infoEl.textContent = total === 0 ? 'Sin resultados'
                    : `Mostrando ${inicio + 1}–${Math.min(fin, total)} de ${total} usuario${total !== 1 ? 's' : ''}`;
            }

            const botsEl = document.getElementById('pag-usr-botones');
            if (!botsEl) return;
            botsEl.innerHTML = '';

            const btnS = (activo) => `cursor:pointer;border:1px solid var(--border);border-radius:7px;padding:0.3rem 0.65rem;font-size:0.8rem;font-weight:600;transition:all 0.18s;background:${activo ? 'var(--primary)' : 'rgba(255,255,255,0.05)'};color:${activo ? '#fff' : 'var(--text-muted)'};min-width:2.1rem;text-align:center;`;

            const bPrev = document.createElement('button');
            bPrev.innerHTML = '&#8249;'; bPrev.title = 'Anterior';
            bPrev.style.cssText = btnS(false) + (usrPagActual === 1 ? 'opacity:0.35;cursor:default;' : '');
            bPrev.disabled = usrPagActual === 1;
            bPrev.onclick = () => renderUsrPagina(usrPagActual - 1);
            botsEl.appendChild(bPrev);

            const ven = 2;
            let pS = Math.max(1, usrPagActual - ven);
            let pE = Math.min(totalPags, usrPagActual + ven);

            if (pS > 1) {
                const b = document.createElement('button'); b.textContent = '1'; b.style.cssText = btnS(false);
                b.onclick = () => renderUsrPagina(1); botsEl.appendChild(b);
                if (pS > 2) { const d = document.createElement('span'); d.textContent = '…'; d.style.cssText = 'padding:0 0.3rem;color:var(--text-muted);font-size:0.8rem;'; botsEl.appendChild(d); }
            }
            for (let p = pS; p <= pE; p++) {
                const b = document.createElement('button'); b.textContent = p; b.style.cssText = btnS(p === usrPagActual);
                b.onclick = () => renderUsrPagina(p); botsEl.appendChild(b);
            }
            if (pE < totalPags) {
                if (pE < totalPags - 1) { const d = document.createElement('span'); d.textContent = '…'; d.style.cssText = 'padding:0 0.3rem;color:var(--text-muted);font-size:0.8rem;'; botsEl.appendChild(d); }
                const b = document.createElement('button'); b.textContent = totalPags; b.style.cssText = btnS(false);
                b.onclick = () => renderUsrPagina(totalPags); botsEl.appendChild(b);
            }

            const bNext = document.createElement('button');
            bNext.innerHTML = '&#8250;'; bNext.title = 'Siguiente';
            bNext.style.cssText = btnS(false) + (usrPagActual === totalPags ? 'opacity:0.35;cursor:default;' : '');
            bNext.disabled = usrPagActual === totalPags;
            bNext.onclick = () => renderUsrPagina(usrPagActual + 1);
            botsEl.appendChild(bNext);
        }

        function cambiarUsrPorPagina(val) {
            usrPorPagina = parseInt(val, 10) || 10;
            renderUsrPagina(1);
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.fila-usuario').forEach(f => { if (!f.dataset.filtrado) f.dataset.filtrado = '1'; });
            renderUsrPagina(1);
        });
    </script>
</body>
</html>
