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

// Caso de uso: BuscarCliente
$busqueda = $_GET['buscar'] ?? '';
$clientes = $busqueda ? $clienteModel->buscar($busqueda) : $clienteModel->obtenerTodos();

// Lógica para dar de baja cliente
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'baja') {
    $idCliente = $_POST['id'] ?? '';
    if ($clienteModel->desactivar($idCliente)) {
        $mensaje = "El cliente ha sido dado de baja exitosamente.";
    } else {
        $error = "Error al intentar dar de baja al cliente.";
    }
    // Re-obtener lista actualizada
    $clientes = $busqueda ? $clienteModel->buscar($busqueda) : $clienteModel->obtenerTodos();
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
        .btn-sm { padding: 0.4rem 1rem; border-radius: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer; border: none; text-decoration: none; display: inline-flex; align-items: center; }
        .btn-edit { background: rgba(59,130,246,0.15); color: #93c5fd; border: 1px solid rgba(59,130,246,0.3); margin-right: 0.4rem; }
        .btn-edit:hover { background: rgba(59,130,246,0.3); color: #fff; }
        .btn-baja { background: rgba(239,68,68,0.15); color: #fca5a5; border: 1px solid rgba(239,68,68,0.3); }
        .btn-baja:hover { background: rgba(239,68,68,0.3); color: #fff; }
        /* Modal Styles */
        .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15,23,42,0.75); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); display: none; align-items: center; justify-content: center; z-index: 9999; animation: fadeIn 0.2s ease; }
        .modal-overlay.active { display: flex; }
        .modal-box { background: #1e293b; border: 1px solid var(--border); border-radius: 20px; padding: 2rem; max-width: 480px; width: 90%; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); animation: slideUp 0.3s cubic-bezier(0.16,1,0.3,1); }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
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
                    <a href="clientes_registro.php">Registro</a>
                    <a href="clientes.php" style="color:var(--primary);font-weight:600;">Listado</a>
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
                <h1 style="font-size: 2rem;">Clientes</h1>
                <p style="color: var(--text-muted);">Listado y búsqueda de clientes registrados.</p>
            </div>
            <a href="clientes_registro.php" class="btn-primary" style="text-decoration:none; display:inline-block; width:auto; padding:0.6rem 1.25rem; font-size:0.9rem;">➕ Nuevo Cliente</a>
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
                        <a href="clientes.php" style="padding:0.875rem 1.5rem;border-radius:12px;color:var(--text-muted);text-decoration:none;border:1px solid var(--border);display:inline-flex;align-items:center;white-space:nowrap;">✕ Limpiar</a>
                    <?php endif; ?>
                </form>

                <table class="table" id="tabla-clientes">
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
                        <tr class="fila-cliente" data-filtrado="1">
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
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="clientes_registro.php?modo=editar&id=<?= $c['id'] ?>" class="btn-sm btn-edit" style="margin-right:0;">Editar</a>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <!-- Fila sin resultados (client-side) -->
                <div id="cli-sin-resultados" style="display:none; text-align:center; color:var(--text-muted); padding:2.5rem 1rem;">
                    🔍 No hay registros para mostrar en esta página.
                </div>

                <!-- ══ PAGINADOR CLIENTES ═════════════════════════════════ -->
                <div id="paginador-clientes" style="
                    display: flex; align-items: center; justify-content: space-between;
                    flex-wrap: wrap; gap: 0.75rem; margin-top: 1.25rem;
                    padding: 0.85rem 1.1rem;
                    background: rgba(255,255,255,0.04);
                    border: 1px solid var(--border); border-radius: 12px;
                ">
                    <div id="pag-cli-info" style="font-size:0.82rem; color:var(--text-muted);"></div>
                    <div id="pag-cli-botones" style="display:flex; gap:0.35rem; flex-wrap:wrap; align-items:center;"></div>
                    <div style="display:flex; align-items:center; gap:0.5rem; font-size:0.82rem; color:var(--text-muted);">
                        <span>Mostrar</span>
                        <select id="sel-cli-pagina" onchange="cambiarCliPorPagina(this.value)" style="
                            background:var(--sidebar-bg); color:var(--text-main);
                            border:1px solid var(--border); border-radius:7px;
                            padding:0.3rem 0.5rem; font-size:0.82rem; cursor:pointer; outline:none;">
                            <option value="10" selected style="background:#1e293b;color:#f1f5f9;">10</option>
                            <option value="15" style="background:#1e293b;color:#f1f5f9;">15</option>
                            <option value="20" style="background:#1e293b;color:#f1f5f9;">20</option>
                            <option value="25" style="background:#1e293b;color:#f1f5f9;">25</option>
                            <option value="30" style="background:#1e293b;color:#f1f5f9;">30</option>
                        </select>
                        <span>por página</span>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    function toggleSubmenu(id) {
        const el = document.getElementById(id);
        if (el) el.classList.toggle('open');
    }

    // ══════════════════════════════════════════════════════════════════
    //  PAGINACIÓN CLIENTES
    // ══════════════════════════════════════════════════════════════════
    let cliPagActual = 1;
    let cliPorPagina = 10;

    function getCliFilas() {
        return Array.from(document.querySelectorAll('.fila-cliente'))
                    .filter(f => f.dataset.filtrado !== '0');
    }

    function renderCliPagina(pagina) {
        const filas     = getCliFilas();
        const total     = filas.length;
        const totalPags = Math.max(1, Math.ceil(total / cliPorPagina));
        cliPagActual    = Math.min(Math.max(1, pagina), totalPags);

        const inicio = (cliPagActual - 1) * cliPorPagina;
        const fin    = inicio + cliPorPagina;

        document.querySelectorAll('.fila-cliente').forEach(f => f.style.display = 'none');
        filas.forEach((f, i) => { f.style.display = (i >= inicio && i < fin) ? '' : 'none'; });

        const sinRes = document.getElementById('cli-sin-resultados');
        if (sinRes) sinRes.style.display = total === 0 ? '' : 'none';

        const infoEl = document.getElementById('pag-cli-info');
        if (infoEl) {
            infoEl.textContent = total === 0 ? 'Sin resultados'
                : `Mostrando ${inicio + 1}–${Math.min(fin, total)} de ${total} cliente${total !== 1 ? 's' : ''}`;
        }

        const botsEl = document.getElementById('pag-cli-botones');
        if (!botsEl) return;
        botsEl.innerHTML = '';

        const btnS = (activo) => `cursor:pointer;border:1px solid var(--border);border-radius:7px;padding:0.3rem 0.65rem;font-size:0.8rem;font-weight:600;transition:all 0.18s;background:${activo ? 'var(--primary)' : 'rgba(255,255,255,0.05)'};color:${activo ? '#fff' : 'var(--text-muted)'};min-width:2.1rem;text-align:center;`;

        const bPrev = document.createElement('button');
        bPrev.innerHTML = '&#8249;'; bPrev.title = 'Anterior';
        bPrev.style.cssText = btnS(false) + (cliPagActual === 1 ? 'opacity:0.35;cursor:default;' : '');
        bPrev.disabled = cliPagActual === 1;
        bPrev.onclick = () => renderCliPagina(cliPagActual - 1);
        botsEl.appendChild(bPrev);

        const ven = 2;
        let pS = Math.max(1, cliPagActual - ven);
        let pE = Math.min(totalPags, cliPagActual + ven);

        if (pS > 1) {
            const b = document.createElement('button'); b.textContent = '1'; b.style.cssText = btnS(false);
            b.onclick = () => renderCliPagina(1); botsEl.appendChild(b);
            if (pS > 2) { const d = document.createElement('span'); d.textContent = '…'; d.style.cssText = 'padding:0 0.3rem;color:var(--text-muted);font-size:0.8rem;'; botsEl.appendChild(d); }
        }
        for (let p = pS; p <= pE; p++) {
            const b = document.createElement('button'); b.textContent = p; b.style.cssText = btnS(p === cliPagActual);
            b.onclick = () => renderCliPagina(p); botsEl.appendChild(b);
        }
        if (pE < totalPags) {
            if (pE < totalPags - 1) { const d = document.createElement('span'); d.textContent = '…'; d.style.cssText = 'padding:0 0.3rem;color:var(--text-muted);font-size:0.8rem;'; botsEl.appendChild(d); }
            const b = document.createElement('button'); b.textContent = totalPags; b.style.cssText = btnS(false);
            b.onclick = () => renderCliPagina(totalPags); botsEl.appendChild(b);
        }

        const bNext = document.createElement('button');
        bNext.innerHTML = '&#8250;'; bNext.title = 'Siguiente';
        bNext.style.cssText = btnS(false) + (cliPagActual === totalPags ? 'opacity:0.35;cursor:default;' : '');
        bNext.disabled = cliPagActual === totalPags;
        bNext.onclick = () => renderCliPagina(cliPagActual + 1);
        botsEl.appendChild(bNext);
    }

    function cambiarCliPorPagina(val) {
        cliPorPagina = parseInt(val, 10) || 10;
        renderCliPagina(1);
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.fila-cliente').forEach(f => { if (!f.dataset.filtrado) f.dataset.filtrado = '1'; });
        renderCliPagina(1);
    });
</script>
</body>
</html>
