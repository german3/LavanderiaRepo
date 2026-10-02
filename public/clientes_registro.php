<?php
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../src/modules/auth/AuthService.php';
require_once __DIR__ . '/../src/modules/customers/Cliente.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;
use App\Modules\Customers\Cliente;

$db = (new Database())->getConnection();
$auth = new AuthService($db);
$clienteModel = new Cliente($db);

// Middleware de autenticación
if (!$auth->checkAuth()) {
    header("Location: login.php");
    exit;
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$mensaje = "";
$error = "";
$modo = $_GET['modo'] ?? 'registro'; // registro | editar
$clienteEditar = null;

if ($modo === 'editar' && isset($_GET['id'])) {
    $clienteEditar = $clienteModel->obtenerPorId($_GET['id']);
}

// Procesar formulario
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';

    if ($action === 'crear') {
        $clienteModel->nombre = trim($_POST['nombre'] ?? '');
        $clienteModel->telefono = trim($_POST['telefono'] ?? '');
        $clienteModel->whatsapp_disponible = isset($_POST['whatsapp_disponible']) ? true : false;
        $clienteModel->correo = trim($_POST['correo'] ?? '') ?: null;
        $clienteModel->direccion = trim($_POST['direccion'] ?? '') ?: null;
        $clienteModel->observaciones = trim($_POST['observaciones'] ?? '') ?: null;
        $clienteModel->estado = 'ACTIVO';

        if ($clienteModel->crear()) {
            $mensaje = "Cliente registrado exitosamente.";
        } else {
            $error = "Error al registrar el cliente. Verifica los datos ingresados.";
        }
    }

    if ($action === 'actualizar') {
        $clienteModel->id = $_POST['id'] ?? '';
        $clienteModel->nombre = trim($_POST['nombre'] ?? '');
        $clienteModel->telefono = trim($_POST['telefono'] ?? '');
        $clienteModel->whatsapp_disponible = isset($_POST['whatsapp_disponible']) ? true : false;
        $clienteModel->correo = trim($_POST['correo'] ?? '') ?: null;
        $clienteModel->direccion = trim($_POST['direccion'] ?? '') ?: null;
        $clienteModel->observaciones = trim($_POST['observaciones'] ?? '') ?: null;

        if ($clienteModel->actualizar()) {
            $mensaje = "Cliente actualizado correctamente.";
            $clienteEditar = $clienteModel->obtenerPorId($clienteModel->id);
        } else {
            $error = "Error al actualizar el cliente.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $modo === 'editar' ? 'Editar Cliente' : 'Registro de Clientes' ?> | Lavandería</title>
    <link rel="stylesheet" href="css/index.css">
    <style>
        .checkbox-group { 
            display: flex; 
            align-items: center; 
            gap: 0.75rem; 
            padding: 0.875rem 1rem; 
            background: rgba(15, 23, 42, 0.6); 
            border: 1px solid var(--border); 
            border-radius: 12px; 
            cursor: pointer; 
        }
        .checkbox-group input[type="checkbox"] { 
            width: 18px; 
            height: 18px; 
            accent-color: #25d366; 
            cursor: pointer; 
        }
        .checkbox-group label { 
            color: var(--text-main); 
            cursor: pointer; 
            font-size: 0.95rem; 
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
                <a href="javascript:void(0)" onclick="toggleSubmenu('submenu-clientes')" style="display:flex;justify-content:space-between;align-items:center;color:var(--primary);font-weight:600;text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);cursor:pointer;">
                    <span>Clientes</span>
                    <span style="font-size:0.75rem;">▾</span>
                </a>
                <div id="submenu-clientes" class="submenu open">
                    <a href="clientes_registro.php" style="color:var(--primary);font-weight:600;"><?= $modo === 'editar' ? 'Editar' : 'Registro' ?></a>
                    <a href="clientes.php">Listado</a>
                </div>
            </div>
            <?php if($auth->isAdmin()): ?>
            <div class="menu-dropdown">
                <a href="javascript:void(0)" onclick="toggleSubmenu('submenu-usuarios')" style="display:flex;justify-content:space-between;align-items:center;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);cursor:pointer;">
                    <span>Usuarios y Roles</span>
                    <span style="font-size:0.75rem;">▾</span>
                </a>
                <div id="submenu-usuarios" class="submenu">
                    <a href="usuarios_alta.php">Altas</a>
                    <a href="usuarios.php">Listado</a>
                </div>
            </div>
            <?php endif; ?>
            <a href="dashboard.php?logout=1" style="display:block;color:#f87171;text-decoration:none;padding:0.75rem 0;margin-top:2rem;">Cerrar Sesión</a>
        </nav>
    </aside>

    <main class="main-content">
        <header style="margin-bottom: 2.5rem; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 style="font-size: 2rem;"><?= $modo === 'editar' ? 'Editar Cliente' : 'Registro de Clientes' ?></h1>
                <p style="color: var(--text-muted);"><?= $modo === 'editar' ? 'Modifica la información del cliente.' : 'Alta y registro de nuevos clientes en el sistema.' ?></p>
            </div>
            <a href="clientes.php" class="btn-primary" style="text-decoration:none; display:inline-block; width:auto; padding:0.6rem 1.25rem; font-size:0.9rem;">Ver Listado</a>
        </header>

        <?php if ($mensaje): ?>
            <div class="alert" style="background:rgba(16,185,129,0.1);color:#34d399;border:1px solid rgba(16,185,129,0.2);padding:1rem;border-radius:12px;margin-bottom:1.5rem;max-width:850px;">
                <?= htmlspecialchars($mensaje) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error" style="max-width:850px;margin-bottom:1.5rem;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div style="max-width: 850px;">
            <!-- Formulario de Registro / Edición -->
            <div class="auth-card" style="width:100%;max-width:100%;padding:2.5rem;border-radius:20px;">
                <h3 style="margin-bottom:1.75rem;font-size:1.35rem;color:#fff;display:flex;align-items:center;gap:0.5rem;">
                    <span style="color:var(--primary);"><?= $modo === 'editar' ? '✏️' : '➕' ?></span>
                    <?= $modo === 'editar' ? 'Editar Cliente' : 'Nuevo Cliente' ?>
                </h3>
                <form method="POST">
                    <input type="hidden" name="action" value="<?= $modo === 'editar' ? 'actualizar' : 'crear' ?>">
                    <?php if ($modo === 'editar' && $clienteEditar): ?>
                        <input type="hidden" name="id" value="<?= htmlspecialchars($clienteEditar['id']) ?>">
                    <?php endif; ?>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Nombre completo *</label>
                            <input type="text" name="nombre" class="form-control" required
                                value="<?= htmlspecialchars($clienteEditar['nombre'] ?? '') ?>"
                                placeholder="Ej: María García">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Teléfono *</label>
                            <input type="tel" name="telefono" class="form-control" required
                                value="<?= htmlspecialchars($clienteEditar['telefono'] ?? '') ?>"
                                placeholder="Ej: 55 1234 5678">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Correo electrónico</label>
                            <input type="email" name="correo" class="form-control"
                                value="<?= htmlspecialchars($clienteEditar['correo'] ?? '') ?>"
                                placeholder="Opcional">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Dirección</label>
                            <input type="text" name="direccion" class="form-control"
                                value="<?= htmlspecialchars($clienteEditar['direccion'] ?? '') ?>"
                                placeholder="Opcional">
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1; margin-bottom: 0;">
                            <label class="form-label">Observaciones</label>
                            <input type="text" name="observaciones" class="form-control"
                                value="<?= htmlspecialchars($clienteEditar['observaciones'] ?? '') ?>"
                                placeholder="Notas adicionales del cliente...">
                        </div>
                        <!-- Notificaciones WhatsApp -->
                        <div class="form-group" style="grid-column: 1 / -1; margin-bottom: 0;">
                            <label class="form-label">Notificaciones</label>
                            <div class="checkbox-group">
                                <input type="checkbox" id="whatsapp" name="whatsapp_disponible" value="1"
                                    <?= (!empty($clienteEditar) && $clienteEditar['whatsapp_disponible']) ? 'checked' : '' ?>>
                                <label for="whatsapp">📱 El cliente acepta notificaciones por <strong>WhatsApp</strong></label>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 1.5rem; display: flex; gap: 1rem;">
                        <button type="submit" class="btn-primary" style="padding: 0.95rem;">
                            <?= $modo === 'editar' ? 'Guardar Cambios' : 'Registrar Cliente' ?>
                        </button>
                        <?php if ($modo === 'editar'): ?>
                            <a href="clientes.php" style="padding: 0.95rem 1.5rem; border-radius: 12px; color: var(--text-muted); text-decoration: none; border: 1px solid var(--border); display: inline-flex; align-items: center; justify-content: center;">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>

<script>
    function toggleSubmenu(id) {
        const el = document.getElementById(id);
        if (el) el.classList.toggle('open');
    }
</script>
</body>
</html>
