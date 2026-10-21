<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Sesión de usuario.
 *
 *   GET  sesion.php                                         Usuario actual y token CSRF (usuario nulo sin sesión).
 *   POST sesion.php {accion: 'ingresar', email, clave}      Inicia sesión.
 *   POST sesion.php {accion: 'salir'}                       Cierra la sesión.
 *   POST sesion.php {accion: 'cambiar_clave', actual, nueva} Cambia la contraseña propia.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$config = require __DIR__ . '/bootstrap.php';
api_headers('GET, POST, OPTIONS');
$pdo = api_db($config);

/** Respuesta con el usuario de la sesión y su token CSRF. */
function session_payload(array $user): array
{
    return [
        'ok' => true,
        'usuario' => [
            'id' => $user['id'],
            'nombre' => $user['nombre'],
            'email' => $user['email'],
            'rol' => $user['rol'],
            'debe_cambiar' => $user['debe_cambiar'],
            'permisos' => USER_ROLES[$user['rol']],
        ],
        'csrf' => session_csrf(),
    ];
}

$method = api_method();

if ($method === 'GET') {
    // Consultar el estado de la sesión no es un error: sin sesión responde usuario nulo.
    $user = session_user($pdo);
    api_respond(200, $user === null ? ['ok' => true, 'usuario' => null] : session_payload($user));
}

if ($method !== 'POST') {
    api_error(405, 'Método no permitido');
}

$body = api_body();
$action = (string) ($body['accion'] ?? '');

if ($action === 'ingresar') {
    if (user_ip_throttled($pdo)) {
        api_error(429, 'Demasiados ingresos fallidos desde esta conexión. Probá de nuevo en 15 minutos.');
    }
    $email = user_normalize_email($body['email'] ?? '');
    $password = is_string($body['clave'] ?? null) ? $body['clave'] : '';
    if ($email === '' || $password === '' || mb_strlen($password) > 128) {
        api_error(400, 'Ingresá el correo y la contraseña');
    }

    $result = user_authenticate($pdo, $email, $password);
    if (!$result['ok']) {
        audit_event($pdo, 'Ingreso rechazado', [
            'categoria' => 'acceso',
            'nivel' => 'error',
            'recurso' => 'sesion',
            'clave' => mb_substr($email, 0, 80),
            'detalle' => !empty($result['bloqueado']) ? 'cuenta bloqueada temporalmente' : 'credenciales incorrectas',
            'estado' => 401,
        ]);
        api_error(401, $result['error']);
    }

    session_login((int) $result['usuario']['id']);
    $user = session_user($pdo);
    audit_event($pdo, 'Ingreso al sistema', ['categoria' => 'acceso', 'recurso' => 'sesion', 'clave' => $user['nombre']]);
    api_respond(200, session_payload($user));
}

if ($action === 'salir') {
    $user = session_user($pdo);
    if ($user !== null) {
        if (!session_csrf_valid()) {
            api_error(403, 'El token de seguridad de la sesión no es válido. Recargá la página.');
        }
        audit_event($pdo, 'Salida del sistema', ['categoria' => 'acceso', 'recurso' => 'sesion', 'clave' => $user['nombre']]);
    }
    session_end();
    api_respond(200, ['ok' => true]);
}

if ($action === 'cambiar_clave') {
    $user = session_user($pdo);
    if ($user === null) {
        api_error(401, 'No hay una sesión iniciada', ['sesion' => false]);
    }
    if (!session_csrf_valid()) {
        api_error(403, 'El token de seguridad de la sesión no es válido. Recargá la página.');
    }
    $current = is_string($body['actual'] ?? null) ? $body['actual'] : '';
    $new = is_string($body['nueva'] ?? null) ? $body['nueva'] : '';

    $st = $pdo->prepare('SELECT clave_hash FROM usuarios WHERE id = ?');
    $st->execute([$user['id']]);
    if (!password_verify($current, (string) $st->fetchColumn())) {
        api_error(422, 'La contraseña actual no es correcta');
    }
    $problem = user_password_problem($new, $user['email']);
    if ($problem !== null) {
        api_error(422, $problem);
    }
    if ($new === $current) {
        api_error(422, 'La contraseña nueva tiene que ser distinta de la actual');
    }

    $pdo->prepare('UPDATE usuarios SET clave_hash = ?, debe_cambiar = 0, clave_cambiada = NOW() WHERE id = ?')
        ->execute([user_hash($new), $user['id']]);
    session_login($user['id']);                    // nueva sesión con identificador y token nuevos
    audit_event($pdo, 'Contraseña cambiada', ['categoria' => 'acceso', 'recurso' => 'sesion', 'clave' => $user['nombre']]);
    api_respond(200, session_payload(session_user($pdo)));
}

api_error(400, 'Acción desconocida');
