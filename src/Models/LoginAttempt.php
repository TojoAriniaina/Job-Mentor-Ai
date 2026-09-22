<?php
namespace App\Models;

use PDO;

/**
 * Limitation des tentatives de connexion (anti brute-force).
 * Suivi par identifiant = email + IP, en base pour résister au
 * vidage des cookies/session (contrairement à un compteur en session).
 */
class LoginAttempt {
    private PDO $pdo;

    /** Nombre d'échecs autorisés avant blocage temporaire. */
    private const MAX_ATTEMPTS = 5;

    /** Durée du blocage en secondes après le seuil atteint. */
    private const LOCKOUT_SECONDS = 300; // 5 minutes

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->ensureTable();
    }

    private function ensureTable(): void {
        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS login_attempts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    identifier VARCHAR(191) NOT NULL,
                    attempts INT NOT NULL DEFAULT 0,
                    first_attempt_at DATETIME NOT NULL,
                    last_attempt_at DATETIME NOT NULL,
                    UNIQUE KEY idx_identifier (identifier)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (\Exception $e) {
            error_log('[LoginAttempt] Création table impossible : ' . $e->getMessage());
        }
    }

    private function makeIdentifier(string $email): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return mb_strtolower($email) . '|' . $ip;
    }

    /**
     * Retourne le nombre de secondes restantes avant de pouvoir
     * retenter, ou 0 si l'utilisateur n'est pas bloqué.
     */
    public function getRetryAfter(string $email): int {
        $stmt = $this->pdo->prepare('SELECT attempts, last_attempt_at FROM login_attempts WHERE identifier = ?');
        $stmt->execute([$this->makeIdentifier($email)]);
        $row = $stmt->fetch();

        if (!$row || (int) $row['attempts'] < self::MAX_ATTEMPTS) {
            return 0;
        }

        $elapsed = time() - strtotime($row['last_attempt_at']);
        $remaining = self::LOCKOUT_SECONDS - $elapsed;

        return $remaining > 0 ? $remaining : 0;
    }

    public function registerFailure(string $email): void {
        $identifier = $this->makeIdentifier($email);
        $now = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare('SELECT attempts, last_attempt_at FROM login_attempts WHERE identifier = ?');
        $stmt->execute([$identifier]);
        $row = $stmt->fetch();

        if (!$row) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO login_attempts (identifier, attempts, first_attempt_at, last_attempt_at) VALUES (?, 1, ?, ?)'
            );
            $stmt->execute([$identifier, $now, $now]);
            return;
        }

        // Si le blocage précédent est expiré depuis longtemps, on repart à zéro.
        $elapsed = time() - strtotime($row['last_attempt_at']);
        if ((int) $row['attempts'] >= self::MAX_ATTEMPTS && $elapsed > self::LOCKOUT_SECONDS) {
            $stmt = $this->pdo->prepare(
                'UPDATE login_attempts SET attempts = 1, first_attempt_at = ?, last_attempt_at = ? WHERE identifier = ?'
            );
            $stmt->execute([$now, $now, $identifier]);
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE login_attempts SET attempts = attempts + 1, last_attempt_at = ? WHERE identifier = ?'
        );
        $stmt->execute([$now, $identifier]);
    }

    public function resetAttempts(string $email): void {
        $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE identifier = ?');
        $stmt->execute([$this->makeIdentifier($email)]);
    }
}
