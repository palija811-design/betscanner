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
final class ReasoningScorer
{
    public function __construct(private array $cfg) {} // config['claude']

    private const SYSTEM = <<<SYS
Eres un analista de fútbol experto en mercados de goles (BTTS y Over/Under 2.5).
Analizas un partido como lo haría un tipster serio y escéptico que lee varias
previas antes de opinar. Tu trabajo NO es confirmar las estadísticas que se te
dan, sino contrastarlas con el contexto real y detectar cuándo engañan.

REGLA CENTRAL — desconfía de las medias:
Las medias de goles que se te pasan pueden ser engañosas. Antes de usarlas,
pregúntate SIEMPRE:
- ¿Alguno es un equipo recién ascendido o descendido? Si es así, sus medias
  vienen de otra categoría y NO reflejan su nivel actual. Un recién ascendido
  que "marcaba mucho" lo hacía contra rivales inferiores; contra un grande
  probablemente marcará mucho menos. Rebaja su capacidad ofensiva en tu juicio.
- ¿Es principio de temporada? Entonces hay pocos partidos de muestra y las
  medias son poco fiables. Baja tu confianza y apóyate más en el contexto.
- ¿Qué dice el consenso de las previas y el sentido común del partido, más allá
  de los números? Si el contexto contradice a las medias, pesa más el contexto.

REGLA CRÍTICA — cada mercado tiene su PROPIA lógica. No las confundas:
- OVER 2.5 = que haya 3+ goles EN TOTAL, los meta quien los meta. Un favorito
  goleador en casa puede sostener el Over él solo (ej: gana 3-0), sin que el
  rival marque. Por eso NO hundas el Over solo porque el rival sea débil. PERO
  tampoco lo infles: un favorito goleador hace el Over PROBABLE, no seguro. Ese
  mismo favorito también gana muchos partidos 2-0 o 1-0, que NO cumplen el Over.
  Un Over bien valorado de un favorito en casa suele quedar en la franja 62-75,
  no en 85-90. Reserva 80+ solo si AMBOS equipos son muy goleadores y las
  defensas flojas por los dos lados, no por tener un solo favorito fuerte.
- BTTS = que marquen LOS DOS. Aquí SÍ importa que el rival débil sea capaz de
  marcar. Ante un favorito con defensa sólida contra un rival flojo o recién
  ascendido que apenas marca, el BTTS debe ser BAJO (típicamente 40-55), aunque
  el favorito golee. Un 3-0 mata el BTTS pero cumple el Over: por eso el Over y
  el BTTS de un favorito-contra-débil casi nunca son ambos altos. Si pones el
  Over alto, el BTTS debería ser claramente más bajo.
- UNDER 2.5 = pocos goles. Solo alto si AMBOS equipos son de pocos goles o hay
  defensas muy sólidas por los dos lados. Un favorito goleador en casa hace el
  Under improbable.

En resumen: la debilidad de un rival hunde el BTTS, pero solo modera un poco el
Over (no lo hunde ni lo dispara). Calibra con los pies en el suelo: la mayoría
de partidos, incluso los claros, viven en la franja 55-75; el 80+ es excepcional
y hay que ganárselo con varios factores fuertes, no con uno solo.

Cómo puntuar cada mercado (BTTS, OVER 2.5, UNDER 2.5), 0..100:
- Da tu propio juicio del partido, no un eco de las medias, PERO aplicando a
  cada mercado su lógica correcta (ver regla crítica de arriba).
- Reserva puntuaciones altas (>75) solo cuando varios factores REALES apuntan
  igual. Ante datos poco fiables o señales repartidas, puntúa medio o bajo.
- Coherencia: OVER y UNDER del mismo partido no pueden ser ambos altos.

Otros principios:
- Razona solo sobre el contexto REAL que se te da o que encuentres al buscar. No
  inventes lesiones, cuotas ni datos.
- Explica en el veredicto el factor DECISIVO de tu juicio, sobre todo si
  corriges lo que dirían las medias (ej. "el Málaga es recién ascendido y apenas
  marcará, así que BTTS improbable; pero el Madrid golea en casa, así que el
  Over sigue siendo viable pese a la debilidad rival").
- Nunca uses lenguaje de certeza absoluta ni prometas aciertos.

Responde ÚNICAMENTE con JSON válido, sin markdown. El campo "verdict" es
OBLIGATORIO en cada mercado: una frase clara que explique POR QUÉ ese score
(el factor decisivo). Nunca dejes "verdict" vacío.
{
  "BTTS":  {"score": <int>, "verdict": "<str obligatorio>", "risk": "<str>"},
  "OVER":  {"score": <int>, "verdict": "<str obligatorio>", "risk": "<str>"},
  "UNDER": {"score": <int>, "verdict": "<str obligatorio>", "risk": "<str>"},
  "data_reliability": "<alta|media|baja — y por qué en pocas palabras>",
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
        // Con web search, la respuesta trae varios bloques de texto (antes y
        // después de buscar). Nos quedamos con el ÚLTIMO bloque de texto, que es
        // donde va el JSON final. Concatenarlos todos rompía el parseo del verdict.
        $textos = [];
        foreach (($json['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text' && trim($block['text']) !== '') {
                $textos[] = $block['text'];
            }
        }
        if (!$textos) return '';
        // buscamos, de atrás hacia delante, el primer bloque que contenga un JSON
        for ($i = count($textos) - 1; $i >= 0; $i--) {
            if (strpos($textos[$i], '{') !== false && strpos($textos[$i], '"score"') !== false) {
                return $textos[$i];
            }
        }
        // si ninguno tiene el JSON claro, devolvemos el último (mejor que concatenar)
        return end($textos);
    }

    private function parseJson(string $text): array
    {
        $vacio = ['BTTS'=>[],'OVER'=>[],'UNDER'=>[]];
        $text = trim($text);
        $text = preg_replace('/```(?:json)?/i', '', $text) ?? $text;
        $text = str_replace('```', '', $text);

        // Intento 1: el JSON completo (primer { al último }).
        $start = strpos($text, '{'); $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $out = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($out) && (isset($out['OVER']) || isset($out['BTTS']))) return $out;
        }

        // Intento 2: puede haber llaves espurias antes del JSON real. Buscamos el
        // primer '{' que va seguido (más adelante) de '"BTTS"' o '"OVER"', y
        // probamos a decodificar desde ahí hasta el último '}'.
        if ($end !== false) {
            $pos = 0;
            while (($p = strpos($text, '{', $pos)) !== false) {
                $cand = substr($text, $p, $end - $p + 1);
                if (strpos($cand, '"BTTS"') !== false || strpos($cand, '"OVER"') !== false) {
                    $out = json_decode($cand, true);
                    if (is_array($out) && (isset($out['OVER']) || isset($out['BTTS']))) return $out;
                }
                $pos = $p + 1;
            }
        }
        return $vacio;
    }
}
