<?php
declare(strict_types=1);

/**
 * EVOLUCIÓN DE ESTRATEGIAS · Signal Pitch
 * -----------------------------------------------------------------
 * Ejecutar semanal o quincenalmente. P.ej.:
 *   0 3 * * 1  php /app/cron/evolve.php >> /var/log/signalpitch.log 2>&1
 *
 * Idea (algoritmo evolutivo simple):
 *   1. Lee el rendimiento de cada estrategia (acierto + ROI) de las vistas.
 *   2. Solo actúa si hay muestra suficiente (mín. de picks resueltos).
 *   3. Pasa ese rendimiento + las configs actuales a Claude y le pide que
 *      proponga N nuevas variaciones (mutaciones de las mejores), explicando
 *      la hipótesis de cada una.
 *   4. Inserta las nuevas estrategias como hijas (parent_id) para competir.
 *   5. Opcional: "poda" las peores (las desactiva) si llevan mucho perdiendo.
 *   6. Reasigna el campeón (is_champion) a la mejor con muestra suficiente.
 *
 * NO se toca la baseline ni se borra nada: las malas se desactivan, no se
 * eliminan, para conservar el histórico y el linaje.
 */

require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/ReasoningScorer.php'; // reutilizamos su cliente HTTP vía una función local

use SignalPitch\Db;

$cfg = require __DIR__ . '/../config/config.php';
$pdo = Db::conn($cfg['db']);

function logln(string $m): void { echo '[' . gmdate('H:i:s') . "] evolve · $m\n"; }

// ---- parámetros del laboratorio ----
const MIN_PICKS_TO_JUDGE   = 40;   // no juzgamos una estrategia con menos de N picks resueltos
const MIN_PICKS_TO_CHAMPION= 60;   // para ser campeón hace falta más muestra aún
const MAX_ACTIVE_STRATS    = 12;   // techo de estrategias activas en paralelo
const NEW_PER_CYCLE        = 3;    // cuántas variaciones nuevas pide por ciclo
const PRUNE_ROI_THRESHOLD  = -8.0; // ROI% por debajo del cual se poda (con muestra)

// 1) rendimiento actual
$perf = $pdo->query("SELECT * FROM v_strategy_performance ORDER BY roi_pct DESC")->fetchAll();
if (!$perf) { logln('sin rendimiento aún, nada que evolucionar'); exit; }

$judgeable = array_filter($perf, fn($p) => (int)$p['picks_settled'] >= MIN_PICKS_TO_JUDGE);
logln(count($perf) . ' estrategias, ' . count($judgeable) . ' con muestra suficiente');

// 2) reasignar campeón a la mejor por ROI con muestra alta (desempate por acierto)
$champCandidates = array_filter($perf, fn($p) => (int)$p['picks_settled'] >= MIN_PICKS_TO_CHAMPION);
if ($champCandidates) {
    usort($champCandidates, function($a,$b){
        if ($a['roi_pct'] == $b['roi_pct']) return $b['hit_rate'] <=> $a['hit_rate'];
        return $b['roi_pct'] <=> $a['roi_pct'];
    });
    $newChamp = (int)$champCandidates[0]['strategy_id'];
    $pdo->exec("UPDATE strategies SET is_champion=0");
    $pdo->prepare("UPDATE strategies SET is_champion=1 WHERE id=:id")->execute([':id'=>$newChamp]);
    logln("campeón => estrategia $newChamp (ROI {$champCandidates[0]['roi_pct']}%, acierto {$champCandidates[0]['hit_rate']}%)");
}

// 3) poda: desactiva las claramente malas con muestra suficiente (nunca la baseline ni la champion)
foreach ($judgeable as $p) {
    if ((float)$p['roi_pct'] < PRUNE_ROI_THRESHOLD && !$p['is_champion'] && $p['code'] !== 'baseline') {
        $pdo->prepare("UPDATE strategies SET is_active=0 WHERE id=:id")->execute([':id'=>(int)$p['strategy_id']]);
        logln("podada estrategia {$p['strategy_id']} ({$p['code']}) ROI {$p['roi_pct']}%");
    }
}

// ¿hay hueco para nuevas?
$activeCount = (int)$pdo->query("SELECT COUNT(*) FROM strategies WHERE is_active=1")->fetchColumn();
$room = MAX_ACTIVE_STRATS - $activeCount;
if ($room <= 0) { logln('sin hueco para nuevas estrategias'); exit; }

// 4) breakdown por mercado/tramo de las mejores, como material para la IA
$breakdown = $pdo->query(
    "SELECT b.*, s.code FROM v_strategy_breakdown b
     JOIN strategies s ON s.id=b.strategy_id
     WHERE b.n >= 10 ORDER BY b.roi_pct DESC LIMIT 40"
)->fetchAll();

// configs actuales de las estrategias con mejor ROI (semillas para mutar)
$topIds = array_slice(array_map(fn($p)=>(int)$p['strategy_id'], $judgeable ?: $perf), 0, 4);
$seeds = [];
if ($topIds) {
    $in = implode(',', array_fill(0, count($topIds), '?'));
    $st = $pdo->prepare("SELECT id, code, config_json FROM strategies WHERE id IN ($in)");
    $st->execute($topIds);
    $seeds = $st->fetchAll();
}

// 5) pedir a Claude nuevas variaciones
$newStrats = proposeStrategies($cfg['claude'], $perf, $breakdown, $seeds, min(NEW_PER_CYCLE, $room));
if (!$newStrats) { logln('la IA no propuso variaciones válidas'); exit; }

$inserted = 0;
foreach ($newStrats as $ns) {
    // validación mínima de la config propuesta
    if (empty($ns['code']) || empty($ns['config']) || !isset($ns['config']['engine'])) continue;
    $code = 'ai_' . preg_replace('/[^a-z0-9_]/','', strtolower($ns['code'])) . '_' . substr((string)time(),-4);
    try {
        $pdo->prepare(
            "INSERT INTO strategies (code,name,description,config_json,is_active,origin,parent_id)
             VALUES (:c,:n,:d,:cfg,1,'ai',:pid)"
        )->execute([
            ':c'=>$code,
            ':n'=>substr($ns['name'] ?? $code, 0, 120),
            ':d'=>substr($ns['hypothesis'] ?? '', 0, 400),
            ':cfg'=>json_encode($ns['config'], JSON_UNESCAPED_UNICODE),
            ':pid'=>$ns['parent_id'] ?? null,
        ]);
        $inserted++;
        logln("nueva estrategia '$code': " . substr($ns['hypothesis'] ?? '', 0, 80));
    } catch (Throwable $e) {
        logln("no se pudo insertar {$code}: {$e->getMessage()}");
    }
}
logln("=== ciclo evolutivo: $inserted nuevas estrategias a competir ===");


/**
 * Llama a Claude con el rendimiento y le pide variaciones nuevas en JSON.
 */
function proposeStrategies(array $claudeCfg, array $perf, array $breakdown, array $seeds, int $howMany): array
{
    $system = <<<SYS
Eres un investigador de modelos de predicción deportiva. Se te da el rendimiento
(acierto % y ROI %) de varias estrategias de scoring de partidos, un desglose por
mercado y tramo de score, y las configuraciones (config_json) de las mejores.

Tu tarea: proponer {$howMany} NUEVAS variaciones que puedan mejorar el ROI. Cada
variación es una mutación razonada de una estrategia existente: ajusta pesos,
el grado de peso de la IA (ai_weight), el margen (ai_margin), el umbral (min_score),
o el motor (stat/hybrid/reasoning). Cambios acotados y justificados por los datos,
no aleatorios.

Reglas de la config (deben respetarse):
- engine: "stat" | "hybrid" | "reasoning"
- ai_weight: 0.0..1.0 ; ai_margin: 5..25 ; min_score: 45..75
- weights por mercado (BTTS/OVER/UNDER) con las MISMAS claves de factores que la
  estrategia madre. Los pesos de cada mercado deben sumar ~1.0.
- use_web: true|false

Cada propuesta incluye una hipótesis clara de POR QUÉ debería mejorar, basada en
el rendimiento observado (p.ej. "la baseline pierde ROI en OVER con score 60-69;
subo el umbral de OVER y doy más peso a goalsAvg").

Responde SOLO con JSON válido:
{"strategies":[
  {"code":"<slug corto>","name":"<nombre>","parent_id":<int|null>,
   "hypothesis":"<por qué mejora>",
   "config":{...config completa...}}
]}
SYS;

    $payload = [
        'rendimiento' => array_map(fn($p)=>[
            'strategy_id'=>(int)$p['strategy_id'],'code'=>$p['code'],
            'picks'=>(int)$p['picks_settled'],'hit_rate'=>(float)$p['hit_rate'],
            'roi_pct'=>(float)$p['roi_pct'],
        ], $perf),
        'desglose' => array_map(fn($b)=>[
            'code'=>$b['code'],'market'=>$b['market'],'band'=>$b['score_band'],
            'n'=>(int)$b['n'],'hit_rate'=>(float)$b['hit_rate'],'roi_pct'=>(float)$b['roi_pct'],
        ], $breakdown),
        'semillas' => array_map(fn($s)=>[
            'parent_id'=>(int)$s['id'],'code'=>$s['code'],
            'config'=>json_decode($s['config_json'], true),
        ], $seeds),
    ];

    $body = [
        'model'      => $claudeCfg['model_top'],   // usamos el mejor modelo para investigar
        'max_tokens' => 1500,
        'system'     => $system,
        'messages'   => [[ 'role'=>'user', 'content'=>json_encode($payload, JSON_UNESCAPED_UNICODE) ]],
    ];

    $ch = curl_init($claudeCfg['base']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>60,
        CURLOPT_POSTFIELDS=>json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER=>[
            'Content-Type: application/json',
            'x-api-key: ' . $claudeCfg['key'],
            'anthropic-version: ' . $claudeCfg['version'],
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code !== 200) return [];

    $json = json_decode($resp, true);
    $text = '';
    foreach (($json['content'] ?? []) as $blk) {
        if (($blk['type'] ?? '')==='text') $text .= $blk['text'];
    }
    $text = preg_replace('/^```(?:json)?|```$/m','', trim($text)) ?? $text;
    $s = strpos($text,'{'); $e = strrpos($text,'}');
    if ($s===false || $e===false) return [];
    $out = json_decode(substr($text,$s,$e-$s+1), true);
    return $out['strategies'] ?? [];
}
