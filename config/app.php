<?php
// config/app.php — Constantes de configuration (remplace les defines de l'ancien config.php)

function loadEnv($path) {
    if (!file_exists($path)) return false;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            putenv("$name=$value");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
    return true;
}

loadEnv(__DIR__ . '/../.env');

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'jobmentor_db');

define('LLM_MODEL', getenv('LLM_MODEL') ?: 'google/gemini-2.0-flash-001');
define('LLM_API_URL', getenv('LLM_API_URL') ?: 'https://openrouter.ai/api/v1/chat/completions');

// URL absolue du site une fois un nom de domaine réel en place (ex: https://jobmentor-ai.mg).
// Sert notamment aux balises Open Graph (og:image, og:url) dans public/frontend/index.html,
// qui doivent être des URLs absolues pour que les aperçus de lien (réseaux sociaux,
// messageries) fonctionnent. Laisser vide en développement local.
define('APP_URL', rtrim(getenv('APP_URL') ?: '', '/'));
define('OPENROUTER_API_KEY', getenv('OPENROUTER_API_KEY') ?: '');
define('OPENROUTER_API_KEY_2', getenv('OPENROUTER_API_KEY_2') ?: '');

define('ELEVENLABS_API_KEY', getenv('ELEVENLABS_API_KEY') ?: '');
define('ELEVENLABS_VOICE_ID', getenv('ELEVENLABS_VOICE_ID') ?: '21m00Tcm4TlvDq8ikWAM');
