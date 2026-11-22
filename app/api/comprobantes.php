<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Facturas, recibos y notas de crédito y débito compartidos, y su emisión electrónica.
 *
 * Qué emite Intellihelp (Responsable Inscripto que factura sus operaciones):
 *  - Facturas y notas de crédito y débito A o B, con CAE (RG 1415 y RG 4291).
 *  - Factura de Crédito Electrónica MiPyME y sus notas (Ley 27.440), si la empresa es
 *    MiPyME: al emitir una factura a un CUIT se consulta a ARCA (wsfecred) si el cliente
 *    está obligado a recibirla y desde qué importe; si lo está, sale como Factura de
 *    Crédito (201/206) con el CBU y la opción de transferencia. Las notas siguen al
 *    comprobante que ajustan.
 *  - Recibos de cobranza (RG 1415, art. 10): respaldan el pago de una factura ya emitida,
 *    no valen como factura y no llevan CAE; son siempre letra X. Los recibos A/B con CAE
 *    (tipos 4 y 9) los usa solo quien eligió documentar sus servicios con recibos en lugar
 *    de facturas (Anexo IV, apartado B, punto 9: «deberán optar»): emitirlos además de la factura
 *    informaría dos veces la venta.
 *
 *   GET    comprobantes.php?pagina=1&por_pagina=50&buscar=… (leer)  Del más reciente al más antiguo.
 *   GET    comprobantes.php?id=…                      (leer)   Un comprobante.
 *   POST   comprobantes.php {id?, id_local?, tipo, letra, …}  (crear) Alta o edición de un borrador.
 *   POST   comprobantes.php {accion: 'emitir', id}    (crear)  Solicita el CAE a ARCA (WSFEv1).
 *   POST   comprobantes.php {accion: 'registrar_cae', id, numero, cae, caeVto} (admin)
 *                                                              Registra un CAE obtenido fuera del sistema.
 *   DELETE comprobantes.php?id=…                      (borrar) Baja de un borrador (con CAE, solo un administrador).
 *
 * Numeración y CAE:
 *  - Los comprobantes A y B no tienen número hasta que ARCA los autoriza: el número es el
 *    último autorizado + 1 y se toma bajo un bloqueo por ambiente, punto de venta y tipo.
 *    Hasta entonces se imprimen «SIN AUTORIZAR». Sin emisión electrónica, un A o B solo
 *    recibe número si un administrador registra el CAE obtenido fuera del sistema.
 *  - La letra X (documento interno, como el recibo de cobranza) lleva el correlativo de
 *    su serie (tipo, letra y punto de venta), o el que se indique si está libre.
 *  - Al reservar el número se guardan el tipo de ARCA y el ambiente: un reintento tras
 *    un corte consulta a ARCA con esos mismos datos (FECompConsultar) antes de pedir otro.
 *  - Un comprobante autorizado o con una emisión en curso no se modifica ni se elimina.
 *  - El CAE nunca se toma del cliente: lo asigna la emisión o lo registra un administrador.
 *  - Un comprobante autorizado no se modifica.
 *
 * Los importes se recalculan siempre en el servidor (lib/importes.php).
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$config = require __DIR__ . '/bootstrap.php';
api_headers('GET, POST, DELETE, OPTIONS');
$pdo = api_db($config);

const COMP_TYPES = ['factura', 'recibo', 'nota'];
/** Letras admitidas: Intellihelp es Responsable Inscripto (no emite C); X es interno, sin valor fiscal. */
const COMP_LETTERS = ['A', 'B', 'X'];
const COMP_MAX_ITEMS = 60;
const COMP_LOCK = 'remitos_comprobantes';

/**
 * Desde este importe (en centavos) una factura B a consumidor final tiene que identificar
 * al comprador con su documento: ARCA rechaza el comprobante (observación 10015) si no.
 */
const COMP_IDENTIFICAR_CF_DESDE = 1000000000;

/** Concepto de WSFEv1: 1 productos, 2 servicios, 3 productos y servicios. */
const COMP_CONCEPTOS = [1, 2, 3];

/**
 * Comprobante con forma, longitudes y formatos controlados.
 *
 * @return array<string, mixed>
 */
function comp_from_input(array $body): array
{
    $str = static fn (mixed $v, int $max): string => api_str($v, $max);
    $date = static function (mixed $v, string $label, bool $required) use ($str): string {
        $text = $str($v, 10);
        if ($text === '') {
            return $required ? date('d/m/Y') : '';
        }
        if (api_date_dmy($text) === '') {
            api_error(422, 'La ' . $label . ' no es válida (dd/mm/aaaa)');
        }

        return $text;
    };
    $type = in_array($body['tipo'] ?? '', COMP_TYPES, true) ? $body['tipo'] : 'factura';
    $letter = strtoupper((string) ($body['letra'] ?? 'X'));
    if ($letter === 'C') {
        api_error(422, 'Intellihelp es Responsable Inscripto: emite comprobantes A o B (la C es de monotributistas y exentos)');
    }
    if ($type === 'recibo' && in_array($letter, ['A', 'B'], true)) {
        api_error(422, 'El recibo de cobranza respalda el pago de una factura ya emitida: no vale como factura ni lleva'
            . ' CAE, y es letra X (RG 1415, art. 10)');
    }
    $client = is_array($body['cliente'] ?? null) ? $body['cliente'] : [];
    $assoc = is_array($body['compAsoc'] ?? null) ? $body['compAsoc'] : [];

    $items = [];
    foreach (array_slice(is_array($body['items'] ?? null) ? $body['items'] : [], 0, COMP_MAX_ITEMS) as $item) {
        if (is_array($item)) {
            $row = [
                'descripcion' => $str($item['descripcion'] ?? '', 300),
                'cantidad' => $str($item['cantidad'] ?? '', 12),
                'precio' => $str($item['precio'] ?? '', 16),
            ];
            if (implode('', $row) !== '') {
                $items[] = $row;
            }
        }
    }

    // «00001-00000012» → «00000012»: el punto de venta va en su propio campo.
    $parts = explode('-', $str($body['numero'] ?? '', 20));
    $number = preg_replace('/\D/', '', (string) end($parts));
    if (strlen(ltrim($number, '0')) > 8) {
        api_error(422, 'El número del comprobante tiene más de 8 dígitos');
    }
    if ($number !== '' && (int) $number < 1) {
        api_error(422, 'El número del comprobante tiene que ser mayor que cero');
    }
    $condIva = $str($client['condIVA'] ?? '', 40);
    $concept = (int) ($body['conceptoTipo'] ?? 1);

    return [
        'tipo' => $type,
        'subtipo' => $type === 'nota' && ($body['subtipo'] ?? '') === 'debito' ? 'debito' : ($type === 'nota' ? 'credito' : ''),
        'letra' => in_array($letter, COMP_LETTERS, true) ? $letter : 'X',
        'ptoVenta' => comp_pv($body['ptoVenta'] ?? ''),
        'numero' => $number === '' ? '' : str_pad(substr($number, -8), 8, '0', STR_PAD_LEFT),
        'fecha' => $date($body['fecha'] ?? '', 'fecha del comprobante', true),
        'cliente' => [
            'razonSocial' => $str($client['razonSocial'] ?? '', 160),
            'cuit' => api_require_cuit($str($client['cuit'] ?? '', 13), 'CUIT del cliente', true),
            'condIVA' => isset(IVA_CONDICIONES[$condIva]) ? $condIva : 'Consumidor Final',
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
        'conceptoTipo' => in_array($concept, COMP_CONCEPTOS, true) ? $concept : 1,
        'servDesde' => $date($body['servDesde'] ?? '', 'fecha de inicio del servicio', false),
        'servHasta' => $date($body['servHasta'] ?? '', 'fecha de fin del servicio', false),
        'vtoPago' => $date($body['vtoPago'] ?? '', 'fecha de vencimiento del pago', false),
        'compAsoc' => [
            'tipo' => $str($assoc['tipo'] ?? '', 20),
            'letra' => strtoupper($str($assoc['letra'] ?? '', 1)),
            'pv' => preg_replace('/\D/', '', $str($assoc['pv'] ?? '', 5)),
            'nro' => preg_replace('/\D/', '', $str($assoc['nro'] ?? '', 8)),
        ],
        'observaciones' => $str($body['observaciones'] ?? '', 600),
        'fce' => in_array($type, ['factura', 'nota'], true) && in_array($letter, ['A', 'B'], true) && api_bool($body['fce'] ?? false),
        'fceAnulacion' => api_bool($body['fceAnulacion'] ?? false),
        // Remito que factura esta factura («Facturar este remito»): al autorizarse en
        // producción, el remito queda marcado como facturado.
        'remitoId' => $type === 'factura' && preg_match('/^[0-9a-f]{16}$/', (string) ($body['remitoId'] ?? ''))
            ? (string) $body['remitoId'] : '',
    ];
}

/** Punto de venta con 5 dígitos (por defecto 00001). */
function comp_pv(mixed $value): string
{
    $digits = preg_replace('/\D/', '', api_str($value, 10));
    $n = (int) substr($digits, -5);

    return str_pad((string) ($n >= 1 && $n <= 99998 ? $n : 1), 5, '0', STR_PAD_LEFT);
}

/** Comprobante guardado, listo para responder. Número, CAE y estado salen de las columnas. */
function comp_public(array $row): array
{
    $doc = json_decode((string) ($row['data'] ?? ''), true) ?: [];
    $doc['id'] = $row['id'];
    $doc['id_local'] = $row['id_local'];
    $doc['estado'] = $row['estado'];
    $doc['ptoVenta'] = $row['pto_vta'];
    $doc['numero'] = (string) ($row['numero'] ?? '');
    $doc['cae'] = (string) ($row['cae'] ?? '');
    $doc['caeVto'] = (string) ($row['cae_vto'] ?? '');
    $doc['ambienteArca'] = (string) ($row['ambiente'] ?? '') ?: ($doc['ambienteArca'] ?? '');
    $doc['updated'] = (int) $row['updated'];
    $doc['creado_por'] = $row['creado_por'] ?? null;
    $doc['actualizado_por'] = $row['actualizado_por'] ?? null;

    return $doc;
}

/** Nombre del comprobante para mensajes y auditoría. */
function comp_label(array $row): string
{
    $name = ['factura' => 'Factura', 'recibo' => 'Recibo', 'nota' => ($row['subtipo'] === 'debito' ? 'Nota de débito' : 'Nota de crédito')];

    return ($name[$row['tipo']] ?? 'Comprobante') . ' ' . $row['letra']
        . ($row['numero'] !== null && $row['numero'] !== '' ? ' Nº ' . $row['pto_vta'] . '-' . $row['numero'] : ' (sin número)');
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

/** ¿Este comprobante se numera y autoriza en ARCA según los ajustes actuales? */
function comp_is_electronic(array $emisor, string $letter, string $type = 'factura'): bool
{
    return !empty($emisor['emision']) && $type !== 'recibo' && in_array($letter, ['A', 'B'], true);
}

/** aaaammdd → dd/mm/aaaa. */
function comp_date_from_arca(string $value): string
{
    return preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m) ? $m[3] . '/' . $m[2] . '/' . $m[1] : '';
}

/** dd/mm/aaaa → aaaammdd. */
function comp_date_to_arca(string $dmy): string
{
    return str_replace('-', '', (string) api_date_iso($dmy));
}

/** Familia del comprobante asociado a partir del texto elegido («Factura», «Nota de débito»…). */
function comp_assoc_type(string $text): array
{
    $t = mb_strtolower($text);
    if (str_contains($t, 'débito') || str_contains($t, 'debito')) {
        return ['nota', 'debito'];
    }
    if (str_contains($t, 'crédito') || str_contains($t, 'credito')) {
        return ['nota', 'credito'];
    }

    return [str_contains($t, 'recibo') ? 'recibo' : 'factura', ''];
}

/**
 * Detalle para FECAESolicitar. Corta con 422 si los datos no alcanzan para emitir.
 *
 * @return array<string, mixed>
 */
function comp_cae_detail(PDO $pdo, array $doc, array $emisor, int $number, string $date): array
{
    $totals = importe_totales($doc);
    if ($totals['errores']) {
        api_error(422, implode('. ', $totals['errores']));
    }
    if ($totals['total'] <= 0) {
        api_error(422, 'El comprobante no tiene importe: cargá al menos un ítem con cantidad y precio');
    }

    $digits = preg_replace('/\D/', '', $doc['cliente']['cuit']);
    if (strlen($digits) === 11) {
        [$docTipo, $docNro] = [80, $digits];
    } elseif (strlen($digits) === 7 || strlen($digits) === 8) {
        [$docTipo, $docNro] = [96, $digits];
    } else {
        [$docTipo, $docNro] = [99, '0'];
    }
    if ($doc['letra'] === 'A' && $docTipo !== 80) {
        api_error(422, 'Un comprobante A requiere el CUIT del cliente');
    }
    $cond = IVA_CONDICIONES[$doc['cliente']['condIVA']];
    if ($cond['clase'] !== $doc['letra']) {
        api_error(422, 'A un cliente «' . $doc['cliente']['condIVA'] . '» corresponde un comprobante ' . $cond['clase']
            . ', no ' . $doc['letra'] . ' (tabla de condiciones frente al IVA de ARCA, RG 5616)');
    }
    if ($doc['letra'] === 'B' && $docTipo === 99 && $totals['total'] >= COMP_IDENTIFICAR_CF_DESDE) {
        api_error(422, 'Una factura B desde $ ' . number_format(COMP_IDENTIFICAR_CF_DESDE / 100, 0, ',', '.')
            . ' tiene que identificar al comprador: cargá su CUIT o DNI');
    }

    $concept = (int) $doc['conceptoTipo'];
    $detail = [
        'concepto' => $concept,
        'doc_tipo' => $docTipo,
        'doc_nro' => $docNro,
        'numero' => $number,
        'fecha' => $date,
        'total' => $totals['total'],
        'neto' => $totals['neto'],
        'iva' => $totals['iva'],
        'exento' => 0,
        'no_gravado' => 0,
        'cond_iva_receptor' => $cond['codigo'],
        'alicuotas' => [],
        'asociados' => [],
    ];
    $detail['alicuotas'][] = ['id' => IVA_ALICUOTAS[$totals['alicuota']], 'base' => $totals['neto'], 'importe' => $totals['iva']];
    if ($concept !== 1) {
        // Sin fechas cargadas, el período y el vencimiento son el día de la emisión ($date),
        // no el del borrador: ARCA exige que el vencimiento no sea anterior al comprobante.
        $detail['serv_desde'] = $doc['servDesde'] !== '' ? comp_date_to_arca($doc['servDesde']) : $date;
        $detail['serv_hasta'] = $doc['servHasta'] !== '' ? comp_date_to_arca($doc['servHasta']) : $date;
        $detail['vto_pago'] = $doc['vtoPago'] !== '' ? comp_date_to_arca($doc['vtoPago']) : $date;
        if ($detail['serv_hasta'] < $detail['serv_desde']) {
            api_error(422, 'El período del servicio termina antes de empezar');
        }
        if ($detail['vto_pago'] < $date) {
            api_error(422, 'El vencimiento del pago no puede ser anterior a la fecha del comprobante');
        }
    }

    $fce = !empty($doc['fce']);
    if ($fce && $docTipo !== 80) {
        api_error(422, 'La Factura de Crédito MiPyME requiere el CUIT del receptor');
    }
    if ($fce) {
        $cbu = preg_replace('/\D/', '', (string) ($emisor['cbu'] ?? ''));
        if (empty($emisor['mipyme']) || !cbu_valid($cbu)) {
            api_error(422, 'La Factura de Crédito Electrónica MiPyME necesita que la empresa esté marcada como MiPyME y su CBU'
                . ' (Configuración → Conexión con ARCA)');
        }
        if ($doc['tipo'] === 'factura') {
            if ($doc['vtoPago'] === '' || comp_date_to_arca($doc['vtoPago']) < $date) {
                api_error(422, 'La Factura de Crédito necesita la fecha de vencimiento del pago, igual o posterior a la emisión');
            }
            $detail['vto_pago'] = comp_date_to_arca($doc['vtoPago']);
            $detail['opcionales'] = [
                ['id' => '2101', 'valor' => $cbu],
                ['id' => '27', 'valor' => in_array($emisor['transferencia'] ?? 'SCA', ['SCA', 'ADC'], true) ? $emisor['transferencia'] : 'SCA'],
            ];
        } else {
            // Notas de la Factura de Crédito: si anulan una factura rechazada (opcional 22).
            $detail['opcionales'] = [['id' => '22', 'valor' => $doc['fceAnulacion'] ? 'S' : 'N']];
            if ($concept === 1) {
                unset($detail['vto_pago']);
            } else {
                $detail['vto_pago'] = '';
            }
        }
    }

    if ($doc['tipo'] === 'nota') {
        $a = $doc['compAsoc'];
        [$assocType, $assocSub] = comp_assoc_type($a['tipo']);
        $assocTipo = arca_cbte_tipo($assocType, $assocSub, $a['letra'], $fce);
        if ($assocTipo === null || (int) $a['pv'] < 1 || (int) $a['nro'] < 1) {
            api_error(422, 'Indicá el comprobante asociado a la nota (tipo, letra A o B, punto de venta y número)');
        }
        if ($a['letra'] !== $doc['letra']) {
            api_error(422, 'La nota ' . $doc['letra'] . ' tiene que asociarse a un comprobante ' . $doc['letra'] . ' (la clase tiene que coincidir)');
        }
        $st = $pdo->prepare(
            'SELECT fecha, data FROM comprobantes WHERE tipo = ? AND subtipo = ? AND letra = ? AND pto_vta = ? AND numero_int = ?
                AND cbte_tipo = ? AND ambiente = ? AND estado = \'autorizado\''
        );
        $st->execute([$assocType, $assocSub, $a['letra'], str_pad((string) (int) $a['pv'], 5, '0', STR_PAD_LEFT), (int) $a['nro'],
            $assocTipo, (string) ($emisor['ambiente'] ?? '')]);
        $assocRow = $st->fetch() ?: null;
        $assocDate = (string) ($assocRow['fecha'] ?? '');
        $assocFce = !empty((json_decode((string) ($assocRow['data'] ?? ''), true) ?: [])['fce']);
        if ($assocRow !== null && $assocFce !== $fce) {
            api_error(422, $assocFce
                ? 'El comprobante asociado es una Factura de Crédito MiPyME: la nota también tiene que serlo'
                : 'La nota de Factura de Crédito MiPyME tiene que asociarse a una Factura de Crédito');
        }
        if ($fce && $assocRow === null) {
            api_error(422, 'La nota de una Factura de Crédito tiene que asociarse a una Factura de Crédito autorizada en el sistema');
        }
        $detail['asociados'][] = [
            'tipo' => $assocTipo,
            'pv' => (int) $a['pv'],
            'nro' => (int) $a['nro'],
            'cuit' => preg_replace('/\D/', '', (string) $emisor['cuit']),
            'fecha' => $assocDate !== '' ? comp_date_to_arca($assocDate) : '',
        ];
    }

    return $detail;
}

/**
 * Decide si el comprobante sale como Factura de Crédito Electrónica MiPyME (Ley 27.440).
 *  - Notas: siguen al comprobante asociado (se resuelve en comp_cae_detail).
 *  - Facturas de una empresa MiPyME a un CUIT: se consulta a ARCA si el cliente está
 *    obligado a recibirla y desde qué importe; si corresponde, se emite como tal.
 *  - Si la consulta no se puede hacer (por ejemplo, el certificado no tiene el servicio
 *    wsfecred), se respeta lo elegido y se devuelve un aviso.
 *
 * @return array{0: array, 1: ?string} comprobante y aviso
 */
function comp_resolve_fce(PDO $pdo, array $config, array $doc, array $emisor, string $environment, string $todayDmy): array
{
    if (!empty($doc['fce']) && empty($emisor['mipyme'])) {
        api_error(422, 'Solo una empresa MiPyME emite Factura de Crédito Electrónica (Configuración → Conexión con ARCA)');
    }
    $cuit = preg_replace('/\D/', '', $doc['cliente']['cuit']);
    if ($doc['tipo'] !== 'factura' || empty($emisor['mipyme']) || !empty($doc['fce']) || strlen($cuit) !== 11) {
        return [$doc, null];
    }
    $total = importe_totales($doc)['total'];
    try {
        $r = arca_fce_obligado($pdo, $config, $environment, $cuit, (string) api_date_iso($todayDmy));
    } catch (ArcaException $e) {
        return [$doc, 'No se pudo consultar en ARCA si el cliente está obligado a recibir Factura de Crédito MiPyME: '
            . $e->getMessage()];
    }
    if ($r['obligado'] && $total >= $r['monto']) {
        $doc['fce'] = true;

        return [$doc, 'Se emitió como Factura de Crédito Electrónica MiPyME: ARCA informa que el cliente está obligado a'
            . ' recibirla desde $ ' . number_format($r['monto'] / 100, 2, ',', '.')];
    }

    return [$doc, null];
}

/** Marca un comprobante como autorizado con el CAE recibido. */
function comp_mark_authorized(PDO $pdo, array $row, int $number, string $cae, string $caeVto, string $date, array $extra, string $by): void
{
    $doc = json_decode((string) $row['data'], true) ?: [];
    $doc = array_merge($doc, $extra, ['fecha' => $date, 'numero' => str_pad((string) $number, 8, '0', STR_PAD_LEFT)]);
    $now = (int) round(microtime(true) * 1000);
    $pdo->prepare(
        "UPDATE comprobantes SET numero = ?, numero_int = ?, fecha = ?, fecha_emision = ?, cae = ?, cae_vto = ?, estado = 'autorizado',
                data = ?, updated = ?, actualizado_por = ? WHERE id = ?"
    )->execute([
        $doc['numero'], $number, $date, api_date_iso($date), $cae, $caeVto, json_encode($doc, JSON_UNESCAPED_UNICODE), $now, $by, $row['id'],
    ]);
    // Una factura de prueba (homologación) no tiene validez fiscal: no factura el remito.
    if (($doc['remitoId'] ?? '') !== '' && ($extra['ambienteArca'] ?? '') === 'produccion') {
        $pdo->prepare("UPDATE remitos SET facturado = 1, updated = ?, actualizado_por = ? WHERE id = ? AND estado = 'autorizado'")
            ->execute([$now, $by, $doc['remitoId']]);
    }
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
    $where = 'WHERE data IS NOT NULL';
    $params = [];
    $search = api_str($_GET['buscar'] ?? '', 80);
    if ($search !== '') {
        $where .= ' AND (cliente LIKE ? OR numero LIKE ? OR cae = ?)';
        $like = '%' . addcslashes($search, '%_\\') . '%';
        $params = [$like, $like, $search];
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM comprobantes $where");
    $st->execute($params);
    $total = (int) $st->fetchColumn();
    $offset = ($page - 1) * $perPage;
    $st = $pdo->prepare("SELECT * FROM comprobantes $where ORDER BY updated DESC, id LIMIT $perPage OFFSET $offset");
    $st->execute($params);
    api_respond(200, [
        'ok' => true,
        'total' => $total,
        'pagina' => $page,
        'paginas' => max(1, (int) ceil($total / $perPage)),
        'comprobantes' => array_map('comp_public', $st->fetchAll()),
    ]);
}

if ($method === 'POST') {
    $body = api_body();
    $action = (string) ($body['accion'] ?? 'guardar');

    /* ------------------------------ Emisión con CAE ------------------------------ */
    if ($action === 'emitir') {
        $auth = auth_require($pdo, $config, 'crear', 'comprobantes');
        $row = comp_find($pdo, (string) ($body['id'] ?? ''));
        if ($row === null) {
            api_error(404, 'Comprobante no encontrado. Guardalo antes de emitirlo.');
        }
        if ($row['estado'] === 'autorizado') {
            api_respond(200, ['ok' => true, 'ya_autorizado' => true, 'comprobante' => comp_public($row)]);
        }
        $emisor = settings_get($pdo, 'emisor');
        if (!comp_is_electronic($emisor, (string) $row['letra'], (string) $row['tipo'])) {
            api_error(409, 'La emisión electrónica no está activa para este comprobante (Configuración → Conexión con ARCA)');
        }
        $environment = (string) $emisor['ambiente'];
        $credential = arca_credentials_status($pdo, $environment);
        if ($credential === null || $credential['vencido']) {
            api_error(409, 'No hay un certificado vigente de ARCA para ' . ARCA_ENVIRONMENT_NAMES[$environment]);
        }
        $doc = json_decode((string) $row['data'], true) ?: [];
        $doc = array_merge(comp_from_input($doc), ['ptoVenta' => comp_pv($emisor['ptoVenta'])]);
        $pointOfSale = (int) $doc['ptoVenta'];
        $today = date('d/m/Y');
        [$doc, $fceNotice] = comp_resolve_fce($pdo, $config, $doc, $emisor, $environment, $today);
        $cbteTipo = arca_cbte_tipo($doc['tipo'], $doc['subtipo'], $doc['letra'], !empty($doc['fce']));
        // Se valida contra las reglas de ARCA antes de llamarla: un comprobante que ARCA
        // rechazaría (letra, condición frente al IVA, identificación, asociado) no consume
        // ningún pedido ni reserva número.
        comp_cae_detail($pdo, $doc, $emisor, 1, comp_date_to_arca($today));

        $lock = 'remitos_cae_' . $environment . '_' . $pointOfSale . '_' . $cbteTipo;
        api_lock($pdo, $lock, 60);
        try {
            $row = comp_find($pdo, $row['id']);       // releído bajo el bloqueo
            if ($row === null) {
                api_error(404, 'El comprobante se eliminó mientras se emitía');
            }
            if ($row['estado'] === 'autorizado') {
                api_respond(200, ['ok' => true, 'ya_autorizado' => true, 'comprobante' => comp_public($row)]);
            }
            if ($row['estado'] === 'emitiendo' && $row['ambiente'] !== '' && $row['ambiente'] !== $environment) {
                api_error(409, 'Este comprobante quedó con una emisión sin confirmar en ' . (ARCA_ENVIRONMENT_NAMES[$row['ambiente']] ?? $row['ambiente'])
                    . '. Volvé a ese ambiente para completarla antes de cambiar de ambiente.');
            }
            try {
                // Un intento anterior pudo quedar sin respuesta: antes de pedir otro número se
                // consulta si ARCA llegó a autorizar el que se había reservado.
                if ($row['estado'] === 'emitiendo' && (int) $row['numero_int'] > 0) {
                    // Se consulta con lo que se reservó (tipo y punto de venta), no con los ajustes de hoy.
                    $reservedTipo = (int) $row['cbte_tipo'] ?: $cbteTipo;
                    $found = arca_cae_lookup($pdo, $config, $environment, (int) $row['pto_vta'], $reservedTipo, (int) $row['numero_int']);
                    $detail = comp_cae_detail($pdo, $doc, $emisor, (int) $row['numero_int'], comp_date_to_arca($today));
                    if ($found !== null && (string) $found['doc_nro'] === (string) $detail['doc_nro']
                        && abs((float) $found['total'] - $detail['total'] / 100) < 0.005) {
                        comp_mark_authorized(
                            $pdo,
                            $row,
                            (int) $row['numero_int'],
                            $found['cae'],
                            comp_date_from_arca($found['vencimiento']),
                            comp_date_from_arca($found['fecha']) ?: $today,
                            ['ptoVenta' => $row['pto_vta'], 'ambienteArca' => $environment,
                                'fce' => in_array($reservedTipo, [201, 202, 203, 206, 207, 208], true)],
                            $auth['nombre']
                        );
                        audit_event($pdo, comp_label(comp_find($pdo, $row['id'])) . ' autorizada (recuperada de ARCA)', [
                            'categoria' => 'comprobante', 'detalle' => 'CAE ' . $found['cae'],
                        ]);
                        api_respond(200, ['ok' => true, 'comprobante' => comp_public(comp_find($pdo, $row['id'])), 'aviso' => $fceNotice]);
                    }
                }

                $number = arca_last_authorized($pdo, $config, $environment, $pointOfSale, $cbteTipo) + 1;
                $detail = comp_cae_detail($pdo, $doc, $emisor, $number, comp_date_to_arca($today));
                // Reserva del número: solo si sigue en borrador (o en una emisión anterior sin
                // confirmar). Si mientras tanto se eliminó o se autorizó, no se pide el CAE.
                try {
                    $st = $pdo->prepare(
                        "UPDATE comprobantes SET estado = 'emitiendo', pto_vta = ?, numero = ?, numero_int = ?, cbte_tipo = ?, ambiente = ?
                          WHERE id = ? AND estado IN ('borrador', 'emitiendo')"
                    );
                    $st->execute([$doc['ptoVenta'], str_pad((string) $number, 8, '0', STR_PAD_LEFT), $number, $cbteTipo, $environment, $row['id']]);
                } catch (PDOException $e) {
                    if (api_is_duplicate($e)) {
                        api_error(409, 'El número ' . $number . ' ya figura en otro comprobante del sistema para este tipo y punto de venta.'
                            . ' Revisá los comprobantes registrados a mano antes de emitir.');
                    }
                    throw $e;
                }
                if ($st->rowCount() !== 1) {
                    api_error(409, 'El comprobante cambió mientras se emitía (se eliminó o ya se autorizó). Actualizá la pantalla.');
                }
                $result = arca_cae_request($pdo, $config, $environment, $pointOfSale, $cbteTipo, $detail);
            } catch (ArcaException $e) {
                audit_event($pdo, 'Emisión sin confirmar: ' . comp_label($row), [
                    'categoria' => 'arca', 'nivel' => 'error', 'detalle' => $e->getMessage(), 'estado' => 502,
                ]);
                api_error(502, $e->getMessage() . ' Volvé a intentar la emisión: antes de pedir un número nuevo se consulta a ARCA.');
            }

            if ($result['resultado'] === 'A' && preg_match('/^\d{14}$/', $result['cae'])) {
                comp_mark_authorized(
                    $pdo,
                    $row,
                    $number,
                    $result['cae'],
                    comp_date_from_arca($result['vencimiento']),
                    $today,
                    ['ptoVenta' => $doc['ptoVenta'], 'observacionesArca' => $result['observaciones'], 'fce' => !empty($doc['fce']),
                        'ambienteArca' => $environment],
                    $auth['nombre']
                );
                $saved = comp_find($pdo, $row['id']);
                audit_event($pdo, comp_label($saved) . ' autorizada', [
                    'categoria' => 'comprobante',
                    'detalle' => 'CAE ' . $result['cae'] . ' · ' . ARCA_ENVIRONMENT_NAMES[$environment] . ' · total ' . arca_amount($detail['total']),
                ]);
                api_respond(200, [
                    'ok' => true, 'comprobante' => comp_public($saved), 'observaciones' => $result['observaciones'], 'aviso' => $fceNotice,
                ]);
            }

            if ($result['resultado'] !== 'R') {
                // Sin un rechazo explícito no se sabe si ARCA lo autorizó: se conserva el número
                // reservado y el reintento lo consulta antes de pedir otro.
                audit_event($pdo, 'Emisión sin confirmar: ' . comp_label($row), [
                    'categoria' => 'arca', 'nivel' => 'error', 'detalle' => 'resultado «' . $result['resultado'] . '»', 'estado' => 502,
                ]);
                api_error(502, 'ARCA no confirmó la emisión. Volvé a intentar: antes de pedir un número nuevo se consulta a ARCA.');
            }
            $pdo->prepare("UPDATE comprobantes SET estado = 'borrador', numero = NULL, numero_int = NULL, cbte_tipo = 0, ambiente = '' WHERE id = ?")
                ->execute([$row['id']]);
            $messages = array_merge($result['errores'], $result['observaciones']);
            audit_event($pdo, 'ARCA rechazó ' . comp_label($row), [
                'categoria' => 'arca', 'nivel' => 'error', 'detalle' => implode(' | ', $messages), 'estado' => 422,
            ]);
            api_error(422, 'ARCA rechazó el comprobante' . ($messages ? ': ' . implode(' · ', $messages) : ''), [
                'errores' => $result['errores'], 'observaciones' => $result['observaciones'],
            ]);
        } finally {
            api_unlock($pdo, $lock);
        }
    }

    /* ------------------------ CAE obtenido fuera del sistema ------------------------ */
    if ($action === 'registrar_cae') {
        $auth = auth_require($pdo, $config, 'admin', 'comprobantes');
        $row = comp_find($pdo, (string) ($body['id'] ?? ''));
        if ($row === null) {
            api_error(404, 'Comprobante no encontrado');
        }
        if ($row['estado'] === 'autorizado') {
            api_error(409, 'El comprobante ya tiene CAE');
        }
        if ($row['estado'] === 'emitiendo') {
            api_error(409, 'El comprobante tiene una emisión en curso: volvé a intentar la emisión');
        }
        if ($row['tipo'] === 'recibo' || !in_array($row['letra'], ['A', 'B'], true)) {
            api_error(422, 'Solo los comprobantes A y B llevan CAE');
        }
        $cae = preg_replace('/\D/', '', api_str($body['cae'] ?? '', 20));
        $caeVto = api_date_dmy($body['caeVto'] ?? '');
        $number = (int) preg_replace('/\D/', '', api_str($body['numero'] ?? '', 8));
        if (strlen($cae) !== 14 || $caeVto === '' || $number < 1) {
            api_error(422, 'Indicá el número, el CAE (14 dígitos) y su vencimiento (dd/mm/aaaa)');
        }
        if ((string) api_date_iso($caeVto) < (string) api_date_iso((string) $row['fecha'])) {
            api_error(422, 'El vencimiento del CAE no puede ser anterior a la fecha del comprobante');
        }
        $regDoc = json_decode((string) $row['data'], true) ?: [];
        $regTipo = (int) arca_cbte_tipo((string) $row['tipo'], (string) $row['subtipo'], (string) $row['letra'], !empty($regDoc['fce']));
        $regEnv = (string) (settings_get($pdo, 'emisor')['ambiente'] ?? '');
        try {
            $pdo->prepare('UPDATE comprobantes SET cbte_tipo = ?, ambiente = ? WHERE id = ?')->execute([$regTipo, $regEnv, $row['id']]);
            $row['cbte_tipo'] = $regTipo;
            comp_mark_authorized($pdo, $row, $number, $cae, $caeVto, (string) $row['fecha'], ['ambienteArca' => $regEnv], $auth['nombre']);
        } catch (PDOException $e) {
            if (api_is_duplicate($e)) {
                api_error(409, 'Ya existe otro comprobante de la misma serie con el Nº ' . $number);
            }
            throw $e;
        }
        $saved = comp_find($pdo, $row['id']);
        audit_event($pdo, 'CAE registrado manualmente: ' . comp_label($saved), ['categoria' => 'comprobante', 'detalle' => 'CAE ' . $cae]);
        api_respond(200, ['ok' => true, 'comprobante' => comp_public($saved)]);
    }

    if ($action !== 'guardar') {
        api_error(400, 'Acción desconocida');
    }

    /* ------------------------------ Alta o edición ------------------------------ */
    $auth = auth_require($pdo, $config, 'crear', 'comprobantes');
    $doc = comp_from_input($body);
    $totals = importe_totales($doc);
    if ($totals['errores']) {
        api_error(422, implode('. ', $totals['errores']));
    }
    $doc['importes'] = ['neto' => $totals['neto'], 'iva' => $totals['iva'], 'total' => $totals['total']];
    $now = (int) round(microtime(true) * 1000);
    $emisor = settings_get($pdo, 'emisor');
    $electronic = comp_is_electronic($emisor, $doc['letra'], $doc['tipo']);
    if ($electronic) {
        $doc['ptoVenta'] = comp_pv($emisor['ptoVenta']);
        $doc['numero'] = '';
    }
    $localId = api_str($body['id_local'] ?? '', 40);
    $localId = preg_match('/^[A-Za-z0-9_-]{4,40}$/', $localId) ? $localId : '';

    api_lock($pdo, COMP_LOCK);
    try {
        $existing = null;
        if ((string) ($body['id'] ?? '') !== '') {
            $existing = comp_find($pdo, (string) $body['id']);
            if ($existing === null) {
                api_error(404, 'Comprobante no encontrado');
            }
        } elseif ($localId !== '') {
            $st = $pdo->prepare('SELECT * FROM comprobantes WHERE id_local = ?');
            $st->execute([$localId]);
            $existing = $st->fetch() ?: null;
        }
        if ($existing !== null && $existing['estado'] !== 'borrador') {
            api_error(409, comp_label($existing) . ($existing['estado'] === 'autorizado'
                ? ' ya está autorizado y no se puede modificar. Para corregirlo, emití una nota de crédito.'
                : ' tiene una emisión en curso. Volvé a intentar la emisión.'), ['comprobante' => comp_public($existing)]);
        }

        $series = [$doc['tipo'], $doc['subtipo'], $doc['letra'], $doc['ptoVenta']];
        if (!$electronic && $doc['letra'] !== 'X') {
            // Un A o B no lleva un número propio: lo asigna ARCA al emitir, o lo registra un
            // administrador junto con el CAE obtenido fuera del sistema.
            $doc['numero'] = '';
        } elseif (!$electronic) {
            if ($doc['numero'] === '') {
                $st = $pdo->prepare(
                    "SELECT COALESCE(MAX(numero_int), 0) FROM comprobantes WHERE tipo = ? AND subtipo = ? AND letra = ? AND pto_vta = ?
                        AND cbte_tipo = 0 AND ambiente = ''"
                );
                $st->execute($series);
                $doc['numero'] = str_pad((string) ((int) $st->fetchColumn() + 1), 8, '0', STR_PAD_LEFT);
            }
            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM comprobantes
                  WHERE tipo = ? AND subtipo = ? AND letra = ? AND pto_vta = ? AND numero_int = ? AND cbte_tipo = 0 AND ambiente = '' AND id <> ?"
            );
            $st->execute([...$series, (int) $doc['numero'], $existing['id'] ?? '']);
            if ((int) $st->fetchColumn() > 0) {
                api_error(409, 'Ya existe ese comprobante con el Nº ' . $doc['ptoVenta'] . '-' . $doc['numero'] . '. Dejá el número vacío para asignar el siguiente.');
            }
        }

        $id = $existing['id'] ?? bin2hex(random_bytes(8));
        $json = json_encode(['id' => $id] + $doc, JSON_UNESCAPED_UNICODE);
        $values = [
            $doc['tipo'], $doc['subtipo'], $doc['letra'], $doc['ptoVenta'], $doc['numero'] ?: null,
            $doc['numero'] === '' ? null : (int) $doc['numero'], $doc['fecha'], api_date_iso($doc['fecha']),
            $doc['cliente']['razonSocial'], $json, $now, $auth['nombre'],
        ];
        if ($existing) {
            // Solo un borrador: si mientras tanto se empezó a emitir o se autorizó, no se pisa.
            $st = $pdo->prepare(
                "UPDATE comprobantes SET tipo = ?, subtipo = ?, letra = ?, pto_vta = ?, numero = ?, numero_int = ?, fecha = ?,
                        fecha_emision = ?, cliente = ?, data = ?, updated = ?, actualizado_por = ? WHERE id = ? AND estado = 'borrador'"
            );
            $st->execute([...$values, $id]);
            if ($st->rowCount() === 0 && (comp_find($pdo, $id)['estado'] ?? 'borrador') !== 'borrador') {
                api_error(409, comp_label($existing) . ' se está emitiendo o ya se autorizó: no se puede modificar.');
            }
        } else {
            $pdo->prepare(
                "INSERT INTO comprobantes (tipo, subtipo, letra, pto_vta, numero, numero_int, fecha, fecha_emision, cliente,
                                           data, updated, actualizado_por, id, id_local, created, creado_por, estado)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'borrador')"
            )->execute([...$values, $id, $localId ?: null, $now, $auth['nombre']]);
        }
    } finally {
        api_unlock($pdo, COMP_LOCK);
    }

    $saved = comp_find($pdo, $id);
    audit_event($pdo, comp_label($saved) . ($existing ? ' modificada' : ' guardada'), [
        'categoria' => 'comprobante', 'detalle' => mb_substr($doc['cliente']['razonSocial'], 0, 120) . ' · total ' . arca_amount($totals['total']),
    ]);
    api_respond($existing ? 200 : 201, ['ok' => true, 'id' => $id, 'comprobante' => comp_public($saved)]);
}

if ($method === 'DELETE') {
    $auth = auth_require($pdo, $config, 'borrar', 'comprobantes');
    $row = comp_find($pdo, (string) ($_GET['id'] ?? ''));
    if ($row === null) {
        api_error(404, 'Comprobante no encontrado');
    }
    // Un comprobante con CAE no se elimina nunca (es un documento fiscal que ARCA ya tiene y
    // que hay que conservar); uno con una emisión en curso, tampoco: puede tener CAE.
    if ($row['estado'] === 'emitiendo') {
        api_error(409, 'El comprobante tiene una emisión en curso. Volvé a intentar la emisión antes de eliminarlo.');
    }
    if ($row['estado'] === 'autorizado') {
        api_error(409, 'Un comprobante con CAE no se elimina. Para anularlo, emití una nota de crédito.');
    }
    $st = $pdo->prepare("DELETE FROM comprobantes WHERE id = ? AND estado = 'borrador'");
    $st->execute([$row['id']]);
    if ($st->rowCount() === 0) {
        api_error(409, 'El comprobante cambió mientras se eliminaba (se está emitiendo). Actualizá la pantalla.');
    }
    audit_event($pdo, 'Comprobante eliminado: ' . comp_label($row), [
        'categoria' => 'comprobante',
        'detalle' => mb_substr((string) $row['cliente'], 0, 120) . ($row['cae'] ? ' · CAE ' . $row['cae'] : ''),
    ]);
    api_respond(200, ['ok' => true, 'id' => $row['id']]);
}

api_error(405, 'Método no permitido');
