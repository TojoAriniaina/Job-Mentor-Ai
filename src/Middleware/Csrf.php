<?php
// src/Middleware/Csrf.php — Jeton CSRF par session + contrôle d'origine.
namespace App\Middleware;

class Csrf {

    public static function token(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /** Régénère le jeton (après login/register) et le renvoie. */
    public static function rotate(): string {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }

    /**
     * Cookie JS-lecture du jeton, posé sur chaque réponse API : le wrapper
     * fetch (config.js) le trouve immédiatement, même si /auth/check n'a pas
     * encore répondu (courses de chargement type tts/speak).
     */
    public static function syncCookie(): void {
        $token = self::token();
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie('csrf_token', $token, [
            'path' => '/',
            'httponly' => false,
            'secure' => $https,
            'samesite' => 'Lax',
        ]);
    }

    /** À appeler avant le dispatch, pour toute requête potentiellement modificative. */
    public static function protect(string $method, string $uri): void {
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        // Couche 1 : contrôle d'origine — protège aussi login/register/request-reset,
        // où aucun jeton de session n'existe encore. Absent (curl, app native) → ignoré.
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin === '' && !empty($_SERVER['HTTP_REFERER'])) {
            $origin = $_SERVER['HTTP_REFERER'];
        }
        if ($origin !== '') {
            $host = parse_url($origin, PHP_URL_HOST) ?: '';
            $serverHost = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
            if ($host !== '' && strtolower($host) !== $serverHost) {
                self::deny();
            }
        }

        // Couche 2 : jeton X-CSRF-Token (exemptés : endpoints sans session préalable).
        if (self::isExempt($uri)) {
            return;
        }

        $sent   = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $stored = (string) ($_SESSION['csrf_token'] ?? '');
        if ($stored === '' || $sent === '' || !hash_equals($stored, $sent)) {
            self::deny();
        }
    }

    private static function isExempt(string $uri): bool {
        foreach (['/auth/login', '/auth/register', '/auth/request-reset'] as $p) {
            if (str_contains($uri, $p)) {
                return true;
            }
        }
        // Format legacy ?action= (route /api/auth.php)
        $action = $_GET['action'] ?? '';
        return str_contains($uri, '/api/auth')
            && in_array($action, ['login', 'register', 'request_reset'], true);
    }

    private static function deny(): void {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error'   => 'Jeton de sécurité invalide ou manquant. Rechargez la page et réessayez.',
        ]);
        exit;
    }
}
