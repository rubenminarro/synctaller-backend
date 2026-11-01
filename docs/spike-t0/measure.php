<?php
// Paso 0: intercala las variantes al azar en cada ciclo; así el ruido las afecta a todas por igual.
[$script, $dir, $cycles] = [$argv[0], $argv[1], (int) $argv[2]];

$tokens = ['base' => trim(file_get_contents("$dir/token-base.txt")), 'spike' => trim(file_get_contents("$dir/token-spike.txt"))];
$variants = ['base' => [8091, 'base'], 'persist' => [8092, 'spike'], 'init' => [8093, 'spike']];
$endpoints = [
    'me' => ['/api/me', true],
    'work-orders' => ['/api/work-orders', true],
    'work-order' => ['/api/work-orders/21', true],
    'sales' => ['/api/reports/sales', true],
    'branding' => ['/api/branding', false],
];

function call(int $port, string $path, ?string $token, array &$headers): array
{
    $headers = [];
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_FRESH_CONNECT => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_filter(['Host: localhost', 'Accept: application/json', $token ? "Authorization: Bearer $token" : null]),
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers) {
            if (stripos($line, 'Server-Timing:') === 0) {
                $headers['timing'] = trim(substr($line, 14));
            }

            return strlen($line);
        },
    ]);
    curl_exec($ch);
    $result = [curl_getinfo($ch, CURLINFO_HTTP_CODE), curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000];
    curl_close($ch);

    return $result;
}

$out = fopen("$dir/samples.jsonl", 'w');

for ($cycle = -10; $cycle < $cycles; $cycle++) {
    foreach ($endpoints as $name => [$path, $auth]) {
        $order = array_keys($variants);
        shuffle($order);

        foreach ($order as $variant) {
            [$port, $tokenKey] = $variants[$variant];
            $headers = [];
            [$status, $ms] = call($port, $path, $auth ? $tokens[$tokenKey] : null, $headers);

            if ($cycle >= 0) {
                fwrite($out, json_encode(compact('cycle', 'name', 'variant', 'status', 'ms') + ['timing' => $headers['timing'] ?? null]) . "\n");
            }
        }
    }

    if ($cycle >= 0 && $cycle % 50 === 0) {
        echo "ciclo $cycle " . date('H:i:s') . "\n";
    }
}

echo "fin\n";
