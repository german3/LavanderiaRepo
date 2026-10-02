<?php
/**
 * Sidebar partial — include from any public/ page.
 * 
 * Before including, set these variables:
 *   $activePage   = 'dashboard' | 'productos_registro' | 'productos' | 'stock_entradas' | 'historial' | 'clientes_registro' | 'clientes' | 'usuarios_alta' | 'usuarios'
 *   $auth         = AuthService instance (for isAdmin check)
 *   $logoutUrl    = (optional) URL for logout, defaults to '?logout=1'
 */
$logoutUrl = $logoutUrl ?? '?logout=1';

// Helper: active class
function isActive($page, $current) {
    return $page === $current ? ' active' : '';
}
function isMenuOpen($pages, $current) {
    return in_array($current, $pages) ? ' open' : '';
}
function isMenuActive($pages, $current) {
    return in_array($current, $pages) ? ' active' : '';
}
?>
<aside class="sidebar" id="app-sidebar">
    <a href="dashboard.php" class="sidebar-logo"><img src="img/Logo.jpeg" alt="Lavandería Vera"></a>
    <button class="sidebar-toggle" onclick="toggleSidebar()" title="Colapsar menú">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
    </button>
    <nav>
        <!-- Dashboard -->
        <a href="dashboard.php" class="nav-link<?= isActive('dashboard', $activePage) ?>">
            <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <span class="nav-text">Dashboard</span>
        </a>
        <!-- Productos -->
        <div class="menu-dropdown">
            <a href="javascript:void(0)" class="nav-link<?= isMenuActive(['productos_registro','productos'], $activePage) ?><?= isMenuOpen(['productos_registro','productos'], $activePage) ?>" onclick="toggleSubmenu('submenu-productos', this)">
                <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                <span class="nav-text">Productos</span>
                <span class="nav-arrow">▾</span>
            </a>
            <div id="submenu-productos" class="submenu<?= isMenuOpen(['productos_registro','productos'], $activePage) ?>">
                <a href="productos_registro.php" class="<?= isActive('productos_registro', $activePage) ?>">Registro</a>
                <a href="productos.php" class="<?= isActive('productos', $activePage) ?>">Catálogo</a>
            </div>
        </div>
        <!-- Inventario -->
        <div class="menu-dropdown">
            <a href="javascript:void(0)" class="nav-link<?= isMenuActive(['stock_entradas','historial'], $activePage) ?><?= isMenuOpen(['stock_entradas','historial'], $activePage) ?>" onclick="toggleSubmenu('submenu-stock', this)">
                <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M9 14l2 2 4-4"/></svg>
                <span class="nav-text">Inventario</span>
                <span class="nav-arrow">▾</span>
            </a>
            <div id="submenu-stock" class="submenu<?= isMenuOpen(['stock_entradas','historial'], $activePage) ?>">
                <a href="stock_entradas.php" class="<?= isActive('stock_entradas', $activePage) ?>">Entradas / Salidas</a>
                <a href="historial.php" class="<?= isActive('historial', $activePage) ?>">Historial</a>
            </div>
        </div>
        <!-- Clientes -->
        <div class="menu-dropdown">
            <a href="javascript:void(0)" class="nav-link<?= isMenuActive(['clientes_registro','clientes'], $activePage) ?><?= isMenuOpen(['clientes_registro','clientes'], $activePage) ?>" onclick="toggleSubmenu('submenu-clientes', this)">
                <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <span class="nav-text">Clientes</span>
                <span class="nav-arrow">▾</span>
            </a>
            <div id="submenu-clientes" class="submenu<?= isMenuOpen(['clientes_registro','clientes'], $activePage) ?>">
                <a href="clientes_registro.php" class="<?= isActive('clientes_registro', $activePage) ?>">Registro</a>
                <a href="clientes.php" class="<?= isActive('clientes', $activePage) ?>">Listado</a>
            </div>
        </div>
        <?php if($auth->isAdmin()): ?>
        <!-- Usuarios y Roles -->
        <div class="menu-dropdown">
            <a href="javascript:void(0)" class="nav-link<?= isMenuActive(['usuarios_alta','usuarios'], $activePage) ?><?= isMenuOpen(['usuarios_alta','usuarios'], $activePage) ?>" onclick="toggleSubmenu('submenu-usuarios', this)">
                <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                <span class="nav-text">Usuarios y Roles</span>
                <span class="nav-arrow">▾</span>
            </a>
            <div id="submenu-usuarios" class="submenu<?= isMenuOpen(['usuarios_alta','usuarios'], $activePage) ?>">
                <a href="usuarios_alta.php" class="<?= isActive('usuarios_alta', $activePage) ?>">Altas</a>
                <a href="usuarios.php" class="<?= isActive('usuarios', $activePage) ?>">Listado</a>
            </div>
        </div>
        <?php endif; ?>
        <div class="nav-separator"></div>
        <a href="<?= $logoutUrl ?>" class="nav-link nav-link-logout">
            <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <span class="nav-text">Cerrar Sesión</span>
        </a>
    </nav>
</aside>
