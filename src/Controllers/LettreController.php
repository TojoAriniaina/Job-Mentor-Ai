<?php
namespace App\Controllers;

use App\Models\CoverLetter;
use App\Middleware\Auth;
use App\Services\LlmService;
use App\Services\AtsScorer;

class LettreController {
    private CoverLetter $letterModel;
    private LlmService $llm;
    private AtsScorer $atsScorer;

    public function __construct() {
        $this->letterModel = new CoverLetter($GLOBALS['pdo']);
        $this->llm = new LlmService();
        $this->atsScorer = new AtsScorer();
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
        Auth::require();
        $input = $this->input();

        $cv                 = $input['cv']                 ?? '';
        $offre              = $input['offre']              ?? '';
        $ton                = $input['ton']                ?? 'formel';
        $nom                = $input['nom']                ?? '';
        $adresse            = $input['adresse']            ?? '';
        $telephone          = $input['telephone']          ?? '';
        $ville              = $input['ville']              ?? 'Antananarivo';
        $entreprise         = $input['entreprise']         ?? '';
        $entreprise_adresse = $input['entreprise_adresse'] ?? '';

        if (empty($cv) || empty($offre)) {
            $this->json(['success' => false, 'error' => 'CV ou offre manquants'], 400);
            return;
        }

        $tonDesc = ($ton === 'dynamique') ? 'dynamique et enthousiaste' : 'professionnelle et formelle';

        $mois = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
        $date = intval(date('j')) . ' ' . $mois[intval(date('n')) - 1] . ' ' . date('Y');
        $lieuDate = trim($ville) . ', le ' . $date;

        $expediteur = '';
        if ($nom)       $expediteur .= "$nom\n";
        if ($adresse)   $expediteur .= "$adresse\n";
        if ($telephone) $expediteur .= "Tél. : $telephone";

        $destinataire = '';
        if ($entreprise)         $destinataire .= "$entreprise\n";
        if ($entreprise_adresse) $destinataire .= $entreprise_adresse;

        $prompt  = "Rédige uniquement le CORPS d'une lettre de motivation (français), ton $tonDesc, 150-200 mots.\n\n";
        $prompt .= "Le corps doit commencer par \"Madame, Monsieur,\" et se terminer par une formule de politesse ";
        $prompt .= "(ex: \"Dans l'attente de votre retour, je vous prie d'agréer, Madame, Monsieur, mes salutations distinguées.\").\n";
        $prompt .= "Structure interne du corps (3 paragraphes séparés par \\n\\n) :\n";
        $prompt .= "1. Accroche et motivation pour rejoindre cette entreprise.\n";
        $prompt .= "2. Compétences et expériences en lien direct avec l'offre.\n";
        $prompt .= "3. Disponibilité pour un entretien + formule de politesse finale.\n\n";
        $prompt .= "Ne mets JAMAIS d'adresse, de date, de nom d'entreprise en en-tête, ni de nom/signature à la fin : ";
        $prompt .= "uniquement le texte qui va de \"Madame, Monsieur,\" à la formule de politesse.\n\n";
        $prompt .= "DONNÉES :\n";
        $prompt .= "Profil candidat : $cv\n";
        $prompt .= "Offre d'emploi : $offre\n\n";
        $prompt .= 'Réponds UNIQUEMENT avec ce JSON valide (sans markdown) :
{"objet":"intitulé du poste extrait de l\'offre (sans le mot Candidature)","corps":"texte du corps avec \\n\\n entre paragraphes","points_forts":["..."],"suggestions":["..."]}';

        try {
            $raw = $this->llm->call([
                ['role' => 'system', 'content' => 'Tu es un expert en rédaction de lettres de motivation. Réponds UNIQUEMENT en JSON valide, sans markdown.'],
                ['role' => 'user',   'content' => $prompt]
            ], ['max_tokens' => 2000]);

            $json_data = $this->llm->extractJson($raw);
            if (!$json_data) throw new \Exception("Erreur de formatage JSON de l'IA.");

            $destinataireLignes = array_filter([
                'Madame / Monsieur le Responsable',
                'Recrutement',
                $entreprise ?: null,
                $entreprise_adresse ?: null,
            ]);

            $json_data['nom']                 = $nom;
            $json_data['adresse']              = $adresse;
            $json_data['telephone']            = $telephone;
            $json_data['ville']                = $ville;
            $json_data['entreprise']            = $entreprise;
            $json_data['entreprise_adresse']    = $entreprise_adresse;
            $json_data['date']                  = $lieuDate;
            $json_data['destinataire_lignes']   = array_values($destinataireLignes);

            $objet = $json_data['objet'] ?? '';
            $corps = $json_data['corps'] ?? '';

            // Score de PERTINENCE calculé de façon déterministe (mots-clés de l'offre
            // réellement présents dans le corps généré) — jamais auto-déclaré par l'IA.
            $relevance = $this->atsScorer->calculateRealATSScore($corps, $offre);
            $json_data['score_pertinence'] = $relevance['total'];
            $json_data['score_details'] = $relevance;

            $lettreComplete  = ($expediteur ?: '') . "\n\n";
            $lettreComplete .= implode("\n", $destinataireLignes) . "\n\n";
            $lettreComplete .= $lieuDate . "\n\n";
            $lettreComplete .= "Objet : Candidature au poste de $objet\n\n";
            $lettreComplete .= $corps . "\n\n";
            $lettreComplete .= ($nom ?: '');

            $json_data['lettre'] = trim($lettreComplete);

            $this->json(['success' => true, 'data' => $json_data]);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function correct(): void {
        Auth::require();
        $input = $this->input();
        $text = $input['text'] ?? '';

        if (empty($text)) {
            $this->json(['success' => false, 'error' => 'Texte manquant'], 400);
            return;
        }

        $prompt  = "Corrige et améliore cette lettre de motivation. Rends-la concise, directe et percutante, en supprimant les longueurs inutiles :\n\"$text\"\n\n";
        $prompt .= "Réponds UNIQUEMENT avec ce JSON (aucun texte avant ou après) :\n";
        $prompt .= '{
  "texte_corrige": "...",
  "erreurs": ["erreur corrigée 1", "..."],
  "suggestions_style": ["..."]
}';

        try {
            $raw = $this->llm->call([
                ['role' => 'system', 'content' => 'Tu es un correcteur expert de lettres de motivation. JSON uniquement, sans markdown.'],
                ['role' => 'user',   'content' => $prompt]
            ]);

            $json_data = $this->llm->extractJson($raw);
            if (!$json_data) throw new \Exception("Format invalide retourné par l'IA.");

            // Score de QUALITÉ calculé de façon déterministe sur le texte corrigé
            // (longueur, structure, formules d'usage) — jamais auto-déclaré par l'IA.
            $quality = $this->atsScorer->calculateLetterQualityScore($json_data['texte_corrige'] ?? $text);
            $json_data['score'] = $quality['total'];
            $json_data['score_details'] = $quality;

            $this->json(['success' => true, 'data' => $json_data]);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function save(): void {
        $userId = Auth::require();
        $input = $this->input();

        try {
            $content = $input['content'] ?? '';
            $offer   = $input['offer']   ?? 'Lettre sans titre';
            $score   = $input['score']   ?? 0;

            if (empty($content)) throw new \Exception("Contenu vide.");

            $this->letterModel->create($userId, $content, $offer, $score);
            $this->json(['success' => true, 'message' => 'Lettre enregistrée !']);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function list(): void {
        $userId = Auth::require();
        $letters = $this->letterModel->findByUser($userId);
        $this->json(['success' => true, 'history' => $letters]);
    }

    public function get(): void {
        $userId = Auth::require();
        $id = $_REQUEST['id'] ?? null;
        if (!$id) {
            $this->json(['success' => false, 'error' => 'Paramètres manquants'], 400);
            return;
        }
        $letter = $this->letterModel->findById((int)$id, $userId);
        if (!$letter) {
            $this->json(['success' => false, 'error' => 'Lettre introuvable'], 404);
            return;
        }
        $this->json(['success' => true, 'data' => $letter]);
    }

    public function delete(): void {
        $userId = Auth::require();
        $id = $_REQUEST['id'] ?? null;
        if (!$id) {
            $this->json(['success' => false, 'error' => 'Paramètres manquants'], 400);
            return;
        }
        $this->letterModel->delete((int)$id, $userId);
        $this->json(['success' => true]);
    }
}
