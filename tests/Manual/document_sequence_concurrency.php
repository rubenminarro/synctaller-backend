<?php

/**
 * Prueba manual de concurrencia de los códigos por serie (E3), contra MySQL.
 * SQLite no reproduce el bloqueo de filas, por eso no está en la suite.
 *
 *   php tests/Manual/document_sequence_concurrency.php [procesos] [códigos por proceso]
 *
 * Lanza N procesos que piden códigos de la serie SRV- al mismo tiempo, cada uno
 * en su transacción y reteniendo el bloqueo unos milisegundos. Verifica que no
 * se repita ningún código ni haya errores. No crea servicios (los modelos del
 * catálogo se auditan y audit_logs no se borra): solo mueve el contador, que al
 * final vuelve a su estado original. No correrlo mientras alguien da de alta
 * servicios en la misma base.
 */

use App\Enums\DocumentSeries;
use App\Services\DocumentSequenceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$series = DocumentSeries::SERVICE;

if (($argv[1] ?? null) === '--child') {
    $count = (int) $argv[2];

    for ($i = 0; $i < $count; $i++) {
        try {
            echo DB::transaction(function () use ($series) {
                $code = app(DocumentSequenceService::class)->next($series);
                usleep(random_int(1000, 5000));

                return $code;
            }), PHP_EOL;
        } catch (Throwable $e) {
            echo 'ERROR ', get_class($e), ': ', $e->getMessage(), PHP_EOL;
        }
    }

    exit(0);
}

if (DB::connection()->getDriverName() !== 'mysql') {
    fwrite(STDERR, "Esta prueba es para MySQL (conexión actual: " . DB::connection()->getDriverName() . ").\n");
    exit(1);
}

$processes = (int) ($argv[1] ?? 8);
$perProcess = (int) ($argv[2] ?? 25);

$original = DB::table('document_sequences')->where('key', $series->value)->first();

echo "Serie {$series->value}: " . ($original ? "next_value {$original->next_value}" : 'sin fila (se prueba también el primer uso)') . PHP_EOL;
echo "{$processes} procesos × {$perProcess} códigos" . PHP_EOL;

$handles = [];

for ($p = 0; $p < $processes; $p++) {
    $handles[] = proc_open(
        [PHP_BINARY, __FILE__, '--child', (string) $perProcess],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    ) ?: throw new RuntimeException('No se pudo lanzar el proceso.');
    $outputs[] = $pipes;
}

$codes = [];
$errors = [];

foreach ($handles as $p => $handle) {
    $lines = array_filter(explode(PHP_EOL, stream_get_contents($outputs[$p][1])));
    $stderr = trim(stream_get_contents($outputs[$p][2]));
    proc_close($handle);

    foreach ($lines as $line) {
        str_starts_with($line, 'ERROR') ? $errors[] = $line : $codes[] = trim($line);
    }

    if ($stderr !== '') {
        $errors[] = $stderr;
    }
}

$duplicates = array_keys(array_filter(array_count_values($codes), fn (int $n) => $n > 1));

// Restaurar el contador.
if ($original === null) {
    DB::table('document_sequences')->where('key', $series->value)->delete();
} else {
    DB::table('document_sequences')->where('key', $series->value)->update(['next_value' => $original->next_value]);
}

echo 'Códigos obtenidos: ' . count($codes) . ' (esperados ' . ($processes * $perProcess) . ')' . PHP_EOL;
echo 'Repetidos: ' . count($duplicates) . ($duplicates ? ' → ' . implode(', ', array_slice($duplicates, 0, 10)) : '') . PHP_EOL;
echo 'Errores: ' . count($errors) . PHP_EOL;

foreach (array_slice($errors, 0, 5) as $error) {
    echo "  {$error}" . PHP_EOL;
}

echo 'Contador restaurado.' . PHP_EOL;

$ok = !$duplicates && !$errors && count($codes) === $processes * $perProcess;
echo $ok ? 'OK' . PHP_EOL : 'FALLA' . PHP_EOL;

exit($ok ? 0 : 1);
