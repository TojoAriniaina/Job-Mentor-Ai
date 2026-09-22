<?php
namespace App\Services;

class EncryptService {
    private const CIPHER = 'aes-256-cbc';
    private static ?string $key = null;

    private static function key(): string {
        if (self::$key !== null) return self::$key;
        $raw = defined('APP_KEY') ? APP_KEY : '';
        if (empty($raw)) {
            throw new \RuntimeException('APP_KEY non configurée dans .env');
        }
        self::$key = hex2bin($raw);
        return self::$key;
    }

    /**
     * Chiffre une chaîne avec AES-256-CBC.
     * Retourne "iv_hex:iphertext_hex".
     */
    public static function encrypt(string $plaintext): string {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            throw new \RuntimeException('Échec du chiffrement');
        }
        return bin2hex($iv) . ':' . bin2hex($encrypted);
    }

    /**
     * Déchiffre une chaîne encryptée par encrypt().
     * Accepte aussi du Base64 pur (migration depuis l'ancien format).
     */
    public static function decrypt(string $data): string {
        // Format AES : "iv_hex:ciphertext_hex"
        if (preg_match('/^[0-9a-f]{32}:[0-9a-f]+$/', $data)) {
            [$ivHex, $encHex] = explode(':', $data, 2);
            $iv = hex2bin($ivHex);
            $encrypted = hex2bin($encHex);
            $decrypted = openssl_decrypt($encrypted, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv);
            if ($decrypted === false) {
                throw new \RuntimeException('Échec du déchiffrement AES');
            }
            return $decrypted;
        }

        // Fallback Base64 (ancien format) — on retourne la valeur décodée
        // pour que l'appelant puisse la ré-encoder en AES
        $decoded = base64_decode($data, true);
        if ($decoded !== false && !str_contains($data, ' ')) {
            return $decoded;
        }

        // Ni AES ni Base64 valide — retourner tel quel
        return $data;
    }

    /**
     * Vérifie si une valeur est déjà chiffrée en AES (pas en Base64).
     */
    public static function isAesEncrypted(string $data): bool {
        return (bool) preg_match('/^[0-9a-f]{32}:[0-9a-f]+$/', $data);
    }
}
