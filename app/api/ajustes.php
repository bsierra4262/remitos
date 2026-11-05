<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Ajustes compartidos por todos los usuarios.
 *
 *   GET  ajustes.php                                   (leer)  Todos los ajustes.
 *   POST ajustes.php {seccion: 'emisor', valores: {…}} (admin) Ambiente, CUIT, punto de venta y emisión electrónica.
 *   POST ajustes.php {seccion: 'remito', valores: {…}} (admin) Punto de venta y ejemplares del remito.
 *
 * La calibración de impresión no está acá: depende de cada impresora y se guarda en cada equipo.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$config = require __DIR__ . '/bootstrap.php';
api_headers('GET, POST, OPTIONS');
$pdo = api_db($config);

$method = api_method();

if ($method === 'GET') {
    auth_require($pdo, $config, 'leer', 'ajustes');
    api_respond(200, ['ok' => true, 'ajustes' => settings_all($pdo)]);
}

if ($method === 'POST') {
    $auth = auth_require($pdo, $config, 'admin', 'ajustes');
    $body = api_body();
    $section = (string) ($body['seccion'] ?? '');
    $values = is_array($body['valores'] ?? null) ? $body['valores'] : [];
    if (!isset(SETTINGS_DEFAULTS[$section])) {
        api_error(400, 'Sección de ajustes desconocida');
    }

    if ($section === 'emisor') {
        $environment = (string) ($values['ambiente'] ?? 'homologacion');
        if (!arca_is_environment($environment)) {
            api_error(400, 'Ambiente inválido (homologacion o produccion)');
        }
        $cuit = preg_replace('/\D/', '', (string) ($values['cuit'] ?? ''));
        $pointOfSale = preg_replace('/\D/', '', (string) ($values['ptoVenta'] ?? ''));
        $emission = api_bool($values['emision'] ?? false);
        if ($cuit !== '' && !api_cuit_valid($cuit)) {
            api_error(422, 'El CUIT del emisor no es válido (11 dígitos con dígito verificador correcto)');
        }
        if ($pointOfSale !== '' && ((int) $pointOfSale < 1 || (int) $pointOfSale > 99998)) {
            api_error(422, 'Punto de venta inválido (1 a 99998)');
        }
        if ($emission) {
            $missing = [];
            if ($cuit === '') {
                $missing[] = 'CUIT del emisor';
            }
            if ($pointOfSale === '') {
                $missing[] = 'punto de venta habilitado para web services';
            }
            $credential = arca_credentials_status($pdo, $environment);
            if ($credential === null) {
                $missing[] = 'certificado de ' . ARCA_ENVIRONMENT_NAMES[$environment];
            } elseif ($credential['vencido']) {
                $missing[] = 'certificado vigente (el cargado está vencido)';
            } elseif ($cuit !== '' && $credential['cuit'] !== $cuit) {
                $missing[] = 'certificado del CUIT ' . $cuit . ' (el cargado es del ' . $credential['cuit'] . ')';
            }
            if ($missing) {
                api_error(422, 'Para activar la emisión electrónica falta: ' . implode(', ', $missing), ['faltan' => $missing]);
            }
        }
        $stored = ['ambiente' => $environment, 'cuit' => $cuit, 'ptoVenta' => $pointOfSale, 'emision' => $emission];
    } else {
        $pointOfSale = preg_replace('/\D/', '', (string) ($values['ptoVenta'] ?? '1')) ?: '1';
        if ((int) $pointOfSale < 1 || (int) $pointOfSale > 99998) {
            api_error(422, 'Punto de venta inválido (1 a 99998)');
        }
        $stored = [
            'ptoVenta' => str_pad($pointOfSale, 5, '0', STR_PAD_LEFT),
            'copias' => (int) ($values['copias'] ?? 2) === 1 ? 1 : 2,
        ];
    }

    $pdo->prepare(
        'INSERT INTO ajustes (clave, valor, actualizado, actualizado_por) VALUES (?, ?, NOW(), ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor), actualizado = VALUES(actualizado), actualizado_por = VALUES(actualizado_por)'
    )->execute([$section, json_encode($stored, JSON_UNESCAPED_UNICODE), $auth['nombre']]);
    audit_event($pdo, 'Ajustes actualizados: ' . $section, [
        'categoria' => 'sistema', 'detalle' => json_encode($stored, JSON_UNESCAPED_UNICODE),
    ]);
    api_respond(200, ['ok' => true, 'ajustes' => settings_all($pdo)]);
}

api_error(405, 'Método no permitido');
