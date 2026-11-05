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
 *   POST   arca.php {accion: 'quitar', ambiente, confirmacion?}  (admin) Elimina las credenciales.
 *   DELETE arca.php?ambiente=…                            (clave maestra) Ídem, para integraciones.
 *
 * Con sesión de usuario, cargar o quitar el certificado exige reconfirmar la
 * contraseña en «confirmacion»; cinco intentos fallidos cierran la sesión. El
 * método DELETE es solo para la clave maestra: la aplicación usa la acción 'quitar'.
 *
 * Si no se indica `cuit`, el certificado debe corresponder al CUIT del emisor
 * configurado en los ajustes (cuando hay uno).
 *
 * Las respuestas nunca incluyen el certificado ni la clave: solo sus datos
 * públicos (titular, CUIT, vigencia y huella SHA-256). La carga exige rol de
 * administrador (o la clave maestra) y una conexión HTTPS.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$config = require __DIR__ . '/bootstrap.php';
api_headers('GET, POST, DELETE, OPTIONS');
$pdo = api_db($config);

/**
 * Con sesión de usuario, las operaciones sobre el certificado piden reconfirmar la
 * contraseña (la clave maestra ya es una credencial de alto privilegio).
 */
function arca_confirm_identity(PDO $pdo, array $auth, mixed $password): void
{
    if (isset($auth['usuario'])) {
        user_confirm_password($pdo, $auth['usuario'], $password, 'certificado de ARCA');
    }
}

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

$body = $method === 'POST' ? api_body() : [];

if ($method === 'POST' && ($body['accion'] ?? '') !== 'quitar') {
    $action = (string) ($body['accion'] ?? '');

    if ($action === 'cargar') {
        api_require_https();
        $auth = auth_require($pdo, $config, 'admin', 'arca');
        arca_confirm_identity($pdo, $auth, $body['confirmacion'] ?? null);
        $environment = arca_environment_param($body['ambiente'] ?? null);

        try {
            $data = arca_inspect(
                is_string($body['certificado'] ?? null) ? $body['certificado'] : '',
                is_string($body['clave'] ?? null) ? $body['clave'] : '',
                is_string($body['frase'] ?? null) ? $body['frase'] : ''
            );
        } catch (InvalidArgumentException $e) {
            audit_event($pdo, 'Certificado de ARCA rechazado (' . ARCA_ENVIRONMENT_NAMES[$environment] . ')', [
                'categoria' => 'arca', 'nivel' => 'error', 'detalle' => $e->getMessage(), 'estado' => 422,
            ]);
            api_error(422, $e->getMessage());
        }

        $expectedCuit = preg_replace('/\D/', '', (string) ($body['cuit'] ?? ''))
            ?: preg_replace('/\D/', '', (string) (settings_get($pdo, 'emisor')['cuit'] ?? ''));
        if ($expectedCuit !== '' && $expectedCuit !== $data['cuit']) {
            api_error(422, 'El certificado pertenece al CUIT ' . $data['cuit'] . ', no al ' . $expectedCuit);
        }

        arca_save_credentials($pdo, $config, $environment, $data, $auth['nombre']);
        audit_event($pdo, 'Certificado de ARCA cargado (' . ARCA_ENVIRONMENT_NAMES[$environment] . ')', [
            'categoria' => 'arca',
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
                'categoria' => 'arca', 'nivel' => 'error', 'detalle' => $e->getMessage(), 'estado' => 502,
            ]);
            api_error(502, $e->getMessage());
        }

        audit_event($pdo, 'Conexión con ARCA verificada (' . ARCA_ENVIRONMENT_NAMES[$environment] . ')', [
            'categoria' => 'arca', 'detalle' => 'CUIT ' . $ticket['cuit'],
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

if ($method === 'DELETE' || ($method === 'POST' && ($body['accion'] ?? '') === 'quitar')) {
    $auth = auth_require($pdo, $config, 'admin', 'arca');
    if ($method === 'DELETE' && isset($auth['usuario'])) {
        api_error(405, 'Con sesión de usuario, el certificado se quita con POST {accion: "quitar"} y la contraseña de confirmación');
    }
    arca_confirm_identity($pdo, $auth, $body['confirmacion'] ?? null);
    $environment = arca_environment_param($method === 'DELETE' ? ($_GET['ambiente'] ?? null) : ($body['ambiente'] ?? null));
    if (!arca_delete_credentials($pdo, $environment)) {
        api_error(404, 'No hay credenciales cargadas para ' . ARCA_ENVIRONMENT_NAMES[$environment]);
    }
    audit_event($pdo, 'Certificado de ARCA eliminado (' . ARCA_ENVIRONMENT_NAMES[$environment] . ')', [
        'categoria' => 'arca',
    ]);
    api_respond(200, ['ok' => true, 'ambiente' => $environment]);
}

api_error(405, 'Método no permitido');
