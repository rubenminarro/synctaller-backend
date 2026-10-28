<?php

namespace App\Enums;

/**
 * Series de códigos internos (no fiscales). El valor es la clave en
 * document_sequences.
 */
enum DocumentSeries: string
{
    case BUDGET = 'budget';
    case WORK_ORDER = 'work_order';
    case PURCHASE = 'purchase';
    case PART = 'part';
    case SERVICE = 'service';
    case DIAGNOSTIC_ITEM_TEMPLATE = 'diagnostic_item_template';

    public function prefix(): string
    {
        return match($this) {
            self::BUDGET => 'BUD-',
            self::WORK_ORDER => 'OT-',
            self::PURCHASE => 'COM-',
            self::PART => 'REP-',
            self::SERVICE => 'SRV-',
            self::DIAGNOSTIC_ITEM_TEMPLATE => 'DIA-',
        };
    }

    /**
     * Tabla de los documentos de la serie (columna única `code`).
     */
    public function table(): string
    {
        return match($this) {
            self::BUDGET => 'budgets',
            self::WORK_ORDER => 'work_orders',
            self::PURCHASE => 'purchases',
            self::PART => 'parts',
            self::SERVICE => 'services',
            self::DIAGNOSTIC_ITEM_TEMPLATE => 'diagnostic_item_templates',
        };
    }
}
