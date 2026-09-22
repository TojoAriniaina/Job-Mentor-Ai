<?php
namespace App\Models;

use PDO;

class InterviewHistory {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function findByUser(int $userId): array {
        $stmt = $this->pdo->prepare('SELECT id, job_title, global_score, created_at FROM interview_history WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function findById(int $id, int $userId): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM interview_history WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        return $stmt->fetch() ?: null;
    }

    public function create(int $userId, string $jobTitle, int $globalScore, array $transcript): int {
        $stmt = $this->pdo->prepare('INSERT INTO interview_history (user_id, job_title, global_score, transcript) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $jobTitle, $globalScore, json_encode($transcript)]);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id, int $userId): bool {
        $stmt = $this->pdo->prepare('DELETE FROM interview_history WHERE id = ? AND user_id = ?');
        return $stmt->execute([$id, $userId]);
    }
}
