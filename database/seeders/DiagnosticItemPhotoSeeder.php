<?php

namespace Database\Seeders;

use App\Enums\Severity;
use App\Models\DiagnosticItem;
use App\Models\DiagnosticItemPhoto;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Fotos de ejemplo en los ítems de diagnóstico de severidad alta o crítica (los
 * que en la práctica se documentan con fotos).
 */
class DiagnosticItemPhotoSeeder extends Seeder
{
    public function run(): void
    {
        $items = DiagnosticItem::whereIn('severity', [Severity::HIGH->value, Severity::CRITICAL->value])->get();

        $assetsPath = database_path(
            'seeders/assets/diagnostic-items'
        );

        $images = [
            [
                'file' => 'diagnostic-example-1.jpg',
                'description' => 'Vista general del componente con desgaste visible.',
            ],
            [
                'file' => 'diagnostic-example-2.jpg',
                'description' => 'Detalle del componente que requiere revisión.',
            ],
        ];

        foreach ($images as $image) {

            $sourcePath = $assetsPath . DIRECTORY_SEPARATOR . $image['file'];

            foreach ($items as $item) {

                $destinationPath = 'diagnostic-items/'
                    . $item->id
                    . '/'
                    . $image['file'];

                Storage::disk('public')->put(
                    $destinationPath,
                    File::get($sourcePath)
                );

                DiagnosticItemPhoto::updateOrCreate(
                    [
                        'diagnostic_item_id' => $item->id,
                        'original_name' => $image['file'],
                    ],
                    [
                        'path' => $destinationPath,
                        'description' => $image['description'],
                    ]
                );
            }
        }
    }
}
