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
    $nombre = trim($_POST['nombre'] ?? '');
    $correo = trim($_POST['correo'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $rol = $_POST['rol'] ?? 'EMPLEADO_CAJERO';

    if (empty($nombre) || empty($correo) || empty($password)) {
        $error = "Todos los campos obligatorios deben ser completados.";
    } elseif ($password !== $confirmPassword) {
        $error = "La contraseña y la confirmación no coinciden.";
    } else {
        $usuarioModel->nombre = $nombre;
        $usuarioModel->correo = $correo;
        $usuarioModel->password_hash = $password;
        $usuarioModel->rol = $rol;
        $usuarioModel->estado = 'ACTIVO';
        
        if ($usuarioModel->crear()) {
            $mensaje = "Usuario creado exitosamente.";
        } else {
            $error = "Error al crear el usuario. Posible correo duplicado.";
        }
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
    <style>
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
        <?php $activePage = 'usuarios_alta'; require_once __DIR__ . '/partials/sidebar.php'; ?>
        
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
                    <form method="POST" id="form-alta-usuario">
                        <input type="hidden" name="action" value="crear">
                        
                        <div class="form-group">
                            <label class="form-label">Nombre</label>
                            <input type="text" name="nombre" class="form-control" placeholder="Nombre completo" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Usuario</label>
                            <input type="text" name="correo" class="form-control" placeholder="Nombre de usuario o correo" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Contraseña</label>
                            <div style="position: relative; display: flex; align-items: center;">
                                <input type="password" name="password" id="inp-password" class="form-control" placeholder="••••••••" required style="padding-right: 2.85rem;" oninput="validarContrasenas()">
                                <button type="button" class="toggle-password-btn" onclick="togglePasswordVisibility('inp-password', 'eye-icon-pass')" title="Mostrar / ocultar contraseña">
                                    <span id="eye-icon-pass">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                    </span>
                                </button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Confirmar Contraseña</label>
                            <div style="position: relative; display: flex; align-items: center;">
                                <input type="password" name="confirm_password" id="inp-confirm-password" class="form-control" placeholder="••••••••" required style="padding-right: 2.85rem;" oninput="validarContrasenas()">
                                <button type="button" class="toggle-password-btn" onclick="togglePasswordVisibility('inp-confirm-password', 'eye-icon-confirm')" title="Mostrar / ocultar contraseña">
                                    <span id="eye-icon-confirm">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                    </span>
                                </button>
                            </div>
                            <small id="pass-match-hint" style="display:none;font-size:0.8rem;margin-top:0.35rem;"></small>
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

        function validarContrasenas() {
            const pass = document.getElementById('inp-password').value;
            const confirm = document.getElementById('inp-confirm-password').value;
            const confirmInput = document.getElementById('inp-confirm-password');
            const hint = document.getElementById('pass-match-hint');

            if (!confirm) {
                confirmInput.setCustomValidity('');
                if (hint) hint.style.display = 'none';
                return true;
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

        document.getElementById('form-alta-usuario').addEventListener('submit', function(e) {
            if (!validarContrasenas()) {
                e.preventDefault();
                alert('La contraseña y la confirmación no coinciden.');
                document.getElementById('inp-confirm-password').focus();
            }
        });
    </script>
    <script src="js/sidebar.js"></script>
</body>
</html>
