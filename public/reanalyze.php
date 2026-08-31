<?php
declare(strict_types=1);

/**
 * Botón "Buscar y analizar partidos que faltan".
 *   GET /refresh.php
 *
 * Analiza SOLO los partidos de hoy de las ligas activas que aún no tienen
 * señal en la base de datos. No repite los ya analizados (salvo que sean
 * inminentes: <3h para el inicio, donde puede haber novedades de alineación,
 * en cuyo caso re-hace la investigación).
 *
 * Devuelve un resumen JSON. Pensado para uso manual desde el dashboard.
 */

require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/ApiFootball.php';
require __DIR__ . '/../src/RecentForm.php';
require __DIR__ . '/../src/BlendWeight.php';
require __DIR__ . '/../src/Scorer.php';
require __DIR__ . '/../src/ReasoningScorer.php';
require __DIR__ . '/../src/PolymarketLink.php';
require __DIR__ . '/../src/OddsFetcher.php';

use SignalPitch\Db;
use SignalPitch\ApiFootball;
use SignalPitch\RecentForm;
use SignalPitch\BlendWeight;
use SignalPitch\Scorer;
use SignalPitch\ReasoningScorer;
use SignalPitch\PolymarketLink;
use SignalPitch\OddsFetcher;

header('Content-Type: application/json; charset=utf-8');
set_time_limit(300);   // más margen antes de que el servidor corte
$cfg = require __DIR__ . '/../config/config.php';

const UMBRAL_INVESTIGACION = 60;
const UMBRAL_GUARDADO = 50;
const HORAS_INMINENTE = 3;   // re-investiga si el partido empieza en <3h
const MAX_POR_TANDA = 25;    // reanaliza más partidos por pulsación (recalcula todo)

try {
    $pdo = Db::conn($cfg['db']);
    $api = new ApiFootball($pdo, $cfg['apifootball']);
    $form = new RecentForm($api, $cfg['apifootball']['season'], 10);
    $reasoner = new ReasoningScorer($cfg['claude']);
    $oddsFetcher = new OddsFetcher($api);
    $season = $cfg['apifootball']['season'];
    $today = gmdate('Y-m-d');

    $leagues = $pdo->query("SELECT id,name,priority FROM leagues WHERE is_active=1 ORDER BY priority ASC")->fetchAll();

    $nuevos = 0; $reinvestigados = 0; $saltados = 0;
    $procesadosNuevos = 0;   // cuenta partidos nuevos analizados en esta tanda

    foreach ($leagues as $lg) {
        if ($procesadosNuevos >= MAX_POR_TANDA) break;   // corta la tanda al llegar al límite
        $leagueId=(int)$lg['id']; $useTop=((int)$lg['priority'])===1;
        try { $api->pace(); $fixtures=$api->get('/fixtures',['league'=>$leagueId,'season'=>$season,'date'=>$today]); }
        catch (Throwable $e) { if(str_contains($e->getMessage(),'agotado')) break; continue; }

        foreach ($fixtures as $fx) {
            if ($procesadosNuevos >= MAX_POR_TANDA) break;
            $fixtureId=(int)$fx['fixture']['id'];
            $koTs = strtotime($fx['fixture']['date']);
            $horasParaKO = ($koTs - time())/3600;

            // ¿ya tiene señales?
            $st=$pdo->prepare("SELECT COUNT(*) FROM signals WHERE fixture_id=:f");
            $st->execute([':f'=>$fixtureId]);
            $yaAnalizado = ((int)$st->fetchColumn()) > 0;

            // reanalyze: NO saltamos ninguno, recalculamos todos los pendientes de hoy
            $esReinvestigacion = $yaAnalizado;

            $h=$fx['teams']['home']; $a=$fx['teams']['away'];
            $pdo->prepare("INSERT INTO teams (id,name,logo_url) VALUES (:i,:n,:l)
                ON DUPLICATE KEY UPDATE name=VALUES(name)")->execute([':i'=>(int)$h['id'],':n'=>$h['name'],':l'=>$h['logo']??null]);
            $pdo->prepare("INSERT INTO teams (id,name,logo_url) VALUES (:i,:n,:l)
                ON DUPLICATE KEY UPDATE name=VALUES(name)")->execute([':i'=>(int)$a['id'],':n'=>$a['name'],':l'=>$a['logo']??null]);

            try {
                $api->pace(); $hs=$form->forTeam((int)$h['id']);
                $api->pace(); $as=$form->forTeam((int)$a['id']);
            } catch (Throwable $e) { if(str_contains($e->getMessage(),'agotado')) break 2; continue; }
            if ($hs['played']==0 && $as['played']==0) continue;

            $expGoals=($hs['gf_avg']+$hs['ga_avg']+$as['gf_avg']+$as['ga_avg'])/2;
            $played=(int)(min($hs['played'],$as['played'])?:max($hs['played'],$as['played']));
            $analysis=Scorer::analyze($hs,$as,$expGoals,$played);
            $poly=PolymarketLink::forMatch($h['name'],$a['name'],$leagueId,$today);

            $pdo->prepare("INSERT INTO fixtures (id,league_id,season,kickoff_utc,status,home_id,away_id,h2h_goals_avg,polymarket_url)
                VALUES (:id,:lg,:s,:ko,:st,:h,:a,:h2h,:poly)
                ON DUPLICATE KEY UPDATE polymarket_url=VALUES(polymarket_url)")
                ->execute([':id'=>$fixtureId,':lg'=>$leagueId,':s'=>$season,
                    ':ko'=>gmdate('Y-m-d H:i:s',$koTs),':st'=>$fx['fixture']['status']['short']??'NS',
                    ':h'=>(int)$h['id'],':a'=>(int)$a['id'],':h2h'=>$expGoals,':poly'=>$poly]);

            $anyStrong=false; foreach($analysis['markets'] as $sc){ if($sc>=UMBRAL_INVESTIGACION){$anyStrong=true;break;} }
            $rAll=null;
            if ($anyStrong) {
                try {
                    $ctx=['league'=>$lg['name'],'kickoff'=>gmdate('H:i',$koTs),'stage'=>$fx['league']['round']??null,
                        'h2h'=>"Goles esperados combinados: ".round($expGoals,2),
                        'home'=>['name'=>$h['name'],'form'=>null,'gf_avg'=>$hs['gf_avg'],'ga_avg'=>$hs['ga_avg'],'btts_pct'=>$hs['btts_pct'],'over25_pct'=>null,'injuries'=>null],
                        'away'=>['name'=>$a['name'],'form'=>null,'gf_avg'=>$as['gf_avg'],'ga_avg'=>$as['ga_avg'],'btts_pct'=>$as['btts_pct'],'over25_pct'=>null,'injuries'=>null]];
                    $rAll=$reasoner->analyze($ctx,$useTop,true);
                } catch (Throwable $e) {}
            }

            $odds=[]; try { $api->pace(); $odds=$oddsFetcher->forFixture($fixtureId); } catch(Throwable $e){}

            foreach (['BTTS','OVER','UNDER'] as $mk) {
                $statScore=$analysis['markets'][$mk];
                if ($statScore<UMBRAL_GUARDADO) continue;
                $finalScore=$statScore; $rv=null;$radj=null;$model='stat';
                if ($rAll!==null && isset($rAll[$mk]['score'])) {
                    $aiScore=(int)$rAll[$mk]['score'];
                    $finalScore=BlendWeight::blend($statScore,$aiScore,$played);
                    $rv=$rAll[$mk]['verdict']??''; $radj=$finalScore-$statScore;
                    $model=$useTop?'sonnet+web':'haiku+web';
                }
                $mktOdds=$odds[$mk]??null;
                $pdo->prepare("INSERT INTO signals
                      (fixture_id,market,stat_score,ai_score,final_score,confidence,factors_json,research_verdict,research_adjustment,model_used,odds,odds_source)
                    VALUES (:fx,:mk,:ss,:as,:fs,:cf,:fj,:rv,:radj,:md,:od,:osrc)
                    ON DUPLICATE KEY UPDATE final_score=VALUES(final_score),confidence=VALUES(confidence),
                      research_verdict=VALUES(research_verdict),research_adjustment=VALUES(research_adjustment),
                      odds=VALUES(odds),odds_source=VALUES(odds_source),computed_at=CURRENT_TIMESTAMP")
                    ->execute([':fx'=>$fixtureId,':mk'=>$mk,':ss'=>$statScore,':as'=>$finalScore,':fs'=>$finalScore,
                        ':cf'=>Scorer::confidence($finalScore),':fj'=>json_encode($analysis['factors'],JSON_UNESCAPED_UNICODE),
                        ':rv'=>$rv,':radj'=>$radj,':md'=>$model,':od'=>$mktOdds,':osrc'=>$mktOdds?'apifootball':null]);
            }
            if ($esReinvestigacion) { $reinvestigados++; } else { $nuevos++; }
            $procesadosNuevos++;   // en reanalyze contamos todos para respetar el límite
        }
    }

    $tandaLlena = ($procesadosNuevos >= MAX_POR_TANDA);
    $totalReanalizados = $nuevos + $reinvestigados;
    echo json_encode(['ok'=>true,'nuevos'=>$nuevos,'reinvestigados'=>$reinvestigados,
        'ya_estaban'=>$saltados,'req_hoy'=>$api->spentToday(),
        'tanda_llena'=>$tandaLlena,
        'msg'=> $tandaLlena
            ? "Reanalizados $totalReanalizados partidos (límite por tanda). Pulsa otra vez para seguir con los que falten."
            : "Reanalizados $totalReanalizados partidos de hoy con la configuración actual."
        ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>substr($e->getMessage(),0,150)]);
}
