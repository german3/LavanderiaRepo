/**
 * Gestor de Temas (Claro / Oscuro) para Lavandería Vera
 * Aplica el tema al elemento raíz (<html>) y persiste en localStorage.
 * Soporta sincronización multi-pestaña y notificación a componentes (ej. Chart.js).
 */
(function() {
    const STORAGE_KEY = 'lavanderia_theme';

    window.ThemeManager = {
        STORAGE_KEY: STORAGE_KEY,

        getTheme: function() {
            try {
                return localStorage.getItem(STORAGE_KEY) || 'dark';
            } catch (e) {
                return 'dark';
            }
        },

        setTheme: function(theme) {
            if (theme !== 'light' && theme !== 'dark') theme = 'dark';
            
            // Aplicar data-theme al <html> y clases al <body>
            document.documentElement.setAttribute('data-theme', theme);
            if (document.body) {
                document.body.classList.remove('theme-light', 'theme-dark');
                document.body.classList.add('theme-' + theme);
            }

            try {
                localStorage.setItem(STORAGE_KEY, theme);
            } catch (e) {}

            this.updateButtons(theme);

            // Disparar evento personalizado para gráficos o vistas que necesiten redibujar
            try {
                window.dispatchEvent(new CustomEvent('themeChanged', { detail: { theme: theme } }));
            } catch (e) {}
        },

        toggle: function() {
            const current = this.getTheme();
            const next = (current === 'dark') ? 'light' : 'dark';
            this.setTheme(next);
            return next;
        },

        updateButtons: function(theme) {
            theme = theme || this.getTheme();
            
            // Actualizar botones de cambio de tema
            document.querySelectorAll('.theme-switch-btn').forEach(function(btn) {
                if (theme === 'light') {
                    btn.classList.add('is-light');
                    btn.classList.remove('is-dark');
                } else {
                    btn.classList.add('is-dark');
                    btn.classList.remove('is-light');
                }
            });

            document.querySelectorAll('.theme-option.opt-light').forEach(function(el) {
                el.classList.toggle('active', theme === 'light');
            });
            document.querySelectorAll('.theme-option.opt-dark').forEach(function(el) {
                el.classList.toggle('active', theme === 'dark');
            });
        },

        init: function() {
            const theme = this.getTheme();
            document.documentElement.setAttribute('data-theme', theme);
            if (document.body) {
                document.body.classList.remove('theme-light', 'theme-dark');
                document.body.classList.add('theme-' + theme);
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => this.updateButtons(theme));
            } else {
                this.updateButtons(theme);
            }
        }
    };

    // Inicialización inmediata para evitar parpadeos en pantalla (FOUC)
    window.ThemeManager.init();

    // Sincronización en vivo entre pestañas del navegador
    window.addEventListener('storage', function(e) {
        if (e.key === STORAGE_KEY && e.newValue) {
            window.ThemeManager.setTheme(e.newValue);
        }
    });
})();

