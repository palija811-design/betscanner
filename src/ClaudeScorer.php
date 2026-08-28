<?php
declare(strict_types=1);

namespace SignalPitch;

use RuntimeException;

/**
 * Capa de IA. Toma los factores YA calculados por Scorer (no deja que el
 * modelo invente datos) y le pide a Claude que:
 *   1. ajuste el score estadístico dentro de un margen acotado,
 *   2. redacte un veredicto breve y el principal riesgo,
 * devolviendo JSON estricto.
 *
 * El diseño clave: el prompt prohíbe inventar cifras y ancla el ajuste
 * a ±12 puntos sobre el score estadístico, para que la IA pondere sin
 * alucinar ni disparar el número.
 */
final class ClaudeScorer
{
    public function __construct(private array $cfg) {} // config['claude']

    private const SYSTEM = <<<SYS
Eres un analista de datos de fútbol. Recibes factores numéricos (0..1) ya
calculados sobre estadísticas reales de un partido y un score estadístico
(0..100) para un mercado de apuestas (BTTS, OVER 2.5 o UNDER 2.5).

Tu trabajo NO es predecir el resultado ni inventar datos. Es:
- Ponderar los factores dados y ajustar el score dentro de ±12 puntos como
  máximo respecto al score estadístico recibido. Nunca fuera de 0..100.
- Redactar un veredicto de una frase (máx. 30 palabras) en español,
  explicando en qué se apoya, y un riesgo de una frase (máx. 20 palabras).

Reglas estrictas:
- Usa SOLO los factores que se te dan. No menciones cifras que no aparezcan.
- No prometas aciertos ni uses lenguaje de certeza ("seguro", "garantizado").
- Responde ÚNICAMENTE con un objeto JSON válido, sin markdown ni texto extra:
  {"ai_score": <int 0-100>, "verdict": "<string>", "risk": "<string>"}
SYS;

    /**
     * @param array  $factors  desglose 0..1 de Scorer
     * @param string $market   BTTS | OVER | UNDER
     * @param int    $statScore score estadístico previo
     * @param array  $meta     ['home'=>..,'away'=>..,'league'=>..]
     * @param bool   $useTop   true => modelo Sonnet, false => Haiku
     * @return array{ai_score:int,verdict:string,risk:string,model:string}
     */
    public function evaluate(array $factors, string $market, int $statScore, array $meta, bool $useTop): array
    {
        $model = $useTop ? $this->cfg['model_top'] : $this->cfg['model_bulk'];

        // redondeamos factores para un prompt limpio
        $fr = [];
        foreach ($factors as $k => $v) {
            $fr[$k] = round((float)$v, 2);
        }

        $userPayload = [
            'partido'         => $meta['home'] . ' vs ' . $meta['away'],
            'liga'            => $meta['league'] ?? '',
            'mercado'         => $market,
            'score_estadistico' => $statScore,
            'factores'        => $fr,
        ];

        $reqBody = [
            'model'      => $model,
            'max_tokens' => $this->cfg['max_tokens'],
            'system'     => self::SYSTEM,
            'messages'   => [[
                'role'    => 'user',
                'content' => json_encode($userPayload, JSON_UNESCAPED_UNICODE),
            ]],
        ];

        $raw = $this->call($reqBody);
        $parsed = $this->parseJson($raw);

        // salvaguardas: si la IA se sale del margen, la recortamos
        $ai = (int)($parsed['ai_score'] ?? $statScore);
        $ai = max($statScore - 12, min($statScore + 12, $ai));
        $ai = max(0, min(100, $ai));

        return [
            'ai_score' => $ai,
            'verdict'  => trim((string)($parsed['verdict'] ?? '')),
            'risk'     => trim((string)($parsed['risk'] ?? '')),
            'model'    => $useTop ? 'sonnet' : 'haiku',
        ];
    }

    private function call(array $body): string
    {
        $ch = curl_init($this->cfg['base']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->cfg['key'],
                'anthropic-version: ' . $this->cfg['version'],
            ],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException("cURL Claude: $err");
        }
        if ($code !== 200) {
            throw new RuntimeException("HTTP $code Claude: " . substr((string)$resp, 0, 300));
        }

        $json = json_decode($resp, true);
        // el texto está en content[].text (bloques type=text)
        $text = '';
        foreach (($json['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }
        return $text;
    }

    /** Extrae el objeto JSON aunque venga con ruido alrededor. */
    private function parseJson(string $text): array
    {
        $text = trim($text);
        // quita fences por si acaso
        $text = preg_replace('/^```(?:json)?|```$/m', '', $text) ?? $text;
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start === false || $end === false) {
            return [];
        }
        $slice = substr($text, $start, $end - $start + 1);
        $out = json_decode($slice, true);
        return is_array($out) ? $out : [];
    }
}
