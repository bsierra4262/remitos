<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Remitos compartidos.
 *
 *   GET    remitos.php?pagina=1&por_pagina=50   (leer)   Remitos completos, del más reciente al más antiguo.
 *   GET    remitos.php?id=…                     (leer)   Un remito.
 *   POST   remitos.php {id?, id_local?, …}      (crear)  Alta o edición. Sin número, el servidor asigna el
 *                                                        siguiente correlativo.
 *   DELETE remitos.php?id=…                     (borrar) Baja (no aplica a remitos autorizados).
 *
 * Un remito autorizado conserva su número, C.A.I. y vencimiento: esos datos solo
 * los asigna la autorización (talonarios.php).
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$config = require __DIR__ . '/bootstrap.php';
api_headers('GET, POST, DELETE, OPTIONS');
$pdo = api_db($config);

const REMITO_MAX_ITEMS = 60;
const REMITO_DOC_TYPES = ['', 'DNI', 'CI', 'Pasaporte', 'Otro'];

/**
 * Arma un remito con forma y longitudes controladas a partir del cuerpo recibido.
 *
 * @return array<string, mixed>
 */
function remito_from_input(array $body): array
{
    $section = static function (mixed $source, array $fields): array {
        $source = is_array($source) ? $source : [];
        $out = [];
        foreach ($fields as $field => $max) {
            $out[$field] = api_str($source[$field] ?? '', $max);
        }

        return $out;
    };

    $items = [];
    foreach (array_slice(is_array($body['items'] ?? null) ? $body['items'] : [], 0, REMITO_MAX_ITEMS) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $row = $section($item, ['articulo' => 40, 'descripcion' => 600, 'cantidad' => 40]);
        if (implode('', $row) !== '') {
            $items[] = $row;
        }
    }

    $recibi = $section($body['recibi'] ?? [], [
        'tipoDoc' => 12, 'nombre' => 120, 'documentoNro' => 20, 'sector' => 80, 'puesto' => 80,
    ]);
    if (!in_array($recibi['tipoDoc'], REMITO_DOC_TYPES, true)) {
        $recibi['tipoDoc'] = '';
    }

    $fecha = api_str($body['fecha'] ?? '', 10);
    if (!preg_match('#^\d{2}/\d{2}/\d{4}$#', $fecha)) {
        $fecha = date('d/m/Y');
    }
    $caiVto = api_str($body['caiVto'] ?? '', 10);

    return [
        'numero' => api_str($body['numero'] ?? '', 14),
        'fecha' => $fecha,
        'cai' => preg_replace('/\D/', '', api_str($body['cai'] ?? '', 14)),
        'caiVto' => preg_match('#^\d{2}/\d{2}/\d{4}$#', $caiVto) ? $caiVto : '',
        'cliente' => $section($body['cliente'] ?? [], [
            'senores' => 160, 'razonSocial' => 160, 'cuit' => 13, 'domicilio' => 160, 'localidad' => 80,
            'telefono' => 40, 'cp' => 10, 'mail' => 120, 'nombre' => 120, 'otrosDatos' => 160,
        ]),
        'items' => $items,
        'recibi' => $recibi,
        'transporte' => $section($body['transporte'] ?? [], [
            'nombre' => 120, 'vehiculo' => 120, 'chofer' => 120, 'lugarEntrega' => 160,
            'fecha' => 10, 'hora' => 5, 'observaciones' => 600,
        ]),
    ];
}

/** Correlativo de un número de remito («00000012» o «00001-00000012»). */
function remito_correlativo(string $numero): int
{
    $parts = explode('-', $numero);

    return (int) preg_replace('/\D/', '', (string) end($parts));
}

/** Remito guardado, listo para responder. */
function remito_public(array $row): array
{
    $doc = json_decode((string) $row['data'], true) ?: [];
    $doc['id'] = $row['id'];
    $doc['estado'] = $row['estado'] ?? ($doc['estado'] ?? 'borrador');
    $doc['facturado'] = (bool) (int) ($row['facturado'] ?? 0);
    $doc['actualizado_por'] = $row['actualizado_por'] ?? null;

    return $doc;
}

/** Remito por id, o null. */
function remito_find(PDO $pdo, string $id): ?array
{
    if (!preg_match('/^[0-9a-f]{16}$/', $id)) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM remitos WHERE id = ?');
    $st->execute([$id]);

    return $st->fetch() ?: null;
}

$method = api_method();

if ($method === 'GET') {
    auth_require($pdo, $config, 'leer', 'remitos');
    if (isset($_GET['id'])) {
        $row = remito_find($pdo, (string) $_GET['id']);
        if ($row === null) {
            api_error(404, 'Remito no encontrado');
        }
        api_respond(200, ['ok' => true, 'remito' => remito_public($row)]);
    }

    $perPage = max(1, min(200, (int) ($_GET['por_pagina'] ?? 50)));
    $page = max(1, (int) ($_GET['pagina'] ?? 1));
    $total = (int) $pdo->query('SELECT COUNT(*) FROM remitos')->fetchColumn();
    $offset = ($page - 1) * $perPage;
    $rows = $pdo->query("SELECT * FROM remitos ORDER BY updated DESC LIMIT $perPage OFFSET $offset")->fetchAll();
    api_respond(200, [
        'ok' => true,
        'total' => $total,
        'pagina' => $page,
        'paginas' => max(1, (int) ceil($total / $perPage)),
        'remitos' => array_map('remito_public', $rows),
    ]);
}

if ($method === 'POST') {
    $auth = auth_require($pdo, $config, 'crear', 'remitos');
    $body = api_body();
    $remito = remito_from_input($body);
    $now = (int) round(microtime(true) * 1000);
    $by = $auth['nombre'];

    $existing = null;
    $requestedId = (string) ($body['id'] ?? '');
    if ($requestedId !== '') {
        $existing = remito_find($pdo, $requestedId);
        if ($existing === null) {
            api_error(404, 'Remito no encontrado');
        }
    }
    $localId = api_str($body['id_local'] ?? '', 40);
    $localId = preg_match('/^[A-Za-z0-9_-]{4,40}$/', $localId) ? $localId : '';
    if ($existing === null && $localId !== '') {
        $st = $pdo->prepare('SELECT * FROM remitos WHERE id_local = ?');
        $st->execute([$localId]);
        $existing = $st->fetch() ?: null;
    }

    $pdo->query("SELECT GET_LOCK('remitos_numero', 10)");
    try {
        $estado = $existing['estado'] ?? 'borrador';
        if ($estado === 'autorizado') {
            // Los datos fiscales de un remito autorizado no se modifican.
            $previous = json_decode((string) $existing['data'], true) ?: [];
            foreach (['numero', 'cai', 'caiVto'] as $field) {
                $remito[$field] = (string) ($previous[$field] ?? '');
            }
        } elseif ($remito['numero'] === '') {
            $last = (int) $pdo->query('SELECT COALESCE(MAX(numero_int), 0) FROM remitos')->fetchColumn();
            $remito['numero'] = str_pad((string) ($last + 1), 8, '0', STR_PAD_LEFT);
        }
        $numeroInt = remito_correlativo($remito['numero']);

        $st = $pdo->prepare('SELECT COUNT(*) FROM remitos WHERE numero = ? AND id <> ?');
        $st->execute([$remito['numero'], $existing['id'] ?? '']);
        if ((int) $st->fetchColumn() > 0) {
            api_error(409, 'Ya existe el remito Nº ' . $remito['numero'] . '. Dejá el número vacío para asignar el siguiente.');
        }

        $id = $existing['id'] ?? bin2hex(random_bytes(8));
        $created = isset($existing['created']) ? (int) $existing['created'] : $now;
        $origin = $existing['origen'] ?? (isset($auth['usuario']) ? 'app' : 'api');
        $document = ['id' => $id, 'tipo' => 'remito'] + $remito
            + ['estado' => $estado, 'origen' => $origin, 'created' => $created, 'updated' => $now];
        $client = $remito['cliente']['razonSocial'] ?: $remito['cliente']['senores'];
        $json = json_encode($document, JSON_UNESCAPED_UNICODE);

        if ($existing) {
            $pdo->prepare(
                'UPDATE remitos SET numero = ?, numero_int = ?, fecha = ?, cliente = ?, data = ?, updated = ?, actualizado_por = ?
                 WHERE id = ?'
            )->execute([$remito['numero'], $numeroInt, $remito['fecha'], $client, $json, $now, $by, $id]);
        } else {
            $pdo->prepare(
                'INSERT INTO remitos (id, id_local, numero, numero_int, fecha, cliente, data, origen, estado,
                                      created, updated, creado_por, actualizado_por)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $id, $localId ?: null, $remito['numero'], $numeroInt, $remito['fecha'], $client, $json, $origin,
                'borrador', $now, $now, $by, $by,
            ]);
            audit_event($pdo, 'Remito guardado Nº ' . $remito['numero'], [
                'categoria' => 'remito', 'clave' => $by, 'detalle' => mb_substr($client, 0, 120),
            ]);
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('remitos_numero')");
    }

    api_respond($existing ? 200 : 201, [
        'ok' => true,
        'id' => $id,
        'actualizado' => (bool) $existing,
        'remito' => remito_public(remito_find($pdo, $id)),
        'url' => api_base_url($config) . '/?open=' . $id,
    ]);
}

if ($method === 'DELETE') {
    $auth = auth_require($pdo, $config, 'borrar', 'remitos');
    $id = (string) ($_GET['id'] ?? '');
    if (!preg_match('/^[0-9a-f]{16}$/', $id)) {
        api_error(400, 'Identificador de remito inválido');
    }
    $row = remito_find($pdo, $id);
    if ($row === null) {
        api_error(404, 'Remito no encontrado');
    }
    if (($row['estado'] ?? '') === 'autorizado') {
        api_error(409, 'Un remito autorizado no se elimina: su número y C.A.I. ya fueron asignados.');
    }
    $pdo->prepare('DELETE FROM remitos WHERE id = ?')->execute([$id]);
    audit_event($pdo, 'Remito eliminado' . ($row['numero'] ? ' Nº ' . $row['numero'] : ''), [
        'categoria' => 'remito', 'clave' => $auth['nombre'], 'detalle' => $row['cliente'],
    ]);
    api_respond(200, ['ok' => true, 'id' => $id]);
}

api_error(405, 'Método no permitido');
