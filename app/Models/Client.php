<?php

namespace App\Models;

use App\Enums\ClientKind;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'kind',
        'document_number',
        'first_name',
        'last_name',
        'business_name',
        'phone',
        'email',
        'address',
        'notifications_enabled',
        'notes',
    ];

    protected $attributes = [
        'kind' => 'person',
        'notifications_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'kind' => ClientKind::class,
            'notifications_enabled' => 'boolean',
        ];
    }

    /**
     * Nombre para mostrar: "nombre apellido" (el mismo texto de siempre) o la razón social de una empresa.
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn () => $this->kind === ClientKind::COMPANY
            ? (string) $this->business_name
            : trim("{$this->first_name} {$this->last_name}"));
    }

    /**
     * Nombre del saludo de los avisos: el nombre de pila de una persona, la razón social de una empresa.
     */
    protected function greetingName(): Attribute
    {
        return Attribute::get(fn () => $this->kind === ClientKind::COMPANY
            ? (string) $this->business_name
            : (string) $this->first_name);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function receptions(): HasMany
    {
        return $this->hasMany(Reception::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function clientNotifications(): HasMany
    {
        return $this->hasMany(ClientNotification::class);
    }
}
