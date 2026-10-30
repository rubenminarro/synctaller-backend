<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Modalidad neta del deducible (M10)
    |--------------------------------------------------------------------------
    |
    | Habilita facturar siniestros con deducible mayor a 0 con la modalidad
    | neta: la aseguradora recibe lo cubierto menos el deducible y el dueño, el
    | deducible en una línea propia. Apagada por defecto (decisión del usuario
    | del 2026-10-30): la modalidad la confirmó un dueño de taller que lleva la
    | contabilidad, y se enciende en producción con la confirmación escrita de
    | un contador (V21). Apagada, rige la regla A: con deducible mayor a 0 no
    | se factura ni se entrega, aunque el siniestro tenga la modalidad cargada.
    |
    */

    'deductible_net_mode' => (bool) env('INSURER_DEDUCTIBLE_NET_MODE', false),

];
