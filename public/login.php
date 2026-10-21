<?php
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../src/modules/auth/AuthService.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$db = (new Database())->getConnection();
$auth = new AuthService($db);

$vieneDePos = (!empty($_GET['from']) && $_GET['from'] === 'pos') || (!empty($_POST['from_pos']) && $_POST['from_pos'] === '1');

// Si viene desde el POS en GET (al hacer clic en el botón de BackOffice),
// forzar SIEMPRE el cierre de sesión de BackOffice previo para obligar a que se autentique
if (!empty($_GET['from']) && $_GET['from'] === 'pos' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $auth->logout();
} elseif ($auth->canAccessBackoffice() && empty($_GET['from'])) {
    // Si ya tiene sesión activa con permisos de Administrador y no viene del POS, redirigir al dashboard
    header("Location: dashboard.php");
    exit;
}

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $correo   = trim($_POST['correo'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($correo === '' || $password === '') {
        $error = "Por favor ingresa usuario/correo y contraseña.";
    } else {
        $res = $auth->loginBackoffice($correo, $password);
        if ($res['success']) {
            // Reemplazar historial para que el botón atrás no regrese a esta página de login
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><script>window.location.replace("dashboard.php");</script></head><body></body></html>';
            exit;
        } else {
            $error = $res['error'] ?? "Credenciales incorrectas o usuario inactivo.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BackOffice | Iniciar Sesión</title>
    <meta name="description" content="Acceso a la administración de lavandería">
    <link rel="icon" type="image/jpeg" href="img/Logo.jpeg">
    <link rel="stylesheet" href="css/index.css">
</head>
<body class="login-body">
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <h1>Lavandería Premium</h1>
                <p>Acceso a <strong>BackOffice (Administración)</strong></p>
            </div>

            <?php if ($vieneDePos): ?>
                <div style="background: rgba(59, 130, 246, 0.12); border: 1px solid rgba(59, 130, 246, 0.35); border-radius: 10px; padding: 0.85rem 1rem; margin-bottom: 1.25rem; font-size: 0.85rem; color: #93c5fd; display: flex; align-items: flex-start; gap: 0.65rem; line-height: 1.4;">
                    <svg style="width:20px;height:20px;flex-shrink:0;margin-top:2px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <div>
                        <strong>Acceso protegido desde el POS</strong>
                        <div style="font-size:0.78rem; color:#bfdbfe; margin-top:0.15rem;">
                            Ingresa con las credenciales de una cuenta con rol <strong>Administrador</strong> para acceder a la administración.
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error" style="margin-bottom: 1.25rem;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" autocomplete="off">
                <?php if ($vieneDePos): ?>
                    <input type="hidden" name="from_pos" value="1">
                <?php endif; ?>
                <div class="form-group">
                    <label class="form-label" for="correo">Usuario o Correo Electrónico</label>
                    <input type="text" id="correo" name="correo" class="form-control" required autofocus placeholder="Usuario o correo electrónico">
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="password">Contraseña</label>
                    <input type="password" id="password" name="password" class="form-control" required placeholder="••••••••">
                </div>

                <button type="submit" class="btn-primary" style="margin-top: 0.5rem;">Iniciar Sesión en BackOffice</button>
            </form>

            <div style="margin-top: 1.5rem; text-align: center; padding-top: 1.25rem; border-top: 1px solid rgba(255,255,255,0.08);">
                <a href="pos.php" style="color: #94a3b8; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 0.45rem; transition: color 0.2s;" onmouseover="this.style.color='#38bdf8'" onmouseout="this.style.color='#94a3b8'">
                    <svg style="width:16px;height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    <span>Volver al Punto de Venta (POS)</span>
                </a>
            </div>
        </div>
    </div>
    <script>
        // Prevenir carga desde la memoria caché del navegador al presionar atrás
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                window.location.reload();
            }
        });
    </script>
</body>
</html>
