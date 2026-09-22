<?php
namespace App\Models;

use PDO;

class CvDocument {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function findByUser(int $userId): array {
        $stmt = $this->pdo->prepare('SELECT id, title, score_ats, created_at FROM cv_documents WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function findById(int $id, int $userId): ?array {
        $stmt = $this->pdo->prepare('SELECT json_content FROM cv_documents WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        $cv = $stmt->fetch();
        if (!$cv) return null;
        return json_decode($cv['json_content'], true);
    }

    public function create(int $userId, string $title, array $jsonData, int $score): int {
        $stmt = $this->pdo->prepare('INSERT INTO cv_documents (user_id, title, json_content, score_ats) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $title, json_encode($jsonData), $score]);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id, int $userId): bool {
        $stmt = $this->pdo->prepare('DELETE FROM cv_documents WHERE id = ? AND user_id = ?');
        return $stmt->execute([$id, $userId]);
    }
}
