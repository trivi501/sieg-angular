<?php

/** Ids de los registros de demostración que se capturan (JSON para el script de capturas). */

require __DIR__.'/comun.php';

use App\Models\Contrato;
use App\Models\EgresoRequisicion;
use App\Models\OrdenCompra;
use App\Models\Proveedor;
use App\Models\TesoreriaPago;
use App\Models\TramiteServicio;

$req = fn (string $folio) => EgresoRequisicion::where('folio_completo', $folio)->value('id_tb_egreso_requisicion');
$tramite = fn (string $tipo, string $estado) => TramiteServicio::where('tipo', $tipo)->where('estado', $estado)->value('id');

echo json_encode([
    'req_pagada' => $req('REQ-0001'),
    'req_egresos' => $req('REQ-0003'),
    'req_clc' => $req('REQ-0006'),
    'req_orden' => $req('REQ-0007'),
    'req_comprometida' => $req('REQ-0008'),
    'req_cotizacion' => $req('REQ-0009'),
    'req_revision' => $req('REQ-0011'),
    'req_extemporanea' => EgresoRequisicion::where('extemporanea', true)->whereNull('extemporanea_autorizada_at')->value('id_tb_egreso_requisicion'),
    'oc_almacen' => OrdenCompra::where('folio_completo', 'OC-2026-0001')->value('id'),
    'servicio' => $tramite('servicio', 'validado'),
    'servicio_captura' => $tramite('servicio', 'captura'),
    'fondo' => $tramite('fondo', 'validado'),
    'fondo_por_validar' => $tramite('fondo', 'por_validar'),
    'bitacora' => $tramite('combustible', 'por_validar'),
    'bitacora_validada' => $tramite('combustible', 'validado'),
    'lote' => App\Models\CombustibleLote::value('id'),
    'contrato' => Contrato::where('estado', 'vigente')->value('id'),
    'pago' => TesoreriaPago::where('forma', 'transferencia')->orderBy('id')->value('id'),
    'proveedor' => Proveedor::where('rfc', 'PSC0503147K2')->value('id'),
    'proveedor_vencido' => Proveedor::where('rfc', 'FMV0607215H9')->value('id'),
    'modificacion' => App\Models\ModificacionPresupuestal::orderBy('id')->value('id'),
    'ticket' => App\Models\SupportTicket::orderBy('id')->value('id'),
    'presupuesto' => App\Models\PresupuestoEgreso::query()->value((new App\Models\PresupuestoEgreso)->getKeyName()),
], JSON_PRETTY_PRINT);
