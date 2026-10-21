<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Alta de un usuario desde la línea de comandos (por ejemplo, el primer administrador).
 *
 *   php api/cli/usuario.php "Nombre Apellido" correo@intellihelp.tech [admin|operador]
 *
 * Imprime una contraseña temporal que se pide cambiar en el primer ingreso.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/bootstrap.php';
$pdo = api_db($config);

[$name, $email, $role] = [trim($argv[1] ?? ''), user_normalize_email($argv[2] ?? ''), $argv[3] ?? 'admin'];
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !isset(USER_ROLES[$role])) {
    fwrite(STDERR, "Uso: php api/cli/usuario.php \"Nombre Apellido\" correo@dominio [admin|operador]\n");
    exit(1);
}

$st = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE email = ?');
$st->execute([$email]);
if ((int) $st->fetchColumn() > 0) {
    fwrite(STDERR, "Ya existe un usuario con el correo $email\n");
    exit(1);
}

$temporary = user_temporary_password();
$pdo->prepare(
    'INSERT INTO usuarios (nombre, email, clave_hash, rol, activo, debe_cambiar, creado) VALUES (?, ?, ?, ?, 1, 1, NOW())'
)->execute([$name, $email, user_hash($temporary), $role]);
audit_event($pdo, 'Usuario creado: ' . $name, ['categoria' => 'usuario', 'clave' => 'línea de comandos', 'detalle' => "$email · $role"]);

echo "Usuario creado: $name <$email> ($role)\n";
echo "Contraseña temporal: $temporary\n";
echo "Se pedirá cambiarla en el primer ingreso.\n";
