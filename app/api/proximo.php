<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Próximo número de un comprobante electrónico según ARCA.
 *
 * RG 1415 (Anexo II, ap. A, V.4): la numeración es correlativa e independiente
 * por punto de venta y tipo de comprobante. En la emisión electrónica ARCA exige
 * que el número sea exactamente el último autorizado + 1 (FECompUltimoAutorizado).
 *
 *   GET proximo.php?ambiente=homologacion&tipo=factura&subtipo=&letra=A&pv=1   (leer)
 *       → { ok, numero, ultimo, ptoVenta, cbteTipo, fuente }
 *
 * Si no hay certificado cargado responde 409 con `sin_credenciales`, y la
 * aplicación continúa con su numeración local.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$config = require __DIR__ . '/bootstrap.php';
api_headers('GET, OPTIONS');
$pdo = api_db($config);
auth_require($pdo, $config, 'leer', 'proximo');

$environment = (string) ($_GET['ambiente'] ?? 'homologacion');
if (!arca_is_environment($environment)) {
    api_error(400, 'Ambiente inválido (homologacion o produccion)');
}
$type = (string) ($_GET['tipo'] ?? 'factura');
if (!in_array($type, ['factura', 'recibo', 'nota'], true)) {
    api_error(400, 'Tipo de comprobante inválido');
}
$subtype = ($_GET['subtipo'] ?? '') === 'debito' ? 'debito' : 'credito';
$letter = strtoupper((string) ($_GET['letra'] ?? ''));
$pointOfSale = (int) preg_replace('/\D/', '', (string) ($_GET['pv'] ?? ''));
if ($pointOfSale < 1 || $pointOfSale > 99998) {
    api_error(400, 'Punto de venta inválido (1 a 99998)');
}

$cbteTipo = arca_cbte_tipo($type, $subtype, $letter);
if ($cbteTipo === null) {
    api_error(422, 'Los comprobantes con letra ' . ($letter ?: '—') . ' no se numeran en ARCA');
}
if (arca_credentials_status($pdo, $environment) === null) {
    api_error(409, 'No hay un certificado de ARCA cargado para ' . ARCA_ENVIRONMENT_NAMES[$environment], [
        'sin_credenciales' => true,
    ]);
}

try {
    $last = arca_last_authorized($pdo, $config, $environment, $pointOfSale, $cbteTipo);
} catch (ArcaException $e) {
    api_error(502, $e->getMessage());
}

api_respond(200, [
    'ok' => true,
    'ambiente' => $environment,
    'ptoVenta' => str_pad((string) $pointOfSale, 5, '0', STR_PAD_LEFT),
    'cbteTipo' => $cbteTipo,
    'ultimo' => str_pad((string) $last, 8, '0', STR_PAD_LEFT),
    'numero' => str_pad((string) ($last + 1), 8, '0', STR_PAD_LEFT),
    'fuente' => 'ARCA',
]);
