<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Administración de usuarios (requiere rol administrador o la clave maestra).
 *
 *   GET    usuarios.php                                   Lista de usuarios.
 *   POST   usuarios.php {nombre, email, rol}              Alta con contraseña temporal (se devuelve una vez).
 *   POST   usuarios.php {accion: 'editar', id, nombre?, rol?, activo?}
 *   POST   usuarios.php {accion: 'restablecer', id}       Nueva contraseña temporal.
 *   DELETE usuarios.php?id=N                              Elimina un usuario.
 *
 * Reglas: siempre queda al menos un administrador activo, y nadie puede
 * quitarse a sí mismo el rol de administrador, desactivarse ni eliminarse.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$config = require __DIR__ . '/bootstrap.php';
api_headers('GET, POST, DELETE, OPTIONS');
$pdo = api_db($config);
$auth = auth_require($pdo, $config, 'admin', 'usuarios');
$selfId = isset($auth['usuario']) ? (int) $auth['usuario']['id'] : 0;

/** Usuario por id o error 404. */
function user_find(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        api_error(404, 'No existe ese usuario');
    }

    return $row;
}

/** Cantidad de administradores activos, excluyendo opcionalmente un id. */
function user_active_admins(PDO $pdo, int $exclude = 0): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1 AND id <> ?");
    $st->execute([$exclude]);

    return (int) $st->fetchColumn();
}

$method = api_method();

if ($method === 'GET') {
    $rows = $pdo->query('SELECT * FROM usuarios ORDER BY activo DESC, nombre')->fetchAll();
    api_respond(200, ['ok' => true, 'usuarios' => array_map('user_public', $rows)]);
}

if ($method === 'POST') {
    $body = api_body();
    $action = (string) ($body['accion'] ?? 'crear');

    if ($action === 'crear') {
        $name = api_str($body['nombre'] ?? '', 120);
        $email = user_normalize_email($body['email'] ?? '');
        $role = (string) ($body['rol'] ?? 'operador');
        if ($name === '') {
            api_error(400, 'Indicá el nombre y apellido');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160) {
            api_error(400, 'El correo no es válido');
        }
        if (!isset(USER_ROLES[$role])) {
            api_error(400, 'Rol inválido (admin u operador)');
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE email = ?');
        $st->execute([$email]);
        if ((int) $st->fetchColumn() > 0) {
            api_error(409, 'Ya existe un usuario con ese correo');
        }

        $temporary = user_temporary_password();
        $pdo->prepare(
            'INSERT INTO usuarios (nombre, email, clave_hash, rol, activo, debe_cambiar, creado) VALUES (?, ?, ?, ?, 1, 1, NOW())'
        )->execute([$name, $email, user_hash($temporary), $role]);
        $id = (int) $pdo->lastInsertId();
        audit_event($pdo, 'Usuario creado: ' . $name, [
            'categoria' => 'usuario', 'clave' => $auth['nombre'], 'detalle' => $email . ' · ' . $role,
        ]);
        api_respond(201, [
            'ok' => true,
            'usuario' => user_public(user_find($pdo, $id)),
            'clave_temporal' => $temporary,
            'aviso' => 'Compartí la contraseña temporal por un medio seguro: se muestra una sola vez y se pide cambiarla al ingresar.',
        ]);
    }

    $id = (int) ($body['id'] ?? 0);
    $user = user_find($pdo, $id);

    if ($action === 'editar') {
        $name = array_key_exists('nombre', $body) ? api_str($body['nombre'], 120) : (string) $user['nombre'];
        $role = array_key_exists('rol', $body) ? (string) $body['rol'] : (string) $user['rol'];
        $active = array_key_exists('activo', $body) ? (bool) $body['activo'] : (bool) (int) $user['activo'];
        if ($name === '') {
            api_error(400, 'Indicá el nombre y apellido');
        }
        if (!isset(USER_ROLES[$role])) {
            api_error(400, 'Rol inválido (admin u operador)');
        }
        if ($id === $selfId && ($role !== 'admin' || !$active)) {
            api_error(409, 'No podés quitarte el rol de administrador ni desactivar tu propio usuario');
        }
        if (($role !== 'admin' || !$active) && $user['rol'] === 'admin' && user_active_admins($pdo, $id) === 0) {
            api_error(409, 'Tiene que quedar al menos un administrador activo');
        }
        $pdo->prepare('UPDATE usuarios SET nombre = ?, rol = ?, activo = ?, intentos = 0, bloqueado_hasta = NULL WHERE id = ?')
            ->execute([$name, $role, $active ? 1 : 0, $id]);
        audit_event($pdo, 'Usuario actualizado: ' . $name, [
            'categoria' => 'usuario', 'clave' => $auth['nombre'],
            'detalle' => $role . ($active ? '' : ' · desactivado'),
        ]);
        api_respond(200, ['ok' => true, 'usuario' => user_public(user_find($pdo, $id))]);
    }

    if ($action === 'restablecer') {
        $temporary = user_temporary_password();
        $pdo->prepare('UPDATE usuarios SET clave_hash = ?, debe_cambiar = 1, intentos = 0, bloqueado_hasta = NULL WHERE id = ?')
            ->execute([user_hash($temporary), $id]);
        audit_event($pdo, 'Contraseña restablecida: ' . $user['nombre'], ['categoria' => 'usuario', 'clave' => $auth['nombre']]);
        api_respond(200, [
            'ok' => true,
            'clave_temporal' => $temporary,
            'aviso' => 'Compartí la contraseña temporal por un medio seguro: se muestra una sola vez.',
        ]);
    }

    api_error(400, 'Acción desconocida');
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    $user = user_find($pdo, $id);
    if ($id === $selfId) {
        api_error(409, 'No podés eliminar tu propio usuario');
    }
    if ($user['rol'] === 'admin' && (int) $user['activo'] && user_active_admins($pdo, $id) === 0) {
        api_error(409, 'Tiene que quedar al menos un administrador activo');
    }
    $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
    audit_event($pdo, 'Usuario eliminado: ' . $user['nombre'], ['categoria' => 'usuario', 'clave' => $auth['nombre']]);
    api_respond(200, ['ok' => true, 'id' => $id]);
}

api_error(405, 'Método no permitido');
