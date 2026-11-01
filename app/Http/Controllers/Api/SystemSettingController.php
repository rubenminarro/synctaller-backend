<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadSystemFaviconRequest;
use App\Http\Requests\UpdateWorkshopSettingsRequest;
use App\Http\Requests\UploadSystemLogoRequest;
use App\Services\BrandingService;
use App\Services\WorkshopSettingService;
use App\Traits\ApiResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Configuración general del sistema: el logo, de lectura pública (branding, la
 * usa el login), y los datos del taller. Los cambia solo el administrador.
 */
class SystemSettingController extends Controller implements HasMiddleware
{
    use ApiResponse;

    public function __construct(
        private readonly BrandingService $brandingService,
        private readonly WorkshopSettingService $workshopSettingService
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:system-settings.update', only: ['uploadLogo', 'deleteLogo', 'uploadFavicon', 'deleteFavicon', 'workshop', 'updateWorkshop']),
        ];
    }

    public function branding()
    {
        return $this->successResponse(
            'Identidad del sistema obtenida correctamente.',
            $this->brandingService->publicData(),
            200
        );
    }

    public function workshop()
    {
        return $this->successResponse(
            'Configuración del taller obtenida correctamente.',
            $this->workshopSettingService->all(),
            200
        );
    }

    public function updateWorkshop(UpdateWorkshopSettingsRequest $request)
    {
        $result = $this->workshopSettingService->update($request->validated());

        return $this->successResponse(
            'Configuración del taller actualizada correctamente.',
            $result['settings'],
            200,
            $result['warnings'] === [] ? null : ['warnings' => $result['warnings']]
        );
    }

    public function uploadLogo(UploadSystemLogoRequest $request)
    {
        return $this->successResponse(
            'Logo actualizado correctamente.',
            $this->brandingService->storeLogo($request->file('logo')),
            200
        );
    }

    public function deleteLogo()
    {
        return $this->successResponse(
            'Logo eliminado correctamente.',
            $this->brandingService->deleteLogo(),
            200
        );
    }

    public function uploadFavicon(UploadSystemFaviconRequest $request)
    {
        return $this->successResponse(
            'Favicon actualizado correctamente.',
            $this->brandingService->storeFavicon($request->file('favicon')),
            200
        );
    }

    public function deleteFavicon()
    {
        return $this->successResponse(
            'Favicon eliminado correctamente.',
            $this->brandingService->deleteFavicon(),
            200
        );
    }
}
