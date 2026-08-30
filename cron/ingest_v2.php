<?php
declare(strict_types=1);

/**
 * INGESTA DIARIA v2 (multi-estrategia) · Signal Pitch
 * -----------------------------------------------------------------
 *   15 8 * * *  php /app/cron/ingest_v2.php >> /var/log/signalpitch.log 2>&1
 *
 * Igual que ingest.php pero, en vez de un único scoring, ejecuta TODAS las
 * estrategias activas sobre cada partido (StrategyRunner) y guarda una
 * predicción por (estrategia, fixture, mercado) en 'predictions'.
 *
 * También construye el CONTEXTO RICO que necesita el motor "reasoning":
 * forma, medias, %BTTS/Over, bajas (si el plan da injuries), stage y H2H.
 * Ese contexto es la misma clase de información que se lee en una previa.
 */

require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/ApiFootball.php';
require __DIR__ . '/../src/Scorer.php';
require __DIR__ . '/../src/ClaudeScorer.php';
require __DIR__ . '/../src/ReasoningScorer.php';
require __DIR__ . '/../src/ReasoningScorerBasic.php';
require __DIR__ . '/../src/StrategyRunner.php';

use SignalPitch\Db;
use SignalPitch\ApiFootball;
use SignalPitch\ClaudeScorer;
use SignalPitch\ReasoningScorer;
use SignalPitch\ReasoningScorerBasic;
use SignalPitch\StrategyRunner;

$cfg = require __DIR__ . '/../config/config.php';
$pdo = Db::conn($cfg['db']);
$api = new ApiFootball($pdo, $cfg['apifootball']);
$runner = new StrategyRunner(
    $pdo,
    new ClaudeScorer($cfg['claude']),
    new ReasoningScorer($cfg['claude']),
    new ReasoningScorerBasic($cfg['claude'])
);
$season = $cfg['apifootball']['season'];
$today  = gmdate('Y-m-d');
$statsTtl = $cfg['apifootball']['stats_ttl_h'];

function logln(string $m): void { echo '[' . gmdate('H:i:s') . "] ingest2 · $m\n"; }
logln("=== $today · gastadas hoy: {$api->spentToday()} req ===");

$leagues = $pdo->query(
    "SELECT id, name, priority FROM leagues WHERE is_active=1 ORDER BY priority ASC"
)->fetchAll();

function freshStats(PDO $pdo, int $teamId, int $leagueId, int $season, int $ttlH): ?array {
    $st = $pdo->prepare(
        "SELECT * FROM team_stats WHERE team_id=:t AND league_id=:l AND season=:s
           AND updated_at > (UTC_TIMESTAMP() - INTERVAL :ttl HOUR)"
    );
    $st->execute([':t'=>$teamId, ':l'=>$leagueId, ':s'=>$season, ':ttl'=>$ttlH]);
    return $st->fetch() ?: null;
}
function saveStats(PDO $pdo, int $teamId, int $leagueId, int $season, array $r): array {
    $gf=$r['goals']['for']['average']['total']??0; $ga=$r['goals']['against']['average']['total']??0;
    $played=$r['fixtures']['played']['total']??0;
    $cs=$r['clean_sheet']['total']??0; $fts=$r['failed_to_score']['total']??0;
    $csPct=$played?(int)round($cs/$played*100):0; $ftsPct=$played?(int)round($fts/$played*100):0;
    $bttsPct=max(0,100-$csPct-$ftsPct);
    // Over 2.5 aprox desde los buckets de goles si vienen; si no, deriva de medias
    $over=$r['goals']['for']['average']['total']??0;
    $over25=(int)round(min(100,max(0,(($gf+$ga)-2.0)*35+40)));
    $form=substr((string)($r['form']??''),-5);
    $row=['played'=>$played,'gf_avg'=>(float)$gf,'ga_avg'=>(float)$ga,'btts_pct'=>$bttsPct,
          'over25_pct'=>$over25,'clean_sheet_pct'=>$csPct,'failed_to_score_pct'=>$ftsPct,'form'=>$form];
    $pdo->prepare(
        "INSERT INTO team_stats (team_id,league_id,season,played,gf_avg,ga_avg,btts_pct,over25_pct,
            clean_sheet_pct,failed_to_score_pct,form,raw_json)
         VALUES (:t,:l,:s,:p,:gf,:ga,:b,:o,:cs,:fts,:form,:raw)
         ON DUPLICATE KEY UPDATE played=VALUES(played),gf_avg=VALUES(gf_avg),ga_avg=VALUES(ga_avg),
            btts_pct=VALUES(btts_pct),over25_pct=VALUES(over25_pct),clean_sheet_pct=VALUES(clean_sheet_pct),
            failed_to_score_pct=VALUES(failed_to_score_pct),form=VALUES(form),raw_json=VALUES(raw_json)"
    )->execute([':t'=>$teamId,':l'=>$leagueId,':s'=>$season,':p'=>$played,':gf'=>$gf,':ga'=>$ga,
        ':b'=>$bttsPct,':o'=>$over25,':cs'=>$csPct,':fts'=>$ftsPct,':form'=>$form,
        ':raw'=>json_encode($r,JSON_UNESCAPED_UNICODE)]);
    return $row;
}
function upsertTeam(PDO $pdo, int $id, string $name, ?string $logo): void {
    $pdo->prepare("INSERT INTO teams (id,name,logo_url) VALUES (:i,:n,:l)
        ON DUPLICATE KEY UPDATE name=VALUES(name), logo_url=VALUES(logo_url)")
        ->execute([':i'=>$id,':n'=>$name,':l'=>$logo]);
}

foreach ($leagues as $lg) {
    $leagueId=(int)$lg['id']; $useTop=((int)$lg['priority'])===1;
    try { $api->pace(); $fixtures=$api->get('/fixtures',['league'=>$leagueId,'season'=>$season,'date'=>$today]); }
    catch (Throwable $e) {
        logln("{$lg['name']}: {$e->getMessage()}");
        if (str_contains($e->getMessage(),'agotado')) { logln('corto por cuota'); break; }
        continue;
    }
    if (!$fixtures) { logln("{$lg['name']}: 0 partidos"); continue; }
    logln("{$lg['name']}: ".count($fixtures).' partidos');

    foreach ($fixtures as $fx) {
        $fixtureId=(int)$fx['fixture']['id'];
        $home=$fx['teams']['home']; $away=$fx['teams']['away'];
        upsertTeam($pdo,(int)$home['id'],$home['name'],$home['logo']??null);
        upsertTeam($pdo,(int)$away['id'],$away['name'],$away['logo']??null);
        $pdo->prepare("INSERT INTO fixtures (id,league_id,season,kickoff_utc,status,home_id,away_id)
            VALUES (:id,:lg,:s,:ko,:st,:h,:a)
            ON DUPLICATE KEY UPDATE status=VALUES(status),kickoff_utc=VALUES(kickoff_utc)")
            ->execute([':id'=>$fixtureId,':lg'=>$leagueId,':s'=>$season,
                ':ko'=>gmdate('Y-m-d H:i:s',strtotime($fx['fixture']['date'])),
                ':st'=>$fx['fixture']['status']['short']??'NS',
                ':h'=>(int)$home['id'],':a'=>(int)$away['id']]);

        $hs=freshStats($pdo,(int)$home['id'],$leagueId,$season,$statsTtl);
        if(!$hs){ try{ $api->pace(); $r=$api->get('/teams/statistics',['league'=>$leagueId,'season'=>$season,'team'=>(int)$home['id']]); $hs=saveStats($pdo,(int)$home['id'],$leagueId,$season,$r);}catch(Throwable $e){logln("  stats home fail");continue;} }
        $as=freshStats($pdo,(int)$away['id'],$leagueId,$season,$statsTtl);
        if(!$as){ try{ $api->pace(); $r=$api->get('/teams/statistics',['league'=>$leagueId,'season'=>$season,'team'=>(int)$away['id']]); $as=saveStats($pdo,(int)$away['id'],$leagueId,$season,$r);}catch(Throwable $e){logln("  stats away fail");continue;} }

        $h2h=(($hs['gf_avg']+$hs['ga_avg']+$as['gf_avg']+$as['ga_avg'])/2);
        $played=(int)min($hs['played']??0,$as['played']??0);

        // contexto rico para el motor reasoning (la "previa")
        $ctx = [
            'league'=>$lg['name'],
            'kickoff'=>gmdate('H:i',strtotime($fx['fixture']['date'])),
            'stage'=>$fx['league']['round']??null,
            'h2h'=>"Media aproximada de goles combinada reciente: ".round($h2h,2),
            'home'=>['name'=>$home['name'],'form'=>$hs['form']??null,'gf_avg'=>$hs['gf_avg'],
                     'ga_avg'=>$hs['ga_avg'],'btts_pct'=>$hs['btts_pct'],'over25_pct'=>$hs['over25_pct']??null,'injuries'=>null],
            'away'=>['name'=>$away['name'],'form'=>$as['form']??null,'gf_avg'=>$as['gf_avg'],
                     'ga_avg'=>$as['ga_avg'],'btts_pct'=>$as['btts_pct'],'over25_pct'=>$as['over25_pct']??null,'injuries'=>null],
        ];
        $stats = ['home'=>$hs,'away'=>$as,'h2h'=>$h2h,'played'=>$played];

        // ¡todas las estrategias puntúan este mismo partido!
        $runner->runAll($fixtureId, $stats, $ctx, $useTop);
    }
}
logln("=== fin ingesta multi-estrategia · req hoy: {$api->spentToday()} ===");
