<?php
namespace App\Controllers;

use App\Models\InterviewHistory;
use App\Models\UserNote;
use App\Middleware\Auth;
use App\Services\LlmService;

class EntretienController {
    private InterviewHistory $historyModel;
    private UserNote $noteModel;
    private LlmService $llm;
    private int $maxQuestions = 5;

    public function __construct() {
        $this->historyModel = new InterviewHistory($GLOBALS['pdo']);
        $this->noteModel = new UserNote($GLOBALS['pdo']);
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

    public function question(): void {
        if (!isset($_SESSION['q_index'])) {
            $_SESSION['q_index'] = 0;
            $_SESSION['interview_history'] = [];
        }

        $index = $_SESSION['q_index'];

        if ($index >= $this->maxQuestions) {
            $this->json([
                "question" => "L'entretien est maintenant terminé. Merci pour cet échange ! Nous reviendrons vers vous très vite.",
                "end" => true
            ]);
            return;
        }

        if ($index === 0 && empty($_SESSION['interview_history'])) {
            $question = "Bonjour ! Je suis ravi de vous recevoir pour cet entretien. Pour commencer, pouvez-vous vous présenter et me parler de votre parcours ?";
            $_SESSION['current_question'] = $question;
            $_SESSION['q_index']++;
            $this->json([
                "question" => $question,
                "progress" => $_SESSION['q_index'] . "/" . $this->maxQuestions,
                "end" => false
            ]);
            return;
        }

        $this->json([
            "question" => $_SESSION['current_question'] ?? "Désolé, j'ai perdu le fil. Pouvons-nous reprendre ?",
            "progress" => $_SESSION['q_index'] . "/" . $this->maxQuestions,
            "end" => false
        ]);
    }

    public function analyze(): void {
        $input = $this->input();
        $answer = $input['answer'] ?? '';
        $question = $_SESSION['current_question'] ?? "Parlez-moi de vous.";
        $history = $_SESSION['interview_history'] ?? [];
        $index = $_SESSION['q_index'] ?? 1;

        if (trim($answer) === "") {
            $this->json([
                "success" => true,
                "feedback" => "Je n'ai pas bien entendu votre réponse.",
                "conseil" => "Il est important de répondre aux questions du recruteur, même brièvement, pour montrer votre engagement.",
                "next_question" => "Pourriez-vous essayer de répondre à ma question : \"$question\" ?",
                "end" => false
            ]);
            return;
        }

        $historyText = "";
        foreach ($history as $item) {
            $historyText .= "Q: {$item['question']}\nR: {$item['answer']}\n";
        }

        $prompt = "Tu es un recruteur expert menant un entretien de recrutement.\n";
        $prompt .= "Historique de la conversation :\n$historyText\n";
        $prompt .= "Dernière question posée : \"$question\"\n";
        $prompt .= "Réponse du candidat : \"$answer\"\n\n";
        $prompt .= "TRAVAIL À FAIRE :\n";
        $prompt .= "1. Analyse la réponse du candidat (feedback constructif, points forts/faibles).\n";
        $prompt .= "2. Donne un conseil coach (méthode STAR, structure).\n";

        if ($index < $this->maxQuestions) {
            $prompt .= "3. Formule la QUESTION SUIVANTE la plus logique. Ne commence PAS par des phrases automatiques comme 'Très intéressant' ou 'Je vois'. Sois naturel comme un humain. Pose une question qui approfondit le sujet ou passe à une nouvelle compétence.\n";
        } else {
            $prompt .= "3. Termine l'entretien poliment.\n";
        }

        $prompt .= "\nRéponds UNIQUEMENT en JSON avec cette structure :\n";
        $prompt .= '{"feedback": "...", "conseil": "...", "next_question": "..."}';

        try {
            $raw = $this->llm->call([
                ['role' => 'system', 'content' => 'Tu es un recruteur professionnel et humain. Tu écoutes vraiment le candidat et tu rebondis sur ses propos. Réponds uniquement en JSON.'],
                ['role' => 'user', 'content' => $prompt]
            ]);

            $json = $this->llm->extractJson($raw);
            if (!$json) throw new \Exception("Erreur analyse réponse");

            $_SESSION['interview_history'][] = [
                'question' => $question,
                'answer' => $answer,
                'feedback' => $json['feedback']
            ];

            // "end" est déterminé uniquement par le compteur de questions,
            // jamais par la présence (ou non) d'un champ next_question dans
            // la réponse de l'IA — sinon la fin de l'entretien dépend d'un
            // détail non garanti du JSON généré.
            $isLastAnswer = $index >= $this->maxQuestions;

            if (!$isLastAnswer && !empty($json['next_question'])) {
                $_SESSION['current_question'] = $json['next_question'];
                $_SESSION['q_index']++;
            }

            $this->json([
                "success" => true,
                "feedback" => $json['feedback'],
                "conseil" => $json['conseil'],
                "next_question" => $isLastAnswer ? null : ($json['next_question'] ?? null),
                "end" => $isLastAnswer
            ]);
        } catch (\Exception $e) {
            error_log('[EntretienController] ' . $e->getMessage());
            $this->json(["success" => false, "error" => "Erreur interne"], 500);
        }
    }

    public function deleteLastAnswer(): void {
        if (!isset($_SESSION['interview_history']) || empty($_SESSION['interview_history'])) {
            $this->json(["success" => false, "error" => "Aucune réponse à supprimer."]);
            return;
        }

        $lastItem = array_pop($_SESSION['interview_history']);
        $_SESSION['current_question'] = $lastItem['question'];
        $_SESSION['q_index']--;
        $this->json([
            "success" => true,
            "message" => "Dernière réponse supprimée.",
            "current_question" => $_SESSION['current_question'],
            "q_index" => $_SESSION['q_index']
        ]);
    }

    public function saveNotes(): void {
        $userId = Auth::require();
        $input = $this->input();
        $notes = $input['notes'] ?? '';

        if (empty($notes)) {
            $this->json(["success" => false, "error" => "Les notes sont vides."]);
            return;
        }

        try {
            $title = "Notes d'entretien " . date('d/m/Y H:i');
            $this->noteModel->create($userId, $title, $notes);
            $this->json(["success" => true, "message" => "Notes sauvegardées avec succès !"]);
        } catch (\Exception $e) {
            error_log('[EntretienController] ' . $e->getMessage());
            $this->json(["success" => false, "error" => "Erreur interne"], 500);
        }
    }

    public function list(): void {
        $userId = Auth::require();
        $history = $this->historyModel->findByUser($userId);
        $this->json(['success' => true, 'history' => $history]);
    }

    public function get(): void {
        $userId = Auth::require();
        $id = $_REQUEST['id'] ?? null;
        $item = $this->historyModel->findById((int)$id, $userId);
        $this->json(['success' => true, 'data' => $item]);
    }

    public function deleteHistory(): void {
        $userId = Auth::require();
        $id = $_REQUEST['id'] ?? null;
        $this->historyModel->delete((int)$id, $userId);
        $this->json(['success' => true]);
    }

    public function listNotes(): void {
        $userId = Auth::require();
        $notes = $this->noteModel->findByUser($userId);
        $this->json(['success' => true, 'notes' => $notes]);
    }

    public function getNote(): void {
        $userId = Auth::require();
        $id = $_REQUEST['id'] ?? null;
        $note = $this->noteModel->findById((int)$id, $userId);
        $this->json(['success' => true, 'data' => $note]);
    }

    public function deleteNote(): void {
        $userId = Auth::require();
        $id = $_REQUEST['id'] ?? null;
        $this->noteModel->delete((int)$id, $userId);
        $this->json(['success' => true]);
    }

    public function reset(): void {
        unset($_SESSION['q_index'], $_SESSION['interview_history'], $_SESSION['current_question']);
        $this->json(["success" => true, "message" => "Entretien réinitialisé."]);
    }

    public function save(): void {
        $userId = Auth::require();
        $input = $this->input();
        $jobTitle = trim($input['job_title'] ?? '');
        if ($jobTitle === '') {
            $jobTitle = 'Entretien général';
        }

        $history = $_SESSION['interview_history'] ?? [];
        if (empty($history)) {
            $this->json(["success" => false, "error" => "Aucun entretien en cours à sauvegarder."]);
            return;
        }

        $globalScore = $this->computeGlobalScore($history);

        try {
            $id = $this->historyModel->create($userId, $jobTitle, $globalScore, $history);
            // On vide la session pour éviter une double sauvegarde du même entretien
            unset($_SESSION['q_index'], $_SESSION['interview_history'], $_SESSION['current_question']);
            $this->json(["success" => true, "id" => $id, "global_score" => $globalScore]);
        } catch (\Exception $e) {
            error_log('[EntretienController] ' . $e->getMessage());
            $this->json(["success" => false, "error" => "Erreur lors de la sauvegarde de l'entretien."], 500);
        }
    }

    private function computeGlobalScore(array $history): int {
        $totalScore = 0;
        $count = count($history);
        if ($count === 0) return 0;

        foreach ($history as $item) {
            $answer = trim($item['answer'] ?? '');
            $wordCount = str_word_count(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $answer));

            // 1. Longueur de la réponse (0-25 pts) — plus c'est long, plus c'est détaillé
            if ($wordCount >= 80) $answerScore = 25;
            elseif ($wordCount >= 40) $answerScore = 20;
            elseif ($wordCount >= 20) $answerScore = 15;
            elseif ($wordCount >= 10) $answerScore = 10;
            else $answerScore = 5;

            // 2. Présence de structure (0-20 pts) — indicateurs STAR, connecteurs
            $structureIndicators = ['parce que', 'grâce à', 'ce qui', 'notamment', 'par exemple',
                'en effet', 'd\'ailleurs', 'de plus', 'ensuite', 'finalement', 'résultat', 'objectif',
                'action', 'situation', 'tâche', 'résultats'];
            $structureCount = 0;
            foreach ($structureIndicators as $ind) {
                if (mb_stripos($answer, $ind, 0, 'UTF-8') !== false) $structureCount++;
            }
            $answerScore += min($structureCount * 5, 20);

            // 3. Variété du vocabulaire (0-20 pts) — ratio mots uniques
            $words = array_filter(preg_split('/\s+/', mb_strtolower($answer, 'UTF-8')), fn($w) => mb_strlen($w, 'UTF-8') > 3);
            $uniqueRatio = count($words) > 0 ? count(array_unique($words)) / count($words) : 0;
            $answerScore += (int) round(min($uniqueRatio * 100, 100) * 0.20);

            // 4. Présence de mots-clés compétences (0-15 pts)
            $skillIndicators = ['compétence', 'expérience', 'projet', 'réalisé', 'développé',
                'géré', 'responsable', 'formation', 'diplôme', 'méthode', 'outil', 'technologie',
                'results', 'achieved', 'managed', 'developed', 'project', 'experience', 'skill'];
            $skillCount = 0;
            foreach ($skillIndicators as $skill) {
                if (mb_stripos($answer, $skill, 0, 'UTF-8') !== false) $skillCount++;
            }
            $answerScore += min($skillCount * 3, 15);

            // 5. Qualité du feedback IA (0-20 pts) — bonus si le feedback est positif
            $feedback = mb_strtolower($item['feedback'] ?? '', 'UTF-8');
            if (preg_match('/excellent|très bien|bravo|pertinent|solide|convaincant|clair|structuré/iu', $feedback)) {
                $answerScore += 10;
            } elseif (preg_match('/correct|acceptable|correctement/iu', $feedback)) {
                $answerScore += 5;
            }

            $totalScore += min($answerScore, 100);
        }

        return max(0, min(100, (int) round($totalScore / $count)));
    }
}
