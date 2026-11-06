<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Autenticación por clave (cabecera X-API-Key).
 *
 * Hay dos clases de credencial:
 *  - Clave maestra: definida en config.php (preferentemente como SHA-256).
 *    Puede todo, incluida la administración de claves, talonarios y
 *    credenciales de ARCA.
 *  - Claves de servicio: se crean desde el panel con permisos acotados
 *    (leer, crear, borrar). De cada una se guarda solo su SHA-256: si se
 *    pierde, se revoca y se emite otra.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/** Permisos que puede tener una clave de servicio. */
const AUTH_PERMISSIONS = ['leer', 'crear', 'borrar'];

/** Intentos rechazados por IP tolerados en la ventana antes de bloquear. */
const AUTH_MAX_FAILURES = 20;

/** Ventana del bloqueo por intentos rechazados, en minutos. */
const AUTH_WINDOW_MINUTES = 10;

/** Genera una clave de servicio nueva: «ith_» seguido de 32 caracteres hexadecimales. */
function auth_new_key(): string
{
    return 'ith_' . bin2hex(random_bytes(16));
}

/**
 * Valida la clave recibida contra el permiso pedido.
 *
 * @param string $permission leer | crear | borrar | admin
 * @return array{ok: bool, code?: int, error?: string, maestra?: bool, id?: int, nombre?: string, permisos?: string[]}
 */
function auth_check(PDO $pdo, array $config, string $permission): array
{
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!is_string($key) || $key === '') {
        return ['ok' => false, 'code' => 401, 'error' => 'Falta la clave de acceso (cabecera X-API-Key)'];
    }

    if (crypto_matches_master($config, $key)) {
        return [
            'ok' => true,
            'maestra' => true,
            'nombre' => 'Clave maestra',
            'permisos' => [...AUTH_PERMISSIONS, 'admin'],
        ];
    }

    if ($permission === 'admin') {
        return ['ok' => false, 'code' => 403, 'error' => 'Esta operación requiere la clave maestra'];
    }

    $st = $pdo->prepare('SELECT id, nombre, permisos, activa FROM api_keys WHERE hash = ? LIMIT 1');
    $st->execute([hash('sha256', $key)]);
    $row = $st->fetch();

    if (!$row) {
        return ['ok' => false, 'code' => 401, 'error' => 'Clave de acceso inválida'];
    }
    if (!(int) $row['activa']) {
        return ['ok' => false, 'code' => 403, 'error' => 'La clave «' . $row['nombre'] . '» está desactivada'];
    }

    $permissions = auth_parse_permissions((string) $row['permisos']);
    if (!in_array($permission, $permissions, true)) {
        return [
            'ok' => false,
            'code' => 403,
            'error' => 'La clave «' . $row['nombre'] . '» no tiene permiso para ' . $permission,
        ];
    }

    $pdo->prepare('UPDATE api_keys SET usos = usos + 1, ultimo_uso = NOW() WHERE id = ?')->execute([$row['id']]);

    return [
        'ok' => true,
        'maestra' => false,
        'id' => (int) $row['id'],
        'nombre' => (string) $row['nombre'],
        'permisos' => $permissions,
    ];
}

/**
 * Autentica la petición o la corta con el error correspondiente.
 *
 * Acepta dos credenciales:
 *  - Cabecera X-API-Key (integraciones y panel): clave maestra o de servicio.
 *  - Sesión de usuario (la aplicación): permisos según el rol y token CSRF en
 *    toda escritura.
 *
 * Los rechazos de claves se auditan y se cuentan en la tabla de intentos: si una
 * misma IP acumula demasiados en poco tiempo, se bloquea temporalmente.
 *
 * @return array{ok: bool, maestra: bool, nombre: string, permisos: string[], id?: int, usuario?: array}
 */
function auth_require(PDO $pdo, array $config, string $permission, string $resource): array
{
    if ((string) ($_SERVER['HTTP_X_API_KEY'] ?? '') === '') {
        return auth_require_session($pdo, $permission, $resource);
    }
    if (auth_is_throttled($pdo)) {
        api_error(429, 'Demasiados intentos rechazados. Volvé a intentar en unos minutos.');
    }

    $auth = auth_check($pdo, $config, $permission);
    if (!$auth['ok']) {
        if ($auth['code'] === 401) {
            attempts_record($pdo, 'clave_api');
        }
        audit_event($pdo, 'Acceso rechazado (' . $permission . ')', [
            'categoria' => 'acceso',
            'nivel' => 'error',
            'detalle' => $auth['error'],
            'recurso' => $resource,
            'estado' => $auth['code'],
        ]);
        api_error($auth['code'], $auth['error']);
    }
    audit_actor(['id' => null, 'nombre' => $auth['nombre']]);

    return $auth;
}

/**
 * Autentica con la sesión de usuario.
 *
 * @return array{ok: bool, maestra: bool, nombre: string, permisos: string[], id: int, usuario: array}
 */
function auth_require_session(PDO $pdo, string $permission, string $resource): array
{
    $user = session_user($pdo);
    if ($user === null) {
        api_error(401, 'La sesión no está iniciada o venció. Ingresá de nuevo.', ['sesion' => false]);
    }
    if (!in_array(api_method(), ['GET', 'HEAD'], true)) {
        if (!session_csrf_valid()) {
            api_error(403, 'El token de seguridad de la sesión no es válido. Recargá la página.', ['csrf' => false]);
        }
        api_require_same_origin();
    }
    audit_actor(['id' => $user['id'], 'nombre' => $user['nombre']]);
    $permissions = USER_ROLES[$user['rol']];
    if (!in_array($permission, $permissions, true)) {
        audit_event($pdo, 'Acceso rechazado (' . $permission . ')', [
            'categoria' => 'acceso',
            'nivel' => 'error',
            'clave' => $user['nombre'],
            'detalle' => 'El rol «' . $user['rol'] . '» no tiene permiso',
            'recurso' => $resource,
            'estado' => 403,
        ]);
        api_error(403, 'Tu usuario no tiene permiso para esta operación');
    }

    return [
        'ok' => true,
        'maestra' => false,
        'id' => $user['id'],
        'nombre' => $user['nombre'],
        'permisos' => $permissions,
        'usuario' => $user,
    ];
}

/** ¿La IP actual superó el máximo de claves rechazadas en la ventana? */
function auth_is_throttled(PDO $pdo): bool
{
    return api_ip() !== '' && attempts_count($pdo, 'clave_api', null, true, AUTH_WINDOW_MINUTES) >= AUTH_MAX_FAILURES;
}

/**
 * Convierte la lista de permisos guardada («leer,crear») en un arreglo.
 *
 * @return string[]
 */
function auth_parse_permissions(string $permissions): array
{
    return array_values(array_filter(array_map('trim', explode(',', $permissions))));
}
