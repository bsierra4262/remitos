<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Facturas, recibos y notas de crédito y débito compartidos.
 *
 *   GET    comprobantes.php?pagina=1&por_pagina=50   (leer)   Comprobantes completos, del más reciente al más antiguo.
 *   GET    comprobantes.php?id=…                     (leer)   Un comprobante.
 *   POST   comprobantes.php {id?, tipo, letra, …}    (crear)  Alta o edición. Sin número, el servidor asigna el
 *                                                             siguiente de la serie (tipo, letra y punto de venta).
 *   DELETE comprobantes.php?id=…                     (borrar) Baja.
 *
 * Un comprobante con CAE solo lo modifica o elimina un administrador.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$config = require __DIR__ . '/bootstrap.php';
api_headers('GET, POST, DELETE, OPTIONS');
$pdo = api_db($config);

const COMP_TYPES = ['factura', 'recibo', 'nota'];
const COMP_LETTERS = ['A', 'B', 'C', 'X'];
const COMP_MAX_ITEMS = 60;

/**
 * Comprobante con forma y longitudes controladas.
 *
 * @return array<string, mixed>
 */
function comp_from_input(array $body): array
{
    $str = static fn (mixed $v, int $max): string => api_str($v, $max);
    $type = in_array($body['tipo'] ?? '', COMP_TYPES, true) ? $body['tipo'] : 'factura';
    $letter = strtoupper((string) ($body['letra'] ?? 'X'));
    $date = $str($body['fecha'] ?? '', 10);
    $caeVto = $str($body['caeVto'] ?? '', 10);
    $client = is_array($body['cliente'] ?? null) ? $body['cliente'] : [];
    $assoc = is_array($body['compAsoc'] ?? null) ? $body['compAsoc'] : [];

    $items = [];
    foreach (array_slice(is_array($body['items'] ?? null) ? $body['items'] : [], 0, COMP_MAX_ITEMS) as $item) {
        if (is_array($item)) {
            $items[] = [
                'descripcion' => $str($item['descripcion'] ?? '', 300),
                'cantidad' => $str($item['cantidad'] ?? '', 12),
                'precio' => $str($item['precio'] ?? '', 16),
            ];
        }
    }

    return [
        'tipo' => $type,
        'subtipo' => $type === 'nota' && ($body['subtipo'] ?? '') === 'debito' ? 'debito' : ($type === 'nota' ? 'credito' : ''),
        'letra' => in_array($letter, COMP_LETTERS, true) ? $letter : 'X',
        'ptoVenta' => str_pad(substr(preg_replace('/\D/', '', $str($body['ptoVenta'] ?? '', 5)) ?: '1', -5), 5, '0', STR_PAD_LEFT),
        'numero' => preg_replace('/\D/', '', $str($body['numero'] ?? '', 8)),
        'fecha' => preg_match('#^\d{2}/\d{2}/\d{4}$#', $date) ? $date : date('d/m/Y'),
        'cliente' => [
            'razonSocial' => $str($client['razonSocial'] ?? '', 160),
            'cuit' => $str($client['cuit'] ?? '', 13),
            'condIVA' => $str($client['condIVA'] ?? '', 40),
            'domicilio' => $str($client['domicilio'] ?? '', 160),
            'localidad' => $str($client['localidad'] ?? '', 80),
            'cp' => $str($client['cp'] ?? '', 10),
        ],
        'condVenta' => $str($body['condVenta'] ?? '', 80),
        'items' => $items,
        'alicuota' => $str($body['alicuota'] ?? '21', 5),
        'concepto' => $str($body['concepto'] ?? '', 400),
        'monto' => $str($body['monto'] ?? '', 16),
        'medioPago' => $str($body['medioPago'] ?? '', 40),
        'compAsoc' => [
            'tipo' => $str($assoc['tipo'] ?? '', 20),
            'letra' => $str($assoc['letra'] ?? '', 2),
            'pv' => $str($assoc['pv'] ?? '', 5),
            'nro' => $str($assoc['nro'] ?? '', 8),
        ],
        'cae' => preg_replace('/\D/', '', $str($body['cae'] ?? '', 14)),
        'caeVto' => preg_match('#^\d{2}/\d{2}/\d{4}$#', $caeVto) ? $caeVto : '',
        'observaciones' => $str($body['observaciones'] ?? '', 600),
    ];
}

/** Comprobante guardado, listo para responder. */
function comp_public(array $row): array
{
    $doc = json_decode((string) ($row['data'] ?? ''), true) ?: [];
    $doc['id'] = $row['id'];
    $doc['updated'] = (int) $row['updated'];
    $doc['actualizado_por'] = $row['actualizado_por'] ?? null;

    return $doc;
}

/** Comprobante por id, o null. */
function comp_find(PDO $pdo, string $id): ?array
{
    if (!preg_match('/^[0-9a-f]{16}$/', $id)) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM comprobantes WHERE id = ?');
    $st->execute([$id]);

    return $st->fetch() ?: null;
}

$method = api_method();

if ($method === 'GET') {
    auth_require($pdo, $config, 'leer', 'comprobantes');
    if (isset($_GET['id'])) {
        $row = comp_find($pdo, (string) $_GET['id']);
        if ($row === null) {
            api_error(404, 'Comprobante no encontrado');
        }
        api_respond(200, ['ok' => true, 'comprobante' => comp_public($row)]);
    }
    $perPage = max(1, min(200, (int) ($_GET['por_pagina'] ?? 50)));
    $page = max(1, (int) ($_GET['pagina'] ?? 1));
    $total = (int) $pdo->query('SELECT COUNT(*) FROM comprobantes WHERE data IS NOT NULL')->fetchColumn();
    $offset = ($page - 1) * $perPage;
    $rows = $pdo->query(
        "SELECT * FROM comprobantes WHERE data IS NOT NULL ORDER BY updated DESC LIMIT $perPage OFFSET $offset"
    )->fetchAll();
    api_respond(200, [
        'ok' => true,
        'total' => $total,
        'pagina' => $page,
        'paginas' => max(1, (int) ceil($total / $perPage)),
        'comprobantes' => array_map('comp_public', $rows),
    ]);
}

if ($method === 'POST') {
    $auth = auth_require($pdo, $config, 'crear', 'comprobantes');
    $body = api_body();
    $doc = comp_from_input($body);
    $now = (int) round(microtime(true) * 1000);

    $existing = null;
    if ((string) ($body['id'] ?? '') !== '') {
        $existing = comp_find($pdo, (string) $body['id']);
        if ($existing === null) {
            api_error(404, 'Comprobante no encontrado');
        }
        if ((string) $existing['cae'] !== '' && !in_array('admin', $auth['permisos'], true)) {
            api_error(409, 'Un comprobante con CAE solo lo puede corregir un administrador.');
        }
    }

    $series = [$doc['tipo'], $doc['subtipo'], $doc['letra'], $doc['ptoVenta']];
    $pdo->query("SELECT GET_LOCK('remitos_comprobantes', 10)");
    if ($doc['numero'] === '') {
        $st = $pdo->prepare(
            'SELECT COALESCE(MAX(numero_int), 0) FROM comprobantes WHERE tipo = ? AND subtipo = ? AND letra = ? AND pto_vta = ?'
        );
        $st->execute($series);
        $doc['numero'] = (string) ((int) $st->fetchColumn() + 1);
    }
    $doc['numero'] = str_pad($doc['numero'], 8, '0', STR_PAD_LEFT);
    $numberInt = (int) $doc['numero'];

    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM comprobantes
          WHERE tipo = ? AND subtipo = ? AND letra = ? AND pto_vta = ? AND numero_int = ? AND id <> ?'
    );
    $st->execute([...$series, $numberInt, $existing['id'] ?? '']);
    if ((int) $st->fetchColumn() > 0) {
        api_error(409, 'Ya existe ese comprobante con el Nº ' . $doc['ptoVenta'] . '-' . $doc['numero'] . '. Dejá el número vacío para asignar el siguiente.');
    }

    $id = $existing['id'] ?? bin2hex(random_bytes(8));
    $json = json_encode(['id' => $id] + $doc, JSON_UNESCAPED_UNICODE);
    $values = [
        $doc['tipo'], $doc['subtipo'], $doc['letra'], $doc['ptoVenta'], $doc['numero'], $numberInt, $doc['fecha'],
        $doc['cliente']['razonSocial'], $doc['cae'] ?: null, $doc['caeVto'] ?: null, $json, $now, $auth['nombre'],
    ];
    if ($existing) {
        $pdo->prepare(
            'UPDATE comprobantes SET tipo = ?, subtipo = ?, letra = ?, pto_vta = ?, numero = ?, numero_int = ?, fecha = ?,
                    cliente = ?, cae = ?, cae_vto = ?, data = ?, updated = ?, actualizado_por = ? WHERE id = ?'
        )->execute([...$values, $id]);
    } else {
        $pdo->prepare(
            'INSERT INTO comprobantes (tipo, subtipo, letra, pto_vta, numero, numero_int, fecha, cliente, cae, cae_vto,
                                       data, updated, actualizado_por, id, created, creado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([...$values, $id, $now, $auth['nombre']]);
    }
    $pdo->query("SELECT RELEASE_LOCK('remitos_comprobantes')");

    $label = ['factura' => 'Factura', 'recibo' => 'Recibo', 'nota' => 'Nota'][$doc['tipo']];
    audit_event($pdo, $label . ' ' . $doc['letra'] . ' guardada Nº ' . $doc['ptoVenta'] . '-' . $doc['numero'], [
        'categoria' => 'comprobante', 'clave' => $auth['nombre'], 'detalle' => $doc['cliente']['razonSocial'],
    ]);
    api_respond($existing ? 200 : 201, ['ok' => true, 'id' => $id, 'comprobante' => comp_public(comp_find($pdo, $id))]);
}

if ($method === 'DELETE') {
    $auth = auth_require($pdo, $config, 'borrar', 'comprobantes');
    $row = comp_find($pdo, (string) ($_GET['id'] ?? ''));
    if ($row === null) {
        api_error(404, 'Comprobante no encontrado');
    }
    if ((string) $row['cae'] !== '' && !in_array('admin', $auth['permisos'], true)) {
        api_error(409, 'Un comprobante con CAE solo lo puede eliminar un administrador. Para anularlo, emití una nota de crédito.');
    }
    $pdo->prepare('DELETE FROM comprobantes WHERE id = ?')->execute([$row['id']]);
    audit_event($pdo, 'Comprobante eliminado Nº ' . $row['pto_vta'] . '-' . $row['numero'], [
        'categoria' => 'comprobante', 'clave' => $auth['nombre'], 'detalle' => $row['cliente'],
    ]);
    api_respond(200, ['ok' => true, 'id' => $row['id']]);
}

api_error(405, 'Método no permitido');
