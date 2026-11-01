<?php

use App\Services\WorkshopSettingService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recordatorios a clientes y cuentas por pagar (requiere schedule:run cada minuto). La hora es
// configurable por taller y en hora local: se compara al correr, no al arrancar.
Schedule::command('notifications:send-reminders')
    ->everyMinute()
    ->when(fn () => app(WorkshopSettingService::class)->remindersDue())
    ->withoutOverlapping();
