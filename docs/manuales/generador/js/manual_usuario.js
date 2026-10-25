// Manual de usuario de SIEG (con capturas de la base de demostración).
const { B, portada, documento, guardar, reiniciar } = require('./base');
const { h1, h2, h3, p, l, n, nota, img, tabla } = B;

reiniciar();
const contenido = [
  portada('Manual de usuario', 'Operación del sistema por área: requisiciones, CLC, trámites, combustible, Tesorería y padrón de proveedores', 'Versión 1.0 · Octubre de 2026'),

  // ------------------------------------------------------------------ 1
  h1('1. Introducción'),
  p('SIEG (Sistema Integral de Egresos Municipales) concentra en un solo lugar el ciclo del gasto del Ayuntamiento de Guadalupe: desde que un área pide un bien o servicio hasta que Tesorería lo paga y lo concilia con el banco.'),
  p('El ciclo completo es:'),
  p('**Requisición → Suficiencia presupuestal → Orden de compra → Recepción → Factura → Compromiso → Devengado → CLC → Pago → Conciliación**'),
  p('Además del ciclo de compra, el sistema incluye la bitácora de combustible por secretaría, el taller de mantenimiento vehicular, los trámites de servicios (con factura previa), los fondos revolventes, los arrendamientos y servicios por contrato, el padrón de proveedores del Órgano Interno de Control y la mesa de control de Tesorería.'),
  h2('1.1 A quién va dirigido'),
  p('A todo el personal que usa SIEG. Cada capítulo indica qué área lo opera. Lo que cada persona ve en el menú depende de los permisos de su rol (ver el Anexo A).'),
  tabla(['Área', 'Qué hace en SIEG'], [
    ['Áreas solicitantes (enlaces administrativos)', 'Capturan requisiciones, trámites de servicios, fondos revolventes, contratos y su bitácora de combustible; generan la CLC de sus compras.'],
    ['Subdirección Administrativa', 'Valida requisiciones y autoriza las extemporáneas.'],
    ['Recursos Materiales', 'Recibe y cotiza requisiciones, genera órdenes de compra, carga facturas, valida trámites y bitácoras, autoriza contratos y opera el almacén.'],
    ['Control Presupuestal', 'Valida la suficiencia presupuestal, autoriza modificaciones y revisa las CLC.'],
    ['Sindicatura', 'Firma las CLC (recibe y regresa las relaciones).'],
    ['Egresos / Tesorería', 'Recibe las CLC, paga a proveedores, concilia con el estado de cuenta y emite los reportes bancarios.'],
    ['Control de combustible', 'Sube las facturas y el Excel mensual del proveedor de combustible.'],
    ['Taller mecánico', 'Recibe vehículos, hace la requisición del mantenimiento y entrega las unidades.'],
    ['Órgano Interno de Control', 'Integra el expediente de proveedores y emite la cédula del padrón.'],
    ['Contabilidad', 'Consulta el reporte mensual de ISR retenido.']
  ], [3000, 6360]),
  h2('1.2 Convenciones de este manual'),
  l([
    'Los nombres de menús y botones van en **negritas**, tal como aparecen en pantalla.',
    'Las capturas usan **datos ficticios** de una base de demostración: personas, proveedores, RFC, folios e importes no son reales.',
    'Los recuadros de **Nota** señalan reglas del sistema que conviene conocer.'
  ]),

  // ------------------------------------------------------------------ 2
  h1('2. Acceso al sistema'),
  h2('2.1 Iniciar sesión'),
  n([
    'Abre el navegador (Chrome, Edge o Firefox actualizados) y entra a la dirección del sistema, por ejemplo **https://siem.gpe.gob.mx**.',
    'Escribe tu **Correo electrónico** y tu **Contraseña**.',
    'Presiona **Entrar**.'
  ]),
  img('01-login', 'Pantalla de inicio de sesión.'),
  nota('Si no tienes usuario o olvidaste tu contraseña, pídelo a la Subsecretaría de Egresos (administración del sistema) o levanta un ticket de soporte desde otra cuenta del área.'),
  h2('2.2 La pantalla principal'),
  p('Al entrar ves el **Inicio**. A la izquierda está el **menú**, agrupado en Principal, Egresos, Catálogos y Administración; solo aparecen las opciones que tu rol permite. Arriba a la derecha están tus **notificaciones** (campana) y tu nombre, donde se cierra la sesión.'),
  p('El Inicio resume la situación del ejercicio:'),
  l([
    '**Presupuesto de egresos**: aprobado, modificado, vigente, comprometido, en trámite, devengado, ejercido, pagado y disponible.',
    '**Requisiciones**: cuántas hay por enviar, en revisión, en Recursos Materiales, con suficiencia sin orden y modificaciones por autorizar.',
    '**CLC y facturas pendientes**: CLC en trámite por área, facturas sin CLC, fondos y bitácoras por validar.',
    '**Pagos y pasivos**: lo pagado en el mes y el año, el pasivo acumulado y los pagos en tránsito sin conciliar.',
    '**Combustible** y **Mantenimiento vehicular** del año, con el costo por secretaría.'
  ]),
  img('02-inicio', 'Inicio: panel con el resumen del presupuesto, las requisiciones, las CLC, los pagos, el combustible y el mantenimiento.'),
  p('Quien no tiene permisos de administración ve un menú reducido, solo con lo de su área:'),
  img('52-menu-solicitante', 'Menú de un área solicitante: solo las opciones de su rol.'),
  h2('2.3 Botones de acción y menú contextual'),
  l([
    'Las acciones de cada página llevan **icono y nombre** (por ejemplo **Válida**, **Imprimir formato (FUR)**). Las de las tablas solo llevan icono; al pasar el mouse se ve su nombre.',
    'En las listas de requisiciones puedes hacer **clic derecho** sobre un renglón para ver las acciones disponibles para esa requisición.',
    'Los botones solo aparecen cuando la acción es posible en ese momento y para tu rol: si no ves un botón, la requisición está en otro paso del trámite o te corresponde a otra área.'
  ]),

  // ------------------------------------------------------------------ 3
  h1('3. Requisiciones'),
  p('La requisición es el documento con el que un área pide bienes o servicios. Recorre estos estados:'),
  tabla(['Estado', 'Qué significa', 'Quién la mueve'], [
    ['Pendiente', 'Capturada, todavía no se envía.', 'Área solicitante'],
    ['En revisión', 'Enviada a validar.', 'Subdirección Administrativa'],
    ['Aprobada / Rechazada', 'Validada o regresada al área con el motivo (No válida).', 'Subdirección Administrativa'],
    ['En Recursos Materiales', 'Enviada para cotizar.', 'Área / Subdirección'],
    ['En cotización', 'Recursos Materiales la recibió y captura los precios.', 'Recursos Materiales'],
    ['Comprometida', 'Control Presupuestal validó la suficiencia: el recurso queda apartado.', 'Control Presupuestal'],
    ['Orden de compra', 'Se generó la orden al proveedor.', 'Recursos Materiales'],
    ['Con factura', 'Se cargó el XML de la factura (devengado).', 'Recursos Materiales'],
    ['CLC generada', 'El área generó la Cuenta por Liquidar Certificada.', 'Área solicitante'],
    ['Pagada', 'Tesorería registró el pago.', 'Egresos'],
    ['Cancelada', 'Se canceló con su motivo.', 'Según el paso']
  ], [2300, 4560, 2500]),
  h2('3.1 Lista de requisiciones'),
  p('En **Requisiciones → Requisiciones** ves las de tu secretaría (o todas, si tu rol lo permite). Filtra por folio, secretaría, concepto, estado o año. El color del estado ayuda a ubicar en qué paso está cada una.'),
  img('03-requisiciones', 'Lista de requisiciones con su estado, total y orden de compra o CLC.'),
  p('Con **clic derecho** sobre una requisición aparecen sus acciones: ver detalle, abrir en otra pestaña y las acciones del paso en que está (por ejemplo validar, cambiar fecha o cancelar).'),
  img('04-requisiciones-menu', 'Menú contextual de una requisición.'),
  h2('3.2 Capturar una requisición'),
  n([
    'Presiona **Nueva requisición**.',
    'Si es un apoyo a un beneficiario sin factura, marca **Sin factura (apoyo a un beneficiario)**; si no, déjalo sin marcar (lleva cotización, orden de compra y factura).',
    'Elige la **Secretaría o dirección** y la **Fecha de trámite**.',
    'Escribe el **Concepto** general.',
    'En cada renglón elige **Partida**, **Proyecto** y **Fuente** (solo aparecen las líneas de presupuesto de tu secretaría), la **Cantidad** y la **Descripción** del artículo o servicio. Agrega los renglones que necesites.',
    'Presiona **Crear requisición**. Queda en estado **Pendiente**.',
    'Revisa el detalle y presiona **Enviar a revisión**.'
  ]),
  img('05-requisicion-nueva', 'Captura de una requisición nueva. El aviso amarillo indica que se está fuera del periodo de captura.'),
  nota('Los precios no se capturan aquí: los pone Recursos Materiales al cotizar. En las requisiciones de mantenimiento vehicular sí se captura el precio desde el taller.'),
  h2('3.3 Periodo de captura y requisiciones extemporáneas'),
  p('Las requisiciones se capturan en el periodo de cada mes definido en **Requisiciones → Calendario de captura** (por omisión, del 1 al 7). Fuera de ese periodo el sistema avisa y la requisición queda **extemporánea**: no se puede enviar a revisión hasta que la Subdirección Administrativa la autorice con **Autorizar extemporánea**. Al autorizarla pasa sola a revisión y queda registrado quién y cuándo la autorizó.'),
  img('07-requisicion-extemporanea', 'Requisición extemporánea en espera de la autorización de la Subdirección Administrativa.'),
  h2('3.4 Validar una requisición (Subdirección Administrativa)'),
  p('Abre la requisición **En revisión**. Revisa los renglones, las partidas y el concepto, y elige:'),
  l(['**Válida**: pasa a Aprobada.', '**No válida**: regresa al área; escribe el motivo para que la corrija y la vuelva a enviar.']),
  img('06-requisicion-revision', 'Requisición en revisión con las acciones Válida y No válida. A la derecha, la bitácora de movimientos.'),
  p('Aprobada, se envía a Recursos Materiales con **Enviar a Recursos Materiales**.'),
  h2('3.5 Cotización (Recursos Materiales)'),
  n([
    'En **Recursos Materiales → Listado** presiona **Recibido: iniciar cotización** en la requisición. Pasa a **En cotización**.',
    'Presiona **Editar cotización**. Captura el **Precio unitario** (hasta 4 decimales) de cada renglón y marca **IVA** cuando aplique.',
    'Presiona **Guardar cotización**. El total de la requisición se calcula solo.'
  ]),
  img('08-requisicion-cotizacion', 'Cotización: precio unitario e IVA por renglón.'),
  p('Si la requisición necesita cambios del área, Recursos Materiales la puede **Regresar al área** con el motivo.'),
  h2('3.6 Suficiencia presupuestal (Control Presupuestal)'),
  p('Con la cotización capturada, Control Presupuestal presiona **Validar suficiencia presupuestal**. El sistema compara el total de cada partida contra su disponible: si alcanza, la requisición queda **Comprometida** (el recurso queda apartado); si no alcanza, el área puede **Solicitar modificación presupuestal** (capítulo 12).'),
  img('09-requisicion-comprometida', 'Requisición comprometida: la tabla de suficiencia muestra por línea lo requerido, vigente, comprometido y disponible.'),
  nota('Si cambian los precios de la cotización, la suficiencia se pierde y hay que volver a validarla.'),
  h2('3.7 Orden de compra y factura (Recursos Materiales)'),
  n([
    'En la requisición comprometida presiona **Generar orden de compra**, elige el **proveedor** y la fecha. Solo se pueden elegir proveedores activos y con cédula vigente en el padrón (capítulo 10). Si es un proveedor eventual, se puede dar de alta como **compra única** desde ahí mismo.',
    'Cuando el proveedor entrega la factura, en la sección **Factura** selecciona el **XML** y presiona **Cargar factura**.',
    'Después de cargar el XML, sube su representación impresa con **Subir PDF**.'
  ]),
  img('10-requisicion-orden', 'Orden de compra emitida, en espera de la factura (XML).'),
  p('Al cargar el XML el sistema verifica, y rechaza la factura si algo no cuadra:'),
  l([
    'Que el **total** coincida con lo cotizado en la orden (si trae ISR retenido, se compara el total más el ISR).',
    'Que el **emisor** sea el proveedor de la orden.',
    'Que esté a nombre del Ayuntamiento (**RFC del receptor**), con su **régimen fiscal** (603) y un **uso de CFDI** aceptado (G01, G03, I01–I08 o S01). Si no, el proveedor debe refacturar.',
    'Que esté **timbrada** (con UUID) y **vigente en el SAT**.',
    'Que no se haya cargado antes en otra orden o trámite (por UUID y por serie y folio).',
    'Si el emisor aparece en la lista de **EFOS** del SAT, la factura se acepta pero se muestra una advertencia.'
  ]),
  p('Con la factura cargada, el gasto pasa de comprometido a **devengado**. Desde la sección de la factura se puede **Verificar en el SAT** de nuevo, **Descargar XML**, **Ver PDF** o **Refacturar** (si el proveedor cancela y vuelve a timbrar).'),
  h2('3.8 Generar la CLC (área solicitante)'),
  p('Con la factura cargada, el área presiona **Generar CLC**. La Cuenta por Liquidar Certificada se imprime con **Imprimir CLC** y sigue su trámite físico entre áreas (capítulo 4).'),
  img('11-requisicion-pagada', 'Requisición pagada: datos, suficiencia, orden de compra, factura, CLC con todo su trámite y la bitácora completa.'),
  h2('3.9 Otras acciones'),
  l([
    '**Imprimir formato (FUR)**: el formato único de requisición en PDF.',
    '**Cambiar fecha**: la fecha de trámite (según el permiso del rol).',
    '**Cancelar requisición**: con su motivo. Si ya tiene orden o CLC, primero se cancelan esas (de lo último a lo primero).',
    '**Ejercicio y control del gasto** (menú Principal): el gasto por secretaría, partida y momento contable, exportable a Excel.'
  ]),
  img('16-gasto', 'Ejercicio y control del gasto por momento contable.'),

  // ------------------------------------------------------------------ 4
  h1('4. Trámite de CLC'),
  p('La CLC física recorre las áreas antes del pago: **área → Recursos Materiales → Control Presupuestal → Sindicatura → Control Presupuestal (firmada) → Egresos**. En **Trámite de CLC** cada área ve en la pestaña **Mi área** las CLC que le toca enviar o recibir.'),
  n([
    'Marca las CLC que vas a mover (casilla de cada renglón).',
    'Presiona la acción del paso: **Enviar a Recursos Materiales**, **Recibir**, **Enviar a Control Presupuestal**, **Enviar a Sindicatura**, **Enviar a Egresos**.',
    'Las que no marques siguen pendientes en tu bandeja.'
  ]),
  img('14-clc', 'Trámite de CLC: bandeja del área con las CLC por enviar a Recursos Materiales.'),
  l([
    'Los envíos a Sindicatura y de regreso generan una **relación** (REL-AÑO-0001) que se imprime para acompañar las CLC físicas; se consultan en la pestaña **Relaciones**.',
    'Control Presupuestal puede **Devolver a Recursos Materiales** una CLC con observaciones.',
    'La pestaña **Seguimiento** muestra dónde está cualquier CLC; el botón de Excel exporta el listado.',
    'Las CLC de trámites de servicios, fondos, contratos y combustible siguen el mismo recorrido.'
  ]),

  // ------------------------------------------------------------------ 5
  h1('5. Trámites de servicios'),
  p('Para servicios que ya se prestaron y tienen factura (por ejemplo, limpieza o mantenimiento de equipos), el área sube primero las facturas y, al validarse, el sistema genera de una vez la requisición, la orden de compra y la CLC.'),
  n([
    'En **Requisiciones → Trámites de servicios** presiona **Nuevo trámite**, elige la secretaría y escribe el concepto general.',
    'Presiona **Subir facturas (XML)**. Todas deben ser del **mismo proveedor**, de ingreso, a nombre del Ayuntamiento y vigentes en el SAT.',
    'A cada factura asígnale **Partida**, **Proyecto**, **Fuente** y **Concepto**, y presiona **Guardar clasificación**. Puedes **Subir PDF** de cada una.',
    'Presiona **Enviar a validar**.',
    'Recursos Materiales revisa y presiona **Validar** (o **No validar** con observaciones). Al validar se generan la requisición, la orden y la CLC; ya se pueden imprimir.'
  ]),
  img('22-servicio-captura', 'Trámite de servicios en captura: facturas subidas y su clasificación.'),
  img('21-servicio', 'Trámite de servicios validado, con la requisición, la orden de compra y la CLC generadas.'),
  nota('El proveedor debe estar registrado y vigente en el padrón para que el trámite avance. Si no lo está, el sistema lo avisa en la parte superior.'),

  // ------------------------------------------------------------------ 6
  h1('6. Fondos revolventes'),
  p('El fondo revolvente repone los gastos menores de un área a favor de un trabajador. Cada factura puede ser de **hasta $2,000.00** y de cualquier proveedor.'),
  n([
    'En **Requisiciones → Fondos revolventes** presiona **Nuevo fondo revolvente**. Captura la secretaría, el concepto y los datos del **trabajador** (a su favor va la CLC).',
    'Sube las facturas (XML).',
    'En cada factura elige el **Tipo de gasto**: viáticos, alimentos y bebidas, papelería y consumibles, material de limpieza, mantenimiento y refacciones menores, transporte, casetas y estacionamiento, u otros gastos menores.',
    'Si es **Viáticos**, captura la **Comisión**: destino, fechas y motivo.',
    'Asigna **Partida**, **Proyecto** y **Fuente** a **cada artículo** (si una factura tiene artículos de dos partidas, en la CLC sale un renglón por partida). **Igual a todos** copia la del primer artículo.',
    'Presiona **Guardar clasificación** en cada factura y luego **Enviar a validar**.'
  ]),
  img('23-fondo', 'Fondo revolvente por validar: resumen por tipo de gasto y las facturas con la partida de cada artículo.'),
  p('Al validarse se generan la requisición y la CLC a favor del trabajador. En la requisición, cada renglón lleva el tipo de gasto y, en viáticos, la comisión.'),
  img('53-fondo-validado', 'Fondo revolvente validado, con viáticos y su comisión.'),

  // ------------------------------------------------------------------ 7
  h1('7. Arrendamientos y servicios por contrato'),
  p('Para pagos recurrentes (rentas, limpieza, vigilancia) se registra el contrato una vez y cada mes se carga el XML: el sistema genera la requisición, la orden de compra y la CLC de esa mensualidad.'),
  n([
    'En **Requisiciones → Arrendamientos y contratos** presiona **Nuevo contrato**: tipo (arrendamiento o servicio), número de contrato, secretaría, proveedor, concepto, vigencia, monto mensual y la línea de presupuesto (partida, proyecto, fuente).',
    'Presiona **Enviar a autorizar**. Recursos Materiales lo revisa y presiona **Autorizar contrato** (o **Rechazar**).',
    'Cada mes, en el detalle del contrato, elige la **Mensualidad**, selecciona la **Factura del mes (XML)** y presiona **Generar requisición, orden y CLC**.'
  ]),
  img('24-contratos', 'Lista de contratos.'),
  img('25-contrato', 'Detalle del contrato: vigencia, monto, saldo por ejercer y el estado de cada mensualidad.'),
  l([
    'La factura debe ser del proveedor del contrato, vigente en el SAT y no rebasar el **saldo por ejercer**. Se valida la suficiencia de la partida en ese momento.',
    'Los meses que pasaron sin facturar se marcan como **Vencida sin facturar**.',
    'Un contrato se puede **Terminar anticipadamente**; al facturar todas las mensualidades queda terminado solo.'
  ]),

  // ------------------------------------------------------------------ 8
  h1('8. Bitácora de combustible'),
  p('Cada mes el proveedor de combustible entrega sus facturas y un Excel con los tickets de todas las secretarías. El sistema reparte los tickets y las facturas en una **bitácora por secretaría**, que cada una completa y envía a validar.'),
  h2('8.1 Carga del proveedor (Control de combustible)'),
  n([
    'En **Vehículos → Cargas del proveedor de combustible** presiona **1. Subir facturas (XML)**. Se verifican en el SAT y quedan **por asignar** hasta que llegue el Excel que las incluya.',
    'Presiona **2. Cargar Excel**, selecciona el reporte mensual del proveedor, revisa la **vista previa** (tickets por secretaría, facturas, errores) y confirma con el periodo (por ejemplo, Septiembre 2026).',
    'El sistema crea una bitácora por secretaría y le pasa sus facturas por folio.'
  ]),
  img('26-combustible-cargas', 'Cargas del proveedor: facturas por asignar y la carga del mes con sus bitácoras.'),
  img('27-combustible-lote', 'Detalle de la carga: bitácoras por secretaría, facturas y su estado.'),
  nota('El Excel se lee por el nombre de las columnas (CLAVE, FECHA DEL TICKET, NO. TICKET, FOLIO VALE, NO. ECO, SECRETARÍA, DEPARTAMENTO, NOMBRE, GASOLINA/DIESEL, LITROS, MONTO, PRECIO POR LITRO, FECHA DE FACTURA, FOLIO, PROVEEDOR), no por su posición. Si una clave de secretaría no existe en el catálogo o una factura está repartida en dos secretarías, la carga se rechaza con el detalle.'),
  h2('8.2 Bitácora de cada secretaría'),
  n([
    'En **Vehículos → Bitácoras de combustible** abre la bitácora de tu secretaría.',
    'Revisa los tickets (**Ver tickets**) y que cada factura del Excel tenga su XML.',
    'En **Clasificar todas las facturas** elige partida (por ejemplo 2611 Combustibles), proyecto, fuente y concepto, y presiona **Aplicar a todas**.',
    'Presiona **Enviar a validar**. Recursos Materiales la valida: si hay suficiencia, se generan la requisición, la orden de compra y la CLC.'
  ]),
  img('28-combustible-bitacora', 'Bitácora de combustible de una secretaría, por validar.'),
  img('54-bitacora-validada', 'Bitácora validada, con su requisición, orden y CLC.'),
  p('La bitácora se descarga para auditoría con **Bitácora en Excel** o **Bitácora en PDF**. El **Reporte de consumo** muestra litros e importe por secretaría, vehículo, mes o producto en cualquier periodo.'),
  img('29-combustible-reporte', 'Reporte de consumo de combustible.'),

  // ------------------------------------------------------------------ 9
  h1('9. Mantenimiento vehicular'),
  p('El taller opera el mantenimiento con el mismo flujo de una requisición, a nombre de la secretaría de resguardo del vehículo.'),
  n([
    'En **Vehículos → Mantenimiento** presiona **Recibir vehículo**: elige el vehículo del padrón, la fecha, el kilometraje, el tipo (preventivo o correctivo), la falla o servicio y quién lo entrega. El vehículo queda **En taller**.',
    'Desde el renglón presiona **Hacer requisición**: se abre la captura con la secretaría de resguardo y solo las partidas de mantenimiento (2961, 2981, 3551, 3571, 2614). Captura conceptos y precios.',
    'La requisición sigue su trámite; el taller hace lo que en otras hace Recursos Materiales (cotizar, orden de compra y factura).',
    'Al terminar presiona **Entregar vehículo**: fecha, quién recibe y observaciones. El vehículo vuelve a **En servicio**.'
  ]),
  img('30-mantenimientos', 'Mantenimiento vehicular: vehículos en taller y entregados, con su requisición.'),
  p('**Vehículos → Costo de mantenimiento** muestra el costo por secretaría o por vehículo en un periodo: pagado, comprometido (con orden de compra) y estimado (solo requisición). Se exporta a Excel. Al hacer clic en un vehículo se abre su historial.'),
  img('31-mantenimiento-costo', 'Costo de mantenimiento vehicular por secretaría.'),
  img('46-vehiculos', 'Padrón vehicular (Catálogos): cada vehículo con su secretaría de resguardo y su área.'),

  // ------------------------------------------------------------------ 10
  h1('10. Padrón de proveedores (OIC)'),
  p('El Órgano Interno de Control integra el expediente de cada proveedor y emite su **cédula** (vigente un año). Recursos Materiales consulta el padrón; solo se generan órdenes de compra a proveedores con cédula vigente y que no estén suspendidos.'),
  img('32-padron', 'Padrón de proveedores con su estado: vigente, por vencer, vencido, en revisión, suspendido o compra única.'),
  n([
    'Presiona **Registrar proveedor** y captura RFC, razón social, giro, representante legal, régimen, domicilio, contacto y CLABE.',
    'En **Expediente** agrega cada documento (PDF o imagen) con su fecha: constancia de situación fiscal, opinión de cumplimiento del SAT (32-D, positiva), identificación, comprobante de domicilio, estado de cuenta y, en personas morales, acta constitutiva.',
    'Cuando **Requisitos para la cédula** está completo, presiona **Emitir cédula** (o **Renovar cédula (un año)**). Se imprime con **Imprimir cédula**.'
  ]),
  img('33-padron-ficha', 'Ficha del proveedor: datos, requisitos, cédulas y expediente.'),
  l([
    'La opinión de cumplimiento vale 30 días si no se indica su vigencia; el padrón avisa las cédulas y opiniones **por vencer**.',
    '**Suspender** bloquea al proveedor para nuevas órdenes; **Reactivar** lo libera.',
    'Los proveedores **de compra única** (eventuales) se dan de alta desde la orden de compra y no requieren cédula.'
  ]),

  // ------------------------------------------------------------------ 11
  h1('11. Tesorería'),
  h2('11.1 Mesa de control de pagos'),
  p('En **Tesorería → Mesa de control de pagos** Egresos ve las CLC recibidas, agrupadas por proveedor, con su fuente, importe, neto a pagar y estado en el SAT.'),
  n([
    'Marca las CLC que se van a pagar (puedes filtrar por fuente).',
    'Opcional: presiona el escudo para volver a **verificar las facturas en el SAT** antes de pagar.',
    'Presiona el botón **Pagar** (indica cuántas CLC y el total) y en la ventana elige la cuenta bancaria del Ayuntamiento, la forma (transferencia o cheque, con su número) y la fecha; confirma con **Pagar**.',
    'Se genera **un pago por proveedor** con un número de transferencia interno (TRF-AÑO-0001) para todas sus CLC. Las CLC y sus requisiciones quedan **Pagadas**.'
  ]),
  img('34-tesoreria-mesa', 'Mesa de control de pagos: CLC por pagar agrupadas por proveedor.'),
  nota('Si alguna factura de las CLC seleccionadas ya está cancelada en el SAT, el sistema no paga ninguna y lo indica.'),
  h2('11.2 Pagos'),
  p('En **Tesorería → Pagos** están todos los pagos con su estado (en tránsito, entregado, conciliado, cancelado). En el detalle se registra la **entrega del cheque**, se **concilia** con un movimiento del banco o se **cancela** (la CLC regresa a la mesa).'),
  img('35-tesoreria-pagos', 'Lista de pagos.'),
  img('36-tesoreria-pago', 'Detalle de un pago: sus CLC y los movimientos del banco que lo concilian.'),
  h2('11.3 Movimientos y conciliación'),
  n([
    'En **Tesorería → Movimientos y conciliación** captura los movimientos del estado de cuenta con **Capturar movimiento**, o descárgalos con **Descargar plantilla**, llénala y súbela con **Importar estado de cuenta**. Los ya importados no se duplican.',
    'Presiona **Conciliar automáticamente**: el sistema une cada pago con el cargo del banco por su número de transferencia o cheque y su importe.',
    'Si el monto no cuadra, no se concilia y se muestra la diferencia para revisarla.'
  ]),
  img('37-tesoreria-movimientos', 'Movimientos bancarios y conciliación.'),
  h2('11.4 Reportes y cuentas'),
  p('En **Tesorería → Reportes** están la posición bancaria (saldo por cuenta, banco y fuente) y el reporte de cheques, ambos en Excel. Las cuentas del Ayuntamiento se dan de alta en **Tesorería → Cuentas bancarias** (banco, número, CLABE, fuente y saldo inicial).'),
  img('38-tesoreria-reportes', 'Reportes de Tesorería: posición bancaria y cheques.'),
  img('45-cuentas', 'Catálogo de cuentas bancarias del Ayuntamiento.'),

  // ------------------------------------------------------------------ 12
  h1('12. Presupuesto y modificaciones'),
  h2('12.1 Presupuesto'),
  p('En **Presupuesto** se consulta cada línea (secretaría, fuente, proyecto, partida) con su aprobado, modificado, vigente y disponible. Control Presupuestal importa el presupuesto aprobado desde Excel y corre los saldos.'),
  img('17-presupuesto', 'Presupuesto de egresos por línea.'),
  h2('12.2 Modificaciones presupuestales'),
  p('Cuando una partida no alcanza, el área solicita una modificación en **Requisiciones → Modificaciones presupuestales → Nueva**:'),
  l([
    '**Traspaso**: de una línea emisora a una receptora de la misma fuente.',
    '**Reducción**: quita recurso de una línea.',
    '**Ampliación**: agrega recurso a una línea.'
  ]),
  p('Se capturan el periodo (mes), el importe y la justificación; se puede ligar a una requisición. Control Presupuestal la **autoriza** o la **rechaza**. Las áreas capturan dentro del periodo del **Calendario de modificaciones presupuestales**; fuera de él solo Control Presupuestal y Admin.'),
  img('18-modificaciones', 'Lista de modificaciones presupuestales.'),
  img('19-modificacion', 'Detalle de una modificación autorizada.'),

  // ------------------------------------------------------------------ 13
  h1('13. Recursos Materiales y almacén'),
  p('**Recursos Materiales → Listado** concentra las requisiciones que le toca recibir, cotizar o comprar. **Órdenes de compra** lista todas las órdenes con su factura, CLC y pago.'),
  img('12-recursos-materiales', 'Listado de Recursos Materiales.'),
  img('13-ordenes-compra', 'Órdenes de compra.'),
  p('Una orden se **asigna al almacén** desde su detalle. En **Almacén** se registran las **entradas** (lo recibido del proveedor) y las **salidas** (lo entregado al área), con sus formatos en PDF. **Inventario** muestra las existencias.'),
  img('41-almacen-orden', 'Entradas y salidas de almacén de una orden de compra.'),
  img('42-inventario', 'Inventario del almacén.'),
  h2('13.1 Facturas'),
  p('**Facturas** (menú Egresos) lista todos los CFDI cargados, con su estado en el SAT, y permite descargarlos.'),
  img('15-facturas', 'Facturas cargadas.'),

  // ------------------------------------------------------------------ 14
  h1('14. Contabilidad: ISR retenido'),
  p('En **ISR retenido (Contabilidad)** se consultan las facturas con ISR retenido de las CLC pagadas en el mes (las retenciones se enteran en el mes en que se pagan), por proveedor. Se exporta a Excel.'),
  img('39-isr', 'Reporte mensual de ISR retenido.'),

  // ------------------------------------------------------------------ 15
  h1('15. Catálogos'),
  p('**Catálogos generales** reúne los catálogos del sistema: secretarías, departamentos, proveedores, vehículos, bancos, cuentas bancarias, fuentes de financiamiento, ejercicios fiscales, calendarios de captura y otros. Cada catálogo permite buscar, dar de alta, editar y desactivar, según los permisos.'),
  img('43-catalogos', 'Catálogos generales.'),
  img('44-calendario', 'Calendario de captura de requisiciones: inicio y fin del periodo de cada mes.'),
  nota('En **Ejercicios fiscales** se definen los días de captura por omisión y se **cierra** un ejercicio: un ejercicio cerrado ya no admite requisiciones nuevas.'),

  // ------------------------------------------------------------------ 16
  h1('16. Administración'),
  h2('16.1 Usuarios, roles y permisos'),
  l([
    '**Usuarios**: alta, edición, rol, secretaría a la que pertenece (lo que ve de su área) y activación.',
    '**Roles**: conjuntos de permisos (ver el Anexo A). Un usuario puede tener varios.',
    '**Permisos**: la lista completa de permisos del sistema.'
  ]),
  img('47-usuarios', 'Usuarios del sistema.'),
  img('48-roles', 'Roles y su número de permisos.'),
  h2('16.2 Tickets de soporte'),
  p('Cualquier usuario puede levantar un **ticket** (título, descripción, prioridad y, opcionalmente, una captura). El equipo de sistemas lo atiende, comenta y cambia su estado; quien lo levantó recibe la notificación en la campana.'),
  img('49-tickets', 'Tickets de soporte.'),
  img('50-ticket', 'Detalle de un ticket con sus comentarios.'),
  h2('16.3 Bitácora de auditoría'),
  p('Registra quién creó, cambió o borró cada registro y cuándo, con los valores anteriores y nuevos. Se filtra por usuario, módulo y fecha.'),
  img('51-auditoria', 'Bitácora de auditoría.'),

  // ------------------------------------------------------------------ 17
  h1('17. Preguntas frecuentes'),
  tabla(['Situación', 'Qué hacer'], [
    ['No veo una opción del menú.', 'Tu rol no tiene ese permiso. Pídelo a la administración del sistema.'],
    ['No puedo enviar mi requisición a revisión.', 'Es extemporánea: la Subdirección Administrativa debe autorizarla. Revisa también que esté Pendiente o Rechazada.'],
    ['La suficiencia dice que no alcanza.', 'Solicita una modificación presupuestal (traspaso o ampliación) y vuelve a validar la suficiencia cuando se autorice.'],
    ['No aparece el proveedor al generar la orden.', 'No tiene cédula vigente o está suspendido. Revisa su ficha en el padrón; si es eventual, dalo de alta como compra única.'],
    ['La factura se rechaza por el uso de CFDI o el régimen.', 'El proveedor debe refacturar a nombre del Ayuntamiento con régimen 603 y uso G03 (u otro aceptado).'],
    ['La factura dice que ya se cargó.', 'Ese UUID (o la misma serie y folio del emisor) ya está en otra orden, trámite o bitácora. Búscala en Facturas.'],
    ['El Excel de combustible no carga.', 'Revisa los errores de la vista previa: claves de secretaría que no existen, facturas repartidas en dos secretarías o columnas sin el nombre esperado.'],
    ['Pagué una CLC por error.', 'Desde el detalle del pago, quita la conciliación (si la tiene) y cancela el pago: la CLC vuelve a la mesa de control.']
  ], [3600, 5760]),

  // ------------------------------------------------------------------ A
  h1('Anexo A. Roles del sistema'),
  p('Roles creados para los módulos de combustible, Tesorería, contratos, padrón y Contabilidad. Los roles **Super Admin** y **Admin** tienen todos los permisos; los demás roles del Ayuntamiento (por ejemplo Control Presupuestal o Sindicatura) se configuran en **Administración → Roles**.'),
  tabla(['Rol', 'Permisos principales'], [
    ['Área solicitante', 'Trámites de servicios, fondos revolventes, bitácoras de combustible y contratos: consultar y capturar.'],
    ['Recursos Materiales', 'Validar trámites de servicios y bitácoras de combustible, autorizar contratos, consultar el padrón.'],
    ['Control de combustible', 'Subir facturas y el Excel del proveedor; llenar bitácoras.'],
    ['Órgano Interno de Control', 'Consultar y gestionar el padrón de proveedores (expediente, cédulas, suspensión).'],
    ['Tesorería', 'Mesa de control, pagos, conciliación y catálogo de cuentas bancarias.'],
    ['Contabilidad', 'Reporte mensual de ISR retenido.']
  ], [3000, 6360]),
  h1('Anexo B. Estados de la CLC'),
  tabla(['Estado', 'Dónde está'], [
    ['Generada', 'Área solicitante'], ['Enviada a / En Recursos Materiales', 'Recursos Materiales'], ['Devuelta a Recursos Materiales', 'Recursos Materiales (con observaciones)'],
    ['Enviada a / En revisión (Control Presupuestal)', 'Control Presupuestal'], ['Enviada a / En Sindicatura', 'Sindicatura (firma)'],
    ['Enviada a / En Control Presupuestal (firmada)', 'Control Presupuestal'], ['Enviada a / En Egresos', 'Egresos (mesa de control)'],
    ['Pagada', 'Egresos (con su pago TRF)'], ['Cancelada', 'Con su motivo; la orden y la requisición se cancelan después']
  ], [4680, 4680])
];

guardar(documento('Manual de usuario', contenido), process.argv[2] ?? require('path').join(__dirname, '..', '..', 'SIEG - Manual de usuario.docx'));
