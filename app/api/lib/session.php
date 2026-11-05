<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Sesión de usuario: ingreso con correo y contraseña, cookie segura y token CSRF.
 *
 * - Cookie HttpOnly, Secure (bajo HTTPS) y SameSite=Strict, limitada a la ruta de la aplicación.
 * - El identificador de sesión se regenera al ingresar.
 * - La sesión vence tras 8 horas sin actividad o 12 horas desde el ingreso.
 * - En cada petición se relee el usuario: desactivarlo o cambiarle el rol rige en el acto.
 * - Cada usuario tiene una versión de sesión: cambiar la contraseña, el rol, el
 *   segundo factor o desactivarlo la incrementa y cierra todas sus otras sesiones.
 * - Con segundo factor activo, la contraseña deja la sesión «pendiente» hasta
 *   validar el código TOTP.
 * - Toda escritura con sesión exige el token CSRF en la cabecera X-CSRF-Token.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

const SESSION_NAME = 'remitos_sid';
const SESSION_IDLE_SECONDS = 8 * 3600;
const SESSION_MAX_SECONDS = 12 * 3600;

/** Roles de usuario y sus permisos. */
const USER_ROLES = [
    'admin' => ['leer', 'crear', 'borrar', 'admin'],
    'operador' => ['leer', 'crear', 'borrar'],
];

/** Ruta pública de la aplicación (por ejemplo «/remitos» o «/»), para limitar la cookie. */
function session_cookie_path(): string
{
    $path = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api/x.php'), 2)), '/');

    return $path === '' ? '/' : $path . '/';
}

/** Inicia la sesión de PHP con parámetros seguros (una vez por petición). */
function session_begin(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = api_is_https();
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) SESSION_MAX_SECONDS);
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => session_cookie_path(),
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

/**
 * Usuario de la sesión actual, releído de la base. Null si no hay sesión válida.
 *
 * @return array{id: int, nombre: string, email: string, rol: string, debe_cambiar: bool}|null
 */
function session_user(PDO $pdo): ?array
{
    // Sin cookie ni sesión abierta en esta misma petición, no hay nada que leer.
    if (!isset($_COOKIE[SESSION_NAME]) && session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    session_begin();
    $id = (int) ($_SESSION['uid'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $now = time();
    if ($now - (int) ($_SESSION['visto'] ?? 0) > SESSION_IDLE_SECONDS || $now - (int) ($_SESSION['inicio'] ?? 0) > SESSION_MAX_SECONDS) {
        session_end();

        return null;
    }

    $st = $pdo->prepare('SELECT id, nombre, email, rol, activo, debe_cambiar, sesion_version, totp_activo FROM usuarios WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row || !(int) $row['activo'] || !isset(USER_ROLES[$row['rol']])
        || (int) $row['sesion_version'] !== (int) ($_SESSION['ver'] ?? 0)) {
        session_end();

        return null;
    }
    $_SESSION['visto'] = $now;

    return [
        'id' => (int) $row['id'],
        'nombre' => (string) $row['nombre'],
        'email' => (string) $row['email'],
        'rol' => (string) $row['rol'],
        'debe_cambiar' => (bool) (int) $row['debe_cambiar'],
        'segundo_factor' => (bool) (int) $row['totp_activo'],
    ];
}

/** Abre la sesión de un usuario autenticado (con la versión de sesión vigente). */
function session_login(PDO $pdo, int $userId): void
{
    $st = $pdo->prepare('SELECT sesion_version FROM usuarios WHERE id = ?');
    $st->execute([$userId]);
    session_begin();
    session_regenerate_id(true);
    $_SESSION = [
        'uid' => $userId,
        'ver' => (int) $st->fetchColumn(),
        'inicio' => time(),
        'visto' => time(),
        'csrf' => bin2hex(random_bytes(32)),
    ];
}

/** Deja la sesión a la espera del segundo factor (sin acceso a datos). */
function session_pending_totp(int $userId): void
{
    session_begin();
    session_regenerate_id(true);
    $_SESSION = ['totp_uid' => $userId, 'totp_desde' => time()];
}

/** Usuario que validó la contraseña y debe ingresar el código TOTP (5 minutos de plazo). */
function session_pending_user(): ?int
{
    if (!isset($_COOKIE[SESSION_NAME])) {
        return null;
    }
    session_begin();
    $id = (int) ($_SESSION['totp_uid'] ?? 0);

    return $id > 0 && time() - (int) ($_SESSION['totp_desde'] ?? 0) <= 300 ? $id : null;
}

/** Cierra la sesión y borra la cookie. */
function session_end(): void
{
    session_begin();
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(SESSION_NAME, '', [
        'expires' => time() - 3600,
        'path' => $params['path'],
        'secure' => $params['secure'],
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_destroy();
}

/** Token CSRF de la sesión actual. */
function session_csrf(): string
{
    session_begin();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf'];
}

/** ¿La petición trae el token CSRF correcto? */
function session_csrf_valid(): bool
{
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    return $sent !== '' && hash_equals(session_csrf(), $sent);
}
