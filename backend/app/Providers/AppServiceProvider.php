<?php

namespace App\Providers;

use App\Catalogos\Catalogos;
use App\Observers\AuditoriaObserver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(app()->isProduction());

        // Roles y permisos son modelos de Spatie: no llevan el trait Auditable. Los accesos (inicio y
        // cierre de sesión, intento fallido) los registra AuthController, porque la API no usa sesión.
        Role::observe(AuditoriaObserver::class);
        Permission::observe(AuditoriaObserver::class);

        // {catalogo} de /api/catalogos/{catalogo} → Catalogo. Va aquí y no en routes/api.php: con las rutas en
        // caché (php artisan optimize) ese archivo no se ejecuta y todos los catálogos darían 404.
        Route::bind('catalogo', fn (string $slug) => Catalogos::buscar($slug) ?? abort(404));
    }
}
