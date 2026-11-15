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
    <script src="js/theme.js"></script>
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

        /* Light Theme Overrides para modales */
        [data-theme="light"] #conf-resumen {
            background: #f8fafc !important;
            border-color: #e2e8f0 !important;
        }
        [data-theme="light"] #modal-confirmar-guardar h3,
        [data-theme="light"] #modal-alerta h3 {
            color: #0f172a !important;
        }
        [data-theme="light"] .toggle-password-btn:hover {
            color: #0f172a;
            background: rgba(0, 0, 0, 0.05);
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
                            <div class="select-wrapper">
                                <select name="rol" class="form-control" required>
                                    <option value="EMPLEADO_CAJERO">Empleado / Cajero</option>
                                    <option value="ADMINISTRADOR">Administrador</option>
                                </select>
                                <div class="select-arrow-btn">▼</div>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn-primary" style="margin-top: 1rem; padding: 0.95rem;">Crear Usuario</button>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <!-- ═══════════════════════════════════════════════════
         MODAL DE CONFIRMACIÓN DE REGISTRO DE USUARIO
         ═══════════════════════════════════════════════════ -->
    <div id="modal-confirmar-guardar" class="modal-overlay" style="z-index: 10050;" onclick="cerrarModalConfirmar(event)">
        <div class="modal-box" style="max-width: 480px;" onclick="event.stopPropagation()">
            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
                <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(59, 130, 246, 0.15); display: flex; align-items: center; justify-content: center; font-size: 1.3rem; color: #60a5fa;">
                    👤
                </div>
                <div>
                    <h3 id="conf-titulo" style="font-size: 1.2rem; color: #fff; margin: 0;">Confirmar Registro</h3>
                    <p style="color: var(--text-muted); font-size: 0.82rem; margin-top: 0.15rem;">Revisa los datos del usuario antes de guardarlo</p>
                </div>
            </div>

            <p style="color: var(--text-muted); font-size: 0.88rem; margin-bottom: 0.75rem;">
                ¿Estás seguro de que deseas registrar este nuevo usuario en el sistema?
            </p>

            <div id="conf-resumen" style="color: var(--text-muted); font-size: 0.88rem; line-height: 1.6; margin: 1rem 0; background: rgba(15,23,42,0.6); border: 1px solid var(--border); border-radius: 12px; padding: 1rem;">
                <!-- Resumen dinámico -->
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn-action" style="background: rgba(255,255,255,0.06); color: var(--text-main); padding: 0.65rem 1.25rem; border-radius: 8px; border: 1px solid var(--border); cursor: pointer;" onclick="cerrarModalConfirmar()">Cancelar</button>
                <button type="button" class="btn-primary" style="width: auto; padding: 0.65rem 1.5rem; border-radius: 8px;" onclick="ejecutarGuardarUsuario()">Sí, Registrar</button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════
         MODAL DE ALERTA PERSONALIZADO (FRAMEWORK UI)
         ═══════════════════════════════════════════════════ -->
    <div id="modal-alerta" class="modal-overlay" style="z-index: 10060;" onclick="cerrarModalAlerta(event)">
        <div class="modal-box" style="max-width: 420px; text-align: center; padding: 2rem 2.25rem;" onclick="event.stopPropagation()">
            <div id="modal-alerta-icono" style="width: 58px; height: 58px; border-radius: 50%; background: rgba(245, 158, 11, 0.15); display: flex; align-items: center; justify-content: center; font-size: 1.6rem; color: #f59e0b; margin: 0 auto 1.25rem; border: 1px solid rgba(245, 158, 11, 0.35);">
                ⚠️
            </div>
            <h3 id="modal-alerta-titulo" style="font-size: 1.2rem; font-weight: 700; color: #fff; margin-bottom: 0.5rem;">
                Atención
            </h3>
            <p id="modal-alerta-mensaje" style="color: var(--text-muted); font-size: 0.92rem; line-height: 1.5; margin-bottom: 1.75rem; white-space: pre-line;">
                Mensaje de alerta
            </p>
            <div style="display: flex; justify-content: center;">
                <button type="button" class="btn-primary" style="width: 100%; padding: 0.75rem 1.5rem; border-radius: 10px; font-weight: 700; font-size: 0.95rem;" onclick="cerrarModalAlerta()">
                    Entendido
                </button>
            </div>
        </div>
    </div>

    <script>
        const eyeOpenSvg = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>`;
        const eyeClosedSvg = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>`;

        let usuarioFormConfirmado = false;

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

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

        function confirmarRegistroUsuario(e) {
            if (usuarioFormConfirmado) return true;
            if (e) e.preventDefault();

            const form = document.getElementById('form-alta-usuario');
            if (!form.checkValidity()) {
                form.reportValidity();
                return false;
            }

            if (!validarContrasenas()) {
                mostrarAlerta('La contraseña y la confirmación no coinciden. Por favor verifícalas antes de continuar.', 'Contraseñas no coinciden');
                document.getElementById('inp-confirm-password').focus();
                return false;
            }

            const nombre = form.querySelector('[name="nombre"]')?.value.trim() || '';
            const correo = form.querySelector('[name="correo"]')?.value.trim() || '';
            const rolVal = form.querySelector('[name="rol"]')?.value || 'EMPLEADO_CAJERO';
            const rolTexto = rolVal === 'ADMINISTRADOR' ? 'Administrador' : 'Empleado / Cajero';
            const rolBadgeColor = rolVal === 'ADMINISTRADOR' ? '#60a5fa' : '#34d399';
            const rolBadgeBg = rolVal === 'ADMINISTRADOR' ? 'rgba(59, 130, 246, 0.15)' : 'rgba(16, 185, 129, 0.15)';

            const resumenHtml = `
                <div style="display:flex; flex-direction:column; gap:0.55rem;">
                    <div>
                        <strong style="color:var(--text-main);">Nombre Completo:</strong>
                        <span style="color:#60a5fa; font-weight:700; margin-left:4px;">${escapeHtml(nombre)}</span>
                    </div>
                    <div>
                        <strong style="color:var(--text-main);">Usuario / Correo:</strong>
                        <span style="color:var(--text-main); font-weight:600; margin-left:4px;">${escapeHtml(correo)}</span>
                    </div>
                    <div>
                        <strong style="color:var(--text-main);">Rol Asignado:</strong>
                        <span style="background:${rolBadgeBg}; color:${rolBadgeColor}; padding:2px 8px; border-radius:6px; font-weight:700; font-size:0.8rem; margin-left:4px;">
                            ${rolTexto}
                        </span>
                    </div>
                    <div>
                        <strong style="color:var(--text-main);">Estado Inicial:</strong>
                        <span style="background:rgba(16, 185, 129, 0.15); color:#34d399; padding:2px 8px; border-radius:6px; font-weight:700; font-size:0.8rem; margin-left:4px;">
                            ✓ Activo
                        </span>
                    </div>
                </div>
            `;

            document.getElementById('conf-resumen').innerHTML = resumenHtml;
            document.getElementById('modal-confirmar-guardar').classList.add('active');
            return false;
        }

        function cerrarModalConfirmar(e) {
            if (e && e.target !== e.currentTarget) return;
            document.getElementById('modal-confirmar-guardar').classList.remove('active');
        }

        function ejecutarGuardarUsuario() {
            usuarioFormConfirmado = true;
            document.getElementById('modal-confirmar-guardar').classList.remove('active');
            document.getElementById('form-alta-usuario').submit();
        }

        function mostrarAlerta(mensaje, titulo = 'Atención') {
            document.getElementById('modal-alerta-titulo').textContent = titulo;
            document.getElementById('modal-alerta-mensaje').textContent = mensaje;
            document.getElementById('modal-alerta').classList.add('active');
        }

        function cerrarModalAlerta(e) {
            if (e && e.target !== e.currentTarget) return;
            document.getElementById('modal-alerta').classList.remove('active');
        }

        document.getElementById('form-alta-usuario').addEventListener('submit', function(e) {
            confirmarRegistroUsuario(e);
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                cerrarModalConfirmar();
                cerrarModalAlerta();
            }
        });
    </script>
    <script src="js/sidebar.js"></script>
</body>
</html>
