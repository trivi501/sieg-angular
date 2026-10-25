<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Documento del expediente del proveedor en el padrón (constancia fiscal, opinión del SAT, identificación…). */
class ProveedorDocumento extends Model
{
    use Auditable;

    /** Tipo → [nombre, ¿obligatorio para la cédula?, ¿solo persona moral?] */
    public const TIPOS = [
        'constancia_fiscal' => ['Constancia de situación fiscal', true, false],
        'opinion_cumplimiento' => ['Opinión de cumplimiento del SAT (32-D)', true, false],
        'identificacion' => ['Identificación oficial (persona física o representante legal)', true, false],
        'comprobante_domicilio' => ['Comprobante de domicilio', true, false],
        'estado_cuenta' => ['Estado de cuenta bancario (CLABE)', true, false],
        'acta_constitutiva' => ['Acta constitutiva', true, true],
        'poder_notarial' => ['Poder notarial del representante', false, true],
        'otro' => ['Otro documento', false, false],
    ];

    /** Días que vale una opinión de cumplimiento del SAT si no se captura su vigencia. */
    public const DIAS_OPINION = 30;

    protected $table = 'proveedor_documentos';

    protected $fillable = ['proveedor_id', 'tipo', 'nombre_archivo', 'archivo_path', 'fecha_documento', 'vigente_hasta', 'sentido', 'observaciones', 'user_id'];

    protected $hidden = ['archivo_path'];

    protected function casts(): array
    {
        return ['fecha_documento' => 'date', 'vigente_hasta' => 'date'];
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function vigente(): bool
    {
        return $this->vigente_hasta === null || $this->vigente_hasta->toDateString() >= now('America/Mexico_City')->toDateString();
    }
}
