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

/**
 * Condición frente al IVA del receptor: tabla oficial de ARCA (FEParamGetCondicionIvaReceptor,
 * RG 5616/2024), con el código que se informa en WSFEv1, la leyenda que se imprime (RG 1415,
 * Anexo V, apartado II, para el remito) y las clases de comprobante que admite: A para
 * responsables inscriptos y monotributistas, B para el resto. Misma tabla en
 * app/js/calculos.js (CONDICIONES_IVA).
 */
const IVA_CONDICIONES = [
    'Responsable Inscripto' => ['codigo' => 1, 'leyenda' => 'IVA RESPONSABLE INSCRIPTO', 'clase' => 'A'],
    'Monotributo' => ['codigo' => 6, 'leyenda' => 'RESPONSABLE MONOTRIBUTO', 'clase' => 'A'],
    'Monotributista Social' => ['codigo' => 13, 'leyenda' => 'MONOTRIBUTISTA SOCIAL', 'clase' => 'A'],
    'Monotributo Trabajador Independiente Promovido' => ['codigo' => 16, 'leyenda' => 'MONOTRIBUTO TRABAJADOR INDEPENDIENTE PROMOVIDO', 'clase' => 'A'],
    'Exento' => ['codigo' => 4, 'leyenda' => 'IVA SUJETO EXENTO', 'clase' => 'B'],
    'Consumidor Final' => ['codigo' => 5, 'leyenda' => 'A CONSUMIDOR FINAL', 'clase' => 'B'],
    'Sujeto no categorizado' => ['codigo' => 7, 'leyenda' => 'SUJETO NO CATEGORIZADO', 'clase' => 'B'],
    'Proveedor del Exterior' => ['codigo' => 8, 'leyenda' => 'PROVEEDOR DEL EXTERIOR', 'clase' => 'B'],
    'Cliente del Exterior' => ['codigo' => 9, 'leyenda' => 'CLIENTE DEL EXTERIOR', 'clase' => 'B'],
    'IVA Liberado - Ley 19.640' => ['codigo' => 10, 'leyenda' => 'IVA LIBERADO - LEY N° 19.640', 'clase' => 'B'],
    'IVA No Alcanzado' => ['codigo' => 15, 'leyenda' => 'IVA NO ALCANZADO', 'clase' => 'B'],
];

/** Condiciones de receptores sin CUIT argentino: no se les exige CUIT en el remito. */
const IVA_SIN_CUIT = ['Consumidor Final', 'Cliente del Exterior', 'Proveedor del Exterior'];

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
    if (!isset(IVA_CONDICIONES[$cond])) {
        $faltan[] = 'la condición del destinatario frente al IVA';
    } elseif (!in_array($cond, IVA_SIN_CUIT, true)) {
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
