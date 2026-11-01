<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Support\WorkshopSettings;
use Illuminate\Support\Facades\DB;

/**
 * Configuración del taller guardada en system_settings. Se aplica sobre config()
 * en cada request, job y tarea programada, así que el resto de la aplicación
 * sigue leyendo config() como siempre. Es un singleton: en un worker recuerda
 * el valor original de lo que reemplazó, para volver a él si se borra.
 */
class WorkshopSettingService
{
    /**
     * @var array<string, mixed> valor original (.env) de cada clave reemplazada
     */
    private array $originals = [];

    public function apply(): void
    {
        $stored = SystemSetting::whereIn('key', WorkshopSettings::FIELDS)->pluck('value', 'key');

        foreach (WorkshopSettings::FIELDS as $field => $key) {
            if ($stored->has($key)) {
                if (!array_key_exists($key, $this->originals)) {
                    $this->originals[$key] = config($key);
                }

                config()->set($key, $this->cast($field, $stored[$key]));
            } elseif (array_key_exists($key, $this->originals)) {
                config()->set($key, $this->originals[$key]);
                unset($this->originals[$key]);
            }
        }
    }

    /**
     * Valores vigentes, por campo.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_map(fn (string $key) => config($key), WorkshopSettings::FIELDS);
    }

    /**
     * Guarda los campos recibidos; null borra el guardado y vuelve al .env.
     *
     * @return array{settings: array<string, mixed>, warnings: string[]}
     */
    public function update(array $data): array
    {
        $previousRate = (int) config('invoice.default_tax_rate');

        DB::transaction(function () use ($data) {
            foreach ($data as $field => $value) {
                $key = WorkshopSettings::FIELDS[$field];

                if ($value === null) {
                    SystemSetting::where('key', $key)->first()?->delete();
                } else {
                    SystemSetting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
                }
            }
        });

        $this->apply();

        $warnings = (int) config('invoice.default_tax_rate') === $previousRate ? [] : [
            'La tasa de IVA nueva se aplica a los cálculos desde ahora, también en los documentos en curso: un presupuesto '
            . 'ya aprobado se facturará con esta tasa. Lo ya emitido no cambia.',
        ];

        return ['settings' => $this->all(), 'warnings' => $warnings];
    }

    /**
     * ¿Es la hora de los recordatorios? Se evalúa al ejecutar el scheduler, no al
     * arrancar: routes/console.php corre en todo comando, incluso sin base migrada.
     */
    public function remindersDue(): bool
    {
        $this->apply();

        return now()->format('H:i') === config('notifications.reminders_at');
    }

    private function cast(string $field, string $value): int|string
    {
        return in_array($field, WorkshopSettings::INTEGERS, true) ? (int) $value : $value;
    }
}
