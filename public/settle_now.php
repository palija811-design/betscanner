<?php
declare(strict_types=1);

/**
 * Botón "Actualizar resultados": ejecuta la liquidación (settle) bajo demanda.
 *   GET /settle_now.php
 *
 * Trae de API-Football los resultados de los partidos ya terminados y marca
 * cada señal/predicción pendiente como win/loss, calculando el beneficio con
 * la cuota guardada. Es lo que llena la página de estadísticas.
 *
 * Reutiliza el mismo cron/settle.php capturando su salida, para no duplicar
 * la lógica. Solo liquida lo que esté 'pending', así que pulsarlo de más no
 * duplica datos (aunque sí gasta llamadas a la API: úsalo cuando de verdad
 * hayan terminado partidos).
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(300);

// contamos cuántas señales estaban pendientes antes, para reportar el avance
$cfg = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Db.php';
$pdo = \SignalPitch\Db::conn($cfg['db']);

try {
    $antesSig = (int)$pdo->query("SELECT COUNT(*) FROM signals WHERE outcome='pending'")->fetchColumn();
    $antesPred = (int)$pdo->query("SELECT COUNT(*) FROM predictions WHERE outcome='pending'")->fetchColumn();

    // ejecuta el settle capturando su salida de texto
    ob_start();
    require __DIR__ . '/../cron/settle.php';
    $log = ob_get_clean();

    // reconecta por si el include alteró el estado de $pdo
    $pdo2 = \SignalPitch\Db::conn($cfg['db']);
    $despuesSig = (int)$pdo2->query("SELECT COUNT(*) FROM signals WHERE outcome='pending'")->fetchColumn();
    $despuesPred = (int)$pdo2->query("SELECT COUNT(*) FROM predictions WHERE outcome='pending'")->fetchColumn();

    $sigLiquidadas = $antesSig - $despuesSig;
    $predLiquidadas = $antesPred - $despuesPred;

    echo json_encode([
        'ok' => true,
        'senales_liquidadas' => max(0, $sigLiquidadas),
        'predicciones_liquidadas' => max(0, $predLiquidadas),
        'pendientes_restantes' => $despuesSig,
        'msg' => ($sigLiquidadas > 0 || $predLiquidadas > 0)
            ? "Liquidadas $sigLiquidadas senales y $predLiquidadas predicciones. Ya puedes ver las estadisticas."
            : "No habia partidos nuevos que liquidar (o aun no constan como finalizados en la API).",
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>substr($e->getMessage(),0,150)]);
}
