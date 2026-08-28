<?php
declare(strict_types=1);

namespace SignalPitch;

/**
 * Calcula las medias de un equipo sobre sus ÚLTIMOS N partidos reales
 * (todas las competiciones), en vez de fiarse de las medias por competición
 * de API-Football (muestra minúscula a principio de temporada).
 *
 * Esta fue la mejora clave de fiabilidad: medias estables y actuales.
 */
final class RecentForm
{
    public function __construct(
        private ApiFootball $api,
        private int $season,
        private int $lastN = 10
    ) {}

    /** @return array{gf_avg:float,ga_avg:float,btts_pct:int,played:int} */
    public function forTeam(int $teamId): array
    {
        $fixtures = $this->api->get('/fixtures', [
            'team' => $teamId, 'season' => $this->season, 'last' => $this->lastN,
        ]);

        $gf = 0; $ga = 0; $bttsCount = 0; $played = 0;
        foreach ($fixtures as $fx) {
            $st = $fx['fixture']['status']['short'] ?? '';
            if (!in_array($st, ['FT','AET','PEN'], true)) continue;
            $hg = $fx['goals']['home']; $ag = $fx['goals']['away'];
            if ($hg === null || $ag === null) continue;
            $home = ($fx['teams']['home']['id'] === $teamId);
            $scored   = $home ? $hg : $ag;
            $conceded = $home ? $ag : $hg;
            $gf += $scored; $ga += $conceded;
            if ($hg > 0 && $ag > 0) $bttsCount++;
            $played++;
        }
        if ($played === 0) {
            return ['gf_avg'=>0.0,'ga_avg'=>0.0,'btts_pct'=>0,'played'=>0];
        }
        return [
            'gf_avg'   => round($gf / $played, 2),
            'ga_avg'   => round($ga / $played, 2),
            'btts_pct' => (int)round($bttsCount / $played * 100),
            'played'   => $played,
        ];
    }
}
