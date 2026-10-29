<?php

namespace App\Models;

use App\Enums\InsurerContactRole;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsurerContact extends Model
{
    use Auditable;

    protected $fillable = [
        'insurer_id',
        'name',
        'role',
        'phone',
        'email',
        'is_primary',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'role' => InsurerContactRole::class,
            'is_primary' => 'boolean',
        ];
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }
}
