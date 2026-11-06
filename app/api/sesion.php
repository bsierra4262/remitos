<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Sesión de usuario. Se ingresa solo con la cuenta de Google de Intellihelp
 * (google.php); este endpoint informa la sesión y la cierra.
 *
 *   GET  sesion.php                  Usuario actual y token CSRF (usuario nulo sin sesión;
 *                                    `google` indica si el ingreso con Google está configurado).
 *   POST sesion.php {accion: 'salir'} Cierra la sesión.
 *
 * `confirmado` indica si la identidad se confirmó con Google en los últimos 15 minutos:
 * las operaciones sensibles lo exigen (ver user_confirm_identity).
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
    // Consultar el estado de la sesión no es un error: sin sesión responde usuario nulo.
    $user = session_user($pdo);
    if ($user !== null) {
        api_respond(200, [
            'ok' => true,
            'usuario' => $user + ['permisos' => USER_ROLES[$user['rol']]],
            'csrf' => session_csrf(),
        ]);
    }
    api_respond(200, ['ok' => true, 'usuario' => null, 'google' => google_config($config) !== null]);
}

if ($method !== 'POST') {
    api_error(405, 'Método no permitido');
}

api_require_same_origin();
$body = api_body();

if (($body['accion'] ?? '') === 'salir') {
    $user = session_user($pdo);
    if ($user !== null) {
        if (!session_csrf_valid()) {
            api_error(403, 'El token de seguridad de la sesión no es válido. Recargá la página.');
        }
        audit_event($pdo, 'Salida del sistema', ['categoria' => 'acceso', 'recurso' => 'sesion', 'usuario_id' => $user['id'], 'clave' => $user['nombre']]);
    }
    session_end();
    api_respond(200, ['ok' => true]);
}

api_error(400, 'Acción desconocida. Se ingresa con la cuenta de Google de Intellihelp.');
