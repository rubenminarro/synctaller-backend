<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Importes del presupuesto de decimal(10,2) (tope 99.999.999,99 Gs.) a
 * decimal(14,2), como las facturas. La cantidad del ítem queda en (10,2).
 */
return new class extends Migration
{
    private const BUDGET_COLUMNS = ['subtotal', 'tax', 'total'];

    private const ITEM_COLUMNS = ['unit_price', 'total'];

    public function up(): void
    {
        $this->resize(14);
    }

    /**
     * Volver a (10,2) truncaría o fallaría a medias si ya hay importes mayores:
     * se detiene antes de tocar nada.
     */
    public function down(): void
    {
        $limit = '99999999.99';

        $tooLarge = DB::table('budgets')->where(fn ($q) => $this->anyAbove($q, self::BUDGET_COLUMNS, $limit))->count()
            + DB::table('budget_items')->where(fn ($q) => $this->anyAbove($q, self::ITEM_COLUMNS, $limit))->count();

        if ($tooLarge > 0) {
            throw new RuntimeException(
                "No se puede revertir: {$tooLarge} presupuesto(s) o ítem(s) superan 99.999.999,99 y no entran en decimal(10,2)."
            );
        }

        $this->resize(10);
    }

    private function resize(int $precision): void
    {
        Schema::table('budgets', function (Blueprint $table) use ($precision) {
            foreach (self::BUDGET_COLUMNS as $column) {
                $table->decimal($column, $precision, 2)->default(0)->change();
            }
        });

        Schema::table('budget_items', function (Blueprint $table) use ($precision) {
            foreach (self::ITEM_COLUMNS as $column) {
                $table->decimal($column, $precision, 2)->change();
            }
        });
    }

    private function anyAbove($query, array $columns, string $limit): void
    {
        foreach ($columns as $column) {
            $query->orWhere($column, '>', $limit);
        }
    }
};
