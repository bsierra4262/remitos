<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Envío de un comprobante o de un remito por correo electrónico (lib/correo.php).
 *
 *   POST {tipo: "comprobante"|"remito", id, para, copia?, mensaje?, enlace?, pdf?, pdfNombre?}
 *
 *  - Solo con sesión de usuario y permiso de crear: una clave de API no envía correos.
 *  - Comprobantes: los ya emitidos (con CAE, o X numerados). Remitos: solo autorizados; el
 *    enlace de conformidad que se incluye tiene que ser de esta aplicación.
 *  - `pdf`: el PDF descargado desde la aplicación, en base64 (hasta 3 MB).
 *  - Cada envío queda en la auditoría. Hay un máximo de envíos por hora por usuario.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$config = require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/correo.php';
api_headers('POST, OPTIONS');
if (api_method() !== 'POST') {
    api_error(405, 'Método no permitido');
}
$pdo = api_db($config);
$auth = auth_require_session($pdo, 'crear', 'correo');
$user = $auth['usuario'];
$body = api_body(intdiv(CORREO_MAX_ADJUNTO * 4, 3) + 65536);

if (attempts_count($pdo, 'correo', (string) $user['id'], false, 60) >= CORREO_MAX_POR_HORA) {
    api_error(429, 'Se alcanzó el máximo de ' . CORREO_MAX_POR_HORA . ' correos por hora. Probá más tarde.');
}

$para = correo_destinatarios(api_str($body['para'] ?? '', 600));
$copia = api_bool($body['copia'] ?? false) && filter_var($user['email'] ?? '', FILTER_VALIDATE_EMAIL) ? [(string) $user['email']] : [];
$mensaje = api_str($body['mensaje'] ?? '', 2000);
$empresa = correo_config($config)['empresa'];

$adjunto = null;
$pdf = (string) ($body['pdf'] ?? '');
if ($pdf !== '') {
    $datos = base64_decode($pdf, true);
    if ($datos === false || !str_starts_with($datos, '%PDF-')) {
        api_error(422, 'El archivo adjunto no es un PDF');
    }
    if (strlen($datos) > CORREO_MAX_ADJUNTO) {
        api_error(413, 'El PDF supera los 3 MB');
    }
    $adjunto = ['nombre' => api_str($body['pdfNombre'] ?? '', 100) ?: 'comprobante.pdf', 'datos' => $datos];
}

$tipo = (string) ($body['tipo'] ?? '');
$id = (string) ($body['id'] ?? '');
if (!preg_match('/^[0-9a-f]{16}$/', $id)) {
    api_error(400, 'Falta el documento a enviar');
}

if ($tipo === 'comprobante') {
    $st = $pdo->prepare('SELECT * FROM comprobantes WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        api_error(404, 'Comprobante no encontrado');
    }
    $doc = json_decode((string) $row['data'], true) ?: [];
    $emitido = $row['estado'] === 'autorizado' || ($row['letra'] === 'X' && (string) $row['numero'] !== '');
    if (!$emitido) {
        api_error(409, 'Solo se envían comprobantes emitidos: con CAE, o letra X ya guardados con número');
    }
    $nombre = ['factura' => 'Factura', 'recibo' => 'Recibo', 'nota' => ($row['subtipo'] === 'debito' ? 'Nota de débito' : 'Nota de crédito')][$row['tipo']] ?? 'Comprobante';
    $numero = $row['pto_vta'] . '-' . $row['numero'];
    $titulo = $nombre . ' ' . $row['letra'] . ' Nº ' . $numero;
    $cli = (array) ($doc['cliente'] ?? []);
    $tot = importe_totales($doc);
    $datos = [
        ['Fecha', (string) ($doc['fecha'] ?? '')],
        ['Cliente', (string) (($cli['razonSocial'] ?? '') ?: 'Consumidor final')],
    ];
    if (($cli['cuit'] ?? '') !== '') {
        $datos[] = ['Documento', (string) $cli['cuit']];
    }
    $enlace = null;
    if ((string) $row['cae'] !== '') {
        $datos[] = ['CAE', (string) $row['cae']];
        $datos[] = ['Vencimiento del CAE', (string) $row['cae_vto']];
        if ($row['ambiente'] === 'homologacion') {
            $datos[] = ['Ambiente', 'Homologación de ARCA: comprobante de prueba, sin validez fiscal'];
        }
        // El mismo enlace del código QR fiscal (RG 4892/2020).
        $digits = preg_replace('/\D/', '', (string) ($cli['cuit'] ?? ''));
        $docTipo = (int) ($cli['docTipo'] ?? 0) ?: (strlen($digits) === 11 ? 80 : (strlen($digits) >= 7 ? 96 : 99));
        $emisor = settings_get($pdo, 'emisor');
        $qr = [
            'ver' => 1, 'fecha' => (string) api_date_iso((string) ($doc['fecha'] ?? '')),
            'cuit' => (int) preg_replace('/\D/', '', (string) ($emisor['cuit'] ?? '')), 'ptoVta' => (int) $row['pto_vta'],
            'tipoCmp' => (int) $row['cbte_tipo'], 'nroCmp' => (int) $row['numero'], 'importe' => $tot['total'] / 100,
            'moneda' => 'PES', 'ctz' => 1, 'tipoDocRec' => $docTipo, 'nroDocRec' => $docTipo === 99 ? 0 : (int) $digits,
            'tipoCodAut' => 'E', 'codAut' => (int) $row['cae'],
        ];
        $enlace = ['Verificar el comprobante en ARCA', 'https://www.afip.gob.ar/fe/qr/?p=' . base64_encode((string) json_encode($qr))];
    }
    $filas = [];
    $totales = [];
    if ($row['tipo'] === 'recibo') {
        $datos[] = ['Forma de pago', (string) ($doc['medioPago'] ?? '')];
        $datos[] = ['En concepto de', (string) ($doc['concepto'] ?? '')];
        foreach ((array) ($doc['cancela'] ?? []) as $c) {
            $filas[] = [(string) ($c['etiqueta'] ?? ''), correo_pesos(importe_centavos($c['importe'] ?? '') ?? 0)];
        }
        $totales[] = ['Total recibido', correo_pesos($tot['total'])];
    } else {
        foreach ((array) ($doc['items'] ?? []) as $it) {
            $filas[] = [
                trim(($it['codigo'] ?? '') !== '' ? $it['codigo'] . ' · ' . ($it['descripcion'] ?? '') : (string) ($it['descripcion'] ?? '')),
                trim(($it['cantidad'] ?? '') . ' ' . ($it['unidad'] ?? '')),
                correo_pesos(importe_item((array) $it) ?? 0),
            ];
        }
        if ($row['letra'] === 'A') {
            $totales[] = ['Importe neto gravado', correo_pesos($tot['neto'])];
            foreach ($tot['alicuotas'] as $a) {
                $totales[] = ['IVA ' . str_replace('.', ',', $a['alicuota']) . ' %', correo_pesos($a['iva'])];
            }
        } elseif ($row['letra'] === 'B') {
            $totales[] = ['IVA contenido (Ley 27.743)', correo_pesos($tot['iva'])];
        }
        $totales[] = ['Importe total', correo_pesos($tot['total'])];
    }
    $cuerpo = correo_cuerpo(
        $empresa,
        $titulo,
        $mensaje,
        $datos,
        ['titulos' => $row['tipo'] === 'recibo' ? ['Comprobante que cancela', 'Importe'] : ['Detalle', 'Cantidad', 'Subtotal'], 'filas' => $filas],
        $totales,
        $enlace
    );
    $evento = $titulo;
} elseif ($tipo === 'remito') {
    $st = $pdo->prepare('SELECT * FROM remitos WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        api_error(404, 'Remito no encontrado');
    }
    if ($row['estado'] !== 'autorizado') {
        api_error(409, 'Solo se envían remitos autorizados (con número y C.A.I.)');
    }
    $doc = json_decode((string) $row['data'], true) ?: [];
    $cli = (array) ($doc['cliente'] ?? []);
    $titulo = 'Remito R Nº ' . $row['numero'];
    $datos = [
        ['Fecha', (string) ($doc['fecha'] ?? '')],
        ['Destinatario', (string) (($cli['razonSocial'] ?? '') ?: ($cli['senores'] ?? ''))],
        ['C.A.I.', (string) ($doc['cai'] ?? '')],
        ['Vencimiento del C.A.I.', (string) ($doc['caiVto'] ?? '')],
    ];
    $filas = [];
    foreach ((array) ($doc['items'] ?? []) as $it) {
        if (trim((string) ($it['descripcion'] ?? '')) !== '') {
            $filas[] = [(string) $it['descripcion'], (string) ($it['cantidad'] ?? '')];
        }
    }
    $enlace = null;
    $url = api_str($body['enlace'] ?? '', 300);
    if ($url !== '') {
        $base = api_base_url($config) . '/conformidad.html#';
        if (!str_starts_with($url, $base) || !preg_match('/^[A-Za-z0-9_-]{43}$/', substr($url, strlen($base)))) {
            api_error(422, 'El enlace de conformidad no es de esta aplicación');
        }
        $enlace = ['Confirmar la recepción', $url];
    }
    $cuerpo = correo_cuerpo($empresa, $titulo, $mensaje, $datos, ['titulos' => ['Bienes', 'Cantidad'], 'filas' => $filas], [], $enlace);
    $evento = 'Remito Nº ' . $row['numero'];
} else {
    api_error(400, 'Tipo de documento inválido (comprobante o remito)');
}

attempts_record($pdo, 'correo', (string) $user['id']);
if (!correo_enviar($config, $para, $copia, (string) $user['email'], $titulo . ' · ' . $empresa, $cuerpo, $adjunto)) {
    audit_event($pdo, $evento . ': falló el envío por correo', ['categoria' => $tipo === 'remito' ? 'remito' : 'comprobante', 'nivel' => 'error', 'detalle' => implode(', ', $para)]);
    api_error(502, 'El servidor de correo no aceptó el mensaje. Probá de nuevo en unos minutos.');
}
audit_event($pdo, $evento . ' enviado por correo', [
    'categoria' => $tipo === 'remito' ? 'remito' : 'comprobante',
    'detalle' => 'A ' . implode(', ', $para) . ($copia ? ' (con copia a ' . $copia[0] . ')' : '') . ($adjunto ? ' · con PDF' : ''),
]);
api_respond(200, ['ok' => true, 'enviado_a' => $para]);
