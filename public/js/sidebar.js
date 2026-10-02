// ── Sidebar: Toggle submenu ──
function toggleSubmenu(id, trigger) {
    const el = document.getElementById(id);
    if (el) {
        el.classList.toggle('open');
        if (trigger) trigger.classList.toggle('open');
    }
}

// ── Sidebar: Collapse / Expand ──
function toggleSidebar() {
    const sidebar = document.getElementById('app-sidebar');
    if (!sidebar) return;
    sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebar-collapsed', sidebar.classList.contains('collapsed'));
}

// ── Sidebar: Restore state from localStorage ──
(function() {
    const collapsed = localStorage.getItem('sidebar-collapsed') === 'true';
    if (collapsed) {
        const sidebar = document.getElementById('app-sidebar');
        if (sidebar) sidebar.classList.add('collapsed');
    }
})();
