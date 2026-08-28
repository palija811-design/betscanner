<?php
declare(strict_types=1);

/**
 * Endpoint público del dashboard: GET /api.php?market=BTTS&min=55
 * Devuelve las señales de hoy ya calculadas (lee de la vista, no llama
 * a ninguna API externa: rápido y sin gastar cuota).
 */

require __DIR__ . '/Db.php';
use SignalPitch\Db;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300'); // 5 min de cache HTTP

$cfg = require __DIR__ . '/../config/config.php';

try {
    $pdo = Db::conn($cfg['db']);

    $market = $_GET['market'] ?? 'all';       // all|BTTS|OVER|UNDER
    $min    = (int)($_GET['min'] ?? $cfg['min_display_score']);
    $min    = max(0, min(100, $min));

    $sql = "SELECT * FROM v_today_signals WHERE final_score >= :min";
    $params = [':min' => $min];
    if (in_array($market, ['BTTS','OVER','UNDER'], true)) {
        $sql .= " AND market = :mk";
        $params[':mk'] = $market;
    }
    $sql .= " ORDER BY final_score DESC LIMIT 100";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    // decodifica factors_json para el frontend
    foreach ($rows as &$r) {
        $r['factors'] = json_decode($r['factors_json'] ?? '{}', true);
        unset($r['factors_json']);
        $r['final_score'] = (int)$r['final_score'];
    }
    unset($r);

    echo json_encode([
        'ok'        => true,
        'date'      => gmdate('Y-m-d'),
        'count'     => count($rows),
        'signals'   => $rows,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudieron cargar las señales.']);
}
