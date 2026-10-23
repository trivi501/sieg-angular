<?php

namespace App\Catalogos;

use App\Models\Banco;
use App\Models\CalendarioCaptura;
use App\Models\CalendarioModificaciones;
use App\Models\Departamento;
use App\Models\EgresoFuenteFinanciamiento;
use App\Models\EgresoObjetoGasto;
use App\Models\EgresoPrograma;
use App\Models\EgresoProyecto;
use App\Models\EgresoUnidadAdministrativa;
use App\Models\EjercicioFiscal;
use App\Models\Firmante;
use App\Models\Proveedor;
use App\Models\ProveedorCuenta;
use App\Models\Regidor;
use App\Models\TipoDocumento;
use App\Models\User;
use App\Models\Vehiculo;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de los catálogos administrables. Agregar uno aquí le da pantalla, validación,
 * importación desde Excel, historial y permisos (después de `php artisan permissions:sync`).
 */
final class Catalogos
{
    public const GRUPOS = [
        'Estructura orgánica',
        'Clasificación presupuestal',
        'Ejercicios y documentos',
        'Proveedores y bancos',
        'Padrón vehicular',
    ];

    /** @var array<string, Catalogo>|null */
    private static ?array $catalogos = null;

    /** @return array<string, Catalogo> */
    public static function todos(): array
    {
        return self::$catalogos ??= collect(self::definir())->keyBy('slug')->all();
    }

    public static function buscar(string $slug): ?Catalogo
    {
        return self::todos()[$slug] ?? null;
    }

    /** Catálogo al que pertenece un modelo (para la bitácora de auditoría). */
    public static function deModelo(string $modelo): ?Catalogo
    {
        foreach (self::todos() as $catalogo) {
            if ($catalogo->modelo === $modelo) {
                return $catalogo;
            }
        }

        return null;
    }

    public static function permisos(): array
    {
        return collect(self::todos())->flatMap(fn (Catalogo $c) => $c->permisos())->unique()->values()->all();
    }

    /** @return Catalogo[] */
    private static function definir(): array
    {
        return [
            // Estructura orgánica
            new Catalogo(
                slug: 'unidades-administrativas',
                titulo: 'Secretarías y direcciones',
                singular: 'secretaría o dirección',
                grupo: 'Estructura orgánica',
                descripcion: 'Dependencias del Ayuntamiento y centros gestores del presupuesto (la clave es la columna CLAVE del presupuesto importado). Cada usuario pertenece a una y solo ve sus requisiciones y presupuesto.',
                modelo: EgresoUnidadAdministrativa::class,
                campos: [
                    Campo::texto('clave', 'Clave')->requerido()->max(20),
                    Campo::texto('nombre', 'Nombre')->requerido()->max(1500)->mayusculas(),
                    Campo::texto('prefijo', 'Prefijo de folio')->max(10)->mayusculas()
                        ->ayuda('Prefijo de los folios de sus requisiciones (p. ej. TES-0001). Vacío = REQ.'),
                    Campo::entero('año', 'Año')->requerido()->min(2000)->max(2100)->porDefecto(fn () => (int) date('Y')),
                    Campo::texto('responsable', 'Titular')->max(200),
                    Campo::texto('cargo_responsable', 'Cargo del titular')->max(200)->oculto(),
                    Campo::calculado('usuarios', 'Usuarios', fn ($r) => (string) User::where('id_cat_egreso_unidad_administrativa', $r->getKey())->count()),
                    Campo::activo(),
                ],
                buscar: ['clave', 'nombre', 'responsable', 'prefijo'],
                llave: ['clave', 'año'],
                valoresFijos: ['id_cat_area_x_nombre_y_puesto' => 1],
                orden: ['año' => 'desc', 'clave' => 'asc'],
            ),
            new Catalogo(
                slug: 'departamentos',
                titulo: 'Departamentos',
                singular: 'departamento',
                grupo: 'Estructura orgánica',
                descripcion: 'Áreas dentro de cada secretaría o dirección.',
                modelo: Departamento::class,
                campos: [
                    Campo::relacion('id_cat_egreso_unidad_administrativa', 'Secretaría o dirección', EgresoUnidadAdministrativa::class, ['clave', 'nombre'], 'clave')->requerido(),
                    Campo::texto('clave', 'Clave')->max(20),
                    Campo::texto('nombre', 'Nombre')->requerido()->max(300)->mayusculas(),
                    Campo::texto('responsable', 'Responsable')->max(200),
                    Campo::activo(),
                ],
                buscar: ['clave', 'nombre', 'responsable', 'unidadAdministrativa.nombre'],
                llave: ['id_cat_egreso_unidad_administrativa', 'nombre'],
            ),
            new Catalogo(
                slug: 'regidores',
                titulo: 'Regidores',
                singular: 'regidor',
                grupo: 'Estructura orgánica',
                descripcion: 'Integrantes del Cabildo. Sus enlaces (usuarios) les pertenecen: el regidor firma sus requisiciones y CLC; para el presupuesto quedan en Cabildo.',
                modelo: Regidor::class,
                campos: [
                    Campo::texto('titulo', 'Título')->max(30)->mayusculas()->ayuda('Ej. LIC., MTRA., ING.'),
                    Campo::texto('nombre', 'Nombre')->requerido()->max(200)->mayusculas(),
                    Campo::seleccion('cargo', 'Cargo', ['REGIDOR' => 'Regidor', 'REGIDORA' => 'Regidora'])->requerido()->porDefecto('REGIDOR'),
                    Campo::texto('comision', 'Comisión')->max(300),
                    Campo::calculado('enlaces', 'Enlaces', fn ($r) => (string) User::where('regidor_id', $r->getKey())->count()),
                    Campo::activo(),
                ],
                buscar: ['nombre', 'comision'],
                llave: ['nombre'],
            ),
            new Catalogo(
                slug: 'firmantes',
                titulo: 'Responsables y firmantes',
                singular: 'firmante',
                grupo: 'Estructura orgánica',
                descripcion: 'Quién firma cada documento impreso. Si se indica secretaría o dirección, tiene prioridad sobre el firmante general.',
                modelo: Firmante::class,
                campos: [
                    Campo::relacion('tipo_documento_id', 'Documento', TipoDocumento::class, ['nombre'], 'clave')->requerido(),
                    Campo::seleccion('rol', 'Firma como', Firmante::ROLES)->requerido(),
                    Campo::relacion('id_cat_egreso_unidad_administrativa', 'Secretaría o dirección', EgresoUnidadAdministrativa::class, ['clave', 'nombre'], 'clave')
                        ->ayuda('Vacío = aplica a todas las secretarías y direcciones.'),
                    Campo::texto('nombre', 'Nombre')->requerido()->max(200),
                    Campo::texto('cargo', 'Cargo')->requerido()->max(200),
                    Campo::fecha('vigente_desde', 'Vigente desde')->requerido()->porDefecto(fn () => now('America/Mexico_City')->toDateString()),
                    Campo::fecha('vigente_hasta', 'Vigente hasta')->reglas(['after_or_equal:vigente_desde'])->ayuda('Vacío = sigue vigente.'),
                    Campo::activo(),
                ],
                buscar: ['nombre', 'cargo', 'tipoDocumento.nombre'],
                llave: [],
                orden: ['tipo_documento_id' => 'asc', 'rol' => 'asc', 'vigente_desde' => 'desc'],
            ),

            // Clasificación presupuestal
            new Catalogo(
                slug: 'objeto-gasto',
                titulo: 'Clasificador por Objeto del Gasto',
                singular: 'partida',
                grupo: 'Clasificación presupuestal',
                descripcion: 'Partidas específicas (CONAC). Capítulo, concepto y partida genérica se derivan de la clave.',
                modelo: EgresoObjetoGasto::class,
                campos: [
                    Campo::texto('clave', 'Partida')->requerido()->max(20)->unico(),
                    Campo::texto('nombre', 'Nombre')->requerido()->max(1500)->mayusculas(),
                    Campo::calculado('capitulo', 'Capítulo', fn ($r) => EgresoObjetoGasto::niveles($r->clave)['capitulo']),
                    Campo::calculado('concepto', 'Concepto', fn ($r) => EgresoObjetoGasto::niveles($r->clave)['concepto']),
                    Campo::calculado('generica', 'Partida genérica', fn ($r) => EgresoObjetoGasto::niveles($r->clave)['generica']),
                    Campo::seleccion('tipo_gasto', 'Tipo de gasto', EgresoObjetoGasto::TIPOS_GASTO)->requerido()->porDefecto('1'),
                    Campo::activo(),
                ],
                buscar: ['clave', 'nombre'],
                orden: ['clave' => 'asc'],
            ),
            new Catalogo(
                slug: 'fuentes-financiamiento',
                titulo: 'Fuentes de financiamiento',
                singular: 'fuente de financiamiento',
                grupo: 'Clasificación presupuestal',
                descripcion: 'La clave es la de la columna CLAVE3 del presupuesto importado.',
                modelo: EgresoFuenteFinanciamiento::class,
                campos: [
                    Campo::texto('clave', 'Clave')->requerido()->max(20)->unico(),
                    Campo::texto('nombre', 'Nombre')->requerido()->max(1500)->mayusculas(),
                    Campo::activo(),
                ],
                buscar: ['clave', 'nombre'],
                orden: ['clave' => 'asc'],
            ),
            new Catalogo(
                slug: 'programas',
                titulo: 'Programas presupuestarios',
                singular: 'programa',
                grupo: 'Clasificación presupuestal',
                modelo: EgresoPrograma::class,
                campos: [
                    Campo::texto('clave', 'Clave')->requerido()->max(20)->unico(),
                    Campo::texto('nombre', 'Nombre')->requerido()->max(500)->mayusculas(),
                    Campo::activo(),
                ],
                buscar: ['clave', 'nombre'],
                orden: ['clave' => 'asc'],
            ),
            new Catalogo(
                slug: 'proyectos',
                titulo: 'Proyectos',
                singular: 'proyecto',
                grupo: 'Clasificación presupuestal',
                descripcion: 'La clave es la de la columna CLAVE6 del presupuesto importado.',
                modelo: EgresoProyecto::class,
                campos: [
                    Campo::texto('clave', 'Clave')->requerido()->max(20)->unico(),
                    Campo::texto('nombre', 'Nombre')->requerido()->max(500)->mayusculas(),
                    Campo::activo(),
                ],
                buscar: ['clave', 'nombre'],
                orden: ['clave' => 'asc'],
            ),

            // Ejercicios y documentos
            new Catalogo(
                slug: 'ejercicios',
                titulo: 'Ejercicios fiscales',
                singular: 'ejercicio fiscal',
                grupo: 'Ejercicios y documentos',
                descripcion: 'Un ejercicio cerrado ya no admite requisiciones nuevas. El periodo de captura (días de cada mes) define qué requisiciones son extemporáneas.',
                modelo: EjercicioFiscal::class,
                campos: [
                    Campo::entero('año', 'Año')->requerido()->min(2000)->max(2100)->unico()->porDefecto(fn () => (int) date('Y')),
                    Campo::texto('descripcion', 'Descripción')->max(200),
                    Campo::seleccion('estado', 'Estado', ['abierto' => 'Abierto', 'cerrado' => 'Cerrado'])->requerido()->porDefecto('abierto'),
                    Campo::entero('captura_dia_inicio', 'Captura de requisiciones: del día')->requerido()->min(1)->max(31)->porDefecto(EjercicioFiscal::CAPTURA_DIA_INICIO),
                    Campo::entero('captura_dia_fin', 'Captura de requisiciones: al día')->requerido()->min(1)->max(31)->porDefecto(EjercicioFiscal::CAPTURA_DIA_FIN)
                        ->ayuda('Solo para los meses sin registro en el Calendario de captura de requisiciones. Fuera del periodo, la requisición queda extemporánea.'),
                    Campo::activo(),
                ],
                buscar: ['año', 'descripcion'],
                llave: ['año'],
                orden: ['año' => 'desc'],
                validar: function (array $datos) {
                    if (isset($datos['captura_dia_inicio'], $datos['captura_dia_fin']) && (int) $datos['captura_dia_inicio'] > (int) $datos['captura_dia_fin']) {
                        return ['captura_dia_fin' => 'El último día de captura no puede ser anterior al primero.'];
                    }

                    return [];
                },
            ),
            new Catalogo(
                slug: 'calendario-captura',
                titulo: 'Calendario de captura de requisiciones',
                singular: 'periodo de captura',
                grupo: 'Ejercicios y documentos',
                descripcion: 'Inicio y fin del periodo de captura de requisiciones de cada mes. Fuera de él la requisición queda extemporánea (necesita autorización de la Subdirección Administrativa). Un mes sin registrar usa los días del ejercicio fiscal.',
                modelo: CalendarioCaptura::class,
                campos: [
                    Campo::entero('año', 'Año')->requerido()->min(2000)->max(2100)->porDefecto(fn () => (int) date('Y')),
                    Campo::seleccion('mes', 'Mes', CalendarioCaptura::MESES)->requerido()->porDefecto(fn () => (string) now('America/Mexico_City')->month),
                    Campo::fecha('fecha_inicio', 'Inicio de captura')->requerido(),
                    Campo::fecha('fecha_fin', 'Fin de captura')->requerido()
                        ->ayuda('Inclusive: ese día todavía se captura en tiempo.'),
                    Campo::activo(),
                ],
                buscar: ['año'],
                llave: ['año', 'mes'],
                orden: ['año' => 'desc', 'mes' => 'asc'],
                validar: function (array $datos, $registro = null) {
                    $errores = [];
                    if (! empty($datos['fecha_inicio']) && ! empty($datos['fecha_fin']) && $datos['fecha_inicio'] > $datos['fecha_fin']) {
                        $errores['fecha_fin'] = 'El fin de captura no puede ser anterior al inicio.';
                    }
                    if (isset($datos['año'], $datos['mes']) && CalendarioCaptura::where('año', $datos['año'])->where('mes', $datos['mes'])
                        ->when($registro, fn ($q) => $q->whereKeyNot($registro->getKey()))->exists()) {
                        $errores['mes'] = 'Ese mes ya tiene su periodo de captura; edítalo.';
                    }

                    return $errores;
                },
            ),
            new Catalogo(
                slug: 'calendario-modificaciones',
                titulo: 'Calendario de captura de modificaciones',
                singular: 'periodo de captura',
                grupo: 'Ejercicios y documentos',
                descripcion: 'Inicio y fin del periodo en que las áreas capturan modificaciones presupuestales de cada mes. Fuera de él solo Control Presupuestal y Admin pueden registrarlas. Un mes sin registrar va del día 1 hasta 3 días antes del fin de mes.',
                modelo: CalendarioModificaciones::class,
                campos: [
                    Campo::entero('año', 'Año')->requerido()->min(2000)->max(2100)->porDefecto(fn () => (int) date('Y')),
                    Campo::seleccion('mes', 'Mes', CalendarioCaptura::MESES)->requerido()->porDefecto(fn () => (string) now('America/Mexico_City')->month),
                    Campo::fecha('fecha_inicio', 'Inicio de captura')->requerido(),
                    Campo::fecha('fecha_fin', 'Fin de captura')->requerido()
                        ->ayuda('Inclusive: ese día el área todavía puede capturar.'),
                    Campo::activo(),
                ],
                buscar: ['año'],
                llave: ['año', 'mes'],
                orden: ['año' => 'desc', 'mes' => 'asc'],
                validar: function (array $datos, $registro = null) {
                    $errores = [];
                    if (! empty($datos['fecha_inicio']) && ! empty($datos['fecha_fin']) && $datos['fecha_inicio'] > $datos['fecha_fin']) {
                        $errores['fecha_fin'] = 'El fin de captura no puede ser anterior al inicio.';
                    }
                    if (isset($datos['año'], $datos['mes']) && CalendarioModificaciones::where('año', $datos['año'])->where('mes', $datos['mes'])
                        ->when($registro, fn ($q) => $q->whereKeyNot($registro->getKey()))->exists()) {
                        $errores['mes'] = 'Ese mes ya tiene su periodo de captura; edítalo.';
                    }

                    return $errores;
                },
            ),
            new Catalogo(
                slug: 'tipos-documento',
                titulo: 'Tipos de documento',
                singular: 'tipo de documento',
                grupo: 'Ejercicios y documentos',
                descripcion: 'Las claves requisicion, orden_compra y modificacion_presupuestal las usan los PDF para buscar a sus firmantes.',
                modelo: TipoDocumento::class,
                campos: [
                    Campo::texto('clave', 'Clave')->requerido()->max(40)->unico()->regex('/^[a-z0-9_]+$/', 'La clave solo admite minúsculas, números y guion bajo.'),
                    Campo::texto('nombre', 'Nombre')->requerido()->max(150),
                    Campo::texto('prefijo', 'Prefijo de folio')->max(10)->mayusculas(),
                    Campo::activo(),
                ],
                buscar: ['clave', 'nombre'],
            ),

            // Proveedores y bancos
            new Catalogo(
                slug: 'proveedores',
                titulo: 'Proveedores',
                singular: 'proveedor',
                grupo: 'Proveedores y bancos',
                descripcion: 'El RFC se usa para validar que la factura (XML) la emita este proveedor.',
                modelo: Proveedor::class,
                permiso: 'proveedores',
                campos: [
                    Campo::texto('nombre', 'Nombre o razón social')->requerido()->max(300),
                    Campo::texto('rfc', 'RFC')->max(13)->unico()->mayusculas()->regex('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/', 'El RFC no tiene un formato válido.'),
                    Campo::texto('correo', 'Correo')->max(150)->reglas(['email']),
                    Campo::texto('telefono', 'Teléfono')->max(30),
                    Campo::texto('domicilio', 'Domicilio')->max(500)->oculto(),
                    Campo::relacion('id_cat_banco', 'Banco', Banco::class, ['clave', 'descripcion'], 'clave')
                        ->ayuda('Si se deja vacío, se toma de los 3 primeros dígitos de la CLABE.'),
                    Campo::texto('clabe', 'CLABE')->max(18)->unico()->regex('/^\d{18}$/', 'La CLABE debe tener 18 dígitos.'),
                    Campo::texto('cuenta_bancaria', 'Número de cuenta')->max(20)->regex('/^\d+$/', 'El número de cuenta solo lleva dígitos.'),
                    Campo::fecha('vigencia', 'Vigencia')
                        ->ayuda('Hasta qué fecha está vigente en el padrón de proveedores. Vencida, no se le asigna orden de compra. Vacía, no vence.'),
                    Campo::activo(),
                ],
                buscar: ['nombre', 'rfc', 'clabe', 'cuenta_bancaria'],
                llave: ['rfc'],
                preparar: function (array $datos) {
                    if (empty($datos['id_cat_banco']) && ! empty($datos['clabe']) && Schema::hasTable('cat_banco')) {
                        $datos['id_cat_banco'] = Banco::where('clave', substr($datos['clabe'], 0, 3))->value('id_cat_banco');
                    }

                    return $datos;
                },
                validar: function (array $datos) {
                    if (! empty($datos['clabe']) && ! ProveedorCuenta::clabeValida($datos['clabe'])) {
                        return ['clabe' => 'La CLABE no es válida (el dígito verificador no coincide).'];
                    }

                    return [];
                },
            ),
            new Catalogo(
                slug: 'cuentas-bancarias',
                titulo: 'Cuentas bancarias de proveedores',
                singular: 'cuenta bancaria',
                grupo: 'Proveedores y bancos',
                descripcion: 'El banco se toma de los 3 primeros dígitos de la CLABE si no se indica.',
                modelo: ProveedorCuenta::class,
                campos: [
                    Campo::relacion('proveedor_id', 'Proveedor', Proveedor::class, ['nombre', 'rfc'], 'rfc')->requerido()
                        ->ayuda('Al importar, el proveedor se identifica por su RFC.'),
                    Campo::relacion('id_cat_banco', 'Banco', Banco::class, ['clave', 'descripcion'], 'clave'),
                    Campo::texto('clabe', 'CLABE')->max(18)->unico()->regex('/^\d{18}$/', 'La CLABE debe tener 18 dígitos.'),
                    Campo::texto('cuenta', 'Número de cuenta')->max(20),
                    Campo::texto('sucursal', 'Sucursal')->max(100)->oculto(),
                    Campo::booleano('principal', 'Cuenta principal', false),
                    Campo::activo(),
                ],
                buscar: ['clabe', 'cuenta', 'proveedor.nombre', 'proveedor.rfc'],
                llave: ['clabe'],
                orden: ['proveedor_id' => 'asc'],
                preparar: function (array $datos) {
                    if (empty($datos['id_cat_banco']) && ! empty($datos['clabe']) && Schema::hasTable('cat_banco')) {
                        $datos['id_cat_banco'] = Banco::where('clave', substr($datos['clabe'], 0, 3))->value('id_cat_banco');
                    }

                    return $datos;
                },
                validar: function (array $datos) {
                    $errores = [];

                    if (empty($datos['clabe']) && empty($datos['cuenta'])) {
                        $errores['clabe'] = 'Captura la CLABE o el número de cuenta.';
                    }

                    if (! empty($datos['clabe']) && ! ProveedorCuenta::clabeValida($datos['clabe'])) {
                        $errores['clabe'] = 'La CLABE no es válida (el dígito verificador no coincide).';
                    }

                    return $errores;
                },
                despuesDeGuardar: function (ProveedorCuenta $cuenta) {
                    // Solo una cuenta principal por proveedor.
                    if ($cuenta->principal) {
                        ProveedorCuenta::where('proveedor_id', $cuenta->proveedor_id)
                            ->whereKeyNot($cuenta->getKey())
                            ->where('principal', true)
                            ->get()
                            ->each(fn ($otra) => $otra->update(['principal' => false]));
                    }
                },
            ),
            new Catalogo(
                slug: 'bancos',
                titulo: 'Bancos',
                singular: 'banco',
                grupo: 'Proveedores y bancos',
                descripcion: 'La clave de 3 dígitos es con la que empieza la CLABE.',
                modelo: Banco::class,
                campos: [
                    Campo::texto('clave', 'Clave')->max(3)->unico()->regex('/^\d{3}$/', 'La clave del banco tiene 3 dígitos.'),
                    Campo::texto('descripcion', 'Nombre')->requerido()->max(500)->mayusculas(),
                    Campo::activo(),
                ],
                buscar: ['clave', 'descripcion'],
                orden: ['descripcion' => 'asc'],
            ),

            // Padrón vehicular
            new Catalogo(
                slug: 'vehiculos',
                titulo: 'Padrón vehicular',
                singular: 'vehículo',
                grupo: 'Padrón vehicular',
                descripcion: 'Cada vehículo es resguardo de una secretaría y está asignado a un área (departamento) de ella. Sus requisiciones de mantenimiento van a nombre de la secretaría de resguardo. Los cambios quedan en el historial de cada vehículo.',
                modelo: Vehiculo::class,
                campos: [
                    Campo::texto('numero_economico', 'Número económico')->requerido()->max(20)->unico()->mayusculas(),
                    Campo::texto('placas', 'Placas')->max(15)->unico()->mayusculas(),
                    Campo::texto('serie', 'Número de serie (NIV)')->max(30)->unico()->mayusculas()->oculto(),
                    Campo::texto('marca', 'Marca')->max(60)->mayusculas(),
                    Campo::texto('linea', 'Línea')->max(80)->mayusculas(),
                    Campo::entero('modelo', 'Modelo (año)')->min(1950)->max(2100),
                    Campo::texto('color', 'Color')->max(40)->mayusculas()->oculto(),
                    Campo::texto('tipo', 'Tipo')->max(40)->mayusculas()->oculto(),
                    Campo::relacion('id_cat_egreso_unidad_administrativa', 'Secretaría (resguardo)', EgresoUnidadAdministrativa::class, ['clave', 'nombre'], 'clave')
                        ->ayuda('Necesaria para recibirlo en el taller: su requisición va a nombre de esta secretaría.'),
                    Campo::relacion('departamento_id', 'Área asignada', Departamento::class, ['nombre'])
                        ->ayuda('Departamento de la secretaría de resguardo al que está asignado.'),
                    Campo::texto('resguardante', 'Resguardante')->max(200)->mayusculas(),
                    Campo::texto('cargo_resguardante', 'Cargo del resguardante')->max(200)->oculto(),
                    Campo::texto('numero_resguardo', 'Número de resguardo')->max(40)->oculto(),
                    Campo::fecha('fecha_resguardo', 'Fecha de resguardo')->oculto(),
                    Campo::seleccion('estado', 'Estado', Vehiculo::ESTADOS)->requerido()->porDefecto('en_servicio'),
                    Campo::textoLargo('observaciones', 'Observaciones')->max(2000),
                    Campo::activo(),
                ],
                buscar: ['numero_economico', 'placas', 'serie', 'marca', 'resguardante'],
                llave: ['numero_economico'],
                orden: ['numero_economico' => 'asc'],
                validar: function (array $datos) {
                    // El área asignada debe ser de la secretaría de resguardo.
                    if (! empty($datos['departamento_id']) && ! empty($datos['id_cat_egreso_unidad_administrativa'])
                        && (int) Departamento::whereKey($datos['departamento_id'])->value('id_cat_egreso_unidad_administrativa') !== (int) $datos['id_cat_egreso_unidad_administrativa']) {
                        return ['departamento_id' => 'El área asignada no pertenece a la secretaría de resguardo.'];
                    }

                    return [];
                },
            ),
        ];
    }
}
