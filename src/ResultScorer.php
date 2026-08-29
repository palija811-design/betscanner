<?php
declare(strict_types=1);

namespace SignalPitch;

/**
 * Scorer para mercados de RESULTADO (no de goles):
 *   - HOME  : gana el local (1)
 *   - AWAY  : gana el visitante (2)
 *   - DC_1X : doble oportunidad local o empate (no pierde el local)
 *   - DC_X2 : doble oportunidad visitante o empate (no pierde el visitante)
 *
 * Se basa en la fuerza ofensiva/defensiva relativa de cada equipo más la
 * ventaja de jugar en casa. Distinto del Scorer de goles.
 *
 * Igual que el resto: es una estimación estadística, no una predicción
 * garantizada. El mercado (la cuota) suele ser mejor predictor.
 */
final class ResultScorer
{
    // ventaja de campo: cuánto suma jugar en casa (en "goles equivalentes")
    private const HOME_ADV = 0.35;

    private static function clamp(float $x): float { return max(0.0, min(1.0, $x)); }

    /**
     * Estima la "fuerza neta" de cada equipo de cara a este partido:
     * lo que se espera que marque menos lo que se espera que encaje.
     */
    public static function analyze(array $home, array $away, int $played): array
    {
        $gfH=(float)$home['gf_avg']; $gaH=(float)$home['ga_avg'];
        $gfA=(float)$away['gf_avg']; $gaA=(float)$away['ga_avg'];

        // fuerza esperada = ataque propio vs defensa rival, con ventaja de campo
        $expHome = ($gfH + $gaA)/2 + self::HOME_ADV;   // goles esperados del local
        $expAway = ($gfA + $gaH)/2;                     // goles esperados del visitante
        $diff = $expHome - $expAway;                    // >0 favorece local

        // convertimos la diferencia en probabilidades aproximadas
        // diff de +1.5 gol ~ local muy favorito; -1.5 ~ visitante muy favorito
        $pHome = self::clamp(0.5 + $diff/3.0);
        $pAway = self::clamp(0.5 - $diff/3.0);
        // el empate resta de los extremos: partidos igualados => más empate
        $pDraw = self::clamp(1 - abs($diff)/2.0) * 0.30;

        // normalizamos las tres a que sumen ~1
        $tot = $pHome + $pAway + $pDraw;
        if ($tot > 0) { $pHome/=$tot; $pAway/=$tot; $pDraw/=$tot; }

        $markets = [
            'HOME'  => (int)round($pHome*100),
            'AWAY'  => (int)round($pAway*100),
            'DC_1X' => (int)round(($pHome+$pDraw)*100),   // local o empate
            'DC_X2' => (int)round(($pAway+$pDraw)*100),   // visitante o empate
        ];

        // ajuste por muestra
        $conf = self::clamp($played/8);
        foreach ($markets as $k=>$v) {
            $markets[$k] = (int)round($v * (0.85 + 0.15*$conf));
        }
        arsort($markets);
        $best = array_key_first($markets);
        return [
            'markets' => $markets,
            'best'    => ['market'=>$best, 'score'=>$markets[$best]],
            'exp'     => ['home'=>round($expHome,2), 'away'=>round($expAway,2)],
        ];
    }
}
