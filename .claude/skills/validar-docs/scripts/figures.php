<?php

/**
 * Clasifica una cifra como "45 services" dentro de una línea. La regla es que toda cifra describe el
 * estado actual y se compara con el código, salvo una marca explícita en su misma cláusula. Ante la
 * duda devuelve 'current': es preferible un falso positivo a ocultar un error.
 *
 * Tipos: current, class (cifra de una clase de test), other-project, historical, delta, example.
 *
 * @return array{type: string, class?: string}
 */
function classifyFigure(string $line, int $offset, int $length, bool $inFence = false): array
{
    $before = substr($line, 0, $offset);
    $after = substr($line, $offset + $length);

    if ($inFence || substr_count($before, '`') % 2 === 1) {
        return ['type' => 'example'];
    }

    if (preg_match('/`([A-Z]\w*)`\s*\(\s*$/', $before, $match)) {
        return ['type' => 'class', 'class' => $match[1]];
    }

    if (preg_match('/^\s*(nuev|adicional|más\b)/iu', $after) || preg_match('/\+\s*$/', $before)) {
        return ['type' => 'delta'];
    }

    $clause = figureClause($before, $after);

    if (preg_match('/\b(frontend|front)\b/iu', $clause)) {
        return ['type' => 'other-project'];
    }

    if (preg_match('/\b(antes|anteriores?|hasta el|hasta la|l[ií]nea base|era|eran|hab[ií]a|ten[ií]a)\b/iu', $clause)) {
        return ['type' => 'historical'];
    }

    if (preg_match('/\b(ejemplo|p\. ?ej\.?|supongamos)\b/iu', $clause)) {
        return ['type' => 'example'];
    }

    return ['type' => 'current'];
}

/**
 * La cláusula termina en una celda de tabla, un punto y coma o el fin de una oración.
 */
function figureClause(string $before, string $after): string
{
    $start = preg_split('/\||;|\.\s/u', $before);
    $end = preg_split('/\||;|\.\s/u', $after);

    return end($start) . ' ' . $end[0];
}
