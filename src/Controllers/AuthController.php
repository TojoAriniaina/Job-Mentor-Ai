<?php
namespace App\Controllers;

use App\Models\User;
use App\Middleware\Auth;

class AuthController {
    private User $userModel;

    public function __construct() {
        $this->userModel = new User($GLOBALS['pdo']);
    }

    private function input(): array {
        return json_decode(file_get_contents('php://input'), true) ?: [];
    }

    private function json(array $data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    private function buildProfilePayload(array $user, ?string $email = null): array {
        return [
            'name'    => $user['name'] ?? '',
            'email'   => $email ?? ($user['email'] ?? ''),
            'titre'   => $user['titre'] ?? '',
            'phone'   => $user['phone'] ?? '',
            'secteur' => $user['secteur'] ?? '',
            'photo'   => $user['photo'] ?? ''
        ];
    }

    public function register(): void {
        $input = $this->input();
        $name    = trim($input['name'] ?? '');
        $email   = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $titre   = trim($input['titre'] ?? '');
        $phone   = trim($input['phone'] ?? '');
        $secteur = trim($input['secteur'] ?? '');
        $photo   = $input['photo'] ?? '';

        if (!$name || !$email || !$password) {
            $this->json(['success' => false, 'error' => 'Tous les champs sont requis'], 400);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->json(['success' => false, 'error' => 'Adresse email invalide'], 400);
            return;
        }

        if (mb_strlen($password, 'UTF-8') < 8) {
            $this->json(['success' => false, 'error' => 'Le mot de passe doit contenir au moins 8 caractères'], 400);
            return;
        }

        if ($this->userModel->emailExists($email)) {
            $this->json(['success' => false, 'error' => 'Email déjà utilisé'], 409);
            return;
        }

        try {
            $this->userModel->addColumnsIfMissing();
            $userId = $this->userModel->create($name, $email, $password, $titre, $phone, $secteur, $photo ?: null);

            $_SESSION['user_id'] = $userId;
            $_SESSION['user_name'] = $name;
            $_SESSION['role'] = 'user';

            $user = $this->userModel->findById($userId);
            $this->json([
                'success' => true,
                'user' => ['id' => $userId, 'name' => $name, 'email' => $email, 'role' => 'user'],
                'profile' => $this->buildProfilePayload($user, $email)
            ]);
        } catch (\PDOException $e) {
            error_log('[AuthController] ' . $e->getMessage());
            $this->json(['success' => false, 'error' => 'Erreur DB : ' . $e->getMessage()], 500);
        }
    }

    public function login(): void {
        $input = $this->input();
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';

        if (!$email || !$password) {
            $this->json(['success' => false, 'error' => 'Champs requis'], 400);
            return;
        }

        $user = $this->userModel->findByEmail($email);

        if ($user && password_verify($password, $user['password_hash'])) {
            if (isset($user['is_active']) && (int) $user['is_active'] === 0) {
                $this->json(['success' => false, 'error' => 'Ce compte a été désactivé.'], 403);
                return;
            }

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['role'] = $user['role'] ?? 'user';

            $this->json([
                'success' => true,
                'user' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role'] ?? 'user'],
                'profile' => $this->buildProfilePayload($user)
            ]);
        } else {
            $this->json(['success' => false, 'error' => 'Identifiants incorrects'], 401);
        }
    }

    public function logout(): void {
        session_destroy();
        $this->json(['success' => true]);
    }

    public function check(): void {
        if (isset($_SESSION['user_id'])) {
            $user = $this->userModel->findById($_SESSION['user_id']);
            if (!$user) {
                session_destroy();
                $this->json(['success' => false, 'error' => 'Non connecté']);
                return;
            }
            if (isset($user['is_active']) && (int) $user['is_active'] === 0) {
                session_destroy();
                $this->json(['success' => false, 'error' => 'Ce compte a été désactivé.']);
                return;
            }
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['role'] = $user['role'] ?? 'user';
            $this->json([
                'success' => true,
                'user' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'photo' => $user['photo'] ?? '', 'role' => $user['role'] ?? 'user'],
                'profile' => $this->buildProfilePayload($user)
            ]);
        } else {
            $this->json(['success' => false, 'error' => 'Non connecté']);
        }
    }

    public function requestReset(): void {
        $input = $this->input();
        $email = trim($input['email'] ?? '');

        if (!$email) {
            $this->json(['success' => false, 'error' => 'Email requis'], 400);
            return;
        }

        $user = $this->userModel->findByEmail($email);

        if ($user) {
            $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['reset_code']       = password_hash($code, PASSWORD_DEFAULT);
            $_SESSION['reset_email']      = $email;
            $_SESSION['reset_expires_at'] = time() + 900;

            $this->json([
                'success' => true,
                'dev_code' => $code,
                'message' => 'Code généré (dev: visible dans la réponse)'
            ]);
        } else {
            $this->json([
                'success' => true,
                'message' => 'Si cet email existe, un code vous a été envoyé'
            ]);
        }
    }

    public function resetPassword(): void {
        $input = $this->input();
        $email        = trim($input['email'] ?? '');
        $code         = trim($input['code'] ?? '');
        $new_password = $input['new_password'] ?? '';

        if (!$email || !$code || !$new_password) {
            $this->json(['success' => false, 'error' => 'Email, code et nouveau mot de passe requis'], 400);
            return;
        }

        if (empty($_SESSION['reset_code']) || empty($_SESSION['reset_email']) || empty($_SESSION['reset_expires_at'])) {
            $this->json(['success' => false, 'error' => 'Aucune demande de réinitialisation en cours. Recommencez.']);
            return;
        }

        if (time() > $_SESSION['reset_expires_at']) {
            unset($_SESSION['reset_code'], $_SESSION['reset_email'], $_SESSION['reset_expires_at']);
            $this->json(['success' => false, 'error' => 'Le code a expiré (15 min). Recommencez.']);
            return;
        }

        if ($_SESSION['reset_email'] !== $email) {
            $this->json(['success' => false, 'error' => 'Email ne correspond pas à la demande en cours.']);
            return;
        }

        if (!password_verify($code, $_SESSION['reset_code'])) {
            $this->json(['success' => false, 'error' => 'Code incorrect.']);
            return;
        }

        $user = $this->userModel->findByEmail($email);
        if (!$user) {
            $this->json(['success' => false, 'error' => 'Utilisateur introuvable.']);
            return;
        }

        if ($this->userModel->updatePassword($user['id'], $new_password)) {
            unset($_SESSION['reset_code'], $_SESSION['reset_email'], $_SESSION['reset_expires_at']);
            $this->json(['success' => true]);
        } else {
            $this->json(['success' => false, 'error' => 'Erreur lors de la réinitialisation'], 500);
        }
    }

    public function updateProfile(): void {
        if (!isset($_SESSION['user_id'])) {
            $this->json(['success' => false, 'error' => 'Non connecté'], 401);
            return;
        }

        $userId = $_SESSION['user_id'];
        $input = $this->input();
        $name  = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $phone = trim($input['phone'] ?? '');

        if (!$name) {
            $this->json(['success' => false, 'error' => 'Le nom ne peut pas être vide'], 400);
            return;
        }
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->json(['success' => false, 'error' => 'Email invalide'], 400);
            return;
        }

        if ($this->userModel->emailExists($email, $userId)) {
            $this->json(['success' => false, 'error' => 'Cet email est déjà utilisé par un autre compte'], 409);
            return;
        }

        $photoProvided = array_key_exists('photo', (array) $input);
        $photo = $photoProvided ? ($input['photo'] ?? '') : null;

        try {
            $this->userModel->updateProfile($userId, $name, $email, $phone, $photo);
            $_SESSION['user_name'] = $name;

            $user = $this->userModel->findById($userId);
            $this->json([
                'success' => true,
                'user' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'photo' => $user['photo'] ?? ''],
                'profile' => $this->buildProfilePayload($user)
            ]);
        } catch (\PDOException $e) {
            error_log('[AuthController] ' . $e->getMessage());
            $this->json(['success' => false, 'error' => 'Erreur DB : ' . $e->getMessage()], 500);
        }
    }
}
