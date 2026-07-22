<?php
namespace App\Services;

class LlmService {
    public function call(array $messages, array $params = []): string {
        $keys = array_values(array_filter([
            OPENROUTER_API_KEY,
            defined('OPENROUTER_API_KEY_2') ? OPENROUTER_API_KEY_2 : ''
        ], fn($k) => !empty($k) && $k !== 'VOTRE_CLE_OPENROUTER_ICI'));

        if (empty($keys)) {
            throw new \Exception("Clé API manquante. Veuillez la configurer dans le fichier .env (OPENROUTER_API_KEY).");
        }

        $lastError = null;
        foreach ($keys as $i => $apiKey) {
            try {
                return $this->attempt($apiKey, $messages, $params);
            } catch (\Exception $e) {
                $lastError = $e;
                // Si ce n'est pas la dernière clé disponible, on retente avec la suivante
                // uniquement pour les erreurs typiques de quota / clé invalide / rate-limit.
                $isRetryable = preg_match('/rate.?limit|quota|429|401|403|invalid.*key/i', $e->getMessage());
                if ($i < count($keys) - 1 && $isRetryable) {
                    error_log('[LlmService] Clé #' . ($i + 1) . ' en échec (' . $e->getMessage() . '), tentative avec la clé suivante.');
                    continue;
                }
                error_log('[LlmService] Échec définitif après ' . ($i + 1) . ' clé(s) testée(s) : ' . $e->getMessage());
                throw $e;
            }
        }

        throw $lastError;
    }

    private function attempt(string $apiKey, array $messages, array $params = []): string {
        $model = $params['model'] ?? LLM_MODEL;
        $url = LLM_API_URL;

        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $params['temperature'] ?? 0.7,
            'max_tokens'  => $params['max_tokens']  ?? 2048,
        ];

        $ch = curl_init($url);
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: http://localhost/Job_Mentor',
            'X-Title: JobMentor AI'
        ];

        curl_setopt_array($ch, [
            CURLOPT_IPRESOLVE        => CURL_IPRESOLVE_V4,
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_POST             => true,
            CURLOPT_POSTFIELDS       => json_encode($body),
            CURLOPT_HTTPHEADER       => $headers,
            CURLOPT_SSL_VERIFYPEER   => false,
            CURLOPT_TIMEOUT          => 60
        ]);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception("Erreur réseau cURL: " . $error);
        }
        curl_close($ch);

        $data = json_decode($response, true);

        if (isset($data['error'])) {
            $errorMsg = is_array($data['error']) ? ($data['error']['message'] ?? 'Erreur inconnue') : $data['error'];
            throw new \Exception("Erreur API (openrouter): " . $errorMsg);
        }

        if (empty($data['choices'][0]['message']['content'])) {
            throw new \Exception("Réponse API vide ou format inattendu (openrouter).");
        }

        return $data['choices'][0]['message']['content'];
    }

    public function extractJson(string $text): ?array {
        if (empty($text)) return null;

        $text = preg_replace('/```json\s*/i', '', $text);
        $text = preg_replace('/```\s*/',      '', $text);
        $text = trim($text);

        $json = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE) return $json;

        if (preg_match('/\{[\s\S]*\}/s', $text, $matches)) {
            $json = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) return $json;
        }

        return null;
    }
}
