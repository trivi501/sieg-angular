<?php

namespace App\Catalogos;

use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Definición de un catálogo administrable desde /catalogos/{slug}: modelo, campos, búsqueda,
 * llave de importación y permisos ({prefijo}-index|create|edit|delete|import).
 */
final class Catalogo
{
    /**
     * @param  class-string<Model>  $modelo
     * @param  Campo[]  $campos
     * @param  string[]  $buscar  columnas para la búsqueda; "relacion.columna" busca en el modelo relacionado
     * @param  string[]  $llave  campos que identifican un registro al importar (existe → se actualiza)
     * @param  array<string, mixed>  $valoresFijos  columnas obligatorias de tablas del sistema anterior que no se capturan
     * @param  Closure|null  $preparar  fn (array $datos): array — normaliza antes de validar
     * @param  Closure|null  $validar  fn (array $datos, ?Model $registro): array<string, string> — errores adicionales
     * @param  Closure|null  $despuesDeGuardar  fn (Model $registro): void
     */
    public function __construct(
        public string $slug,
        public string $titulo,
        public string $singular,
        public string $grupo,
        public string $modelo,
        public array $campos,
        public array $buscar,
        public string $descripcion = '',
        public ?string $permiso = null,
        public array $llave = ['clave'],
        public array $valoresFijos = [],
        public array $orden = ['nombre' => 'asc'],
        public ?Closure $preparar = null,
        public ?Closure $validar = null,
        public ?Closure $despuesDeGuardar = null,
    ) {}

    public function prefijoPermiso(): string
    {
        return $this->permiso ?? "catalogos-{$this->slug}";
    }

    public function permisos(): array
    {
        return array_map(fn ($a) => "{$this->prefijoPermiso()}-{$a}", ['index', 'create', 'edit', 'delete', 'import']);
    }

    public function nuevoModelo(): Model
    {
        return new $this->modelo;
    }

    public function tabla(): string
    {
        return $this->nuevoModelo()->getTable();
    }

    /** Los catálogos sobre tablas del sistema anterior no existen en la base SQLite de pruebas. */
    public function disponible(): bool
    {
        return Schema::hasTable($this->tabla());
    }

    public function campo(string $nombre): ?Campo
    {
        foreach ($this->campos as $campo) {
            if ($campo->nombre === $nombre) {
                return $campo;
            }
        }

        return null;
    }

    /** @return Campo[] */
    public function camposEditables(): array
    {
        return array_values(array_filter($this->campos, fn (Campo $c) => $c->editable()));
    }

    public function tieneActivo(): bool
    {
        return $this->campo('activo') !== null;
    }

    public function query(): Builder
    {
        $query = $this->modelo::query();

        foreach ($this->orden as $columna => $direccion) {
            $query->orderBy($columna, $direccion);
        }

        return $query;
    }

    public function buscarRegistro(int|string $id): Model
    {
        return $this->modelo::query()->findOrFail($id);
    }

    /** Convierte '' en null, aplica mayúsculas y deja solo los campos editables. */
    public function normalizar(array $entrada): array
    {
        $datos = [];

        foreach ($this->camposEditables() as $campo) {
            if (! array_key_exists($campo->nombre, $entrada)) {
                continue;
            }

            $valor = $entrada[$campo->nombre];

            if (is_string($valor)) {
                $valor = trim($valor);
                $valor = $valor === '' ? null : $valor;
            }

            if ($valor !== null && $campo->mayusculas) {
                $valor = mb_strtoupper($valor);
            }

            if ($campo->tipo === 'booleano') {
                // Celda vacía al importar: se queda el valor actual o el de por defecto.
                if ($valor === null) {
                    continue;
                }

                $valor = filter_var($valor, FILTER_VALIDATE_BOOLEAN);
            }

            $datos[$campo->nombre] = $valor;
        }

        return $this->preparar ? ($this->preparar)($datos) : $datos;
    }

    /**
     * Valida datos ya normalizados. Lanza ValidationException con los mensajes por campo.
     */
    public function validar(array $datos, ?Model $registro = null): array
    {
        $reglas = [];
        $mensajes = [];
        $atributos = [];

        foreach ($this->camposEditables() as $campo) {
            $reglas[$campo->nombre] = $campo->reglasValidacion($this->tabla(), $registro);
            $atributos[$campo->nombre] = mb_strtolower($campo->etiqueta);

            if ($campo->mensajeRegex) {
                $mensajes["{$campo->nombre}.regex"] = $campo->mensajeRegex;
            }
        }

        $validados = Validator::make($datos, $reglas, $mensajes, $atributos)->validate();

        $errores = $this->erroresDeLlave($validados, $registro);

        if ($this->validar) {
            $errores = [...$errores, ...($this->validar)($validados, $registro)];
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return $validados;
    }

    /** Llaves compuestas (p. ej. clave + año): no puede haber otro registro con la misma combinación. */
    private function erroresDeLlave(array $datos, ?Model $registro): array
    {
        if (count($this->llave) < 2) {
            return [];
        }

        $existente = $this->buscarPorLlave($datos);

        if ($existente && (! $registro || $existente->getKey() != $registro->getKey())) {
            $etiquetas = array_map(fn ($c) => mb_strtolower($this->campo($c)?->etiqueta ?? $c), $this->llave);

            return [$this->llave[0] => 'Ya existe un registro con la misma combinación de '.implode(', ', $etiquetas).'.'];
        }

        return [];
    }

    public function buscarPorLlave(array $datos): ?Model
    {
        if ($this->llave === []) {
            return null;
        }

        $query = $this->modelo::query();

        foreach ($this->llave as $columna) {
            if (! isset($datos[$columna]) || $datos[$columna] === '') {
                return null;
            }

            $query->where($columna, $datos[$columna]);
        }

        return $query->first();
    }

    public function crear(array $datos): Model
    {
        return DB::transaction(function () use ($datos) {
            $registro = $this->nuevoModelo();
            $registro->fill([...$this->valoresFijos, ...$datos]);

            // Las tablas del sistema anterior no tienen AUTO_INCREMENT: el id se calcula.
            if (! $registro->getIncrementing()) {
                $max = $this->modelo::query()->lockForUpdate()->max($registro->getKeyName()) ?? 0;
                $registro->setAttribute($registro->getKeyName(), $max + 1);
            }

            $registro->save();
            $this->despuesDeGuardar && ($this->despuesDeGuardar)($registro);

            return $registro;
        });
    }

    public function actualizar(Model $registro, array $datos): Model
    {
        return DB::transaction(function () use ($registro, $datos) {
            $registro->fill($datos)->save();
            $this->despuesDeGuardar && ($this->despuesDeGuardar)($registro);

            return $registro;
        });
    }

    /** Valor para el formulario (fechas como Y-m-d). */
    public function valorDe(Model $registro, Campo $campo): mixed
    {
        $valor = $registro->getAttribute($campo->nombre);

        return $valor instanceof \DateTimeInterface ? $valor->format('Y-m-d') : $valor;
    }

    /** Texto para la tabla del listado. */
    public function textoDe(Model $registro, Campo $campo, array $opcionesRelacion): ?string
    {
        if ($campo->tipo === 'calculado') {
            return ($campo->calcular)($registro);
        }

        $valor = $this->valorDe($registro, $campo);

        if ($valor === null || $valor === '') {
            return null;
        }

        return match ($campo->tipo) {
            'booleano' => $valor ? 'Sí' : 'No',
            'seleccion' => $campo->opciones[$valor] ?? (string) $valor,
            'relacion' => $opcionesRelacion[$campo->nombre][$valor] ?? (string) $valor,
            'fecha' => Carbon::parse($valor)->format('d/m/Y'),
            default => (string) $valor,
        };
    }
}
