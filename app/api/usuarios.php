<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Administración de usuarios (requiere rol administrador o la clave maestra).
 *
 *   GET    usuarios.php                                   Lista de usuarios.
 *   POST   usuarios.php {nombre, email, rol}              Alta: desde ese momento ingresa con Google.
 *   POST   usuarios.php {accion: 'editar', id, nombre?, rol?, activo?}
 *   DELETE usuarios.php?id=N                              Elimina un usuario.
 *
 * No hay contraseñas: cada persona ingresa con su cuenta de Google de Intellihelp, así
 * que el correo tiene que ser de ese dominio. Reglas: siempre queda al menos un
 * administrador activo, y nadie puede quitarse a sí mismo el rol de administrador,
 * desactivarse ni eliminarse. Cambiar el rol o desactivar cierra las sesiones abiertas
 * de ese usuario.
 *
 * Con ITHub configurado, quién entra y con qué rol lo decide ITHub (→ Permisos): el rol de
 * ITHub se aplica en cada ingreso y el usuario se crea solo la primera vez. Lo que se cambie
 * acá vale hasta el próximo ingreso de la persona.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$config = require __DIR__ . '/bootstrap.php';
api_headers('GET, POST, DELETE, OPTIONS');
$pdo = api_db($config);
/** Bloqueo de los cambios de rol y de estado (regla del último administrador). */
const USUARIOS_LOCK = 'remitos_usuarios';

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
    api_respond(200, ['ok' => true, 'usuarios' => array_map(static fn (array $r): array => user_public($r), $rows)]);
}

if ($method === 'POST') {
    $body = api_body();
    $action = (string) ($body['accion'] ?? 'crear');

    if ($action === 'crear') {
        auth_confirm_identity($auth);
        $name = api_str($body['nombre'] ?? '', 120);
        $email = user_normalize_email($body['email'] ?? '');
        $role = (string) ($body['rol'] ?? 'operador');
        if ($name === '') {
            api_error(400, 'Indicá el nombre y apellido');
        }
        $problem = user_email_problem($email, $config);
        if ($problem !== null) {
            api_error(400, $problem);
        }
        if (!isset(USER_ROLES[$role])) {
            api_error(400, 'Rol inválido (admin u operador)');
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE email = ?');
        $st->execute([$email]);
        if ((int) $st->fetchColumn() > 0) {
            api_error(409, 'Ya existe un usuario con ese correo');
        }

        try {
            $pdo->prepare('INSERT INTO usuarios (nombre, email, rol, activo, creado) VALUES (?, ?, ?, 1, NOW())')
                ->execute([$name, $email, $role]);
        } catch (PDOException $e) {
            if (api_is_duplicate($e)) {
                api_error(409, 'Ya existe un usuario con ese correo');
            }
            throw $e;
        }
        $id = (int) $pdo->lastInsertId();
        audit_event($pdo, 'Usuario creado: ' . $name, [
            'categoria' => 'usuario', 'detalle' => $email . ' · ' . $role,
        ]);
        api_respond(201, [
            'ok' => true,
            'usuario' => user_public(user_find($pdo, $id)),
            'aviso' => ithub_config($config) !== null
                ? 'Para que pueda ingresar, dale acceso a Remitos en ITHub → Permisos (' . $email . '): el rol que tenga ahí se aplica en cada ingreso.'
                : 'Ya puede ingresar con «Ingresar con Google» usando ' . $email . '.',
        ]);
    }

    $id = (int) ($body['id'] ?? 0);
    $user = user_find($pdo, $id);

    if ($action === 'editar') {
        $name = array_key_exists('nombre', $body) ? api_str($body['nombre'], 120) : (string) $user['nombre'];
        $role = array_key_exists('rol', $body) ? (string) $body['rol'] : (string) $user['rol'];
        $active = array_key_exists('activo', $body) ? api_bool($body['activo'], true) : (bool) (int) $user['activo'];
        if ($name === '') {
            api_error(400, 'Indicá el nombre y apellido');
        }
        if (!isset(USER_ROLES[$role])) {
            api_error(400, 'Rol inválido (admin u operador)');
        }
        if ($role === 'admin' && $user['rol'] !== 'admin') {
            auth_confirm_identity($auth);
        }
        if ($id === $selfId && ($role !== 'admin' || !$active)) {
            api_error(409, 'No podés quitarte el rol de administrador ni desactivar tu propio usuario');
        }
        // La regla «al menos un administrador activo» se controla y aplica bajo bloqueo:
        // dos administradores no pueden desactivarse entre sí al mismo tiempo.
        api_lock($pdo, USUARIOS_LOCK);
        try {
            if (($role !== 'admin' || !$active) && $user['rol'] === 'admin' && user_active_admins($pdo, $id) === 0) {
                api_error(409, 'Tiene que quedar al menos un administrador activo');
            }
            $pdo->prepare('UPDATE usuarios SET nombre = ?, rol = ?, activo = ? WHERE id = ?')
                ->execute([$name, $role, $active ? 1 : 0, $id]);
        } finally {
            api_unlock($pdo, USUARIOS_LOCK);
        }
        if ($role !== $user['rol'] || $active !== (bool) (int) $user['activo']) {
            user_revoke_sessions($pdo, $id);
        }
        audit_event($pdo, 'Usuario actualizado: ' . $name, [
            'categoria' => 'usuario',
            'detalle' => $user['rol'] . ' → ' . $role . ($active ? '' : ' · desactivado'),
        ]);
        api_respond(200, ['ok' => true, 'usuario' => user_public(user_find($pdo, $id))]);
    }

    api_error(400, 'Acción desconocida');
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    $user = user_find($pdo, $id);
    if ($id === $selfId) {
        api_error(409, 'No podés eliminar tu propio usuario');
    }
    api_lock($pdo, USUARIOS_LOCK);
    try {
        if ($user['rol'] === 'admin' && (int) $user['activo'] && user_active_admins($pdo, $id) === 0) {
            api_error(409, 'Tiene que quedar al menos un administrador activo');
        }
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
    } finally {
        api_unlock($pdo, USUARIOS_LOCK);
    }
    audit_event($pdo, 'Usuario eliminado: ' . $user['nombre'], ['categoria' => 'usuario', 'detalle' => (string) $user['email']]);
    api_respond(200, ['ok' => true, 'id' => $id]);
}

api_error(405, 'Método no permitido');
