<?php
declare(strict_types=1);

/**
 * Configuración central de Signal Pitch.
 * Las credenciales se leen de variables de entorno (no hardcodear).
 * En Easypanel: defínelas en la sección Environment del servicio.
 */

return [
    'db' => [
        'host'    => getenv('DB_HOST') ?: '127.0.0.1',
        'port'    => (int)(getenv('DB_PORT') ?: 3306),
        'name'    => getenv('DB_NAME') ?: 'signalpitch',
        'user'    => getenv('DB_USER') ?: 'signalpitch',
        'pass'    => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],

    'apifootball' => [
        'key'          => getenv('APIFOOTBALL_KEY') ?: '',
        'base'         => 'https://v3.football.api-sports.io',
        'season'       => (int)(getenv('SEASON') ?: 2026),
        // límite conservador para no pasarnos del plan free (~100/día)
        'daily_budget' => (int)(getenv('APIFOOTBALL_DAILY_BUDGET') ?: 90),
        // horas de validez del cache de stats de equipo
        'stats_ttl_h'  => 20,
    ],

    'claude' => [
        'key'          => getenv('ANTHROPIC_API_KEY') ?: '',
        'base'         => 'https://api.anthropic.com/v1/messages',
        'version'      => '2023-06-01',
        // modelo por prioridad de liga
        'model_top'    => 'claude-sonnet-5',      // ligas priority 1
        'model_bulk'   => 'claude-haiku-4-5-20251001', // resto
        'max_tokens'   => 400,
    ],

    // umbral mínimo para que una señal se considere "mostrable"
    'min_display_score' => 55,
];
