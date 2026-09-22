<?php
namespace App\Models;

use PDO;

class OralAnalysis {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function findByUser(int $userId): array {
        $stmt = $this->pdo->prepare('SELECT id, job_title, score_global, created_at FROM oral_analyses WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function findById(int $id, int $userId): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM oral_analyses WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        return $stmt->fetch() ?: null;
    }

    public function create(int $userId, string $jobTitle, string $transcription, array $analysisJson, int $scoreGlobal): int {
        $stmt = $this->pdo->prepare('INSERT INTO oral_analyses (user_id, job_title, transcription, analysis_json, score_global) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $jobTitle, $transcription, json_encode($analysisJson), $scoreGlobal]);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id, int $userId): bool {
        $stmt = $this->pdo->prepare('DELETE FROM oral_analyses WHERE id = ? AND user_id = ?');
        return $stmt->execute([$id, $userId]);
    }
}
