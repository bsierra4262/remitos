<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Administración de claves de servicio y consulta de la auditoría.
 * Todo requiere la clave maestra: una clave de servicio no puede administrarse a sí misma.
 *
 *   GET    claves.php?accion=listar       Claves y resumen de uso.
 *   GET    claves.php?accion=registro     Auditoría paginada (pagina, por_pagina, categoria).
 *   GET    claves.php?accion=integridad   Verifica la cadena de hashes de la auditoría.
 *   POST   claves.php {nombre, permisos}  Crea una clave (se devuelve completa una sola vez).
 *   POST   claves.php {id, activa}        Activa o desactiva una clave.
 *   DELETE claves.php?id=N                Elimina una clave.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$config = require __DIR__ . '/bootstrap.php';
api_headers('GET, POST, DELETE, OPTIONS');
$pdo = api_db($config);
$auth = auth_require($pdo, $config, 'admin', 'claves');

/**
 * Datos de una clave aptos para mostrar (nunca la clave ni su hash).
 *
 * @return array<string, mixed>
 */
function key_public(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'nombre' => $row['nombre'],
        'prefijo' => $row['prefijo'] . '…',
        'permisos' => auth_parse_permissions((string) $row['permisos']),
        'activa' => (bool) (int) $row['activa'],
        'nota' => $row['nota'],
        'creada' => $row['creada'],
        'ultimo_uso' => $row['ultimo_uso'],
        'usos' => (int) $row['usos'],
    ];
}

/** Nombre de una clave por id, o null si no existe. */
function key_name(PDO $pdo, int $id): ?string
{
    $st = $pdo->prepare('SELECT nombre FROM api_keys WHERE id = ?');
    $st->execute([$id]);
    $name = $st->fetchColumn();

    return $name === false ? null : (string) $name;
}

$method = api_method();

if ($method === 'GET') {
    if (($_GET['accion'] ?? '') === 'integridad') {
        $result = audit_verify($pdo);
        if (!$result['ok']) {
            audit_event($pdo, 'Integridad de la auditoría: falla detectada', [
                'categoria' => 'sistema', 'nivel' => 'error', 'detalle' => json_encode($result['falla'], JSON_UNESCAPED_UNICODE),
            ]);
        }
        api_respond(200, ['ok' => true, 'integridad' => $result, 'retencion_dias' => AUDIT_RETENTION_DAYS]);
    }

    if (($_GET['accion'] ?? 'listar') === 'registro') {
        $perPage = max(5, min(100, (int) ($_GET['por_pagina'] ?? 25)));
        $page = max(1, (int) ($_GET['pagina'] ?? 1));
        $category = (string) ($_GET['categoria'] ?? '');
        $where = '';
        $params = [];
        if ($category === 'error') {
            $where = "WHERE nivel = 'error'";
        } elseif (in_array($category, AUDIT_CATEGORIES, true)) {
            $where = 'WHERE categoria = ?';
            $params[] = $category;
        }

        $st = $pdo->prepare("SELECT COUNT(*) FROM api_log $where");
        $st->execute($params);
        $total = (int) $st->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $st = $pdo->prepare(
            "SELECT id, categoria, nivel, origen, usuario_id, clave, accion, detalle, recurso, estado, ip, fecha
               FROM api_log $where ORDER BY id DESC LIMIT $perPage OFFSET $offset"
        );
        $st->execute($params);

        $byCategory = [];
        foreach ($pdo->query('SELECT categoria, COUNT(*) n FROM api_log GROUP BY categoria')->fetchAll() as $row) {
            $byCategory[$row['categoria']] = (int) $row['n'];
        }

        api_respond(200, [
            'ok' => true,
            'movimientos' => $st->fetchAll(),
            'total' => $total,
            'pagina' => $page,
            'por_pagina' => $perPage,
            'paginas' => $pages,
            'por_categoria' => $byCategory,
            'errores' => (int) $pdo->query("SELECT COUNT(*) FROM api_log WHERE nivel = 'error'")->fetchColumn(),
        ]);
    }

    $keys = array_map('key_public', $pdo->query('SELECT * FROM api_keys ORDER BY activa DESC, id DESC')->fetchAll());
    $active = count(array_filter($keys, static fn (array $k): bool => $k['activa']));
    $rejected = (int) $pdo->query(
        "SELECT COUNT(*) FROM api_log
          WHERE categoria = 'acceso' AND nivel = 'error' AND fecha > NOW() - INTERVAL 7 DAY"
    )->fetchColumn();

    api_respond(200, [
        'ok' => true,
        'claves' => $keys,
        'resumen' => [
            'total' => count($keys),
            'activas' => $active,
            'inactivas' => count($keys) - $active,
            'usos' => array_sum(array_column($keys, 'usos')),
            'rechazos_7d' => $rejected,
        ],
    ]);
}

if ($method === 'POST') {
    $body = api_body();

    if (isset($body['id'])) {
        $id = (int) $body['id'];
        $name = key_name($pdo, $id);
        if ($name === null) {
            api_error(404, 'No existe esa clave');
        }
        $active = api_bool($body['activa']);
        $pdo->prepare('UPDATE api_keys SET activa = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
        audit_event($pdo, ($active ? 'Clave activada: ' : 'Clave desactivada: ') . $name, [
            'categoria' => 'clave',
        ]);
        api_respond(200, ['ok' => true, 'id' => $id, 'activa' => $active]);
    }

    $name = api_str($body['nombre'] ?? '', 81);
    if ($name === '') {
        api_error(400, 'Indicá un nombre para identificar la clave');
    }
    if (mb_strlen($name) > 80) {
        api_error(400, 'El nombre no puede superar los 80 caracteres');
    }

    $permissions = $body['permisos'] ?? ['leer'];
    if (!is_array($permissions)) {
        $permissions = explode(',', (string) $permissions);
    }
    $permissions = array_values(array_unique(array_filter(array_map(
        static fn (mixed $p): string => is_scalar($p) ? trim((string) $p) : '',
        $permissions
    ))));
    $invalid = array_diff($permissions, AUTH_PERMISSIONS);
    if ($invalid) {
        api_error(400, 'Permiso desconocido: ' . implode(', ', $invalid));
    }
    if (!$permissions) {
        api_error(400, 'Elegí al menos un permiso');
    }

    $key = auth_new_key();
    $pdo->prepare(
        'INSERT INTO api_keys (nombre, prefijo, hash, permisos, activa, nota, creada) VALUES (?, ?, ?, ?, 1, ?, NOW())'
    )->execute([
        $name,
        substr($key, 0, 12),
        hash('sha256', $key),
        implode(',', $permissions),
        api_str($body['nota'] ?? '', 160) ?: null,
    ]);
    // Se lee antes de auditar: el registro de auditoría hace su propio INSERT.
    $newId = (int) $pdo->lastInsertId();

    audit_event($pdo, 'Clave creada: ' . $name, [
        'categoria' => 'clave', 'detalle' => 'permisos: ' . implode(', ', $permissions),
    ]);
    api_respond(201, [
        'ok' => true,
        'id' => $newId,
        'nombre' => $name,
        'clave' => $key,
        'aviso' => 'Guardala ahora: no se puede volver a ver. Si se pierde, revocala y creá otra.',
    ]);
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    $name = $id > 0 ? key_name($pdo, $id) : null;
    if ($name === null) {
        api_error(404, 'No existe esa clave');
    }
    $pdo->prepare('DELETE FROM api_keys WHERE id = ?')->execute([$id]);
    audit_event($pdo, 'Clave eliminada: ' . $name, ['categoria' => 'clave']);
    api_respond(200, ['ok' => true, 'id' => $id]);
}

api_error(405, 'Método no permitido');
