<?php
declare(strict_types=1);

/**
 * LIQUIDACIÓN DE RESULTADOS · Signal Pitch
 * -----------------------------------------------------------------
 * Ejecutar de noche, tras los partidos. P.ej.:
 *   30 1 * * *  php /app/cron/settle.php >> /var/log/signalpitch.log 2>&1
 *
 * Flujo:
 *   1. Busca fixtures de ayer/hoy ya finalizados (status FT) sin liquidar.
 *   2. Trae el marcador final (1 request por fixture, o lote por fecha).
 *   3. Calcula si cada mercado (BTTS/OVER/UNDER) se cumplió -> fixture_results.
 *   4. Liquida cada prediction: outcome win/loss y profit (a stake 1).
 *
 * El % de acierto y el ROI por estrategia salen luego de las vistas
 * v_strategy_performance y v_strategy_breakdown, sin más cálculo.
 */

require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/ApiFootball.php';

use SignalPitch\Db;
use SignalPitch\ApiFootball;

$cfg = require __DIR__ . '/../config/config.php';
$pdo = Db::conn($cfg['db']);
$api = new ApiFootball($pdo, $cfg['apifootball']);

function logln(string $m): void { echo '[' . gmdate('H:i:s') . "] settle · $m\n"; }

// mercados: dado un marcador, ¿se cumplió?
function marketHit(string $market, int $h, int $a): bool {
    $total = $h + $a;
    return match ($market) {
        'BTTS'  => $h > 0 && $a > 0,
        'OVER'  => $total >= 3,   // Over 2.5
        'UNDER' => $total <= 2,   // Under 2.5
        'HOME'  => $h > $a,       // gana el local
        'AWAY'  => $a > $h,       // gana el visitante
        'DC_1X' => $h >= $a,      // local o empate
        'DC_X2' => $a >= $h,      // visitante o empate
        default => false,
    };
}

// fixtures con predicciones pendientes y que ya deberían haber acabado
$rows = $pdo->query(
    "SELECT DISTINCT f.id, f.kickoff_utc, f.status
     FROM fixtures f
     JOIN predictions p ON p.fixture_id = f.id AND p.outcome = 'pending'
     WHERE f.kickoff_utc < (UTC_TIMESTAMP() - INTERVAL 150 MINUTE)
     ORDER BY f.kickoff_utc ASC
     LIMIT 40"
)->fetchAll();

if (!$rows) { logln('nada que liquidar'); exit; }
logln(count($rows) . ' fixtures por liquidar');

$settled = 0;

foreach ($rows as $fx) {
    $fixtureId = (int)$fx['id'];
    try {
        $api->pace();
        $resp = $api->get('/fixtures', ['id' => $fixtureId]);
    } catch (Throwable $e) {
        logln("fixture $fixtureId: {$e->getMessage()}");
        if (str_contains($e->getMessage(), 'agotado')) { logln('corto por cuota'); break; }
        continue;
    }
    $item = $resp[0] ?? null;
    if (!$item) continue;

    $status = $item['fixture']['status']['short'] ?? 'NS';
    if (!in_array($status, ['FT','AET','PEN'], true)) {
        // aún no acabó de verdad; lo dejamos pendiente
        continue;
    }
    $hg = (int)($item['goals']['home'] ?? 0);
    $ag = (int)($item['goals']['away'] ?? 0);

    // actualiza el fixture
    $pdo->prepare("UPDATE fixtures SET status='FT', home_goals=:h, away_goals=:a WHERE id=:id")
        ->execute([':h'=>$hg, ':a'=>$ag, ':id'=>$fixtureId]);

    // resultado por mercado (compartido)
    foreach (['BTTS','OVER','UNDER'] as $mk) {
        $hit = marketHit($mk, $hg, $ag) ? 1 : 0;
        $pdo->prepare(
            "INSERT INTO fixture_results (fixture_id, market, hit, home_goals, away_goals)
             VALUES (:f,:m,:hit,:h,:a)
             ON DUPLICATE KEY UPDATE hit=VALUES(hit), home_goals=VALUES(home_goals), away_goals=VALUES(away_goals)"
        )->execute([':f'=>$fixtureId, ':m'=>$mk, ':hit'=>$hit, ':h'=>$hg, ':a'=>$ag]);
    }

    // liquida cada predicción de este fixture (todas las estrategias)
    $preds = $pdo->prepare(
        "SELECT p.id, p.market, p.odds, p.picked
         FROM predictions p WHERE p.fixture_id=:f AND p.outcome='pending'"
    );
    $preds->execute([':f'=>$fixtureId]);

    foreach ($preds->fetchAll() as $p) {
        $hit = marketHit($p['market'], $hg, $ag);
        $outcome = $hit ? 'win' : 'loss';
        // profit a stake 1: win => (odds-1); loss => -1. Sin cuota guardada, usamos 1.90 por defecto.
        $odds = $p['odds'] !== null ? (float)$p['odds'] : 1.90;
        $profit = $hit ? round($odds - 1, 2) : -1.0;

        $pdo->prepare(
            "UPDATE predictions
             SET outcome=:o, profit=:pf, settled_at=UTC_TIMESTAMP()
             WHERE id=:id"
        )->execute([':o'=>$outcome, ':pf'=>$profit, ':id'=>(int)$p['id']]);
        $settled++;
    }
    // liquida también las SEÑALES del dashboard (tabla signals)
    $sigs = $pdo->prepare(
        "SELECT id, market, odds FROM signals WHERE fixture_id=:f AND outcome='pending'"
    );
    $sigs->execute([':f'=>$fixtureId]);
    foreach ($sigs->fetchAll() as $s) {
        $hit = marketHit($s['market'], $hg, $ag);
        $odds = $s['odds'] !== null ? (float)$s['odds'] : 1.90;
        $profit = $hit ? round($odds - 1, 2) : -1.0;
        $pdo->prepare(
            "UPDATE signals SET outcome=:o, profit=:pf, settled_at=UTC_TIMESTAMP() WHERE id=:id"
        )->execute([':o'=>$hit?'win':'loss', ':pf'=>$profit, ':id'=>(int)$s['id']]);
    }

    logln("fixture $fixtureId: {$hg}-{$ag} liquidado");
}

logln("=== liquidadas $settled predicciones ===");
