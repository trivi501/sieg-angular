<?php

namespace App\Observers;

use App\Models\Auditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Escribe en la bitácora de auditoría cada alta, modificación, baja/reactivación (columna `activo`)
 * y eliminación de un modelo, con los valores anteriores y nuevos de los campos que cambiaron.
 * Lo usan el trait Auditable (modelos propios) y AppServiceProvider (modelos de paquetes, como los
 * roles y permisos de Spatie). Los cambios masivos (`Model::whereIn(...)->update()`) no pasan por aquí.
 */
class AuditoriaObserver
{
    /** Columnas que no aportan a la bitácora. */
    private const OMITIR = ['created_at', 'updated_at', 'remember_token'];

    public function created(Model $modelo): void
    {
        Auditoria::registrar($modelo, 'alta', null, $this->valores($modelo, $modelo->getAttributes()));
    }

    public function updated(Model $modelo): void
    {
        $despues = $this->valores($modelo, $modelo->getChanges());

        if ($despues === []) {
            return;
        }

        $antes = $this->valores($modelo, Arr::only($modelo->getOriginal(), array_keys($despues)));
        $accion = 'modificación';

        if (array_keys($despues) === ['activo']) {
            $accion = $despues['activo'] ? 'reactivación' : 'baja';
        }

        Auditoria::registrar($modelo, $accion, $antes, $despues);
    }

    public function deleted(Model $modelo): void
    {
        Auditoria::registrar($modelo, 'eliminación', $this->valores($modelo, $modelo->getAttributes()), null);
    }

    /**
     * La llave ya queda en auditable_id. Los atributos ocultos (contraseña, secretos de doble factor)
     * se registran como "[oculto]": queda constancia de que cambiaron, sin guardar el valor.
     */
    private function valores(Model $modelo, array $valores): array
    {
        $ocultos = $modelo->getHidden();

        return collect(Arr::except($valores, [$modelo->getKeyName(), ...self::OMITIR]))
            ->map(fn ($v, $campo) => match (true) {
                in_array($campo, $ocultos, true) => $v === null ? null : '[oculto]',
                $v instanceof \DateTimeInterface => $v->format('Y-m-d H:i:s'),
                default => $v,
            })
            ->all();
    }
}
