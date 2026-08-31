<?php
declare(strict_types=1);
/**
 * Diagnóstico ampliado: muestra la respuesta CRUDA de la IA (sin parsear),
 * para ver qué formato está rompiendo el parseo.
 *   GET /debug_ia.php
 * BORRAR tras diagnosticar.
 */
require __DIR__ . '/../src/Db.php';
use SignalPitch\Db;

header('Content-Type: text/plain; charset=utf-8');
$cfg = require __DIR__ . '/../config/config.php';
set_time_limit(120);

// Construimos la llamada a la API igual que ReasoningScorer, pero mostramos la respuesta cruda
$claude = $cfg['claude'];

$system = "Eres un analista de fútbol. Para cada mercado (BTTS, OVER, UNDER) da un score 0-100 y un verdict de una frase. Responde SOLO con JSON: {\"BTTS\":{\"score\":50,\"verdict\":\"...\"},\"OVER\":{\"score\":50,\"verdict\":\"...\"},\"UNDER\":{\"score\":50,\"verdict\":\"...\"}}";
$user = "Analiza Aston Villa vs Arsenal. Villa marca 1.2 encaja 1.8. Arsenal marca 2.2 encaja 0.75.";

$body = [
    'model' => $claude['model_top'] ?? 'claude-sonnet-4-6',
    'max_tokens' => 1500,
    'system' => $system,
    'messages' => [['role'=>'user','content'=>$user]],
    'tools' => [['type'=>'web_search_20250305','name'=>'web_search']],
];

$ch = curl_init($claude['base']);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 90,
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . $claude['key'],
        'anthropic-version: ' . $claude['version'],
    ],
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "=== HTTP $code ===\n\n";
$json = json_decode($resp, true);

echo "=== TIPOS DE BLOQUES EN LA RESPUESTA ===\n";
foreach (($json['content'] ?? []) as $i => $block) {
    $t = $block['type'] ?? '?';
    $preview = '';
    if ($t === 'text') $preview = substr($block['text'], 0, 500);
    echo "[$i] type=$t".($preview ? "\n    texto: $preview" : '')."\n\n";
}

echo "\n=== MODELO CONFIGURADO ===\n";
echo "model_top: ".($claude['model_top'] ?? 'NO DEFINIDO')."\n";
echo "model (haiku): ".($claude['model'] ?? 'NO DEFINIDO')."\n";
echo "base: ".($claude['base'] ?? 'NO DEFINIDO')."\n";
echo "version: ".($claude['version'] ?? 'NO DEFINIDO')."\n";
