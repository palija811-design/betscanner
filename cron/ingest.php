<?php
declare(strict_types=1);

/**
 * INGESTA DIARIA · Signal Pitch
 * -----------------------------------------------------------------
 * Ejecutar por cron una vez por la mañana, p.ej.:
 *   15 8 * * *  php /app/cron/ingest.php >> /var/log/signalpitch.log 2>&1
 *
 * Flujo:
 *   1. Trae los fixtures de hoy de las ligas activas (1 request/liga).
 *   2. Para cada equipo sin stats frescas, trae statistics (cache 20h).
 *   3. Calcula el score estadístico de los 3 mercados.
 *   4. Pide a Claude el ajuste + veredicto (Sonnet en ligas top, Haiku resto).
 *   5. Persiste en signals (upsert).
 *
 * Todo el gasto de API-Football pasa por el presupuesto diario de ApiFootball,
 * así que si te quedas sin cuota, corta limpio sin romper nada.
 */

require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/ApiFootball.php';
require __DIR__ . '/../src/Scorer.php';
require __DIR__ . '/../src/ClaudeScorer.php';

use SignalPitch\Db;
use SignalPitch\ApiFootball;
use SignalPitch\Scorer;
use SignalPitch\ClaudeScorer;

$cfg = require __DIR__ . '/../config/config.php';
$pdo = Db::conn($cfg['db']);
$api = new ApiFootball($pdo, $cfg['apifootball']);
$ai  = new ClaudeScorer($cfg['claude']);
$season = $cfg['apifootball']['season'];
$today  = gmdate('Y-m-d');

function logln(string $m): void { echo '[' . gmdate('H:i:s') . "] $m\n"; }

logln("=== Ingesta $today · gastadas hoy: {$api->spentToday()} req ===");

// ---- ligas activas ordenadas por prioridad ----
$leagues = $pdo->query(
    "SELECT id, name, priority FROM leagues WHERE is_active=1 ORDER BY priority ASC"
)->fetchAll();

$statsTtl = $cfg['apifootball']['stats_ttl_h'];

/** ¿Tenemos stats frescas de este equipo/liga? */
function freshStats(PDO $pdo, int $teamId, int $leagueId, int $season, int $ttlH): ?array {
    $st = $pdo->prepare(
        "SELECT * FROM team_stats
         WHERE team_id=:t AND league_id=:l AND season=:s
           AND updated_at > (UTC_TIMESTAMP() - INTERVAL :ttl HOUR)"
    );
    $st->execute([':t'=>$teamId, ':l'=>$leagueId, ':s'=>$season, ':ttl'=>$ttlH]);
    return $st->fetch() ?: null;
}

/** Persiste stats de equipo desde la respuesta de API-Football. */
function saveStats(PDO $pdo, int $teamId, int $leagueId, int $season, array $r): array {
    $goalsFor = $r['goals']['for']['average']['total']      ?? 0;
    $goalsAg  = $r['goals']['against']['average']['total']   ?? 0;
    $played   = $r['fixtures']['played']['total']            ?? 0;
    // API-Football no da btts directo: lo derivamos de clean sheets y failed-to-score
    $csTotal  = $r['clean_sheet']['total']                   ?? 0;
    $ftsTotal = $r['failed_to_score']['total']               ?? 0;
    $csPct    = $played ? (int)round($csTotal  / $played * 100) : 0;
    $ftsPct   = $played ? (int)round($ftsTotal / $played * 100) : 0;
    // BTTS aprox: partidos en los que ni dejó porteria a cero ni se quedó sin marcar
    $bttsPct  = max(0, 100 - $csPct - $ftsPct);
    $form     = substr((string)($r['form'] ?? ''), -5);

    $row = [
        'played'=>$played, 'gf_avg'=>(float)$goalsFor, 'ga_avg'=>(float)$goalsAg,
        'btts_pct'=>$bttsPct, 'clean_sheet_pct'=>$csPct, 'failed_to_score_pct'=>$ftsPct,
        'form'=>$form,
    ];
    $st = $pdo->prepare(
        "INSERT INTO team_stats
           (team_id, league_id, season, played, gf_avg, ga_avg, btts_pct,
            clean_sheet_pct, failed_to_score_pct, form, raw_json)
         VALUES (:t,:l,:s,:p,:gf,:ga,:b,:cs,:fts,:form,:raw)
         ON DUPLICATE KEY UPDATE
            played=VALUES(played), gf_avg=VALUES(gf_avg), ga_avg=VALUES(ga_avg),
            btts_pct=VALUES(btts_pct), clean_sheet_pct=VALUES(clean_sheet_pct),
            failed_to_score_pct=VALUES(failed_to_score_pct), form=VALUES(form),
            raw_json=VALUES(raw_json)"
    );
    $st->execute([
        ':t'=>$teamId, ':l'=>$leagueId, ':s'=>$season, ':p'=>$played,
        ':gf'=>$goalsFor, ':ga'=>$goalsAg, ':b'=>$bttsPct, ':cs'=>$csPct,
        ':fts'=>$ftsPct, ':form'=>$form, ':raw'=>json_encode($r, JSON_UNESCAPED_UNICODE),
    ]);
    return $row;
}

function upsertTeam(PDO $pdo, int $id, string $name, ?string $logo): void {
    $pdo->prepare(
        "INSERT INTO teams (id,name,logo_url) VALUES (:i,:n,:l)
         ON DUPLICATE KEY UPDATE name=VALUES(name), logo_url=VALUES(logo_url)"
    )->execute([':i'=>$id, ':n'=>$name, ':l'=>$logo]);
}

$totalSignals = 0;

foreach ($leagues as $lg) {
    $leagueId = (int)$lg['id'];
    $useTop   = ((int)$lg['priority']) === 1;

    try {
        $api->pace();
        $fixtures = $api->get('/fixtures', [
            'league' => $leagueId, 'season' => $season, 'date' => $today,
        ]);
    } catch (Throwable $e) {
        logln("Liga {$lg['name']}: sin fixtures ({$e->getMessage()})");
        // si es agotamiento de cuota, paramos del todo
        if (str_contains($e->getMessage(), 'agotado')) { logln('Corto por cuota.'); break; }
        continue;
    }

    if (!$fixtures) { logln("Liga {$lg['name']}: 0 partidos hoy"); continue; }
    logln("Liga {$lg['name']}: " . count($fixtures) . ' partidos');

    foreach ($fixtures as $fx) {
        $fixtureId = (int)$fx['fixture']['id'];
        $home = $fx['teams']['home']; $away = $fx['teams']['away'];
        upsertTeam($pdo, (int)$home['id'], $home['name'], $home['logo'] ?? null);
        upsertTeam($pdo, (int)$away['id'], $away['name'], $away['logo'] ?? null);

        // guarda el fixture
        $pdo->prepare(
            "INSERT INTO fixtures (id,league_id,season,kickoff_utc,status,home_id,away_id)
             VALUES (:id,:lg,:s,:ko,:st,:h,:a)
             ON DUPLICATE KEY UPDATE status=VALUES(status), kickoff_utc=VALUES(kickoff_utc)"
        )->execute([
            ':id'=>$fixtureId, ':lg'=>$leagueId, ':s'=>$season,
            ':ko'=>gmdate('Y-m-d H:i:s', strtotime($fx['fixture']['date'])),
            ':st'=>$fx['fixture']['status']['short'] ?? 'NS',
            ':h'=>(int)$home['id'], ':a'=>(int)$away['id'],
        ]);

        // stats de cada equipo (cache)
        $hs = freshStats($pdo, (int)$home['id'], $leagueId, $season, $statsTtl);
        if (!$hs) {
            try {
                $api->pace();
                $r = $api->get('/teams/statistics', ['league'=>$leagueId,'season'=>$season,'team'=>(int)$home['id']]);
                $hs = saveStats($pdo, (int)$home['id'], $leagueId, $season, $r);
            } catch (Throwable $e) { logln("  stats home fail: {$e->getMessage()}"); continue; }
        }
        $as = freshStats($pdo, (int)$away['id'], $leagueId, $season, $statsTtl);
        if (!$as) {
            try {
                $api->pace();
                $r = $api->get('/teams/statistics', ['league'=>$leagueId,'season'=>$season,'team'=>(int)$away['id']]);
                $as = saveStats($pdo, (int)$away['id'], $leagueId, $season, $r);
            } catch (Throwable $e) { logln("  stats away fail: {$e->getMessage()}"); continue; }
        }

        // H2H: si no lo tenemos, usamos media simple de las medias (evita gastar request);
        // opcionalmente se puede pedir /fixtures/headtohead cuando sobre cuota.
        $h2h = isset($fx['h2h_goals_avg']) ? (float)$fx['h2h_goals_avg'] : 0.0;
        if (!$h2h) {
            $h2h = (($hs['gf_avg']+$hs['ga_avg']+$as['gf_avg']+$as['ga_avg'])/2);
        }

        $played = (int)min($hs['played'] ?? 0, $as['played'] ?? 0);
        $analysis = Scorer::analyze($hs, $as, $h2h, $played);

        // scoring IA por mercado (solo los que superan un piso, para ahorrar tokens)
        foreach ($analysis['markets'] as $market => $statScore) {
            if ($statScore < 45) { continue; } // no gastamos IA en señales flojas
            try {
                $res = $ai->evaluate(
                    $analysis['factors'], $market, $statScore,
                    ['home'=>$home['name'],'away'=>$away['name'],'league'=>$lg['name']],
                    $useTop
                );
            } catch (Throwable $e) {
                logln("  IA fail ($market): {$e->getMessage()}");
                $res = ['ai_score'=>$statScore,'verdict'=>'','risk'=>'','model'=>$useTop?'sonnet':'haiku'];
            }

            $final = $res['ai_score'];
            $conf  = Scorer::confidence($final);

            $pdo->prepare(
                "INSERT INTO signals
                   (fixture_id, market, stat_score, ai_score, final_score, confidence,
                    factors_json, ai_verdict, ai_risk, model_used)
                 VALUES (:fx,:mk,:ss,:as,:fs,:cf,:fj,:vd,:rk,:md)
                 ON DUPLICATE KEY UPDATE
                    stat_score=VALUES(stat_score), ai_score=VALUES(ai_score),
                    final_score=VALUES(final_score), confidence=VALUES(confidence),
                    factors_json=VALUES(factors_json), ai_verdict=VALUES(ai_verdict),
                    ai_risk=VALUES(ai_risk), model_used=VALUES(model_used),
                    computed_at=CURRENT_TIMESTAMP"
            )->execute([
                ':fx'=>$fixtureId, ':mk'=>$market, ':ss'=>$statScore, ':as'=>$res['ai_score'],
                ':fs'=>$final, ':cf'=>$conf,
                ':fj'=>json_encode($analysis['factors'], JSON_UNESCAPED_UNICODE),
                ':vd'=>$res['verdict'], ':rk'=>$res['risk'], ':md'=>$res['model'],
            ]);
            $totalSignals++;
        }
    }
}

logln("=== Fin. Señales generadas/actualizadas: $totalSignals · req totales hoy: {$api->spentToday()} ===");
