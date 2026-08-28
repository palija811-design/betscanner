<?php
declare(strict_types=1);

namespace SignalPitch;

/**
 * Peso dinámico entre la capa estadística y la de investigación IA.
 *
 * Idea: cuando hay poca muestra de partidos, la estadística es poco fiable,
 * así que se le da MÁS voz a la investigación. Con muestra amplia, la
 * estadística manda. El peso se desliza de forma gradual y con topes:
 * la estadística nunca baja del 40% ni sube del 65%.
 *
 * NO es una mejora demostrada, es una hipótesis razonable. Solo los
 * resultados registrados dirán si acierta más que el peso fijo.
 */
final class BlendWeight
{
    /**
     * Devuelve el peso de la ESTADÍSTICA (0.40..0.65) según los partidos
     * de muestra. El peso de la investigación es 1 menos esto.
     *
     *   played <= 2  -> 0.40 (estadística floja, manda el contexto)
     *   played  = 5  -> ~0.53
     *   played >= 9  -> 0.65 (estadística sólida, manda el número)
     */
    public static function statWeight(int $played): float
    {
        $min = 0.40; $max = 0.65;
        $lo = 2; $hi = 9;                 // rango de muestra donde interpola
        if ($played <= $lo) return $min;
        if ($played >= $hi) return $max;
        $t = ($played - $lo) / ($hi - $lo);   // 0..1
        return round($min + ($max - $min) * $t, 3);
    }

    /**
     * Combina score estadístico y score IA con el peso dinámico.
     * Devuelve el score final entero 0..100.
     */
    public static function blend(int $statScore, int $aiScore, int $played): int
    {
        $w = self::statWeight($played);
        return (int)round($statScore * $w + $aiScore * (1 - $w));
    }
}
