<?php
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../src/modules/auth/AuthService.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;

$db = (new Database())->getConnection();
$auth = new AuthService($db);

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $correo = $_POST['correo'] ?? '';
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
                    <label class="form-label" for="correo">Correo Electrónico</label>
                    <input type="email" id="correo" name="correo" class="form-control" required autofocus placeholder="ejemplo@lavanderia.com">
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="password">Contraseña</label>
                    <input type="password" id="password" name="password" class="form-control" required placeholder="••••••••">
                </div>

                <button type="submit" class="btn-primary">Iniciar Sesión</button>
            </form>
        </div>
    </div>
</body>
</html>
