<?php
declare(strict_types=1);

namespace SignalPitch;

/**
 * Obtiene cuotas de API-Football (/odds) para un fixture y las mapea a
 * nuestros mercados. La cuota se GUARDA y se MUESTRA, pero NO influye en el
 * score (decisión deliberada: el mercado ya es mejor predictor; usaremos la
 * cuota más adelante para medir "value" con resultados reales).
 *
 * API-Football devuelve muchas casas y mercados; tomamos una casa de
 * referencia y extraemos los mercados que nos interesan.
 */
final class OddsFetcher
{
    public function __construct(private ApiFootball $api) {}

    /**
     * @return array<string,float> mapa mercado=>cuota, p.ej. ['BTTS'=>1.70,'OVER'=>1.85,'HOME'=>2.10]
     */
    public function forFixture(int $fixtureId): array
    {
        try {
            $resp = $this->api->get('/odds', ['fixture' => $fixtureId]);
        } catch (\Throwable $e) {
            return [];
        }
        if (!$resp) return [];

        $out = [];
        // estructura: response[0].bookmakers[].bets[].values[]
        $bookmakers = $resp[0]['bookmakers'] ?? [];
        if (!$bookmakers) return [];
        // usamos el primer bookmaker disponible como referencia
        $bets = $bookmakers[0]['bets'] ?? [];

        foreach ($bets as $bet) {
            $name = strtolower($bet['name'] ?? '');
            $values = $bet['values'] ?? [];

            // Match Winner (1X2)
            if ($name === 'match winner') {
                foreach ($values as $v) {
                    $lbl = strtolower($v['value'] ?? '');
                    if ($lbl === 'home') $out['HOME'] = (float)$v['odd'];
                    if ($lbl === 'away') $out['AWAY'] = (float)$v['odd'];
                }
            }
            // Both Teams Score
            if ($name === 'both teams to score') {
                foreach ($values as $v) {
                    if (strtolower($v['value'] ?? '') === 'yes') $out['BTTS'] = (float)$v['odd'];
                }
            }
            // Goals Over/Under
            if ($name === 'goals over/under') {
                foreach ($values as $v) {
                    $lbl = strtolower($v['value'] ?? '');
                    if ($lbl === 'over 2.5')  $out['OVER']  = (float)$v['odd'];
                    if ($lbl === 'under 2.5') $out['UNDER'] = (float)$v['odd'];
                }
            }
            // Double Chance
            if ($name === 'double chance') {
                foreach ($values as $v) {
                    $lbl = strtolower($v['value'] ?? '');
                    if ($lbl === 'home/draw') $out['DC_1X'] = (float)$v['odd'];
                    if ($lbl === 'draw/away') $out['DC_X2'] = (float)$v['odd'];
                }
            }
        }
        return $out;
    }
}
