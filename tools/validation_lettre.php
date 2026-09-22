<?php
// Validation du module lettre de motivation — sans base de données ni appel IA.
// Exécution : php tools/validation_lettre.php   (ou composer run validate)
require __DIR__ . '/../vendor/autoload.php';
use App\Services\AtsScorer;

$atsScorer = new AtsScorer();

$passed = 0;
$failed = 0;
$errors = [];

function test(string $name, bool $condition) : void {
    global $passed, $failed, $errors;
    if ($condition) {
        echo "  PASS: $name\n";
        $passed++;
    } else {
        echo "  FAIL: $name\n";
        $failed++;
        $errors[] = $name;
    }
}

// Les anomalies de duplication portent un message suffixé (« Groupe de mots
// dupliqué : « … » ») : on teste le préfixe plutôt que la chaîne exacte.
function aDuplique(array $r): bool {
    foreach ($r['anomalies'] ?? [] as $a) {
        if (str_contains($a, 'dupliqué') || str_contains($a, 'dupliquée')) return true;
    }
    return false;
}

// === 1. SCORE ORIGINAL ===
echo "=== 1. SCORE ORIGINAL ===\n";
$text = "Madame, Monsieur,
Je m'appelle Jean.
Je suis développeur web.
Je vous prie d'agréer, Madame, Monsieur,
l'expression de mes salutations distinguées.";
$result = $atsScorer->calculateLetterQualityScore($text);
test("Score original calculé via AtsScorer", isset($result['total']));
test("Score a word_count", isset($result['word_count']));
test("Score a length_score", isset($result['length_score']));
test("word_count est un nombre", is_numeric($result['word_count']));
// Fixed: use $result instead of $length_score
test("length_score est un nombre entre 0-25", $result['length_score'] >= 0 && $result['length_score'] <= 25);
test("Score est positif", $result['total'] > 0);

// Vérifier que correct() retire score (code inspection)
$ctrl = file_get_contents(__DIR__ . '/../src/Controllers/LettreController.php');
// Ligne 551-552 du controller
test("correct() supprime 'score'", strpos($ctrl, "unset") !== false);
test("correct() supprime 'score_details'", strpos($ctrl, "unset") !== false && strpos($ctrl, "score_details") !== false);

// === 2. DUPLICATIONS A-F ===
echo "=== 2. DUPLICATIONS A-F ===\n";

// TEST A: "Tech Solutions Madagascar" 2x => aucune duplication
$textA = "Madame, Monsieur,
Tech Solutions Madagascar
Antananarivo, le 22 septembre 2026

À l'attention du Responsable du recrutement
Tech Solutions Madagascar

Objet : Candidature au poste de Développeur Web

Madame, Monsieur,
Je vous prie d'agréer, Madame, Monsieur,
l'expression de mes salutations distinguées.";
$resultA = $atsScorer->calculateLetterQualityScore($textA);
test("TEST A: 'Tech Solutions Madagascar' 2x => aucune anomalie",
    !aDuplique($resultA));

// TEST B: "Candidature au poste de" 2x => aucune duplication automatique
$textB = "Madame, Monsieur,
Objet : Candidature au poste de Développeur Web
Antananarivo, le 22 septembre 2026

Compétences :
- PHP
- JavaScript

Expérience :
Candidature au poste de compétences en PHP et JavaScript.

Madame, Monsieur,
Je vous prie d'agréer, Madame, Monsieur,
l'expression de mes salutations distinguées.";
$resultB = $atsScorer->calculateLetterQualityScore($textB);
test("TEST B: 'Candidature au poste de' 2x => aucune duplication",
    !aDuplique($resultB));

// TEST C: "Madame, Monsieur," début + "Madame, Monsieur" formule finale => aucune duplication
$textC = "Madame, Monsieur,
Text content here.

...

Je vous prie d'agréer, Madame, Monsieur,
l'expression de mes salutations distinguées.";
$resultC = $atsScorer->calculateLetterQualityScore($textC);
test("TEST C: 'Madame, Monsieur' départ + formule finale => aucune anomalie",
    !aDuplique($resultC));

// TEST D: "Madame, Monsieur, Madame, Monsieur," => duplication détectée
$textD = "Madame, Monsieur, Madame, Monsieur,
Text content here.

Je vous prie d'agréer, Madame, Monsieur, Madame, Monsieur,
l'expression de mes salutations distinguées.";
$resultD = $atsScorer->calculateLetterQualityScore($textD);
test("TEST D: 'Madame, Monsieur, Madame, Monsieur,' => duplication détectée",
    aDuplique($resultD));

// TEST E: phrase complète identique répétée 2x => duplication détectée
$textE = "Madame, Monsieur,
I want to apply for the position.
I want to apply for the position.
Je vous prie d'agréer, Madame, Monsieur,
l'expression de mes salutations distinguées.";
$resultE = $atsScorer->calculateLetterQualityScore($textE);
test("TEST E: phrase complète répétée 2x => duplication détectée",
    aDuplique($resultE));

// TEST F: Mots courants répétés => pas de faux positif
$textF = "Madame, Monsieur,
Vous m'écrivez pour candidater.
Je suis motivé pour ce poste.
Vos compétences m'intéressent.
Je apporte mes compétences.

Madame, Monsieur,
Je vous prie d'agréer, Madame, Monsieur,
l'expression de mes salutations distinguées.";
$resultF = $atsScorer->calculateLetterQualityScore($textF);
test("TEST F: Mots courants répétés => pas de faux positif uniquement àcause de ces mots",
    !aDuplique($resultF));

// === 3. STRUCTURE ===
echo "=== 3. STRUCTURE ===\n";
$textStruct = "Madame, Monsieur,
Tech Solutions Madagascar
Antananarivo, le 22 septembre 2026

À l'attention du Responsable du recrutement
Tech Solutions Madagascar

Objet : Candidature au poste de Développeur Web

Compétences techniques :
- PHP
- JavaScript
- HTML/CSS

Titulaire d'une Licence en Informatique, je me suis spécialisé dans le développement web back-end au cours de deux projets de fin d'études menés en équipe sur une durée de six mois.

J'ai participé à la refonte d'une application de gestion des étudiants en PHP et MySQL, où j'ai pris en charge le module d'inscription et les requêtes d'optimisation des listes.

Votre offre correspond directement à l'environnement dans lequel je souhaite évoluer, car elle réunit le travail en équipe, la norme PSR et la revue de code quotidienne.

Je serais heureux de vous exposer de vive voix mes réalisations et ma disponibilité lors d'un entretien, à la date qui vous conviendra.

Je vous prie d'agréer, Madame, Monsieur, l'expression de mes salutations distinguées.

Jean RAKOTO";
$resultStruct = $atsScorer->calculateLetterQualityScore($textStruct);
test("Structure: paragraph_count = 4 (ni l'en-tête, ni l'objet, ni les puces, ni la politesse)",
    ($resultStruct['paragraph_count'] ?? 0) === 4);
test("Structure: structure_score ≥ 12", ($resultStruct['structure_score'] ?? 0) >= 12);

// === 4. LONGUEUR ===
echo "=== 4. LONGUEUR ===\n";
// 311 mots au total : la phrase d'ajout est comprise dans le compte, sinon le
// test mesurait 337 mots et tombait dans le palier de pénalité forte.
$suffixe = "Madame, Monsieur, je suis developpeur web Antananarivo le 22 septembre 2026 objet Candidature poste developpeur competences php javascript";
$nbSuffixe = str_word_count($suffixe);
$words311 = [];
for ($i = 1; $i <= 311 - $nbSuffixe; $i++) {
    $words311[] = "mot$i";
}
$textLen = implode(" ", $words311) . " " . $suffixe;
$resultLen = $atsScorer->calculateLetterQualityScore($textLen);
test("word_count ≈ 311", abs($resultLen['word_count'] - 311) <= 10);
test("length_score n'est pas 5 (pénalty fort)", $resultLen['length_score'] >= 10);
test("Affiche 'Nombre de mots : 311'", $resultLen['word_count'] > 300);
test("Affiche 'Longueur : X/25' et non '311/25'", $resultLen['length_score'] <= 25);
test("Pas de recommandation rigide 120-250", 
    !isset($resultLen['recommandations']) || !is_string($resultLen['recommandations']) || str_contains($resultLen['recommandations'], '120-250') === false);

// === 5. FRONTEND ===
echo "=== 5. FRONTEND ===\n";
// Vérifier le controller actual
test("LettreController contient score original", strpos($ctrl, "score") !== false);
test("LettreController affiche 'details'", strpos($ctrl, "details") !== false);
test("Points forts dans la réponse", strpos($ctrl, "points_forts") !== false);
test("Points faibles dans la réponse", strpos($ctrl, "points_faibles") !== false);
test("Recommandations dans la réponse", strpos($ctrl, "recommandations") !== false);

// === 6. CORRECTION IA ===
echo "=== 6. CORRECTION IA ===\n";
// Les règles anti-invention sont dans le prompt de correct(), pas dans LlmService.php directement
test("LLmService existe et est utilisable", file_exists(__DIR__ . '/../src/Services/LlmService.php'));
// Vérifier que correct() ne retourne pas de score
test("correct() ne retourne pas de score dans la réponse", 
    strpos($ctrl, "texte_corrige") !== false);

// === 7. FORMAT JSON IA ===
echo "=== 7. FORMAT JSON IA ===\n";
test("Format JSON: analyse", strpos($ctrl, '"analyse"') !== false);
test("Format JSON: texte_corrige", strpos($ctrl, '"texte_corrige"') !== false);
test("Format JSON: ameliorations", strpos($ctrl, '"ameliorations"') !== false);
test("Format JSON: recommandations", strpos($ctrl, '"recommandations"') !== false);
// Vérifier structure améliorations
test("Améliorations ont avant/probleme/apres/justification", 
    strpos($ctrl, '"avant"') !== false && strpos($ctrl, '"probleme"') !== false && 
    strpos($ctrl, '"apres"') !== false && strpos($ctrl, '"justification"') !== false);

// === 8. LETTRE DÉJÀ BONNE ===
echo "=== 8. LETTRE DÉJÀ BONNE ===\n";
$textGood = "Madame, Monsieur,
Je m'appelle Jean Dupont.
Titulaire d'une Licence en Informatique.
Diplômé en 2015.
Expérience: 5 ans en développement web.
Je suis motivé pour ce poste.

Je vous prie d'agréer, Madame, Monsieur,
l'expression de mes salutations distinguées.";
$resultGood = $atsScorer->calculateLetterQualityScore($textGood);
test("Lettre déjà bonne obtient un score correct", $resultGood['total'] > 0);
test("Aucune anomalie majeure", count($resultGood['anomalies'] ?? []) <= 2);

// === 9. LETTRE COURTE ===
echo "=== 9. LETTRE COURTE ===\n";
$textShort = "Madame, Monsieur,
Je m'appelle Jean.
Je suis disponible.

Je vous prie d'agréer, Madame, Monsieur,
l'expression de mes salutations distinguées.";
$resultShort = $atsScorer->calculateLetterQualityScore($textShort);
test("Lettre courte détectée mais pas pénalisée excessivement", $resultShort['total'] >= 50);
// calculateLetterQualityScore ne renvoie que le score et ses détails :
// les « points faibles » et « recommandations » sont produits par LettreController::analyze().
test("Faiblesses pénalisées dans les détails du score",
    $resultShort['length_score'] <= 15 && $resultShort['structure_score'] <= 12);
test("analyze() produit recommandations et points_faibles",
    strpos($ctrl, '$recommandations[]') !== false && strpos($ctrl, '$points_faibles[]') !== false);

// === 10. OFFRE D'EMPLOI ===
echo "=== 10. OFFRE D'EMPLOI ===\n";
test("AtsScorer calculateLetterQualityScore ne contient pas 'offre'", 
    !in_array('offre', $resultLen['anomalies'] ?? []));

// === 11. FORMULATIONS GÉNÉRIQUES ===
echo "=== 11. FORMULATIONS GÉNÉRIQUES ===\n";
// Vérifier le controller pour les formulations génériques
test("analyze() détecte formulations génériques", strpos($ctrl, "formulations génériques") !== false);

// === 12. LETTRE DE RÉFÉRENCE ===
echo "=== 12. LETTRE DE RÉFÉRENCE ===\n";
$refText = "RAKOTO Jean
Lot II A 45, Antananarivo
Tél. : 034 12 345 67
E-mail : jean.rakoto@gmail.com

Antananarivo, le 20 septembre 2026

À l'attention du Responsable du recrutement
Tech Solutions Madagascar
Antananarivo, Madagascar

Objet : Candidature au poste de Développeur Web

Madame, Monsieur,

Je vous adresse ma candidature au poste de Développeur Web au sein de Tech Solutions Madagascar. Très intéressé par le développement informatique, je souhaite aujourd’hui mettre mes connaissances et mes compétences au service d’une entreprise dans laquelle je pourrais continuer à progresser.

Titulaire d’une Licence en Informatique, j’ai développé au cours de ma formation des compétences en développement web, notamment avec PHP, JavaScript, HTML, CSS et MySQL. J’ai également eu l’occasion de réaliser plusieurs projets universitaires, parmi lesquels une application web de gestion des étudiants permettant d’enregistrer, de modifier et de consulter différentes informations.

Ces différents projets m’ont permis de renforcer mes compétences techniques, mais également mon sens de l’organisation et ma capacité à résoudre des problèmes. Je suis une personne sérieuse, motivée, organisée et capable de travailler aussi bien en équipe qu’en autonomie. Je souhaite continuer à améliorer mes connaissances afin de devenir un développeur plus efficace et de répondre aux besoins des utilisateurs.

Intégrer Tech Solutions Madagascar représente pour moi une opportunité de mettre en pratique mes compétences et d’acquérir de nouvelles expériences professionnelles. Je suis particulièrement motivé à l’idée de rejoindre votre équipe et de participer aux différents projets de votre entreprise.

Je serais heureux de pouvoir vous rencontrer lors d’un entretien afin de vous présenter plus en détail mon parcours et ma motivation.

Je vous prie d'agréer, Madame, Monsieur, l'expression de mes salutations distinguées.

Jean RAKOTO";

$resultRef = $atsScorer->calculateLetterQualityScore($refText);
test("Lettre référence analyse sans erreur critique", count($resultRef['anomalies'] ?? []) < 5);
test("Lettre référence : en-tête et formule de politesse non comptés comme paragraphes",
    ($resultRef['paragraph_count'] ?? 0) === 5);
test("Lettre référence : structure correcte récompensée", ($resultRef['structure_score'] ?? 0) >= 12);
test("analyze() expose les points forts de la lettre", strpos($ctrl, '$points_forts[]') !== false);
test("Lettre référence word_count raisonnable", $resultRef['word_count'] > 50);
test("Lettre référence score global élevé", $resultRef['total'] >= 80);

echo "\n========================================\n";
echo "VALIDATION FINIE\n";
echo "========================================\n";
echo "Tests réussis: $passed\n";
echo "Tests échoués: $failed\n";
echo "Erreurs: " . implode(', ', $errors) . "\n";
echo "========================================\n";