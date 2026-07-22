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
