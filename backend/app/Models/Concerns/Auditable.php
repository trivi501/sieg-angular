<?php

namespace App\Models\Concerns;

use App\Observers\AuditoriaObserver;

/**
 * Registra en la bitácora de auditoría cada alta, modificación, baja/reactivación y eliminación
 * del modelo (ver AuditoriaObserver). Los cambios masivos (`Model::whereIn(...)->update()`)
 * no pasan por aquí: hay que recorrer los modelos o registrar el cambio a mano con Auditoria.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        // static::observe() no se puede usar mientras el modelo arranca: se registran los eventos.
        $observador = new AuditoriaObserver;

        static::created(fn ($modelo) => $observador->created($modelo));
        static::updated(fn ($modelo) => $observador->updated($modelo));
        static::deleted(fn ($modelo) => $observador->deleted($modelo));
    }
}
