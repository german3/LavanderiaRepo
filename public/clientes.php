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
$modo = $_GET['modo'] ?? 'lista'; // lista | editar
$clienteEditar = null;

// Caso de uso: BuscarCliente
$busqueda = $_GET['buscar'] ?? '';
$clientes = $busqueda ? $clienteModel->buscar($busqueda) : $clienteModel->obtenerTodos();

// Caso de uso: RegistrarCliente (Admins y Cajeros)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';

    if ($action === 'crear') {
        $clienteModel->nombre = trim($_POST['nombre']);
        $clienteModel->telefono = trim($_POST['telefono']);
        $clienteModel->whatsapp_disponible = isset($_POST['whatsapp_disponible']) ? true : false;
        $clienteModel->correo = trim($_POST['correo']) ?: null;
        $clienteModel->direccion = trim($_POST['direccion']) ?: null;
        $clienteModel->observaciones = trim($_POST['observaciones']) ?: null;
        $clienteModel->estado = 'ACTIVO';

        if ($clienteModel->crear()) {
            $mensaje = "Cliente registrado exitosamente.";
        } else {
            $error = "Error al registrar el cliente.";
        }
        $clientes = $clienteModel->obtenerTodos();
    }

    // Caso de uso: ActualizarCliente
    if ($action === 'actualizar') {
        $clienteModel->id = $_POST['id'];
        $clienteModel->nombre = trim($_POST['nombre']);
        $clienteModel->telefono = trim($_POST['telefono']);
        $clienteModel->whatsapp_disponible = isset($_POST['whatsapp_disponible']) ? true : false;
        $clienteModel->correo = trim($_POST['correo']) ?: null;
        $clienteModel->direccion = trim($_POST['direccion']) ?: null;
        $clienteModel->observaciones = trim($_POST['observaciones']) ?: null;

        if ($clienteModel->actualizar()) {
            $mensaje = "Cliente actualizado correctamente.";
        } else {
            $error = "Error al actualizar el cliente.";
        }
        $modo = 'lista';
        $clientes = $clienteModel->obtenerTodos();
    }
}

// Cargar datos para edición
if ($modo === 'editar' && isset($_GET['id'])) {
    $clienteEditar = $clienteModel->obtenerPorId($_GET['id']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes | Lavandería</title>
    <link rel="stylesheet" href="css/index.css">
    <style>
        .table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; color: var(--text-main); }
        .table th { padding: 0.75rem 1rem; text-align: left; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); border-bottom: 2px solid var(--border); }
        .table td { padding: 1rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        .table tr:hover td { background: rgba(255,255,255,0.02); }
        .badge { padding: 0.2rem 0.6rem; border-radius: 99px; font-size: 0.72rem; font-weight: 600; }
        .badge-wp { background: rgba(37, 211, 102, 0.15); color: #25d366; border: 1px solid rgba(37, 211, 102, 0.3); }
        .badge-no-wp { background: rgba(148,163,184,0.1); color: var(--text-muted); border: 1px solid var(--border); }
        .search-bar { display: flex; gap: 0.75rem; margin-bottom: 1.5rem; }
        .search-bar input { flex: 1; }
        .btn-sm { padding: 0.4rem 1rem; border-radius: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer; border: none; }
        .btn-edit { background: rgba(59,130,246,0.15); color: #93c5fd; border: 1px solid rgba(59,130,246,0.3); }
        .btn-edit:hover { background: rgba(59,130,246,0.3); }
        .checkbox-group { display: flex; align-items: center; gap: 0.75rem; padding: 0.875rem 1rem; background: rgba(15, 23, 42, 0.6); border: 1px solid var(--border); border-radius: 12px; cursor: pointer; }
        .checkbox-group input[type="checkbox"] { width: 18px; height: 18px; accent-color: #25d366; cursor: pointer; }
        .checkbox-group label { color: var(--text-main); cursor: pointer; font-size: 0.95rem; }
    </style>
</head>
<body>
<div class="app-container">
    <aside class="sidebar">
        <h2 style="font-size: 1.25rem; color: #fff; margin-bottom: 2rem;">Lavandería App</h2>
        <nav>
            <a href="dashboard.php" style="display:block;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);">Dashboard</a>
            <a href="productos.php" style="display:block;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);">Catálogo de Productos</a>
            <a href="clientes.php" style="display:block;color:var(--primary);font-weight:600;text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);">Clientes</a>
            <?php if($auth->isAdmin()): ?>
            <a href="usuarios.php" style="display:block;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);">Usuarios y Roles</a>
            <?php endif; ?>
            <a href="dashboard.php?logout=1" style="display:block;color:#f87171;text-decoration:none;padding:0.75rem 0;margin-top:2rem;">Cerrar Sesión</a>
        </nav>
    </aside>

    <main class="main-content">
        <header style="margin-bottom: 2rem;">
            <h1 style="font-size: 2rem;">Clientes</h1>
            <p style="color: var(--text-muted);">Registro y gestión de clientes del sistema.</p>
        </header>

        <?php if ($mensaje): ?>
            <div class="alert" style="background:rgba(16,185,129,0.1);color:#34d399;border:1px solid rgba(16,185,129,0.2);padding:1rem;border-radius:12px;margin-bottom:1.5rem;">
                <?= htmlspecialchars($mensaje) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div style="display: flex; flex-direction: column; gap: 2rem;">

            <!-- Formulario Nuevo / Editar -->
            <div class="auth-card" style="width:100%;max-width:100%;padding:2rem;">
                <h3 style="margin-bottom:1.5rem;">
                    <?= $modo === 'editar' ? '✏️ Editar Cliente' : '➕ Nuevo Cliente' ?>
                </h3>
                <form method="POST">
                    <input type="hidden" name="action" value="<?= $modo === 'editar' ? 'actualizar' : 'crear' ?>">
                    <?php if ($modo === 'editar' && $clienteEditar): ?>
                        <input type="hidden" name="id" value="<?= htmlspecialchars($clienteEditar['id']) ?>">
                    <?php endif; ?>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label">Nombre completo *</label>
                            <input type="text" name="nombre" class="form-control" required
                                value="<?= htmlspecialchars($clienteEditar['nombre'] ?? '') ?>"
                                placeholder="Ej: María García">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Teléfono *</label>
                            <input type="tel" name="telefono" class="form-control" required
                                value="<?= htmlspecialchars($clienteEditar['telefono'] ?? '') ?>"
                                placeholder="Ej: 55 1234 5678">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Correo electrónico</label>
                            <input type="email" name="correo" class="form-control"
                                value="<?= htmlspecialchars($clienteEditar['correo'] ?? '') ?>"
                                placeholder="Opcional">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Dirección</label>
                            <input type="text" name="direccion" class="form-control"
                                value="<?= htmlspecialchars($clienteEditar['direccion'] ?? '') ?>"
                                placeholder="Opcional">
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label class="form-label">Observaciones</label>
                            <input type="text" name="observaciones" class="form-control"
                                value="<?= htmlspecialchars($clienteEditar['observaciones'] ?? '') ?>"
                                placeholder="Notas adicionales del cliente...">
                        </div>
                        <!-- Regla de Negocio: WhatsApp flag -->
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label class="form-label">Notificaciones</label>
                            <div class="checkbox-group">
                                <input type="checkbox" id="whatsapp" name="whatsapp_disponible" value="1"
                                    <?= (!empty($clienteEditar) && $clienteEditar['whatsapp_disponible']) ? 'checked' : '' ?>>
                                <label for="whatsapp">📱 El cliente acepta notificaciones por <strong>WhatsApp</strong></label>
                            </div>
                        </div>
                    </div>

                    <div style="display:flex;gap:1rem;margin-top:0.5rem;">
                        <button type="submit" class="btn-primary" style="padding:0.75rem;">
                            <?= $modo === 'editar' ? 'Guardar Cambios' : 'Registrar Cliente' ?>
                        </button>
                        <?php if ($modo === 'editar'): ?>
                            <a href="clientes.php" style="padding:0.75rem 1.5rem;border-radius:12px;color:var(--text-muted);text-decoration:none;border:1px solid var(--border);display:inline-flex;align-items:center;">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Lista + Búsqueda -->
            <div class="auth-card" style="width:100%;max-width:100%;padding:2rem;">
                <h3>Clientes Registrados</h3>

                <!-- Caso de uso: BuscarCliente -->
                <form method="GET" class="search-bar" style="margin-top:1.5rem;">
                    <input type="text" name="buscar" class="form-control"
                        placeholder="🔍  Buscar por nombre o teléfono..."
                        value="<?= htmlspecialchars($busqueda) ?>">
                    <button type="submit" class="btn-primary" style="width:auto;padding:0.875rem 1.5rem;">Buscar</button>
                    <?php if ($busqueda): ?>
                        <a href="clientes.php" style="padding:0.875rem 1.5rem;border-radius:12px;color:var(--text-muted);text-decoration:none;border:1px solid var(--border);display:inline-flex;align-items:center;white-space:nowrap;">✕ Limpiar</a>
                    <?php endif; ?>
                </form>

                <table class="table">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Teléfono</th>
                            <th>WhatsApp</th>
                            <th>Correo</th>
                            <th>Registro</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($clientes)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center;color:var(--text-muted);padding:2rem;">
                                <?= $busqueda ? 'Sin resultados para "' . htmlspecialchars($busqueda) . '"' : 'No hay clientes registrados aún.' ?>
                            </td>
                        </tr>
                        <?php else: foreach($clientes as $c): ?>
                        <tr>
                            <td style="font-weight:500;"><?= htmlspecialchars($c['nombre']) ?></td>
                            <td style="color:var(--text-muted);"><?= htmlspecialchars($c['telefono']) ?></td>
                            <td>
                                <?php if ($c['whatsapp_disponible']): ?>
                                    <span class="badge badge-wp">📱 Sí</span>
                                <?php else: ?>
                                    <span class="badge badge-no-wp">No</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.85rem;color:var(--text-muted);"><?= htmlspecialchars($c['correo'] ?? '—') ?></td>
                            <td style="font-size:0.8rem;color:var(--text-muted);"><?= date('d/m/Y', strtotime($c['fecha_registro'])) ?></td>
                            <td>
                                <a href="clientes.php?modo=editar&id=<?= $c['id'] ?>" class="btn-sm btn-edit">Editar</a>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
</body>
</html>
