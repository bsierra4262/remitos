<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Facturas, recibos y notas de crédito y débito compartidos, y su emisión electrónica.
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
 *  - Con la emisión electrónica activa (ajustes del emisor), los comprobantes A, B y C
 *    no tienen número hasta que ARCA los autoriza: el número es el último autorizado
 *    + 1 y se toma bajo un bloqueo por punto de venta y tipo. Hasta entonces se
 *    imprimen como «SIN AUTORIZAR».
 *  - Sin emisión electrónica (o letra X) el servidor asigna el correlativo interno de
 *    la serie (tipo, letra y punto de venta), o respeta el que se indique si está libre.
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
const COMP_LETTERS = ['A', 'B', 'C', 'X'];
const COMP_MAX_ITEMS = 60;
const COMP_LOCK = 'remitos_comprobantes';

/** Condición frente al IVA del receptor → código de WSFEv1 (RG 5616/2024). */
const COMP_COND_IVA = ['Responsable Inscripto' => 1, 'Exento' => 4, 'Consumidor Final' => 5, 'Monotributo' => 6];

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
            'condIVA' => isset(COMP_COND_IVA[$condIva]) ? $condIva : 'Consumidor Final',
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
function comp_is_electronic(array $emisor, string $letter): bool
{
    return !empty($emisor['emision']) && in_array($letter, ['A', 'B', 'C'], true);
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
    if ($doc['letra'] === 'A' && $doc['cliente']['condIVA'] === 'Consumidor Final') {
        api_error(422, 'Un comprobante A no se emite a un consumidor final: revisá la letra o la condición frente al IVA');
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
        'cond_iva_receptor' => COMP_COND_IVA[$doc['cliente']['condIVA']],
        'alicuotas' => [],
        'asociados' => [],
    ];
    if ($doc['letra'] !== 'C') {
        $detail['alicuotas'][] = ['id' => IVA_ALICUOTAS[$totals['alicuota']], 'base' => $totals['neto'], 'importe' => $totals['iva']];
    }
    if ($concept !== 1) {
        $detail['serv_desde'] = comp_date_to_arca($doc['servDesde'] ?: $doc['fecha']);
        $detail['serv_hasta'] = comp_date_to_arca($doc['servHasta'] ?: $doc['fecha']);
        $detail['vto_pago'] = comp_date_to_arca($doc['vtoPago'] ?: $doc['fecha']);
        if ($detail['serv_hasta'] < $detail['serv_desde']) {
            api_error(422, 'El período del servicio termina antes de empezar');
        }
    }

    if ($doc['tipo'] === 'nota') {
        $a = $doc['compAsoc'];
        [$assocType, $assocSub] = comp_assoc_type($a['tipo']);
        $assocTipo = arca_cbte_tipo($assocType, $assocSub, $a['letra']);
        if ($assocTipo === null || (int) $a['pv'] < 1 || (int) $a['nro'] < 1) {
            api_error(422, 'Indicá el comprobante asociado a la nota (tipo, letra A/B/C, punto de venta y número)');
        }
        $st = $pdo->prepare(
            'SELECT fecha FROM comprobantes WHERE tipo = ? AND subtipo = ? AND letra = ? AND pto_vta = ? AND numero_int = ? AND estado = \'autorizado\''
        );
        $st->execute([$assocType, $assocSub, $a['letra'], str_pad((string) (int) $a['pv'], 5, '0', STR_PAD_LEFT), (int) $a['nro']]);
        $assocDate = (string) ($st->fetchColumn() ?: '');
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
        if (!comp_is_electronic($emisor, (string) $row['letra'])) {
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
        $cbteTipo = arca_cbte_tipo($doc['tipo'], $doc['subtipo'], $doc['letra']);
        $today = date('d/m/Y');

        $lock = 'remitos_cae_' . $environment . '_' . $pointOfSale . '_' . $cbteTipo;
        api_lock($pdo, $lock, 60);
        try {
            $row = comp_find($pdo, $row['id']);       // releído bajo el bloqueo
            if ($row['estado'] === 'autorizado') {
                api_respond(200, ['ok' => true, 'ya_autorizado' => true, 'comprobante' => comp_public($row)]);
            }
            try {
                // Un intento anterior pudo quedar sin respuesta: antes de pedir otro número se
                // consulta si ARCA llegó a autorizar el que se había reservado.
                if ($row['estado'] === 'emitiendo' && (int) $row['numero_int'] > 0) {
                    $found = arca_cae_lookup($pdo, $config, $environment, $pointOfSale, $cbteTipo, (int) $row['numero_int']);
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
                            ['ptoVenta' => $doc['ptoVenta']],
                            $auth['nombre']
                        );
                        audit_event($pdo, comp_label(comp_find($pdo, $row['id'])) . ' autorizada (recuperada de ARCA)', [
                            'categoria' => 'comprobante', 'detalle' => 'CAE ' . $found['cae'],
                        ]);
                        api_respond(200, ['ok' => true, 'comprobante' => comp_public(comp_find($pdo, $row['id']))]);
                    }
                }

                $number = arca_last_authorized($pdo, $config, $environment, $pointOfSale, $cbteTipo) + 1;
                $detail = comp_cae_detail($pdo, $doc, $emisor, $number, comp_date_to_arca($today));
                $pdo->prepare("UPDATE comprobantes SET estado = 'emitiendo', pto_vta = ?, numero = ?, numero_int = ? WHERE id = ?")
                    ->execute([$doc['ptoVenta'], str_pad((string) $number, 8, '0', STR_PAD_LEFT), $number, $row['id']]);
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
                    ['ptoVenta' => $doc['ptoVenta'], 'observacionesArca' => $result['observaciones']],
                    $auth['nombre']
                );
                $saved = comp_find($pdo, $row['id']);
                audit_event($pdo, comp_label($saved) . ' autorizada', [
                    'categoria' => 'comprobante',
                    'detalle' => 'CAE ' . $result['cae'] . ' · ' . ARCA_ENVIRONMENT_NAMES[$environment] . ' · total ' . arca_amount($detail['total']),
                ]);
                api_respond(200, ['ok' => true, 'comprobante' => comp_public($saved), 'observaciones' => $result['observaciones']]);
            }

            $pdo->prepare("UPDATE comprobantes SET estado = 'borrador', numero = NULL, numero_int = NULL WHERE id = ?")
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
        $cae = preg_replace('/\D/', '', api_str($body['cae'] ?? '', 20));
        $caeVto = api_date_dmy($body['caeVto'] ?? '');
        $number = (int) preg_replace('/\D/', '', api_str($body['numero'] ?? '', 8));
        if (strlen($cae) !== 14 || $caeVto === '' || $number < 1) {
            api_error(422, 'Indicá el número, el CAE (14 dígitos) y su vencimiento (dd/mm/aaaa)');
        }
        try {
            comp_mark_authorized($pdo, $row, $number, $cae, $caeVto, (string) $row['fecha'], [], $auth['nombre']);
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
    $electronic = comp_is_electronic($emisor, $doc['letra']);
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
        if (!$electronic) {
            if ($doc['numero'] === '') {
                $st = $pdo->prepare(
                    'SELECT COALESCE(MAX(numero_int), 0) FROM comprobantes WHERE tipo = ? AND subtipo = ? AND letra = ? AND pto_vta = ?'
                );
                $st->execute($series);
                $doc['numero'] = str_pad((string) ((int) $st->fetchColumn() + 1), 8, '0', STR_PAD_LEFT);
            }
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM comprobantes
                  WHERE tipo = ? AND subtipo = ? AND letra = ? AND pto_vta = ? AND numero_int = ? AND id <> ?'
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
            $pdo->prepare(
                'UPDATE comprobantes SET tipo = ?, subtipo = ?, letra = ?, pto_vta = ?, numero = ?, numero_int = ?, fecha = ?,
                        fecha_emision = ?, cliente = ?, data = ?, updated = ?, actualizado_por = ? WHERE id = ?'
            )->execute([...$values, $id]);
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
    if ($row['estado'] === 'emitiendo' && !in_array('admin', $auth['permisos'], true)) {
        api_error(409, 'El comprobante tiene una emisión en curso. Volvé a intentar la emisión antes de eliminarlo.');
    }
    if ($row['estado'] === 'autorizado' && !in_array('admin', $auth['permisos'], true)) {
        api_error(409, 'Un comprobante con CAE solo lo puede eliminar un administrador. Para anularlo, emití una nota de crédito.');
    }
    $pdo->prepare('DELETE FROM comprobantes WHERE id = ?')->execute([$row['id']]);
    audit_event($pdo, 'Comprobante eliminado: ' . comp_label($row), [
        'categoria' => 'comprobante',
        'nivel' => $row['estado'] === 'autorizado' ? 'error' : 'info',
        'detalle' => mb_substr((string) $row['cliente'], 0, 120) . ($row['cae'] ? ' · CAE ' . $row['cae'] : ''),
    ]);
    api_respond(200, ['ok' => true, 'id' => $row['id']]);
}

api_error(405, 'Método no permitido');
