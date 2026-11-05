<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Conformidad de recepción de un remito digital (RG 5678/2025, art. 27 de la RG 1415:
 * «deberán habilitarse los medios que permitan a los destinatarios de los bienes
 * conformar su recepción»).
 *
 *   POST conformidad.php {accion: 'ver', token}                       Datos del remito.
 *   POST conformidad.php {accion: 'conformar', token, nombre, documento, observaciones?, conforme: true}
 *
 * No usa sesión: la credencial es el token del enlace (256 bits, se guarda solo su
 * SHA-256), que vence a los 60 días y deja de valer si se genera uno nuevo. Muestra
 * solo lo que el destinatario ya tiene en su copia del remito. Los tokens inválidos
 * se cuentan por conexión y, tras 20 en 15 minutos, se responde 429.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$config = require __DIR__ . '/bootstrap.php';
api_headers('POST, OPTIONS');
if (api_method() !== 'POST') {
    api_error(405, 'Método no permitido');
}
api_require_same_origin();
$pdo = api_db($config);

const CONFORMIDAD_DIAS = 60;
const CONFORMIDAD_MAX_FALLOS = 20;

if (attempts_count($pdo, 'conformidad', null, true, 15) >= CONFORMIDAD_MAX_FALLOS) {
    api_error(429, 'Demasiados intentos con enlaces inválidos. Probá de nuevo en unos minutos.');
}

$body = api_body();
$token = is_string($body['token'] ?? null) ? $body['token'] : '';
$row = null;
if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
    $st = $pdo->prepare(
        "SELECT * FROM remitos WHERE conformidad_hash = ? AND estado = 'autorizado'
            AND conformidad_desde > NOW() - INTERVAL " . CONFORMIDAD_DIAS . ' DAY'
    );
    $st->execute([hash('sha256', $token)]);
    $row = $st->fetch() ?: null;
}
if ($row === null) {
    attempts_record($pdo, 'conformidad');
    api_error(404, 'El enlace no es válido o venció. Pedile uno nuevo a quien te envió el remito.');
}

/** Lo que ve el destinatario: el contenido de su propia copia del remito. */
function conformidad_public(array $row): array
{
    $doc = json_decode((string) $row['data'], true) ?: [];
    $cliente = (array) ($doc['cliente'] ?? []);
    $transporte = (array) ($doc['transporte'] ?? []);

    return [
        'numero' => (string) $row['numero'],
        'fecha' => (string) $row['fecha'],
        'cai' => (string) ($doc['cai'] ?? ''),
        'cai_vto' => (string) ($doc['caiVto'] ?? ''),
        'destinatario' => [
            'razonSocial' => (string) ($cliente['razonSocial'] ?? $cliente['senores'] ?? ''),
            'cuit' => (string) ($cliente['cuit'] ?? ''),
            'domicilio' => trim(implode(' - ', array_filter([(string) ($cliente['domicilio'] ?? ''), (string) ($cliente['localidad'] ?? '')]))),
        ],
        'items' => array_values(array_map(static fn (array $i): array => [
            'articulo' => (string) ($i['articulo'] ?? ''),
            'descripcion' => (string) ($i['descripcion'] ?? ''),
            'cantidad' => (string) ($i['cantidad'] ?? ''),
        ], array_filter((array) ($doc['items'] ?? []), 'is_array'))),
        'lugar_entrega' => (string) ($transporte['lugarEntrega'] ?? ''),
        'observaciones' => (string) ($transporte['observaciones'] ?? ''),
        'conformado' => $row['conformado'],
        'conformado_nombre' => $row['conformado_nombre'],
        'conformado_doc' => $row['conformado_doc'],
        'conformado_obs' => $row['conformado_obs'],
    ];
}

$action = (string) ($body['accion'] ?? 'ver');

if ($action === 'ver') {
    api_respond(200, ['ok' => true, 'remito' => conformidad_public($row)]);
}

if ($action === 'conformar') {
    if ($row['conformado'] !== null) {
        api_error(409, 'La recepción de este remito ya fue confirmada', ['remito' => conformidad_public($row)]);
    }
    $nombre = api_str($body['nombre'] ?? '', 120);
    $documento = preg_replace('/[^0-9A-Za-z]/', '', api_str($body['documento'] ?? '', 20));
    $obs = api_str($body['observaciones'] ?? '', 300);
    if (mb_strlen($nombre) < 3) {
        api_error(422, 'Escribí tu nombre y apellido');
    }
    if (!preg_match('/^(\d{7,8}|\d{11}|[A-Za-z0-9]{6,15})$/', (string) $documento)) {
        api_error(422, 'Escribí tu número de documento (DNI, CUIL o pasaporte)');
    }
    if (!api_bool($body['conforme'] ?? false)) {
        api_error(422, 'Para confirmar tenés que marcar que recibiste los bienes detallados');
    }

    // La condición sobre «conformado IS NULL» evita una doble confirmación simultánea.
    $st = $pdo->prepare(
        'UPDATE remitos SET conformado = NOW(), conformado_nombre = ?, conformado_doc = ?, conformado_obs = ?, conformado_ip = ?, updated = ?
          WHERE id = ? AND conformado IS NULL'
    );
    $st->execute([$nombre, $documento, $obs !== '' ? $obs : null, api_ip(), (int) round(microtime(true) * 1000), $row['id']]);
    if ($st->rowCount() === 0) {
        api_error(409, 'La recepción de este remito ya fue confirmada');
    }
    audit_actor(['id' => null, 'nombre' => 'Destinatario: ' . $nombre]);
    audit_event($pdo, 'Recepción conformada: remito Nº ' . $row['numero'], [
        'categoria' => 'remito',
        'origen' => 'app',
        'detalle' => $nombre . ' · doc. ' . $documento . ($obs !== '' ? ' · ' . mb_substr($obs, 0, 120) : ''),
    ]);
    $st = $pdo->prepare('SELECT * FROM remitos WHERE id = ?');
    $st->execute([$row['id']]);
    api_respond(200, ['ok' => true, 'remito' => conformidad_public($st->fetch())]);
}

api_error(400, 'Acción desconocida');
