<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Administración de usuarios desde la línea de comandos.
 *
 *   php api/cli/usuario.php "Nombre Apellido" correo@dominio [admin|operador]   Alta (contraseña temporal).
 *   php api/cli/usuario.php restablecer correo@dominio                          Nueva contraseña temporal.
 *   php api/cli/usuario.php quitar-segundo-factor correo@dominio                Desactiva el TOTP.
 *
 * Sirve para crear el primer administrador y para recuperar el acceso si el único
 * administrador perdió la contraseña o el teléfono con el segundo factor. Toda
 * acción cierra las sesiones abiertas del usuario y queda en la auditoría.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

require __DIR__ . '/comun.php';
cli_require_schema($pdo);

$cli = ['categoria' => 'usuario', 'clave' => 'línea de comandos'];

/** Usuario por correo o fin con error. */
function cli_user(PDO $pdo, string $email): array
{
    $st = $pdo->prepare('SELECT * FROM usuarios WHERE email = ?');
    $st->execute([user_normalize_email($email)]);

    return $st->fetch() ?: cli_fail("No existe un usuario con el correo $email");
}

$command = $argv[1] ?? '';

if ($command === 'restablecer') {
    $user = cli_user($pdo, $argv[2] ?? '');
    $temporary = user_temporary_password();
    $pdo->prepare('UPDATE usuarios SET clave_hash = ?, debe_cambiar = 1, activo = 1, sesion_version = sesion_version + 1 WHERE id = ?')
        ->execute([user_hash($temporary), $user['id']]);
    attempts_clear($pdo, 'ingreso', (string) $user['email']);
    audit_event($pdo, 'Contraseña restablecida: ' . $user['nombre'], $cli);
    echo "Contraseña temporal de {$user['email']}: $temporary\nSe pedirá cambiarla en el próximo ingreso.\n";
    exit(0);
}

if ($command === 'quitar-segundo-factor') {
    $user = cli_user($pdo, $argv[2] ?? '');
    $pdo->prepare('UPDATE usuarios SET totp_activo = 0, totp_secret = NULL, totp_ultimo = NULL, sesion_version = sesion_version + 1 WHERE id = ?')
        ->execute([$user['id']]);
    audit_event($pdo, 'Segundo factor quitado: ' . $user['nombre'], $cli);
    echo "Segundo factor desactivado para {$user['email']}.\n";
    exit(0);
}

[$name, $email, $role] = [trim($argv[1] ?? ''), user_normalize_email($argv[2] ?? ''), $argv[3] ?? 'admin'];
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !isset(USER_ROLES[$role])) {
    cli_fail("Uso:\n  php api/cli/usuario.php \"Nombre Apellido\" correo@dominio [admin|operador]\n"
        . "  php api/cli/usuario.php restablecer correo@dominio\n  php api/cli/usuario.php quitar-segundo-factor correo@dominio");
}

$st = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE email = ?');
$st->execute([$email]);
if ((int) $st->fetchColumn() > 0) {
    cli_fail("Ya existe un usuario con el correo $email");
}

$temporary = user_temporary_password();
$pdo->prepare(
    'INSERT INTO usuarios (nombre, email, clave_hash, rol, activo, debe_cambiar, creado) VALUES (?, ?, ?, ?, 1, 1, NOW())'
)->execute([$name, $email, user_hash($temporary), $role]);
audit_event($pdo, 'Usuario creado: ' . $name, $cli + ['detalle' => "$email · $role"]);

echo "Usuario creado: $name <$email> ($role)\n";
echo "Contraseña temporal: $temporary\n";
echo "Se pedirá cambiarla en el primer ingreso.\n";
