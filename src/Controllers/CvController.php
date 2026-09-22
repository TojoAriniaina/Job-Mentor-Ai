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

        $titreSaisi = $info_for_ai['titre'] ?? '';

        $prompt = 'Génère un CV professionnel en français à partir de ces informations : ' . json_encode($info_for_ai, JSON_UNESCAPED_UNICODE) . '
Le score de complétude calculé est ' . $completenessScore . '/100 basé sur les données fournies.
IMPORTANT : si une information n\'est pas fournie, laisse le champ correspondant en chaîne vide "". N\'écris JAMAIS de texte de remplissage comme "Non renseigné", "N/A", "non spécifié" ou équivalent : soit tu as l\'information, soit le champ reste vide.
IMPORTANT : le champ "titre" DOIT reprendre EXACTEMENT le titre professionnel fourni par l\'utilisateur (' . json_encode($titreSaisi, JSON_UNESCAPED_UNICODE) . '). N\'invente pas un autre titre, n\'ajoute pas de mots comme "Full Stack", "Senior" ou "Junior" si ce n\'est pas dans le titre fourni.
IMPORTANT : les soft skills fournis par l\'utilisateur dans "competences.soft" doivent être repris TELS QUELS, sans reformulation ni ajout.
Format JSON strict : {
  "nom": "...",
  "titre": ' . json_encode($titreSaisi, JSON_UNESCAPED_UNICODE) . ',
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
            error_log('[CvController] ' . $e->getMessage());
            $this->json(['success' => false, 'error' => 'Erreur interne'], 500);
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

IMPORTANT : extrais aussi les informations structurées du CV pour créer un aperçu.
ATTENTION AUX LANGUES : repère TOUTES les langues mentionnées (ex: "Français (natif)", "Anglais (C1)") et inclus-les dans "cv_data.langues".

Réponds UNIQUEMENT en JSON: {
  "score_ats": ' . $atsScore['total'] . ',
  "points_forts": [],
  "points_faibles": [],
  "suggestions": [],
  "mots_cles_manquants": ' . json_encode($atsScore['missing_keywords']) . ',
  "mots_cles_trouves": ' . json_encode($atsScore['matched_keywords']) . ',
  "profil_ameliore": "version courte optimisée",
  "cv_data": {
    "nom": "...",
    "titre": "...",
    "contact": { "email": "...", "tel": "...", "ville": "...", "linkedin": "..." },
    "profil": "Résumé professionnel en 3-4 phrases",
    "competences": { "techniques": ["..."], "outils": ["..."], "soft": ["..."] },
    "experience": [{ "poste": "...", "entreprise": "...", "periode": "...", "realisation": ["..."] }],
    "formation": [{ "diplome": "...", "etablissement": "...", "annee": "..." }],
    "langues": [{ "langue": "...", "niveau": "..." }]
  }
}';

        try {
            $raw = $this->llm->call([
                ['role' => 'system', 'content' => 'Tu es un expert RH spécialisé en optimisation de CV. Réponds UNIQUEMENT en JSON valide.'],
                ['role' => 'user',   'content' => $prompt]
            ]);

            $json_data = $this->llm->extractJson($raw);
            if (!$json_data) throw new \Exception("Format de réponse invalide de l'IA.");

            // Fallback : si le LLM n'a pas retourné cv_data, construire un aperçu minimal
            if (empty($json_data['cv_data']) || !is_array($json_data['cv_data'])) {
                $json_data['cv_data'] = [
                    'nom' => '',
                    'titre' => '',
                    'contact' => ['email' => '', 'tel' => '', 'ville' => '', 'linkedin' => ''],
                    'profil' => $json_data['profil_ameliore'] ?? '',
                    'competences' => ['techniques' => [], 'outils' => [], 'soft' => []],
                    'experience' => [],
                    'formation' => [],
                    'langues' => [],
                ];
            }

            // Fallback langues : si le tableau langues est vide, extraire du texte brut
            if (empty($json_data['cv_data']['langues']) || !is_array($json_data['cv_data']['langues'])) {
                $json_data['cv_data']['langues'] = $this->extractLanguesFromText($cvContent);
            }

            $json_data['score_ats'] = $atsScore['total'];
            $json_data['ats_details'] = $atsScore;

            $this->json(['success' => true, 'data' => $json_data]);
        } catch (\Exception $e) {
            error_log('[CvController] ' . $e->getMessage());
            $this->json(['success' => false, 'error' => 'Erreur interne'], 500);
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

    public function importAnalyze(): void {
        $userId = Auth::require();
        $input = $this->input();
        $cvText = trim($input['cv_text'] ?? '');
        $jobOffer = trim($input['job_offer'] ?? '');
        $photo = $input['photo'] ?? null;

        if (empty($cvText)) {
            $this->json(['success' => false, 'error' => 'Le texte du CV est manquant.'], 400);
            return;
        }

        $prompt  = "Tu es un expert RH, rédacteur de CV et correcteur linguistique. Tu ne fais PAS que parser : tu AMÉLIORES RÉELLEMENT ce CV.\n\n";
        $prompt .= "## RÈGLE ABSOLUE : JAMAIS INVENTER\n";
        $prompt .= "N'ajoute AUCUNE information absente du CV source : pas de compétence, diplôme, entreprise, poste, mission, chiffre, résultat, logiciel ou langage inventés.\n";
        $prompt .= "Si une offre d'emploi est fournie, cela ne signifie PAS que le candidat maîtrise les technologies qu'elle mentionne : tu ne les utilises que si elles figurent déjà dans le CV.\n";
        $prompt .= "Une reformulation est autorisée. L'ajout d'un fait nouveau est INTERDIT. Si une information importante manque, laisse le champ vide.\n\n";
        $prompt .= "## AMÉLIORATIONS À EFFECTUER\n";
        $prompt .= "1. PROFIL : réécris le résumé professionnel en 3-4 phrases percutantes, uniquement à partir des expériences et compétences réellement présentes. Pas de clichés (« dynamique », « motivé », « environnement stimulant »).\n";
        $prompt .= "2. EXPÉRIENCES : pour chaque « realisation », reformule avec un verbe d'action concret et mets en valeur ce qui est réellement décrit. Fusionne les puces qui répètent la même idée. Ne chiffre jamais un résultat qui n'est pas chiffré dans la source.\n";
        $prompt .= "3. COMPÉTENCES : réorganise en techniques / outils / soft UNIQUEMENT à partir de ce qui est mentionné dans le CV. Classe, ne complète pas.\n";
        $prompt .= "4. PERSONNALITÉ : le niveau de langue doit rester celui du candidat. Ne transforme pas un CV simple en CV pompeux.\n";
        $prompt .= "5. NETTOYAGE : corrige orthographe, grammaire, syntaxe ; supprime duplications, répétitions et placeholders ([À compléter], xxx...) ; harmonise les formats de dates.\n";
        if (!empty($jobOffer)) {
            $prompt .= "6. PERSONNALISATION : rend plus visibles les correspondances RÉELLES entre le CV et cette offre (ordre des puces, vocabulaire repris de l'offre quand il décrit déjà le candidat). Ne change que ce qui est justifié par l'offre.\n";
        }
        $prompt .= "\n## CONTRÔLE FINAL\nAvant de répondre, vérifie que chaque information de ta sortie existe dans la source, qu'aucune duplication ne subsiste, et que le CV est réellement meilleur (plus clair, plus structuré, plus précis) — pas simplement paraphrasé.\n\n";
        $prompt .= "ATTENTION AUX LANGUES : repère TOUTES les langues mentionnées (ex: \"Français (natif)\", \"Anglais (C1)\") avec leur niveau exact. Ne fusionne pas, n'omets aucune langue.\n\n";
        $prompt .= ($jobOffer ? "OFFRE D'EMPLOI CIBLÉE :\n$jobOffer\n\n" : "");
        $prompt .= "CV À AMÉLIORER :\n$cvText\n\n";
        $prompt .= 'Format JSON strict (mêmes champs qu\'un CV structuré, valeurs améliorées) : {
  "nom": "...",
  "titre": "...",
  "contact": { "email": "...", "tel": "...", "ville": "...", "linkedin": "..." },
  "profil": "Résumé professionnel réécrit en 3-4 phrases",
  "competences": { "techniques": ["..."], "outils": ["..."], "soft": ["..."] },
  "experience": [{ "poste": "...", "entreprise": "...", "periode": "...", "realisation": ["..."] }],
  "formation": [{ "diplome": "...", "etablissement": "...", "annee": "..." }],
  "langues": [{ "langue": "...", "niveau": "..." }]
}';

        try {
            $raw = $this->llm->call([
                ['role' => 'system', 'content' => 'Tu es un expert RH, rédacteur de CV et correcteur linguistique. Tu AMÉLIORES réellement les CV sans jamais inventer d\'information. Réponds UNIQUEMENT en JSON valide, sans markdown, sans texte avant ou après.'],
                ['role' => 'user',   'content' => $prompt]
            ], ['temperature' => 0.3, 'max_tokens' => 3000]);

            $json_data = $this->llm->extractJson($raw);
            if (!$json_data) throw new \Exception("Impossible de parser le CV. Format de réponse invalide.");

            $json_data = $this->ats->cleanAiPlaceholderText($json_data);

            // Fallback : si le LLM a retourné un tableau de langues vide ou absent,
            // tenter d'extraire les langues du texte brut via des motifs courants.
            if (empty($json_data['langues']) || !is_array($json_data['langues'])) {
                $extractedLangues = $this->extractLanguesFromText($cvText);
                $json_data['langues'] = $extractedLangues;
            }

            // Score de complétude
            $normalized = $this->ats->normalizeCvInfoForScoring($json_data);
            $completeness = $this->ats->calculateCompletenessScore($normalized);
            $finalScore = $this->ats->calculateGeneratedCvScore($json_data);
            $json_data['score_ats'] = $finalScore['completeness_score'];
            $json_data['ats_details'] = $finalScore;

            // Score vs offre (si fournie)
            if (!empty($jobOffer)) {
                $cvContent = json_encode($json_data, JSON_UNESCAPED_UNICODE);
                $atsAgainstOffer = $this->ats->calculateRealATSScore($cvContent, $jobOffer);
                $json_data['score_pertinence'] = $atsAgainstOffer['total'];
                $json_data['pertinence_details'] = $atsAgainstOffer;
            }

            // Attacher la photo si fournie
            if ($photo) {
                $json_data['photo'] = $photo;
            }

            // Sauvegarder
            if ($userId) {
                $title = $json_data['nom'] ?? 'CV importé';
                $this->cvModel->create($userId, $title, $json_data, $json_data['score_ats']);
            }

            $this->json(['success' => true, 'data' => $json_data]);
        } catch (\Exception $e) {
            error_log('[CvController::importAnalyze] ' . $e->getMessage());
            $this->json(['success' => false, 'error' => 'Erreur interne'], 500);
        }
    }

    /**
     * Extraction best-effort des langues depuis le texte brut d'un CV.
     * Gère les formats courants :
     *   "Français (natif), Anglais (C1), Espagnol (B2)"
     *   "Langues : Français - Natif, Anglais - Courant"
     *   "Anglais: fluent, French: native"
     */
    private function extractLanguesFromText(string $text): array {
        $langues = [];
        $langPatterns = [
            // "Français (natif)" ou "Anglais (C1)" — avec parenthèses
            '/(\w[\w\sÀ-ÿ]{1,30}?)\s*\(([^)]{1,30})\)/iu',
            // "Français : Natif" ou "Anglais - Courant" — avec séparateur
            '/(\w[\w\sÀ-ÿ]{1,30}?)[\s]*[-:][\s]*([A-ZÀ-ÿ\w\s]{1,30})/iu',
        ];

        // Détection de la section langues dans le texte
        $sectionText = $text;
        if (preg_match('/langues?\s*[:\n]/iu', $text, $m, PREG_OFFSET_CAPTURE)) {
            // Prendre le texte à partir de "Langue(s):" jusqu'à la prochaine section ou 500 caractères
            $start = $m[0][1];
            $remaining = mb_substr($text, $start, 500, 'UTF-8');
            // Couper à la prochaine section
            $remaining = preg_replace('/\n\s*[A-ZÀ-ÿ][\w\s]{2,30}\s*:\s*\n.*/s', '', $remaining);
            $sectionText = $remaining;
        }

        foreach ($langPatterns as $pattern) {
            if (preg_match_all($pattern, $sectionText, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $langue = trim($match[1]);
                    $niveau = trim($match[2]);
                    // Filtrer les faux positifs (phrases trop longues, mots vides)
                    if (mb_strlen($langue, 'UTF-8') >= 2 && mb_strlen($langue, 'UTF-8') <= 30
                        && mb_strlen($niveau, 'UTF-8') >= 1 && mb_strlen($niveau, 'UTF-8') <= 30
                        && !in_array(strtolower($langue), ['the', 'and', 'for', 'des', 'les', 'une'])) {
                        $langues[] = ['langue' => $langue, 'niveau' => $niveau];
                    }
                }
            }
        }

        return $langues;
    }
}
