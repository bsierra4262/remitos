<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Pruebas unitarias del servidor que no necesitan base de datos: importes
 * (api/lib/importes.php), CUIT (api/lib/http.php), TOTP (api/lib/totp.php) y el
 * detalle que se envía a ARCA (api/lib/arca.php).
 *
 *   php test/unitarias/importes.test.php
 *
 * Usa los mismos casos que calculos.test.js (casos-importes.json).
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$api = dirname(__DIR__, 2) . '/app/api/lib';
require $api . '/http.php';
require $api . '/importes.php';
require $api . '/totp.php';
require $api . '/arca.php';
require $api . '/normativa.php';

$fallas = 0;
$total = 0;

function verificar(bool $condicion, string $nombre): void
{
    global $fallas, $total;
    $total++;
    if (!$condicion) {
        $fallas++;
        echo "  ✗ $nombre\n";
    }
}

$casos = json_decode((string) file_get_contents(__DIR__ . '/casos-importes.json'), true, 512, JSON_THROW_ON_ERROR);

foreach ($casos['numeros'] as [$entrada, $esperado]) {
    $n = importe_numero($entrada);
    verificar($esperado === null ? $n === null : abs($n - $esperado) < 1e-9, "número «{$entrada}»");
}

foreach ($casos['totales'] as $caso) {
    $t = importe_totales($caso['doc']);
    verificar(
        ['neto' => $t['neto'], 'iva' => $t['iva'], 'total' => $t['total']] === $caso['centavos'] && $t['errores'] === [],
        'totales: ' . $caso['nombre'] . ' → ' . json_encode([$t['neto'], $t['iva'], $t['total']])
    );
}
verificar(importe_totales(['letra' => 'A', 'items' => [['cantidad' => '1', 'precio' => 'abc']]])['errores'] !== [], 'importe inválido informado');
verificar(importe_alicuota('19') === null && importe_alicuota('10,5') === '10.5', 'alícuotas admitidas');

foreach ($casos['cuit'] as [$cuit, $valido]) {
    verificar(api_cuit_valid($cuit) === $valido, "CUIT «{$cuit}»");
}
verificar(api_date_dmy('31/02/2026') === '' && api_date_dmy('29/02/2028') === '29/02/2028', 'fechas del calendario');
verificar(api_bool('false') === false && api_bool('sí') === true && api_bool('x', true) === true, 'booleanos');

// RFC 6238, apéndice B (SHA-1, 8 dígitos en el RFC; los 6 últimos son el código de 6 dígitos).
$secreto = totp_base32_encode('12345678901234567890');
verificar(totp_code($secreto, intdiv(59, 30)) === '287082', 'TOTP vector RFC 6238 (T=59)');
verificar(totp_code($secreto, intdiv(1111111109, 30)) === '081804', 'TOTP vector RFC 6238 (T=1111111109)');
$ahora = intdiv(time(), 30);
verificar(totp_verify($secreto, totp_code($secreto, $ahora), null) === $ahora, 'TOTP acepta el código vigente');
verificar(totp_verify($secreto, totp_code($secreto, $ahora), $ahora) === null, 'TOTP no acepta un código ya usado');

// Detalle de FECAESolicitar: importes con punto y dos decimales, y orden del esquema.
$xml = arca_cae_detail([
    'concepto' => 2, 'doc_tipo' => 80, 'doc_nro' => '20123456786', 'numero' => 15, 'fecha' => '20261104',
    'total' => 298764, 'neto' => 246912, 'iva' => 51852, 'exento' => 0, 'no_gravado' => 0,
    'serv_desde' => '20261101', 'serv_hasta' => '20261130', 'vto_pago' => '20261210', 'cond_iva_receptor' => 1,
    'alicuotas' => [['id' => 5, 'base' => 246912, 'importe' => 51852]],
    'asociados' => [['tipo' => 1, 'pv' => 1, 'nro' => 14, 'cuit' => '30711409757', 'fecha' => '']],
]);
verificar(str_contains($xml, '<ar:ImpTotal>2987.64</ar:ImpTotal>') && str_contains($xml, '<ar:ImpIVA>518.52</ar:ImpIVA>'), 'importes para ARCA');
verificar(strpos($xml, 'FchServDesde') < strpos($xml, 'MonId') && strpos($xml, 'CbtesAsoc') < strpos($xml, '<ar:Iva>'), 'orden del esquema WSFEv1');
verificar(!str_contains($xml, '<ar:CbteFch></ar:CbteFch>'), 'fecha del asociado vacía se omite');
verificar(arca_xml('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>') === null, 'XML con DOCTYPE rechazado');

// WSCDC: pedido de constatación de un remito con C.A.I. (importe en cero) y lectura de la
// respuesta de ejemplo del manual de ARCA (validación 108, fecha fuera del rango del C.A.I.).
$pedido = arca_constatar_request(['token' => 'T', 'sign' => 'S', 'cuit' => '30711409757'], '30711409757', 1, 91, 25, '20261105', '75041234567890');
verificar(str_contains($pedido, '<wsf:CbteModo>CAI</wsf:CbteModo>') && str_contains($pedido, '<wsf:CbteTipo>91</wsf:CbteTipo>')
    && str_contains($pedido, '<wsf:ImpTotal>0</wsf:ImpTotal>') && str_contains($pedido, 'xmlns:wsf="http://servicios1.afip.gob.ar/wscdc/"'), 'pedido WSCDC');
$respuesta = arca_xml('<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
    . '<ComprobanteConstatarResponse xmlns="http://servicios1.afip.gob.ar/wscdc/"><ComprobanteConstatarResult>'
    . '<CmpResp><CbteModo>CAI</CbteModo></CmpResp><Resultado>R</Resultado><Observaciones><Obs><Code>108</Code>'
    . '<Msg>La fecha consignada no se encuentra dentro del rango de fechas habilitadas para el CAI ingresado</Msg></Obs>'
    . '</Observaciones><FchProceso>20130912110834</FchProceso></ComprobanteConstatarResult></ComprobanteConstatarResponse>'
    . '</soap:Body></soap:Envelope>');
$r = arca_constatar_result($respuesta);
verificar($r['resultado'] === 'R' && str_starts_with($r['observaciones'][0] ?? '', '[108] La fecha consignada'), 'respuesta WSCDC rechazada');
$ok = arca_constatar_result(arca_xml('<a><Resultado>A</Resultado></a>'));
verificar($ok['resultado'] === 'A' && $ok['observaciones'] === [] && $ok['errores'] === [], 'respuesta WSCDC aprobada');

// Contenido obligatorio del remito (RG 1415, Anexo V).
$completo = [
    'fecha' => '05/11/2026',
    'cliente' => ['razonSocial' => 'X', 'condIVA' => 'Responsable Inscripto', 'cuit' => '20-12345678-6', 'domicilio' => 'Calle 1'],
    'items' => [['descripcion' => 'Bien', 'cantidad' => '1']],
    'transporte' => ['tipo' => 'propio'],
];
verificar(remito_faltantes($completo) === [], 'remito completo');
verificar(remito_faltantes(array_replace_recursive($completo, ['cliente' => ['condIVA' => 'Consumidor Final', 'cuit' => '', 'domicilio' => '']])) === [], 'consumidor final sin CUIT');
verificar(count(remito_faltantes(array_replace_recursive($completo, ['transporte' => ['tipo' => 'tercero']]))) === 3, 'transportista tercero');
verificar(in_array('la cantidad del ítem 1', remito_faltantes(array_replace($completo, ['items' => [['descripcion' => 'Bien']]])), true), 'cantidad obligatoria');

echo $fallas === 0 ? "Pruebas unitarias del servidor: los $total casos pasaron\n" : "$fallas de $total casos fallaron\n";
exit($fallas === 0 ? 0 : 1);
