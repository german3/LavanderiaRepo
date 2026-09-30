<?php
require_once __DIR__ . '/../../../../src/config/Database.php';
require_once __DIR__ . '/../../../../src/modules/auth/Usuario.php';
require_once __DIR__ . '/../../../../src/modules/auth/AuthService.php';
require_once __DIR__ . '/../../../../src/modules/customers/Cliente.php';

use App\Config\Database;
use App\Modules\Auth\AuthService;
use App\Modules\Customers\Cliente;

$db = (new Database())->getConnection();
$auth = new AuthService($db);
$clienteModel = new Cliente($db);

// Middleware de autenticación
if (!$auth->checkAuth()) {
    header("Location: ../../../../public/login.php");
    exit;
}

$mensaje = "";
$error = "";

// Caso de uso: BuscarCliente
$busqueda = $_GET['buscar'] ?? '';
$clientes = $busqueda ? $clienteModel->buscar($busqueda) : $clienteModel->obtenerTodos();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes | Lavandería</title>
    <link rel="stylesheet" href="../../../../public/css/index.css">
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
        .btn-sm { padding: 0.4rem 1rem; border-radius: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer; border: none; text-decoration: none; display: inline-flex; align-items: center; }
        .btn-edit { background: rgba(59,130,246,0.15); color: #93c5fd; border: 1px solid rgba(59,130,246,0.3); }
        .btn-edit:hover { background: rgba(59,130,246,0.3); color: #fff; }
    </style>
</head>
<body>
<div class="app-container">
    <aside class="sidebar">
        <h2 style="font-size: 1.25rem; color: #fff; margin-bottom: 2rem;">Lavandería App</h2>
        <nav>
            <a href="../../../../public/dashboard.php" style="display:block;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);">Dashboard</a>
            <a href="../../../../src/modules/catalog/views/gestion_productos.php" style="display:block;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);">Catálogo de Productos</a>
            <div class="menu-dropdown">
                <a href="javascript:void(0)" onclick="toggleSubmenu('submenu-clientes')" style="display:flex;justify-content:space-between;align-items:center;color:var(--primary);font-weight:600;text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);cursor:pointer;">
                    <span>Clientes</span>
                    <span style="font-size:0.75rem;">▾</span>
                </a>
                <div id="submenu-clientes" class="submenu open">
                    <a href="cliente_registro.php">Registro</a>
                    <a href="gestion_clientes.php" style="color:var(--primary);font-weight:600;">Listado</a>
                </div>
            </div>
            <?php if($auth->isAdmin()): ?>
            <div class="menu-dropdown">
                <a href="javascript:void(0)" onclick="toggleSubmenu('submenu-usuarios')" style="display:flex;justify-content:space-between;align-items:center;color:var(--text-muted);text-decoration:none;padding:0.75rem 0;border-bottom:1px solid var(--border);cursor:pointer;">
                    <span>Usuarios y Roles</span>
                    <span style="font-size:0.75rem;">▾</span>
                </a>
                <div id="submenu-usuarios" class="submenu">
                    <a href="../../../../src/modules/auth/views/usuario_alta.php">Altas</a>
                    <a href="../../../../src/modules/auth/views/gestion_usuarios.php">Listado</a>
                </div>
            </div>
            <?php endif; ?>
            <a href="../../../../public/dashboard.php?logout=1" style="display:block;color:#f87171;text-decoration:none;padding:0.75rem 0;margin-top:2rem;">Cerrar Sesión</a>
        </nav>
    </aside>

    <main class="main-content">
        <header style="margin-bottom: 2.5rem; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 style="font-size: 2rem;">Clientes</h1>
                <p style="color: var(--text-muted);">Listado y búsqueda de clientes registrados.</p>
            </div>
            <a href="cliente_registro.php" class="btn-primary" style="text-decoration:none; display:inline-block; width:auto; padding:0.6rem 1.25rem; font-size:0.9rem;">➕ Nuevo Cliente</a>
        </header>

        <?php if ($mensaje): ?>
            <div class="alert" style="background:rgba(16,185,129,0.1);color:#34d399;border:1px solid rgba(16,185,129,0.2);padding:1rem;border-radius:12px;margin-bottom:1.5rem;">
                <?= htmlspecialchars($mensaje) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div>
            <!-- Lista + Búsqueda -->
            <div class="auth-card" style="width:100%;max-width:100%;padding:2rem;">
                <h3 style="margin-bottom: 0;">Clientes Registrados</h3>

                <!-- Caso de uso: BuscarCliente -->
                <form method="GET" class="search-bar" style="margin-top:1.5rem;">
                    <input type="text" name="buscar" class="form-control"
                        placeholder="🔍  Buscar por nombre o teléfono..."
                        value="<?= htmlspecialchars($busqueda) ?>">
                    <button type="submit" class="btn-primary" style="width:auto;padding:0.875rem 1.5rem;">Buscar</button>
                    <?php if ($busqueda): ?>
                        <a href="gestion_clientes.php" style="padding:0.875rem 1.5rem;border-radius:12px;color:var(--text-muted);text-decoration:none;border:1px solid var(--border);display:inline-flex;align-items:center;white-space:nowrap;">✕ Limpiar</a>
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
                            <th style="text-align: right;">Acción</th>
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
                            <td style="text-align: right;">
                                <a href="cliente_registro.php?modo=editar&id=<?= $c['id'] ?>" class="btn-sm btn-edit">Editar</a>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
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
