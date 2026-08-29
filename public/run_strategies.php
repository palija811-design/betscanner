<?php
declare(strict_types=1);

/**
 * Botón del laboratorio: lanza el StrategyRunner sobre los partidos de hoy.
 *   GET /run_strategies.php
 *
 * Hace que TODAS las estrategias activas puntúen los partidos del día y
 * guarden su predicción en 'predictions'. Es lo que alimenta el laboratorio.
 * Luego, cuando settle.php liquide esos partidos, el ranking de acierto/ROI
 * dejará de estar vacío.
 *
 * Uso manual desde el panel del laboratorio; también lo ejecuta el cron.
 */

require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/ApiFootball.php';
require __DIR__ . '/../src/RecentForm.php';
require __DIR__ . '/../src/Scorer.php';
require __DIR__ . '/../src/ClaudeScorer.php';
require __DIR__ . '/../src/ReasoningScorer.php';
require __DIR__ . '/../src/StrategyRunner.php';

use SignalPitch\Db;
use SignalPitch\ApiFootball;
use SignalPitch\RecentForm;
use SignalPitch\ClaudeScorer;
use SignalPitch\ReasoningScorer;
use SignalPitch\StrategyRunner;

header('Content-Type: application/json; charset=utf-8');
$cfg = require __DIR__ . '/../config/config.php';

// puede tardar: subimos el límite de tiempo
set_time_limit(300);

try {
    $pdo = Db::conn($cfg['db']);
    $api = new ApiFootball($pdo, $cfg['apifootball']);
    $form = new RecentForm($api, $cfg['apifootball']['season'], 10);
    $runner = new StrategyRunner($pdo, new ClaudeScorer($cfg['claude']), new ReasoningScorer($cfg['claude']));
    $season = $cfg['apifootball']['season'];
    $today = gmdate('Y-m-d');

    $leagues = $pdo->query("SELECT id,name,priority FROM leagues WHERE is_active=1 ORDER BY priority ASC")->fetchAll();
    $partidos = 0; $estrategias = (int)$pdo->query("SELECT COUNT(*) FROM strategies WHERE is_active=1")->fetchColumn();

    foreach ($leagues as $lg) {
        $leagueId=(int)$lg['id']; $useTop=((int)$lg['priority'])===1;
        try { $api->pace(); $fixtures=$api->get('/fixtures',['league'=>$leagueId,'season'=>$season,'date'=>$today]); }
        catch (Throwable $e) { if(str_contains($e->getMessage(),'agotado')) break; continue; }

        foreach ($fixtures as $fx) {
            $fixtureId=(int)$fx['fixture']['id'];
            $h=$fx['teams']['home']; $a=$fx['teams']['away'];
            // asegura equipos y fixture en BD
            $pdo->prepare("INSERT INTO teams (id,name,logo_url) VALUES (:i,:n,:l) ON DUPLICATE KEY UPDATE name=VALUES(name)")
                ->execute([':i'=>(int)$h['id'],':n'=>$h['name'],':l'=>$h['logo']??null]);
            $pdo->prepare("INSERT INTO teams (id,name,logo_url) VALUES (:i,:n,:l) ON DUPLICATE KEY UPDATE name=VALUES(name)")
                ->execute([':i'=>(int)$a['id'],':n'=>$a['name'],':l'=>$a['logo']??null]);

            try { $api->pace(); $hs=$form->forTeam((int)$h['id']); $api->pace(); $as=$form->forTeam((int)$a['id']); }
            catch (Throwable $e) { if(str_contains($e->getMessage(),'agotado')) break 2; continue; }
            if ($hs['played']==0 && $as['played']==0) continue;

            $expGoals=($hs['gf_avg']+$hs['ga_avg']+$as['gf_avg']+$as['ga_avg'])/2;
            $played=(int)(min($hs['played'],$as['played'])?:max($hs['played'],$as['played']));

            $pdo->prepare("INSERT INTO fixtures (id,league_id,season,kickoff_utc,status,home_id,away_id,h2h_goals_avg)
                VALUES (:id,:lg,:s,:ko,:st,:h,:a,:h2h)
                ON DUPLICATE KEY UPDATE h2h_goals_avg=VALUES(h2h_goals_avg)")
                ->execute([':id'=>$fixtureId,':lg'=>$leagueId,':s'=>$season,
                    ':ko'=>gmdate('Y-m-d H:i:s',strtotime($fx['fixture']['date'])),
                    ':st'=>$fx['fixture']['status']['short']??'NS',
                    ':h'=>(int)$h['id'],':a'=>(int)$a['id'],':h2h'=>$expGoals]);

            $stats=['home'=>$hs,'away'=>$as,'h2h'=>$expGoals,'played'=>$played];
            $ctx=['league'=>$lg['name'],'kickoff'=>gmdate('H:i',strtotime($fx['fixture']['date'])),
                'stage'=>$fx['league']['round']??null,'h2h'=>"Goles esperados: ".round($expGoals,2),
                'home'=>['name'=>$h['name'],'form'=>null,'gf_avg'=>$hs['gf_avg'],'ga_avg'=>$hs['ga_avg'],'btts_pct'=>$hs['btts_pct'],'over25_pct'=>null,'injuries'=>null],
                'away'=>['name'=>$a['name'],'form'=>null,'gf_avg'=>$as['gf_avg'],'ga_avg'=>$as['ga_avg'],'btts_pct'=>$as['btts_pct'],'over25_pct'=>null,'injuries'=>null]];

            $runner->runAll($fixtureId, $stats, $ctx, $useTop);
            $partidos++;
        }
    }

    echo json_encode(['ok'=>true,'partidos'=>$partidos,'estrategias'=>$estrategias,
        'msg'=>"$estrategias estrategias puntuaron $partidos partidos. Cuando se jueguen y settle.php los liquide, el ranking se llenara.",
        'req_hoy'=>$api->spentToday()], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>substr($e->getMessage(),0,150)]);
}
