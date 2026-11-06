<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Administración de usuarios desde la línea de comandos.
 *
 *   php api/cli/usuario.php "Nombre Apellido" correo@dominio [admin|operador]   Alta.
 *   php api/cli/usuario.php admin correo@dominio                                Lo activa como administrador.
 *
 * Se ingresa solo con la cuenta de Google de Intellihelp: no hay contraseñas que
 * entregar. Sirve para crear el primer administrador y para recuperar el acceso si
 * no queda ningún administrador que pueda ingresar. Toda acción cierra las sesiones
 * abiertas del usuario y queda en la auditoría.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

require __DIR__ . '/comun.php';
cli_require_schema($pdo);

$cli = ['categoria' => 'usuario', 'clave' => 'línea de comandos'];
$command = $argv[1] ?? '';

if ($command === 'admin') {
    $st = $pdo->prepare('SELECT * FROM usuarios WHERE email = ?');
    $st->execute([user_normalize_email($argv[2] ?? '')]);
    $user = $st->fetch() ?: cli_fail('No existe un usuario con el correo ' . ($argv[2] ?? ''));
    $pdo->prepare("UPDATE usuarios SET rol = 'admin', activo = 1, sesion_version = sesion_version + 1 WHERE id = ?")
        ->execute([$user['id']]);
    audit_event($pdo, 'Usuario activado como administrador: ' . $user['nombre'], $cli + ['detalle' => (string) $user['email']]);
    echo "{$user['email']} quedó activo como administrador. Ingresa con «Ingresar con Google».\n";
    exit(0);
}

[$name, $email, $role] = [trim($argv[1] ?? ''), user_normalize_email($argv[2] ?? ''), $argv[3] ?? 'admin'];
if ($name === '' || $email === '' || !isset(USER_ROLES[$role])) {
    cli_fail("Uso:\n  php api/cli/usuario.php \"Nombre Apellido\" correo@dominio [admin|operador]\n"
        . '  php api/cli/usuario.php admin correo@dominio');
}
$problem = user_email_problem($email, $config);
if ($problem !== null) {
    cli_fail($problem);
}

$st = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE email = ?');
$st->execute([$email]);
if ((int) $st->fetchColumn() > 0) {
    cli_fail("Ya existe un usuario con el correo $email");
}

$pdo->prepare('INSERT INTO usuarios (nombre, email, rol, activo, creado) VALUES (?, ?, ?, 1, NOW())')->execute([$name, $email, $role]);
audit_event($pdo, 'Usuario creado: ' . $name, $cli + ['detalle' => "$email · $role"]);

echo "Usuario creado: $name <$email> ($role)\n";
echo "Ingresa con «Ingresar con Google» usando esa cuenta.\n";
