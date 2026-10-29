<?php

namespace App\Enums;

/**
 * Tipo de documento de un siniestro.
 */
enum ClaimDocumentType: string
{
    case LOSS_REPORT = 'loss_report';
    case POLICY = 'policy';
    case ADJUSTER_REPORT = 'adjuster_report';
    case AUTHORIZATION = 'authorization';
    case PHOTO = 'photo';
    case OTHER = 'other';

    public function label(): string {
        return match($this) {
            self::LOSS_REPORT => 'Denuncia',
            self::POLICY => 'Póliza',
            self::ADJUSTER_REPORT => 'Informe del perito',
            self::AUTHORIZATION => 'Autorización',
            self::PHOTO => 'Foto',
            self::OTHER => 'Otro',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
