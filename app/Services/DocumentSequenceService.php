<?php

namespace App\Services;

use App\Enums\DocumentSeries;
use App\Exceptions\AppException;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Códigos internos por serie (BUD-000001…). El contador de la serie se bloquea
 * dentro de la transacción del alta: dos altas simultáneas no calculan el mismo
 * código, y si el alta falla el incremento se revierte. Puede haber huecos (no
 * son documentos fiscales). Orden de bloqueo: documento → serie (la serie,
 * siempre última).
 */
class DocumentSequenceService
{
    /**
     * Crea el documento con el próximo código de la serie. Debe llamarse dentro
     * de una transacción. Un código repetido igual (algo que lo cargó sin pasar
     * por la serie) responde 409 DOCUMENT_CODE_CONFLICT, nunca 500.
     *
     * @template T of Model
     * @param Closure(string): T $create
     * @return T
     */
    public function create(DocumentSeries $series, Closure $create): Model
    {
        $code = $this->next($series);

        try {
            return $create($code);
        } catch (UniqueConstraintViolationException $e) {
            if ($this->isCodeViolation($series, $e)) {
                throw new AppException(
                    "El código {$code} ya está en uso. Intentá de nuevo.",
                    409,
                    'DOCUMENT_CODE_CONFLICT'
                );
            }

            throw $e;
        }
    }

    /**
     * Próximo código libre de la serie. Salta los códigos ya usados (también los
     * de registros eliminados).
     */
    public function next(DocumentSeries $series): string
    {
        $sequence = $this->lockedSequence($series);
        $value = (int) $sequence->next_value;

        do {
            $code = $series->prefix() . str_pad((string) $value, 6, '0', STR_PAD_LEFT);
            $value++;
        } while (DB::table($series->table())->where('code', $code)->exists());

        DB::table('document_sequences')
            ->where('id', $sequence->id)
            ->update(['next_value' => $value, 'updated_at' => now()]);

        return $code;
    }

    /**
     * Fila de la serie bloqueada. En el primer uso se crea con el máximo id + 1,
     * el mismo código que daba el cálculo anterior (continuidad con los datos).
     */
    private function lockedSequence(DocumentSeries $series): object
    {
        $query = fn () => DB::table('document_sequences')
            ->where('key', $series->value)
            ->lockForUpdate()
            ->first();

        $sequence = $query();

        if ($sequence === null) {
            DB::table('document_sequences')->insertOrIgnore([
                'key' => $series->value,
                'next_value' => (int) DB::table($series->table())->max('id') + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = $query();
        }

        return $sequence;
    }

    private function isCodeViolation(DocumentSeries $series, UniqueConstraintViolationException $e): bool
    {
        $table = $series->table();
        $message = $e->getMessage();

        // SQLite: "UNIQUE constraint failed: budgets.code"; MySQL: "... for key 'budgets_code_unique'".
        return str_contains($message, "{$table}.code") || str_contains($message, "{$table}_code_unique");
    }
}
