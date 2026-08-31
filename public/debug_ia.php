<?php
declare(strict_types=1);
/**
 * Diagnóstico: muestra la respuesta CRUDA de la IA para un partido de prueba,
 * y si el parseo extrae bien los scores y el verdict.
 *   GET /debug_ia.php
 * BORRAR este archivo tras diagnosticar.
 */
require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/ReasoningScorer.php';
use SignalPitch\ReasoningScorer;

header('Content-Type: text/plain; charset=utf-8');
$cfg = require __DIR__ . '/../config/config.php';
set_time_limit(120);

// contexto de prueba: un favorito goleador en casa (tipo Aston Villa-Arsenal)
$ctx = [
    'league'=>'Premier League','kickoff'=>'21:00','stage'=>null,
    'h2h'=>'Goles esperados combinados: 2.8',
    'home'=>['name'=>'Aston Villa','form'=>null,'gf_avg'=>1.2,'ga_avg'=>1.8,'btts_pct'=>50,'over25_pct'=>null,'injuries'=>null],
    'away'=>['name'=>'Arsenal','form'=>null,'gf_avg'=>2.2,'ga_avg'=>0.75,'btts_pct'=>40,'over25_pct'=>null,'injuries'=>null],
];

$reasoner = new ReasoningScorer($cfg['claude']);
try {
    $r = $reasoner->analyze($ctx, true, true);  // useTop=true (sonnet), web=true
    echo "=== RESULTADO PARSEADO ===\n";
    foreach (['BTTS','OVER','UNDER'] as $mk) {
        $sc = $r[$mk]['score'] ?? 'NO HAY SCORE';
        $vd = $r[$mk]['verdict'] ?? 'NO HAY VERDICT';
        echo "$mk: score=$sc\n   verdict: $vd\n";
    }
    echo "\nmodel: ".($r['model'] ?? '?')."\n";
    echo "\n=== ¿Se parseó bien? ===\n";
    echo isset($r['OVER']['score']) && $r['OVER']['score']>0
        ? "SÍ: la IA devolvió scores parseables.\n"
        : "NO: el parseo falló, no hay scores. Aquí está el problema.\n";
} catch (Throwable $e) {
    echo "ERROR: ".$e->getMessage()."\n";
}
