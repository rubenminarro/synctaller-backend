<?php

namespace App\Http\Requests;

use App\Enums\ClaimDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * Subida de un documento de siniestro (decisión E). Se validan la extensión del
 * nombre, el tipo MIME real del contenido y el tamaño. Solo PDF, JPG, PNG y
 * WEBP: SVG y HTML quedan fuera (podrían ejecutar código al abrirse). Límite
 * provisorio de 10 MB (V8).
 */
class UploadClaimDocumentRequest extends FormRequest
{
    public const MAX_KB = 10240;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'extensions:pdf,jpg,jpeg,png,webp',
                'mimetypes:application/pdf,image/jpeg,image/png,image/webp',
                'max:' . self::MAX_KB,
            ],
            'type' => ['required', Rule::enum(ClaimDocumentType::class)],
            'notes' => ['nullable', 'string', 'max:500', 'regex:/^[\pL\pN\s.,;:()\-#@!?%\/°º&\'"]*$/u'],
        ];
    }

    /**
     * La extensión del nombre tiene que corresponder al contenido: un PNG llamado .pdf (o al revés) se rechaza.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $file = $this->file('file');

                if ($validator->errors()->has('file') || !$file) {
                    return;
                }

                $expected = match (strtolower($file->getClientOriginalExtension())) {
                    'pdf' => 'application/pdf',
                    'jpg', 'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'webp' => 'image/webp',
                    default => null,
                };

                if ($expected !== $file->getMimeType()) {
                    $validator->errors()->add('file', 'La extensión del archivo no coincide con su contenido.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'file' => [
                'required' => 'El archivo es obligatorio.',
                'file' => 'El archivo no se recibió correctamente.',
                'extensions' => 'Solo se aceptan archivos PDF, JPG, PNG o WEBP.',
                'mimetypes' => 'El contenido del archivo no es un PDF, JPG, PNG o WEBP válido.',
                'max' => 'El archivo no debe superar los 10 MB.',
            ],
            'type' => [
                'required' => 'El tipo de documento es obligatorio.',
                Enum::class => 'El tipo debe ser loss_report, policy, adjuster_report, authorization, photo u other.',
            ],
            'notes' => [
                'string' => 'Las notas deben ser un texto válido.',
                'max' => 'Las notas no deben superar los 500 caracteres.',
                'regex' => 'Las notas contienen caracteres no permitidos.',
            ],
        ];
    }
}
