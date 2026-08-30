<?php
declare(strict_types=1);

/**
 * Historial detallado de apuestas liquidadas.
 *   GET /history.php?source=dashboard   (señales del dashboard)
 *   GET /history.php?source=lab&strategy=baseline  (predicciones de una estrategia)
 *
 * Devuelve la lista cronológica de cada señal/predicción YA liquidada, con el
 * partido, mercado, score, cuota y si se ganó o perdió. Es el "extracto" que
 * permite ver no solo "8 de 9" sino CUÁLES fueron esas apuestas.
 */

require __DIR__ . '/../src/Db.php';
use SignalPitch\Db;

header('Content-Type: application/json; charset=utf-8');
$cfg = require __DIR__ . '/../config/config.php';

$source = $_GET['source'] ?? 'dashboard';
$limit = min(200, max(10, (int)($_GET['limit'] ?? 100)));

try {
    $pdo = Db::conn($cfg['db']);
    $rows = [];

    if ($source === 'lab') {
        // predicciones de estrategias del laboratorio
        $where = "p.outcome IN ('win','loss') AND p.picked=1";
        $params = [];
        if (!empty($_GET['strategy'])) {
            $where .= " AND st.code = :code";
            $params[':code'] = $_GET['strategy'];
        }
        $sql = "SELECT p.market, p.score, p.odds, p.outcome, p.profit, p.settled_at,
                       st.name AS strategy, st.code AS strategy_code,
                       th.name AS home, ta.name AS away, l.name AS league,
                       f.kickoff_utc, fr.home_goals, fr.away_goals
                FROM predictions p
                JOIN strategies st ON st.id = p.strategy_id
                JOIN fixtures f ON f.id = p.fixture_id
                JOIN teams th ON th.id = f.home_id
                JOIN teams ta ON ta.id = f.away_id
                JOIN leagues l ON l.id = f.league_id
                LEFT JOIN fixture_results fr ON fr.fixture_id = f.id AND fr.market = p.market
                WHERE $where
                ORDER BY p.settled_at DESC
                LIMIT $limit";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } else {
        // señales del dashboard
        $sql = "SELECT s.market, s.final_score AS score, s.odds, s.outcome, s.profit, s.settled_at,
                       th.name AS home, ta.name AS away, l.name AS league,
                       f.kickoff_utc, fr.home_goals, fr.away_goals
                FROM signals s
                JOIN fixtures f ON f.id = s.fixture_id
                JOIN teams th ON th.id = f.home_id
                JOIN teams ta ON ta.id = f.away_id
                JOIN leagues l ON l.id = f.league_id
                LEFT JOIN fixture_results fr ON fr.fixture_id = f.id AND fr.market = s.market
                WHERE s.outcome IN ('win','loss')
                ORDER BY s.settled_at DESC
                LIMIT $limit";
        $rows = $pdo->query($sql)->fetchAll();
    }

    // resumen rápido
    $n = count($rows);
    $wins = 0; $profit = 0.0;
    foreach ($rows as $r) {
        if ($r['outcome']==='win') $wins++;
        $profit += (float)($r['profit'] ?? 0);
    }

    echo json_encode([
        'ok' => true,
        'source' => $source,
        'total' => $n,
        'wins' => $wins,
        'losses' => $n - $wins,
        'hit_rate' => $n ? round(100*$wins/$n, 1) : 0,
        'profit_u' => round($profit, 2),
        'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>substr($e->getMessage(),0,150)]);
}
