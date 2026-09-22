<?php
namespace App\Services;

class AtsScorer {
    public function calculateRealATSScore(string $cvContent, string $jobOffer): array {
        $cvLower = mb_strtolower($cvContent, 'UTF-8');
        $offerLower = mb_strtolower($jobOffer, 'UTF-8');

        $offerKeywords = $this->extractKeywords($offerLower);

        $matchedKeywords = [];
        $missingKeywords = [];
        foreach ($offerKeywords as $keyword) {
            if (strpos($cvLower, $keyword) !== false) {
                $matchedKeywords[] = $keyword;
            } else {
                $missingKeywords[] = $keyword;
            }
        }
        $keywordMatch = count($offerKeywords) > 0 ? (count($matchedKeywords) / count($offerKeywords)) * 100 : 0;

        // Compétences attendues déduites DYNAMIQUEMENT de l'offre d'emploi.
        // (Avant : liste figée de 19 mots-clés informatiques ("php","react","docker"...) qui pénalisait
        // injustement tout CV hors du domaine tech. Désormais le calcul s'adapte à N'IMPORTE QUEL métier :
        // finance, santé, RH, vente, marketing, BTP, etc., en se basant sur les mots spécifiques de l'offre.)
        $genericWords = ['développeur', 'developer', 'ingénieur', 'engineer', 'manager', 'chef',
            'expérience', 'experience', 'compétence', 'skill', 'formation', 'education',
            'diplôme', 'degree', 'licence', 'master', 'anglais', 'english', 'français', 'french',
            'gestion', 'management', 'leadership', 'communication', 'analyse', 'analysis',
            'développement', 'development', 'projet', 'project', 'équipe', 'team', 'client', 'customer'];
        $offerSpecificWords = array_values(array_filter($offerKeywords, function ($w) use ($genericWords) {
            return mb_strlen($w, 'UTF-8') > 4 && !in_array($w, $genericWords, true);
        }));

        if (count($offerSpecificWords) > 0) {
            // Une offre a été fournie : on vérifie combien de ses termes spécifiques (quel que soit le métier)
            // se retrouvent dans le CV.
            $foundSkills = [];
            foreach ($offerSpecificWords as $skill) {
                if (strpos($cvLower, $skill) !== false) {
                    $foundSkills[] = $skill;
                }
            }
            $skillsMatch = (count($foundSkills) / count($offerSpecificWords)) * 100;
        } else {
            // Pas d'offre fournie : on évalue simplement si le CV présente une vraie section
            // "compétences" bien remplie, sans présumer du domaine (technique, commercial, médical...).
            $foundSkills = [];
            if (preg_match('/(compétences?|skills?|savoir[- ]faire)\s*[:\n]/ui', $cvContent, $m, PREG_OFFSET_CAPTURE)) {
                $sectionStart = $m[0][1] + strlen($m[0][0]);
                $sectionText = mb_substr($cvContent, $sectionStart, 400);
                $items = preg_split('/[,•\-\n;]+/u', $sectionText, -1, PREG_SPLIT_NO_EMPTY);
                $items = array_values(array_filter(array_map('trim', $items), function ($i) {
                    return mb_strlen($i, 'UTF-8') > 1;
                }));
                $foundSkills = array_slice($items, 0, 15);
            }
            $skillsMatch = min(count($foundSkills) * 10, 100);
        }

        $experienceIndicators = ['expérience', 'expériences', 'travaillé', 'développé', 'géré', 'responsable', 'projet', 'projets', 'année', 'années'];
        $experienceScore = 0;
        foreach ($experienceIndicators as $indicator) {
            if (strpos($cvLower, $indicator) !== false) {
                $experienceScore += 5;
            }
        }
        $experienceMatch = min($experienceScore, 100);

        $structureScore = 0;
        $requiredSections = ['compétence', 'expérience', 'formation', 'éducation', 'contact', 'email', 'téléphone'];
        foreach ($requiredSections as $section) {
            if (strpos($cvLower, $section) !== false) {
                $structureScore += 15;
            }
        }
        $structureScore = min($structureScore, 100);

        $totalScore = round(
            ($keywordMatch * 0.40) +
            ($skillsMatch * 0.25) +
            ($experienceMatch * 0.20) +
            ($structureScore * 0.15)
        );

        return [
            'total' => $totalScore,
            'keyword_match' => round($keywordMatch),
            'skills_match' => round($skillsMatch),
            'experience_match' => round($experienceMatch),
            'structure_score' => round($structureScore),
            'matched_keywords' => $matchedKeywords,
            'missing_keywords' => $missingKeywords,
            'found_skills' => $foundSkills
        ];
    }

    public function extractKeywords(string $text): array {
        $commonKeywords = [
            'développeur', 'developer', 'ingénieur', 'engineer', 'manager', 'chef',
            'expérience', 'experience', 'compétence', 'skill', 'formation', 'education',
            'diplôme', 'degree', 'licence', 'master', 'bac',
            'anglais', 'english', 'français', 'french',
            'gestion', 'management', 'leadership', 'communication',
            'analyse', 'analysis', 'développement', 'development',
            'projet', 'project', 'équipe', 'team', 'client', 'customer',
            'vente', 'sales', 'marketing', 'rh', 'hr', 'finance', 'comptabilité',
            'logiciel', 'software', 'application', 'web', 'mobile', 'data', 'base de données'
        ];

        $words = preg_split('/[\s,.;:!?()\[\]{}"\'-]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $words = array_filter($words, function($word) {
            return mb_strlen($word, 'UTF-8') > 3;
        });

        $foundKeywords = [];
        foreach ($commonKeywords as $keyword) {
            if (in_array($keyword, $words) || strpos($text, $keyword) !== false) {
                $foundKeywords[] = $keyword;
            }
        }

        $uniqueWords = array_unique($words);
        foreach ($uniqueWords as $word) {
            if (!in_array($word, $foundKeywords) && mb_strlen($word, 'UTF-8') > 4) {
                $foundKeywords[] = $word;
            }
        }

        return array_slice($foundKeywords, 0, 20);
    }

    public function calculateCompletenessScore(array $info): int {
        $info = $this->normalizeCvInfoForScoring($info);
        $score = 0;

        if (!empty($info['email'])) $score += 5;
        if (!empty($info['tel'])) $score += 5;
        if (!empty($info['ville'])) $score += 5;
        if (!empty($info['linkedin'])) $score += 5;

        if (!empty($info['profil']) && mb_strlen($info['profil'], 'UTF-8') > 50) $score += 15;

        if (!empty($info['competences'])) {
            $compCount = 0;
            if (!empty($info['competences']['techniques'])) $compCount += count($info['competences']['techniques']);
            if (!empty($info['competences']['outils'])) $compCount += count($info['competences']['outils']);
            if (!empty($info['competences']['soft'])) $compCount += count($info['competences']['soft']);
            $score += min($compCount * 3, 25);
        }

        if (!empty($info['experience']) && is_array($info['experience'])) {
            $expScore = 0;
            foreach ($info['experience'] as $exp) {
                if (!empty($exp['poste'])) $expScore += 5;
                if (!empty($exp['entreprise'])) $expScore += 5;
                if (!empty($exp['periode'])) $expScore += 3;
                if (!empty($exp['realisation']) && is_array($exp['realisation']) && count($exp['realisation']) > 0) $expScore += 2;
            }
            $score += min($expScore, 25);
        }

        if (!empty($info['formation']) && is_array($info['formation'])) {
            $formScore = 0;
            foreach ($info['formation'] as $form) {
                if (!empty($form['diplome'])) $formScore += 5;
                if (!empty($form['etablissement'])) $formScore += 5;
                if (!empty($form['annee'])) $formScore += 5;
            }
            $score += min($formScore, 15);
        }

        if (!empty($info['langues']) && is_array($info['langues'])) {
            $langScore = 0;
            foreach ($info['langues'] as $lang) {
                if (!empty($lang['langue'])) $langScore += 3;
                if (!empty($lang['niveau'])) $langScore += 2;
            }
            $score += min($langScore, 10);
        }

        return min($score, 100);
    }

    public function parseDatedTextRows(string $text, string $type): array {
        $rows = preg_split('/\r\n|\r|\n/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $items = [];

        foreach ($rows as $row) {
            $parts = preg_split('/\s*:\s*/', trim($row), 2);
            $date = count($parts) === 2 ? trim($parts[0]) : '';
            $desc = count($parts) === 2 ? trim($parts[1]) : trim($row);

            if ($desc === '') continue;

            if ($type === 'formation') {
                $items[] = ['diplome' => $desc, 'etablissement' => '', 'annee' => $date];
            } else {
                $items[] = ['poste' => $desc, 'entreprise' => '', 'periode' => $date, 'realisation' => [$desc]];
            }
        }

        return $items;
    }

    public function normalizeCvInfoForScoring(array $info): array {
        if (empty($info['tel']) && !empty($info['telephone'])) {
            $info['tel'] = $info['telephone'];
        }
        if (empty($info['profil']) && !empty($info['resume'])) {
            $info['profil'] = $info['resume'];
        }
        if (empty($info['experience']) && !empty($info['experiences'])) {
            $info['experience'] = is_array($info['experiences'])
                ? $info['experiences']
                : $this->parseDatedTextRows($info['experiences'], 'experience');
        }
        if (!empty($info['formation']) && !is_array($info['formation'])) {
            $info['formation'] = $this->parseDatedTextRows($info['formation'], 'formation');
        }
        $soft = array_values(array_filter(array_map('trim', explode(',', $info['soft_competences'] ?? ''))));
        if (!empty($info['competences']) && !is_array($info['competences'])) {
            $skills = array_values(array_filter(array_map('trim', explode(',', $info['competences']))));
            $info['competences'] = ['techniques' => $skills, 'outils' => [], 'soft' => $soft];
        } elseif (!empty($soft)) {
            $info['competences'] = ['techniques' => [], 'outils' => [], 'soft' => $soft];
        }

        // Normalisation des langues : convertir une chaîne libre en tableau structuré
        if (!empty($info['langues']) && is_string($info['langues'])) {
            $info['langues'] = $this->parseLanguesString($info['langues']);
        }
        if (!empty($info['langues']) && !is_array($info['langues'])) {
            $info['langues'] = [];
        }

        return $info;
    }

    public function cleanAiPlaceholderText($value) {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->cleanAiPlaceholderText($v);
            }
            return $out;
        }
        if (!is_string($value)) return $value;

        $trimmed = trim($value);
        $placeholderPattern = '/^(non[\s\-]?renseign[ée]e?|n\/?a|non[\s\-]?applicable|non[\s\-]?sp[ée]cifi[ée]e?|non[\s\-]?communiqu[ée]e?|[àa]\s?d[ée]finir|inconnu(e)?|not\s?provided|not\s?specified)\.?$/iu';
        if (preg_match($placeholderPattern, $trimmed)) {
            return '';
        }
        return $value;
    }

    /**
     * Score de QUALITÉ d'écriture d'une lettre (utilisé par la correction).
     * Ne compare PAS à une offre — mesure la forme : longueur, structure,
     * formules d'usage, absence de répétitions excessives.
     * Différent de calculateRealATSScore() qui mesure, lui, la PERTINENCE
     * (correspondance mots-clés) entre une lettre/CV et une offre précise.
     */
    public function calculateLetterQualityScore(string $text): array {
        $text = trim($text);
        $details = [];
        $anomalies = [];

        // ── Longueur (idéal : 120-250 mots pour une lettre de motivation) ──
        $wordCount = str_word_count(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text));
        $details['word_count'] = $wordCount;
        if ($wordCount >= 120 && $wordCount <= 250) {
            $details['length_score'] = 25;
        } elseif ($wordCount >= 80 && $wordCount <= 320) {
            $details['length_score'] = 15;
        } else {
            $details['length_score'] = 5;
        }

        // ── Formules d'usage (ouverture / clôture) ──
        $textLower = mb_strtolower($text, 'UTF-8');
        $hasOpening = (bool) preg_match('/madame|monsieur/iu', $textLower);
        $hasClosing = (bool) preg_match('/salutations|considération|agréer|cordialement/iu', $textLower);
        $details['formula_score'] = ($hasOpening ? 10 : 0) + ($hasClosing ? 10 : 0);

        // ── Structure en paragraphes (idéal : 3 à 4 paragraphes du corps) ──
        // Filtrer les lignes d'en-tête (ne pas les compter comme paragraphes de corps)
        $allBlocks = array_values(array_filter(preg_split('/\n\s*\n/', $text), fn($p) => trim($p) !== ''));

        // Patterns d'en-tête à exclure du compte de paragraphes
        $headerPatterns = [
            '/^[\p{L}\s]+$/',  // Une seule ligne de nom/prénom
            '/^(adresse|tel|téléphone|email|mail|poste|ville|cp|code)\s*:?/i',
            '/^\d{1,2}\s+(janvier|février|mars|avril|mai|juin|juillet|août|septembre|octobre|novembre|décembre)\s+\d{4}/i',
            '/^(madame|monsieur|à l.attention|objet)\s*:?/i',
            '/^(cordialement|salutations|distinguées|respectueuses)\s*$/i',
        ];

        // Formules d'usage : jamais des paragraphes de corps
        $politenessPatterns = [
            '/^(madame|monsieur|ma[sd]ame,? monsieur)\b/iu',
            '/je vous (prie|saurai[s]? gré|serais obligé)/iu',
            '/veuillez agréer/iu',
            '/salutations (distinguées|respectueuses|dévouées)/iu',
            '/^(cordialement|bien cordialement|dans l.attente)/iu',
        ];

        $bodyParagraphs = [];
        foreach ($allBlocks as $block) {
            $trimmed = trim($block);
            $flat = trim(preg_replace('/\s+/u', ' ', $trimmed));

            $isHeader = false;
            foreach (array_merge($headerPatterns, $politenessPatterns) as $pattern) {
                if (preg_match($pattern, $trimmed)) {
                    $isHeader = true;
                    break;
                }
            }
            if ($isHeader) continue;

            // Un paragraphe de corps est un bloc développé. Sans ce filtre, nom,
            // adresse, date, destinataire, objet, listes à puces et formule de
            // politesse étaient comptés comme des paragraphes : une lettre
            // classique bien mise en page se voyait pénalisée pour une structure
            // correcte. Un bloc très long est retenu même sans ponctuation finale,
            // pour ne pas perdre un paragraphe volontairement sans point.
            $flatLen = mb_strlen($flat, 'UTF-8');
            if ($flatLen >= 200 || ($flatLen >= 60 && preg_match('/[.!?]$/u', $flat))) {
                $bodyParagraphs[] = $block;
            }
        }
        $paraCount = count($bodyParagraphs);
        $details['paragraph_count'] = $paraCount;
        if ($paraCount >= 3 && $paraCount <= 4) {
            $details['structure_score'] = 20;
        } elseif ($paraCount >= 2 && $paraCount <= 5) {
            $details['structure_score'] = 12;
        } else {
            $details['structure_score'] = 5;
        }

        // ── Variété du vocabulaire ──
        $words = array_filter(preg_split('/\s+/', $textLower), fn($w) => mb_strlen($w, 'UTF-8') > 3);
        $uniqueRatio = count($words) > 0 ? count(array_unique($words)) / count($words) : 0;
        $details['variety_score'] = (int) round(min($uniqueRatio * 100, 100) * 0.25);

        // ── Absence de texte d'espace réservé IA non nettoyé ──
        $hasPlaceholder = (bool) preg_match('/\[.*?\]|xxx|lorem ipsum/iu', $text);
        $details['no_placeholder_score'] = $hasPlaceholder ? 0 : 10;

        // ── Détection de duplications textuelles ──
        $duplicationScore = 20; // Score max = 20, on retire des points par anomalie trouvée

        // 1. Lignes dupliquées (ligne identique apparaît 2+ fois)
        // Ne pas signaler les éléments d'en-tête qui apparaissent normalement une fois au début et une fois en clôture
        // Motifs exclus : nom/adresse/téléphone/email, date, destinataire, objet, formule d'appel
        $lines = array_map('trim', preg_split('/\r?\n/', $text));
        $lines = array_filter($lines, fn($l) => mb_strlen($l, 'UTF-8') > 5);
        $lineCounts = array_count_values($lines);
        foreach ($lineCounts as $line => $count) {
            if ($count >= 2) {
                $trimmed = mb_substr($line, 0, 80, 'UTF-8');
                // Exclure les en-têtes normaux qui apparaissent une fois au début et une fois en clôture
                $excludedPatterns = [
                    '/^madame,\s*monsieur,$/i',           // Madame, Monsieur, en début et fin
                    '/^[\p{L}\s]+$/',                     // Ligne ne contenant que nom/prénom
                    '/^(adresse|tel|téléphone|email|mail)\s*:?/i',
                    '/^\d{1,2}\s+(janvier|février|mars|avril|mai|juin|juillet|août|septembre|octobre|novembre|décembre)\s+\d{4}/i',
                    '/^objet\s*:/i',
                    '/^(madame|monsieur)\s*[,]?\s*$/i',
                ];
                $isExcluded = false;
                foreach ($excludedPatterns as $pattern) {
                    if (preg_match($pattern, $line)) {
                        $isExcluded = true;
                        break;
                    }
                }
                if (!$isExcluded) {
                    $anomalies[] = "Ligne dupliquée : « " . $trimmed . " » (×$count)";
                    $duplicationScore -= 8;
                }
            }
        }

        // 2. Groupes de mots dupliqués (3+ mots consécutifs identiques)
        // Ne pas flaguer les mots/phrases courants qui peuvent apparaître plusieurs fois naturellement
        $stopDuplicationWords = [
            'tech', 'solutions', 'madagascar', 'candidature', 'poste', 'competences',
            'competence', 'vous', 'je', 'suis', 'être', 'être', 'poste', 'mot', 'motivation',
            'entreprise', 'client', 'equipe', 'projet', 'faire', 'avoir', 'ainsi', 'donc'
        ];
        $stopDuplicationWords = array_map('mb_strtolower', $stopDuplicationWords);

        $cleanText = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $cleanText = preg_replace('/\s+/', ' ', trim($cleanText));
        $allWords = explode(' ', $cleanText);
        for ($ngram = 4; $ngram >= 3; $ngram--) {
            for ($i = 0; $i <= count($allWords) - ($ngram * 2); $i++) {
                $chunk = implode(' ', array_slice($allWords, $i, $ngram));
                $chunkLower = mb_strtolower($chunk, 'UTF-8');
                $rest = implode(' ', array_slice($allWords, $i + $ngram));
                // Ne pas flaguer si le chunk contient des mots courants en arrêt
                $hasStopWord = false;
                $chunkWords = explode(' ', $chunkLower);
                foreach ($chunkWords as $cw) {
                    if (in_array($cw, $stopDuplicationWords)) {
                        $hasStopWord = true;
                        break;
                    }
                }
                if ($hasStopWord) continue; // Skip this chunk
                $restLower = mb_strtolower($rest, 'UTF-8');
                if (mb_strlen($chunk, 'UTF-8') > 8 && mb_stripos($restLower, $chunkLower, 0, 'UTF-8') !== false) {
                    $anomalies[] = "Groupe de mots dupliqué : « " . mb_substr($chunk, 0, 80, 'UTF-8') . " »";
                    $duplicationScore -= 10;
                    break;
                }
            }
        }

        // 3. Détection de formules de politesse ou salutations répétées
        // Ne compter que les formules complètes "Madame, Monsieur," et non les occurrences individuelles
        // Flaguer uniquement si le pattern complet apparaît 2+ fois (vrai duplication)
        $openingPattern = "/(?:^|[\n\r])madame,\s*monsieur,$/iu";
        preg_match_all($openingPattern, $textLower, $openings);
        $openingCount = count($openings[0]);
        if ($openingCount > 1) {
            // Vérifier si c'est la forme "Madame, Monsieur, Madame, Monsieur," (vraie duplication)
            // ou simplement Madame, Monsieur... + Madame, Monsieur à la clôture (normal)
            $fullDuplicationPattern = "/madame,\s*monsieur,$/iu";
            if (preg_match_all($fullDuplicationPattern, $text, $fullMatches) && count($fullMatches[0]) > 1) {
                // Vérifier si c'est le pattern "Madame, Monsieur, Madame, Monsieur," (suite immédiate)
                $consecutivePattern = "/madame,\s*monsieur,,\s*madame,\s*monsieur,/i";
                if (preg_match($consecutivePattern, $text)) {
                    $anomalies[] = "Formule d'ouverture dupliquée : « Madame, Monsieur, Madame, Monsieur, »";
                    $duplicationScore -= 5;
                } elseif ($openingCount > 2) {
                    $anomalies[] = "Formule d'ouverture répétée (" . $openingCount . " occurrences)";
                    $duplicationScore -= 5;
                }
            }
        }

        $closingPattern = "/(?:je\s+(?:vous\s+)?prie\s+d.agréer)(?:\s+madame|monsieur)?/iu";
        preg_match_all($closingPattern, $textLower, $closings);
        $closingCount = count($closings[0]);
        if ($closingCount > 1) {
            $anomalies[] = "Formule de clôture répétée (" . $closingCount . " occurrences détectées)";
            $duplicationScore -= 5;
        }

        // 4. Caractères parasites ou espaces anormaux
        if (preg_match('/[^\p{L}\p{N}\p{P}\p{Z}\n\r\t]/u', $text)) {
            $anomalies[] = "Présence de caractères parasites ou invisibles dans le texte";
            $duplicationScore -= 5;
        }
        if (preg_match('/\s{4,}/', $text)) {
            $anomalies[] = "Espaces multiples excessifs détectés";
            $duplicationScore -= 3;
        }

        $details['duplication_score'] = max($duplicationScore, 0);

        // ── Score final ──
        $total = min(
            $details['length_score'] + $details['formula_score'] +
            $details['structure_score'] + $details['variety_score'] +
            $details['no_placeholder_score'] + $details['duplication_score'],
            100
        );

        $result = array_merge(['total' => $total], $details);
        if (!empty($anomalies)) {
            $result['anomalies'] = $anomalies;
        }
        return $result;
    }

    public function calculateAgeFromBirthdate(?string $dateNaissance): ?int {
        if (empty($dateNaissance)) return null;
        try {
            $birth = new \DateTime($dateNaissance);
            $today = new \DateTime('today');
            if ($birth > $today) return null;
            return $today->diff($birth)->y;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function calculateGeneratedCvScore(array $json_data): array {
        $c = $json_data['contact'] ?? [];
        $details = [];

        $details['contact_score'] =
            (!empty($c['email']) ? 5 : 0) +
            (!empty($c['tel']) ? 5 : 0) +
            (!empty($c['ville']) ? 5 : 0) +
            (!empty($c['linkedin']) ? 5 : 0);

        $profil = $json_data['profil'] ?? '';
        $details['profile_score'] = (!empty($profil) && mb_strlen($profil, 'UTF-8') > 50) ? 15 : 0;

        $comp = $json_data['competences'] ?? [];
        $compCount = (!empty($comp['techniques']) ? count($comp['techniques']) : 0)
                   + (!empty($comp['outils']) ? count($comp['outils']) : 0)
                   + (!empty($comp['soft']) ? count($comp['soft']) : 0);
        $details['skills_score'] = min($compCount * 3, 25);

        $experience = $json_data['experience'] ?? [];
        $details['experience_score'] = is_array($experience) ? min(array_reduce($experience, function($carry, $exp) {
            return $carry + (!empty($exp['poste']) ? 5 : 0) + (!empty($exp['entreprise']) ? 5 : 0) + (!empty($exp['periode']) ? 3 : 0) + (!empty($exp['realisation']) && is_array($exp['realisation']) && count($exp['realisation']) > 0 ? 2 : 0);
        }, 0), 25) : 0;

        $formation = $json_data['formation'] ?? [];
        $details['education_score'] = is_array($formation) ? min(array_reduce($formation, function($carry, $form) {
            return $carry + (!empty($form['diplome']) ? 5 : 0) + (!empty($form['etablissement']) ? 5 : 0) + (!empty($form['annee']) ? 5 : 0);
        }, 0), 15) : 0;

        $langues = $json_data['langues'] ?? [];
        $details['languages_score'] = is_array($langues) ? min(array_reduce($langues, function($carry, $lang) {
            return $carry + (!empty($lang['langue']) ? 3 : 0) + (!empty($lang['niveau']) ? 2 : 0);
        }, 0), 10) : 0;

        $details['completeness_score'] = min(
            $details['contact_score'] + $details['profile_score'] + $details['skills_score'] +
            $details['experience_score'] + $details['education_score'] + $details['languages_score'],
            100
        );

        return $details;
    }

    /**
     * Parse une chaîne de langues libre (ex: "Français (natif), Anglais (C1)")
     * en tableau structuré [{langue, niveau}].
     */
    private function parseLanguesString(string $text): array {
        $langues = [];
        $parts = preg_split('/[,;]/', $text);
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) continue;
            // "Français (natif)" → ["Français", "natif"]
            if (preg_match('/^(.+?)\s*\(([^)]+)\)\s*$/u', $part, $m)) {
                $langues[] = ['langue' => trim($m[1]), 'niveau' => trim($m[2])];
            }
            // "Français : Natif" ou "Anglais - Courant"
            elseif (preg_match('/^(.+?)\s*[-:]\s*(.+)$/u', $part, $m)) {
                $langues[] = ['langue' => trim($m[1]), 'niveau' => trim($m[2])];
            }
            // Sinon, la langue seule sans niveau
            else {
                $langues[] = ['langue' => $part, 'niveau' => ''];
            }
        }
        return $langues;
    }
}
