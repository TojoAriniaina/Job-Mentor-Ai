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
        $civilite           = $input['civilite']           ?? 'Madame, Monsieur';
        $nom                = $input['nom']                ?? '';
        $poste              = $input['poste']              ?? '';
        $email              = $input['email']              ?? '';
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
        if ($poste)     $expediteur .= "$poste\n";
        if ($telephone) $expediteur .= "Tél. : $telephone\n";
        if ($email)     $expediteur .= "$email\n";
        if ($adresse)   $expediteur .= $adresse;

        $destinataire = '';
        if ($entreprise)         $destinataire .= "$entreprise\n";
        if ($entreprise_adresse) $destinataire .= $entreprise_adresse;

        $prompt  = "Rédige uniquement le CORPS d'une lettre de motivation (français), ton $tonDesc, 150-200 mots.\n\n";
        $prompt .= "Le corps doit commencer par \"$civilite,\" et se terminer par une formule de politesse ";
        $prompt .= "(ex: \"Dans l'attente de votre retour, je vous prie d'agréer, $civilite, mes salutations distinguées.\").\n";
        $prompt .= "Structure interne du corps (3 paragraphes séparés par \\n\\n) :\n";
        $prompt .= "1. Accroche et motivation pour rejoindre cette entreprise.\n";
        $prompt .= "2. Compétences et expériences en lien direct avec l'offre.\n";
        $prompt .= "3. Disponibilité pour un entretien + formule de politesse finale.\n\n";
        $prompt .= "Ne mets JAMAIS d'adresse, de date, de nom d'entreprise en en-tête, ni de nom/signature à la fin : ";
        $prompt .= "uniquement le texte qui va de \"$civilite,\" à la formule de politesse.\n\n";
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
                "$civilite le Responsable",
                'Recrutement',
                $entreprise ?: null,
                $entreprise_adresse ?: null,
            ]);

            $json_data['nom']                 = $nom;
            $json_data['poste']               = $poste;
            $json_data['email']               = $email;
            $json_data['adresse']              = $adresse;
            $json_data['telephone']            = $telephone;
            $json_data['ville']                = $ville;
            $json_data['civilite']             = $civilite;
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
            error_log('[LettreController] ' . $e->getMessage());
            $this->json(['success' => false, 'error' => 'Erreur interne'], 500);
        }
    }

    public function analyze(): void {
        Auth::require();
        $input = $this->input();
        $text = $input['text'] ?? '';

        if (empty($text)) {
            $this->json(['success' => false, 'error' => 'Texte manquant'], 400);
            return;
        }

        $quality = $this->atsScorer->calculateLetterQualityScore($text);

        $points_forts = [];
        $points_faibles = [];
        $recommandations = [];
        $anomalies = $quality['anomalies'] ?? [];

        $textLower = mb_strtolower($text, 'UTF-8');
        $wordCount = $quality['word_count'] ?? str_word_count(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text));

        // ── A. ANALYSE DE LA STRUCTURE ──

        // Date
        $hasDate = (bool) preg_match('/\d{1,2}\s+(?:janvier|février|mars|avril|mai|juin|juillet|août|septembre|octobre|novembre|décembre)\s+\d{4}/iu', $text);
        if ($hasDate) {
            $points_forts[] = "Date présente dans la lettre";
        } else {
            $recommandations[] = "La lettre ne contient pas de date. Ajoutez la date du jour.";
        }

        // Destinataire
        $hasDestinataire = (bool) preg_match("/à l.attention|madame|monsieur/iu", $textLower);
        if ($hasDestinataire) {
            $points_forts[] = "Destinataire identifié dans la lettre";
        } else {
            $points_faibles[] = "Destinataire non identifié";
            $recommandations[] = "Ajoutez une ligne « À l'attention du Responsable du recrutement » ou équivalent.";
        }

        // Objet
        $hasObjet = (bool) preg_match('/^objet\s*:/miu', $text);
        if ($hasObjet) {
            $points_forts[] = "Objet de la lettre présent";
        } else {
            $recommandations[] = "L'ajout d'un objet clair (ex : « Objet : Candidature au poste de... ») améliore la lisibilité.";
        }

        // Formule d'appel
        $hasAppel = (bool) preg_match('/madame|monsieur/iu', $textLower);
        if ($hasAppel) {
            $points_forts[] = "Formule d'appel présente";
        } else {
            $points_faibles[] = "Formule d'appel manquante";
            $recommandations[] = "Ajoutez une formule d'appel (ex : « Madame, » ou « Monsieur, »).";
        }

        // Formule de politesse
        $hasClosing = (bool) preg_match('/salutations|considération|agréer|cordialement/iu', $textLower);
        if ($hasClosing) {
            $points_forts[] = "Formule de politesse finale présente";
        } else {
            $points_faibles[] = "Formule de politesse finale manquante";
            $recommandations[] = "Terminez par une formule de politesse (ex : « Je vous prie d'agréer... »).";
        }

        // Signature / nom
        $hasSignature = (bool) preg_match('/cordialement|distinguées|respectueuses|salutations/iu', $textLower);
        if ($hasSignature) {
            $points_forts[] = "Clôture avec formule de politesse";
        }

        // Structure en paragraphes
        $paragraphs = array_values(array_filter(preg_split('/\n\s*\n/', $text), fn($p) => trim($p) !== ''));
        $paraCount = count($paragraphs);
        if ($paraCount >= 3 && $paraCount <= 4) {
            $points_forts[] = "Structure bien organisée ($paraCount paragraphes)";
        } elseif ($paraCount >= 2 && $paraCount <= 5) {
            $recommandations[] = "La structure est acceptable ($paraCount paragraphes). Visez 3 à 4 paragraphes distincts.";
        } else {
            $points_faibles[] = "Structure insuffisante ($paraCount paragraphe(s) — manque de découpage)";
            $recommandations[] = "Organisez votre lettre en 3 à 4 paragraphes : accroche, profil/compétences, motivation, conclusion.";
        }

        // ── B. ANALYSE QUALITÉ RÉDACTIONNELLE ──

        // Longueur
        if ($quality['length_score'] >= 25) {
            $points_forts[] = "Longueur adaptée ($wordCount mots — idéal pour une lettre de motivation)";
        } elseif ($quality['length_score'] >= 15) {
            $recommandations[] = "Longueur acceptable ($wordCount mots). Visez 120-250 mots pour rester concis et percutant.";
        } else {
            $points_faibles[] = "Longueur inadaptée ($wordCount mots)";
            $recommandations[] = $wordCount < 80
                ? "La lettre est trop courte. Visez entre 120 et 250 mots pour être complet et percutant."
                : "La lettre est trop longue. Réduisez-la à environ 120-250 mots pour maintenir l'attention du recruteur.";
        }

        // Variété du vocabulaire
        if ($quality['variety_score'] >= 20) {
            $points_forts[] = "Vocabulaire varié, peu de répétitions";
        } else {
            $points_faibles[] = "Vocabulaire répétitif — certains mots ou expressions reviennent trop souvent";
            $recommandations[] = "Variez votre vocabulaire et reformulez les passages qui répètent les mêmes idées.";
        }

        // Placeholder
        if ($quality['no_placeholder_score'] > 0) {
            $points_forts[] = "Aucun texte d'espace réservé non remplacé";
        } else {
            $points_faibles[] = "Présence de texte d'espace réservé (ex : [À compléter])";
            $recommandations[] = "Remplacez tous les espaces réservés par les informations réelles.";
        }

        // Phrases trop longues (>30 mots)
        $sentences = preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $longSentences = array_filter($sentences, fn($s) => str_word_count(trim($s)) > 30);
        if (count($longSentences) > 2) {
            $points_faibles[] = count($longSentences) . " phrases dépassent 30 mots — la lisibilité en souffre";
            $recommandations[] = "Découpez les phrases longues en phrases plus courtes pour améliorer la lisibilité.";
        } elseif (count($longSentences) > 0 && count($longSentences) <= 2) {
            $recommandations[] = count($longSentences) . " phrase(s) longue(s) détectée(s). Découpez si nécessaire.";
        }

        // Répétitions de mots (hors mots courants)
        $stopWords = ['de','la','le','les','des','un','une','du','et','en','au','aux','que','qui','je','ne','pas','pour','par','dans','avec','est','sont','sur','ce','se','son','sa','ses','mon','ma','mes','votre','notre','cette','tout','très','plus'];
        $significantWords = array_filter(explode(' ', $textLower), function($w) use ($stopWords) {
            $w = preg_replace('/[^\p{L}]/u', '', $w);
            return mb_strlen($w, 'UTF-8') > 3 && !in_array($w, $stopWords);
        });
        $wordCounts = array_count_values($significantWords);
        $repeatedWords = array_filter($wordCounts, fn($c) => $c >= 4);
        if (!empty($repeatedWords)) {
            arsort($repeatedWords);
            $top3 = array_slice($repeatedWords, 0, 3, true);
            $wordsList = implode(', ', array_map(fn($w, $c) => "« $w » (×$c)", array_keys($top3), $top3));
            $points_faibles[] = "Mots répétitifs détectés : $wordsList";
            $recommandations[] = "Remplacez ou reformulez les passages qui répètent les mêmes termes.";
        } elseif (count($significantWords) > 10) {
            $points_forts[] = "Peu de répétitions de mots significatifs";
        }

        // ── C. DÉTECTION D'ANOMALIES TEXTUELLES ──

        // Duplications de phrases ou segments
        $cleanText = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $cleanText = preg_replace('/\s+/', ' ', trim($cleanText));
        $allWordsArr = explode(' ', $cleanText);

        // Duplications de segments de 5+ mots consécutifs
        for ($ngram = 5; $ngram >= 4; $ngram--) {
            for ($i = 0; $i <= count($allWordsArr) - ($ngram * 2); $i++) {
                $chunk = implode(' ', array_slice($allWordsArr, $i, $ngram));
                $rest = implode(' ', array_slice($allWordsArr, $i + $ngram));
                if (mb_strlen($chunk, 'UTF-8') > 15 && mb_stripos($rest, $chunk, 0, 'UTF-8') !== false) {
                    $anomalies[] = "Segment dupliqué : « " . mb_substr($chunk, 0, 80, 'UTF-8') . " »";
                    break;
                }
            }
        }

        // Duplication de formule d'ouverture
        $openingPattern = "/(?:à l.attention\s+du|madame\s*(?:,\s*monsieur)?|monsieur\s+le|madame\s+la)/iu";
        preg_match_all($openingPattern, $textLower, $openings);
        if (count($openings[0]) > 2) {
            $anomalies[] = "Formule d'ouverture répétée (" . count($openings[0]) . " occurrences)";
        }

        // Duplication de formule de clôture
        $closingPattern = "/(?:je\s+(?:vous\s+)?prie\s+d.agréer|salutations?\s+(?:distinguées|cordiales|respectueuses))/iu";
        preg_match_all($closingPattern, $textLower, $closings);
        if (count($closings[0]) > 1) {
            $anomalies[] = "Formule de clôture répétée (" . count($closings[0]) . " occurrences)";
        }

        // Caractères parasites
        if (preg_match('/[^\p{L}\p{N}\p{P}\p{Z}\n\r\t]/u', $text)) {
            $anomalies[] = "Présence de caractères parasites ou invisibles dans le texte";
        }
        if (preg_match('/\s{4,}/', $text)) {
            $anomalies[] = "Espaces multiples excessifs détectés";
        }

        // Informations contradictoires (exemple : "je suis junior" + "mes 10 ans d'expérience")
        $isJunior = (bool) preg_match('/junior|débutant|alternant|stagiaire/iu', $text);
        $isSenior = (bool) preg_match('/senior|expert|chef de projet|responsable|directeur|manager|10\s+ans|15\s+ans|20\s+ans/iu', $text);
        if ($isJunior && $isSenior) {
            $anomalies[] = "Informations potentiellement contradictoires : profil junior associé dans la même lettre à des mentions d'expérience senior";
        }

        // ── D. FORMULATIONS GÉNÉRIQUES ──
        $hasBody = false;
        foreach ($paragraphs as $p) {
            if (mb_strlen(trim($p), 'UTF-8') > 80) { $hasBody = true; break; }
        }
        if ($hasBody) {
            $genericPatterns = [
                '/je suis (?:très|extrêmement|particulièrement)?\s*(?:motivé|passionné|dynamique|rigoureux|sérieux)/iu',
                '/mettre\s+(?:mes\s+)?compétences?\s+au\s+service/iu',
                '/contribuer\s+(?:à|aux)\s+(?:vos|leur)s?\s+objectifs/iu',
                '/relever\s+(?:de\s+)?nouveaux\s+défis/iu',
                '/environnement\s+stimulant/iu',
                '/projets?\s+ambitieux/iu',
                '/équipe\s+dynamique/iu',
                '/participer\s+activement\s+à\s+(?:votre|leur)\s+développement/iu',
                '/intégrer\s+(?:une\s+)?(?:entreprise|société)\s+prestigieuse/iu',
                '/je\s+suis\s+extrêmement\s+motivé/iu',
                '/mettre\s+(?:mes\s+)?acquis\s+au\s+service/iu',
            ];
            $foundGeneric = [];
            foreach ($genericPatterns as $pat) {
                if (preg_match($pat, $text, $m)) {
                    $foundGeneric[] = $m[0];
                }
            }
            if (count($foundGeneric) >= 2) {
                $points_faibles[] = "Plusieurs formulations génériques détectées (" . count($foundGeneric) . ")";
                $recommandations[] = "Remplacez les expressions génériques par des arguments concrets liés au poste.";
            } elseif (count($foundGeneric) === 1) {
                $recommandations[] = "Formulation générique détectée : « " . $foundGeneric[0] . " ». Préférez un argument concret.";
            }
        }

        // ── E. PRÉSENTATION ──

        // Duplications évidentes de lignes
        $lines = array_map('trim', preg_split('/\r?\n/', $text));
        $lines = array_filter($lines, fn($l) => mb_strlen($l, 'UTF-8') > 5);
        $lineCounts = array_count_values($lines);
        foreach ($lineCounts as $line => $count) {
            if ($count >= 2) {
                $anomalies[] = "Ligne dupliquée : « " . mb_substr($line, 0, 80, 'UTF-8') . " » (×$count)";
            }
        }

        // ── Les anomalies sont affichées dans leur propre section,
        // elles ne sont pas répétées dans les points à améliorer.
        $anomalies = array_values(array_unique($anomalies));

        $this->json([
            'success' => true,
            'data' => [
                'score'            => $quality['total'],
                'details'          => $quality,
                'points_forts'     => $points_forts,
                'points_faibles'   => $points_faibles,
                'recommandations'  => $recommandations,
                'anomalies'        => $anomalies,
            ]
        ]);
    }

    public function correct(): void {
        Auth::require();
        $input = $this->input();
        $text = $input['text'] ?? '';

        if (empty($text)) {
            $this->json(['success' => false, 'error' => 'Texte manquant'], 400);
            return;
        }

        $prompt  = "Tu es à la fois un recruteur expérimenté, un expert en rédaction professionnelle et un correcteur linguistique spécialisé dans les lettres de motivation.\n";
        $prompt .= "Ton rôle : ANALYSER en profondeur, DIAGNOSTIQUER les vrais problèmes, puis RÉÉCRIRE réellement la lettre pour la rendre professionnellement meilleure.\n\n";

        $prompt .= "## RÈGLE ABSOLUE : JAMAIS INVENTER D'INFORMATIONS\n\n";
        $prompt .= "La version corrigée ne doit JAMAIS ajouter d'information qui n'existe pas dans la lettre source :\n";
        $prompt .= "- expérience professionnelle inexistante\n";
        $prompt .= "- stage ou emploi non mentionné\n";
        $prompt .= "- diplôme ou certification inexistant\n";
        $prompt .= "- compétence, logiciel, langage ou technologie non mentionné(e)\n";
        $prompt .= "- projet inexistant\n";
        $prompt .= "- résultat chiffré inventé\n";
        $prompt .= "- mission ou responsabilité non décrite\n";
        $prompt .= "- niveau de langue non mentionné\n";
        $prompt .= "- motivation ou raison non fournie par le candidat\n";
        $prompt .= "- entreprise ou poste non mentionné(s)\n\n";
        $prompt .= "Si l'offre d'emploi mentionne React, Laravel, Docker, etc., CELA NE SIGNIFIE PAS que le candidat les maîtrise. Tu ne dois JAMAIS les ajouter simplement parce qu'ils apparaissent dans l'offre.\n";
        $prompt .= "Tu peux uniquement améliorer la MANIÈRE dont les informations existantes sont présentées.\n";
        $prompt .= "Si une information importante manque dans la source, ne l'invente pas — mets-la dans 'recommandations'.\n\n";

        $prompt .= "### Exemples concrets :\n";
        $prompt .= "SOURCE : « Je suis capable de résoudre des problèmes complexes. »\n";
        $prompt .= "AUTORISÉ : « Ma capacité à résoudre des problèmes complexes me permet d'aborder les difficultés techniques avec efficacité. »\n";
        $prompt .= "INTERDIT : « J'ai développé une méthode rigoureuse d'analyse et d'optimisation des performances. »\n";
        $prompt .= "(La dernière phrase ajoute une information qui n'est pas dans la source.)\n\n";

        $prompt .= "## RÈGLE 2 : DÉTECTER ET CORRIGER LES DUPLICATIONS\n\n";
        $prompt .= "Tu dois impérativement rechercher et corriger TOUTE anomalie de duplication :\n";
        $prompt .= "- Phrases ou segments de texte dupliqués\n";
        $prompt .= "- Groupes de mots répétés (ex : « À l'attention du À l'attention du Responsable »)\n";
        $prompt .= "- Formule d'ouverture dupliquée (ex : « Madame, Monsieur, Madame, Monsieur, »)\n";
        $prompt .= "- Formule de clôture dupliquée\n";
        $prompt .= "- Paragraphes entiers dupliqués\n";
        $prompt .= "- Destinataire répété\n";
        $prompt .= "- Objet dupliqué (ex : « Objet : Objet : Candidature... »)\n";
        $prompt .= "- Date ou en-tête dupliqués\n";
        $prompt .= "- Mots ou groupes de mots copiés accidentellement deux fois\n\n";
        $prompt .= "Lorsque tu trouves une duplication, SUPPRIME-la intégralement dans la version corrigée.\n";
        $prompt .= "L'anomalie doit être signalée dans 'analyse' ET corrigée dans 'texte_corrige'.\n\n";

        $prompt .= "## RÈGLE 3 : VÉRITABLE RÉÉCRITURE (pas juste des synonymes)\n\n";
        $prompt .= "Tu ne dois PAS te limiter à corriger l'orthographe, remplacer quelques synonymes ou ajouter des adjectifs professionnels.\n\n";
        $prompt .= "Pour chaque paragraphe, se demander :\n";
        $prompt .= "1. Quel est son objectif ?\n";
        $prompt .= "2. Est-il utile ? Apporte-t-il une information réelle ?\n";
        $prompt .= "3. Est-il suffisamment clair et précis ?\n";
        $prompt .= "4. Est-il convaincant ?\n";
        $prompt .= "5. Y a-t-il des répétitions (mots ou idées) ?\n";
        $prompt .= "6. Peut-il être reformulé plus naturellement ?\n";
        $prompt .= "7. Peut-il être raccourci ou restructuré ?\n\n";
        $prompt .= "Si une phrase est faible → la reconstruire.\n";
        $prompt .= "Si deux phrases répètent la même idée → les fusionner.\n";
        $prompt .= "Si un paragraphe est mal organisé → le restructurer.\n";
        $prompt .= "Si l'introduction est faible → l'améliorer.\n";
        $prompt .= "Si la conclusion est faible → la renforcer.\n\n";

        $prompt .= "### Exemple de vraie réécriture :\n";
        $prompt .= "ORIGINAL : « Je suis une personne motivée qui aime travailler dans l'informatique. »\n";
        $prompt .= "RÉÉCRIT : « Mon parcours en informatique m'a permis de développer des compétences concrètes et m'a donné l'envie de mettre ces acquis au service de projets au sein de votre équipe. »\n";
        $prompt .= "FAUX : « Je suis particulièrement motivé par l'informatique. » (simple remplacement de synonyme)\n\n";

        $prompt .= "## RÈGLE 4 : CONSERVER LA PERSONNALITÉ DU CANDIDAT\n\n";
        $prompt .= "La lettre doit rester humaine, naturelle et crédible.\n";
        $prompt .= "Ne pas transformer automatiquement une lettre simple en lettre excessivement sophistiquée.\n";
        $prompt .= "Éviter les phrases clichés : « Je suis extrêmement motivé », « votre prestigieuse entreprise », « opportunité exceptionnelle », « Fort de mes nombreuses expériences », « mettre mes compétences au service de votre entreprise », « contribuer à vos objectifs », « relever de nouveaux défis », « environnement stimulant », « projets ambitieux », « équipe dynamique ».\n";
        $prompt .= "Simple et précis > sophistiqué et artificiel.\n\n";

        $prompt .= "## RÈGLE 5 : STRUCTURE RECOMMANDÉE\n\n";
        $prompt .= "Si pertinent, organiser la lettre selon une structure logique :\n";
        $prompt .= "1. En-tête / destinataire / date\n";
        $prompt .= "2. Objet\n";
        $prompt .= "3. Formule d'appel\n";
        $prompt .= "4. Introduction (poste, candidature, raison)\n";
        $prompt .= "5. Profil et compétences pertinentes\n";
        $prompt .= "6. Correspondance avec le poste\n";
        $prompt .= "7. Motivation pour le poste / entreprise\n";
        $prompt .= "8. Conclusion et disponibilité\n";
        $prompt .= "9. Formule de politesse et signature\n";
        $prompt .= "Ne pas appliquer cette structure mécaniquement si la lettre source est déjà bien structurée.\n\n";

        $prompt .= "## RÈGLE 6 : STYLE\n\n";
        $prompt .= "Professionnel, naturel, fluide, précis, concis, convaincant.\n";
        $prompt .= "Pas de phrases excessivement longues (>30 mots → découper).\n";
        $prompt .= "Pas de superlatifs inutiles, pas de répétitions.\n";
        $prompt .= "Ne pas rallonger artificiellement. Une lettre concise et pertinente est préférable à une lettre longue remplie de phrases génériques.\n";
        $prompt .= "Chaque phrase doit apporter : une information, une compétence, une motivation, un argument, ou un lien concret avec le poste.\n";
        $prompt .= "Ne jamais supprimer une information importante uniquement pour atteindre une longueur donnée.\n\n";

        $prompt .= "## RÈGLE 7 : NETTOYAGE PRÉALABLE AVANT RÉÉCRITURE\n\n";
        $prompt .= "Avant de réécrire, effectuer ce nettoyage :\n";
        $prompt .= "1. SUPPRIMER les duplications (phrases, segments, formules répétées)\n";
        $prompt .= "2. SUPPRIMER les placeholders non remplacés ([À compléter], xxx, etc.)\n";
        $prompt .= "3. CORRIGER les caractères parasites ou espaces anormaux\n";
        $prompt .= "4. CORRIGER les fautes d'orthographe, grammaire, conjugaison, ponctuation, syntaxe\n";
        $prompt .= "5. FUSIONNER les phrases qui répètent la même idée\n";
        $prompt .= "6. RESTRUCTURER les paragraphes déséquilibrés ou mal organisés\n\n";

        $prompt .= "## RÈGLE 8 : CONTRÔLE ANTI-INVENTION OBLIGATOIRE\n\n";
        $prompt .= "Avant de retourner 'texte_corrige', comparer mentalement chaque nouvelle information avec la source.\n";
        $prompt .= "Si tu as ajouté UNE SEULE information qui n'est pas dans l'original → la supprimer.\n";
        $prompt .= "Ne pas confondre amélioration rédactionnelle et ajout d'informations.\n";
        $prompt .= "Une reformulation est autorisée. L'ajout d'un fait non présent dans la source est INTERDIT.\n\n";

        $prompt .= "## RÈGLE 9 : PROCESSUS OBLIGATOIRE\n\n";
        $prompt .= "ÉTAPE 1 — DIAGNOSTIC : Identifier TOUS les problèmes (duplications, répétitions, erreurs, faiblesses structurelles, formulations vagues, anomalies)\n";
        $prompt .= "ÉTAPE 2 — STRUCTURE : Déterminer si l'organisation doit être améliorée\n";
        $prompt .= "ÉTAPE 3 — NETTOYAGE : Supprimer duplications, répétitions, formulations inutiles, erreurs, placeholders, anomalies\n";
        $prompt .= "ÉTAPE 4 — RÉÉCRIRE : Reconstruire les phrases et paragraphes faibles\n";
        $prompt .= "ÉTAPE 5 — PERSONNALISER : Utiliser uniquement les informations réellement disponibles\n";
        $prompt .= "ÉTAPE 6 — CONTRÔLE ANTI-INVENTION : Comparer chaque nouvelle information avec la source\n";
        $prompt .= "ÉTAPE 7 — CONTRÔLE FINAL : Vérifier orthographe, grammaire, syntaxe, cohérence, répétitions, structure, professionnalisme, naturel, absence d'invention, absence de duplication\n\n";

        $prompt .= "## TEXTE ORIGINAL À ANALYSER ET AMÉLIORER\n\n";
        $prompt .= "\"$text\"\n\n";

        $prompt .= "## FORMAT DE SORTIE (JSON STRICT)\n\n";
        $prompt .= "Tu dois répondre UNIQUEMENT avec un JSON valide (aucun texte, aucune explication, aucun markdown avant ou après) :\n";
        $prompt .= '{"analyse":["problème 1 identifié","problème 2","...max 8"],"texte_corrige":"lettre entièrement réécrite et améliorée avec toutes les anomalies corrigées","ameliorations":[{"avant":"extrait original exact","probleme":"pourquoi c était un problème","apres":"nouvelle formulation améliorée","justification":"pourquoi c est mieux"}],"recommandations":["information manquante 1 ou conseil 1","..."]}' . "\n\n";

        $prompt .= "RÈGLES POUR LE JSON :\n";
        $prompt .= "- 'analyse' : problèmes réels et importants, max 8. Inclure duplications, anomalies, erreurs, faiblesses.\n";
        $prompt .= "- 'ameliorations' : améliorations significatives, max 8, avec avant/probleme/apres/justification.\n";
        $prompt .= "- 'ameliorations' peut contenir 0 à 8 éléments. Si la lettre est déjà très bonne, ne pas inventer d'améliorations.\n";
        $prompt .= "- 'recommandations' : uniquement quand une information manque ou un conseil est pertinent. Sinon [].\n";
        $prompt .= "- 'texte_corrige' : lettre entière réécrite. TOUTES les duplications et anomalies doivent être corrigées.\n";
        $prompt .= "- NE JAMAIS générer de scores.";

        try {
            $raw = $this->llm->call([
                ['role' => 'system', 'content' => 'Tu es un recruteur expérimenté, expert en rédaction de lettres de motivation et correcteur linguistique. Tu ANALYSES en profondeur, DIAGNOSTIQUES les vrais problèmes (duplications, anomalies, erreurs, faiblesses) puis RÉÉCRIS réellement les lettres. Tu ne te contentes JAMAIS de corriger quelques fautes ou remplacer des synonymes. Tu détectes et corriges TOUTE duplication textuelle. Tu ne JAMAIS inventes d\'informations. Réponds UNIQUEMENT en JSON valide, sans markdown.'],
                ['role' => 'user',   'content' => $prompt]
            ], ['temperature' => 0.3, 'max_tokens' => 4000]);

            $json_data = $this->llm->extractJson($raw);
            if (!$json_data) throw new \Exception("Format invalide retourné par l'IA.");

            $corrected = trim($json_data['texte_corrige'] ?? '');
            if (empty($corrected)) {
                $json_data['texte_corrige'] = $text;
            } elseif (mb_strlen($corrected, 'UTF-8') < mb_strlen($text, 'UTF-8') * 0.4) {
                $json_data['texte_corrige'] = $text;
                $json_data['ameliorations'] = [['avant' => '', 'probleme' => 'Le texte corrigé était trop court', 'apres' => '', 'justification' => 'Version originale conservée par sécurité']];
            } elseif (mb_strlen($corrected, 'UTF-8') > mb_strlen($text, 'UTF-8') * 2.5) {
                $json_data['texte_corrige'] = $text;
                $json_data['ameliorations'] = [['avant' => '', 'probleme' => 'Le texte corrigé était trop différent de l\'original', 'apres' => '', 'justification' => 'Version originale conservée par sécurité']];
            } else {
                $json_data['texte_corrige'] = $corrected;
            }

            if (!is_array($json_data['analyse'] ?? null)) $json_data['analyse'] = [];
            if (!is_array($json_data['ameliorations'] ?? null)) $json_data['ameliorations'] = [];
            if (!is_array($json_data['recommandations'] ?? null)) $json_data['recommandations'] = [];

            unset($json_data['score']);
            unset($json_data['score_details']);

            $this->json(['success' => true, 'data' => $json_data]);
        } catch (\Exception $e) {
            error_log('[LettreController] ' . $e->getMessage());
            $this->json(['success' => false, 'error' => 'Erreur interne'], 500);
        }
    }

    public function save(): void {
        $userId = Auth::require();
        $input = $this->input();

        try {
            $content = $input['content'] ?? '';
            $offer   = $input['offer']   ?? 'Lettre sans titre';
            $score   = $input['score']   ?? 0;
            $template = $input['template'] ?? 'classique';

            if (empty($content)) throw new \Exception("Contenu vide.");

            $this->letterModel->create($userId, $content, $offer, $score, $template);
            $this->json(['success' => true, 'message' => 'Lettre enregistrée !']);
        } catch (\Exception $e) {
            error_log('[LettreController] ' . $e->getMessage());
            $this->json(['success' => false, 'error' => 'Erreur interne'], 500);
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
