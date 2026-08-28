<?php
declare(strict_types=1);

/**
 * Endpoint de rendimiento: GET /performance.php
 * Devuelve el ranking de estrategias (acierto + ROI) y el desglose por
 * mercado/tramo. Alimenta el panel de "laboratorio" donde ves qué
 * estrategia va ganando. No gasta cuota de API externa.
 */

require __DIR__ . '/../src/Db.php';
use SignalPitch\Db;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=600');

$cfg = require __DIR__ . '/../config/config.php';

try {
    $pdo = Db::conn($cfg['db']);

    $perf = $pdo->query(
        "SELECT * FROM v_strategy_performance ORDER BY roi_pct DESC, hit_rate DESC"
    )->fetchAll();

    $breakdown = $pdo->query(
        "SELECT b.*, s.code FROM v_strategy_breakdown b
         JOIN strategies s ON s.id=b.strategy_id
         ORDER BY b.strategy_id, b.market, b.score_band"
    )->fetchAll();

    // metadatos de estrategias (para mostrar linaje/origen/hipótesis)
    $strats = $pdo->query(
        "SELECT id, code, name, description, origin, parent_id, is_active, is_champion, created_at
         FROM strategies ORDER BY created_at ASC"
    )->fetchAll();

    echo json_encode([
        'ok'          => true,
        'performance' => $perf,
        'breakdown'   => $breakdown,
        'strategies'  => $strats,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'No se pudo cargar el rendimiento.']);
}
