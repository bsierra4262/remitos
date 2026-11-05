<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Remitos compartidos.
 *
 *   GET    remitos.php?pagina=1&por_pagina=50&buscar=… (leer)  Remitos, del más reciente al más antiguo.
 *   GET    remitos.php?id=…                           (leer)   Un remito.
 *   POST   remitos.php {id?, id_local?, …}            (crear)  Alta o edición de un borrador.
 *   POST   remitos.php {accion: 'facturado', id, facturado} (crear) Marca el remito como facturado.
 *   POST   remitos.php {accion: 'conformidad', id}    (crear)  Enlace para que el destinatario confirme la
 *                                                     recepción (remito digital, RG 5678/2025).
 *   DELETE remitos.php?id=…                           (borrar) Baja de un borrador.
 *
 * Numeración (RG 1415/2003): el número fiscal (punto de venta y correlativo), el
 * C.A.I. y su vencimiento los asigna únicamente la autorización contra un
 * talonario (talonarios.php). Hasta entonces el remito es un borrador con una
 * referencia interna («Borrador 12»), que no se confunde con un número fiscal y
 * se imprime como «SIN AUTORIZAR». Los datos que envía el cliente en `numero`,
 * `cai` o `caiVto` se ignoran.
 *
 * Un remito autorizado no se modifica ni se elimina; solo admite la marca de facturado.
 *
 * Contenido (RG 1415, Anexo V): `faltantes` lista lo que le falta a un borrador para
 * poder autorizarse (condición IVA y CUIT del destinatario, bienes y cantidades,
 * transportista cuando el traslado lo hace un tercero).
 *
 * Remito digital (RG 5678/2025): un remito autorizado puede no imprimirse si se exhibe en
 * formato digital y el destinatario puede confirmar la recepción. `conformidad` genera un
 * enlace de un solo destinatario (token de 256 bits; se guarda solo su SHA-256) que se
 * abre en conformidad.html.
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
const REMITO_LOCK = 'remitos_numero';
const REMITO_CONFORMIDAD_DIAS = 60;

/**
 * Arma un remito con forma, longitudes y formatos controlados a partir del cuerpo recibido.
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

    $fecha = trim((string) (is_scalar($body['fecha'] ?? null) ? $body['fecha'] : ''));
    if ($fecha === '') {
        $fecha = date('d/m/Y');
    } elseif (api_date_dmy($fecha) === '') {
        api_error(422, 'La fecha del remito no es válida (dd/mm/aaaa)');
    }

    $cliente = $section($body['cliente'] ?? [], [
        'senores' => 160, 'razonSocial' => 160, 'cuit' => 13, 'condIVA' => 40, 'domicilio' => 160, 'localidad' => 80,
        'telefono' => 40, 'cp' => 10, 'mail' => 120, 'nombre' => 120, 'otrosDatos' => 160,
    ]);
    $cliente['cuit'] = api_require_cuit($cliente['cuit'], 'CUIT del cliente', true);
    if ($cliente['condIVA'] !== '' && !isset(REMITO_LEYENDAS_IVA[$cliente['condIVA']])) {
        $cliente['condIVA'] = '';
    }

    $transporte = $section($body['transporte'] ?? [], [
        'tipo' => 10, 'nombre' => 120, 'cuit' => 13, 'domicilio' => 160, 'vehiculo' => 120, 'chofer' => 120,
        'lugarEntrega' => 160, 'fecha' => 10, 'hora' => 5, 'observaciones' => 600,
    ]);
    if (!in_array($transporte['tipo'], REMITO_TRANSPORTES, true)) {
        $transporte['tipo'] = '';
    }
    $transporte['cuit'] = api_require_cuit($transporte['cuit'], 'CUIT del transportista');
    if ($transporte['fecha'] !== '' && api_date_dmy($transporte['fecha']) === '') {
        api_error(422, 'La fecha de entrega no es válida (dd/mm/aaaa)');
    }
    if ($transporte['hora'] !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $transporte['hora'])) {
        api_error(422, 'La hora de entrega no es válida (hh:mm)');
    }

    return [
        'fecha' => $fecha,
        'cliente' => $cliente,
        'items' => $items,
        'recibi' => $recibi,
        'transporte' => $transporte,
    ];
}

/** Remito guardado, listo para responder. Los datos fiscales salen de las columnas, no del JSON. */
function remito_public(array $row): array
{
    $doc = json_decode((string) $row['data'], true) ?: [];
    $authorized = ($row['estado'] ?? '') === 'autorizado';
    $doc['id'] = $row['id'];
    $doc['tipo'] = 'remito';
    $doc['estado'] = $authorized ? 'autorizado' : 'borrador';
    $doc['referencia'] = $row['referencia'] !== null ? (int) $row['referencia'] : null;
    $doc['punto_venta'] = $authorized ? (int) $row['punto_venta'] : null;
    $doc['numero'] = $authorized ? (string) $row['numero'] : '';
    if (!$authorized) {
        $doc['cai'] = '';
        $doc['caiVto'] = '';
    }
    $doc['facturado'] = (bool) (int) ($row['facturado'] ?? 0);
    $doc['faltantes'] = $authorized ? [] : remito_faltantes($doc);
    $doc['conformidad'] = [
        'enlace_activo' => !empty($row['conformidad_hash'])
            && strtotime((string) $row['conformidad_desde']) > time() - REMITO_CONFORMIDAD_DIAS * 86400,
        'enlace_desde' => $row['conformidad_desde'] ?? null,
        'conformado' => $row['conformado'] ?? null,
        'nombre' => $row['conformado_nombre'] ?? null,
        'documento' => $row['conformado_doc'] ?? null,
        'observaciones' => $row['conformado_obs'] ?? null,
    ];
    $doc['id_local'] = $row['id_local'];
    $doc['creado_por'] = $row['creado_por'] ?? null;
    $doc['actualizado_por'] = $row['actualizado_por'] ?? null;

    return $doc;
}

/** Nombre del remito para mensajes y auditoría. */
function remito_label(array $row): string
{
    return ($row['estado'] ?? '') === 'autorizado' ? 'Nº ' . $row['numero'] : 'borrador ' . $row['referencia'];
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
    $where = '';
    $params = [];
    $search = api_str($_GET['buscar'] ?? '', 80);
    if ($search !== '') {
        $where = 'WHERE cliente LIKE ? OR numero LIKE ? OR referencia = ?';
        $like = '%' . addcslashes($search, '%_\\') . '%';
        $params = [$like, $like, ctype_digit($search) ? (int) $search : -1];
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM remitos $where");
    $st->execute($params);
    $total = (int) $st->fetchColumn();
    $offset = ($page - 1) * $perPage;
    $st = $pdo->prepare("SELECT * FROM remitos $where ORDER BY updated DESC, id LIMIT $perPage OFFSET $offset");
    $st->execute($params);
    api_respond(200, [
        'ok' => true,
        'total' => $total,
        'pagina' => $page,
        'paginas' => max(1, (int) ceil($total / $perPage)),
        'remitos' => array_map('remito_public', $st->fetchAll()),
    ]);
}

if ($method === 'POST') {
    $auth = auth_require($pdo, $config, 'crear', 'remitos');
    $body = api_body();
    $by = $auth['nombre'];
    $now = (int) round(microtime(true) * 1000);

    if (($body['accion'] ?? '') === 'facturado') {
        $row = remito_find($pdo, (string) ($body['id'] ?? ''));
        if ($row === null) {
            api_error(404, 'Remito no encontrado');
        }
        $value = api_bool($body['facturado'] ?? true, true);
        $pdo->prepare('UPDATE remitos SET facturado = ?, updated = ?, actualizado_por = ? WHERE id = ?')
            ->execute([$value ? 1 : 0, $now, $by, $row['id']]);
        audit_event($pdo, 'Remito ' . remito_label($row) . ($value ? ' marcado como facturado' : ' marcado como pendiente de facturar'), [
            'categoria' => 'remito', 'detalle' => mb_substr((string) $row['cliente'], 0, 120),
        ]);
        api_respond(200, ['ok' => true, 'remito' => remito_public(remito_find($pdo, $row['id']))]);
    }

    if (($body['accion'] ?? '') === 'conformidad') {
        $row = remito_find($pdo, (string) ($body['id'] ?? ''));
        if ($row === null) {
            api_error(404, 'Remito no encontrado');
        }
        if ($row['estado'] !== 'autorizado') {
            api_error(409, 'Solo un remito autorizado (con número y C.A.I.) se puede enviar en formato digital');
        }
        if ($row['conformado'] !== null) {
            api_error(409, 'El destinatario ya confirmó la recepción de este remito', ['remito' => remito_public($row)]);
        }
        // Un enlace nuevo reemplaza al anterior: el viejo deja de funcionar.
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $pdo->prepare('UPDATE remitos SET conformidad_hash = ?, conformidad_desde = NOW(), updated = ? WHERE id = ?')
            ->execute([hash('sha256', $token), $now, $row['id']]);
        audit_event($pdo, 'Enlace de conformidad generado: remito ' . remito_label($row), [
            'categoria' => 'remito', 'detalle' => 'vence en ' . REMITO_CONFORMIDAD_DIAS . ' días' . (!empty($row['conformidad_hash']) ? ' · reemplaza al anterior' : ''),
        ]);
        api_respond(200, [
            'ok' => true,
            'url' => api_base_url($config) . '/conformidad.html#' . $token,
            'vence_dias' => REMITO_CONFORMIDAD_DIAS,
            'remito' => remito_public(remito_find($pdo, $row['id'])),
        ]);
    }

    $remito = remito_from_input($body);
    $requestedId = (string) ($body['id'] ?? '');
    $localId = api_str($body['id_local'] ?? '', 40);
    $localId = preg_match('/^[A-Za-z0-9_-]{4,40}$/', $localId) ? $localId : '';

    // Todo el alta ocurre bajo el bloqueo: la búsqueda por id_local (reintentos) y la
    // referencia correlativa no pueden competir con otra petición simultánea.
    api_lock($pdo, REMITO_LOCK);
    try {
        $existing = null;
        if ($requestedId !== '') {
            $existing = remito_find($pdo, $requestedId);
            if ($existing === null) {
                api_error(404, 'Remito no encontrado');
            }
        } elseif ($localId !== '') {
            $st = $pdo->prepare('SELECT * FROM remitos WHERE id_local = ?');
            $st->execute([$localId]);
            $existing = $st->fetch() ?: null;
        }
        if ($existing !== null && $existing['estado'] === 'autorizado') {
            api_error(409, 'El remito ' . remito_label($existing) . ' ya está autorizado y no se puede modificar.', [
                'remito' => remito_public($existing),
            ]);
        }

        $id = $existing['id'] ?? bin2hex(random_bytes(8));
        $previous = $existing ? (json_decode((string) $existing['data'], true) ?: []) : [];
        $reference = $existing
            ? (int) $existing['referencia']
            : (int) $pdo->query('SELECT COALESCE(MAX(referencia), 0) + 1 FROM remitos')->fetchColumn();
        $created = isset($existing['created']) ? (int) $existing['created'] : $now;
        $origin = $existing['origen'] ?? (isset($auth['usuario']) ? 'app' : 'api');
        $document = ['id' => $id, 'tipo' => 'remito', 'numero' => '', 'cai' => '', 'caiVto' => '', 'referencia' => $reference]
            + $remito
            + ['estado' => 'borrador', 'origen' => $origin, 'created' => $created, 'updated' => $now];
        $client = $remito['cliente']['razonSocial'] ?: $remito['cliente']['senores'];
        $json = json_encode($document, JSON_UNESCAPED_UNICODE);

        if ($existing) {
            $pdo->prepare(
                'UPDATE remitos SET fecha = ?, fecha_emision = ?, cliente = ?, data = ?, updated = ?, actualizado_por = ? WHERE id = ?'
            )->execute([$remito['fecha'], api_date_iso($remito['fecha']), $client, $json, $now, $by, $id]);
            $changed = array_keys(array_filter(
                ['cliente' => 'cliente', 'fecha' => 'fecha', 'items' => 'ítems', 'recibi' => 'recepción', 'transporte' => 'transporte'],
                static fn (string $label, string $key): bool => ($previous[$key] ?? null) != $remito[$key],
                ARRAY_FILTER_USE_BOTH
            ));
            if ($changed !== []) {
                audit_event($pdo, 'Remito modificado (borrador ' . $reference . ')', [
                    'categoria' => 'remito', 'detalle' => 'cambios en: ' . implode(', ', $changed),
                ]);
            }
        } else {
            $pdo->prepare(
                'INSERT INTO remitos (id, id_local, referencia, numero, numero_int, punto_venta, fecha, fecha_emision, cliente, data,
                                      origen, estado, created, updated, creado_por, actualizado_por)
                 VALUES (?, ?, ?, NULL, NULL, NULL, ?, ?, ?, ?, ?, \'borrador\', ?, ?, ?, ?)'
            )->execute([
                $id, $localId ?: null, $reference, $remito['fecha'], api_date_iso($remito['fecha']), $client, $json, $origin,
                $now, $now, $by, $by,
            ]);
            audit_event($pdo, 'Remito guardado (borrador ' . $reference . ')', [
                'categoria' => 'remito', 'detalle' => mb_substr($client, 0, 120),
            ]);
        }
    } finally {
        api_unlock($pdo, REMITO_LOCK);
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
    auth_require($pdo, $config, 'borrar', 'remitos');
    $id = (string) ($_GET['id'] ?? '');
    if (!preg_match('/^[0-9a-f]{16}$/', $id)) {
        api_error(400, 'Identificador de remito inválido');
    }
    $row = remito_find($pdo, $id);
    if ($row === null) {
        api_error(404, 'Remito no encontrado');
    }
    if ($row['estado'] === 'autorizado') {
        api_error(409, 'Un remito autorizado no se elimina: su número y C.A.I. ya fueron asignados.');
    }
    $pdo->prepare('DELETE FROM remitos WHERE id = ?')->execute([$id]);
    audit_event($pdo, 'Remito eliminado (' . remito_label($row) . ')', [
        'categoria' => 'remito', 'detalle' => mb_substr((string) $row['cliente'], 0, 120),
    ]);
    api_respond(200, ['ok' => true, 'id' => $id]);
}

api_error(405, 'Método no permitido');
