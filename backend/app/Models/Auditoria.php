<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Auditoria extends Model
{
    protected $table = 'auditoria';

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'accion', 'auditable_type', 'auditable_id', 'descripcion', 'antes', 'despues', 'lote', 'ip'];

    protected function casts(): array
    {
        return ['antes' => 'array', 'despues' => 'array'];
    }

    /** Lote y descripción activos (importación masiva): todos los cambios del bloque los comparten. */
    private static ?array $loteActual = null;

    public static function enLote(string $descripcion, callable $callback): mixed
    {
        $anterior = self::$loteActual;
        self::$loteActual = ['lote' => (string) Str::uuid(), 'descripcion' => mb_substr($descripcion, 0, 300)];

        try {
            return $callback();
        } finally {
            self::$loteActual = $anterior;
        }
    }

    public const ACCIONES = [
        'alta', 'modificación', 'baja', 'reactivación', 'eliminación',
        'inicio de sesión', 'cierre de sesión', 'acceso fallido',
    ];

    public static function registrar(Model $modelo, string $accion, ?array $antes, ?array $despues, ?string $descripcion = null): void
    {
        self::escribir($modelo::class, (string) $modelo->getKey(), $accion, $antes, $despues, $descripcion, auth()->id());
    }

    /**
     * Cambios de relaciones muchos a muchos (roles de un usuario, permisos de un rol, unidades de una
     * secretaría), que no disparan eventos de modelo. Solo registra si la lista cambió.
     */
    public static function relacion(Model $modelo, string $campo, array $antes, array $despues): void
    {
        sort($antes);
        sort($despues);

        if ($antes !== $despues) {
            self::registrar($modelo, 'modificación', [$campo => implode(', ', $antes)], [$campo => implode(', ', $despues)]);
        }
    }

    /** Inicio y cierre de sesión e intentos fallidos. */
    public static function acceso(string $accion, ?Model $usuario, ?string $descripcion = null): void
    {
        self::escribir(
            User::class,
            (string) ($usuario?->getKey() ?? ''),
            $accion,
            null,
            null,
            $descripcion,
            $accion === 'acceso fallido' ? null : $usuario?->getKey(),
        );
    }

    private static function escribir(string $tipo, string $id, string $accion, ?array $antes, ?array $despues, ?string $descripcion, ?int $usuarioId): void
    {
        static::create([
            'user_id' => $usuarioId,
            'accion' => $accion,
            'auditable_type' => $tipo,
            'auditable_id' => $id,
            'descripcion' => $descripcion !== null ? mb_substr($descripcion, 0, 300) : (self::$loteActual['descripcion'] ?? null),
            'antes' => $antes,
            'despues' => $despues,
            'lote' => self::$loteActual['lote'] ?? null,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
