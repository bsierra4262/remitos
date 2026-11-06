<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Usuarios: se ingresa solo con la cuenta de Google de Intellihelp (ver lib/google.php).
 * La aplicación no guarda contraseñas: el usuario existe para dar el rol y poder
 * desactivarlo, y Google identifica a la persona.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/** Normaliza un correo (minúsculas, sin espacios). */
function user_normalize_email(mixed $email): string
{
    return strtolower(trim(is_string($email) ? $email : ''));
}

/**
 * Motivo por el que el correo no sirve para un usuario, o null si sirve. Con el ingreso
 * con Google configurado, tiene que ser del dominio de Google Workspace de la empresa:
 * otro correo no podría ingresar nunca.
 */
function user_email_problem(string $email, array $config): ?string
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160) {
        return 'El correo no es válido';
    }
    $g = google_config($config);
    if ($g !== null && !str_ends_with($email, '@' . $g['dominio'])) {
        return 'El correo tiene que ser una cuenta de Google de ' . $g['dominio'] . ': es con la que se ingresa';
    }

    return null;
}

/**
 * Reconfirma la identidad del usuario con sesión antes de una operación sensible
 * (certificado de ARCA). Vale el ingreso con Google de los últimos
 * SESSION_CONFIRM_SECONDS; pasado ese plazo la respuesta pide volver a confirmar con
 * Google (`reconfirmar_google`), así una sesión robada no alcanza para estas operaciones.
 */
function user_confirm_identity(array $user): void
{
    if (empty($user['confirmado'])) {
        api_error(403, 'Por seguridad, confirmá tu identidad con Google y repetí la operación.', ['reconfirmar_google' => true]);
    }
}

/** Invalida todas las sesiones abiertas de un usuario (cambio de rol, baja). */
function user_revoke_sessions(PDO $pdo, int $userId): void
{
    $pdo->prepare('UPDATE usuarios SET sesion_version = sesion_version + 1 WHERE id = ?')->execute([$userId]);
}

/**
 * Datos públicos de un usuario.
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
        'ultimo_ingreso' => $row['ultimo_ingreso'],
        'creado' => $row['creado'],
    ];
}
