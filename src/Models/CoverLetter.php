<?php
namespace App\Models;

use PDO;

class CoverLetter {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function findByUser(int $userId): array {
        $stmt = $this->pdo->prepare('SELECT id, job_offer, score, created_at FROM cover_letters WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function findById(int $id, int $userId): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM cover_letters WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        return $stmt->fetch() ?: null;
    }

    public function create(int $userId, string $content, string $jobOffer = '', int $score = 0): int {
        $stmt = $this->pdo->prepare('INSERT INTO cover_letters (user_id, job_offer, content, score) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $jobOffer, $content, $score]);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id, int $userId): bool {
        $stmt = $this->pdo->prepare('DELETE FROM cover_letters WHERE id = ? AND user_id = ?');
        return $stmt->execute([$id, $userId]);
    }
}
