<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Services\AtsScorer;
$ats = new AtsScorer();

$ref = "RAKOTO Jean\nLot II A 45, Antananarivo\nTél. : 034 12 345 67\n\nAntananarivo, le 20 septembre 2026\n\nÀ l'attention du Responsable du recrutement\nTech Solutions Madagascar\n\nObjet : Candidature au poste de Développeur Web\n\nMadame, Monsieur,\n\n"
 . str_repeat("Je vous adresse ma candidature au poste de Développeur Web avec de vraies phrases construites et des compétences en PHP et JavaScript. ", 12)
 . "\n\nDeuxième paragraphe du corps de la lettre avec des arguments concrets sur mes projets.\n\nTroisième paragraphe pour la motivation et la disponibilité.\n\nJe vous prie d'agréer, Madame, Monsieur, l'expression de mes salutations distinguées.\n\nJean RAKOTO";

$short = "Madame, Monsieur,\nJe m'appelle Jean.\nJe suis disponible.\n\nJe vous prie d'agréer, Madame, Monsieur, l'expression de mes salutations distinguées.";

foreach (['REFERENCE' => $ref, 'COURTE' => $short] as $label => $t) {
    $r = $ats->calculateLetterQualityScore($t);
    echo "=== $label ===\n";
    echo "clés: " . implode(', ', array_keys($r)) . "\n";
    foreach ($r as $k => $v) {
        if (is_array($v)) {
            echo "$k (" . count($v) . "): " . json_encode(array_slice($v, 0, 4), JSON_UNESCAPED_UNICODE) . "\n";
        } else {
            echo "$k: " . var_export($v, true) . "\n";
        }
    }
    echo "\n";
}
