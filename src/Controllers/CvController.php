<?php
namespace App\Controllers;

use App\Models\CvDocument;
use App\Middleware\Auth;
use App\Services\LlmService;
use App\Services\AtsScorer;

class CvController {
    private CvDocument $cvModel;
    private LlmService $llm;
    private AtsScorer $ats;

    public function __construct() {
        $this->cvModel = new CvDocument($GLOBALS['pdo']);
        $this->llm = new LlmService();
        $this->ats = new AtsScorer();
    }

    private function input(): array {
        return json_decode(file_get_contents('php://input'), true) ?: [];
    }

    private function json(array $data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    public function generate(): void {
        $userId = Auth::require();
        $input = $this->input();
        $info = $input['info'] ?? [];

        if (empty($info['nom'])) {
            $this->json(['success' => false, 'error' => 'Les informations du CV sont manquantes.'], 400);
            return;
        }

        $info = $this->ats->normalizeCvInfoForScoring($info);
        $photo = $info['photo'] ?? null;
        $info_for_ai = $info;
        unset($info_for_ai['photo']);

        $completenessScore = $this->ats->calculateCompletenessScore($info_for_ai);

        $prompt = 'Génère un CV professionnel en français à partir de ces informations : ' . json_encode($info_for_ai, JSON_UNESCAPED_UNICODE) . '
Le score de complétude calculé est ' . $completenessScore . '/100 basé sur les données fournies.
IMPORTANT : si une information n\'est pas fournie, laisse le champ correspondant en chaîne vide "". N\'écris JAMAIS de texte de remplissage comme "Non renseigné", "N/A", "non spécifié" ou équivalent : soit tu as l\'information, soit le champ reste vide.
Format JSON strict : {
  "nom": "...",
  "titre": "Titre professionnel accrocheur",
  "contact": { "email":"...", "tel":"...", "ville":"...", "linkedin":"..." },
  "profil": "Résumé professionnel percutant en 3-4 phrases",
  "competences": { "techniques":["..."], "outils":["..."], "soft":["..."] },
  "experience": [{ "poste":"...", "entreprise":"...", "periode":"...", "realisation":["..."] }],
  "formation":  [{ "diplome":"...", "etablissement":"...", "annee":"..." }],
  "langues":    [{ "langue":"...", "niveau":"..." }],
  "mots_cles":  ["mots-clés ATS optimisés"],
  "score_ats":  ' . $completenessScore . ',
  "suggestions": ["Suggestion 1","..."]
}';

        try {
            $raw = $this->llm->call([
                ['role' => 'system', 'content' => 'Tu es un expert RH et rédacteur de CV. Réponds UNIQUEMENT en JSON valide, sans markdown, sans texte avant ou après.'],
                ['role' => 'user',   'content' => $prompt]
            ], ['max_tokens' => 2500]);

            $json_data = $this->llm->extractJson($raw);
            if (!$json_data) {
                throw new \Exception("Impossible d'analyser le JSON. Erreur: " . json_last_error_msg());
            }

            $json_data = $this->ats->cleanAiPlaceholderText($json_data);

            $age = $this->ats->calculateAgeFromBirthdate($info['date_naissance'] ?? null);
            if ($age !== null) {
                if (!isset($json_data['contact']) || !is_array($json_data['contact'])) {
                    $json_data['contact'] = [];
                }
                $json_data['contact']['age'] = $age;
            }

            if ($photo) {
                $json_data['photo'] = $photo;
            }

            $finalScoreDetails = $this->ats->calculateGeneratedCvScore($json_data);
            $completenessScore = $finalScoreDetails['completeness_score'];
            $json_data['score_ats'] = $completenessScore;
            $json_data['ats_details'] = $finalScoreDetails;

            if ($userId && isset($json_data)) {
                $title = $json_data['nom'] ?? 'Mon CV';
                $this->cvModel->create($userId, $title, $json_data, $completenessScore);
            }

            $this->json(['success' => true, 'data' => $json_data]);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function improve(): void {
        Auth::require();
        $input = $this->input();
        $cvContent = $input['cv'] ?? '';
        $jobOffer  = $input['jobOffer'] ?? '';

        if (empty($cvContent)) {
            $this->json(['success' => false, 'error' => 'Le contenu du CV est manquant.'], 400);
            return;
        }

        $atsScore = $this->ats->calculateRealATSScore($cvContent, $jobOffer);

        $prompt = 'Analyse ce CV par rapport à cette offre. Le score ATS calculé est ' . $atsScore['total'] . '/100.
CV: ' . $cvContent . '
OFFRE: ' . $jobOffer . '

Détails du calcul ATS:
- Matching mots-clés: ' . $atsScore['keyword_match'] . '%
- Compétences techniques: ' . $atsScore['skills_match'] . '%
- Expérience pertinente: ' . $atsScore['experience_match'] . '%
- Structure et format: ' . $atsScore['structure_score'] . '%

Réponds UNIQUEMENT en JSON: {
  "score_ats": ' . $atsScore['total'] . ',
  "points_forts": [],
  "points_faibles": [],
  "suggestions": [],
  "mots_cles_manquants": ' . json_encode($atsScore['missing_keywords']) . ',
  "mots_cles_trouves": ' . json_encode($atsScore['matched_keywords']) . ',
  "profil_ameliore": "version courte optimisée"
}';

        try {
            $raw = $this->llm->call([
                ['role' => 'system', 'content' => 'Tu es un expert RH spécialisé en optimisation de CV. Réponds UNIQUEMENT en JSON valide.'],
                ['role' => 'user',   'content' => $prompt]
            ]);

            $json_data = $this->llm->extractJson($raw);
            if (!$json_data) throw new \Exception("Format de réponse invalide de l'IA.");

            $json_data['score_ats'] = $atsScore['total'];
            $json_data['ats_details'] = $atsScore;

            $this->json(['success' => true, 'data' => $json_data]);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function history(): void {
        $userId = Auth::require();
        $cvs = $this->cvModel->findByUser($userId);
        $this->json(['success' => true, 'cv_history' => $cvs]);
    }

    public function get(): void {
        $userId = Auth::require();
        $id = $_REQUEST['id'] ?? null;
        if (!$id) {
            $this->json(['success' => false, 'error' => 'Paramètres manquants'], 400);
            return;
        }
        $cv = $this->cvModel->findById((int)$id, $userId);
        if (!$cv) {
            $this->json(['success' => false, 'error' => 'CV non trouvé'], 404);
            return;
        }
        $this->json(['success' => true, 'data' => $cv]);
    }

    public function delete(): void {
        $userId = Auth::require();
        $id = $_REQUEST['id'] ?? null;
        if (!$id) {
            $this->json(['success' => false, 'error' => 'Paramètres manquants'], 400);
            return;
        }
        $this->cvModel->delete((int)$id, $userId);
        $this->json(['success' => true]);
    }
}
