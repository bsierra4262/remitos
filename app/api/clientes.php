<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Agenda de clientes compartida.
 *
 *   GET    clientes.php                               (leer)   Todos los clientes, por razón social.
 *   POST   clientes.php {id?, razonSocial, cuit, …}   (crear)  Alta o edición.
 *   POST   clientes.php {accion: 'importar', clientes: […]}  (crear)  Alta o actualización por CUIT.
 *   DELETE clientes.php?id=N                          (borrar) Baja.
 *
 * El CUIT se normaliza a 99-99999999-9 y no puede repetirse.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$config = require __DIR__ . '/bootstrap.php';
api_headers('GET, POST, DELETE, OPTIONS');
$pdo = api_db($config);

/** Campos de un cliente y su longitud máxima. */
const CLIENT_FIELDS = [
    'razonSocial' => 160, 'cuit' => 13, 'condIVA' => 40, 'telefono' => 40, 'senores' => 160,
    'domicilio' => 160, 'localidad' => 80, 'cp' => 10, 'mail' => 120, 'nombre' => 120, 'otrosDatos' => 160,
];

/** Máximo de clientes aceptados por importación. */
const CLIENT_IMPORT_MAX = 2000;

/** CUIT con guiones si tiene 11 dígitos; cualquier otro valor se conserva recortado. */
function client_cuit(string $value): string
{
    $digits = preg_replace('/\D/', '', $value);
    if (strlen($digits) === 11) {
        return substr($digits, 0, 2) . '-' . substr($digits, 2, 8) . '-' . substr($digits, 10);
    }

    return mb_substr(trim($value), 0, 13);
}

/**
 * Cliente con forma y longitudes controladas.
 *
 * @return array<string, string>
 */
function client_from_input(array $input): array
{
    $client = [];
    foreach (CLIENT_FIELDS as $field => $max) {
        $client[$field] = api_str($input[$field] ?? '', $max);
    }
    $client['cuit'] = client_cuit($client['cuit']);
    $client['cp'] = strtoupper($client['cp']);

    return $client;
}

/** Cliente guardado, listo para responder. */
function client_public(array $row): array
{
    $data = json_decode((string) $row['data'], true) ?: [];

    return ['id' => (int) $row['id']] + array_merge(array_fill_keys(array_keys(CLIENT_FIELDS), ''), $data)
        + ['actualizado' => $row['actualizado'], 'actualizado_por' => $row['actualizado_por']];
}

/** Id del cliente con ese CUIT (excluyendo opcionalmente uno), o 0. */
function client_by_cuit(PDO $pdo, string $cuit, int $exclude = 0): int
{
    if ($cuit === '') {
        return 0;
    }
    $st = $pdo->prepare('SELECT id FROM clientes WHERE cuit = ? AND id <> ?');
    $st->execute([$cuit, $exclude]);

    return (int) $st->fetchColumn();
}

/** Inserta o actualiza un cliente y devuelve su id. */
function client_save(PDO $pdo, array $client, int $id, string $by): int
{
    $json = json_encode($client, JSON_UNESCAPED_UNICODE);
    $cuit = $client['cuit'] !== '' ? $client['cuit'] : null;
    if ($id > 0) {
        $pdo->prepare(
            'UPDATE clientes SET razon_social = ?, cuit = ?, data = ?, actualizado = NOW(), actualizado_por = ? WHERE id = ?'
        )->execute([$client['razonSocial'], $cuit, $json, $by, $id]);

        return $id;
    }
    $pdo->prepare(
        'INSERT INTO clientes (razon_social, cuit, data, creado, actualizado, creado_por, actualizado_por)
         VALUES (?, ?, ?, NOW(), NOW(), ?, ?)'
    )->execute([$client['razonSocial'], $cuit, $json, $by, $by]);

    return (int) $pdo->lastInsertId();
}

$method = api_method();

if ($method === 'GET') {
    auth_require($pdo, $config, 'leer', 'clientes');
    $rows = $pdo->query('SELECT * FROM clientes ORDER BY razon_social, id LIMIT 5000')->fetchAll();
    api_respond(200, ['ok' => true, 'total' => count($rows), 'clientes' => array_map('client_public', $rows)]);
}

if ($method === 'POST') {
    $auth = auth_require($pdo, $config, 'crear', 'clientes');
    $body = api_body();

    if (($body['accion'] ?? '') === 'importar') {
        $list = $body['clientes'] ?? null;
        if (!is_array($list) || !array_is_list($list)) {
            api_error(400, 'Falta la lista de clientes a importar');
        }
        if (count($list) > CLIENT_IMPORT_MAX) {
            api_error(413, 'Se pueden importar hasta ' . CLIENT_IMPORT_MAX . ' clientes por vez');
        }
        $added = $updated = $skipped = 0;
        $pdo->beginTransaction();
        try {
            foreach ($list as $item) {
                $client = client_from_input(is_array($item) ? $item : []);
                if ($client['razonSocial'] === '' && $client['cuit'] === '') {
                    $skipped++;
                    continue;
                }
                $existing = client_by_cuit($pdo, $client['cuit']);
                if ($existing > 0) {
                    $st = $pdo->prepare('SELECT data FROM clientes WHERE id = ?');
                    $st->execute([$existing]);
                    $previous = json_decode((string) $st->fetchColumn(), true) ?: [];
                    // Los campos vacíos de la planilla no borran datos ya cargados.
                    $merged = array_merge($previous, array_filter($client, static fn (string $v): bool => $v !== ''));
                    client_save($pdo, client_from_input($merged), $existing, $auth['nombre']);
                    $updated++;
                } else {
                    client_save($pdo, $client, 0, $auth['nombre']);
                    $added++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        audit_event($pdo, 'Clientes importados', [
            'categoria' => 'cliente', 'clave' => $auth['nombre'],
            'detalle' => "$added nuevos · $updated actualizados · $skipped sin datos",
        ]);
        api_respond(200, ['ok' => true, 'agregados' => $added, 'actualizados' => $updated, 'omitidos' => $skipped]);
    }

    $client = client_from_input($body);
    $id = (int) ($body['id'] ?? 0);
    if ($client['razonSocial'] === '' && $client['cuit'] === '') {
        api_error(400, 'Completá al menos la razón social o el CUIT');
    }
    if ($id > 0) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM clientes WHERE id = ?');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() === 0) {
            api_error(404, 'No existe ese cliente');
        }
    }
    $duplicate = client_by_cuit($pdo, $client['cuit'], $id);
    if ($duplicate > 0) {
        api_error(409, 'Ya hay un cliente con el CUIT ' . $client['cuit'], ['id_existente' => $duplicate]);
    }

    $savedId = client_save($pdo, $client, $id, $auth['nombre']);
    audit_event($pdo, ($id > 0 ? 'Cliente actualizado: ' : 'Cliente creado: ') . ($client['razonSocial'] ?: $client['cuit']), [
        'categoria' => 'cliente', 'clave' => $auth['nombre'],
    ]);
    $st = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
    $st->execute([$savedId]);
    api_respond($id > 0 ? 200 : 201, ['ok' => true, 'cliente' => client_public($st->fetch())]);
}

if ($method === 'DELETE') {
    $auth = auth_require($pdo, $config, 'borrar', 'clientes');
    $id = (int) ($_GET['id'] ?? 0);
    $st = $pdo->prepare('SELECT razon_social, cuit FROM clientes WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        api_error(404, 'No existe ese cliente');
    }
    $pdo->prepare('DELETE FROM clientes WHERE id = ?')->execute([$id]);
    audit_event($pdo, 'Cliente eliminado: ' . ($row['razon_social'] ?: $row['cuit']), [
        'categoria' => 'cliente', 'clave' => $auth['nombre'],
    ]);
    api_respond(200, ['ok' => true, 'id' => $id]);
}

api_error(405, 'Método no permitido');
