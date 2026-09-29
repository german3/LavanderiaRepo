<?php
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../src/modules/auth/AuthService.php';
require_once __DIR__ . '/../src/modules/catalog/Producto.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;
use App\Modules\Catalog\Producto;

$db = (new Database())->getConnection();
$auth = new AuthService($db);
$productoModel = new Producto($db);

// Middleware
if (!$auth->checkAuth()) {
    header("Location: login.php");
    exit;
}

$mensaje = "";
$error = "";

// Regla de Negocio: Edición/Creación restringida (Admin para costos/precios)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'crear') {
    if (!$auth->isAdmin()) {
        $error = "Solo los administradores pueden registrar nuevos productos y costos.";
    } else {
        $productoModel->descripcion = $_POST['descripcion'];
        $productoModel->codigo_barras = $_POST['codigo_barras'] ?? '';
        $productoModel->codigo_interno_sku = $_POST['codigo_interno_sku'] ?? '';
        $productoModel->tipo = $_POST['tipo'];
        $productoModel->unidad_medida = $_POST['unidad_medida'];
        $productoModel->costo = $_POST['costo'];
        $productoModel->precio_venta = $_POST['precio_venta'];
        $productoModel->estado = 'ACTIVO';
        
        // RELACIÓN CON EL USUARIO ACTUAL (Auditoría/Registro)
        $productoModel->usuario_registro_id = $_SESSION['usuario_id'];
        
        if($productoModel->crear()) {
            $mensaje = "Producto registrado exitosamente.";
        } else {
            $error = "Error al crear el producto. Revisa los datos.";
        }
    }
}

// Obtener catálogo
$productos = $productoModel->obtenerTodos();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo de Productos | Lavandería</title>
    <link rel="stylesheet" href="css/index.css">
    <style>
        .table { width: 100%; border-collapse: collapse; margin-top: 2rem; color: var(--text-main); }
        .table th, .table td { padding: 1rem; text-align: left; border-bottom: 1px solid var(--border); }
        .badge { padding: 0.25rem 0.75rem; border-radius: 99px; font-size: 0.75rem; font-weight: 600; }
        .badge-normal { background: rgba(59, 130, 246, 0.2); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.3); }
        .badge-kit { background: rgba(139, 92, 246, 0.2); color: #c4b5fd; border: 1px solid rgba(139, 92, 246, 0.3); }
        .badge-servicio { background: rgba(16, 185, 129, 0.2); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.3); }
    </style>
</head>
<body>
    <div class="app-container">
        <aside class="sidebar">
            <h2 style="font-size: 1.25rem; color: #fff; margin-bottom: 2rem;">Lavandería App</h2>
            <nav>
                <a href="dashboard.php" style="display:block;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);">Dashboard</a>
                <a href="productos.php" style="display:block;color:var(--primary);font-weight:600;text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);">Catálogo de Productos</a>
                <a href="clientes.php" style="display:block;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);">Clientes</a>
                <?php if($auth->isAdmin()): ?>
                <a href="usuarios.php" style="display:block;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);">Usuarios y Roles</a>
                <?php endif; ?>
                <a href="dashboard.php?logout=1" style="display:block;color:#f87171;text-decoration:none;padding:0.75rem 0;margin-top:2rem;">Cerrar Sesión</a>
            </nav>
        </aside>
        
        <main class="main-content">
            <header style="margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1 style="font-size: 2rem;">Catálogo</h1>
                    <p style="color: var(--text-muted);">Administra productos, servicios y kits.</p>
                </div>
            </header>

            <?php if ($mensaje): ?>
                <div class="alert" style="background: rgba(16, 185, 129, 0.1); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.2); padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem;">
                    <?= htmlspecialchars($mensaje) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div style="display: flex; flex-direction: column; gap: 2rem;">
                <!-- Formulario -->
                <div class="auth-card" style="width: 100%; max-width: 100%; padding: 2rem;">
                    <h3 style="margin-bottom: 1.5rem;">Nuevo Elemento</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="crear">
                        <div class="form-group">
                            <label class="form-label">Descripción</label>
                            <input type="text" name="descripcion" class="form-control" required placeholder="Ej: Jabón Líquido / Carga de Ropa">
                        </div>
                        <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div>
                                <label class="form-label">Código Barras</label>
                                <input type="text" name="codigo_barras" class="form-control" placeholder="(Auto si vacío)">
                            </div>
                            <div>
                                <label class="form-label">SKU</label>
                                <input type="text" name="codigo_interno_sku" class="form-control" placeholder="Opcional">
                            </div>
                        </div>
                        <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div>
                                <label class="form-label">Tipo</label>
                                <select name="tipo" class="form-control" required>
                                    <option value="NORMAL">Normal</option>
                                    <option value="SERVICIO">Servicio</option>
                                    <option value="KIT">Kit / Paquete</option>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">Unidad</label>
                                <select name="unidad_medida" class="form-control" required>
                                    <option value="PIEZA">Pieza</option>
                                    <option value="LITRO">Litro</option>
                                    <option value="MILILITRO">Mililitro</option>
                                    <option value="KG">Kilogramo</option>
                                    <option value="CARGA">Carga</option>
                                    <option value="SERVICIO">Servicio</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div>
                                <label class="form-label">Costo ($)</label>
                                <input type="number" step="0.01" name="costo" class="form-control" value="0.00" required>
                            </div>
                            <div>
                                <label class="form-label">Precio Venta ($)</label>
                                <input type="number" step="0.01" name="precio_venta" class="form-control" value="0.00" required>
                            </div>
                        </div>
                        <button type="submit" class="btn-primary" style="padding: 0.75rem;">Registrar en Catálogo</button>
                    </form>
                </div>

                <!-- Lista -->
                <div class="auth-card" style="width: 100%; max-width: 100%; padding: 2rem; overflow-x: auto;">
                    <h3 style="margin-bottom: 0;">Elementos Registrados</h3>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Cód / SKU</th>
                                <th>Descripción</th>
                                <th>Tipo</th>
                                <th>Precio</th>
                                <th>Registrado por</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($productos as $p): ?>
                            <tr>
                                <td style="font-size: 0.85rem; color: var(--text-muted);">
                                    <?= htmlspecialchars($p['codigo_barras'] ?? '') ?><br>
                                    <small><?= htmlspecialchars($p['codigo_interno_sku'] ?? '') ?></small>
                                </td>
                                <td><?= htmlspecialchars($p['descripcion']) ?></td>
                                <td>
                                    <?php 
                                        $badgeClass = 'badge-normal';
                                        if($p['tipo'] == 'KIT') $badgeClass = 'badge-kit';
                                        if($p['tipo'] == 'SERVICIO') $badgeClass = 'badge-servicio';
                                    ?>
                                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($p['tipo']) ?></span><br>
                                    <small style="color: var(--text-muted); font-size: 0.75rem;"><?= htmlspecialchars($p['unidad_medida']) ?></small>
                                </td>
                                <td>$<?= number_format($p['precio_venta'], 2) ?></td>
                                <td style="font-size: 0.85rem; color: var(--text-muted);">
                                    <?= htmlspecialchars($p['usuario_nombre'] ?? 'Desconocido') ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
