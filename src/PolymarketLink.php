<?php
declare(strict_types=1);

namespace SignalPitch;

/**
 * Genera el enlace directo al mercado de un partido en Polymarket usando su
 * API pública Gamma (sin autenticación). Filtra por la fecha del partido para
 * coger el evento correcto (no la ida ni mercados de clasificación).
 * Si falla, devuelve la página de la liga como respaldo.
 *
 * NOTA: Polymarket puede estar bloqueado por geolocalización según desde dónde
 * corra esto. En un VPS en España probablemente haga falta salida por proxy/VPN;
 * si no hay acceso, cae siempre al respaldo sin romper nada.
 */
final class PolymarketLink
{
    // Códigos de liga en Polymarket. Europeas y las 5 grandes domésticas.
    private const LEAGUE = [
        2 => 'ucl', 3 => 'uel', 848 => 'ucol',        // Champions, Europa, Conference
        39 => 'epl', 140 => 'laliga', 135 => 'seriea', // Premier, LaLiga, Serie A
        78 => 'bundesliga', 61 => 'ligue-1',           // Bundesliga, Ligue 1
        94 => 'primeira-liga',                         // Primeira Liga
    ];

    public static function forMatch(string $home, string $away, int $leagueId, string $matchDate): string
    {
        $liga = self::LEAGUE[$leagueId] ?? '';
        $fallback = $liga ? "https://polymarket.com/es/sports/{$liga}/games" : 'https://polymarket.com';

        try {
            $q = urlencode("$home $away");
            $ch = curl_init("https://gamma-api.polymarket.com/public-search?q=$q");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_HTTPHEADER     => ['User-Agent: signalpitch/1.0'],
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 200 && $body) {
                $data = json_decode($body, true);
                foreach (($data['events'] ?? []) as $e) {
                    $slug = $e['slug'] ?? '';
                    if ($slug !== '' && str_contains($slug, $matchDate)) {
                        // el slug ya empieza por el codigo de liga de Polymarket
                        // (p.ej. "uel-agf-ben-..."), asi que lo usamos para la ruta
                        $ligaSlug = $liga;
                        if ($ligaSlug === '' && str_contains($slug, '-')) {
                            $ligaSlug = explode('-', $slug)[0];  // saca "uel" de "uel-agf-ben-..."
                        }
                        return "https://polymarket.com/es/sports/{$ligaSlug}/{$slug}";
                    }
                }
            }
        } catch (\Throwable $e) { /* cae al respaldo */ }

        return $fallback;
    }
}
