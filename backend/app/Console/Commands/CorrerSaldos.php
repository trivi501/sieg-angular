<?php

namespace App\Console\Commands;

use App\Services\SaldosMensuales;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Corre los saldos del presupuesto: lo que quedó disponible en el mes anterior se suma al mes.
 * Es manual (no se programa): desde el botón "Correr saldos" del ejercicio del gasto o con este comando.
 */
class CorrerSaldos extends Command
{
    protected $signature = 'presupuesto:correr-saldos
        {--mes= : Mes a correr en formato AAAA-MM (por omisión, el mes actual)}
        {--forzar : Vuelve a calcular un mes que ya se corrió}';

    protected $description = 'Suma al mes lo que quedó disponible en el mes anterior en cada línea del presupuesto';

    public function handle(SaldosMensuales $saldos): int
    {
        $mes = $this->option('mes');

        if ($mes !== null && ! preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $mes)) {
            $this->error('El mes debe tener el formato AAAA-MM, por ejemplo 2026-03.');

            return Command::FAILURE;
        }

        $fecha = $mes ? now(SaldosMensuales::ZONA)->setDate((int) substr($mes, 0, 4), (int) substr($mes, 5, 2), 1) : now(SaldosMensuales::ZONA);

        try {
            $corrida = $saldos->correr($fecha->year, $fecha->month, 'manual', null, (bool) $this->option('forzar'));
        } catch (RuntimeException $e) {
            $this->warn($e->getMessage());

            return Command::SUCCESS;
        }

        $this->info(sprintf('Saldos corridos a %s %d: %d líneas, $%s del mes anterior.',
            SaldosMensuales::MESES[$corrida->mes], $corrida->ejercicio, $corrida->lineas, number_format($corrida->total_corrido, 2)));

        return Command::SUCCESS;
    }
}
