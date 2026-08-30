<?php
declare(strict_types=1);

namespace SignalPitch;

use RuntimeException;

/**
 * MOTOR DE RAZONAMIENTO ("reasoning").
 *
 * A diferencia de ClaudeScorer (que solo ajusta un score ya calculado),
 * este motor replica el análisis CUALITATIVO: recibe el contexto crudo
 * del partido —la misma clase de información que un analista humano mira
 * en una previa— y pide a Claude que razone y puntúe cada mercado.
 *
 * Los inputs son deliberadamente los mismos que se usan al analizar una
 * previa a mano:
 *   - forma reciente de cada equipo
 *   - medias de goles marcados/encajados (local y visitante)
 *   - % BTTS / Over histórico
 *   - qué se juega cada equipo (ida/vuelta, necesidad de ganar)
 *   - lesiones / bajas si están disponibles
 *   - histórico H2H
 *   - (opcional) previas y noticias vía web search
 *
 * Salida: JSON con score + veredicto + riesgo + razonamiento estructurado,
 * por cada mercado.
 */
final class ReasoningScorerBasic
{
    public function __construct(private array $cfg) {} // config['claude']

    private const SYSTEM = <<<SYS
Eres un analista de fútbol experto en mercados de goles (BTTS y Over/Under 2.5).
Analizas un partido como lo haría un tipster serio ante una previa: sopesas la
forma reciente, la capacidad ofensiva y defensiva de cada equipo, lo que se
juegan (eliminatoria, necesidad de ganar, ida/vuelta), las bajas relevantes y
el histórico directo. Integras todo en un juicio, no en una fórmula.

Para cada uno de los tres mercados (BTTS, OVER 2.5, UNDER 2.5) das una
puntuación 0..100 que refleja tu confianza en que se cumpla, un veredicto de
una frase que explique tu razonamiento principal, y el riesgo principal en
contra.

Principios:
- Razona sobre el contexto REAL que se te da. No inventes lesiones, cuotas ni
  datos que no aparezcan.
- Pondera el contexto cualitativo: un equipo que necesita ganar una vuelta se
  abrirá y eso favorece goles; una defensa muy sólida resta valor al Over; etc.
- Sé calibrado: reserva puntuaciones altas (>75) para cuando varios factores
  apuntan en la misma dirección. Si las señales están repartidas, puntúa medio.
- Nunca uses lenguaje de certeza absoluta ni prometas aciertos.
- Coherencia: OVER y UNDER del mismo partido no pueden ser ambos altos.

Responde ÚNICAMENTE con JSON válido, sin markdown:
{
  "BTTS":  {"score": <int>, "verdict": "<str>", "risk": "<str>"},
  "OVER":  {"score": <int>, "verdict": "<str>", "risk": "<str>"},
  "UNDER": {"score": <int>, "verdict": "<str>", "risk": "<str>"},
  "key_factors": ["<factor 1>", "<factor 2>"]
}
SYS;

    /**
     * @param array $ctx Contexto del partido. Estructura esperada:
     *   home/away => ['name','form','gf_avg','ga_avg','btts_pct','over25_pct','injuries'(str|null)]
     *   league, stage(str|null: "vuelta playoff, Hapoel pierde 2-1"...), h2h(str|null), kickoff
     * @param bool $useTop  usar Sonnet (true) o Haiku (false)
     * @param bool $useWeb  añadir web search para previas/lesiones
     * @return array  el JSON decodificado + 'model'
     */
    public function analyze(array $ctx, bool $useTop, bool $useWeb = false): array
    {
        $model = $useTop ? $this->cfg['model_top'] : $this->cfg['model_bulk'];

        $userText = $this->buildContext($ctx);

        $body = [
            'model'      => $model,
            'max_tokens' => 700,
            'system'     => self::SYSTEM,
            'messages'   => [[
                'role'    => 'user',
                'content' => $userText,
            ]],
        ];

        // web search opcional: replica el "yo buscaba previas en el momento"
        if ($useWeb) {
            $body['tools'] = [[
                'type' => 'web_search_20250305',
                'name' => 'web_search',
            ]];
            $body['max_tokens'] = 1200;
        }

        $text = $this->call($body);
        $parsed = $this->parseJson($text);

        // saneado: aseguramos los tres mercados y rangos válidos
        foreach (['BTTS','OVER','UNDER'] as $mk) {
            $s = (int)($parsed[$mk]['score'] ?? 0);
            $parsed[$mk]['score'] = max(0, min(100, $s));
            $parsed[$mk]['verdict'] = trim((string)($parsed[$mk]['verdict'] ?? ''));
            $parsed[$mk]['risk']    = trim((string)($parsed[$mk]['risk'] ?? ''));
        }
        // coherencia dura: si OVER y UNDER salen ambos altos, baja el menor
        if ($parsed['OVER']['score'] >= 60 && $parsed['UNDER']['score'] >= 60) {
            if ($parsed['OVER']['score'] >= $parsed['UNDER']['score']) {
                $parsed['UNDER']['score'] = min($parsed['UNDER']['score'], 45);
            } else {
                $parsed['OVER']['score'] = min($parsed['OVER']['score'], 45);
            }
        }
        $parsed['model'] = $useTop ? 'sonnet' : 'haiku';
        return $parsed;
    }

    /** Construye el texto de contexto tal como se leería una previa. */
    private function buildContext(array $ctx): string
    {
        $h = $ctx['home']; $a = $ctx['away'];
        $lines = [];
        $lines[] = "PARTIDO: {$h['name']} vs {$a['name']}";
        $lines[] = "Competición: " . ($ctx['league'] ?? 'n/d') . ($ctx['kickoff'] ? " · KO {$ctx['kickoff']}" : '');
        if (!empty($ctx['stage'])) {
            $lines[] = "Contexto de eliminatoria / qué se juegan: {$ctx['stage']}";
        }
        $lines[] = "";
        $lines[] = "LOCAL {$h['name']}:";
        $lines[] = "  Forma reciente: " . ($h['form'] ?? 'n/d');
        $lines[] = "  Goles a favor/partido: " . ($h['gf_avg'] ?? 'n/d') . " · en contra/partido: " . ($h['ga_avg'] ?? 'n/d');
        $lines[] = "  % partidos con BTTS: " . ($h['btts_pct'] ?? 'n/d') . " · % Over 2.5: " . ($h['over25_pct'] ?? 'n/d');
        if (!empty($h['injuries'])) $lines[] = "  Bajas: {$h['injuries']}";
        $lines[] = "";
        $lines[] = "VISITANTE {$a['name']}:";
        $lines[] = "  Forma reciente: " . ($a['form'] ?? 'n/d');
        $lines[] = "  Goles a favor/partido: " . ($a['gf_avg'] ?? 'n/d') . " · en contra/partido: " . ($a['ga_avg'] ?? 'n/d');
        $lines[] = "  % partidos con BTTS: " . ($a['btts_pct'] ?? 'n/d') . " · % Over 2.5: " . ($a['over25_pct'] ?? 'n/d');
        if (!empty($a['injuries'])) $lines[] = "  Bajas: {$a['injuries']}";
        if (!empty($ctx['h2h'])) {
            $lines[] = "";
            $lines[] = "HISTÓRICO DIRECTO: {$ctx['h2h']}";
        }
        $lines[] = "";
        $lines[] = "Analiza los tres mercados y devuelve el JSON.";
        return implode("\n", $lines);
    }

    private function call(array $body): string
    {
        $ch = curl_init($this->cfg['base']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 60,     // web search puede tardar más
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

        if ($resp === false) throw new RuntimeException("cURL Claude: $err");
        if ($code !== 200) throw new RuntimeException("HTTP $code Claude: " . substr((string)$resp, 0, 300));

        $json = json_decode($resp, true);
        $text = '';
        foreach (($json['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'];
        }
        return $text;
    }

    private function parseJson(string $text): array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?|```$/m', '', $text) ?? $text;
        $start = strpos($text, '{'); $end = strrpos($text, '}');
        if ($start === false || $end === false) return ['BTTS'=>[],'OVER'=>[],'UNDER'=>[]];
        $out = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($out) ? $out : ['BTTS'=>[],'OVER'=>[],'UNDER'=>[]];
    }
}
