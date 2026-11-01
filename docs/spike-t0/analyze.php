<?php
$dir = $argv[1];

function pct(array $v, float $p): float
{
    sort($v);
    $i = ($p / 100) * (count($v) - 1);
    $lo = (int) floor($i);
    $hi = (int) ceil($i);

    return $v[$lo] + ($v[$hi] - $v[$lo]) * ($i - $lo);
}

$byCycle = $times = $stages = $bad = [];

foreach (file("$dir/samples.jsonl") as $line) {
    $s = json_decode($line, true);

    if ($s['status'] !== 200) {
        $bad["{$s['variant']} {$s['name']} {$s['status']}"] = ($bad["{$s['variant']} {$s['name']} {$s['status']}"] ?? 0) + 1;
        continue;
    }

    $times[$s['name']][$s['variant']][] = $s['ms'];
    $byCycle[$s['name']][$s['cycle']][$s['variant']] = $s['ms'];

    if ($s['timing']) {
        $total = 0;

        foreach (explode(', ', $s['timing']) as $part) {
            [$stage, $dur] = explode(';dur=', $part);
            $stages[$s['variant']][$stage][] = (float) $dur;
            $total += (float) $dur;
        }

        $stages[$s['variant']]['TOTAL'][] = $total;
    }
}

echo "errores: " . json_encode($bad) . "\n\n== Total por endpoint (ms): p50 / p95 / n\n";

foreach ($times as $name => $variants) {
    printf("%-12s", $name);

    foreach (['base', 'init', 'persist'] as $v) {
        printf(" | %-6s %6.1f /%7.1f n=%d", $v, pct($variants[$v], 50), pct($variants[$v], 95), count($variants[$v]));
    }

    echo "\n";
}

echo "\n== Sobrecarga pareada contra base, por ciclo (ms): mediana / p95 / IC95 de la mediana\n";

foreach ($byCycle as $name => $cycles) {
    printf("%-12s", $name);

    foreach (['init', 'persist'] as $v) {
        $d = [];

        foreach ($cycles as $c) {
            if (isset($c['base'], $c[$v])) {
                $d[] = $c[$v] - $c['base'];
            }
        }

        sort($d);
        $n = count($d);
        $k = (int) floor(1.96 * sqrt($n) / 2);
        $m = (int) floor($n / 2);
        printf(" | %-6s %+6.1f /%+7.1f [%+.1f,%+.1f]", $v, pct($d, 50), pct($d, 95), $d[max(0, $m - $k)], $d[min($n - 1, $m + $k)]);
    }

    echo "\n";
}

echo "\n== Etapas dentro del proceso (ms): p50 / p95\n";

foreach ($stages as $variant => $list) {
    echo "[$variant]\n";

    foreach ($list as $stage => $values) {
        printf("  %-34s %7.2f / %7.2f\n", $stage, pct($values, 50), pct($values, 95));
    }
}

echo "\n== Sobrecarga como diferencia de p50 y de p95 contra base (ms)\n";

foreach ($times as $name => $variants) {
    printf("%-12s", $name);

    foreach (['init', 'persist'] as $v) {
        printf(" | %-7s p50 %+6.1f  p95 %+6.1f", $v, pct($variants[$v], 50) - pct($variants['base'], 50), pct($variants[$v], 95) - pct($variants['base'], 95));
    }

    echo "\n";
}
