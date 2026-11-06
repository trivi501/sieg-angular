<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * "Quitar límite" de los fondos revolventes: mientras uno esté vigente (hasta en el futuro y sin restablecer), todos
 * los fondos aceptan facturas de más de $2,000. Al terminar el tiempo el límite vuelve solo.
 */
class FondoSinLimite extends Model
{
    use Auditable;

    /** Minutos que dura cada vez que se quita el límite. */
    public const MINUTOS = 5;

    protected $table = 'fondos_sin_limite';

    protected $fillable = ['hasta', 'user_id', 'restablecido_at', 'restablecido_user_id'];

    protected function casts(): array
    {
        return ['hasta' => 'datetime', 'restablecido_at' => 'datetime'];
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function restablecio()
    {
        return $this->belongsTo(User::class, 'restablecido_user_id');
    }

    /** El periodo sin límite en curso, o null. */
    public static function vigente(): ?self
    {
        return static::whereNull('restablecido_at')->where('hasta', '>', now())->latest('hasta')->first();
    }
}
