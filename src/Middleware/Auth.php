<?php
namespace App\Middleware;

class Auth {
    public static function require(): int {
        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Non authentifié. Veuillez vous connecter.', 'redirect' => 'login']);
            exit;
        }

        $userId = (int) $_SESSION['user_id'];
        self::assertStillActive($userId);

        return $userId;
    }

    public static function requireAdmin(): int {
        $userId = self::require();
        if (($_SESSION['role'] ?? 'user') !== 'admin') {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Accès réservé aux administrateurs.']);
            exit;
        }
        return $userId;
    }

    /**
     * Revérifie en base que le compte existe toujours et est actif, à chaque
     * requête authentifiée. Permet à une désactivation (ou suppression) faite
     * par un admin de prendre effet immédiatement, même si l'utilisateur a
     * déjà une session ouverte, sans attendre un prochain login/check.
     * Met aussi à jour le rôle en session s'il a changé entre-temps.
     */
    private static function assertStillActive(int $userId): void {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!$pdo) return; // sécurité : si pdo indisponible, on ne bloque pas la requête

        $stmt = $pdo->prepare('SELECT role, is_active FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row || (int) $row['is_active'] === 0) {
            session_destroy();
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Ce compte a été désactivé.', 'redirect' => 'login']);
            exit;
        }

        $_SESSION['role'] = $row['role'] ?? 'user';
    }
}
