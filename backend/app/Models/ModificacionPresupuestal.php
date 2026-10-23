<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\PresupuestoService;
use App\Services\SaldosMensuales;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class ModificacionPresupuestal extends Model
{
    use Auditable;

    protected $table = 'modificaciones_presupuestales';

    public const TIPOS = [
        'traspaso' => 'Traspaso',
        'reduccion' => 'Reducción',
        'ampliacion' => 'Ampliación',
    ];

    protected $fillable = [
        'folio', 'año', 'folio_completo', 'tipo', 'estado',
        'origen_clave', 'origen_fuente', 'origen_proyecto', 'origen_partida', 'origen_descripcion', 'origen_mes',
        'destino_clave', 'destino_fuente', 'destino_proyecto', 'destino_partida', 'destino_descripcion', 'destino_mes',
        'importe', 'justificacion', 'id_tb_egreso_requisicion',
        'solicitante_id', 'autorizador_id', 'resuelto_at', 'observaciones_resolucion',
        'origen_disponible_antes', 'destino_vigente_antes',
    ];

    protected function casts(): array
    {
        return [
            'importe' => 'float',
            'resuelto_at' => 'datetime',
            'origen_mes' => 'integer',
            'destino_mes' => 'integer',
            'origen_disponible_antes' => 'float',
            'destino_vigente_antes' => 'float',
        ];
    }

    /** Días antes del fin de mes en que se cierra la captura para las áreas. */
    public const DIAS_CIERRE = 3;

    /**
     * Periodo de captura de las áreas para la fecha: el del calendario (Catálogos → Calendario de captura de
     * modificaciones) que la incluya o el de su mes; si el mes no está registrado, del día 1 hasta el fin de
     * mes menos DIAS_CIERRE (en octubre, hasta el 28).
     *
     * @return array{inicio: CarbonImmutable, fin: CarbonImmutable, calendario: bool}
     */
    public static function periodoCaptura(?CarbonImmutable $hoy = null): array
    {
        $hoy ??= CarbonImmutable::now(SaldosMensuales::ZONA);
        $dia = $hoy->toDateString();
        $activos = CalendarioModificaciones::where('activo', true);

        // Uno que incluya hoy (el de noviembre puede empezar a fines de octubre) o, si no, el del mes.
        $mes = (clone $activos)->whereDate('fecha_inicio', '<=', $dia)->whereDate('fecha_fin', '>=', $dia)->orderBy('fecha_inicio')->first()
            ?? (clone $activos)->where('año', $hoy->year)->where('mes', $hoy->month)->first();
        if ($mes) {
            return [
                'inicio' => CarbonImmutable::parse($mes->fecha_inicio->toDateString(), SaldosMensuales::ZONA),
                'fin' => CarbonImmutable::parse($mes->fecha_fin->toDateString(), SaldosMensuales::ZONA),
                'calendario' => true,
            ];
        }

        return [
            'inicio' => $hoy->startOfMonth()->startOfDay(),
            'fin' => $hoy->endOfMonth()->subDays(self::DIAS_CIERRE)->startOfDay(),
            'calendario' => false,
        ];
    }

    /** Último día del periodo de captura de las áreas (ver periodoCaptura()). */
    public static function ultimoDiaCaptura(?CarbonImmutable $hoy = null): CarbonImmutable
    {
        return self::periodoCaptura($hoy)['fin'];
    }

    /** Control Presupuestal (quien autoriza) y Admin capturan siempre; las áreas, dentro de su periodo. */
    public static function puedeCapturar(User $user, ?CarbonImmutable $hoy = null): bool
    {
        if (self::siempreAbierta($user)) {
            return true;
        }

        $periodo = self::periodoCaptura($hoy);
        $dia = ($hoy ?? CarbonImmutable::now(SaldosMensuales::ZONA))->toDateString();

        return $dia >= $periodo['inicio']->toDateString() && $dia <= $periodo['fin']->toDateString();
    }

    /** Siguiente día en que se abre la captura para las áreas (si hoy está cerrada). */
    public static function proximaApertura(?CarbonImmutable $hoy = null): CarbonImmutable
    {
        $hoy ??= CarbonImmutable::now(SaldosMensuales::ZONA);
        $periodo = self::periodoCaptura($hoy);
        if ($hoy->toDateString() < $periodo['inicio']->toDateString()) {
            return $periodo['inicio'];
        }

        // Periodo del mes siguiente (o uno del calendario que empiece antes).
        $siguiente = CalendarioModificaciones::where('activo', true)->whereDate('fecha_inicio', '>', $hoy->toDateString())->orderBy('fecha_inicio')->first();
        $delMes = self::periodoCaptura($hoy->addMonthNoOverflow()->startOfMonth())['inicio'];

        return $siguiente ? min(CarbonImmutable::parse($siguiente->fecha_inicio->toDateString(), SaldosMensuales::ZONA), $delMes) : $delMes;
    }

    public static function siempreAbierta(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin']) || $user->can('modificaciones-autorizar');
    }

    /** Clave unidad|fuente|proyecto|partida del lado indicado, o null si ese lado no aplica al tipo. */
    public function claveLinea(string $lado): ?string
    {
        if ($this->{"{$lado}_clave"} === null) {
            return null;
        }

        return PresupuestoService::clave(
            $this->{"{$lado}_clave"}, $this->{"{$lado}_fuente"}, $this->{"{$lado}_proyecto"}, $this->{"{$lado}_partida"}
        );
    }

    public function solicitante()
    {
        return $this->belongsTo(User::class, 'solicitante_id');
    }

    public function autorizador()
    {
        return $this->belongsTo(User::class, 'autorizador_id');
    }

    public function requisicion()
    {
        return $this->belongsTo(EgresoRequisicion::class, 'id_tb_egreso_requisicion', 'id_tb_egreso_requisicion');
    }
}
