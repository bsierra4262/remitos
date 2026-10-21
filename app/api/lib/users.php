<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Usuarios: política de contraseñas, verificación con bloqueo por intentos y
 * contraseñas temporales.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/** Intentos fallidos tolerados antes de bloquear la cuenta. */
const USER_MAX_ATTEMPTS = 5;

/** Minutos de bloqueo de una cuenta tras superar los intentos. */
const USER_LOCK_MINUTES = 15;

/** Ingresos fallidos tolerados por IP en la ventana de 15 minutos. */
const USER_IP_MAX_FAILURES = 30;

/** Devuelve el motivo por el que la contraseña no cumple la política, o null si la cumple. */
function user_password_problem(string $password, string $email = ''): ?string
{
    if (mb_strlen($password) < 10) {
        return 'La contraseña debe tener al menos 10 caracteres';
    }
    if (mb_strlen($password) > 128) {
        return 'La contraseña no puede superar los 128 caracteres';
    }
    if (!preg_match('/\p{L}/u', $password) || !preg_match('/\d/', $password)) {
        return 'La contraseña debe combinar letras y números';
    }
    $local = strtolower(strtok($email, '@') ?: '');
    if (strlen($local) >= 4 && str_contains(strtolower($password), $local)) {
        return 'La contraseña no puede contener el usuario del correo';
    }

    return null;
}

/** Hash de una contraseña (bcrypt o el algoritmo vigente de PHP). */
function user_hash(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

/** Contraseña temporal legible de 14 caracteres (sin caracteres ambiguos). */
function user_temporary_password(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    $digits = '23456789';
    $out = '';
    for ($i = 0; $i < 10; $i++) {
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

/** ¿Hay demasiados ingresos fallidos recientes desde la IP actual? */
function user_ip_throttled(PDO $pdo): bool
{
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM api_log
          WHERE ip = ? AND recurso = 'sesion' AND nivel = 'error' AND fecha > NOW() - INTERVAL 15 MINUTE"
    );
    $st->execute([api_ip()]);

    return (int) $st->fetchColumn() >= USER_IP_MAX_FAILURES;
}

/**
 * Verifica correo y contraseña. Devuelve el usuario o un error genérico.
 * Siempre ejecuta una verificación de hash para no revelar qué correos existen
 * por la diferencia de tiempo de respuesta.
 *
 * @return array{ok: bool, usuario?: array, error?: string, bloqueado?: bool}
 */
function user_authenticate(PDO $pdo, string $email, string $password): array
{
    // Hash de referencia (de un valor sin uso) para igualar el tiempo de respuesta.
    $dummy = '$2y$10$TWfHWKUKhNMG1iOwF8/wmeYZJOff8PH6eIN7TcCQ.Ybeqg0aX.v/u';
    $generic = 'Correo o contraseña incorrectos';

    $st = $pdo->prepare('SELECT * FROM usuarios WHERE email = ?');
    $st->execute([$email]);
    $user = $st->fetch() ?: null;

    if ($user === null) {
        password_verify($password, $dummy);

        return ['ok' => false, 'error' => $generic];
    }
    if ($user['bloqueado_hasta'] !== null && strtotime((string) $user['bloqueado_hasta']) > time()) {
        password_verify($password, $dummy);

        return [
            'ok' => false,
            'bloqueado' => true,
            'error' => 'La cuenta está bloqueada temporalmente por intentos fallidos. Probá de nuevo en unos minutos.',
        ];
    }
    if (!password_verify($password, (string) $user['clave_hash'])) {
        $attempts = (int) $user['intentos'] + 1;
        $lock = $attempts >= USER_MAX_ATTEMPTS;
        $pdo->prepare(
            'UPDATE usuarios SET intentos = ?, bloqueado_hasta = ' .
            ($lock ? 'NOW() + INTERVAL ' . USER_LOCK_MINUTES . ' MINUTE' : 'NULL') . ' WHERE id = ?'
        )->execute([$lock ? 0 : $attempts, $user['id']]);

        return ['ok' => false, 'error' => $generic, 'usuario' => $user];
    }
    if (!(int) $user['activo']) {
        return ['ok' => false, 'error' => 'El usuario está desactivado. Consultá con un administrador.', 'usuario' => $user];
    }

    $rehash = password_needs_rehash((string) $user['clave_hash'], PASSWORD_DEFAULT);
    $pdo->prepare(
        'UPDATE usuarios SET intentos = 0, bloqueado_hasta = NULL, ultimo_ingreso = NOW()' .
        ($rehash ? ', clave_hash = ?' : '') . ' WHERE id = ?'
    )->execute($rehash ? [user_hash($password), $user['id']] : [$user['id']]);

    return ['ok' => true, 'usuario' => $user];
}

/**
 * Datos públicos de un usuario (nunca el hash).
 *
 * @return array<string, mixed>
 */
function user_public(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'nombre' => $row['nombre'],
        'email' => $row['email'],
        'rol' => $row['rol'],
        'activo' => (bool) (int) $row['activo'],
        'debe_cambiar' => (bool) (int) $row['debe_cambiar'],
        'bloqueado' => $row['bloqueado_hasta'] !== null && strtotime((string) $row['bloqueado_hasta']) > time(),
        'ultimo_ingreso' => $row['ultimo_ingreso'],
        'creado' => $row['creado'],
    ];
}
