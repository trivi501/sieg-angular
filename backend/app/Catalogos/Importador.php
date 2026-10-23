<?php

namespace App\Catalogos;

use App\Models\Auditoria;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Importación masiva de un catálogo desde Excel/CSV en dos pasos: `analizar` valida cada renglón
 * y dice si será alta, modificación o sin cambios (sin escribir nada); `aplicar` guarda los renglones
 * ya analizados dentro de una transacción y un mismo lote de auditoría.
 */
final class Importador
{
    public const MAX_FILAS = 5000;

    public function __construct(private Catalogo $catalogo) {}

    public function plantilla(): Spreadsheet
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle(Str::limit($this->catalogo->titulo, 28, ''));

        foreach ($this->catalogo->camposEditables() as $i => $campo) {
            $columna = $i + 1;
            $hoja->setCellValue([$columna, 1], $campo->etiqueta.($campo->requerido ? ' *' : ''));
            $hoja->getStyle([$columna, 1])->getFont()->setBold(true);
            $hoja->getColumnDimensionByColumn($columna)->setWidth(max(14, mb_strlen($campo->etiqueta) + 4));

            // Texto para que Excel no convierta claves, CLABE o RFC en números.
            if (in_array($campo->tipo, ['texto', 'texto_largo', 'relacion'], true)) {
                $hoja->getStyle([$columna, 2, $columna, 2000])->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }
        }

        $instrucciones = $libro->createSheet();
        $instrucciones->setTitle('Instrucciones');
        $instrucciones->fromArray([['Columna', 'Obligatoria', 'Qué capturar']]);
        $instrucciones->getStyle('A1:C1')->getFont()->setBold(true);

        foreach ($this->catalogo->camposEditables() as $i => $campo) {
            $instrucciones->fromArray([[$campo->etiqueta, $campo->requerido ? 'Sí' : 'No', $this->instruccion($campo)]], null, 'A'.($i + 2));
        }

        $llave = array_map(fn ($c) => $this->catalogo->campo($c)?->etiqueta ?? $c, $this->catalogo->llave);
        $nota = $llave === []
            ? 'Cada renglón se da de alta como un registro nuevo.'
            : 'Si ya existe un registro con el mismo '.implode(' + ', $llave).', se actualiza (las celdas vacías no borran el valor actual); si no, se da de alta.';
        $instrucciones->setCellValue('A'.(count($this->catalogo->camposEditables()) + 3), $nota);

        foreach (['A' => 30, 'B' => 12, 'C' => 90] as $col => $ancho) {
            $instrucciones->getColumnDimension($col)->setWidth($ancho);
        }

        $libro->setActiveSheetIndex(0);

        return $libro;
    }

    private function instruccion(Campo $campo): string
    {
        $texto = match ($campo->tipo) {
            'entero' => 'Número entero.',
            'fecha' => 'Fecha (dd/mm/aaaa).',
            'booleano' => 'Sí o No.',
            'seleccion' => 'Una de: '.implode(', ', $campo->opciones).'.',
            'relacion' => 'Se busca por '.($campo->importarPor === 'rfc' ? 'RFC' : $campo->importarPor).' o por nombre.',
            default => $campo->max ? "Texto (máximo {$campo->max} caracteres)." : 'Texto.',
        };

        return trim($texto.' '.($campo->ayuda ?? ''));
    }

    /**
     * @return array{filas: array, errores: array, columnasIgnoradas: string[], faltantes: string[]}
     */
    public function analizar(UploadedFile $archivo): array
    {
        $hoja = IOFactory::load($archivo->getPathname())->getSheet(0);
        $renglones = $hoja->toArray(null, true, false, false);

        $encabezados = array_shift($renglones) ?? [];
        [$mapa, $ignoradas] = $this->mapearEncabezados($encabezados);

        $faltantes = collect($this->catalogo->camposEditables())
            ->filter(fn (Campo $c) => $c->requerido && ! in_array($c->nombre, $mapa, true))
            ->map(fn (Campo $c) => $c->etiqueta)
            ->values()
            ->all();

        if ($faltantes !== []) {
            return ['filas' => [], 'errores' => [], 'columnasIgnoradas' => $ignoradas, 'faltantes' => $faltantes];
        }

        if (count($renglones) > self::MAX_FILAS) {
            throw ValidationException::withMessages(['archivo' => 'El archivo tiene más de '.self::MAX_FILAS.' renglones; divídelo en varios.']);
        }

        $relaciones = $this->indicesDeRelaciones();
        $filas = [];
        $errores = [];
        $llavesVistas = [];

        foreach ($renglones as $i => $renglon) {
            $numero = $i + 2;

            if (collect($renglon)->filter(fn ($v) => $v !== null && trim((string) $v) !== '')->isEmpty()) {
                continue;
            }

            $mensajes = [];
            $entrada = [];

            foreach ($mapa as $indice => $nombre) {
                $campo = $this->catalogo->campo($nombre);
                $entrada[$nombre] = $this->convertir($campo, $renglon[$indice] ?? null, $relaciones, $mensajes);
            }

            // Los campos que no vienen en el archivo toman su valor por defecto en las altas.
            $datos = $this->catalogo->normalizar($entrada);
            $existente = $this->catalogo->buscarPorLlave($datos);

            if (! $existente) {
                foreach ($this->catalogo->camposEditables() as $campo) {
                    if (($datos[$campo->nombre] ?? null) === null && $campo->porDefecto !== null) {
                        $datos[$campo->nombre] = $campo->porDefecto instanceof \Closure ? ($campo->porDefecto)() : $campo->porDefecto;
                    }
                }
            }

            if ($mensajes === []) {
                try {
                    // En una modificación las celdas vacías no borran el valor actual.
                    $datos = $this->catalogo->validar(
                        $existente ? [...$this->valoresActuales($existente), ...array_filter($datos, fn ($v) => $v !== null)] : $datos,
                        $existente,
                    );
                } catch (ValidationException $e) {
                    $mensajes = collect($e->errors())->flatten()->all();
                }
            }

            $llave = $this->textoLlave($datos);

            if ($mensajes === [] && $llave !== null) {
                if (isset($llavesVistas[$llave])) {
                    $mensajes[] = "Está repetido en el archivo (renglón {$llavesVistas[$llave]}).";
                }

                $llavesVistas[$llave] ??= $numero;
            }

            if ($mensajes !== []) {
                $errores[] = ['fila' => $numero, 'mensajes' => array_values(array_unique($mensajes))];

                continue;
            }

            $filas[] = [
                'fila' => $numero,
                'accion' => ! $existente ? 'alta' : ($this->cambia($existente, $datos) ? 'modificacion' : 'sin_cambios'),
                'id' => $existente?->getKey(),
                'datos' => $datos,
            ];
        }

        return ['filas' => $filas, 'errores' => $errores, 'columnasIgnoradas' => $ignoradas, 'faltantes' => []];
    }

    /** @return array{0: array<int, string>, 1: string[]} índice de columna => nombre de campo, y encabezados no reconocidos */
    private function mapearEncabezados(array $encabezados): array
    {
        $porTexto = [];

        foreach ($this->catalogo->camposEditables() as $campo) {
            $porTexto[self::normalizarTexto($campo->etiqueta)] = $campo->nombre;
            $porTexto[self::normalizarTexto($campo->nombre)] = $campo->nombre;
        }

        $mapa = [];
        $ignoradas = [];

        foreach ($encabezados as $indice => $encabezado) {
            if ($encabezado === null || trim((string) $encabezado) === '') {
                continue;
            }

            $nombre = $porTexto[self::normalizarTexto((string) $encabezado)] ?? null;

            if ($nombre === null || in_array($nombre, $mapa, true)) {
                $ignoradas[] = (string) $encabezado;

                continue;
            }

            $mapa[$indice] = $nombre;
        }

        return [$mapa, $ignoradas];
    }

    public static function normalizarTexto(string $texto): string
    {
        $texto = str_replace(['*', "\xC2\xA0"], ['', ' '], $texto);
        $texto = Str::of($texto)->ascii()->lower()->squish()->toString();

        return $texto;
    }

    /** Índices de búsqueda de cada relación: valor normalizado (importarPor o etiqueta) => id. */
    private function indicesDeRelaciones(): array
    {
        $indices = [];

        foreach ($this->catalogo->camposEditables() as $campo) {
            if ($campo->tipo !== 'relacion' || ! $campo->tablaRelacionDisponible()) {
                continue;
            }

            $indice = [];

            // Si dos registros comparten valor (p. ej. la misma clave en años distintos) gana el último.
            foreach ($campo->modelo::query()->orderBy((new $campo->modelo)->getKeyName())->get() as $relacionado) {
                $indice[self::normalizarTexto($campo->etiquetaDe($relacionado))] = $relacionado->getKey();
                $indice[self::normalizarTexto((string) $relacionado->getAttribute($campo->importarPor))] = $relacionado->getKey();

                foreach ($campo->columnasEtiqueta as $columna) {
                    $indice[self::normalizarTexto((string) $relacionado->getAttribute($columna))] ??= $relacionado->getKey();
                }
            }

            unset($indice['']);
            $indices[$campo->nombre] = $indice;
        }

        return $indices;
    }

    private function convertir(Campo $campo, mixed $valor, array $relaciones, array &$mensajes): mixed
    {
        if (is_string($valor)) {
            $valor = trim($valor);
        }

        if ($valor === null || $valor === '') {
            return null;
        }

        switch ($campo->tipo) {
            case 'booleano':
                $texto = self::normalizarTexto((string) $valor);

                if (in_array($texto, ['si', 's', '1', 'true', 'verdadero', 'x', 'activo'], true)) {
                    return true;
                }

                if (in_array($texto, ['no', 'n', '0', 'false', 'falso', 'inactivo'], true)) {
                    return false;
                }

                $mensajes[] = "{$campo->etiqueta}: escribe Sí o No.";

                return null;

            case 'fecha':
                try {
                    if (is_numeric($valor)) {
                        return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $valor))->toDateString();
                    }

                    foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'd/m/y'] as $formato) {
                        $fecha = \DateTime::createFromFormat('!'.$formato, (string) $valor);

                        if ($fecha !== false) {
                            return $fecha->format('Y-m-d');
                        }
                    }
                } catch (\Throwable) {
                }

                $mensajes[] = "{$campo->etiqueta}: \"{$valor}\" no es una fecha válida (usa dd/mm/aaaa).";

                return null;

            case 'seleccion':
                $texto = self::normalizarTexto((string) $valor);

                foreach ($campo->opciones as $clave => $etiqueta) {
                    if ($texto === self::normalizarTexto((string) $clave) || $texto === self::normalizarTexto($etiqueta)) {
                        return (string) $clave;
                    }
                }

                $mensajes[] = "{$campo->etiqueta}: \"{$valor}\" no es una opción válida (".implode(', ', $campo->opciones).').';

                return null;

            case 'relacion':
                $id = $relaciones[$campo->nombre][self::normalizarTexto(self::comoTexto($valor))] ?? null;

                if ($id === null) {
                    $mensajes[] = "{$campo->etiqueta}: no se encontró \"{$valor}\".";
                }

                return $id;

            case 'entero':
                if (is_numeric($valor) && (float) $valor == (int) $valor) {
                    return (int) $valor;
                }

                $mensajes[] = "{$campo->etiqueta}: \"{$valor}\" no es un número entero.";

                return null;

            default:
                return self::comoTexto($valor);
        }
    }

    /** Números leídos de Excel (1131.0) como texto sin decimales sobrantes. */
    private static function comoTexto(mixed $valor): string
    {
        if (is_float($valor) && floor($valor) === $valor && abs($valor) < 1e15) {
            return number_format($valor, 0, '', '');
        }

        return (string) $valor;
    }

    private function valoresActuales(Model $registro): array
    {
        return collect($this->catalogo->camposEditables())
            ->mapWithKeys(fn (Campo $c) => [$c->nombre => $this->catalogo->valorDe($registro, $c)])
            ->all();
    }

    private function cambia(Model $registro, array $datos): bool
    {
        foreach ($datos as $nombre => $valor) {
            $actual = $this->catalogo->valorDe($registro, $this->catalogo->campo($nombre));

            if (is_bool($valor) || is_bool($actual)) {
                if ((bool) $valor !== (bool) $actual) {
                    return true;
                }
            } elseif ((string) $valor !== (string) $actual) {
                return true;
            }
        }

        return false;
    }

    private function textoLlave(array $datos): ?string
    {
        if ($this->catalogo->llave === []) {
            return null;
        }

        $partes = [];

        foreach ($this->catalogo->llave as $columna) {
            if (! isset($datos[$columna]) || $datos[$columna] === '') {
                return null;
            }

            $partes[] = mb_strtolower((string) $datos[$columna]);
        }

        return implode('|', $partes);
    }

    /** @return array{altas: int, modificaciones: int} */
    public function aplicar(array $filas, string $archivo): array
    {
        $resultado = ['altas' => 0, 'modificaciones' => 0];
        $pendientes = array_filter($filas, fn ($f) => $f['accion'] !== 'sin_cambios');

        Auditoria::enLote("Importación de {$this->catalogo->titulo} ({$archivo})", function () use ($pendientes, &$resultado) {
            DB::transaction(function () use ($pendientes, &$resultado) {
                foreach ($pendientes as $fila) {
                    if ($fila['accion'] === 'alta') {
                        $this->catalogo->crear($fila['datos']);
                        $resultado['altas']++;
                    } else {
                        $this->catalogo->actualizar($this->catalogo->buscarRegistro($fila['id']), $fila['datos']);
                        $resultado['modificaciones']++;
                    }
                }
            });
        });

        return $resultado;
    }
}
