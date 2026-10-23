<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class EjercicioFiscal extends Model
{
    use Auditable;

    /** Periodo ordinario de captura de requisiciones cuando el ejercicio no está registrado. */
    public const CAPTURA_DIA_INICIO = 1;

    public const CAPTURA_DIA_FIN = 7;

    protected $table = 'ejercicios_fiscales';

    protected $fillable = ['año', 'descripcion', 'estado', 'captura_dia_inicio', 'captura_dia_fin', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'año' => 'integer', 'captura_dia_inicio' => 'integer', 'captura_dia_fin' => 'integer'];
    }

    /** Un ejercicio registrado y cerrado no admite documentos nuevos; uno sin registrar no se bloquea. */
    public static function estaCerrado(int $año): bool
    {
        return static::where('año', $año)->where('estado', 'cerrado')->exists();
    }

    /**
     * Días del mes (inicio, fin) en que se capturan requisiciones en el ejercicio.
     *
     * @return array{0: int, 1: int}
     */
    public static function periodoCaptura(int $año): array
    {
        $ejercicio = static::where('año', $año)->first(['captura_dia_inicio', 'captura_dia_fin']);

        return [
            $ejercicio?->captura_dia_inicio ?? self::CAPTURA_DIA_INICIO,
            $ejercicio?->captura_dia_fin ?? self::CAPTURA_DIA_FIN,
        ];
    }

    /**
     * Periodo de captura del mes de la fecha: el del calendario (Catálogos → Calendario de captura) o, si
     * ese mes no está registrado, los días del ejercicio (por omisión, del 1 al 7).
     *
     * @return array{inicio: Carbon, fin: Carbon, calendario: bool}
     */
    public static function periodoDelMes(CarbonInterface $fecha): array
    {
        $mes = CalendarioCaptura::where('año', $fecha->year)->where('mes', $fecha->month)->where('activo', true)->first();
        if ($mes) {
            return ['inicio' => $mes->fecha_inicio->copy()->startOfDay(), 'fin' => $mes->fecha_fin->copy()->startOfDay(), 'calendario' => true];
        }

        [$inicio, $fin] = static::periodoCaptura($fecha->year);
        $base = Carbon::create($fecha->year, $fecha->month, 1);

        return [
            'inicio' => $base->copy()->day(min($inicio, $base->daysInMonth)),
            'fin' => $base->copy()->day(min($fin, $base->daysInMonth)),
            'calendario' => false,
        ];
    }

    /**
     * ¿La fecha (hora de México) cae en un periodo de captura? Cuenta cualquier periodo del calendario que
     * la incluya (el de noviembre puede empezar a fines de octubre) y, si no, el periodo de su mes.
     */
    public static function enPeriodoDeCaptura(CarbonInterface $fecha): bool
    {
        $dia = $fecha->toDateString();
        if (CalendarioCaptura::where('activo', true)->whereDate('fecha_inicio', '<=', $dia)->whereDate('fecha_fin', '>=', $dia)->exists()) {
            return true;
        }

        $periodo = static::periodoDelMes($fecha);

        return $dia >= $periodo['inicio']->toDateString() && $dia <= $periodo['fin']->toDateString();
    }
}
