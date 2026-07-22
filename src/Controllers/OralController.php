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

        if (empty($transcription)) {
            $this->json(['success' => false, 'error' => 'Transcription manquante'], 400);
            return;
        }

        $languesDisponibles = [
            'fr-FR' => ['nom' => 'français', 'hesitations' => 'euh, bah, donc, voilà, du coup'],
            'en-US' => ['nom' => 'anglais',  'hesitations' => 'um, uh, like, so, you know'],
            'es-ES' => ['nom' => 'espagnol', 'hesitations' => 'eh, esto, bueno, o sea, pues'],
        ];
        $langueInfo = $languesDisponibles[$langueCode] ?? $languesDisponibles['fr-FR'];
        $langueNom        = $langueInfo['nom'];
        $hesitationsTypes = $langueInfo['hesitations'];

        $prompt  = "Tu es un expert en communication et recruteur. Analyse cette transcription d'une réponse orale d'un candidat pour le poste de \"$poste\".\n";
        $prompt .= "La transcription est en $langueNom. Rédige TOUTE ta réponse (points forts, axes d'amélioration, reformulation, conseil) en $langueNom, quelle que soit la langue de ces instructions.\n";
        $prompt .= "Transcription : \"$transcription\"\n\n";
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
            ]);

            $json_data = $this->llm->extractJson($raw);
            if (!$json_data) throw new \Exception("Erreur d'analyse de la réponse de l'IA.");

            $score = $json_data['score_global'] ?? 0;
            $this->oralModel->create($userId, $poste, $transcription, $json_data, $score);

            $this->json(['success' => true, 'data' => $json_data]);
        } catch (\Exception $e) {
            error_log('[OralController] ' . $e->getMessage());
            $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
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
