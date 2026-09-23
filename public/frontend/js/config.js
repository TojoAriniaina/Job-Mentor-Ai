// config.js — Doit être chargé EN PREMIER, avant tout autre script.
// Calcule dynamiquement la racine de l'app pour que les appels API
// fonctionnent peu importe où le projet est placé (racine du domaine,
// sous-dossier XAMPP type /Job-Mentor-Ai/, nom de dossier renommé, etc.)
(function () {
    var path = window.location.pathname;
    var apiMarker = '/public/frontend/';
    var apiIdx = path.indexOf(apiMarker);
    var appRoot = apiIdx !== -1 ? path.substring(0, apiIdx) : '';
    window.API_BASE = appRoot + '/api';

    // Racine du frontend (dossier contenant index.html), peu importe la profondeur
    // de la page courante (frontend/index.html vs frontend/pages/xxx.html)
    var frontendMarker = '/frontend/';
    var frontendIdx = path.indexOf(frontendMarker);
    window.FRONTEND_BASE = frontendIdx !== -1
        ? path.substring(0, frontendIdx) + '/frontend'
        : '';
})();

// ── CSRF : injection globale du jeton X-CSRF-Token ────────────────
// config.js est chargé en premier sur toutes les pages ; ce wrapper
// évite de modifier les dizaines d'appels fetch() dispersés. Le jeton
// est stocké par checkAuthStatus (utils.js) depuis /api/auth/check.
(function () {
    var nativeFetch = window.fetch;
    window.JM_CSRF_TOKEN = '';

    function csrfToken() {
        if (window.JM_CSRF_TOKEN) return window.JM_CSRF_TOKEN;
        var m = document.cookie.match(/(?:^|;\s*)csrf_token=([a-f0-9]{64})/);
        return m ? m[1] : '';
    }

    window.fetch = function (input, init) {
        try {
            if (typeof input === 'string') {
                init = init || {};
                var method = (init.method || 'GET').toUpperCase();
                if (method !== 'GET' && method !== 'HEAD') {
                    var token = csrfToken();
                    if (token) {
                        var u = new URL(input, window.location.href);
                        if (u.origin === window.location.origin && u.pathname.indexOf('/api/') !== -1) {
                            var h = new Headers(init.headers || {});
                            if (!h.has('X-CSRF-Token')) h.set('X-CSRF-Token', token);
                            init.headers = h;
                        }
                    }
                }
            }
        } catch (e) { /* ne jamais casser un fetch légitime */ }
        return nativeFetch.call(window, input, init);
    };
})();
