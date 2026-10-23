<?php

namespace App\Catalogos;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Un campo de un catálogo: de aquí salen la columna de la tabla, el control del formulario,
 * las reglas de validación y la columna de la plantilla de importación.
 */
final class Campo
{
    public bool $requerido = false;

    public ?int $max = null;

    public ?int $min = null;

    public bool $unico = false;

    public bool $enLista = true;

    public bool $mayusculas = false;

    public ?string $regex = null;

    public ?string $mensajeRegex = null;

    public ?string $ayuda = null;

    public mixed $porDefecto = null;

    /** @var array<string|int, string> valor => etiqueta (tipo seleccion) */
    public array $opciones = [];

    /** @var class-string<Model>|null tipo relacion */
    public ?string $modelo = null;

    /** @var string[] columnas del modelo relacionado que forman su etiqueta */
    public array $columnasEtiqueta = ['nombre'];

    /** Columna del modelo relacionado con la que se busca el valor de una celda al importar. */
    public ?string $importarPor = null;

    public ?Closure $calcular = null;

    public array $reglasExtra = [];

    private function __construct(public string $nombre, public string $etiqueta, public string $tipo) {}

    public static function texto(string $nombre, string $etiqueta): self
    {
        return new self($nombre, $etiqueta, 'texto');
    }

    public static function textoLargo(string $nombre, string $etiqueta): self
    {
        return (new self($nombre, $etiqueta, 'texto_largo'))->oculto();
    }

    public static function entero(string $nombre, string $etiqueta): self
    {
        return new self($nombre, $etiqueta, 'entero');
    }

    public static function fecha(string $nombre, string $etiqueta): self
    {
        return new self($nombre, $etiqueta, 'fecha');
    }

    public static function booleano(string $nombre, string $etiqueta, bool $porDefecto = true): self
    {
        return (new self($nombre, $etiqueta, 'booleano'))->porDefecto($porDefecto);
    }

    public static function activo(): self
    {
        // La lista ya muestra la columna Estatus.
        return self::booleano('activo', 'Activo')->oculto();
    }

    public static function seleccion(string $nombre, string $etiqueta, array $opciones): self
    {
        $campo = new self($nombre, $etiqueta, 'seleccion');
        $campo->opciones = $opciones;

        return $campo;
    }

    /** @param class-string<Model> $modelo */
    public static function relacion(string $nombre, string $etiqueta, string $modelo, array $columnasEtiqueta = ['nombre'], ?string $importarPor = null): self
    {
        $campo = new self($nombre, $etiqueta, 'relacion');
        $campo->modelo = $modelo;
        $campo->columnasEtiqueta = $columnasEtiqueta;
        $campo->importarPor = $importarPor ?? $columnasEtiqueta[0];

        return $campo;
    }

    /** Columna de solo lectura que se calcula a partir del registro. */
    public static function calculado(string $nombre, string $etiqueta, Closure $calcular): self
    {
        $campo = new self($nombre, $etiqueta, 'calculado');
        $campo->calcular = $calcular;

        return $campo;
    }

    public function requerido(): self
    {
        $this->requerido = true;

        return $this;
    }

    public function max(int $max): self
    {
        $this->max = $max;

        return $this;
    }

    public function min(int $min): self
    {
        $this->min = $min;

        return $this;
    }

    public function unico(): self
    {
        $this->unico = true;

        return $this;
    }

    public function mayusculas(): self
    {
        $this->mayusculas = true;

        return $this;
    }

    public function regex(string $regex, string $mensaje): self
    {
        $this->regex = $regex;
        $this->mensajeRegex = $mensaje;

        return $this;
    }

    public function ayuda(string $ayuda): self
    {
        $this->ayuda = $ayuda;

        return $this;
    }

    public function porDefecto(mixed $valor): self
    {
        $this->porDefecto = $valor;

        return $this;
    }

    public function oculto(): self
    {
        $this->enLista = false;

        return $this;
    }

    public function reglas(array $reglas): self
    {
        $this->reglasExtra = $reglas;

        return $this;
    }

    public function editable(): bool
    {
        return $this->tipo !== 'calculado';
    }

    public function tablaRelacionDisponible(): bool
    {
        return $this->modelo !== null && Schema::hasTable((new $this->modelo)->getTable());
    }

    /** Reglas de Laravel para este campo; $registro es el que se edita (para ignorarlo en `unique`). */
    public function reglasValidacion(string $tabla, ?Model $registro): array
    {
        $reglas = [$this->requerido ? 'required' : 'nullable'];

        $reglas[] = match ($this->tipo) {
            'entero' => 'integer',
            'fecha' => 'date',
            'booleano' => 'boolean',
            default => 'string',
        };

        if ($this->max !== null) {
            $reglas[] = "max:{$this->max}";
        }

        if ($this->min !== null) {
            $reglas[] = "min:{$this->min}";
        }

        if ($this->tipo === 'seleccion') {
            $reglas[array_key_last($reglas)] = Rule::in(array_map('strval', array_keys($this->opciones)));
        }

        if ($this->tipo === 'relacion') {
            array_pop($reglas);
            $reglas[] = 'integer';

            if ($this->tablaRelacionDisponible()) {
                $relacionado = new $this->modelo;
                $reglas[] = Rule::exists($relacionado->getTable(), $relacionado->getKeyName());
            }
        }

        if ($this->regex !== null) {
            $reglas[] = "regex:{$this->regex}";
        }

        if ($this->unico) {
            $unica = Rule::unique($tabla, $this->nombre);

            if ($registro?->exists) {
                $unica->ignore($registro->getKey(), $registro->getKeyName());
            }

            $reglas[] = $unica;
        }

        return [...$reglas, ...$this->reglasExtra];
    }

    /** Opciones [valor => etiqueta] de una relación (todas, para mostrar también las inactivas ya asignadas). */
    public function opcionesRelacion(): array
    {
        if (! $this->tablaRelacionDisponible()) {
            return [];
        }

        $relacionado = new $this->modelo;
        $columnas = array_unique([$relacionado->getKeyName(), ...$this->columnasEtiqueta]);

        return $this->modelo::query()
            ->orderBy($this->columnasEtiqueta[0])
            ->get($columnas)
            ->mapWithKeys(fn ($m) => [$m->getKey() => $this->etiquetaDe($m)])
            ->all();
    }

    public function etiquetaDe(Model $relacionado): string
    {
        return collect($this->columnasEtiqueta)
            ->map(fn ($c) => $relacionado->getAttribute($c))
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->implode(' — ');
    }

    /** Para el formulario genérico del frontend. */
    public function paraFrontend(?array $opcionesRelacion = null): array
    {
        $opciones = match ($this->tipo) {
            'seleccion' => $this->opciones,
            'relacion' => $opcionesRelacion ?? $this->opcionesRelacion(),
            default => null,
        };

        return [
            'nombre' => $this->nombre,
            'etiqueta' => $this->etiqueta,
            'tipo' => $this->tipo,
            'requerido' => $this->requerido,
            'max' => $this->max,
            'enLista' => $this->enLista,
            'editable' => $this->editable(),
            'mayusculas' => $this->mayusculas,
            'ayuda' => $this->ayuda,
            'porDefecto' => $this->porDefecto instanceof Closure ? ($this->porDefecto)() : $this->porDefecto,
            'opciones' => $opciones === null ? null : collect($opciones)
                ->map(fn ($etiqueta, $valor) => ['valor' => (string) $valor, 'etiqueta' => (string) $etiqueta])
                ->values()
                ->all(),
        ];
    }
}
