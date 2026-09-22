<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Services\AtsScorer;
$ats = new AtsScorer();

$refText = file_get_contents(__DIR__ . '/fixtures_lettre_ref.txt');
$r = $ats->calculateLetterQualityScore($refText);
echo "Réelle lettre de référence:\n";
foreach ($r as $k => $v) {
    echo is_array($v) ? "$k (" . count($v) . "): " . json_encode($v, JSON_UNESCAPED_UNICODE) . "\n" : "$k: $v\n";
}
$blocks = preg_split('/\n\s*\n/', trim($refText));
echo "\nblocs (" . count($blocks) . "):\n";
foreach ($blocks as $i => $b) {
    echo "  [$i] " . str_replace("\n", ' | ', mb_substr(trim($b), 0, 70)) . "\n";
}
