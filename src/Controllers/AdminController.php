<?php
namespace App\Controllers;

use App\Models\User;
use App\Middleware\Auth;

class AdminController {
    private User $userModel;
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
        $this->userModel = new User($this->pdo);
    }

    private function input(): array {
        return json_decode(file_get_contents('php://input'), true) ?: [];
    }

    private function json(array $data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    /**
     * GET /api/admin/users
     * Liste tous les utilisateurs avec leurs infos de base.
     */
    public function users(): void {
        Auth::requireAdmin();
        $users = $this->userModel->findAll();
        $this->json(['success' => true, 'users' => $users]);
    }

    /**
     * GET /api/admin/stats
     * Compteurs globaux d'usage de la plateforme.
     */
    public function stats(): void {
        Auth::requireAdmin();

        $count = function (string $table): int {
            $stmt = $this->pdo->query("SELECT COUNT(*) AS c FROM $table");
            return (int) $stmt->fetch()['c'];
        };

        $recentSignups = $this->pdo->query(
            "SELECT COUNT(*) AS c FROM users WHERE created_at >= (NOW() - INTERVAL 7 DAY)"
        )->fetch()['c'];

        $this->json([
            'success' => true,
            'stats' => [
                'total_users'      => $count('users'),
                'active_users'     => (int) $this->pdo->query("SELECT COUNT(*) AS c FROM users WHERE is_active = 1")->fetch()['c'],
                'new_users_7j'     => (int) $recentSignups,
                'total_cv'         => $count('cv_documents'),
                'total_lettres'    => $count('cover_letters'),
                'total_entretiens' => $count('interview_history'),
                'total_oral'       => $count('oral_analyses'),
            ]
        ]);
    }

    /**
     * POST /api/admin/users/{id}/status  { "active": true|false }
     * Active ou désactive un compte (sans le supprimer).
     */
    public function setStatus(): void {
        $adminId = Auth::requireAdmin();
        $targetId = (int) ($_REQUEST['id'] ?? 0);
        $input = $this->input();

        if (!$targetId) {
            $this->json(['success' => false, 'error' => 'Utilisateur invalide'], 400);
            return;
        }
        if ($targetId === $adminId) {
            $this->json(['success' => false, 'error' => 'Vous ne pouvez pas désactiver votre propre compte'], 400);
            return;
        }

        $active = !empty($input['active']);
        $ok = $this->userModel->setActive($targetId, $active);
        $this->json(['success' => $ok]);
    }

    /**
     * POST /api/admin/users/{id}/role  { "role": "user"|"admin" }
     */
    public function setRole(): void {
        $adminId = Auth::requireAdmin();
        $targetId = (int) ($_REQUEST['id'] ?? 0);
        $input = $this->input();
        $role = $input['role'] ?? '';

        if (!$targetId || !in_array($role, ['user', 'admin'], true)) {
            $this->json(['success' => false, 'error' => 'Paramètres invalides'], 400);
            return;
        }

        // Empêche de se retirer soi-même le rôle admin si on est le dernier admin
        if ($targetId === $adminId && $role === 'user' && $this->userModel->countAdmins() <= 1) {
            $this->json(['success' => false, 'error' => 'Impossible de retirer le dernier compte administrateur'], 400);
            return;
        }

        $ok = $this->userModel->setRole($targetId, $role);
        $this->json(['success' => $ok]);
    }

    /**
     * DELETE /api/admin/users/{id}
     * Supprime définitivement un compte (et ses données via ON DELETE CASCADE).
     */
    public function deleteUser(): void {
        $adminId = Auth::requireAdmin();
        $targetId = (int) ($_REQUEST['id'] ?? 0);

        if (!$targetId) {
            $this->json(['success' => false, 'error' => 'Utilisateur invalide'], 400);
            return;
        }
        if ($targetId === $adminId) {
            $this->json(['success' => false, 'error' => 'Vous ne pouvez pas supprimer votre propre compte'], 400);
            return;
        }

        $ok = $this->userModel->deleteUser($targetId);
        $this->json(['success' => $ok]);
    }
}
