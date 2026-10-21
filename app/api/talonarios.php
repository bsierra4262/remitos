<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Talonarios de C.A.I. y autorización de remitos.
 *
 * Un talonario es el rango de numeración que ARCA autoriza en cada solicitud de
 * C.A.I. (RG 1415/2003, Anexo II, ap. A, V.4): punto de venta, tipo de
 * comprobante, número desde/hasta, código de 14 dígitos y vencimiento. ARCA no
 * ofrece web service para el C.A.I.: se tramita con Clave Fiscal y se carga acá
 * una vez por rango. A partir de ahí cada remito toma su número correlativo.
 *
 *   GET    talonarios.php?accion=listar               (admin) Talonarios y uso.
 *   GET    talonarios.php?accion=vigente&pv=1&tipo=91 (leer)  Talonario activo, sin consumir número.
 *   POST   talonarios.php {punto_venta, numero_desde, numero_hasta, cai, cai_vencimiento}  (admin) Alta.
 *   POST   talonarios.php {accion: 'autorizar', pv, tipo, fecha, confirmar?}             (crear) Asigna número.
 *   POST   talonarios.php {id, activa}                (admin) Activa o desactiva.
 *   DELETE talonarios.php?id=N                        (admin) Elimina.
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
        'restantes' => $remaining,
        'dias_para_vto' => $days,
        'vencido' => $days !== null && $days < 0,
        'por_vencer' => $days !== null && $days >= 0 && $days <= AVISO_DIAS_VTO,
        'pocos_numeros' => $remaining <= AVISO_RESTANTES,
        'agotado' => $remaining <= 0,
    ];
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
    // Cada número consumido de un talonario es un remito autorizado.
    $authorized = (int) $pdo->query(
        'SELECT COALESCE(SUM(proximo_numero - numero_desde), 0) FROM cai_talonarios'
    )->fetchColumn();

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
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !DateTimeImmutable::createFromFormat('!Y-m-d', $date)) {
            $date = date('Y-m-d');
        }
        $confirm = !empty($body['confirmar']);

        // Bloqueo de fila: dos pedidos simultáneos nunca reciben el mismo número.
        $pdo->beginTransaction();
        try {
            $t = talonario_vigente($pdo, $pointOfSale, $type, true);
            if ($t === null) {
                $pdo->rollBack();
                api_error(409, 'No hay un talonario activo con números disponibles. Cargá el próximo C.A.I.');
            }
            if ($date > $t['cai_vencimiento'] && !$confirm) {
                $pdo->rollBack();
                api_error(
                    409,
                    'El C.A.I. venció el ' . date('d/m/Y', (int) strtotime($t['cai_vencimiento']))
                        . '. Un comprobante emitido con C.A.I. vencido no es válido.',
                    ['vencido' => true]
                );
            }
            $number = (int) $t['proximo_numero'];
            $pdo->prepare('UPDATE cai_talonarios SET proximo_numero = proximo_numero + 1 WHERE id = ?')
                ->execute([$t['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        audit_event($pdo, 'Remito autorizado Nº ' . fmt_numero($pointOfSale, $number), [
            'categoria' => 'remito',
            'clave' => $auth['nombre'],
            'detalle' => 'C.A.I. ' . $t['cai'] . ' · vence ' . $t['cai_vencimiento'] . ($confirm ? ' · confirmado vencido' : ''),
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
        ]);
    }

    $auth = auth_require($pdo, $config, 'admin', 'talonarios');

    if (isset($body['id'], $body['activa'])) {
        $id = (int) $body['id'];
        $active = !empty($body['activa']);
        $st = $pdo->prepare('SELECT id FROM cai_talonarios WHERE id = ?');
        $st->execute([$id]);
        if ($st->fetchColumn() === false) {
            api_error(404, 'No existe ese talonario');
        }
        $pdo->prepare('UPDATE cai_talonarios SET activa = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
        audit_event($pdo, $active ? 'Talonario activado' : 'Talonario desactivado', [
            'categoria' => 'cai', 'clave' => $auth['nombre'],
        ]);
        api_respond(200, ['ok' => true, 'id' => $id, 'activa' => $active]);
    }

    $pointOfSale = (int) ($body['punto_venta'] ?? 0);
    $from = (int) ($body['numero_desde'] ?? 0);
    $to = (int) ($body['numero_hasta'] ?? 0);
    $cai = preg_replace('/\D/', '', (string) ($body['cai'] ?? ''));
    $expiry = (string) ($body['cai_vencimiento'] ?? '');
    $type = (int) ($body['tipo_comprobante'] ?? TIPO_REMITO);

    // ARCA no ofrece un servicio para validar el C.A.I.: se controla la coherencia de los datos.
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
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry) || !DateTimeImmutable::createFromFormat('!Y-m-d', $expiry)) {
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

    $pdo->prepare(
        'INSERT INTO cai_talonarios
            (punto_venta, tipo_comprobante, numero_desde, numero_hasta, proximo_numero, cai, cai_vencimiento, activa, nota, creado)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())'
    )->execute([$pointOfSale, $type, $from, $to, $from, $cai, $expiry, api_str($body['nota'] ?? '', 160) ?: null]);
    $newId = (int) $pdo->lastInsertId();

    audit_event($pdo, 'C.A.I. cargado · PV ' . str_pad((string) $pointOfSale, 5, '0', STR_PAD_LEFT), [
        'categoria' => 'cai',
        'clave' => $auth['nombre'],
        'detalle' => 'rango ' . fmt_numero($pointOfSale, $from) . ' a ' . fmt_numero($pointOfSale, $to) . ' · vence ' . $expiry,
    ]);
    api_respond(201, [
        'ok' => true,
        'id' => $newId,
        'numero_desde' => fmt_numero($pointOfSale, $from),
        'numero_hasta' => fmt_numero($pointOfSale, $to),
    ]);
}

if ($method === 'DELETE') {
    $auth = auth_require($pdo, $config, 'admin', 'talonarios');
    $id = (int) ($_GET['id'] ?? 0);
    $st = $pdo->prepare('SELECT numero_desde, proximo_numero, activa FROM cai_talonarios WHERE id = ?');
    $st->execute([$id]);
    $t = $st->fetch();
    if (!$t) {
        api_error(404, 'No existe ese talonario');
    }
    // Un talonario activo que ya emitió no se borra: primero se desactiva, para no perderlo de vista.
    if ((int) $t['activa'] && (int) $t['proximo_numero'] > (int) $t['numero_desde']) {
        api_error(409, 'Este talonario está activo y ya emitió comprobantes. Desactivalo primero.');
    }
    $pdo->prepare('DELETE FROM cai_talonarios WHERE id = ?')->execute([$id]);
    audit_event($pdo, 'Talonario eliminado', ['categoria' => 'cai', 'clave' => $auth['nombre']]);
    api_respond(200, ['ok' => true, 'id' => $id]);
}

api_error(405, 'Método no permitido');
