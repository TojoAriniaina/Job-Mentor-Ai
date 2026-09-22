<?php
namespace App\Controllers;

use App\Models\User;
use App\Middleware\Auth;
use App\Services\EncryptService;

class UserController {
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

    public function saveApikey(): void {
        $userId = Auth::require();
        $input = $this->input();
        $apiKey   = trim($input['api_key'] ?? '');
        $provider = $input['provider'] ?? 'openrouter';

        try {
            $encrypted = EncryptService::encrypt($apiKey);
        } catch (\Throwable $e) {
            error_log('[UserController] ' . $e->getMessage());
            $this->json(['success' => false, 'error' => "Chiffrement impossible : vérifiez APP_KEY dans .env"], 500);
            return;
        }
        $this->userModel->saveApiKey($userId, $encrypted, $provider);
        $this->json(['success' => true]);
    }

    public function getApikey(): void {
        $userId = Auth::require();
        $row = $this->userModel->getApiKey($userId);

        if ($row && $row['api_key_encrypted']) {
            $raw = $row['api_key_encrypted'];

            try {
                $key = EncryptService::decrypt($raw);
            } catch (\Throwable $e) {
                $this->json(['success' => false, 'error' => 'Erreur déchiffrement clé API']);
                return;
            }

            // Migration automatique : si l'ancien format Base64, ré-encoder en AES
            if (!EncryptService::isAesEncrypted($raw)) {
                $this->userModel->saveApiKey(
                    $userId,
                    EncryptService::encrypt($key),
                    $row['api_provider']
                );
            }

            $this->json(['success' => true, 'api_key' => $key, 'provider' => $row['api_provider']]);
        } else {
            $this->json(['success' => false, 'error' => 'Aucune clé configurée']);
        }
    }
}
