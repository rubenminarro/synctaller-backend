<?php

namespace App\Http\Middleware;

use App\Services\WorkshopSettingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aplica la configuración del taller guardada en la base antes de atender el request.
 */
class ApplyWorkshopSettings
{
    public function __construct(
        private readonly WorkshopSettingService $settings
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->settings->apply();

        return $next($request);
    }
}
