<?php
// bootstrap/app.php — Point d'initialisation unique

// ── Autoloader ─────────────────────────────────────────────────
require_once __DIR__ . '/../vendor/autoload.php';

// ── Configuration ──────────────────────────────────────────────
require_once __DIR__ . '/../config/app.php';

// ── Erreurs PHP ────────────────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__DIR__) . '/logs/php_errors.log');

// ── Session ────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Connexion DB ───────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
        DB_USER,
        DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur connexion base de données']);
    exit;
}

// Rendre $pdo accessible globalement (rétrocompatibilité)
$GLOBALS['pdo'] = $pdo;
