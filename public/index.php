<?php
// public/index.php — Front controller (API uniquement)
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$uri = rtrim($uri, '/');

// Strip project folder prefix if present (XAMPP: /Job-Mentor-Ai/api/...)
$apiPos = strpos($uri, '/api/');
if ($apiPos !== false) {
    $uri = substr($uri, $apiPos);
}

// Si ce n'est pas une requête API, rediriger vers le frontend
if (strpos($uri, '/api/') !== 0) {
    // SCRIPT_NAME = ex. "/Job-Mentor-Ai/public/index.php" (peu importe le nom du dossier projet)
    // → on en déduit dynamiquement le chemin vers public/frontend/
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

    // On n'arrive ici QUE pour un fichier qui n'existe pas réellement sur disque
    // (sinon Apache l'aurait déjà servi directement via .htaccess). La racine du
    // projet (ou "/public/index.php" lui-même) va donc vers l'accueil ; toute
    // autre route inconnue va vers une vraie page 404 stylée.
    $appRoot = rtrim(dirname($scriptDir), '/');
    if ($appRoot === '/' || $appRoot === '.') $appRoot = '';
    $isRoot = in_array($uri, ['', $appRoot, $appRoot . '/public', $appRoot . '/public/index.php'], true);
    $target = $isRoot ? '/frontend/index.html' : '/frontend/404.html';

    header('Location: ' . $scriptDir . $target);
    exit;
}

require_once __DIR__ . '/../bootstrap/app.php';

use App\Router;
use App\Controllers\AuthController;
use App\Controllers\CvController;
use App\Controllers\LettreController;
use App\Controllers\EntretienController;
use App\Controllers\OralController;
use App\Controllers\UserController;
use App\Controllers\AdminController;

// ── CORS centralisé ────────────────────────────────────────────
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');

// ── Routes ─────────────────────────────────────────────────────
$router = new Router();

// Auth
$router->get( '/api/auth/check',          AuthController::class, 'check');
$router->post('/api/auth/login',          AuthController::class, 'login');
$router->post('/api/auth/register',       AuthController::class, 'register');
$router->get( '/api/auth/logout',         AuthController::class, 'logout');
$router->post('/api/auth/request-reset',  AuthController::class, 'requestReset');
$router->post('/api/auth/reset-password', AuthController::class, 'resetPassword');
$router->post('/api/auth/update-profile', AuthController::class, 'updateProfile');

// CV
$router->post(  '/api/cv/generate',  CvController::class, 'generate');
$router->post(  '/api/cv/improve',   CvController::class, 'improve');
$router->get(   '/api/cv/history',   CvController::class, 'history');
$router->get(   '/api/cv/{id}',      CvController::class, 'get');
$router->delete('/api/cv/{id}',      CvController::class, 'delete');

// Lettre
$router->post(  '/api/lettre/generate', LettreController::class, 'generate');
$router->post(  '/api/lettre/correct',  LettreController::class, 'correct');
$router->post(  '/api/lettre/save',     LettreController::class, 'save');
$router->get(   '/api/lettre/list',     LettreController::class, 'list');
$router->get(   '/api/lettre/{id}',     LettreController::class, 'get');
$router->post(  '/api/lettre/{id}',     LettreController::class, 'delete');

// Entretien
$router->get(   '/api/entretien/question',       EntretienController::class, 'question');
$router->post(  '/api/entretien/analyze',        EntretienController::class, 'analyze');
$router->post(  '/api/entretien/save-notes',     EntretienController::class, 'saveNotes');
$router->get(   '/api/entretien/list',           EntretienController::class, 'list');
$router->get(   '/api/entretien/{id}',           EntretienController::class, 'get');
$router->get(   '/api/entretien/delete/{id}',    EntretienController::class, 'deleteHistory');
$router->get(   '/api/entretien/notes/list',     EntretienController::class, 'listNotes');
$router->get(   '/api/entretien/notes/{id}',     EntretienController::class, 'getNote');
$router->get(   '/api/entretien/notes/delete/{id}', EntretienController::class, 'deleteNote');
$router->get(   '/api/entretien/last-answer',    EntretienController::class, 'deleteLastAnswer');
$router->get(   '/api/entretien/reset',          EntretienController::class, 'reset');

// Oral
$router->post(  '/api/oral/analyze',  OralController::class, 'analyze');
$router->get(   '/api/oral/list',     OralController::class, 'list');
$router->get(   '/api/oral/{id}',     OralController::class, 'get');
$router->get(   '/api/oral/delete/{id}', OralController::class, 'delete');

// User
$router->post('/api/user/save-apikey', UserController::class, 'saveApikey');
$router->get( '/api/user/apikey',     UserController::class, 'getApikey');

// Admin
$router->get(   '/api/admin/users',              AdminController::class, 'users');
$router->get(   '/api/admin/stats',              AdminController::class, 'stats');
$router->post(  '/api/admin/users/{id}/status',  AdminController::class, 'setStatus');
$router->post(  '/api/admin/users/{id}/role',    AdminController::class, 'setRole');
$router->delete('/api/admin/users/{id}',         AdminController::class, 'deleteUser');

// ── Dispatch ───────────────────────────────────────────────────
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
