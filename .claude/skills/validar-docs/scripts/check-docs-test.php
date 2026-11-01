<?php

/**
 * Pruebas de regresión de check-docs.php. No dependen del estado del repositorio: arman un proyecto
 * de ejemplo en una carpeta temporal y corren el validador real sobre él.
 *   php .claude/skills/validar-docs/scripts/check-docs-test.php
 */

require __DIR__ . '/figures.php';

$failures = 0;
$passed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $failures, $passed;

    if ($condition) {
        $passed++;
        echo "  ok    $name\n";
    } else {
        $failures++;
        echo "  FALLA $name\n" . ($detail === '' ? '' : "        $detail\n");
    }
}

function typeOf(string $line): array
{
    preg_match('/\b\d+ (tests|rutas|controllers|services)\b/u', $line, $match, PREG_OFFSET_CAPTURE);

    return classifyFigure($line, $match[0][1], strlen($match[0][0]));
}

echo "Clasificación\n";

foreach ([
    ['current', '| `app/Services` | Lógica de negocio (45 services) |'],
    ['current', '| `app/Services` | Reglas de negocio (47 services, incluido `AuditLogger`) |'],
    ['current', '| `app/Http/Controllers/Api` | 58 controllers |'],
    ['current', 'Contado el 2026-11-01: 489 tests en `tests/Feature`.'],
    ['current', '284 rutas API, 489 tests en verde.'],
    ['current', 'Hay 45 services. Antes de empezar, leer el índice.'],
    ['class', 'Además, `DocumentSequence` (6 tests) cubre los códigos internos.'],
    ['historical', 'Antes: 460 tests con M9.'],
    ['historical', 'La línea base eran 374 tests.'],
    ['historical', 'Hasta el 2026-10-29 había 460 tests.'],
    ['other-project', '| Cada flujo | Solo hay 43 tests del frontend. |'],
    ['current', '| Frontend y backend | 45 services |'],
    ['other-project', 'Frontend: 43 tests.'],
    ['delta', 'Se agregaron 3 tests nuevos.'],
    ['example', 'Por ejemplo, 45 services.'],
    ['example', 'Se escribe `45 services` en la tabla.'],
] as [$expected, $line]) {
    $actual = typeOf($line)['type'];
    check("$expected: $line", $actual === $expected, "obtuvo $actual");
}

check('conserva el nombre de la clase', (typeOf('`Login` (9 tests)')['class'] ?? null) === 'Login');

// Proyecto de ejemplo con 47 services, 58 controllers, 3 rutas y 9 tests en LoginTest.
function fixture(array $live): string
{
    $root = sys_get_temp_dir() . '/check-docs-' . uniqid();

    foreach (['app/Services', 'app/Http/Controllers/Api', 'docs/project', 'docs/modules', 'docs/development', 'docs/roadmap', 'docs/decisions', 'tests/Feature/Auth', 'tests/Unit'] as $dir) {
        mkdir("$root/$dir", 0777, true);
    }

    for ($i = 0; $i < 47; $i++) {
        touch("$root/app/Services/S$i.php");
    }

    for ($i = 0; $i < 58; $i++) {
        touch("$root/app/Http/Controllers/Api/C$i.php");
    }

    file_put_contents("$root/tests/Feature/Auth/LoginTest.php", str_repeat("function test_x() {}\n", 9));
    file_put_contents("$root/artisan", '<?php echo json_encode([1, 2, 3]);');

    $files = ['README.md' => '', 'CLAUDE.md' => '', 'docs/handoff.md' => '', 'docs/roadmap/README.md' => '', 'docs/decisions/README.md' => ''];
    $index = "[a](handoff.md) [b](roadmap/README.md) [c](decisions/README.md)\n";

    foreach ($live + $files as $file => $text) {
        file_put_contents("$root/$file", $text);

        if (str_starts_with($file, 'docs/') && ! in_array($file, ['docs/handoff.md', 'docs/roadmap/README.md', 'docs/decisions/README.md'], true)) {
            $index .= '[x](' . substr($file, 5) . ")\n";
        }
    }

    file_put_contents("$root/docs/README.md", $index);

    return $root;
}

function run(string $root, string $flags = ''): array
{
    $previous = getcwd();
    chdir($root);
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/check-docs.php') . " $flags", $output, $code);
    chdir($previous);

    return [implode("\n", $output), $code];
}

function remove(string $path): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}

echo "\nCaso 1: detecta 45 frente a 47\n";
$root = fixture(['README.md' => "# Título\n| `app/Services` | Lógica de negocio (45 services) |\n", 'CLAUDE.md' => "Reglas (47 services)\n"]);
[$output, $code] = run($root);
check('reporta README.md:2', str_contains($output, 'README.md:2: dice 45 services; el código tiene 47'), $output);
check('no reporta la cifra correcta de CLAUDE.md', ! str_contains($output, 'CLAUDE.md'), $output);
check('sale con 1', $code === 1);
remove($root);

echo "\nCaso 2: cifras por clase\n";
$root = fixture(['docs/modules/a.md' => "`Login` (9 tests) y `Login` 9\n"]);
[$output, $code] = run($root);
check('sin falso positivo con la clase correcta', str_contains($output, 'Sin hallazgos.') && $code === 0, $output);
remove($root);

$root = fixture(['docs/modules/a.md' => "`Login` (8 tests)\n", 'docs/project/b.md' => "`Fantasma` (2 tests)\n"]);
[$output] = run($root);
check('detecta una cifra de clase incorrecta', str_contains($output, 'LoginTest tiene 9 tests, no 8'), $output);
check('detecta una clase inexistente', str_contains($output, 'cita FantasmaTest, que no existe'), $output);
remove($root);

echo "\nCaso 3: historia, otros proyectos y ejemplos\n";
$root = fixture(['docs/project/a.md' => implode("\n", [
    'Antes: 460 tests con M9.',
    'La línea base eran 374 tests.',
    'Frontend: 43 tests.',
    'Se agregaron 3 tests nuevos.',
    'Por ejemplo, 45 services.',
    '```',
    '999 tests',
    '```',
]) . "\n"]);
[$output, $code] = run($root, '--detalle');
check('no confunde historia con estado actual', str_contains($output, 'Sin hallazgos.') && $code === 0, $output);
check('informa cuántas excluyó', str_contains($output, 'Excluidas por una marca explícita: 6.'), $output);
check('--detalle las lista', str_contains($output, 'excluida: historical: docs/project/a.md:1: 460 tests'), $output);
remove($root);

$root = fixture(['docs/project/a.md' => "Hay 460 tests.\nAntes: 460 tests. Hoy hay 450 tests.\n"]);
[$output] = run($root);
check('sin marca, la misma cifra sí se reporta', str_contains($output, 'a.md:1: dice 460 tests'), $output);
check('la marca solo cubre su cláusula', str_contains($output, 'a.md:2: dice 450 tests') && ! str_contains($output, 'a.md:2: dice 460 tests'), $output);
remove($root);

$root = fixture(['docs/project/a.md' => "| Frontend y backend | 45 services |\n"]);
[$output] = run($root);
check('una marca en otra celda no excluye la cifra', str_contains($output, 'a.md:1: dice 45 services'), $output);
remove($root);

printf("\n%d correctas, %d fallidas.\n", $passed, $failures);
exit($failures === 0 ? 0 : 1);
