<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Eventos de la interfaz que van a la auditoría (impresiones y errores de JavaScript).
 *
 *   POST evento.php { evento, id?, detalle? }   (crear)
 *
 * Eventos admitidos:
 *   remito_impreso       id del remito          «Remito impreso Nº …»
 *   comprobante_impreso  id del comprobante     «Factura A impresa Nº …»
 *   prueba_impresa       —                      Hoja de prueba de calibración.
 *   error_interfaz       detalle (mensaje)      Error de JavaScript no controlado.
 *
 * La interfaz no escribe texto libre en la acción: el servidor la arma con los datos
 * guardados del documento. Hay una cuota por usuario y conexión, y la auditoría se
 * depura por antigüedad (nunca por cantidad), así que un exceso de eventos no puede
 * desplazar registros anteriores.
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
$pdo = api_db($config);
$auth = auth_require($pdo, $config, 'crear', 'app');
$body = api_body();

/** Eventos de la interfaz por usuario (o clave) y conexión cada 10 minutos. */
const EVENT_QUOTA = 60;

$quotaKey = 'u' . ($auth['id'] ?? 0) . ':' . $auth['nombre'];
if (attempts_count($pdo, 'evento', $quotaKey, true, 10) >= EVENT_QUOTA) {
    api_error(429, 'Demasiados eventos en poco tiempo');
}
attempts_record($pdo, 'evento', $quotaKey);

$event = (string) ($body['evento'] ?? '');
$id = (string) ($body['id'] ?? '');

/** Fila de un documento por id (16 hexadecimales), o null. */
$find = static function (string $table) use ($pdo, $id): ?array {
    if (!preg_match('/^[0-9a-f]{16}$/', $id)) {
        return null;
    }
    $st = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
    $st->execute([$id]);

    return $st->fetch() ?: null;
};

switch ($event) {
    case 'remito_impreso':
        $row = $find('remitos');
        if ($row === null) {
            api_error(404, 'Remito no encontrado');
        }
        $authorized = $row['estado'] === 'autorizado';
        audit_event($pdo, $authorized ? 'Remito impreso Nº ' . $row['numero'] : 'Remito impreso SIN AUTORIZAR (borrador ' . $row['referencia'] . ')', [
            'categoria' => 'remito', 'origen' => 'app', 'detalle' => mb_substr((string) $row['cliente'], 0, 120),
        ]);
        break;

    case 'comprobante_impreso':
        $row = $find('comprobantes');
        if ($row === null) {
            api_error(404, 'Comprobante no encontrado');
        }
        $names = ['factura' => 'Factura', 'recibo' => 'Recibo', 'nota' => $row['subtipo'] === 'debito' ? 'Nota de débito' : 'Nota de crédito'];
        $label = ($names[$row['tipo']] ?? 'Comprobante') . ' ' . $row['letra'];
        audit_event($pdo, $label . ' impresa' . ($row['estado'] === 'autorizado'
            ? ' Nº ' . $row['pto_vta'] . '-' . $row['numero']
            : ' SIN AUTORIZAR' . ($row['numero'] ? ' (Nº interno ' . $row['pto_vta'] . '-' . $row['numero'] . ')' : '')), [
            'categoria' => 'comprobante', 'origen' => 'app', 'detalle' => mb_substr((string) $row['cliente'], 0, 120),
        ]);
        break;

    case 'prueba_impresa':
        audit_event($pdo, 'Hoja de prueba de calibración impresa', ['categoria' => 'sistema', 'origen' => 'app']);
        break;

    case 'error_interfaz':
        audit_event($pdo, 'Error en la interfaz', [
            'categoria' => 'error', 'nivel' => 'error', 'origen' => 'app', 'estado' => 500,
            'detalle' => api_str($body['detalle'] ?? '', 255),
        ]);
        break;

    default:
        api_error(400, 'Evento desconocido');
}

api_respond(200, ['ok' => true]);
