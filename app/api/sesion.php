<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Sesión de usuario.
 *
 *   GET  sesion.php                                          Usuario actual y token CSRF (usuario nulo sin sesión).
 *   POST sesion.php {accion: 'ingresar', email, clave}       Inicia sesión (o pide el código del segundo factor).
 *   POST sesion.php {accion: 'verificar_codigo', codigo}     Completa el ingreso con el código TOTP.
 *   POST sesion.php {accion: 'salir'}                        Cierra la sesión.
 *   POST sesion.php {accion: 'cambiar_clave', actual, nueva} Cambia la contraseña propia.
 *   POST sesion.php {accion: 'totp_iniciar', clave}          Genera un secreto TOTP (sin activarlo todavía).
 *   POST sesion.php {accion: 'totp_activar', codigo}         Activa el segundo factor con un código válido.
 *   POST sesion.php {accion: 'totp_desactivar', clave, codigo} Desactiva el segundo factor.
 *
 * Cambiar la contraseña o el segundo factor cierra todas las demás sesiones del usuario.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$config = require __DIR__ . '/bootstrap.php';
api_headers('GET, POST, OPTIONS');
$pdo = api_db($config);

const SESSION_CODE_MAX_ATTEMPTS = 5;

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
            'segundo_factor' => $user['segundo_factor'],
            'permisos' => USER_ROLES[$user['rol']],
        ],
        'csrf' => session_csrf(),
    ];
}

/** Usuario con sesión y token CSRF válido, o corta la petición. */
function session_require_user(PDO $pdo): array
{
    $user = session_user($pdo);
    if ($user === null) {
        api_error(401, 'No hay una sesión iniciada', ['sesion' => false]);
    }
    if (!session_csrf_valid()) {
        api_error(403, 'El token de seguridad de la sesión no es válido. Recargá la página.', ['csrf' => false]);
    }
    audit_actor(['id' => $user['id'], 'nombre' => $user['nombre']]);

    return $user;
}

/** Fila completa del usuario. */
function session_row(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
    $st->execute([$id]);

    return $st->fetch() ?: [];
}

/** Verifica un código TOTP del usuario, con límite de intentos. Devuelve el contador usado. */
function session_check_code(PDO $pdo, array $config, array $row, mixed $code, bool $pending): int
{
    $key = 'u' . $row['id'];
    if (attempts_count($pdo, 'totp', $key, false, USER_WINDOW_MINUTES) >= SESSION_CODE_MAX_ATTEMPTS) {
        session_end();
        api_error(429, 'Demasiados códigos incorrectos. Esperá unos minutos e ingresá de nuevo.', ['sesion' => false]);
    }
    $secret = totp_user_secret($config, $row);
    $counter = $secret === null ? null
        : totp_verify($secret, is_scalar($code) ? (string) $code : '', $row['totp_ultimo'] === null ? null : (int) $row['totp_ultimo']);
    if ($counter === null) {
        attempts_record($pdo, 'totp', $key);
        audit_event($pdo, 'Código de segundo factor rechazado', [
            'categoria' => 'acceso', 'nivel' => 'error', 'recurso' => 'sesion', 'estado' => 401,
            'usuario_id' => (int) $row['id'], 'clave' => $row['nombre'],
        ]);
        api_error(401, 'El código no es correcto o ya se usó', $pending ? ['segundo_factor' => true] : []);
    }
    attempts_clear($pdo, 'totp', $key);

    return $counter;
}

$method = api_method();

if ($method === 'GET') {
    // Consultar el estado de la sesión no es un error: sin sesión responde usuario nulo.
    $user = session_user($pdo);
    if ($user !== null) {
        api_respond(200, session_payload($user));
    }
    api_respond(200, ['ok' => true, 'usuario' => null, 'segundo_factor' => session_pending_user() !== null]);
}

if ($method !== 'POST') {
    api_error(405, 'Método no permitido');
}

api_require_same_origin();
$body = api_body();
$action = (string) ($body['accion'] ?? '');

if ($action === 'ingresar') {
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
            'detalle' => !empty($result['limitado']) ? 'demasiados intentos' : 'credenciales incorrectas',
            'estado' => !empty($result['limitado']) ? 429 : 401,
        ]);
        api_error(!empty($result['limitado']) ? 429 : 401, $result['error']);
    }

    $row = $result['usuario'];
    if ((int) $row['totp_activo']) {
        session_pending_totp((int) $row['id']);
        api_respond(200, ['ok' => true, 'usuario' => null, 'segundo_factor' => true]);
    }

    session_login($pdo, (int) $row['id']);
    $user = session_user($pdo);
    audit_event($pdo, 'Ingreso al sistema', ['categoria' => 'acceso', 'recurso' => 'sesion', 'usuario_id' => $user['id'], 'clave' => $user['nombre']]);
    api_respond(200, session_payload($user));
}

if ($action === 'verificar_codigo') {
    $id = session_pending_user();
    if ($id === null) {
        api_error(401, 'El ingreso venció. Ingresá el correo y la contraseña de nuevo.', ['sesion' => false]);
    }
    $row = session_row($pdo, $id);
    if (!$row || !(int) $row['activo'] || !(int) $row['totp_activo']) {
        session_end();
        api_error(401, 'El ingreso venció. Ingresá el correo y la contraseña de nuevo.', ['sesion' => false]);
    }
    $counter = session_check_code($pdo, $config, $row, $body['codigo'] ?? '', true);
    $pdo->prepare('UPDATE usuarios SET totp_ultimo = ? WHERE id = ?')->execute([$counter, $id]);

    session_login($pdo, $id);
    $user = session_user($pdo);
    audit_event($pdo, 'Ingreso al sistema', [
        'categoria' => 'acceso', 'recurso' => 'sesion', 'usuario_id' => $user['id'], 'clave' => $user['nombre'], 'detalle' => 'con segundo factor',
    ]);
    api_respond(200, session_payload($user));
}

if ($action === 'salir') {
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

if ($action === 'cambiar_clave') {
    $user = session_require_user($pdo);
    $current = is_string($body['actual'] ?? null) ? $body['actual'] : '';
    $new = is_string($body['nueva'] ?? null) ? $body['nueva'] : '';
    user_confirm_password($pdo, $user, $current, 'cambio de contraseña');

    $problem = user_password_problem($new, $user['email']);
    if ($problem !== null) {
        api_error(422, $problem);
    }
    if ($new === $current) {
        api_error(422, 'La contraseña nueva tiene que ser distinta de la actual');
    }

    $pdo->prepare(
        'UPDATE usuarios SET clave_hash = ?, debe_cambiar = 0, clave_cambiada = NOW(), sesion_version = sesion_version + 1 WHERE id = ?'
    )->execute([user_hash($new), $user['id']]);
    session_login($pdo, $user['id']);              // nueva sesión; las demás quedan invalidadas
    audit_event($pdo, 'Contraseña cambiada', ['categoria' => 'acceso', 'recurso' => 'sesion', 'detalle' => 'se cerraron las demás sesiones']);
    api_respond(200, session_payload(session_user($pdo)));
}

if ($action === 'totp_iniciar') {
    $user = session_require_user($pdo);
    if ($user['segundo_factor']) {
        api_error(409, 'El segundo factor ya está activo. Desactivalo antes de generar uno nuevo.');
    }
    user_confirm_password($pdo, $user, $body['clave'] ?? '', 'segundo factor');
    $secret = totp_new_secret();
    $pdo->prepare('UPDATE usuarios SET totp_secret = ?, totp_activo = 0, totp_ultimo = NULL WHERE id = ?')
        ->execute([crypto_encrypt($secret, $config, 'totp:' . $user['id']), $user['id']]);
    api_respond(200, ['ok' => true, 'secreto' => $secret, 'uri' => totp_uri($secret, $user['email'])]);
}

if ($action === 'totp_activar') {
    $user = session_require_user($pdo);
    $row = session_row($pdo, $user['id']);
    if ((int) $row['totp_activo'] || empty($row['totp_secret'])) {
        api_error(409, 'Primero generá el código QR del segundo factor');
    }
    $counter = session_check_code($pdo, $config, $row, $body['codigo'] ?? '', false);
    $pdo->prepare('UPDATE usuarios SET totp_activo = 1, totp_ultimo = ?, sesion_version = sesion_version + 1 WHERE id = ?')
        ->execute([$counter, $user['id']]);
    session_login($pdo, $user['id']);
    audit_event($pdo, 'Segundo factor activado', ['categoria' => 'acceso', 'recurso' => 'sesion', 'detalle' => 'se cerraron las demás sesiones']);
    api_respond(200, session_payload(session_user($pdo)));
}

if ($action === 'totp_desactivar') {
    $user = session_require_user($pdo);
    $row = session_row($pdo, $user['id']);
    if (!(int) $row['totp_activo']) {
        api_error(409, 'El segundo factor no está activo');
    }
    user_confirm_password($pdo, $user, $body['clave'] ?? '', 'segundo factor');
    session_check_code($pdo, $config, $row, $body['codigo'] ?? '', false);
    $pdo->prepare('UPDATE usuarios SET totp_activo = 0, totp_secret = NULL, totp_ultimo = NULL, sesion_version = sesion_version + 1 WHERE id = ?')
        ->execute([$user['id']]);
    session_login($pdo, $user['id']);
    audit_event($pdo, 'Segundo factor desactivado', ['categoria' => 'acceso', 'recurso' => 'sesion']);
    api_respond(200, session_payload(session_user($pdo)));
}

api_error(400, 'Acción desconocida');
