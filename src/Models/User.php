<?php
namespace App\Models;

use PDO;

class User {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function findById(int $id): ?array {
        $stmt = $this->pdo->prepare('SELECT id, name, email, titre, phone, secteur, photo, role, is_active FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findByEmail(string $email): ?array {
        $stmt = $this->pdo->prepare('SELECT id, name, email, password_hash, titre, phone, secteur, photo, role, is_active FROM users WHERE email = ?');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public function create(string $name, string $email, string $password, string $titre = '', string $phone = '', string $secteur = '', ?string $photo = null): int {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password_hash, titre, phone, secteur, photo) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$name, $email, $hash, $titre, $phone, $secteur, $photo ?: null]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateProfile(int $userId, string $name, string $email, string $phone, ?string $photo = null): bool {
        if ($photo !== null) {
            $stmt = $this->pdo->prepare('UPDATE users SET name = ?, email = ?, phone = ?, photo = ? WHERE id = ?');
            return $stmt->execute([$name, $email, $phone, $photo ?: null, $userId]);
        }
        $stmt = $this->pdo->prepare('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?');
        return $stmt->execute([$name, $email, $phone, $userId]);
    }

    public function updatePassword(int $userId, string $newPassword): bool {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        return $stmt->execute([$hash, $userId]);
    }

    public function emailExists(string $email, ?int $excludeId = null): bool {
        if ($excludeId) {
            $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
            $stmt->execute([$email, $excludeId]);
        } else {
            $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
        }
        return (bool) $stmt->fetch();
    }

    public function addColumnsIfMissing(): void {
        $columns = [
            ['titre',     'VARCHAR(200)',          'NULL'],
            ['phone',     'VARCHAR(30)',           'NULL'],
            ['secteur',   'VARCHAR(50)',           'NULL'],
            ['role',      "ENUM('user','admin')",  "'user'"],
            ['is_active', 'TINYINT(1)',            '1'],
        ];
        foreach ($columns as [$name, $type, $default]) {
            try {
                $this->pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS $name $type DEFAULT $default");
            } catch (\Exception $e) {
                // Colonne déjà présente
            }
        }
    }

    /* ── Administration ──────────────────────────────────────── */

    public function findAll(): array {
        $stmt = $this->pdo->query(
            'SELECT id, name, email, titre, secteur, photo, role, is_active, created_at FROM users ORDER BY created_at DESC'
        );
        return $stmt->fetchAll();
    }

    public function setRole(int $userId, string $role): bool {
        if (!in_array($role, ['user', 'admin'], true)) return false;
        $stmt = $this->pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
        return $stmt->execute([$role, $userId]);
    }

    public function setActive(int $userId, bool $active): bool {
        $stmt = $this->pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?');
        return $stmt->execute([$active ? 1 : 0, $userId]);
    }

    public function deleteUser(int $userId): bool {
        $stmt = $this->pdo->prepare('DELETE FROM users WHERE id = ?');
        return $stmt->execute([$userId]);
    }

    public function countAdmins(): int {
        $stmt = $this->pdo->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin'");
        return (int) $stmt->fetch()['c'];
    }

    public function getApiKey(int $userId): ?array {
        $stmt = $this->pdo->prepare('SELECT api_key_encrypted, api_provider FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    public function saveApiKey(int $userId, string $apiKey, string $provider): bool {
        $stmt = $this->pdo->prepare('UPDATE users SET api_key_encrypted = ?, api_provider = ? WHERE id = ?');
        return $stmt->execute([$apiKey, $provider, $userId]);
    }
}
