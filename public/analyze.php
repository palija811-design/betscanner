<?php
declare(strict_types=1);

/**
 * Analizador de apuestas manual.
 *   GET /analyze.php?home=Lille&away=PSG&market=BTTS
 *   GET /analyze.php?fixture_id=123456&market=OVER
 *
 * Busca el partido en API-Football, calcula la forma reciente real de ambos
 * equipos, puntúa con el motor y aplica la capa de investigación IA. Devuelve
 * el análisis del mercado pedido (o de los tres si no se especifica).
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

header('Content-Type: application/json; charset=utf-8');
$cfg = require __DIR__ . '/../config/config.php';

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok'=>false, 'error'=>$msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Db::conn($cfg['db']);
    $api = new ApiFootball($pdo, $cfg['apifootball']);
    $form = new RecentForm($api, $cfg['apifootball']['season'], 10);
    $season = $cfg['apifootball']['season'];

    $market = strtoupper(trim($_GET['market'] ?? ''));
    $wantMarkets = in_array($market, ['BTTS','OVER','UNDER'], true) ? [$market] : ['BTTS','OVER','UNDER'];

    // --- localizar el partido ---
    $fixture = null;
    if (!empty($_GET['fixture_id'])) {
        $r = $api->get('/fixtures', ['id' => (int)$_GET['fixture_id']]);
        $fixture = $r[0] ?? null;
    } else {
        $home = trim($_GET['home'] ?? '');
        $away = trim($_GET['away'] ?? '');
        if ($home === '' || $away === '') fail('Indica los dos equipos (home y away) o un fixture_id.');
        // busca partidos próximos del equipo local y cruza con el visitante
        $today = gmdate('Y-m-d');
        $to = gmdate('Y-m-d', strtotime('+7 days'));
        // busca por nombre de equipo
        $teams = $api->get('/teams', ['search' => $home]);
        if (!$teams) fail("No encuentro el equipo local: $home");
        $homeId = (int)$teams[0]['team']['id'];
        $upcoming = $api->get('/fixtures', ['team'=>$homeId, 'season'=>$season, 'from'=>$today, 'to'=>$to]);
        foreach ($upcoming as $fx) {
            $an = strtolower($fx['teams']['away']['name']);
            $hn = strtolower($fx['teams']['home']['name']);
            if (str_contains($an, strtolower($away)) || str_contains(strtolower($away), $an)
                || str_contains($hn, strtolower($away))) {
                $fixture = $fx; break;
            }
        }
    }
    if (!$fixture) fail('No encontré ese partido en los próximos días.', 404);

    $h = $fixture['teams']['home']; $a = $fixture['teams']['away'];
    $leagueId = (int)$fixture['league']['id'];
    $matchDate = substr($fixture['fixture']['date'], 0, 10);

    // --- forma reciente real ---
    $hs = $form->forTeam((int)$h['id']);
    $as = $form->forTeam((int)$a['id']);
    if ($hs['played']==0 && $as['played']==0) fail('No hay datos de forma reciente para estos equipos.');

    $expGoals = ($hs['gf_avg']+$hs['ga_avg']+$as['gf_avg']+$as['ga_avg'])/2;
    $played = (int)(min($hs['played'],$as['played']) ?: max($hs['played'],$as['played']));
    $analysis = Scorer::analyze($hs, $as, $expGoals, $played);

    // --- investigación IA (siempre, porque el usuario lo pidió con profundidad completa) ---
    $useTop = in_array($leagueId, [2,3,39,140,135,78,61], true);
    $rAll = null;
    try {
        $reasoner = new ReasoningScorer($cfg['claude']);
        $ctx = [
            'league'=>$fixture['league']['name'], 'kickoff'=>substr($fixture['fixture']['date'],11,5),
            'stage'=>$fixture['league']['round']??null,
            'h2h'=>"Goles esperados combinados: ".round($expGoals,2),
            'home'=>['name'=>$h['name'],'form'=>null,'gf_avg'=>$hs['gf_avg'],'ga_avg'=>$hs['ga_avg'],'btts_pct'=>$hs['btts_pct'],'over25_pct'=>null,'injuries'=>null],
            'away'=>['name'=>$a['name'],'form'=>null,'gf_avg'=>$as['gf_avg'],'ga_avg'=>$as['ga_avg'],'btts_pct'=>$as['btts_pct'],'over25_pct'=>null,'injuries'=>null],
        ];
        $rAll = $reasoner->analyze($ctx, $useTop, true);
    } catch (Throwable $e) { /* seguimos con solo estadística */ }

    $poly = PolymarketLink::forMatch($h['name'], $a['name'], $leagueId, $matchDate);

    // --- construir la respuesta por mercado ---
    $out = [];
    foreach ($wantMarkets as $mk) {
        $statScore = $analysis['markets'][$mk];
        $finalScore = $statScore; $rv = null; $radj = null;
        if ($rAll !== null && isset($rAll[$mk]['score'])) {
            $aiScore = (int)$rAll[$mk]['score'];
            $finalScore = (int)round($statScore*0.6 + $aiScore*0.4);
            $rv = $rAll[$mk]['verdict'] ?? '';
            $radj = $finalScore - $statScore;
        }
        $out[] = [
            'market'=>$mk, 'stat_score'=>$statScore, 'final_score'=>$finalScore,
            'confidence'=>Scorer::confidence($finalScore),
            'research_verdict'=>$rv, 'research_adjustment'=>$radj,
        ];
    }
    // ordena por score final desc
    usort($out, fn($x,$y)=>$y['final_score']-$x['final_score']);

    echo json_encode([
        'ok'=>true,
        'match'=>[
            'home'=>$h['name'], 'away'=>$a['name'],
            'league'=>$fixture['league']['name'], 'country'=>$fixture['league']['country'],
            'kickoff'=>$fixture['fixture']['date'],
            'home_form'=>$hs, 'away_form'=>$as,
            'polymarket_url'=>$poly,
        ],
        'markets'=>$out,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    fail('Error analizando la apuesta: '.substr($e->getMessage(),0,120), 500);
}
