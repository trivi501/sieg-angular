<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'titulo', 'cargo', 'email', 'password', 'id_cat_egreso_unidad_administrativa', 'regidor_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    use Auditable, HasApiTokens, HasRoles, Notifiable;

    /** Secretaría o dirección del usuario: define qué requisiciones y presupuesto ve. */
    public function unidadAdministrativa()
    {
        return $this->belongsTo(EgresoUnidadAdministrativa::class, 'id_cat_egreso_unidad_administrativa', 'id_cat_egreso_unidad_administrativa');
    }

    /** Regidor al que pertenece (sus enlaces); si no tiene, pertenece a su secretaría o dirección. */
    public function regidor()
    {
        return $this->belongsTo(Regidor::class);
    }

    /** Nombre con su título para los formatos: "L.C. FATIMA JANETH ARTEAGA SALINAS". */
    public function nombreConTitulo(): string
    {
        return trim(trim((string) $this->titulo).' '.$this->name);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
