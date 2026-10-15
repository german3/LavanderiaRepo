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
            
            <!-- Card Acceso Directo FrontOffice (Punto de Venta) -->
            <div style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(59, 130, 246, 0.15)); border: 1px solid rgba(16, 185, 129, 0.4); border-radius: 20px; padding: 2rem; margin-bottom: 2rem; display: flex; align-items: center; justify-content: space-between; gap: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.3);">
                <div>
                    <span style="font-size: 0.75rem; font-weight: 800; background: #10b981; color: #042f2e; padding: 0.25rem 0.6rem; border-radius: 9999px; text-transform: uppercase; letter-spacing: 0.05em;">FrontOffice Independiente</span>
                    <h2 style="font-size: 1.5rem; font-weight: 700; margin-top: 0.5rem; color: #fff;">Punto de Venta y Caja</h2>
                    <p style="color: var(--text-muted); font-size: 0.95rem; margin-top: 0.35rem; max-width: 600px;">
                        Módulo exclusivo para cajeros y mostrador. Cobra servicios, gestiona kits, pagos posteriores, entregas de lavandería, arqueos y cortes de caja.
                    </p>
                </div>
                <div>
                    <a href="pos.php" target="_blank" style="display: inline-flex; align-items: center; gap: 0.75rem; background: linear-gradient(135deg, #10b981, #059669); color: #fff; font-size: 1.05rem; font-weight: 700; padding: 1rem 1.75rem; border-radius: 12px; text-decoration: none; box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4); transition: all 0.2s; white-space: nowrap;">
                        <svg style="width: 22px; height: 22px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                        <span>Abrir Punto de Venta ↗</span>
                    </a>
                </div>
            </div>

            <div class="auth-card" style="width: 100%; max-width: none;">
                <h3>Resumen Rápido</h3>
                <p style="color: var(--text-muted); margin-top: 1rem;">El sistema de autenticación funciona correctamente. La información de sesión y auditoría de la última actividad han sido registradas en la base de datos.</p>
            </div>
        </main>
    </div>
    <script src="js/sidebar.js"></script>
</body>
</html>
