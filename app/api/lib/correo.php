<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Envío de comprobantes y remitos por correo electrónico.
 *
 *  - Sale con la función mail() del hosting. El dominio autoriza a ese servidor en su
 *    registro SPF, así que el mensaje llega como enviado por la empresa.
 *  - El remitente se configura en config.php (`correo.remitente`). Las respuestas van a
 *    quien lo envió (Reply-To), que además puede recibir una copia.
 *  - El cuerpo lleva el detalle del documento en HTML y en texto, y para los comprobantes
 *    con CAE el enlace de verificación de ARCA (el mismo del código QR, RG 4892/2020).
 *    Se puede adjuntar el PDF que se descargó desde la aplicación.
 *  - En el entorno de pruebas (variable REMITOS_CORREO_DIR) los mensajes se escriben como
 *    archivos .eml en esa carpeta en lugar de enviarse.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/** Destinatarios por envío y tamaño máximo del PDF adjunto. */
const CORREO_MAX_DESTINATARIOS = 5;
const CORREO_MAX_ADJUNTO = 3145728;

/** Envíos permitidos por usuario en una hora (evita usar el sistema para enviar correo masivo). */
const CORREO_MAX_POR_HORA = 40;

/** Remitente y nombre de la empresa, desde config.php (con valores por defecto). */
function correo_config(array $config): array
{
    $c = is_array($config['correo'] ?? null) ? $config['correo'] : [];
    $remitente = (string) ($c['remitente'] ?? '');
    if (!filter_var($remitente, FILTER_VALIDATE_EMAIL)) {
        $dominio = (string) (($config['google']['dominio'] ?? '') ?: 'intellihelp.tech');
        $remitente = 'comprobantes@' . $dominio;
    }

    return ['remitente' => $remitente, 'empresa' => (string) (($c['empresa'] ?? '') ?: 'INTELLIHELP S.A.')];
}

/**
 * Lista de direcciones separadas por coma, punto y coma o espacios. Responde 422 si alguna
 * no es válida o si son demasiadas.
 *
 * @return string[]
 */
function correo_destinatarios(string $texto): array
{
    $lista = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', mb_strtolower($texto)) ?: []))));
    if ($lista === []) {
        api_error(422, 'Indicá al menos una dirección de correo');
    }
    if (count($lista) > CORREO_MAX_DESTINATARIOS) {
        api_error(422, 'Se puede enviar a ' . CORREO_MAX_DESTINATARIOS . ' direcciones como máximo');
    }
    foreach ($lista as $dir) {
        if (!filter_var($dir, FILTER_VALIDATE_EMAIL) || strlen($dir) > 190) {
            api_error(422, 'La dirección «' . $dir . '» no es un correo válido');
        }
    }

    return $lista;
}

/** Texto seguro para HTML. */
function correo_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Importe en centavos → «$ 1.234,56». */
function correo_pesos(int $centavos): string
{
    return '$ ' . number_format($centavos / 100, 2, ',', '.');
}

/**
 * Cuerpo del mensaje (HTML y texto) a partir de filas «etiqueta → valor», una tabla de
 * renglones y un enlace opcional.
 *
 * @param array<int, array{0: string, 1: string}> $datos
 * @param array{titulos: string[], filas: array<int, string[]>} $tabla
 * @param array<int, array{0: string, 1: string}> $totales
 * @return array{html: string, texto: string}
 */
function correo_cuerpo(string $empresa, string $titulo, string $mensaje, array $datos, array $tabla, array $totales, ?array $enlace): array
{
    $azul = '#0c3d91';
    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>' . correo_h($titulo) . '</title></head>'
        . '<body style="margin:0;padding:24px;background:#f3f5f9;font-family:Segoe UI,Arial,sans-serif;color:#1d2433">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;margin:0 auto;background:#fff;border:1px solid #e2e6ee;border-radius:10px">'
        . '<tr><td style="padding:20px 24px;background:' . $azul . ';color:#fff;border-radius:10px 10px 0 0">'
        . '<div style="font-size:13px;opacity:.85">' . correo_h($empresa) . '</div>'
        . '<div style="font-size:20px;font-weight:700;margin-top:4px">' . correo_h($titulo) . '</div></td></tr>'
        . '<tr><td style="padding:20px 24px">';
    $texto = $empresa . "\n" . $titulo . "\n\n";
    if ($mensaje !== '') {
        $html .= '<p style="margin:0 0 16px;white-space:pre-line;line-height:1.5">' . correo_h($mensaje) . '</p>';
        $texto .= $mensaje . "\n\n";
    }
    $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;margin-bottom:16px">';
    foreach ($datos as [$k, $v]) {
        $html .= '<tr><td style="padding:4px 12px 4px 0;color:#5b6577;white-space:nowrap;vertical-align:top">' . correo_h($k)
            . '</td><td style="padding:4px 0;font-weight:600">' . correo_h($v) . '</td></tr>';
        $texto .= $k . ': ' . $v . "\n";
    }
    $html .= '</table>';
    if ($tabla['filas'] !== []) {
        $html .= '<table cellpadding="0" cellspacing="0" style="width:100%;font-size:13px;border-collapse:collapse;margin-bottom:12px"><tr>';
        foreach ($tabla['titulos'] as $i => $t) {
            $html .= '<th style="text-align:' . ($i === 0 ? 'left' : 'right') . ';padding:8px;border-bottom:2px solid #e2e6ee;color:#5b6577">' . correo_h($t) . '</th>';
        }
        $html .= '</tr>';
        $texto .= "\n";
        foreach ($tabla['filas'] as $fila) {
            $html .= '<tr>';
            foreach ($fila as $i => $celda) {
                $html .= '<td style="text-align:' . ($i === 0 ? 'left' : 'right') . ';padding:8px;border-bottom:1px solid #eef1f5">' . correo_h($celda) . '</td>';
            }
            $html .= '</tr>';
            $texto .= '- ' . implode(' · ', $fila) . "\n";
        }
        $html .= '</table>';
    }
    if ($totales !== []) {
        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;margin-bottom:16px">';
        $texto .= "\n";
        foreach ($totales as $i => [$k, $v]) {
            $fuerte = $i === array_key_last($totales);
            $html .= '<tr><td style="text-align:right;padding:3px 12px 3px 0;color:#5b6577' . ($fuerte ? ';font-weight:700;color:#1d2433' : '') . '">'
                . correo_h($k) . '</td><td style="text-align:right;padding:3px 0;width:140px;font-weight:' . ($fuerte ? '700;font-size:16px' : '600') . '">' . correo_h($v) . '</td></tr>';
            $texto .= $k . ': ' . $v . "\n";
        }
        $html .= '</table>';
    }
    if ($enlace !== null) {
        $html .= '<p style="margin:8px 0 0"><a href="' . correo_h($enlace[1]) . '" style="display:inline-block;padding:10px 16px;background:' . $azul
            . ';color:#fff;text-decoration:none;border-radius:6px;font-weight:600">' . correo_h($enlace[0]) . '</a></p>';
        $texto .= "\n" . $enlace[0] . ': ' . $enlace[1] . "\n";
    }
    $html .= '</td></tr><tr><td style="padding:14px 24px;border-top:1px solid #e2e6ee;font-size:12px;color:#7a8396">'
        . 'Mensaje enviado por ' . correo_h($empresa) . ' desde su sistema de comprobantes. Para consultas, respondé este correo.'
        . '</td></tr></table></body></html>';

    return ['html' => $html, 'texto' => $texto];
}

/**
 * Envía (o, en pruebas, guarda) un mensaje MIME con HTML, texto y un PDF opcional.
 *
 * @param string[] $para
 * @param string[] $copia
 * @param array{nombre: string, datos: string}|null $adjunto
 */
function correo_enviar(array $config, array $para, array $copia, string $responderA, string $asunto, array $cuerpo, ?array $adjunto): bool
{
    $cfg = correo_config($config);
    $limpio = static fn (string $s): string => trim(str_replace(["\r", "\n"], ' ', $s));
    $alt = 'alt_' . bin2hex(random_bytes(12));
    $mixto = 'mix_' . bin2hex(random_bytes(12));

    $cabeceras = [
        'From: ' . mb_encode_mimeheader($limpio($cfg['empresa']), 'UTF-8', 'Q') . ' <' . $cfg['remitente'] . '>',
        'Reply-To: ' . $limpio($responderA),
        'MIME-Version: 1.0',
        'X-Mailer: Remitos-Intellihelp',
    ];
    if ($copia !== []) {
        $cabeceras[] = 'Cc: ' . implode(', ', $copia);
    }
    $alternativa = '--' . $alt . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($cuerpo['texto']))
        . '--' . $alt . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($cuerpo['html']))
        . '--' . $alt . "--\r\n";
    if ($adjunto === null) {
        $cabeceras[] = 'Content-Type: multipart/alternative; boundary="' . $alt . '"';
        $mensaje = $alternativa;
    } else {
        $nombre = preg_replace('/[^A-Za-z0-9 ._-]/', '', $adjunto['nombre']) ?: 'comprobante.pdf';
        $cabeceras[] = 'Content-Type: multipart/mixed; boundary="' . $mixto . '"';
        $mensaje = '--' . $mixto . "\r\nContent-Type: multipart/alternative; boundary=\"" . $alt . "\"\r\n\r\n" . $alternativa
            . '--' . $mixto . "\r\nContent-Type: application/pdf; name=\"" . $nombre . "\"\r\nContent-Transfer-Encoding: base64\r\n"
            . 'Content-Disposition: attachment; filename="' . $nombre . "\"\r\n\r\n"
            . chunk_split(base64_encode($adjunto['datos']))
            . '--' . $mixto . "--\r\n";
    }
    $asuntoMime = mb_encode_mimeheader($limpio($asunto), 'UTF-8', 'Q');

    $carpeta = getenv('REMITOS_CORREO_DIR');
    if (is_string($carpeta) && $carpeta !== '') {
        $eml = 'To: ' . implode(', ', $para) . "\r\nSubject: " . $asuntoMime . "\r\n" . implode("\r\n", $cabeceras) . "\r\n\r\n" . $mensaje;

        return file_put_contents(rtrim($carpeta, '/\\') . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml', $eml) !== false;
    }

    // -f fija el remitente del sobre (Return-Path), que es lo que valida el SPF.
    return mail(implode(', ', $para), $asuntoMime, $mensaje, implode("\r\n", $cabeceras), '-f' . $cfg['remitente']);
}
