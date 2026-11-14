<?php
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../src/modules/auth/AuthService.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;
use App\Modules\Auth\Usuario;

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$db   = (new Database())->getConnection();
$auth = new AuthService($db);

// Si ya tiene sesión activa en POS y no solicitó cambio, redirigir a pos.php sin guardar historial
if ($auth->checkAuthPos() && empty($_GET['cambiar'])) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><script>window.location.replace("pos.php");</script></head><body></body></html>';
    exit;
}

// Si solicitó cambiar usuario, cerrar la sesión actual de POS primero
if (!empty($_GET['cambiar'])) {
    $auth->logoutPos();
}

// Consultar si existe un turno de caja abierto en el sistema
$cajaAbierta = null;
try {
    $stmtCaja = $db->query("
        SELECT c.id, c.usuario_id, c.fecha_apertura, c.monto_inicial,
               u.nombre AS cajero_nombre, u.correo AS cajero_correo, u.rol AS cajero_rol
        FROM caja_cortes c
        INNER JOIN usuarios u ON c.usuario_id COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci
        WHERE c.estado = 'ABIERTA'
        ORDER BY c.fecha_apertura DESC LIMIT 1
    ");
    $cajaAbierta = $stmtCaja ? $stmtCaja->fetch(PDO::FETCH_ASSOC) : null;
} catch (\Throwable $e) {
    $cajaAbierta = null;
}

$error = '';
$adminModeActive = !empty($_POST['admin_mode']);
$backOfficeUser = null;
if ($auth->checkAuth()) {
    $backOfficeUser = [
        'nombre' => $_SESSION['nombre'] ?? 'Usuario',
        'rol'    => $_SESSION['rol'] ?? 'ADMINISTRADOR'
    ];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $usuarioModel = new Usuario($db);

    if ($cajaAbierta) {
        // Existe un turno abierto pendiente de cierre
        if (!empty($_POST['admin_mode'])) {
            // Intento de ingreso mediante acceso de Administrador
            $adminUser = trim($_POST['admin_correo'] ?? '');
            $adminPass = $_POST['admin_password'] ?? '';

            if ($adminUser === '' || $adminPass === '') {
                $error = "Por favor ingresa usuario/correo y contraseña de Administrador.";
                $adminModeActive = true;
            } else {
                $userFound = $usuarioModel->buscarPorCorreo($adminUser);
                if (!$userFound || !password_verify($adminPass, $userFound['password_hash'])) {
                    $error = "Credenciales de Administrador incorrectas o usuario no encontrado.";
                    $adminModeActive = true;
                } elseif ($userFound['estado'] !== 'ACTIVO') {
                    $error = "La cuenta de Administrador se encuentra inactiva.";
                    $adminModeActive = true;
                } elseif ($userFound['rol'] !== 'ADMINISTRADOR') {
                    $error = "Acceso denegado: El usuario ingresado no tiene rol de Administrador. Solo un Administrador puede ingresar al turno de otro empleado.";
                    $adminModeActive = true;
                } else {
                    // Login exitoso como Administrador
                    if ($auth->loginPos($userFound['correo'], $adminPass)) {
                        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><script>window.location.replace("pos.php");</script></head><body></body></html>';
                        exit;
                    } else {
                        $error = "Error al autenticar sesión de Administrador.";
                        $adminModeActive = true;
                    }
                }
            }
        } else {
            // Reanudación exclusiva del titular del turno (cajero asignado a la caja abierta)
            $correoCajero = $cajaAbierta['cajero_correo'];
            $password     = $_POST['password'] ?? '';

            if ($password === '') {
                $error = "Por favor ingresa tu contraseña para reanudar el turno.";
            } elseif ($auth->loginPos($correoCajero, $password)) {
                echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><script>window.location.replace("pos.php");</script></head><body></body></html>';
                exit;
            } else {
                $error = "Contraseña incorrecta para el cajero " . htmlspecialchars($cajaAbierta['cajero_nombre']) . ".";
            }
        }
    } else {
        // No hay turno abierto: login estándar de cualquier usuario activo
        $correo   = trim($_POST['correo'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($correo === '' || $password === '') {
            $error = "Por favor ingresa usuario/correo y contraseña.";
        } elseif ($auth->loginPos($correo, $password)) {
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><script>window.location.replace("pos.php");</script></head><body></body></html>';
            exit;
        } else {
            $error = "Credenciales incorrectas o usuario inactivo.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Punto de Venta | Acceso FrontOffice</title>
    <link rel="icon" type="image/jpeg" href="img/Logo.jpeg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --pos-bg: #0b0f19;
            --pos-surface: #111827;
            --pos-surface-card: rgba(17, 24, 39, 0.95);
            --pos-border: rgba(255, 255, 255, 0.08);
            --pos-border-focus: #10b981;
            --pos-text: #f9fafb;
            --pos-text-muted: #9ca3af;
            --pos-emerald: #10b981;
            --pos-emerald-hover: #059669;
            --pos-radius: 14px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        body {
            background-color: var(--pos-bg);
            color: var(--pos-text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            position: relative;
            overflow-x: hidden;
        }

        /* Fondo decorativo con luces sutiles */
        body::before {
            content: '';
            position: absolute;
            width: 450px;
            height: 450px;
            background: radial-gradient(circle, rgba(16, 185, 129, 0.12) 0%, rgba(11, 15, 25, 0) 70%);
            top: 10%;
            left: 20%;
            z-index: 0;
            pointer-events: none;
        }

        body::after {
            content: '';
            position: absolute;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(6, 182, 212, 0.08) 0%, rgba(11, 15, 25, 0) 70%);
            bottom: 10%;
            right: 20%;
            z-index: 0;
            pointer-events: none;
        }

        .pos-login-container {
            width: 100%;
            max-width: 460px;
            position: relative;
            z-index: 1;
        }

        .pos-login-card {
            background: var(--pos-surface-card);
            backdrop-filter: blur(20px);
            border: 1px solid var(--pos-border);
            border-radius: var(--pos-radius);
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.7), 0 0 0 1px rgba(255, 255, 255, 0.05);
            padding: 2.25rem;
            animation: fadeIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .pos-login-brand {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.25rem;
            padding-bottom: 1.15rem;
            border-bottom: 1px solid var(--pos-border);
        }

        .pos-login-logo {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            object-fit: cover;
            border: 2px solid rgba(16, 185, 129, 0.35);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.2);
        }

        .pos-login-titles h1 {
            font-size: 1.25rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.02em;
        }

        .pos-badge-front {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.7rem;
            font-weight: 800;
            background: rgba(16, 185, 129, 0.18);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.35);
            padding: 0.15rem 0.5rem;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-top: 0.25rem;
        }

        .pos-badge-front::before {
            content: '';
            width: 6px;
            height: 6px;
            background: #10b981;
            border-radius: 50%;
            box-shadow: 0 0 8px #10b981;
        }

        .pos-subtitle {
            font-size: 0.85rem;
            color: var(--pos-text-muted);
            margin-bottom: 1.25rem;
            line-height: 1.4;
        }

        /* Banner de turno abierto / bloqueo */
        .pos-shift-notice {
            background: rgba(245, 158, 11, 0.12);
            border: 1px solid rgba(245, 158, 11, 0.35);
            border-radius: 12px;
            padding: 0.85rem 1rem;
            margin-bottom: 1.35rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .pos-shift-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: rgba(245, 158, 11, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: #fbbf24;
            margin-top: 2px;
        }

        .pos-shift-title {
            color: #fbbf24;
            font-weight: 800;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .pos-shift-desc {
            color: #cbd5e1;
            font-size: 0.78rem;
            margin-top: 0.25rem;
            line-height: 1.35;
        }

        /* Notificación Backoffice */
        .pos-backoffice-notice {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.25);
            border-radius: 10px;
            padding: 0.75rem 0.9rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
            font-size: 0.8rem;
            color: #93c5fd;
            line-height: 1.35;
        }

        .pos-backoffice-notice svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
            margin-top: 1px;
            color: #60a5fa;
        }

        .pos-alert-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.35);
            color: #fca5a5;
            padding: 0.75rem 1rem;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            line-height: 1.35;
        }

        /* Tarjeta de usuario bloqueado */
        .locked-user-card {
            background: rgba(15, 23, 42, 0.75);
            border: 1.5px solid rgba(245, 158, 11, 0.35);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.25rem;
            gap: 0.75rem;
        }

        .locked-user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 0;
        }

        .locked-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(245,158,11,0.25), rgba(245,158,11,0.1));
            border: 1.5px solid #f59e0b;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fbbf24;
            font-weight: 800;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .locked-name {
            color: #fff;
            font-weight: 700;
            font-size: 0.92rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .locked-meta {
            color: var(--pos-text-muted);
            font-size: 0.75rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .locked-badge {
            font-size: 0.72rem;
            color: #fbbf24;
            background: rgba(245, 158, 11, 0.15);
            border: 1px solid rgba(245, 158, 11, 0.35);
            padding: 3px 8px;
            border-radius: 6px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 4px;
            flex-shrink: 0;
            letter-spacing: 0.02em;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--pos-text-muted);
            margin-bottom: 0.45rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            width: 18px;
            height: 18px;
            color: var(--pos-text-muted);
            pointer-events: none;
            transition: color 0.2s;
        }

        .form-input {
            width: 100%;
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid var(--pos-border);
            border-radius: 10px;
            padding: 0.85rem 1rem 0.85rem 2.75rem;
            font-size: 0.95rem;
            color: #fff;
            transition: all 0.2s ease;
            outline: none;
        }

        .form-input:focus {
            border-color: var(--pos-border-focus);
            background: rgba(15, 23, 42, 0.95);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
        }

        .form-input:focus + .input-icon,
        .input-wrapper:focus-within .input-icon {
            color: #34d399;
        }

        .btn-toggle-pass {
            position: absolute;
            right: 0.75rem;
            background: none;
            border: none;
            color: var(--pos-text-muted);
            cursor: pointer;
            padding: 0.35rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            transition: color 0.2s;
        }

        .btn-toggle-pass:hover {
            color: #fff;
        }

        .btn-submit-pos {
            width: 100%;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 0.95rem 1.5rem;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.65rem;
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.35);
            transition: all 0.2s ease;
            margin-top: 1.25rem;
        }

        .btn-submit-pos:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-1px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.45);
        }

        .btn-submit-pos:active {
            transform: translateY(0);
        }

        .btn-submit-admin {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.35);
        }

        .btn-submit-admin:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.45);
        }

        .pos-login-footer {
            margin-top: 1.5rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--pos-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.8rem;
        }

        .pos-login-footer a {
            color: #93c5fd;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            transition: color 0.2s;
        }

        .pos-login-footer a:hover {
            color: #bfdbfe;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="pos-login-container">
        <div class="pos-login-card">
            <!-- Brand -->
            <div class="pos-login-brand">
                <img src="img/Logo.jpeg" alt="Logo Lavandería Vera" class="pos-login-logo">
                <div class="pos-login-titles">
                    <h1>Lavandería Vera</h1>
                    <span class="pos-badge-front">FrontOffice POS</span>
                </div>
            </div>

            <?php if ($cajaAbierta): ?>
                <!-- Turno de caja abierto en el sistema -->
                <div class="pos-shift-notice">
                    <div class="pos-shift-icon">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    </div>
                    <div>
                        <div class="pos-shift-title">
                            Turno de Caja Abierto en Espera
                        </div>
                        <div class="pos-shift-desc">
                            Existe un turno activo a nombre de <strong><?= htmlspecialchars($cajaAbierta['cajero_nombre']) ?></strong>. Para proteger el arqueo de caja, solo el cajero titular o un Administrador pueden ingresar.
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <p class="pos-subtitle">
                    Ingresa con las credenciales del cajero para operar la terminal de punto de venta y control de caja.
                </p>
            <?php endif; ?>

            <?php if (!empty($_GET['temporal'])): ?>
                <div style="background:rgba(16, 185, 129, 0.12); border:1px solid rgba(16, 185, 129, 0.3); border-radius:10px; padding:0.75rem 0.9rem; margin-bottom:1.25rem; font-size:0.8rem; color:#6ee7b7; display:flex; align-items:center; gap:0.5rem;">
                    <span>🔒</span>
                    <span>Has salido temporalmente. Tu turno permanece seguro y abierto bajo tu usuario.</span>
                </div>
            <?php endif; ?>

            <?php if ($backOfficeUser): ?>
                <div class="pos-backoffice-notice">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <div>
                        Sesión activa en BackOffice como <strong><?= htmlspecialchars($backOfficeUser['nombre']) ?></strong>.
                        <div style="margin-top:0.15rem; font-size:0.75rem; color:#cbd5e1;">
                            El POS requiere su propio inicio de sesión para asociar la caja y las ventas al cajero correspondiente.
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="pos-alert-error">
                    <svg style="width:20px;height:20px;flex-shrink:0;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($cajaAbierta): ?>
                <!-- ══════════════════════════════════════════════════════════
                     CASO 1: TURNO ACTIVO PENDIENTE
                     ══════════════════════════════════════════════════════════ -->

                <!-- VISTA A: REANUDAR CAJERO TITULAR (Bloqueado) -->
                <div id="vista-cajero" style="<?= $adminModeActive ? 'display:none;' : 'display:block;' ?>">
                    <form method="POST" action="pos_login.php" autocomplete="off">
                        <div class="form-group">
                            <label class="form-label">Cajero en Turno</label>
                            <div class="locked-user-card">
                                <div class="locked-user-info">
                                    <div class="locked-avatar">
                                        <?= strtoupper(substr($cajaAbierta['cajero_nombre'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="locked-name"><?= htmlspecialchars($cajaAbierta['cajero_nombre']) ?></div>
                                        <div class="locked-meta">
                                            <?= htmlspecialchars($cajaAbierta['cajero_correo']) ?> • <?= htmlspecialchars($cajaAbierta['cajero_rol']) ?>
                                        </div>
                                    </div>
                                </div>
                                <span class="locked-badge" title="Este turno está asignado exclusivamente a este usuario">
                                    🔒 Bloqueado
                                </span>
                            </div>
                            <!-- Usuario fijado sin posibilidad de modificación -->
                            <input type="hidden" name="correo" value="<?= htmlspecialchars($cajaAbierta['cajero_correo']) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="pos-password">Contraseña de <?= htmlspecialchars($cajaAbierta['cajero_nombre']) ?></label>
                            <div class="input-wrapper">
                                <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                                <input type="password" id="pos-password" name="password" class="form-input" required autofocus placeholder="Ingresa tu contraseña para reanudar" style="padding-right: 2.75rem;">
                                <button type="button" class="btn-toggle-pass" onclick="togglePasswordVisibility('pos-password', 'eye-icon-cajero')" title="Mostrar u ocultar contraseña">
                                    <svg id="eye-icon-cajero" style="width:18px;height:18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="btn-submit-pos">
                            <svg style="width:20px;height:20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>
                            <span>Reanudar Turno</span>
                        </button>
                    </form>

                    <!-- Opción de Acceso para Administrador -->
                    <div style="margin-top:1.35rem; padding-top:1.15rem; border-top:1px dashed var(--pos-border); text-align:center;">
                        <button type="button" onclick="mostrarModoAdmin()" style="background:none; border:none; color:#60a5fa; font-size:0.83rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:0.45rem; padding:0.5rem 0.85rem; border-radius:8px; transition:all 0.2s;" onmouseover="this.style.background='rgba(59,130,246,0.1)'; this.style.color='#93c5fd';" onmouseout="this.style.background='none'; this.style.color='#60a5fa';">
                            <svg style="width:16px;height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            <span>¿Eres Administrador? Ingresar como Administrador</span>
                        </button>
                    </div>
                </div>

                <!-- VISTA B: ACCESO DE ADMINISTRADOR OVERRIDE -->
                <div id="vista-admin" style="<?= $adminModeActive ? 'display:block;' : 'display:none;' ?>">
                    <div style="background:rgba(59, 130, 246, 0.12); border:1px solid rgba(59, 130, 246, 0.35); border-radius:10px; padding:0.85rem 1rem; margin-bottom:1.25rem;">
                        <div style="color:#93c5fd; font-weight:700; font-size:0.85rem; display:flex; align-items:center; gap:0.4rem;">
                            🛡️ Acceso Exclusivo de Administrador
                        </div>
                        <div style="color:#cbd5e1; font-size:0.78rem; margin-top:0.25rem; line-height:1.4;">
                            Ingresa tus credenciales de Administrador para supervisar o realizar el corte y cierre de caja del turno de <strong><?= htmlspecialchars($cajaAbierta['cajero_nombre']) ?></strong>.
                        </div>
                    </div>

                    <form method="POST" action="pos_login.php" autocomplete="off">
                        <input type="hidden" name="admin_mode" value="1">

                        <div class="form-group">
                            <label class="form-label" for="admin-usuario">Usuario o Correo de Administrador</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                <input type="text" id="admin-usuario" name="admin_correo" class="form-input" required placeholder="Nombre de usuario o correo de Administrador">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="admin-password">Contraseña de Administrador</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                                <input type="password" id="admin-password" name="admin_password" class="form-input" required placeholder="••••••••" style="padding-right: 2.75rem;">
                                <button type="button" class="btn-toggle-pass" onclick="togglePasswordVisibility('admin-password', 'eye-icon-admin')" title="Mostrar u ocultar contraseña">
                                    <svg id="eye-icon-admin" style="width:18px;height:18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="btn-submit-pos btn-submit-admin">
                            <svg style="width:20px;height:20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            <span>Ingresar como Administrador</span>
                        </button>
                    </form>

                    <div style="margin-top:1.25rem; text-align:center;">
                        <button type="button" onclick="mostrarModoCajero()" style="background:none; border:none; color:#94a3b8; font-size:0.83rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:0.4rem; padding:0.45rem 0.85rem; border-radius:8px; transition:all 0.2s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='#94a3b8'">
                            <span>← Volver al acceso de <?= htmlspecialchars($cajaAbierta['cajero_nombre']) ?></span>
                        </button>
                    </div>
                </div>

            <?php else: ?>
                <!-- ══════════════════════════════════════════════════════════
                     CASO 2: NO HAY TURNO ABIERTO (Login Estándar)
                     ══════════════════════════════════════════════════════════ -->
                <form method="POST" action="pos_login.php" autocomplete="off">
                    <div class="form-group">
                        <label class="form-label" for="pos-usuario">Usuario o Correo</label>
                        <div class="input-wrapper">
                            <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            <input type="text" id="pos-usuario" name="correo" class="form-input" required autofocus placeholder="Nombre de usuario o correo">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="pos-password">Contraseña</label>
                        <div class="input-wrapper">
                            <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                            <input type="password" id="pos-password" name="password" class="form-input" required placeholder="••••••••" style="padding-right: 2.75rem;">
                            <button type="button" class="btn-toggle-pass" onclick="togglePasswordVisibility('pos-password', 'eye-icon-normal')" title="Mostrar u ocultar contraseña">
                                <svg id="eye-icon-normal" style="width:18px;height:18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit-pos">
                        <svg style="width:20px;height:20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                        <span>Entrar al Punto de Venta</span>
                    </button>
                </form>
            <?php endif; ?>

            <div class="pos-login-footer">
                <span style="color:var(--pos-text-muted);">Terminal Mostrador</span>
                <a href="<?= $backOfficeUser ? 'dashboard.php' : 'login.php' ?>">
                    <svg style="width:15px;height:15px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    <span>Volver al BackOffice</span>
                </a>
            </div>
        </div>
    </div>

    <script>
        function togglePasswordVisibility(inputId, iconId) {
            const passInput = document.getElementById(inputId);
            const eyeIcon = document.getElementById(iconId);
            if (!passInput || !eyeIcon) return;
            if (passInput.type === 'password') {
                passInput.type = 'text';
                eyeIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/>';
            } else {
                passInput.type = 'password';
                eyeIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>';
            }
        }

        function mostrarModoAdmin() {
            const vCajero = document.getElementById('vista-cajero');
            const vAdmin = document.getElementById('vista-admin');
            if (vCajero) vCajero.style.display = 'none';
            if (vAdmin) {
                vAdmin.style.display = 'block';
                const admInput = document.getElementById('admin-usuario');
                if (admInput) admInput.focus();
            }
        }

        function mostrarModoCajero() {
            const vCajero = document.getElementById('vista-cajero');
            const vAdmin = document.getElementById('vista-admin');
            if (vAdmin) vAdmin.style.display = 'none';
            if (vCajero) {
                vCajero.style.display = 'block';
                const passInput = document.getElementById('pos-password');
                if (passInput) passInput.focus();
            }
        }
    </script>
</body>
</html>
