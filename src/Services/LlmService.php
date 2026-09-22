<?php
namespace App\Services;

class LlmService {
    public function call(array $messages, array $params = []): string {
        $messages = $this->injectCurrentDate($messages);

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
                return $this->attemptWithRetry($apiKey, $messages, $params);
            } catch (\Exception $e) {
                $lastError = $e;
                $msg = $e->getMessage();
                $isRetryable = preg_match('/rate.?limit|quota|429|401|403|invalid.*key/i', $msg);
                if ($i < count($keys) - 1 && $isRetryable) {
                    error_log('[LlmService] Clé #' . ($i + 1) . ' en échec (' . $msg . '), tentative avec la clé suivante.');
                    continue;
                }
                error_log('[LlmService] Échec définitif après ' . ($i + 1) . ' clé(s) testée(s) : ' . $msg);
                throw $e;
            }
        }

        throw $lastError;
    }

    // Le modèle a une coupure de connaissances antérieure à l'année courante :
    // sans date de référence il marque à tort « incohérentes » des dates 2025/2026 sur les CV.
    private function injectCurrentDate(array $messages): array {
        $note = "\nDate du jour : " . date('d/m/Y') . " (année " . date('Y') . "). Une date de l'année en cours ou d'une année passée n'est JAMAIS future ni incohérente. Les dates légèrement futures (stage, diplôme ou mission prévus) sont normales sur un CV ou une lettre : ne les signale pas comme erreur.";
        foreach ($messages as $i => $m) {
            if (($m['role'] ?? '') === 'system') {
                $messages[$i]['content'] .= $note;
                return $messages;
            }
        }
        array_unshift($messages, ['role' => 'system', 'content' => 'Tu es un assistant professionnel.' . $note]);
        return $messages;
    }

    private function attemptWithRetry(string $apiKey, array $messages, array $params = [], int $maxRetries = 2): string {
        $lastError = null;
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                return $this->attempt($apiKey, $messages, $params);
            } catch (\Exception $e) {
                $lastError = $e;
                $msg = $e->getMessage();
                $isNetworkError = preg_match('/HTTP2 framing layer|Failed to connect|cURL|timeout|reset by peer|connection refused/i', $msg);
                if ($isNetworkError && $attempt < $maxRetries) {
                    $delay = ($attempt + 1) * 2;
                    error_log('[LlmService] Erreur réseau (tentative ' . ($attempt + 1) . '/' . ($maxRetries + 1) . '), retry dans ' . $delay . 's : ' . $msg);
                    sleep($delay);
                    continue;
                }
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
            CURLOPT_SSL_VERIFYPEER   => true,
            CURLOPT_TIMEOUT          => 60
        ]);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            error_log('[LlmService] cURL error: ' . $error);
            throw new \Exception("Erreur réseau. Veuillez réessayer.");
        }
        curl_close($ch);

        $data = json_decode($response, true);

        if (isset($data['error'])) {
            $errorMsg = is_array($data['error']) ? ($data['error']['message'] ?? 'Erreur inconnue') : $data['error'];
            error_log('[LlmService] API error: ' . $errorMsg);
            throw new \Exception("Erreur du service IA. Veuillez réessayer.");
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
