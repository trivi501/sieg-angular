<?php

namespace App\Models;

/** Periodo de captura de modificaciones presupuestales de un mes: del día fecha_inicio al fecha_fin (inclusive). */
class CalendarioModificaciones extends CalendarioCaptura
{
    protected $table = 'calendario_modificaciones';
}
