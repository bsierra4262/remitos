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
 * Los rechazos se auditan (son eventos de seguridad) y, si una misma IP acumula
 * demasiados en poco tiempo, se bloquea temporalmente.
 *
 * @return array{ok: bool, maestra: bool, nombre: string, permisos: string[], id?: int}
 */
function auth_require(PDO $pdo, array $config, string $permission, string $resource): array
{
    if (auth_is_throttled($pdo)) {
        api_error(429, 'Demasiados intentos rechazados. Volvé a intentar en unos minutos.');
    }

    $auth = auth_check($pdo, $config, $permission);
    if (!$auth['ok']) {
        audit_event($pdo, 'Acceso rechazado (' . $permission . ')', [
            'categoria' => 'acceso',
            'nivel' => 'error',
            'detalle' => $auth['error'],
            'recurso' => $resource,
            'estado' => $auth['code'],
        ]);
        api_error($auth['code'], $auth['error']);
    }

    return $auth;
}

/** ¿La IP actual superó el máximo de intentos rechazados en la ventana? */
function auth_is_throttled(PDO $pdo): bool
{
    $ip = api_ip();
    if ($ip === '') {
        return false;
    }
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM api_log
          WHERE ip = ? AND categoria = 'acceso' AND nivel = 'error'
            AND fecha > NOW() - INTERVAL " . AUTH_WINDOW_MINUTES . ' MINUTE'
    );
    $st->execute([$ip]);

    return (int) $st->fetchColumn() >= AUTH_MAX_FAILURES;
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
