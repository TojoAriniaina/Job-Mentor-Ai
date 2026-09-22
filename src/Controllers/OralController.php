<?php
namespace App\Controllers;

use App\Models\OralAnalysis;
use App\Middleware\Auth;
use App\Services\LlmService;

class OralController {
    private OralAnalysis $oralModel;
    private LlmService $llm;

    public function __construct() {
        $this->oralModel = new OralAnalysis($GLOBALS['pdo']);
        $this->llm = new LlmService();
    }

    private function input(): array {
        return json_decode(file_get_contents('php://input'), true) ?: [];
    }

    private function json(array $data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    public function analyze(): void {
        $userId = Auth::require();
        $input = $this->input();

        $transcription = $input['transcription'] ?? '';
        $poste        = $input['poste']         ?? 'Emploi';
        $langueCode   = $input['langue']        ?? 'fr-FR';
        $question     = $input['question']      ?? '';

        if (empty($transcription)) {
            $this->json(['success' => false, 'error' => 'Transcription manquante'], 400);
            return;
        }

        // Limite de longueur : tronquer à ~2000 mots pour éviter overflow contexte LLM
        $wordCount = str_word_count($transcription);
        if ($wordCount > 2000) {
            $words = preg_split('/\s+/', $transcription, 2001);
            array_pop($words);
            $transcription = implode(' ', $words) . '...';
        }

        // Scores algorithmiques partiels (déterministes)
        $algoScores = $this->computeAlgorithmicScores($transcription);

        $languesDisponibles = [
            'fr-FR' => ['nom' => 'français', 'hesitations' => 'euh, bah, donc, voilà, du coup'],
            'en-US' => ['nom' => 'anglais',  'hesitations' => 'um, uh, like, so, you know'],
            'es-ES' => ['nom' => 'espagnol', 'hesitations' => 'eh, esto, bueno, o sea, pues'],
        ];
        $langueInfo = $languesDisponibles[$langueCode] ?? $languesDisponibles['fr-FR'];
        $langueNom        = $langueInfo['nom'];
        $hesitationsTypes = $langueInfo['hesitations'];

        $questionContext = !empty($question) ? "\nQuestion posée par le recruteur : \"$question\"\nAnalyse la réponse du candidat par rapport à cette question." : '';

        $prompt  = "Tu es un expert en communication et recruteur. Analyse cette transcription d'une réponse orale d'un candidat pour le poste de \"$poste\".\n";
        $prompt .= "La transcription est en $langueNom. Rédige TOUTE ta réponse (points forts, axes d'amélioration, reformulation, conseil) en $langueNom, quelle que soit la langue de ces instructions.\n";
        $prompt .= $questionContext . "\n";
        $prompt .= "Transcription : \"$transcription\"\n\n";
        $prompt .= "IMPORTANT : les scores fluidite, structure, vocabulaire, clarte seront VÉRIFIÉS algorithmiquement. Donne ton évaluation la plus honnête possible.\n\n";
        $prompt .= "1. Donne un score global sur 100.\n";
        $prompt .= "2. Donne des scores (0-100) pour : fluidité, structure, vocabulaire, clarté.\n";
        $prompt .= "3. Identifie les mots d'hésitation présents, typiques du $langueNom (ex : $hesitationsTypes).\n";
        $prompt .= "4. Liste 3 points forts et 3 axes d'amélioration, en $langueNom.\n";
        $prompt .= "5. Propose une reformulation percutante de sa réponse, en $langueNom.\n";
        $prompt .= "6. Donne un conseil principal pour son prochain entretien, en $langueNom.\n\n";
        $prompt .= "Réponds UNIQUEMENT avec ce JSON valide (aucun texte avant ou après), avec toutes les valeurs textuelles en $langueNom :\n";
        $prompt .= '{
  "score_global": 75,
  "scores": { "fluidite": 80, "structure": 70, "vocabulaire": 75, "clarte": 75 },
  "hesitations": ["euh", "bah"],
  "points_forts": ["Point fort 1", "Point fort 2", "Point fort 3"],
  "axes_amelioration": ["Axe 1", "Axe 2", "Axe 3"],
  "reformulation": "Une meilleure version de la réponse...",
  "conseil_principal": "Le conseil clé..."
}';

        try {
            $raw = $this->llm->call([
                ['role' => 'system', 'content' => 'Tu es un expert RH et coach en expression orale. Réponds UNIQUEMENT en JSON.'],
                ['role' => 'user',   'content' => $prompt]
            ], ['temperature' => 0.4, 'max_tokens' => 2000]);

            $json_data = $this->llm->extractJson($raw);
            if (!$json_data) throw new \Exception("Erreur d'analyse de la réponse de l'IA.");

            // Validation du schéma : s'assurer que les champs requis existent
            $json_data = $this->validateSchema($json_data);

            // Mélanger les scores : 40% algorithmique + 60% LLM pour les métriques quantifiables
            $json_data['scores']['fluidite']    = (int) round($algoScores['fluidite'] * 0.4 + ($json_data['scores']['fluidite'] ?? 70) * 0.6);
            $json_data['scores']['structure']   = (int) round($algoScores['structure'] * 0.4 + ($json_data['scores']['structure'] ?? 70) * 0.6);
            $json_data['scores']['vocabulaire'] = (int) round($algoScores['vocabulaire'] * 0.4 + ($json_data['scores']['vocabulaire'] ?? 70) * 0.6);
            $json_data['scores']['clarte']      = (int) round($algoScores['clarte'] * 0.4 + ($json_data['scores']['clarte'] ?? 70) * 0.6);

            // Score global recalculé à partir des scores mélangés
            $scores = $json_data['scores'];
            $json_data['score_global'] = max(0, min(100, (int) round(
                $scores['fluidite'] * 0.25 + $scores['structure'] * 0.25 +
                $scores['vocabulaire'] * 0.25 + $scores['clarte'] * 0.25
            )));

            $score = $json_data['score_global'] ?? 0;
            $this->oralModel->create($userId, $poste, $transcription, $json_data, $score);

            $this->json(['success' => true, 'data' => $json_data]);
        } catch (\Exception $e) {
            error_log('[OralController] ' . $e->getMessage());
            $this->json(['success' => false, 'error' => 'Erreur interne'], 500);
        }
    }

    private function computeAlgorithmicScores(string $transcription): array {
        $text = trim($transcription);
        $wordCount = str_word_count(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text));
        $sentences = preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $sentenceCount = count(array_filter($sentences, fn($s) => trim($s) !== ''));
        $words = array_filter(preg_split('/\s+/', mb_strtolower($text, 'UTF-8')), fn($w) => mb_strlen($w, 'UTF-8') > 3);
        $uniqueRatio = count($words) > 0 ? count(array_unique($words)) / count($words) : 0;

        // Fluidité : longueur moyenne des phrases (ni trop courtes ni trop longues)
        $avgWordsPerSentence = $sentenceCount > 0 ? $wordCount / $sentenceCount : 0;
        if ($avgWordsPerSentence >= 8 && $avgWordsPerSentence <= 25) $fluidite = 85;
        elseif ($avgWordsPerSentence >= 5 && $avgWordsPerSentence <= 35) $fluidite = 70;
        else $fluidite = 50;
        // Bonus longueur globale
        if ($wordCount >= 30) $fluidite = min(100, $fluidite + 10);

        // Structure : nombre de phrases (idéal 3-8 pour une réponse orale)
        if ($sentenceCount >= 3 && $sentenceCount <= 8) $structure = 85;
        elseif ($sentenceCount >= 2 && $sentenceCount <= 12) $structure = 70;
        else $structure = 50;

        // Vocabulaire : ratio mots uniques
        $vocabulaire = (int) round(min($uniqueRatio * 120, 100));

        // Clarté : combinaison de structure et vocabulaire
        $clarte = (int) round(($structure * 0.5 + $vocabulaire * 0.5));

        return [
            'fluidite' => max(0, min(100, $fluidite)),
            'structure' => max(0, min(100, $structure)),
            'vocabulaire' => max(0, min(100, $vocabulaire)),
            'clarte' => max(0, min(100, $clarte)),
        ];
    }

    private function validateSchema(array $data): array {
        $required = ['score_global', 'scores', 'points_forts', 'axes_amelioration', 'reformulation', 'conseil_principal'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                $data[$field] = $field === 'scores'
                    ? ['fluidite' => 70, 'structure' => 70, 'vocabulaire' => 70, 'clarte' => 70]
                    : ($field === 'score_global' ? 70 : []);
            }
        }
        if (!is_array($data['scores'])) $data['scores'] = ['fluidite' => 70, 'structure' => 70, 'vocabulaire' => 70, 'clarte' => 70];
        foreach (['fluidite', 'structure', 'vocabulaire', 'clarte'] as $k) {
            if (!isset($data['scores'][$k])) $data['scores'][$k] = 70;
        }
        if (!is_array($data['points_forts'])) $data['points_forts'] = [];
        if (!is_array($data['axes_amelioration'])) $data['axes_amelioration'] = [];
        if (empty($data['hesitations']) || !is_array($data['hesitations'])) $data['hesitations'] = [];
        return $data;
    }

    public function list(): void {
        $userId = Auth::require();
        $history = $this->oralModel->findByUser($userId);
        $this->json(['success' => true, 'history' => $history]);
    }

    public function get(): void {
        $userId = Auth::require();
        $id = $_REQUEST['id'] ?? null;
        $analysis = $this->oralModel->findById((int)$id, $userId);
        if (!$analysis) {
            $this->json(['success' => false, 'error' => 'Analyse introuvable'], 404);
            return;
        }
        $this->json(['success' => true, 'data' => $analysis]);
    }

    public function delete(): void {
        $userId = Auth::require();
        $id = $_REQUEST['id'] ?? null;
        $this->oralModel->delete((int)$id, $userId);
        $this->json(['success' => true]);
    }
}
