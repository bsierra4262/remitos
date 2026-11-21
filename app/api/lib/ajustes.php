<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Ajustes compartidos de la organización (emisor y remitos), guardados en la base.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/** Valores por defecto de cada sección. */
const SETTINGS_DEFAULTS = [
    'emisor' => [
        'ambiente' => 'homologacion', 'cuit' => '', 'ptoVenta' => '', 'emision' => false,
        // Factura de Crédito Electrónica MiPyME (Ley 27.440): condición MiPyME del emisor,
        // CBU donde cobra y opción de transferencia (SCA: Sistema de Circulación Abierta;
        // ADC: Agente de Depósito Colectivo).
        'mipyme' => false, 'cbu' => '', 'transferencia' => 'SCA',
    ],
    'remito' => ['ptoVenta' => '00001', 'copias' => 2],
];

/**
 * Todos los ajustes, completados con los valores por defecto.
 *
 * @return array<string, array<string, mixed>>
 */
function settings_all(PDO $pdo): array
{
    $out = SETTINGS_DEFAULTS;
    foreach ($pdo->query('SELECT clave, valor FROM ajustes')->fetchAll() as $row) {
        if (isset($out[$row['clave']])) {
            $out[$row['clave']] = array_merge($out[$row['clave']], json_decode((string) $row['valor'], true) ?: []);
        }
    }

    return $out;
}

/** Una sección de los ajustes. */
function settings_get(PDO $pdo, string $section): array
{
    return settings_all($pdo)[$section] ?? [];
}
