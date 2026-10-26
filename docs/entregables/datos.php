<?php

/**
 * Datos del sistema para la documentación técnica del expediente (JSON en la salida): rutas de la API con su permiso,
 * tablas de la base con sus columnas, roles y permisos, versiones de las dependencias y conteos del código.
 * Solo lee. La base es la de backend/.env, o la que se indique en DB_DATABASE.
 */
$raiz = dirname(__DIR__, 2);
require $raiz.'/backend/vendor/autoload.php';
$app = require $raiz.'/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

$rutas = collect(Route::getRoutes()->getRoutes())
    ->filter(fn ($r) => str_starts_with($r->uri(), 'api/'))
    ->map(fn ($r) => [
        'metodo' => implode('|', array_diff($r->methods(), ['HEAD'])),
        'uri' => '/'.$r->uri(),
        'accion' => str_replace('App\\Http\\Controllers\\Api\\', '', (string) $r->getActionName()),
        'permiso' => collect($r->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'))
            ->map(fn ($m) => substr($m, 11))->implode(', '),
    ])->sortBy('uri')->values();

$base = DB::connection()->getDatabaseName();
// Schema::getTables() trae las de todas las bases del servidor: solo las de esta.
$tablas = collect(Schema::getTables())->filter(fn ($t) => ($t['schema'] ?? $base) === $base)->map(fn ($t) => [
    'nombre' => $t['name'],
    'columnas' => count(Schema::getColumns($t['name'])),
    'comentario' => $t['comment'] ?? null,
])->sortBy('nombre')->values();

$lock = json_decode(file_get_contents($raiz.'/backend/composer.lock'), true);
$version = fn (string $p) => collect([...$lock['packages'], ...$lock['packages-dev']])->firstWhere('name', $p)['version'] ?? null;
$npm = json_decode(file_get_contents($raiz.'/frontend/package-lock.json'), true)['packages'] ?? [];
$versionNpm = fn (string $p) => $npm["node_modules/{$p}"]['version'] ?? null;

$contar = fn (string $patron) => count(glob($raiz.'/'.$patron));

echo json_encode([
    'base' => $base,
    'commit' => trim((string) shell_exec('git -C '.escapeshellarg($raiz).' rev-parse --short HEAD')),
    'fecha_commit' => trim((string) shell_exec('git -C '.escapeshellarg($raiz).' log -1 --format=%cs')),
    'rutas' => $rutas,
    'tablas' => $tablas,
    'roles' => Role::withCount('permissions')->orderBy('name')->get()->map(fn ($r) => ['nombre' => $r->name, 'permisos' => $r->permissions_count]),
    'permisos' => Permission::orderBy('name')->get()->map(fn ($p) => [
        'nombre' => $p->name,
        'descripcion' => $p->nombre_mostrar ?? null,
    ]),
    'versiones' => [
        'php' => PHP_VERSION,
        'laravel' => $version('laravel/framework'),
        'sanctum' => $version('laravel/sanctum'),
        'permission' => $version('spatie/laravel-permission'),
        'dompdf' => $version('barryvdh/laravel-dompdf'),
        'phpspreadsheet' => $version('phpoffice/phpspreadsheet'),
        'angular' => $versionNpm('@angular/core'),
        'typescript' => $versionNpm('typescript'),
        'bootstrap' => $versionNpm('bootstrap'),
        'tomselect' => $versionNpm('tom-select'),
        'mysql' => DB::selectOne('select version() as v')->v,
    ],
    'conteos' => [
        'controladores' => $contar('backend/app/Http/Controllers/Api/*.php'),
        'modelos' => $contar('backend/app/Models/*.php'),
        'servicios' => $contar('backend/app/Services/*.php'),
        'migraciones' => $contar('backend/database/migrations/*.php'),
        'vistas_pdf' => count(iterator_to_array(new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz.'/backend/resources/views')), '/\.blade\.php$/'))),
        'pantallas' => count(iterator_to_array(new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz.'/frontend/src/app/pages')), '/\.component\.ts$/'))),
        'modulos_frontend' => count(glob($raiz.'/frontend/src/app/pages/*', GLOB_ONLYDIR)),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
