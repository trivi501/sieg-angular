<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class Firmante extends Model
{
    use Auditable;

    public const ROLES = [
        'elabora' => 'Elabora',
        'revisa' => 'Revisa',
        'autoriza' => 'Autoriza',
        'vobo' => 'Visto bueno',
    ];

    protected $fillable = ['tipo_documento_id', 'rol', 'id_cat_egreso_unidad_administrativa', 'nombre', 'cargo', 'vigente_desde', 'vigente_hasta', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'vigente_desde' => 'date', 'vigente_hasta' => 'date'];
    }

    /**
     * Firmante vigente en la fecha del documento. El configurado para la unidad tiene prioridad
     * sobre el general (sin unidad); null si no hay ninguno y el PDF usa su texto por defecto.
     */
    public static function vigente(string $tipoDocumento, string $rol, ?int $unidadId = null, ?CarbonInterface $fecha = null): ?self
    {
        // Las vigencias son fechas de México: un momento de Laravel (UTC, p. ej. created_at) se pasa a
        // hora local; una fecha sin hora (fecha de la orden) se toma tal cual.
        $fecha = $fecha === null
            ? now('America/Mexico_City')->toDateString()
            : ($fecha->format('H:i:s') === '00:00:00' ? $fecha->toDateString() : $fecha->copy()->setTimezone('America/Mexico_City')->toDateString());

        return static::query()
            ->whereHas('tipoDocumento', fn ($q) => $q->where('clave', $tipoDocumento))
            ->where('rol', $rol)
            ->where('activo', true)
            ->whereDate('vigente_desde', '<=', $fecha)
            ->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', $fecha))
            ->where(fn ($q) => $q->whereNull('id_cat_egreso_unidad_administrativa')->orWhere('id_cat_egreso_unidad_administrativa', $unidadId))
            ->orderByRaw('id_cat_egreso_unidad_administrativa is null')
            ->orderByDesc('vigente_desde')
            ->first();
    }

    /**
     * Titular de la secretaría o dirección que firma un documento ("Autorizó"), en este orden:
     * el firmante "autoriza" del documento vigente en la fecha; si no, el más reciente de ese
     * documento; si no, el "autoriza" de la unidad en cualquier otro documento (es la misma persona);
     * y si no, el titular capturado en la propia unidad (catálogo de secretarías y direcciones).
     * "cargo" es el nombre de la secretaría o dirección, que se imprime debajo del nombre.
     *
     * @return array{nombre: string|null, cargo: string|null}
     */
    public static function titular(string $tipoDocumento, ?int $unidadId, ?CarbonInterface $fecha = null): array
    {
        $base = fn () => static::query()->where('rol', 'autoriza')->where('activo', true)
            ->where('id_cat_egreso_unidad_administrativa', $unidadId)
            ->orderByDesc('vigente_desde');

        $firmante = static::vigente($tipoDocumento, 'autoriza', $unidadId, $fecha)
            ?? ($unidadId ? $base()->whereHas('tipoDocumento', fn ($q) => $q->where('clave', $tipoDocumento))->first() : null)
            ?? ($unidadId ? $base()->first() : null);

        $unidad = $unidadId ? EgresoUnidadAdministrativa::find($unidadId) : null;

        // Debajo del nombre del titular va la secretaría o dirección (no el cargo capturado).
        return [
            'nombre' => $firmante?->nombre ?? $unidad?->responsable,
            'cargo' => $unidad?->nombre ?? $firmante?->cargo,
        ];
    }

    /**
     * Titular que firma por el área de un documento capturado por $usuario: si el usuario pertenece a
     * un regidor, el regidor (nombre con título y "REGIDOR/A"); si no, el titular de la secretaría.
     *
     * @return array{nombre: string|null, cargo: string|null}
     */
    public static function titularDe(?User $usuario, string $tipoDocumento, ?int $unidadId, ?CarbonInterface $fecha = null): array
    {
        $regidor = $usuario?->regidor;
        if ($regidor) {
            return ['nombre' => $regidor->nombreConTitulo(), 'cargo' => $regidor->cargo];
        }

        return static::titular($tipoDocumento, $unidadId, $fecha);
    }

    public function tipoDocumento()
    {
        return $this->belongsTo(TipoDocumento::class);
    }

    public function unidadAdministrativa()
    {
        return $this->belongsTo(EgresoUnidadAdministrativa::class, 'id_cat_egreso_unidad_administrativa', 'id_cat_egreso_unidad_administrativa');
    }
}
