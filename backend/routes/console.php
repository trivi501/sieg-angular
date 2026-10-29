<?php

use App\Support\Inactividad;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Laravel\Sanctum\PersonalAccessToken;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sesiones vencidas por inactividad (App\Support\Inactividad): se borran cada hora (requiere el cron de schedule:run).
Schedule::call(function () {
    $limite = now()->subMinutes(Inactividad::minutos());
    PersonalAccessToken::whereRaw('COALESCE(actividad_at, last_used_at, created_at) < ?', [$limite])->delete();
})->hourly()->name('sesiones-vencidas');
