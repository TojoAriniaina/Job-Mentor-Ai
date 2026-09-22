<?php
namespace App\Models;

use PDO;

class UserNote {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function findByUser(int $userId): array {
        $stmt = $this->pdo->prepare('SELECT id, title, content, created_at FROM user_notes WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function findById(int $id, int $userId): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM user_notes WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        return $stmt->fetch() ?: null;
    }

    public function create(int $userId, string $title, string $content): int {
        $stmt = $this->pdo->prepare('INSERT INTO user_notes (user_id, title, content) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $title, $content]);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id, int $userId): bool {
        $stmt = $this->pdo->prepare('DELETE FROM user_notes WHERE id = ? AND user_id = ?');
        return $stmt->execute([$id, $userId]);
    }
}
