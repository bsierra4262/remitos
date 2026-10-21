<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Remitos: alta desde integraciones y respaldo de la aplicación.
 *
 *   POST   remitos.php          (crear)  Crea un remito. Si trae `id_local` (respaldo de la
 *                                        aplicación) y ya existe, lo actualiza.
 *   GET    remitos.php?id=…     (pública) Devuelve un remito por su identificador.
 *   GET    remitos.php          (leer)   Lista los últimos 100.
 *   DELETE remitos.php?id=…     (borrar) Elimina un remito.
 *
 * La lectura por identificador es pública porque el id es aleatorio de 64 bits
 * y no se puede adivinar: es el enlace que abre el remito listo para imprimir.
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

    return [
        'numero' => api_str($body['numero'] ?? '', 40),
        'fecha' => $fecha,
        'estado' => ($body['estado'] ?? '') === 'autorizado' ? 'autorizado' : 'borrador',
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

$method = api_method();

if ($method === 'GET') {
    $id = $_GET['id'] ?? null;
    if ($id !== null) {
        if (!is_string($id) || !preg_match('/^[0-9a-f]{16}$/', $id)) {
            api_error(404, 'Remito no encontrado');
        }
        $st = $pdo->prepare('SELECT data FROM remitos WHERE id = ?');
        $st->execute([$id]);
        $data = $st->fetchColumn();
        if ($data === false) {
            api_error(404, 'Remito no encontrado');
        }
        api_respond(200, ['ok' => true, 'remito' => json_decode((string) $data, true)]);
    }

    auth_require($pdo, $config, 'leer', 'remitos');
    $rows = $pdo->query(
        'SELECT id, numero, fecha, cliente, origen, facturado, updated FROM remitos ORDER BY updated DESC LIMIT 100'
    )->fetchAll();
    foreach ($rows as &$row) {
        $row['facturado'] = (bool) (int) $row['facturado'];
        $row['updated'] = (int) $row['updated'];
    }
    unset($row);
    api_respond(200, ['ok' => true, 'total' => count($rows), 'items' => $rows]);
}

if ($method === 'POST') {
    $auth = auth_require($pdo, $config, 'crear', 'remitos');
    $body = api_body();
    $remito = remito_from_input($body);
    $now = (int) round(microtime(true) * 1000);

    $localId = api_str($body['id_local'] ?? '', 40);
    $localId = preg_match('/^[A-Za-z0-9_-]{4,40}$/', $localId) ? $localId : '';
    $origin = $localId !== '' ? 'app' : 'api';
    $client = $remito['cliente']['razonSocial'] ?: $remito['cliente']['senores'];

    $existing = null;
    if ($localId !== '') {
        $st = $pdo->prepare('SELECT id, created FROM remitos WHERE id_local = ?');
        $st->execute([$localId]);
        $existing = $st->fetch() ?: null;
    }

    $id = $existing['id'] ?? bin2hex(random_bytes(8));
    $created = isset($existing['created']) ? (int) $existing['created'] : $now;
    $document = ['id' => $id, 'tipo' => 'remito'] + $remito
        + ['origen' => $origin, 'created' => $created, 'updated' => $now];
    $json = json_encode($document, JSON_UNESCAPED_UNICODE);

    if ($existing) {
        $pdo->prepare('UPDATE remitos SET numero = ?, fecha = ?, cliente = ?, data = ?, updated = ? WHERE id = ?')
            ->execute([$remito['numero'] ?: null, $remito['fecha'], $client, $json, $now, $id]);
    } else {
        $pdo->prepare(
            'INSERT INTO remitos (id, id_local, numero, fecha, cliente, data, origen, created, updated)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id, $localId ?: null, $remito['numero'] ?: null, $remito['fecha'], $client, $json, $origin, $now, $now,
        ]);
        audit_event(
            $pdo,
            ($origin === 'app' ? 'Remito respaldado' : 'Remito creado por API')
                . ($remito['numero'] !== '' ? ' Nº ' . $remito['numero'] : ''),
            ['categoria' => 'remito', 'clave' => $auth['nombre'], 'detalle' => mb_substr($client, 0, 120)]
        );
    }

    api_respond($existing ? 200 : 201, [
        'ok' => true,
        'id' => $id,
        'actualizado' => (bool) $existing,
        'url' => api_base_url($config) . '/?open=' . $id,
    ]);
}

if ($method === 'DELETE') {
    $auth = auth_require($pdo, $config, 'borrar', 'remitos');
    $id = (string) ($_GET['id'] ?? '');
    if (!preg_match('/^[0-9a-f]{16}$/', $id)) {
        api_error(400, 'Identificador de remito inválido');
    }
    $st = $pdo->prepare('SELECT numero, cliente FROM remitos WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        api_error(404, 'Remito no encontrado');
    }
    $pdo->prepare('DELETE FROM remitos WHERE id = ?')->execute([$id]);
    audit_event($pdo, 'Remito eliminado' . ($row['numero'] ? ' Nº ' . $row['numero'] : ''), [
        'categoria' => 'remito', 'clave' => $auth['nombre'], 'detalle' => $row['cliente'],
    ]);
    api_respond(200, ['ok' => true, 'id' => $id]);
}

api_error(405, 'Método no permitido');
