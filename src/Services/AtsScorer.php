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

        $technicalSkills = ['php', 'javascript', 'python', 'java', 'react', 'angular', 'vue', 'node', 'sql', 'mysql', 'html', 'css', 'git', 'docker', 'aws', 'azure', 'linux', 'agile', 'scrum'];
        $foundSkills = [];
        foreach ($technicalSkills as $skill) {
            if (strpos($cvLower, $skill) !== false) {
                $foundSkills[] = $skill;
            }
        }
        $skillsMatch = min(count($foundSkills) * 10, 100);

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
        if (!empty($info['competences']) && !is_array($info['competences'])) {
            $skills = array_values(array_filter(array_map('trim', explode(',', $info['competences']))));
            $info['competences'] = ['techniques' => $skills, 'outils' => [], 'soft' => []];
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

        // Longueur (idéal : 120-250 mots pour une lettre de motivation)
        $wordCount = str_word_count(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text));
        if ($wordCount >= 120 && $wordCount <= 250) {
            $details['length_score'] = 25;
        } elseif ($wordCount >= 80 && $wordCount <= 320) {
            $details['length_score'] = 15;
        } else {
            $details['length_score'] = 5;
        }

        // Formules d'usage (ouverture / clôture)
        $textLower = mb_strtolower($text, 'UTF-8');
        $hasOpening = (bool) preg_match('/madame|monsieur/iu', $textLower);
        $hasClosing = (bool) preg_match('/salutations|considération|agréer|cordialement/iu', $textLower);
        $details['formula_score'] = ($hasOpening ? 10 : 0) + ($hasClosing ? 10 : 0);

        // Structure en paragraphes (idéal : 3 à 4 paragraphes)
        $paragraphs = array_values(array_filter(preg_split('/\n\s*\n/', $text), fn($p) => trim($p) !== ''));
        $paraCount = count($paragraphs);
        if ($paraCount >= 3 && $paraCount <= 4) {
            $details['structure_score'] = 20;
        } elseif ($paraCount >= 2 && $paraCount <= 5) {
            $details['structure_score'] = 12;
        } else {
            $details['structure_score'] = 5;
        }

        // Variété du vocabulaire (ratio mots uniques / mots totaux — pénalise les répétitions)
        $words = array_filter(preg_split('/\s+/', $textLower), fn($w) => mb_strlen($w, 'UTF-8') > 3);
        $uniqueRatio = count($words) > 0 ? count(array_unique($words)) / count($words) : 0;
        $details['variety_score'] = (int) round(min($uniqueRatio * 100, 100) * 0.25);

        // Absence de texte d'espace réservé IA non nettoyé
        $hasPlaceholder = (bool) preg_match('/\[.*?\]|xxx|lorem ipsum/iu', $text);
        $details['no_placeholder_score'] = $hasPlaceholder ? 0 : 10;

        $total = min(
            $details['length_score'] + $details['formula_score'] +
            $details['structure_score'] + $details['variety_score'] +
            $details['no_placeholder_score'],
            100
        );

        return array_merge(['total' => $total], $details);
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

        $details['completeness_score'] = min(
            $details['contact_score'] + $details['profile_score'] + $details['skills_score'] +
            $details['experience_score'] + $details['education_score'],
            100
        );

        return $details;
    }
}
