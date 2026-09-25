<?php
namespace App\Controllers;

use App\Middleware\Auth;

class TtsController {
    private function log(string $msg): void {
        error_log('[TtsController] ' . $msg);
    }

    public function speak(): void {
        Auth::require();

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $text = trim($input['text'] ?? '');

        if ($text === '') {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Texte vide.']);
            return;
        }

        // Comme pour OpenRouter : clé principale + clé de secours (ELEVENLABS_API_KEY_2)
        // en cas de quota dépassé ou de clé refusée sur la première.
        $keys = array_values(array_filter([
            ELEVENLABS_API_KEY,
            defined('ELEVENLABS_API_KEY_2') ? ELEVENLABS_API_KEY_2 : '',
        ], fn($k) => !empty($k)));

        if (empty($keys)) {
            $this->log('ERREUR: ELEVENLABS_API_KEY non définie dans .env');
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Clé API ElevenLabs manquante. Vérifiez votre fichier .env.']);
            return;
        }

        $voiceId = ELEVENLABS_VOICE_ID;
        $this->log("Requête TTS voice_id={$voiceId} texte=" . mb_substr($text, 0, 60) . '...');

        $body = json_encode([
            'text' => $text,
            'model_id' => 'eleven_multilingual_v2',
            'voice_settings' => [
                'stability' => 0.5,
                'similarity_boost' => 0.7,
            ],
        ]);

        $result = null;
        foreach ($keys as $i => $apiKey) {
            $result = $this->synthesize($apiKey, $voiceId, $body);
            if ($result['ok']) {
                break;
            }
            $isRetryable = in_array($result['reason'] ?? '', ['quota', 'auth'], true);
            if ($i < count($keys) - 1 && $isRetryable) {
                $this->log('Clé #' . ($i + 1) . ' en échec (' . $result['reason'] . '), bascule sur la clé ElevenLabs suivante.');
                continue;
            }
            break;
        }

        if ($result['ok']) {
            $this->log('Succès — audio renvoyé (' . strlen($result['audio']) . ' octets)');
            http_response_code(200);
            header('Content-Type: audio/mpeg');
            header('Cache-Control: no-cache');
            echo $result['audio'];
            return;
        }

        if ($result['reason'] === 'network') {
            error_log('[TtsController] cURL error: ' . $result['detail']);
            http_response_code(502);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Erreur réseau. Veuillez réessayer.']);
            return;
        }

        $httpCode = $result['http'];
        $errMsg = $result['detail'];
        $this->log("ERREUR HTTP {$httpCode}: {$errMsg}");

        // Message explicite côté utilisateur plutôt qu'un échec silencieux.
        $userMsg = 'Synthèse vocale indisponible. La voix du navigateur prend le relais.';
        $userCode = 'tts_error';
        $status = 502;
        if ($result['reason'] === 'quota') {
            $userMsg = 'Quota de synthèse vocale ElevenLabs dépassé. La voix du navigateur prend le relais.';
            $userCode = 'tts_quota';
            $status = 503;
        } elseif ($result['reason'] === 'auth') {
            $userMsg = 'Clé API ElevenLabs refusée (quota ou authentification). La voix du navigateur prend le relais.';
            $userCode = 'tts_auth';
            $status = 503;
        }

        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $userMsg, 'code' => $userCode, 'detail' => $errMsg]);
    }

    /** @return array{ok:bool, http?:int, audio?:string, reason?:string, detail?:string} */
    private function synthesize(string $apiKey, string $voiceId, string $body): array {
        $url = "https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'xi-api-key: ' . $apiKey,
                'Content-Type: application/json',
            ],
        ]);

        $audio = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['ok' => false, 'reason' => 'network', 'detail' => $curlError];
        }
        if ($httpCode === 200) {
            return ['ok' => true, 'http' => 200, 'audio' => $audio];
        }

        $decoded = json_decode($audio, true);
        $detail = $decoded['detail'] ?? [];
        $code = $detail['code'] ?? ($detail['status'] ?? '');
        $errMsg = $detail['message'] ?? ("Erreur ElevenLabs (HTTP $httpCode)");
        $this->log("Réponse brute: " . mb_substr($audio, 0, 300));

        $reason = 'error';
        if ($code === 'quota_exceeded' || $code === 'gcs_tts_quota_exceeded' || $httpCode === 429 || $httpCode === 402) {
            // 402 paid_plan_required : les comptes gratuits récents n'ont plus accès
            // aux voix de bibliothèque via API — une autre clé (ancien compte) peut marcher.
            $reason = 'quota';
        } elseif ($httpCode === 401) {
            $reason = 'auth';
        }
        return ['ok' => false, 'http' => $httpCode, 'reason' => $reason, 'detail' => $errMsg];
    }
}
