<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Requisitos de contenido de los remitos (RG 1415/2003, Anexo V, con la RG 5678/2025).
 * Se exigen al autorizar: un remito sin estos datos no es un documento de traslado
 * válido. La interfaz aplica las mismas reglas (app/js/calculos.js · faltantesRemito)
 * para avisar antes de autorizar.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/** Condición del destinatario frente al IVA → leyenda que exige el Anexo V, apartado II. */
const REMITO_LEYENDAS_IVA = [
    'Responsable Inscripto' => 'IVA RESPONSABLE INSCRIPTO',
    'Exento' => 'IVA EXENTO',
    'No Responsable' => 'NO RESPONSABLE IVA',
    'Monotributo' => 'RESPONSABLE MONOTRIBUTO',
    'Consumidor Final' => 'A CONSUMIDOR FINAL',
    'Sujeto no categorizado' => 'SUJETO NO CATEGORIZADO',
];

/** Tipos de transporte: propio (no se informa transportista) o por terceros (Anexo V, apartado IV). */
const REMITO_TRANSPORTES = ['', 'propio', 'tercero'];

/**
 * Datos que le faltan a un remito para ser válido, en lenguaje de usuario. Vacío si
 * cumple.
 *
 * @return string[]
 */
function remito_faltantes(array $doc): array
{
    $faltan = [];
    $cliente = (array) ($doc['cliente'] ?? []);
    $transporte = (array) ($doc['transporte'] ?? []);
    $cond = (string) ($cliente['condIVA'] ?? '');

    if (api_date_dmy($doc['fecha'] ?? '') === '') {
        $faltan[] = 'la fecha de emisión';
    }
    if (trim((string) ($cliente['razonSocial'] ?? '')) === '' && trim((string) ($cliente['senores'] ?? '')) === '') {
        $faltan[] = 'el nombre o la razón social del destinatario';
    }
    if (!isset(REMITO_LEYENDAS_IVA[$cond])) {
        $faltan[] = 'la condición del destinatario frente al IVA';
    } elseif ($cond !== 'Consumidor Final') {
        if (!api_cuit_valid((string) ($cliente['cuit'] ?? ''))) {
            $faltan[] = 'el CUIT del destinatario';
        }
        if (trim((string) ($cliente['domicilio'] ?? '')) === '') {
            $faltan[] = 'el domicilio comercial del destinatario';
        }
    }

    $items = array_filter((array) ($doc['items'] ?? []), static fn ($i): bool => is_array($i) && trim((string) ($i['descripcion'] ?? '')) !== '');
    if ($items === []) {
        $faltan[] = 'la descripción de los bienes que se trasladan';
    }
    foreach ($items as $n => $item) {
        if (trim((string) ($item['cantidad'] ?? '')) === '') {
            $faltan[] = 'la cantidad del ítem ' . ($n + 1);
        }
    }

    if (($transporte['tipo'] ?? '') === 'tercero') {
        if (trim((string) ($transporte['nombre'] ?? '')) === '') {
            $faltan[] = 'el nombre o la razón social del transportista';
        }
        if (!api_cuit_valid((string) ($transporte['cuit'] ?? ''))) {
            $faltan[] = 'el CUIT del transportista';
        }
        if (trim((string) ($transporte['domicilio'] ?? '')) === '') {
            $faltan[] = 'el domicilio comercial del transportista';
        }
    } elseif (!in_array($transporte['tipo'] ?? '', ['propio', 'tercero'], true)) {
        $faltan[] = 'si el traslado es con transporte propio o de un tercero';
    }

    return $faltan;
}
