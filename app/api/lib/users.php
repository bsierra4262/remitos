<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Usuarios: política de contraseñas, verificación con límite de intentos,
 * reconfirmación de identidad y contraseñas temporales.
 *
 * Límites de intentos (tabla `intentos`, ventana de 15 minutos):
 *  - Por correo e IP: 5 intentos. Frena la fuerza bruta sin bloquear la cuenta
 *    para el resto de las conexiones (evita que un tercero la deje inutilizable).
 *  - Por IP: 30 intentos con cualquier correo.
 *  - Por correo, desde cualquier IP: 50 intentos (ataque distribuido).
 * Los límites se aplican igual a correos inexistentes y el mensaje es siempre el
 * mismo: la respuesta no revela qué cuentas existen.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

const USER_MAX_ATTEMPTS = 5;
const USER_IP_MAX_FAILURES = 30;
const USER_ACCOUNT_MAX_FAILURES = 50;
const USER_WINDOW_MINUTES = 15;
const USER_MIN_PASSWORD = 12;

/** Contraseñas y palabras demasiado comunes (se comparan sin mayúsculas, números ni símbolos). */
const USER_COMMON_PASSWORDS = [
    'password', 'contraseña', 'contrasena', 'clave', 'qwerty', 'qwertyuiop', 'asdfgh', 'asdfghjkl', 'zxcvbn',
    'admin', 'administrador', 'usuario', 'user', 'login', 'bienvenido', 'welcome', 'letmein', 'secret', 'secreto',
    'intellihelp', 'remitos', 'remito', 'factura', 'facturas', 'argentina', 'buenosaires', 'boca', 'river',
    'iloveyou', 'teamo', 'dragon', 'monkey', 'master', 'superman', 'batman', 'football', 'futbol', 'abc', 'abcdef',
    'abcdefgh', 'changeme', 'cambiar', 'prueba', 'test', 'demo', 'default', 'invierno', 'verano', 'primavera', 'otono',
];

/** Devuelve el motivo por el que la contraseña no cumple la política, o null si la cumple. */
function user_password_problem(string $password, string $email = ''): ?string
{
    if (mb_strlen($password) < USER_MIN_PASSWORD) {
        return 'La contraseña debe tener al menos ' . USER_MIN_PASSWORD . ' caracteres';
    }
    if (mb_strlen($password) > 128) {
        return 'La contraseña no puede superar los 128 caracteres';
    }
    if (!preg_match('/\p{L}/u', $password) || !preg_match('/\d/', $password)) {
        return 'La contraseña debe combinar letras y números';
    }
    if (count(array_unique(mb_str_split(mb_strtolower($password)))) < 6) {
        return 'La contraseña repite demasiados caracteres';
    }
    $letters = preg_replace('/[^\p{L}]/u', '', mb_strtolower($password));
    foreach (USER_COMMON_PASSWORDS as $word) {
        if ($letters === $word || (mb_strlen($word) >= 6 && str_contains($letters, $word))) {
            return 'La contraseña es demasiado común o fácil de adivinar';
        }
    }
    if (preg_match('/(0123|1234|2345|3456|4567|5678|6789|9876|8765|7654|6543|5432|4321)/', $password)
        && mb_strlen(preg_replace('/\d/', '', $password)) < 6) {
        return 'La contraseña es demasiado común o fácil de adivinar';
    }
    $local = strtolower(strtok($email, '@') ?: '');
    foreach (preg_split('/[._-]/', $local) as $part) {
        if (strlen($part) >= 4 && str_contains(strtolower($password), $part)) {
            return 'La contraseña no puede contener tu nombre de usuario';
        }
    }

    return null;
}

/** Hash de una contraseña (bcrypt o el algoritmo vigente de PHP). */
function user_hash(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

/** Contraseña temporal legible de 16 caracteres (sin caracteres ambiguos). */
function user_temporary_password(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    $digits = '23456789';
    $out = '';
    for ($i = 0; $i < 12; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    for ($i = 0; $i < 4; $i++) {
        $out .= $digits[random_int(0, strlen($digits) - 1)];
    }

    return str_shuffle($out);
}

/** Normaliza un correo (minúsculas, sin espacios). */
function user_normalize_email(mixed $email): string
{
    return strtolower(trim(is_string($email) ? $email : ''));
}

/** ¿Corresponde frenar el ingreso para este correo desde esta conexión? */
function user_login_throttled(PDO $pdo, string $email): bool
{
    return attempts_count($pdo, 'ingreso', $email, true, USER_WINDOW_MINUTES) >= USER_MAX_ATTEMPTS
        || attempts_count($pdo, 'ingreso', null, true, USER_WINDOW_MINUTES) >= USER_IP_MAX_FAILURES
        || attempts_count($pdo, 'ingreso', $email, false, USER_WINDOW_MINUTES) >= USER_ACCOUNT_MAX_FAILURES;
}

/**
 * Verifica correo y contraseña. Siempre ejecuta una verificación de hash para no
 * revelar qué correos existen por la diferencia de tiempo, y el mensaje de error es
 * el mismo en todos los casos.
 *
 * @return array{ok: bool, usuario?: array, error?: string, limitado?: bool}
 */
function user_authenticate(PDO $pdo, string $email, string $password): array
{
    // Hash de referencia (de un valor sin uso) para igualar el tiempo de respuesta.
    $dummy = '$2y$10$TWfHWKUKhNMG1iOwF8/wmeYZJOff8PH6eIN7TcCQ.Ybeqg0aX.v/u';
    $generic = 'Correo o contraseña incorrectos';

    if (user_login_throttled($pdo, $email)) {
        password_verify($password, $dummy);

        return ['ok' => false, 'limitado' => true, 'error' => 'Demasiados intentos fallidos. Esperá unos minutos y volvé a intentar.'];
    }

    $st = $pdo->prepare('SELECT * FROM usuarios WHERE email = ?');
    $st->execute([$email]);
    $user = $st->fetch() ?: null;

    $valid = password_verify($password, $user ? (string) $user['clave_hash'] : $dummy);
    if (!$user || !$valid || !(int) $user['activo']) {
        attempts_record($pdo, 'ingreso', $email);

        return ['ok' => false, 'error' => $generic];
    }

    attempts_clear($pdo, 'ingreso', $email);
    $rehash = password_needs_rehash((string) $user['clave_hash'], PASSWORD_DEFAULT);
    $pdo->prepare(
        'UPDATE usuarios SET intentos = 0, bloqueado_hasta = NULL, ultimo_ingreso = NOW()' .
        ($rehash ? ', clave_hash = ?' : '') . ' WHERE id = ?'
    )->execute($rehash ? [user_hash($password), $user['id']] : [$user['id']]);

    return ['ok' => true, 'usuario' => $user];
}

/**
 * Reconfirma la identidad del usuario con sesión (operaciones sensibles y cambio de
 * contraseña). Cinco fallos en 15 minutos cierran la sesión: con una sesión robada no
 * se puede probar contraseñas sin límite.
 *
 * Con una sesión abierta con Google no se pide contraseña: vale el ingreso con Google
 * de los últimos SESSION_CONFIRM_SECONDS y, pasado ese plazo, la respuesta pide volver
 * a confirmar con Google (`reconfirmar_google`). Quien tenga contraseña propia puede
 * escribirla igual.
 */
function user_confirm_password(PDO $pdo, array $user, mixed $password, string $motivo): void
{
    $key = 'u' . $user['id'];
    if (($user['ingreso'] ?? 'clave') === 'google' && (!is_string($password) || $password === '')) {
        if (!empty($user['confirmado'])) {
            return;
        }
        api_error(403, 'Por seguridad, confirmá tu identidad con Google y repetí la operación.', ['reconfirmar_google' => true]);
    }
    if (attempts_count($pdo, 'reconfirmar', $key, false, USER_WINDOW_MINUTES) >= USER_MAX_ATTEMPTS) {
        session_end();
        api_error(429, 'Demasiados intentos fallidos. La sesión se cerró: ingresá de nuevo.', ['sesion' => false]);
    }
    $st = $pdo->prepare('SELECT clave_hash FROM usuarios WHERE id = ?');
    $st->execute([$user['id']]);
    if (!is_string($password) || $password === '' || !password_verify($password, (string) $st->fetchColumn())) {
        attempts_record($pdo, 'reconfirmar', $key);
        audit_event($pdo, 'Confirmación de identidad rechazada (' . $motivo . ')', [
            'categoria' => 'acceso', 'nivel' => 'error', 'recurso' => 'sesion', 'estado' => 403,
        ]);
        api_error(403, 'La contraseña de confirmación no es correcta');
    }
    attempts_clear($pdo, 'reconfirmar', $key);
}

/** Invalida todas las sesiones abiertas de un usuario (cambio de contraseña, rol, baja). */
function user_revoke_sessions(PDO $pdo, int $userId): void
{
    $pdo->prepare('UPDATE usuarios SET sesion_version = sesion_version + 1 WHERE id = ?')->execute([$userId]);
}

/**
 * Datos públicos de un usuario (nunca el hash ni el secreto del segundo factor).
 *
 * @return array<string, mixed>
 */
function user_public(PDO $pdo, array $row): array
{
    return [
        'id' => (int) $row['id'],
        'nombre' => $row['nombre'],
        'email' => $row['email'],
        'rol' => $row['rol'],
        'activo' => (bool) (int) $row['activo'],
        'debe_cambiar' => (bool) (int) $row['debe_cambiar'],
        'segundo_factor' => (bool) (int) ($row['totp_activo'] ?? 0),
        'bloqueado' => attempts_count($pdo, 'ingreso', (string) $row['email'], false, USER_WINDOW_MINUTES) >= USER_MAX_ATTEMPTS,
        'ultimo_ingreso' => $row['ultimo_ingreso'],
        'creado' => $row['creado'],
    ];
}
