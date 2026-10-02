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

// Si ya tiene sesión activa, redirigir automáticamente al dashboard
if ($auth->checkAuth()) {
    header("Location: dashboard.php");
    exit;
}

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $correo = trim($_POST['correo'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($auth->login($correo, $password)) {
        header("Location: dashboard.php");
        exit;
    } else {
        $error = "Credenciales incorrectas o usuario inactivo.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lavandería | Iniciar Sesión</title>
    <meta name="description" content="Sistema de gestión de lavandería">
    <link rel="stylesheet" href="css/index.css">
</head>
<body class="login-body">
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <h1>Lavandería Premium</h1>
                <p>Ingresa tus credenciales para continuar</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label" for="correo">Usuario o Correo Electrónico</label>
                    <input type="text" id="correo" name="correo" class="form-control" required autofocus placeholder="Usuario o correo electrónico">
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="password">Contraseña</label>
                    <input type="password" id="password" name="password" class="form-control" required placeholder="••••••••">
                </div>

                <button type="submit" class="btn-primary">Iniciar Sesión</button>
            </form>
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
