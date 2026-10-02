<?php
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../src/modules/auth/AuthService.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;

$db = (new Database())->getConnection();
$auth = new AuthService($db);

if (!$auth->checkAuth()) {
    header("Location: login.php");
    exit;
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (isset($_GET['logout'])) {
    $auth->logout();
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard | Lavandería</title>
    <link rel="stylesheet" href="css/index.css">
</head>
<body>
    <div class="app-container">
        <?php $activePage = 'dashboard'; require_once __DIR__ . '/partials/sidebar.php'; ?>
        
        <main class="main-content">
            <header style="margin-bottom: 3rem;">
                <h1 style="font-size: 2rem;">Hola, <?= htmlspecialchars($_SESSION['nombre']) ?> 👋</h1>
                <p style="color: var(--text-muted);">Has iniciado sesión como <strong><?= htmlspecialchars($_SESSION['rol']) ?></strong></p>
            </header>
            
            <div class="auth-card" style="width: 100%; max-width: none;">
                <h3>Resumen Rápido</h3>
                <p style="color: var(--text-muted); margin-top: 1rem;">El sistema de autenticación funciona correctamente. La información de sesión y auditoría de la última actividad han sido registradas en la base de datos.</p>
            </div>
        </main>
    </div>
    <script src="js/sidebar.js"></script>
</body>
</html>
