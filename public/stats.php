<?php
declare(strict_types=1);

/**
 * Estadísticas de rendimiento.
 *   GET /stats.php?stake=10
 *
 * Devuelve, POR SEPARADO:
 *   - dashboard: acierto y ROI de las señales del dashboard (motor+IA),
 *     desglosado por banda de confianza (fuerte/moderada/débil) y por mercado.
 *   - laboratorio: acierto y ROI de las estrategias, por estrategia.
 *   - simulador: cuánto habrías ganado/perdido apostando 'stake' fijo a cada señal.
 *
 * Todo se basa en apuestas YA LIQUIDADAS (outcome win/loss). Si aún no hay
 * partidos jugados y liquidados, sale vacío: es lo normal al principio.
 */

require __DIR__ . '/../src/Db.php';
use SignalPitch\Db;

header('Content-Type: application/json; charset=utf-8');
$cfg = require __DIR__ . '/../config/config.php';

$stake = isset($_GET['stake']) ? max(0.5, min(10000, (float)$_GET['stake'])) : 10.0;

// filtros opcionales del simulador
$fMarkets = isset($_GET['markets']) && $_GET['markets'] !== ''
    ? array_intersect(explode(',', strtoupper($_GET['markets'])), ['BTTS','OVER','UNDER','HOME','AWAY','DC_1X','DC_X2'])
    : [];
$fBandas = isset($_GET['bandas']) && $_GET['bandas'] !== ''
    ? array_intersect(explode(',', strtolower($_GET['bandas'])), ['fuerte','moderada','debil'])
    : [];

// construye el WHERE del simulador según los filtros
function bandaSql(array $bandas): string {
    if (!$bandas) return '';
    $conds = [];
    foreach ($bandas as $b) {
        if ($b==='fuerte')   $conds[] = 'final_score>=70';
        if ($b==='moderada') $conds[] = '(final_score>=55 AND final_score<70)';
        if ($b==='debil')    $conds[] = 'final_score<55';
    }
    return $conds ? ' AND ('.implode(' OR ', $conds).')' : '';
}
function marketSql(array $markets, PDO $pdo): string {
    if (!$markets) return '';
    $q = array_map(fn($m)=>$pdo->quote($m), $markets);
    return ' AND market IN ('.implode(',', $q).')';
}

try {
    $pdo = Db::conn($cfg['db']);

    // ---- DASHBOARD: por banda de confianza ----
    $bandas = $pdo->query(
        "SELECT
            CASE WHEN final_score>=70 THEN 'fuerte'
                 WHEN final_score>=55 THEN 'moderada' ELSE 'debil' END AS banda,
            COUNT(*) n, SUM(outcome='win') wins, SUM(outcome='loss') losses,
            ROUND(100*SUM(outcome='win')/NULLIF(COUNT(*),0),1) hit_rate,
            ROUND(SUM(profit),2) profit_u
         FROM signals WHERE outcome IN ('win','loss')
         GROUP BY banda"
    )->fetchAll();

    // ---- DASHBOARD: por mercado ----
    $mercados = $pdo->query(
        "SELECT market,
            COUNT(*) n, SUM(outcome='win') wins,
            ROUND(100*SUM(outcome='win')/NULLIF(COUNT(*),0),1) hit_rate,
            ROUND(SUM(profit),2) profit_u
         FROM signals WHERE outcome IN ('win','loss')
         GROUP BY market ORDER BY n DESC"
    )->fetchAll();

    // ---- Totales dashboard + simulador (CON filtros aplicados) ----
    $whereFiltros = bandaSql($fBandas) . marketSql($fMarkets, $pdo);
    $tot = $pdo->query(
        "SELECT COUNT(*) n, SUM(outcome='win') wins, SUM(outcome='loss') losses,
            ROUND(SUM(profit),2) profit_u
         FROM signals WHERE outcome IN ('win','loss')" . $whereFiltros
    )->fetch();

    $n = (int)($tot['n'] ?? 0);
    $profitU = (float)($tot['profit_u'] ?? 0);   // en unidades (stake=1)
    $sim = [
        'stake'        => $stake,
        'apuestas'     => $n,
        'invertido'    => round($n * $stake, 2),
        'resultado'    => round($profitU * $stake, 2),   // ganancia/pérdida neta
        'roi_pct'      => $n ? round(100 * $profitU / $n, 1) : 0,
        'hit_rate'     => $n ? round(100 * (int)$tot['wins'] / $n, 1) : 0,
        'filtros'      => ['markets'=>array_values($fMarkets), 'bandas'=>array_values($fBandas)],
        'aviso_filtro' => $n > 0 && $n < 20
            ? "Con estos filtros solo quedan $n apuestas: la cifra es poco fiable, tomatela como orientativa."
            : null,
    ];

    // ---- LABORATORIO: por estrategia ----
    $lab = $pdo->query(
        "SELECT st.code, st.name, st.is_champion,
            COUNT(*) n, SUM(p.outcome='win') wins,
            ROUND(100*SUM(p.outcome='win')/NULLIF(COUNT(*),0),1) hit_rate,
            ROUND(SUM(p.profit),2) profit_u,
            ROUND(100*SUM(p.profit)/NULLIF(COUNT(*),0),1) roi_pct
         FROM strategies st
         JOIN predictions p ON p.strategy_id=st.id AND p.picked=1 AND p.outcome IN ('win','loss')
         GROUP BY st.id, st.code, st.name, st.is_champion
         ORDER BY roi_pct DESC"
    )->fetchAll();

    // fiabilidad: cuántas apuestas hacen falta para fiarse
    $fiabilidad = $n >= 200 ? 'alta' : ($n >= 50 ? 'media' : 'baja');

    echo json_encode([
        'ok' => true,
        'fiabilidad' => $fiabilidad,
        'aviso' => $n < 50
            ? "Solo $n apuestas liquidadas. Hacen falta bastantes más (100-300) para que estas cifras signifiquen algo. De momento son orientativas."
            : null,
        'dashboard' => [
            'por_banda'   => $bandas,
            'por_mercado' => $mercados,
            'simulador'   => $sim,
        ],
        'laboratorio' => $lab,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>substr($e->getMessage(),0,150)]);
}
