<?php

namespace App\Models;

use App\Enums\ClaimDocumentType;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Documento privado de un siniestro. Se audita (metadatos, sin la ruta interna)
 * y cada descarga deja un registro `downloaded`.
 */
class ClaimDocument extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'claim_id',
        'type',
        'original_name',
        'path',
        'mime',
        'size',
        'sha256',
        'notes',
        'uploaded_by',
        'deleted_by',
        'deletion_reason',
    ];

    /** La ruta interna del archivo no sale ni en la auditoría. */
    protected array $auditExclude = ['path'];

    protected function casts(): array
    {
        return [
            'type' => ClaimDocumentType::class,
            'size' => 'integer',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }
}
