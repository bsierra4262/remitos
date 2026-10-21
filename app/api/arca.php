<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Credenciales de ARCA (certificado X.509 y clave privada) por ambiente.
 *
 *   GET    arca.php                                       (leer)  Estado de las credenciales.
 *   POST   arca.php {accion: 'cargar', ambiente, cuit?, certificado, clave, frase?}
 *                                                         (admin) Valida y guarda cifrado.
 *   POST   arca.php {accion: 'probar', ambiente}          (crear) Verifica servidores y acceso.
 *   DELETE arca.php?ambiente=…                            (admin) Elimina las credenciales.
 *
 * Las respuestas nunca incluyen el certificado ni la clave: solo sus datos
 * públicos (titular, CUIT, vigencia y huella SHA-256). La carga exige la clave
 * maestra y una conexión HTTPS.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$config = require __DIR__ . '/bootstrap.php';
api_headers('GET, POST, DELETE, OPTIONS');
$pdo = api_db($config);

/** Ambiente pedido o error 400. */
function arca_environment_param(mixed $value): string
{
    if (!arca_is_environment($value)) {
        api_error(400, 'Ambiente inválido (homologacion o produccion)');
    }

    return $value;
}

$method = api_method();

if ($method === 'GET') {
    auth_require($pdo, $config, 'leer', 'arca');
    $status = [];
    foreach (ARCA_ENVIRONMENTS as $environment) {
        $status[$environment] = arca_credentials_status($pdo, $environment);
    }
    api_respond(200, ['ok' => true, 'ambientes' => $status]);
}

if ($method === 'POST') {
    $body = api_body();
    $action = (string) ($body['accion'] ?? '');

    if ($action === 'cargar') {
        api_require_https();
        $auth = auth_require($pdo, $config, 'admin', 'arca');
        $environment = arca_environment_param($body['ambiente'] ?? null);

        try {
            $data = arca_inspect(
                is_string($body['certificado'] ?? null) ? $body['certificado'] : '',
                is_string($body['clave'] ?? null) ? $body['clave'] : '',
                is_string($body['frase'] ?? null) ? $body['frase'] : ''
            );
        } catch (InvalidArgumentException $e) {
            audit_event($pdo, 'Certificado de ARCA rechazado (' . ARCA_ENVIRONMENT_NAMES[$environment] . ')', [
                'categoria' => 'arca', 'nivel' => 'error', 'clave' => $auth['nombre'],
                'detalle' => $e->getMessage(), 'estado' => 422,
            ]);
            api_error(422, $e->getMessage());
        }

        $expectedCuit = preg_replace('/\D/', '', (string) ($body['cuit'] ?? ''));
        if ($expectedCuit !== '' && $expectedCuit !== $data['cuit']) {
            api_error(422, 'El certificado pertenece al CUIT ' . $data['cuit'] . ', no al ' . $expectedCuit);
        }

        arca_save_credentials($pdo, $config, $environment, $data, $auth['nombre']);
        audit_event($pdo, 'Certificado de ARCA cargado (' . ARCA_ENVIRONMENT_NAMES[$environment] . ')', [
            'categoria' => 'arca',
            'clave' => $auth['nombre'],
            'detalle' => 'CUIT ' . $data['cuit'] . ' · vence ' . date('d/m/Y', (int) strtotime($data['valido_hasta']))
                . ' · huella ' . substr($data['huella'], 0, 16) . '…',
        ]);
        api_respond(201, ['ok' => true, 'ambiente' => $environment, 'credencial' => arca_credentials_status($pdo, $environment)]);
    }

    if ($action === 'probar') {
        $auth = auth_require($pdo, $config, 'crear', 'arca');
        $environment = arca_environment_param($body['ambiente'] ?? null);
        if (arca_credentials_status($pdo, $environment) === null) {
            api_error(409, 'No hay un certificado de ARCA cargado para ' . ARCA_ENVIRONMENT_NAMES[$environment], [
                'sin_credenciales' => true,
            ]);
        }

        try {
            $servers = arca_fe_dummy($environment);
            $ticket = arca_ticket($pdo, $config, $environment);
        } catch (ArcaException $e) {
            audit_event($pdo, 'Conexión con ARCA fallida (' . ARCA_ENVIRONMENT_NAMES[$environment] . ')', [
                'categoria' => 'arca', 'nivel' => 'error', 'clave' => $auth['nombre'],
                'detalle' => $e->getMessage(), 'estado' => 502,
            ]);
            api_error(502, $e->getMessage());
        }

        audit_event($pdo, 'Conexión con ARCA verificada (' . ARCA_ENVIRONMENT_NAMES[$environment] . ')', [
            'categoria' => 'arca', 'clave' => $auth['nombre'], 'detalle' => 'CUIT ' . $ticket['cuit'],
        ]);
        api_respond(200, [
            'ok' => true,
            'ambiente' => $environment,
            'servidores' => $servers,
            'cuit' => $ticket['cuit'],
            'ticket_vence' => $ticket['expira'],
        ]);
    }

    api_error(400, 'Acción desconocida');
}

if ($method === 'DELETE') {
    $auth = auth_require($pdo, $config, 'admin', 'arca');
    $environment = arca_environment_param($_GET['ambiente'] ?? null);
    if (!arca_delete_credentials($pdo, $environment)) {
        api_error(404, 'No hay credenciales cargadas para ' . ARCA_ENVIRONMENT_NAMES[$environment]);
    }
    audit_event($pdo, 'Certificado de ARCA eliminado (' . ARCA_ENVIRONMENT_NAMES[$environment] . ')', [
        'categoria' => 'arca', 'clave' => $auth['nombre'],
    ]);
    api_respond(200, ['ok' => true, 'ambiente' => $environment]);
}

api_error(405, 'Método no permitido');
