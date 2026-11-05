<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Talonarios de C.A.I. y autorización de remitos.
 *
 * Un talonario es el rango de numeración que ARCA autoriza en cada solicitud de
 * C.A.I. (RG 1415/2003, Anexo II, ap. A, V.4): punto de venta, tipo de
 * comprobante, número desde/hasta, código de 14 dígitos y vencimiento. ARCA no
 * ofrece web service para solicitar el C.A.I.: se tramita con Clave Fiscal en el
 * servicio «Autorización de Impresión de Comprobantes» y se carga acá una vez por
 * rango. A partir de ahí cada remito toma su número correlativo.
 *
 * Lo que sí ofrece ARCA es la constatación (WSCDC): al cargar el talonario se verifica
 * que el C.A.I. corresponda al CUIT del emisor, al punto de venta, al tipo de
 * comprobante, al primer y al último número del rango y a la fecha. Se usa el
 * certificado de producción (los C.A.I. reales solo existen ahí), con el servicio
 * «wscdc» asociado en ARCA.
 *
 *   GET    talonarios.php?accion=listar               (admin) Talonarios y uso.
 *   GET    talonarios.php?accion=vigente&pv=1&tipo=91 (leer)  Talonario activo, sin consumir número.
 *   POST   talonarios.php {punto_venta, numero_desde, numero_hasta, cai, cai_vencimiento, sin_verificar?}
 *                                                     (admin) Alta, constatada con ARCA.
 *   POST   talonarios.php {accion: 'verificar', id}   (admin) Vuelve a constatar un talonario con ARCA.
 *   POST   talonarios.php {accion: 'autorizar', remito_id, pv, tipo, fecha}  (crear) Asigna número.
 *   POST   talonarios.php {id, activa}                (admin) Activa o desactiva.
 *   DELETE talonarios.php?id=N                        (admin) Elimina.
 *
 * Autorizar exige que el remito tenga los datos del Anexo V de la RG 1415 (ver
 * lib/normativa.php) y nunca usa un C.A.I. vencido: un comprobante emitido con C.A.I.
 * vencido no es válido.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$config = require __DIR__ . '/bootstrap.php';
api_headers('GET, POST, DELETE, OPTIONS');
$pdo = api_db($config);

/** Remito R (tabla de comprobantes de ARCA). */
const TIPO_REMITO = 91;

/** Tipos de comprobante admitidos en un talonario (tabla de comprobantes de ARCA). */
const TIPOS_CAI = [1, 2, 3, 4, 6, 7, 8, 9, 11, 12, 13, 15, 91];

/** Avisar cuando queden pocos números en el talonario. */
const AVISO_RESTANTES = 20;

/** Avisar cuando falten pocos días para el vencimiento del C.A.I. */
const AVISO_DIAS_VTO = 30;

/** Número completo: 00001-00000001 (RG 1415: 5 dígitos de punto de venta + 8 de número). */
function fmt_numero(int $pointOfSale, int $number): string
{
    return str_pad((string) $pointOfSale, 5, '0', STR_PAD_LEFT) . '-' . str_pad((string) $number, 8, '0', STR_PAD_LEFT);
}

/**
 * Talonario con su estado calculado (restantes, vencimiento y alertas).
 *
 * @return array<string, mixed>
 */
function talonario_public(array $t): array
{
    $remaining = max(0, (int) $t['numero_hasta'] - (int) $t['proximo_numero'] + 1);
    $expiry = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $t['cai_vencimiento']);
    $days = $expiry ? (int) (new DateTimeImmutable('today'))->diff($expiry)->format('%r%a') : null;

    return [
        'id' => (int) $t['id'],
        'punto_venta' => (int) $t['punto_venta'],
        'tipo_comprobante' => (int) $t['tipo_comprobante'],
        'numero_desde' => (int) $t['numero_desde'],
        'numero_hasta' => (int) $t['numero_hasta'],
        'proximo_numero' => (int) $t['proximo_numero'],
        'proximo_formato' => fmt_numero((int) $t['punto_venta'], (int) $t['proximo_numero']),
        'cai' => $t['cai'],
        'cai_vencimiento' => $t['cai_vencimiento'],
        'activa' => (bool) (int) $t['activa'],
        'nota' => $t['nota'],
        'verificado_arca' => $t['verificado_arca'] ?? null,
        'verificacion' => $t['verificacion'] ?? null,
        'restantes' => $remaining,
        'dias_para_vto' => $days,
        'vencido' => $days !== null && $days < 0,
        'por_vencer' => $days !== null && $days >= 0 && $days <= AVISO_DIAS_VTO,
        'pocos_numeros' => $remaining <= AVISO_RESTANTES,
        'agotado' => $remaining <= 0,
    ];
}

/**
 * Constata el talonario en ARCA (WSCDC) con el primer y el último número del rango.
 *
 * @return array{estado: string, detalle: string, mensajes: string[]}
 *         estado: verificado | rechazado | sin_credenciales | error
 */
function talonario_constatar(PDO $pdo, array $config, int $pointOfSale, int $type, int $from, int $to, string $cai): array
{
    $credential = arca_credentials_status($pdo, 'produccion');
    if ($credential === null || $credential['vencido']) {
        return [
            'estado' => 'sin_credenciales',
            'detalle' => 'Sin verificar: no hay un certificado vigente de producción para constatar el C.A.I. en ARCA',
            'mensajes' => [],
        ];
    }
    $cuit = preg_replace('/\D/', '', (string) (settings_get($pdo, 'emisor')['cuit'] ?? '')) ?: $credential['cuit'];
    $today = date('Ymd');
    try {
        $mensajes = [];
        foreach (array_unique([$from, $to]) as $number) {
            $r = arca_cai_constatar($pdo, $config, 'produccion', $cuit, $pointOfSale, $type, $number, $today, $cai);
            if ($r['resultado'] !== 'A') {
                $mensajes = array_merge($mensajes, $r['errores'], $r['observaciones']);
                $mensajes = $mensajes ?: ['ARCA no aprobó el número ' . $number];
            }
        }
    } catch (ArcaException $e) {
        return ['estado' => 'error', 'detalle' => $e->getMessage(), 'mensajes' => []];
    }
    if ($mensajes !== []) {
        return [
            'estado' => 'rechazado',
            'detalle' => 'ARCA no reconoce el C.A.I. para este rango: ' . implode(' · ', array_unique($mensajes)),
            'mensajes' => array_values(array_unique($mensajes)),
        ];
    }

    return ['estado' => 'verificado', 'detalle' => 'Constatado con ARCA (WSCDC) para el CUIT ' . $cuit, 'mensajes' => []];
}

/** ¿Es una fecha aaaa-mm-dd válida en el calendario? */
function talonario_iso_valid(string $date): bool
{
    return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/** Talonario activo con números disponibles para un punto de venta y tipo. */
function talonario_vigente(PDO $pdo, int $pointOfSale, int $type, bool $lock = false): ?array
{
    $st = $pdo->prepare(
        'SELECT * FROM cai_talonarios
          WHERE punto_venta = ? AND tipo_comprobante = ? AND activa = 1 AND proximo_numero <= numero_hasta
          ORDER BY numero_desde ASC LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
    );
    $st->execute([$pointOfSale, $type]);

    return $st->fetch() ?: null;
}

$method = api_method();

if ($method === 'GET') {
    if (($_GET['accion'] ?? 'listar') === 'vigente') {
        auth_require($pdo, $config, 'leer', 'talonarios');
        $t = talonario_vigente($pdo, (int) ($_GET['pv'] ?? 1), (int) ($_GET['tipo'] ?? TIPO_REMITO));
        if ($t === null) {
            api_respond(200, [
                'ok' => true,
                'hay' => false,
                'mensaje' => 'No hay un talonario activo con números disponibles para ese punto de venta',
            ]);
        }
        api_respond(200, ['ok' => true, 'hay' => true, 'talonario' => talonario_public($t)]);
    }

    auth_require($pdo, $config, 'admin', 'talonarios');
    $list = array_map(
        'talonario_public',
        $pdo->query('SELECT * FROM cai_talonarios ORDER BY activa DESC, punto_venta, numero_desde')->fetchAll()
    );
    $authorized = (int) $pdo->query("SELECT COUNT(*) FROM remitos WHERE estado = 'autorizado'")->fetchColumn();

    api_respond(200, [
        'ok' => true,
        'talonarios' => $list,
        'resumen' => [
            'total' => count($list),
            'activos' => count(array_filter($list, static fn (array $x): bool => $x['activa'])),
            'por_vencer' => count(array_filter($list, static fn (array $x): bool => $x['por_vencer'])),
            'vencidos' => count(array_filter($list, static fn (array $x): bool => $x['vencido'])),
            'remitos_autorizados' => $authorized,
        ],
    ]);
}

if ($method === 'POST') {
    $body = api_body();

    if (($body['accion'] ?? '') === 'autorizar') {
        $auth = auth_require($pdo, $config, 'crear', 'talonarios');
        $pointOfSale = (int) ($body['pv'] ?? 1);
        $type = (int) ($body['tipo'] ?? TIPO_REMITO);
        $date = (string) ($body['fecha'] ?? '');
        if (!talonario_iso_valid($date)) {
            $date = date('Y-m-d');
        }
        $remitoId = (string) ($body['remito_id'] ?? '');
        // Cada número se asigna a un remito concreto: nunca se consumen números sueltos.
        if (!preg_match('/^[0-9a-f]{16}$/', $remitoId)) {
            api_error(400, 'Indicá el remito a autorizar (remito_id). Guardalo antes de autorizarlo.');
        }

        // Bloqueo de filas: dos pedidos simultáneos nunca reciben el mismo número, y el
        // número queda escrito en el remito dentro de la misma transacción. La clave única
        // (punto_venta, numero_int) de la tabla es la última barrera.
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT * FROM remitos WHERE id = ? FOR UPDATE');
            $st->execute([$remitoId]);
            $remito = $st->fetch() ?: null;
            if ($remito === null) {
                api_error(404, 'Remito no encontrado. Guardalo antes de autorizarlo.');
            }
            if (($remito['estado'] ?? '') === 'autorizado') {
                api_error(409, 'Este remito ya está autorizado con el Nº ' . $remito['numero']);
            }
            $faltan = remito_faltantes(json_decode((string) $remito['data'], true) ?: []);
            if ($faltan !== []) {
                api_error(422, 'Para autorizar el remito falta: ' . implode(', ', $faltan) . ' (RG 1415, Anexo V).', [
                    'faltan' => $faltan,
                ]);
            }
            $t = talonario_vigente($pdo, $pointOfSale, $type, true);
            if ($t === null) {
                api_error(409, 'No hay un talonario activo con números disponibles. Cargá el próximo C.A.I.');
            }
            if ($date > $t['cai_vencimiento']) {
                api_error(
                    409,
                    'El C.A.I. venció el ' . date('d/m/Y', (int) strtotime($t['cai_vencimiento']))
                        . '. Un comprobante emitido con C.A.I. vencido no es válido: cargá el talonario nuevo.',
                    ['vencido' => true]
                );
            }
            $number = (int) $t['proximo_numero'];
            $pdo->prepare('UPDATE cai_talonarios SET proximo_numero = proximo_numero + 1 WHERE id = ?')
                ->execute([$t['id']]);
            $doc = json_decode((string) $remito['data'], true) ?: [];
            $doc['numero'] = fmt_numero($pointOfSale, $number);
            $doc['estado'] = 'autorizado';
            $doc['cai'] = $t['cai'];
            $doc['caiVto'] = date('d/m/Y', (int) strtotime($t['cai_vencimiento']));
            $doc['fecha'] = date('d/m/Y', (int) strtotime($date));
            $doc['updated'] = (int) round(microtime(true) * 1000);
            $pdo->prepare(
                "UPDATE remitos SET numero = ?, numero_int = ?, punto_venta = ?, estado = 'autorizado', data = ?,
                        fecha = ?, fecha_emision = ?, updated = ?, actualizado_por = ? WHERE id = ?"
            )->execute([
                $doc['numero'], $number, $pointOfSale, json_encode($doc, JSON_UNESCAPED_UNICODE),
                $doc['fecha'], $date, $doc['updated'], $auth['nombre'], $remitoId,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        audit_event($pdo, 'Remito autorizado Nº ' . fmt_numero($pointOfSale, $number) . ' (borrador ' . $remito['referencia'] . ')', [
            'categoria' => 'remito',
            'detalle' => 'C.A.I. ' . $t['cai'] . ' · vence ' . $t['cai_vencimiento'] . ($t['verificado_arca'] ? '' : ' · talonario sin constatar con ARCA'),
        ]);
        api_respond(200, [
            'ok' => true,
            'estado' => 'autorizado',
            'punto_venta' => $pointOfSale,
            'numero' => $number,
            'numero_formato' => fmt_numero($pointOfSale, $number),
            'cai' => $t['cai'],
            'cai_vencimiento' => $t['cai_vencimiento'],
            'restantes' => max(0, (int) $t['numero_hasta'] - $number),
            'remito_id' => $remitoId,
        ]);
    }

    $auth = auth_require($pdo, $config, 'admin', 'talonarios');

    if (isset($body['id'], $body['activa'])) {
        $id = (int) $body['id'];
        $active = api_bool($body['activa']);
        $st = $pdo->prepare('SELECT id FROM cai_talonarios WHERE id = ?');
        $st->execute([$id]);
        if ($st->fetchColumn() === false) {
            api_error(404, 'No existe ese talonario');
        }
        $pdo->prepare('UPDATE cai_talonarios SET activa = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
        audit_event($pdo, ($active ? 'Talonario activado' : 'Talonario desactivado') . ' #' . $id, ['categoria' => 'cai']);
        api_respond(200, ['ok' => true, 'id' => $id, 'activa' => $active]);
    }

    $pointOfSale = (int) ($body['punto_venta'] ?? 0);
    $from = (int) ($body['numero_desde'] ?? 0);
    $to = (int) ($body['numero_hasta'] ?? 0);
    $cai = preg_replace('/\D/', '', (string) ($body['cai'] ?? ''));
    $expiry = (string) ($body['cai_vencimiento'] ?? '');
    $type = (int) ($body['tipo_comprobante'] ?? TIPO_REMITO);

    if (($body['accion'] ?? '') === 'verificar') {
        $st = $pdo->prepare('SELECT * FROM cai_talonarios WHERE id = ?');
        $st->execute([(int) ($body['id'] ?? 0)]);
        $t = $st->fetch();
        if (!$t) {
            api_error(404, 'No existe ese talonario');
        }
        $v = talonario_constatar($pdo, $config, (int) $t['punto_venta'], (int) $t['tipo_comprobante'], (int) $t['numero_desde'], (int) $t['numero_hasta'], (string) $t['cai']);
        if ($v['estado'] === 'verificado' || $v['estado'] === 'rechazado') {
            $pdo->prepare('UPDATE cai_talonarios SET verificado_arca = ?, verificacion = ? WHERE id = ?')
                ->execute([$v['estado'] === 'verificado' ? date('Y-m-d H:i:s') : null, mb_substr($v['detalle'], 0, 255), $t['id']]);
        }
        audit_event($pdo, 'Talonario #' . $t['id'] . ($v['estado'] === 'verificado' ? ' constatado con ARCA' : ' sin constatar'), [
            'categoria' => 'cai', 'nivel' => $v['estado'] === 'verificado' ? 'info' : 'error', 'detalle' => $v['detalle'],
        ]);
        $st->execute([$t['id']]);
        api_respond(200, ['ok' => $v['estado'] === 'verificado', 'verificacion' => $v, 'talonario' => talonario_public($st->fetch())]);
    }

    // Primero la coherencia de los datos; después la constatación con ARCA (WSCDC).
    if (!in_array($type, TIPOS_CAI, true)) {
        api_error(400, 'Tipo de comprobante inválido (91 para remitos R)');
    }
    if ($pointOfSale < 1 || $pointOfSale > 99998) {
        api_error(400, 'Punto de venta inválido (1 a 99998)');
    }
    if ($from < 1 || $from > 99999999) {
        api_error(400, 'El número inicial debe estar entre 1 y 99999999');
    }
    if ($to <= $from || $to > 99999999) {
        api_error(400, 'El número final debe ser mayor que el inicial y no superar 99999999');
    }
    if (strlen($cai) !== 14) {
        api_error(400, 'El C.A.I. debe tener 14 dígitos');
    }
    if (!talonario_iso_valid($expiry)) {
        api_error(400, 'Fecha de vencimiento inválida');
    }
    if ($expiry < date('Y-m-d')) {
        api_error(400, 'El C.A.I. ya está vencido a la fecha de hoy');
    }
    $overlap = $pdo->prepare(
        'SELECT COUNT(*) FROM cai_talonarios
          WHERE punto_venta = ? AND tipo_comprobante = ? AND numero_desde <= ? AND numero_hasta >= ?'
    );
    $overlap->execute([$pointOfSale, $type, $to, $from]);
    if ((int) $overlap->fetchColumn() > 0) {
        api_error(409, 'El rango se superpone con otro talonario del mismo punto de venta');
    }

    $v = talonario_constatar($pdo, $config, $pointOfSale, $type, $from, $to, $cai);
    if ($v['estado'] === 'rechazado') {
        audit_event($pdo, 'C.A.I. rechazado por ARCA · PV ' . str_pad((string) $pointOfSale, 5, '0', STR_PAD_LEFT), [
            'categoria' => 'cai', 'nivel' => 'error', 'detalle' => mb_substr($v['detalle'], 0, 255), 'estado' => 422,
        ]);
        api_error(422, $v['detalle'] . '. Revisá el C.A.I., el punto de venta y el rango contra el comprobante de ARCA.', [
            'verificacion' => $v,
        ]);
    }
    if ($v['estado'] === 'error' && !api_bool($body['sin_verificar'] ?? false)) {
        api_error(502, 'No se pudo constatar el C.A.I. con ARCA: ' . $v['detalle'], [
            'verificacion' => $v, 'puede_cargar_sin_verificar' => true,
        ]);
    }

    $pdo->prepare(
        'INSERT INTO cai_talonarios
            (punto_venta, tipo_comprobante, numero_desde, numero_hasta, proximo_numero, cai, cai_vencimiento, activa, nota, creado,
             verificado_arca, verificacion)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), ?, ?)'
    )->execute([
        $pointOfSale, $type, $from, $to, $from, $cai, $expiry, api_str($body['nota'] ?? '', 160) ?: null,
        $v['estado'] === 'verificado' ? date('Y-m-d H:i:s') : null, mb_substr($v['detalle'], 0, 255),
    ]);
    $newId = (int) $pdo->lastInsertId();

    audit_event($pdo, 'C.A.I. cargado · PV ' . str_pad((string) $pointOfSale, 5, '0', STR_PAD_LEFT), [
        'categoria' => 'cai',
        'nivel' => $v['estado'] === 'verificado' ? 'info' : 'error',
        'detalle' => 'rango ' . fmt_numero($pointOfSale, $from) . ' a ' . fmt_numero($pointOfSale, $to) . ' · vence ' . $expiry
            . ' · ' . ($v['estado'] === 'verificado' ? 'constatado con ARCA' : 'sin constatar'),
    ]);
    api_respond(201, [
        'ok' => true,
        'id' => $newId,
        'numero_desde' => fmt_numero($pointOfSale, $from),
        'numero_hasta' => fmt_numero($pointOfSale, $to),
        'verificacion' => $v,
    ]);
}

if ($method === 'DELETE') {
    $auth = auth_require($pdo, $config, 'admin', 'talonarios');
    $id = (int) ($_GET['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM cai_talonarios WHERE id = ?');
    $st->execute([$id]);
    $t = $st->fetch();
    if (!$t) {
        api_error(404, 'No existe ese talonario');
    }
    // Un talonario con remitos autorizados es respaldo de esos comprobantes: no se borra
    // (se desactiva). Solo se elimina uno cargado por error y nunca usado.
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM remitos WHERE estado = 'autorizado' AND punto_venta = ? AND numero_int BETWEEN ? AND ?"
    );
    $st->execute([$t['punto_venta'], $t['numero_desde'], $t['numero_hasta']]);
    if ((int) $st->fetchColumn() > 0 || (int) $t['proximo_numero'] > (int) $t['numero_desde']) {
        api_error(409, 'Este talonario ya se usó para autorizar remitos y no se puede eliminar. Podés desactivarlo.');
    }
    $pdo->prepare('DELETE FROM cai_talonarios WHERE id = ?')->execute([$id]);
    audit_event($pdo, 'Talonario eliminado #' . $id, [
        'categoria' => 'cai',
        'detalle' => 'C.A.I. ' . $t['cai'] . ' · rango ' . fmt_numero((int) $t['punto_venta'], (int) $t['numero_desde'])
            . ' a ' . fmt_numero((int) $t['punto_venta'], (int) $t['numero_hasta']),
    ]);
    api_respond(200, ['ok' => true, 'id' => $id]);
}

api_error(405, 'Método no permitido');
