<?php

/**
 * Auditoría de la documentación, de solo lectura. Correr desde la raíz del repositorio:
 *   php .claude/skills/validar-docs/scripts/check-docs.php [--detalle]
 * Sale con 1 si encuentra algo. --detalle lista las cifras excluidas de la comparación.
 * Pruebas de regresión: php .claude/skills/validar-docs/scripts/check-docs-test.php
 */

require __DIR__ . '/figures.php';

if (! is_file('artisan') || ! is_dir('docs')) {
    fwrite(STDERR, "Correr desde la raíz del repositorio.\n");
    exit(2);
}

const LIVE = ['CLAUDE.md', 'README.md', 'docs/README.md', 'docs/handoff.md', 'docs/project', 'docs/modules', 'docs/roadmap/README.md', 'docs/development', 'docs/decisions/README.md'];

// Rutas que viven en ../synctaller-frontend, no en este repositorio.
const FRONTEND_PATHS = ['docs/plan-desarrollo.md'];

$findings = [];

function report(string $check, string $where, string $detail): void
{
    global $findings;
    $findings[$check][] = "$where: $detail";
}

function files(string $dir, string $pattern): array
{
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = str_replace('\\', '/', $file->getPathname());

        if (preg_match($pattern, $path)) {
            $found[] = $path;
        }
    }

    sort($found);

    return $found;
}

function live(): array
{
    $found = [];

    foreach (LIVE as $path) {
        $found = array_merge($found, is_dir($path) ? files($path, '/\.md$/') : [$path]);
    }

    return $found;
}

function normalize(string $path): string
{
    $parts = [];

    foreach (explode('/', $path) as $part) {
        if ($part === '..') {
            array_pop($parts);
        } elseif ($part !== '.' && $part !== '') {
            $parts[] = $part;
        }
    }

    return implode('/', $parts);
}

function slug(string $heading): string
{
    $text = mb_strtolower(trim(str_replace('`', '', $heading)));
    $text = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $text);

    return preg_replace('/\s/u', '-', $text);
}

function anchors(string $file): array
{
    preg_match_all('/^#{1,6}\s+(.+?)\s*$/m', str_replace("\r", '', file_get_contents($file)), $matches);

    return array_map('slug', $matches[1]);
}

function withoutCode(string $text): string
{
    return preg_replace('/```.*?```/s', '', str_replace("\r", '', $text));
}

// Enlaces y anclas
$docs = files('docs', '/\.(md|html)$/');
$sources = array_merge($docs, ['CLAUDE.md', 'README.md']);
$graph = [];
$total = 0;

foreach ($sources as $file) {
    $text = withoutCode(file_get_contents($file));
    $pattern = str_ends_with($file, '.md') ? '/\]\(([^)\s]+)\)/' : '/href="([^"]+)"/';
    preg_match_all($pattern, $text, $matches);
    $graph[$file] = [];

    foreach ($matches[1] as $link) {
        if (preg_match('/^(https?:|mailto:)/', $link)) {
            continue;
        }

        $total++;
        [$path, $anchor] = array_pad(explode('#', $link, 2), 2, null);
        $target = $path === '' ? $file : normalize(dirname($file) . '/' . $path);

        if (! file_exists($target)) {
            report('Enlaces rotos', $file, $link);
            continue;
        }

        if ($anchor && str_ends_with($target, '.md') && ! in_array(rawurldecode($anchor), anchors($target), true)) {
            report('Anclas rotas', $file, $link);
        }

        $graph[$file][] = $target;
    }
}

// Documentos huérfanos
$seen = ['docs/README.md' => true];
$queue = ['docs/README.md'];

while ($queue) {
    foreach ($graph[array_shift($queue)] ?? [] as $target) {
        if (str_starts_with($target, 'docs/') && ! isset($seen[$target])) {
            $seen[$target] = true;
            $queue[] = $target;
        }
    }
}

foreach ($docs as $file) {
    if (! isset($seen[$file])) {
        report('Documentos huérfanos', $file, 'no se alcanza desde docs/README.md');
    }
}

// Rutas citadas en documentos vivos
foreach (live() as $file) {
    preg_match_all('/`((?:docs|app|tests|config|routes|database)\/[\w.\/-]+\.\w+)`/', file_get_contents($file), $matches);

    foreach (array_unique($matches[1]) as $path) {
        if (! file_exists($path) && ! in_array($path, FRONTEND_PATHS, true)) {
            report('Rutas inexistentes', $file, $path);
        }
    }
}

// Cifras contra el código
$tests = [];

foreach (array_merge(files('tests/Feature', '/Test\.php$/'), files('tests/Unit', '/Test\.php$/')) as $file) {
    $tests[basename($file, 'Test.php')] = preg_match_all('/function test_|#\[Test\]/', file_get_contents($file));
}

$routes = json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' artisan route:list --path=api --json 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null')), true);
$actual = [
    'tests' => array_sum($tests),
    'rutas' => is_array($routes) ? count($routes) : null,
    'controllers' => count(glob('app/Http/Controllers/Api/*.php')),
    'services' => count(glob('app/Services/*.php')),
];

$compared = 0;
$excluded = [];

foreach (live() as $file) {
    $inFence = false;

    foreach (preg_split('/\R/', file_get_contents($file)) as $number => $line) {
        if (str_starts_with(ltrim($line), '```')) {
            $inFence = ! $inFence;
            continue;
        }

        preg_match_all('/\b(\d+) (tests|rutas|controllers|services)\b/u', $line, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            [$text, $offset] = $match[0];
            $count = (int) $match[1][0];
            $kind = $match[2][0];
            $where = $file . ':' . ($number + 1);
            $figure = classifyFigure($line, $offset, strlen($text), $inFence);

            if ($figure['type'] === 'current') {
                $compared++;

                if ($actual[$kind] !== null && $count !== $actual[$kind]) {
                    report('Cifras', $where, "dice $count $kind; el código tiene {$actual[$kind]}");
                }
            } elseif ($figure['type'] === 'class' && $kind === 'tests') {
                $compared++;

                if (! isset($tests[$figure['class']])) {
                    report('Cifras', $where, "cita {$figure['class']}Test, que no existe");
                } elseif ($tests[$figure['class']] !== $count) {
                    report('Cifras', $where, "{$figure['class']}Test tiene {$tests[$figure['class']]} tests, no $count");
                }
            } else {
                $excluded[] = "{$figure['type']}: $where: $text";
            }
        }
    }
}

foreach (files('docs/modules', '/\.md$/') as $file) {
    preg_match_all('/`([A-Z]\w+)` (\d+)\b/', file_get_contents($file), $matches, PREG_SET_ORDER);

    foreach ($matches as [, $class, $count]) {
        if (! isset($tests[$class])) {
            report('Cifras', $file, "cita {$class}Test, que no existe");
        } elseif ($tests[$class] !== (int) $count) {
            report('Cifras', $file, "{$class}Test tiene {$tests[$class]} tests, no $count");
        }
    }
}

// Resultado
printf("Documentos: %d. Enlaces internos: %d. Alcanzables desde el índice: %d.\n", count($docs), $total, count($seen));
printf("Código: %d tests, %s rutas, %d controllers, %d services.\n", $actual['tests'], $actual['rutas'] ?? 'sin dato de', $actual['controllers'], $actual['services']);
printf("Cifras comparadas: %d. Excluidas por una marca explícita: %d.\n\n", $compared, count($excluded));

if (in_array('--detalle', $argv, true)) {
    foreach ($excluded as $item) {
        echo "  excluida: $item\n";
    }

    echo "\n";
}

if ($actual['rutas'] === null) {
    echo "Aviso: no se pudieron contar las rutas.\n\n";
}

if (! $findings) {
    echo "Sin hallazgos.\n";
    exit(0);
}

foreach ($findings as $check => $items) {
    echo "$check (" . count($items) . ")\n";

    foreach ($items as $item) {
        echo "  - $item\n";
    }
}

exit(1);
