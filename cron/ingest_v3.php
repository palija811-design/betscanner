<?php
declare(strict_types=1);

/**
 * INGESTA v3 · Signal Pitch — flujo de DOS CAPAS
 * -----------------------------------------------------------------
 *   15 8 * * *  php /app/cron/ingest_v3.php >> /var/log/signalpitch.log 2>&1
 *
 * Flujo completo validado a mano:
 *   1. Trae los partidos del día de las ligas activas.
 *   2. CAPA 1 (estadística): medias sobre últimos 10 partidos REALES de cada
 *      equipo (RecentForm) + motor recalibrado (Scorer v2 discriminante).
 *   3. Solo para los partidos con score >= UMBRAL_INVESTIGACION, aplica
 *      CAPA 2 (investigación): ReasoningScorer con web search, que confirma o
 *      corrige el score estadístico (como la corrección de Yverdon-Wil).
 *   4. Genera el enlace de Polymarket del partido.
 *   5. Persiste en fixtures + signals.
 *
 * La capa 2 es cara (web search por partido), por eso SOLO se aplica a los
 * candidatos fuertes, no a los 35. Eficiente y con sentido.
 */

require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/ApiFootball.php';
require __DIR__ . '/../src/RecentForm.php';
require __DIR__ . '/../src/Scorer.php';
require __DIR__ . '/../src/ReasoningScorer.php';
require __DIR__ . '/../src/PolymarketLink.php';

use SignalPitch\Db;
use SignalPitch\ApiFootball;
use SignalPitch\RecentForm;
use SignalPitch\Scorer;
use SignalPitch\ReasoningScorer;
use SignalPitch\PolymarketLink;

$cfg = require __DIR__ . '/../config/config.php';
$pdo = Db::conn($cfg['db']);
$api = new ApiFootball($pdo, $cfg['apifootball']);
$form = new RecentForm($api, $cfg['apifootball']['season'], 10);
$reasoner = new ReasoningScorer($cfg['claude']);
$season = $cfg['apifootball']['season'];
$today  = gmdate('Y-m-d');

const UMBRAL_INVESTIGACION = 65;   // solo investigamos partidos con score >= esto

function logln(string $m): void { echo '[' . gmdate('H:i:s') . "] v3 · $m\n"; }
logln("=== $today · gastadas hoy: {$api->spentToday()} req ===");

$leagues = $pdo->query(
    "SELECT id, name, priority FROM leagues WHERE is_active=1 ORDER BY priority ASC"
)->fetchAll();

function upsertTeam(PDO $pdo, int $id, string $name, ?string $logo): void {
    $pdo->prepare("INSERT INTO teams (id,name,logo_url) VALUES (:i,:n,:l)
        ON DUPLICATE KEY UPDATE name=VALUES(name), logo_url=VALUES(logo_url)")
        ->execute([':i'=>$id,':n'=>$name,':l'=>$logo]);
}

$total = 0;
foreach ($leagues as $lg) {
    $leagueId = (int)$lg['id']; $useTop = ((int)$lg['priority']) === 1;
    try {
        $api->pace();
        $fixtures = $api->get('/fixtures', ['league'=>$leagueId,'season'=>$season,'date'=>$today]);
    } catch (Throwable $e) {
        logln("{$lg['name']}: {$e->getMessage()}");
        if (str_contains($e->getMessage(),'agotado')) { logln('corto por cuota'); break; }
        continue;
    }
    if (!$fixtures) { logln("{$lg['name']}: 0 partidos"); continue; }
    logln("{$lg['name']}: ".count($fixtures).' partidos');

    foreach ($fixtures as $fx) {
        $fixtureId = (int)$fx['fixture']['id'];
        $h = $fx['teams']['home']; $a = $fx['teams']['away'];
        upsertTeam($pdo, (int)$h['id'], $h['name'], $h['logo'] ?? null);
        upsertTeam($pdo, (int)$a['id'], $a['name'], $a['logo'] ?? null);

        // CAPA 1: forma reciente real + motor recalibrado
        try {
            $api->pace(); $hs = $form->forTeam((int)$h['id']);
            $api->pace(); $as = $form->forTeam((int)$a['id']);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(),'agotado')) { logln('corto por cuota'); break 2; }
            logln("  forma fail {$h['name']}-{$a['name']}"); continue;
        }
        if ($hs['played']==0 && $as['played']==0) continue;

        $expGoals = ($hs['gf_avg']+$hs['ga_avg']+$as['gf_avg']+$as['ga_avg'])/2;
        $played = (int)(min($hs['played'],$as['played']) ?: max($hs['played'],$as['played']));
        $analysis = Scorer::analyze($hs, $as, $expGoals, $played);
        $best = $analysis['best'];

        // enlace Polymarket (con la fecha para el evento correcto)
        $poly = PolymarketLink::forMatch($h['name'], $a['name'], $leagueId, $today);

        // guarda fixture
        $pdo->prepare("INSERT INTO fixtures (id,league_id,season,kickoff_utc,status,home_id,away_id,h2h_goals_avg,polymarket_url)
            VALUES (:id,:lg,:s,:ko,:st,:h,:a,:h2h,:poly)
            ON DUPLICATE KEY UPDATE status=VALUES(status), h2h_goals_avg=VALUES(h2h_goals_avg), polymarket_url=VALUES(polymarket_url)")
            ->execute([':id'=>$fixtureId,':lg'=>$leagueId,':s'=>$season,
                ':ko'=>gmdate('Y-m-d H:i:s',strtotime($fx['fixture']['date'])),
                ':st'=>$fx['fixture']['status']['short']??'NS',
                ':h'=>(int)$h['id'],':a'=>(int)$a['id'],':h2h'=>$expGoals,':poly'=>$poly]);

        // CAPA 2: investigación web SOLO si el mejor mercado supera el umbral
        $research = ['verdict'=>null,'adjustment'=>null];
        $finalScore = $best['score'];
        if ($best['score'] >= UMBRAL_INVESTIGACION) {
            try {
                $ctx = [
                    'league'=>$lg['name'], 'kickoff'=>gmdate('H:i',strtotime($fx['fixture']['date'])),
                    'stage'=>$fx['league']['round']??null,
                    'h2h'=>"Goles esperados combinados: ".round($expGoals,2),
                    'home'=>['name'=>$h['name'],'form'=>null,'gf_avg'=>$hs['gf_avg'],'ga_avg'=>$hs['ga_avg'],'btts_pct'=>$hs['btts_pct'],'over25_pct'=>null,'injuries'=>null],
                    'away'=>['name'=>$a['name'],'form'=>null,'gf_avg'=>$as['gf_avg'],'ga_avg'=>$as['ga_avg'],'btts_pct'=>$as['btts_pct'],'over25_pct'=>null,'injuries'=>null],
                ];
                $r = $reasoner->analyze($ctx, $useTop, true);  // true = web search ON
                $mk = $best['market'];
                if (isset($r[$mk]['score'])) {
                    $aiScore = (int)$r[$mk]['score'];
                    // la investigación ajusta el score estadístico (media ponderada 60/40)
                    $finalScore = (int)round($best['score']*0.6 + $aiScore*0.4);
                    $research['verdict'] = $r[$mk]['verdict'] ?? '';
                    $research['adjustment'] = $finalScore - $best['score'];
                }
            } catch (Throwable $e) {
                logln("  investigacion fail {$h['name']}-{$a['name']}: ".substr($e->getMessage(),0,60));
            }
        }

        // persiste la señal del mejor mercado
        $conf = Scorer::confidence($finalScore);
        $pdo->prepare("INSERT INTO signals
              (fixture_id, market, stat_score, ai_score, final_score, confidence,
               factors_json, ai_verdict, ai_risk, research_verdict, research_adjustment, model_used)
            VALUES (:fx,:mk,:ss,:as,:fs,:cf,:fj,:vd,:rk,:rv,:radj,:md)
            ON DUPLICATE KEY UPDATE
              stat_score=VALUES(stat_score), final_score=VALUES(final_score),
              confidence=VALUES(confidence), factors_json=VALUES(factors_json),
              research_verdict=VALUES(research_verdict), research_adjustment=VALUES(research_adjustment),
              computed_at=CURRENT_TIMESTAMP")
            ->execute([
                ':fx'=>$fixtureId, ':mk'=>$best['market'], ':ss'=>$best['score'],
                ':as'=>$finalScore, ':fs'=>$finalScore, ':cf'=>$conf,
                ':fj'=>json_encode($analysis['factors'], JSON_UNESCAPED_UNICODE),
                ':vd'=>null, ':rk'=>null,
                ':rv'=>$research['verdict'], ':radj'=>$research['adjustment'],
                ':md'=>$best['score']>=UMBRAL_INVESTIGACION ? ($useTop?'sonnet+web':'haiku+web') : 'stat',
            ]);
        $total++;
    }
}
logln("=== fin v3 · señales: $total · req hoy: {$api->spentToday()} ===");
