<?php
declare(strict_types=1);

namespace SignalPitch;

/**
 * Motor de scoring estadístico — CALIBRACIÓN v2 (discriminante).
 *
 * Los umbrales se centran en la realidad del fútbol (media ~2.7 goles/partido,
 * ~1.3 gf y ga por equipo, BTTS neutro al 50%) para que el score DISCRIMINE
 * en vez de marcar "Over fuerte" en todo. Validado sobre 21 partidos reales.
 *
 * Recibe medias de local y visitante, calcula factores 0..1 relativos a lo
 * "normal", los pondera por mercado y devuelve score 0..100 por mercado.
 */
final class Scorer
{
    /** Ponderaciones por mercado. */
    private const WEIGHTS = [
        'BTTS'  => ['attackHome'=>0.20,'attackAway'=>0.20,'leaky'=>0.20,'bttsHist'=>0.30,'h2h'=>0.10],
        'OVER'  => ['goalsAvg'=>0.40,'attackHome'=>0.15,'attackAway'=>0.15,'leaky'=>0.20,'h2h'=>0.10],
        'UNDER' => ['lowGoals'=>0.45,'solidHome'=>0.22,'solidAway'=>0.23,'h2h'=>0.10],
    ];

    private static function clamp(float $x): float
    {
        return max(0.0, min(1.0, $x));
    }

    /**
     * $home / $away: arrays con gf_avg, ga_avg, btts_pct.
     * Los factores miden CUÁNTO SE DESVÍA cada métrica de lo normal, no el valor
     * absoluto, para que un partido medio dé ~0.4-0.5 y solo los extremos den >0.7.
     */
    public static function factors(array $home, array $away, float $h2hGoals): array
    {
        $gfH = (float)$home['gf_avg']; $gaH = (float)$home['ga_avg'];
        $gfA = (float)$away['gf_avg']; $gaA = (float)$away['ga_avg'];
        $bH  = (float)$home['btts_pct']; $bA = (float)$away['btts_pct'];

        // goles esperados del partido (proxy)
        $expGoals = ($gfH + $gaA + $gfA + $gaH) / 2;

        return [
            // ataque: 0.8 gf/partido es flojo, 2.4 es muy alto
            'attackHome' => self::clamp(($gfH - 0.8) / 1.6),
            'attackAway' => self::clamp(($gfA - 0.8) / 1.6),
            // defensa permeable: media de goles encajados por encima de 0.9 suma
            'leaky'      => self::clamp((($gaH + $gaA) / 2 - 0.9) / 1.2),
            'solidHome'  => self::clamp((1.3 - $gaH) / 1.2),
            'solidAway'  => self::clamp((1.3 - $gaA) / 1.2),
            // goles: centrado en ~2.7 esperados. 2.0->0, 3.8->1
            'goalsAvg'   => self::clamp(($expGoals - 2.0) / 1.8),
            'lowGoals'   => self::clamp((3.2 - $expGoals) / 1.8),
            // BTTS histórico: 35% neutro-bajo, 80% muy alto
            'bttsHist'   => self::clamp((($bH + $bA) / 2 - 35) / 45),
            // h2h usa los goles esperados como proxy si no hay dato directo
            'h2h'        => self::clamp(($h2hGoals - 2.0) / 1.8),
        ];
    }

    /** Score 0..100 para un mercado, con ajuste por tamaño de muestra. */
    public static function scoreFor(array $factors, string $market, int $played): int
    {
        $w = self::WEIGHTS[$market] ?? [];
        $s = 0.0;
        foreach ($w as $k => $weight) {
            $s += ($factors[$k] ?? 0.0) * $weight;
        }
        $conf = self::clamp($played / 8);
        $s = $s * (0.85 + 0.15 * $conf);
        return (int)round($s * 100);
    }

    public static function confidence(int $score): string
    {
        if ($score >= 70) return 'fuerte';
        if ($score >= 55) return 'moderada';
        return 'debil';
    }

    /**
     * Analiza los tres mercados. $h2hGoals: media de goles esperada del partido
     * (o H2H real si se dispone).
     */
    public static function analyze(array $home, array $away, float $h2hGoals, int $played): array
    {
        $f = self::factors($home, $away, $h2hGoals);
        $markets = [];
        foreach (['BTTS','OVER','UNDER'] as $mk) {
            $markets[$mk] = self::scoreFor($f, $mk, $played);
        }
        arsort($markets);
        $bestMk = array_key_first($markets);
        return [
            'factors' => $f,
            'markets' => $markets,
            'best'    => ['market' => $bestMk, 'score' => $markets[$bestMk]],
        ];
    }
}
