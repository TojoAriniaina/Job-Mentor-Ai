<?php
namespace App\Controllers;

class TtsController {
    private function log(string $msg): void {
        error_log('[TtsController] ' . $msg);
    }

    public function speak(): void {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $text = trim($input['text'] ?? '');

        if ($text === '') {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Texte vide.']);
            return;
        }

        if (empty(ELEVENLABS_API_KEY)) {
            $this->log('ERREUR: ELEVENLABS_API_KEY non définie dans .env');
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Clé API ElevenLabs manquante. Vérifiez votre fichier .env.']);
            return;
        }

        $voiceId = ELEVENLABS_VOICE_ID;
        $this->log("Requête TTS voice_id={$voiceId} texte=" . mb_substr($text, 0, 60) . '...');
        $url = "https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}";

        $body = json_encode([
            'text' => $text,
            'model_id' => 'eleven_multilingual_v2',
            'voice_settings' => [
                'stability' => 0.5,
                'similarity_boost' => 0.7,
            ],
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'xi-api-key: ' . ELEVENLABS_API_KEY,
                'Content-Type: application/json',
            ],
        ]);

        $audio = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log('[TtsController] cURL: ' . $curlError);
            http_response_code(502);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Erreur réseau ElevenLabs : ' . $curlError]);
            return;
        }

        if ($httpCode !== 200) {
            $decoded = json_decode($audio, true);
            $errMsg = $decoded['detail']['message'] ?? ("Erreur ElevenLabs (HTTP $httpCode)");
            $this->log("ERREUR HTTP {$httpCode}: {$errMsg}");
            $this->log("Réponse brute: " . mb_substr($audio, 0, 300));
            http_response_code(502);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $errMsg]);
            return;
        }

        $this->log('Succès — audio renvoyé (' . strlen($audio) . ' octets)');

        http_response_code(200);
        header('Content-Type: audio/mpeg');
        header('Cache-Control: no-cache');
        echo $audio;
    }
}
