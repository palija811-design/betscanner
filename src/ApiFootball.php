<?php
declare(strict_types=1);

namespace SignalPitch;

use PDO;
use RuntimeException;

/**
 * Cliente de API-Football (api-sports.io).
 *
 * Controla el presupuesto diario de requests contra la tabla api_usage
 * para no reventar el límite del plan free (~100/día). Antes de cada
 * llamada comprueba el gasto; si se alcanza el budget, lanza excepción
 * y el cron se detiene con lo que haya podido traer.
 */
final class ApiFootball
{
    public function __construct(
        private PDO $db,
        private array $cfg          // config['apifootball']
    ) {}

    /** Requests ya gastadas hoy. */
    public function spentToday(): int
    {
        $st = $this->db->prepare(
            "SELECT requests FROM api_usage WHERE day = UTC_DATE() AND provider='apifootball'"
        );
        $st->execute();
        return (int)($st->fetchColumn() ?: 0);
    }

    private function bumpUsage(int $n = 1): void
    {
        $this->db->prepare(
            "INSERT INTO api_usage (day, provider, requests)
             VALUES (UTC_DATE(), 'apifootball', :n)
             ON DUPLICATE KEY UPDATE requests = requests + :n2"
        )->execute([':n' => $n, ':n2' => $n]);
    }

    /**
     * GET a un endpoint. $params es query assoc.
     * Devuelve el array 'response' ya decodificado.
     */
    public function get(string $endpoint, array $params = []): array
    {
        if ($this->spentToday() >= $this->cfg['daily_budget']) {
            throw new RuntimeException('Presupuesto diario de API-Football agotado, corto aquí.');
        }

        $url = rtrim($this->cfg['base'], '/') . '/' . ltrim($endpoint, '/');
        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'x-apisports-key: ' . $this->cfg['key'],
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $this->bumpUsage(1);

        if ($body === false) {
            throw new RuntimeException("cURL error en $endpoint: $err");
        }
        if ($code !== 200) {
            throw new RuntimeException("HTTP $code en $endpoint: " . substr((string)$body, 0, 300));
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException("Respuesta no-JSON en $endpoint");
        }
        // API-Football mete errores dentro del 200 en el campo 'errors'
        if (!empty($json['errors'])) {
            $msg = is_array($json['errors']) ? json_encode($json['errors']) : (string)$json['errors'];
            // rate-limit u otros errores lógicos
            throw new RuntimeException("API-Football error en $endpoint: $msg");
        }
        return $json['response'] ?? [];
    }

    /** Rate-limit suave: espera para respetar los 10 req/min del free. */
    public function pace(): void
    {
        usleep(6_500_000); // ~6.5s entre llamadas => <10/min con margen
    }
}
