<?php
declare(strict_types=1);

namespace SignalPitch;

use PDO;

/**
 * Orquestador de estrategias.
 *
 * Dado un partido con su contexto, ejecuta TODAS las estrategias activas
 * sobre los MISMOS datos y guarda la predicción de cada una en 'predictions'.
 * Así todas compiten sobre idénticos partidos y la comparación es justa.
 *
 * Cada estrategia define en su config_json qué motor usa:
 *   - "stat":      solo Scorer (pesos, sin IA)
 *   - "hybrid":    Scorer + ClaudeScorer (ajuste acotado)  [baseline]
 *   - "reasoning": ReasoningScorer (juicio cualitativo tipo-analista)
 *
 * El motor "reasoning" es más caro (una llamada rica por partido), así que
 * normalmente lo reservas para pocas estrategias / ligas priority alta.
 */
final class StrategyRunner
{
    public function __construct(
        private PDO $pdo,
        private ClaudeScorer $adjuster,      // motor hybrid
        private ReasoningScorer $reasoner    // motor reasoning
    ) {}

    /** Carga estrategias activas con su config decodificada. */
    public function activeStrategies(): array
    {
        $rows = $this->pdo->query(
            "SELECT id, code, config_json FROM strategies WHERE is_active=1"
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['config'] = json_decode($r['config_json'], true) ?: [];
        }
        return $rows;
    }

    /**
     * Ejecuta todas las estrategias sobre un partido.
     *
     * @param array $stats  ['home'=>team_stats, 'away'=>team_stats, 'h2h'=>float, 'played'=>int]
     * @param array $ctx    contexto rico para reasoning (forma, stage, injuries, h2h texto)
     * @param bool  $useTop liga de prioridad alta => Sonnet donde aplique
     */
    public function runAll(int $fixtureId, array $stats, array $ctx, bool $useTop): void
    {
        $strategies = $this->activeStrategies();

        // cacheamos el resultado del motor reasoning por si varias estrategias
        // lo comparten (misma llamada cara reutilizada)
        $reasoningCache = null;

        foreach ($strategies as $st) {
            $cfg = $st['config'];
            $engine = $cfg['engine'] ?? 'hybrid';
            $minScore = (int)($cfg['min_score'] ?? 55);

            $perMarket = []; // market => [score, verdict, risk, factors, model, reasoning]

            if ($engine === 'reasoning') {
                if ($reasoningCache === null) {
                    $useWeb = (bool)($cfg['use_web'] ?? false);
                    try {
                        $reasoningCache = $this->reasoner->analyze($ctx, $useTop, $useWeb);
                    } catch (\Throwable $e) {
                        $reasoningCache = ['BTTS'=>[],'OVER'=>[],'UNDER'=>[],'model'=>'error'];
                    }
                }
                foreach (['BTTS','OVER','UNDER'] as $mk) {
                    $perMarket[$mk] = [
                        'score'   => (int)($reasoningCache[$mk]['score'] ?? 0),
                        'verdict' => $reasoningCache[$mk]['verdict'] ?? '',
                        'risk'    => $reasoningCache[$mk]['risk'] ?? '',
                        'factors' => null,
                        'model'   => $reasoningCache['model'] ?? 'reasoning',
                        'reasoning' => $reasoningCache['key_factors'] ?? null,
                    ];
                }
            } else {
                // stat o hybrid: parten del Scorer con los pesos de ESTA estrategia
                $weights = $cfg['weights'] ?? null;
                $analysis = $this->scoreWithWeights($stats, $weights);
                foreach (['BTTS','OVER','UNDER'] as $mk) {
                    $statScore = $analysis['markets'][$mk];
                    $verdict = ''; $risk = ''; $model = 'stat'; $finalScore = $statScore;

                    if ($engine === 'hybrid' && $statScore >= 45) {
                        try {
                            $res = $this->adjuster->evaluate(
                                $analysis['factors'], $mk, $statScore,
                                ['home'=>$ctx['home']['name'],'away'=>$ctx['away']['name'],'league'=>$ctx['league']??''],
                                $useTop
                            );
                            $finalScore = $res['ai_score'];
                            $verdict = $res['verdict']; $risk = $res['risk']; $model = $res['model'];
                        } catch (\Throwable $e) { /* nos quedamos con el stat score */ }
                    }
                    $perMarket[$mk] = [
                        'score'=>$finalScore, 'verdict'=>$verdict, 'risk'=>$risk,
                        'factors'=>$analysis['factors'], 'model'=>$model, 'reasoning'=>null,
                    ];
                }
            }

            // persistimos la predicción de esta estrategia por mercado
            foreach ($perMarket as $mk => $d) {
                $picked = $d['score'] >= $minScore ? 1 : 0;
                $conf = Scorer::confidence($d['score']);
                $this->pdo->prepare(
                    "INSERT INTO predictions
                       (strategy_id, fixture_id, market, score, confidence, picked,
                        factors_json, ai_verdict, ai_risk, reasoning_json, model_used)
                     VALUES (:sid,:fid,:mk,:sc,:cf,:pk,:fj,:vd,:rk,:rj,:md)
                     ON DUPLICATE KEY UPDATE
                        score=VALUES(score), confidence=VALUES(confidence), picked=VALUES(picked),
                        factors_json=VALUES(factors_json), ai_verdict=VALUES(ai_verdict),
                        ai_risk=VALUES(ai_risk), reasoning_json=VALUES(reasoning_json),
                        model_used=VALUES(model_used)"
                )->execute([
                    ':sid'=>$st['id'], ':fid'=>$fixtureId, ':mk'=>$mk,
                    ':sc'=>$d['score'], ':cf'=>$conf, ':pk'=>$picked,
                    ':fj'=>$d['factors']!==null? json_encode($d['factors'], JSON_UNESCAPED_UNICODE):null,
                    ':vd'=>$d['verdict'], ':rk'=>$d['risk'],
                    ':rj'=>$d['reasoning']!==null? json_encode($d['reasoning'], JSON_UNESCAPED_UNICODE):null,
                    ':md'=>$d['model'],
                ]);
            }
        }
    }

    /**
     * Igual que Scorer::analyze pero permite inyectar pesos personalizados
     * de la estrategia (si no, usa los de por defecto del Scorer).
     */
    private function scoreWithWeights(array $stats, ?array $weights): array
    {
        $f = Scorer::factors($stats['home'], $stats['away'], (float)$stats['h2h']);
        $played = (int)$stats['played'];
        $markets = [];
        foreach (['BTTS','OVER','UNDER'] as $mk) {
            if ($weights && isset($weights[$mk])) {
                $markets[$mk] = self::weightedScore($f, $weights[$mk], $played);
            } else {
                $markets[$mk] = Scorer::scoreFor($f, $mk, $played);
            }
        }
        return ['factors'=>$f, 'markets'=>$markets];
    }

    private static function weightedScore(array $f, array $w, int $played): int
    {
        $s = 0.0; $sum = 0.0;
        foreach ($w as $k => $weight) { $s += ($f[$k] ?? 0.0) * $weight; $sum += $weight; }
        if ($sum > 0) $s /= $sum;              // normaliza por si los pesos no suman 1
        $conf = max(0.0, min(1.0, $played/8));
        $s = $s * (0.85 + 0.15*$conf);
        return (int)round($s*100);
    }
}
