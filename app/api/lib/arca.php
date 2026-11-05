<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Integración con los web services de ARCA:
 *  - Validación y resguardo cifrado del certificado y la clave privada.
 *  - WSAA: firma del pedido de acceso (CMS/PKCS#7) y obtención del ticket.
 *  - WSFEv1: estado del servicio y último comprobante autorizado.
 *
 * La clave privada nunca sale del servidor: se recibe una sola vez por HTTPS,
 * se valida contra el certificado y se guarda cifrada. Las firmas se generan acá.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

const ARCA_ENVIRONMENTS = ['homologacion', 'produccion'];

const ARCA_ENVIRONMENT_NAMES = ['homologacion' => 'homologación', 'produccion' => 'producción'];

const ARCA_WSAA_URL = [
    'homologacion' => 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms',
    'produccion' => 'https://wsaa.afip.gov.ar/ws/services/LoginCms',
];

const ARCA_WSFE_URL = [
    'homologacion' => 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx',
    'produccion' => 'https://servicios1.afip.gov.ar/wsfev1/service.asmx',
];

const ARCA_WSFE_NS = 'http://ar.gov.afip.dif.FEV1/';

/** Tamaño máximo aceptado para cada archivo PEM. */
const ARCA_PEM_MAX = 16384;

/** Margen con el que se renueva el ticket de acceso antes de su vencimiento. */
const ARCA_TICKET_MARGIN_SECONDS = 300;

/** Tipos de comprobante de WSFEv1 (tabla de comprobantes de ARCA), por familia y letra. */
const ARCA_CBTE_TIPOS = [
    'factura' => ['A' => 1, 'B' => 6, 'C' => 11],
    'nd' => ['A' => 2, 'B' => 7, 'C' => 12],
    'nc' => ['A' => 3, 'B' => 8, 'C' => 13],
    'recibo' => ['A' => 4, 'B' => 9, 'C' => 15],
];

/** Error informado por ARCA (falla del servicio o rechazo de la operación). */
final class ArcaException extends RuntimeException
{
}

/** ¿Es un ambiente válido? */
function arca_is_environment(mixed $environment): bool
{
    return is_string($environment) && in_array($environment, ARCA_ENVIRONMENTS, true);
}

/**
 * Tipo de comprobante de WSFEv1 para un documento de la aplicación.
 *
 * @param string $type    factura | recibo | nota
 * @param string $subtype credito | debito (solo notas)
 */
function arca_cbte_tipo(string $type, string $subtype, string $letter): ?int
{
    $family = match ($type) {
        'recibo' => 'recibo',
        'nota' => $subtype === 'debito' ? 'nd' : 'nc',
        default => 'factura',
    };

    return ARCA_CBTE_TIPOS[$family][strtoupper($letter)] ?? null;
}

/**
 * Valida un par certificado / clave privada y devuelve sus datos.
 *
 * Comprueba que ambos sean PEM válidos, que la clave corresponda al certificado,
 * que el certificado esté vigente y que identifique un CUIT. Si la clave viene
 * protegida con contraseña, se devuelve ya descifrada para guardarla cifrada
 * con la clave de la aplicación.
 *
 * @return array{cuit: string, titular: string, emisor: string, serie: string, huella: string,
 *               valido_desde: string, valido_hasta: string, certificado: string, clave: string}
 */
function arca_inspect(string $certPem, string $keyPem, string $passphrase = ''): array
{
    $certPem = trim($certPem);
    $keyPem = trim($keyPem);
    if ($certPem === '' || $keyPem === '') {
        throw new InvalidArgumentException('Faltan el certificado o la clave privada');
    }
    if (strlen($certPem) > ARCA_PEM_MAX || strlen($keyPem) > ARCA_PEM_MAX) {
        throw new InvalidArgumentException('El certificado o la clave privada superan el tamaño esperado');
    }

    $cert = @openssl_x509_read($certPem);
    if ($cert === false) {
        throw new InvalidArgumentException(
            'El certificado no es un PEM X.509 válido (debe comenzar con -----BEGIN CERTIFICATE-----)'
        );
    }
    $key = @openssl_pkey_get_private($keyPem, $passphrase !== '' ? $passphrase : null);
    if ($key === false) {
        throw new InvalidArgumentException(
            str_contains($keyPem, 'ENCRYPTED')
                ? 'La clave privada está protegida y la contraseña no es correcta'
                : 'La clave privada no es un PEM válido (debe comenzar con -----BEGIN PRIVATE KEY-----)'
        );
    }
    if (!openssl_x509_check_private_key($cert, $key)) {
        throw new InvalidArgumentException('La clave privada no corresponde a este certificado');
    }
    $details = openssl_pkey_get_details($key);
    if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
        throw new InvalidArgumentException('La clave privada debe ser RSA (ARCA no acepta otros tipos)');
    }
    if (($details['bits'] ?? 0) < 2048) {
        throw new InvalidArgumentException('La clave privada debe ser RSA de 2048 bits o más');
    }

    $info = openssl_x509_parse($cert);
    $from = (int) ($info['validFrom_time_t'] ?? 0);
    $to = (int) ($info['validTo_time_t'] ?? 0);
    if ($to <= time()) {
        throw new InvalidArgumentException('El certificado venció el ' . date('d/m/Y', $to));
    }

    $cuit = preg_replace('/\D/', '', arca_dn_value($info['subject'] ?? [], 'serialNumber'));
    if (strlen($cuit) !== 11) {
        throw new InvalidArgumentException('El certificado no indica un CUIT (campo serialNumber del titular)');
    }

    if (!openssl_x509_export($cert, $certOut) || !openssl_pkey_export($key, $keyOut)) {
        throw new RuntimeException('No se pudo procesar el certificado: ' . (openssl_error_string() ?: 'error de OpenSSL'));
    }

    return [
        'cuit' => $cuit,
        'titular' => mb_substr(arca_dn_value($info['subject'] ?? [], 'CN'), 0, 160),
        'emisor' => mb_substr(arca_dn_value($info['issuer'] ?? [], 'CN'), 0, 160),
        'serie' => mb_substr((string) ($info['serialNumberHex'] ?? $info['serialNumber'] ?? ''), 0, 64),
        'huella' => (string) openssl_x509_fingerprint($cert, 'sha256'),
        'valido_desde' => date('Y-m-d H:i:s', $from),
        'valido_hasta' => date('Y-m-d H:i:s', $to),
        'certificado' => $certOut,
        'clave' => $keyOut,
    ];
}

/** Valor de un atributo de un nombre distinguido (puede venir repetido). */
function arca_dn_value(array $dn, string $field): string
{
    $value = $dn[$field] ?? '';

    return is_array($value) ? implode(', ', $value) : (string) $value;
}

/**
 * Guarda (o reemplaza) las credenciales de un ambiente, cifradas.
 *
 * @param array<string, string> $data Resultado de arca_inspect().
 */
function arca_save_credentials(PDO $pdo, array $config, string $environment, array $data, ?string $by): void
{
    $pdo->prepare(
        'INSERT INTO arca_credenciales
            (ambiente, cuit, titular, emisor, serie, huella, valido_desde, valido_hasta,
             certificado, clave, cargado, cargado_por)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
         ON DUPLICATE KEY UPDATE
            cuit = VALUES(cuit), titular = VALUES(titular), emisor = VALUES(emisor),
            serie = VALUES(serie), huella = VALUES(huella), valido_desde = VALUES(valido_desde),
            valido_hasta = VALUES(valido_hasta), certificado = VALUES(certificado),
            clave = VALUES(clave), cargado = VALUES(cargado), cargado_por = VALUES(cargado_por)'
    )->execute([
        $environment,
        $data['cuit'],
        $data['titular'],
        $data['emisor'],
        $data['serie'],
        $data['huella'],
        $data['valido_desde'],
        $data['valido_hasta'],
        crypto_encrypt($data['certificado'], $config, 'arca:' . $environment . ':certificado'),
        crypto_encrypt($data['clave'], $config, 'arca:' . $environment . ':clave'),
        $by,
    ]);
    // Un certificado nuevo invalida los tickets obtenidos con el anterior.
    $pdo->prepare('DELETE FROM arca_tickets WHERE ambiente = ?')->execute([$environment]);
}

/** Elimina las credenciales (y los tickets) de un ambiente. Devuelve si había algo cargado. */
function arca_delete_credentials(PDO $pdo, string $environment): bool
{
    $st = $pdo->prepare('DELETE FROM arca_credenciales WHERE ambiente = ?');
    $st->execute([$environment]);
    $pdo->prepare('DELETE FROM arca_tickets WHERE ambiente = ?')->execute([$environment]);

    return $st->rowCount() > 0;
}

/**
 * Datos públicos de las credenciales de un ambiente (nunca el certificado ni la clave).
 *
 * @return array<string, mixed>|null
 */
function arca_credentials_status(PDO $pdo, string $environment): ?array
{
    $st = $pdo->prepare(
        'SELECT cuit, titular, emisor, serie, huella, valido_desde, valido_hasta, cargado, cargado_por
           FROM arca_credenciales WHERE ambiente = ?'
    );
    $st->execute([$environment]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    $days = (int) floor((strtotime((string) $row['valido_hasta']) - time()) / 86400);

    return [
        'cuit' => $row['cuit'],
        'titular' => $row['titular'],
        'emisor' => $row['emisor'],
        'serie' => $row['serie'],
        'huella' => $row['huella'],
        'valido_desde' => $row['valido_desde'],
        'valido_hasta' => $row['valido_hasta'],
        'dias_para_vencer' => $days,
        'vencido' => $days < 0,
        'cargado' => $row['cargado'],
        'cargado_por' => $row['cargado_por'],
    ];
}

/**
 * Credenciales descifradas, listas para firmar.
 *
 * @return array{cuit: string, certificado: string, clave: string}|null
 */
function arca_load_credentials(PDO $pdo, array $config, string $environment): ?array
{
    $st = $pdo->prepare('SELECT cuit, certificado, clave FROM arca_credenciales WHERE ambiente = ?');
    $st->execute([$environment]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }

    return [
        'cuit' => (string) $row['cuit'],
        'certificado' => crypto_decrypt((string) $row['certificado'], $config, 'arca:' . $environment . ':certificado'),
        'clave' => crypto_decrypt((string) $row['clave'], $config, 'arca:' . $environment . ':clave'),
    ];
}

/**
 * Ticket de acceso (token y firma) para un servicio, reutilizando el vigente.
 *
 * ARCA otorga un ticket por certificado y servicio con validez de hasta 12 horas
 * y rechaza pedir otro mientras el anterior siga vigente, por eso se guarda.
 *
 * @return array{token: string, sign: string, cuit: string, expira: string}
 */
function arca_ticket(PDO $pdo, array $config, string $environment, string $service = 'wsfe'): array
{
    $credentials = arca_load_credentials($pdo, $config, $environment);
    if ($credentials === null) {
        throw new ArcaException(
            'No hay un certificado de ARCA cargado para ' . ARCA_ENVIRONMENT_NAMES[$environment]
        );
    }

    $cached = arca_cached_ticket($pdo, $config, $environment, $service, $credentials['cuit']);
    if ($cached !== null) {
        return $cached;
    }

    // Un solo pedido de ticket a la vez: ARCA rechaza un segundo LoginCms mientras el
    // primero sigue vigente (alreadyAuthenticated).
    $lock = 'remitos_wsaa_' . $environment . '_' . $service;
    api_lock($pdo, $lock, 30);
    try {
        $cached = arca_cached_ticket($pdo, $config, $environment, $service, $credentials['cuit']);

        return $cached ?? arca_request_ticket($pdo, $config, $environment, $service, $credentials);
    } finally {
        api_unlock($pdo, $lock);
    }
}

/** Ticket vigente guardado, o null. */
function arca_cached_ticket(PDO $pdo, array $config, string $environment, string $service, string $cuit): ?array
{
    $st = $pdo->prepare(
        'SELECT token, sign, expira FROM arca_tickets
          WHERE ambiente = ? AND servicio = ? AND expira > NOW() + INTERVAL ' . ARCA_TICKET_MARGIN_SECONDS . ' SECOND'
    );
    $st->execute([$environment, $service]);
    $cached = $st->fetch();
    if (!$cached) {
        return null;
    }

    return [
        'token' => crypto_decrypt((string) $cached['token'], $config, 'arca:' . $environment . ':' . $service . ':token'),
        'sign' => crypto_decrypt((string) $cached['sign'], $config, 'arca:' . $environment . ':' . $service . ':sign'),
        'cuit' => $cuit,
        'expira' => (string) $cached['expira'],
    ];
}

/**
 * Pide un ticket nuevo a WSAA y lo guarda cifrado.
 *
 * @param array{cuit: string, certificado: string, clave: string} $credentials
 */
function arca_request_ticket(PDO $pdo, array $config, string $environment, string $service, array $credentials): array
{

    $cms = arca_sign_cms(arca_login_request($service), $credentials['certificado'], $credentials['clave']);
    $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"'
        . ' xmlns:wsaa="http://wsaa.view.sua.dvadac.desein.afip.gov">'
        . '<soapenv:Header/><soapenv:Body><wsaa:loginCms><wsaa:in0>' . $cms . '</wsaa:in0></wsaa:loginCms>'
        . '</soapenv:Body></soapenv:Envelope>';

    $response = arca_soap(ARCA_WSAA_URL[$environment], $envelope, '');
    $return = $response->getElementsByTagName('loginCmsReturn')->item(0);
    if ($return === null) {
        throw new ArcaException('ARCA no devolvió el ticket de acceso');
    }

    $ticket = arca_xml($return->textContent);
    if ($ticket === null) {
        throw new ArcaException('El ticket de acceso de ARCA no tiene el formato esperado');
    }
    $token = (string) $ticket->getElementsByTagName('token')->item(0)?->textContent;
    $sign = (string) $ticket->getElementsByTagName('sign')->item(0)?->textContent;
    $expiration = strtotime((string) $ticket->getElementsByTagName('expirationTime')->item(0)?->textContent);
    if ($token === '' || $sign === '' || $expiration === false) {
        throw new ArcaException('El ticket de acceso de ARCA está incompleto');
    }
    $expires = date('Y-m-d H:i:s', $expiration);

    $pdo->prepare(
        'INSERT INTO arca_tickets (ambiente, servicio, token, sign, expira) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE token = VALUES(token), sign = VALUES(sign), expira = VALUES(expira)'
    )->execute([
        $environment,
        $service,
        crypto_encrypt($token, $config, 'arca:' . $environment . ':' . $service . ':token'),
        crypto_encrypt($sign, $config, 'arca:' . $environment . ':' . $service . ':sign'),
        $expires,
    ]);

    return ['token' => $token, 'sign' => $sign, 'cuit' => $credentials['cuit'], 'expira' => $expires];
}

/** Pedido de acceso (TRA) para un servicio, válido por unos minutos alrededor de ahora. */
function arca_login_request(string $service): string
{
    $now = time();

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<loginTicketRequest version="1.0"><header>'
        . '<uniqueId>' . $now . '</uniqueId>'
        . '<generationTime>' . date('c', $now - 600) . '</generationTime>'
        . '<expirationTime>' . date('c', $now + 600) . '</expirationTime>'
        . '</header><service>' . htmlspecialchars($service, ENT_XML1) . '</service></loginTicketRequest>';
}

/**
 * Firma el pedido de acceso y devuelve el CMS en base64, como lo espera WSAA.
 *
 * openssl_pkcs7_sign() trabaja con archivos: se usan temporales que contienen
 * solo el pedido y el resultado firmado (la clave privada se pasa en memoria)
 * y se eliminan siempre al terminar.
 */
function arca_sign_cms(string $request, string $certPem, string $keyPem): string
{
    $dir = sys_get_temp_dir();
    $input = tempnam($dir, 'tra');
    $output = tempnam($dir, 'cms');
    if ($input === false || $output === false) {
        throw new RuntimeException('No se pudieron crear los archivos temporales para firmar');
    }

    try {
        @chmod($input, 0600);
        @chmod($output, 0600);
        file_put_contents($input, $request);
        // Firma con el contenido incluido (no separada), que es lo que requiere WSAA.
        if (!openssl_pkcs7_sign($input, $output, $certPem, $keyPem, [], 0)) {
            throw new RuntimeException('No se pudo firmar el pedido de acceso con el certificado cargado');
        }
        $smime = (string) file_get_contents($output);
    } finally {
        @unlink($input);
        @unlink($output);
    }

    // El resultado es un mensaje S/MIME: cabeceras, línea en blanco y el CMS en base64.
    $parts = preg_split('/\r?\n\r?\n/', $smime, 2);

    return preg_replace('/\s+/', '', $parts[1] ?? '');
}

/** Envía un mensaje SOAP y devuelve la respuesta. Las fallas SOAP se convierten en ArcaException. */
function arca_soap(string $url, string $envelope, string $action): DOMDocument
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $envelope,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "' . $action . '"',
        ],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $body === '') {
        error_log('[remitos] ARCA sin respuesta (' . $url . '): ' . $error);
        throw new ArcaException('No se pudo conectar con ARCA. Volvé a intentar en unos minutos.');
    }

    $doc = arca_xml((string) $body);
    if ($doc === null) {
        error_log('[remitos] ARCA respondió HTTP ' . $status . ' con contenido no XML');
        throw new ArcaException('ARCA devolvió una respuesta inesperada (HTTP ' . $status . ')');
    }

    $fault = $doc->getElementsByTagName('faultstring')->item(0);
    if ($fault !== null) {
        $code = (string) $doc->getElementsByTagName('faultcode')->item(0)?->textContent;
        error_log('[remitos] Falla SOAP de ARCA: ' . $code . ' ' . trim($fault->textContent));
        throw new ArcaException(arca_fault_message($code, trim($fault->textContent)));
    }

    return $doc;
}

/**
 * Interpreta XML recibido de ARCA sin acceso a red ni definiciones de tipo de
 * documento (evita XXE y la resolución de entidades externas).
 */
function arca_xml(string $xml): ?DOMDocument
{
    if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
        return null;
    }
    $doc = new DOMDocument();

    return @$doc->loadXML($xml, LIBXML_NONET) ? $doc : null;
}

/** Traduce las fallas más frecuentes de WSAA a un mensaje accionable. */
function arca_fault_message(string $code, string $message): string
{
    $known = [
        'alreadyAuthenticated' => 'ARCA ya otorgó un ticket vigente a este certificado. Se podrá pedir uno nuevo '
            . 'cuando venza (hasta 12 horas).',
        'cms.cert.untrusted' => 'ARCA no reconoce el certificado en este ambiente: verificá que sea el emitido '
            . 'para homologación o para producción, según corresponda.',
        'cms.cert.expired' => 'ARCA informa que el certificado está vencido.',
        'notAuthorized' => 'El certificado no tiene autorizado el servicio de facturación electrónica (wsfe). '
            . 'Hay que asociarlo desde el Administrador de Relaciones de ARCA.',
        'generationTime' => 'ARCA rechazó el pedido por la hora del servidor. Volvé a intentar en unos minutos.',
    ];
    foreach ($known as $needle => $text) {
        if (str_contains($code, $needle) || str_contains($message, $needle)) {
            return $text;
        }
    }

    return 'ARCA rechazó el pedido' . ($code !== '' ? ' (' . preg_replace('/[^\w.:-]/', '', $code) . ')' : '') . '.';
}

/** Ejecuta una operación de WSFEv1. */
function arca_wsfe(string $environment, string $operation, string $innerXml): DOMDocument
{
    $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ar="' . ARCA_WSFE_NS . '">'
        . '<soap:Body><ar:' . $operation . '>' . $innerXml . '</ar:' . $operation . '></soap:Body></soap:Envelope>';

    return arca_soap(ARCA_WSFE_URL[$environment], $envelope, ARCA_WSFE_NS . $operation);
}

/** Primer valor de un elemento de WSFEv1 en la respuesta. */
function arca_wsfe_value(DOMDocument $doc, string $name): string
{
    return trim((string) $doc->getElementsByTagNameNS(ARCA_WSFE_NS, $name)->item(0)?->textContent);
}

/** Lanza una excepción si la respuesta de WSFEv1 trae errores. */
function arca_wsfe_assert(DOMDocument $doc): void
{
    $errors = $doc->getElementsByTagNameNS(ARCA_WSFE_NS, 'Err');
    if ($errors->length === 0) {
        return;
    }
    $messages = [];
    foreach ($errors as $error) {
        $code = trim((string) $error->getElementsByTagNameNS(ARCA_WSFE_NS, 'Code')->item(0)?->textContent);
        $msg = trim((string) $error->getElementsByTagNameNS(ARCA_WSFE_NS, 'Msg')->item(0)?->textContent);
        $messages[] = ($code !== '' ? '[' . $code . '] ' : '') . $msg;
    }
    throw new ArcaException('ARCA: ' . implode(' · ', $messages));
}

/**
 * Estado de los servidores de WSFEv1 (no requiere autenticación).
 *
 * @return array{app: string, db: string, auth: string}
 */
function arca_fe_dummy(string $environment): array
{
    $doc = arca_wsfe($environment, 'FEDummy', '');

    return [
        'app' => arca_wsfe_value($doc, 'AppServer'),
        'db' => arca_wsfe_value($doc, 'DbServer'),
        'auth' => arca_wsfe_value($doc, 'AuthServer'),
    ];
}

/** Bloque de autenticación de WSFEv1. */
function arca_wsfe_auth(array $ticket): string
{
    $x = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    return '<ar:Auth><ar:Token>' . $x($ticket['token']) . '</ar:Token>'
        . '<ar:Sign>' . $x($ticket['sign']) . '</ar:Sign>'
        . '<ar:Cuit>' . $x($ticket['cuit']) . '</ar:Cuit></ar:Auth>';
}

/** Último número autorizado por ARCA para un punto de venta y tipo de comprobante. */
function arca_last_authorized(PDO $pdo, array $config, string $environment, int $pointOfSale, int $cbteTipo): int
{
    $ticket = arca_ticket($pdo, $config, $environment);
    $doc = arca_wsfe(
        $environment,
        'FECompUltimoAutorizado',
        arca_wsfe_auth($ticket) . '<ar:PtoVta>' . $pointOfSale . '</ar:PtoVta><ar:CbteTipo>' . $cbteTipo . '</ar:CbteTipo>'
    );
    arca_wsfe_assert($doc);

    return (int) arca_wsfe_value($doc, 'CbteNro');
}

/** Importe con dos decimales y punto, como lo espera WSFEv1 (a partir de centavos). */
function arca_amount(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

/**
 * Detalle de un comprobante para FECAESolicitar, a partir de datos ya validados. El
 * orden de los elementos sigue el esquema de WSFEv1.
 *
 * @param array{concepto: int, doc_tipo: int, doc_nro: string, numero: int, fecha: string, total: int, neto: int,
 *              iva: int, exento: int, no_gravado: int, serv_desde?: string, serv_hasta?: string, vto_pago?: string,
 *              cond_iva_receptor: int, alicuotas: array<int, array{id: int, base: int, importe: int}>,
 *              asociados?: array<int, array{tipo: int, pv: int, nro: int, cuit: string, fecha: string}>} $c
 */
function arca_cae_detail(array $c): string
{
    $x = static fn (string|int $v): string => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $xml = '<ar:FECAEDetRequest>'
        . '<ar:Concepto>' . $c['concepto'] . '</ar:Concepto>'
        . '<ar:DocTipo>' . $c['doc_tipo'] . '</ar:DocTipo>'
        . '<ar:DocNro>' . $x($c['doc_nro']) . '</ar:DocNro>'
        . '<ar:CbteDesde>' . $c['numero'] . '</ar:CbteDesde>'
        . '<ar:CbteHasta>' . $c['numero'] . '</ar:CbteHasta>'
        . '<ar:CbteFch>' . $x($c['fecha']) . '</ar:CbteFch>'
        . '<ar:ImpTotal>' . arca_amount($c['total']) . '</ar:ImpTotal>'
        . '<ar:ImpTotConc>' . arca_amount($c['no_gravado']) . '</ar:ImpTotConc>'
        . '<ar:ImpNeto>' . arca_amount($c['neto']) . '</ar:ImpNeto>'
        . '<ar:ImpOpEx>' . arca_amount($c['exento']) . '</ar:ImpOpEx>'
        . '<ar:ImpTrib>0.00</ar:ImpTrib>'
        . '<ar:ImpIVA>' . arca_amount($c['iva']) . '</ar:ImpIVA>';
    if ($c['concepto'] !== 1) {
        $xml .= '<ar:FchServDesde>' . $x($c['serv_desde'] ?? '') . '</ar:FchServDesde>'
            . '<ar:FchServHasta>' . $x($c['serv_hasta'] ?? '') . '</ar:FchServHasta>'
            . '<ar:FchVtoPago>' . $x($c['vto_pago'] ?? '') . '</ar:FchVtoPago>';
    }
    $xml .= '<ar:MonId>PES</ar:MonId><ar:MonCotiz>1</ar:MonCotiz>'
        . '<ar:CondicionIVAReceptorId>' . $c['cond_iva_receptor'] . '</ar:CondicionIVAReceptorId>';
    if (!empty($c['asociados'])) {
        $xml .= '<ar:CbtesAsoc>';
        foreach ($c['asociados'] as $a) {
            $xml .= '<ar:CbteAsoc><ar:Tipo>' . $a['tipo'] . '</ar:Tipo><ar:PtoVta>' . $a['pv'] . '</ar:PtoVta>'
                . '<ar:Nro>' . $a['nro'] . '</ar:Nro>'
                . (($a['cuit'] ?? '') !== '' ? '<ar:Cuit>' . $x($a['cuit']) . '</ar:Cuit>' : '')
                . (($a['fecha'] ?? '') !== '' ? '<ar:CbteFch>' . $x($a['fecha']) . '</ar:CbteFch>' : '')
                . '</ar:CbteAsoc>';
        }
        $xml .= '</ar:CbtesAsoc>';
    }
    if (!empty($c['alicuotas'])) {
        $xml .= '<ar:Iva>';
        foreach ($c['alicuotas'] as $a) {
            $xml .= '<ar:AlicIva><ar:Id>' . $a['id'] . '</ar:Id><ar:BaseImp>' . arca_amount($a['base']) . '</ar:BaseImp>'
                . '<ar:Importe>' . arca_amount($a['importe']) . '</ar:Importe></ar:AlicIva>';
        }
        $xml .= '</ar:Iva>';
    }

    return $xml . '</ar:FECAEDetRequest>';
}

/** Observaciones o errores de una respuesta de WSFEv1 («[código] mensaje»). */
function arca_wsfe_messages(DOMDocument $doc, string $container, string $item): array
{
    $out = [];
    foreach ($doc->getElementsByTagNameNS(ARCA_WSFE_NS, $container) as $node) {
        foreach ($node->getElementsByTagNameNS(ARCA_WSFE_NS, $item) as $entry) {
            $code = trim((string) $entry->getElementsByTagNameNS(ARCA_WSFE_NS, 'Code')->item(0)?->textContent);
            $msg = trim((string) $entry->getElementsByTagNameNS(ARCA_WSFE_NS, 'Msg')->item(0)?->textContent);
            $out[] = ($code !== '' ? '[' . $code . '] ' : '') . mb_substr($msg, 0, 300);
        }
    }

    return $out;
}

/**
 * Solicita el CAE de un comprobante (FECAESolicitar).
 *
 * @return array{resultado: string, cae: string, vencimiento: string, observaciones: string[], errores: string[]}
 */
function arca_cae_request(PDO $pdo, array $config, string $environment, int $pointOfSale, int $cbteTipo, array $detail): array
{
    $ticket = arca_ticket($pdo, $config, $environment);
    $doc = arca_wsfe(
        $environment,
        'FECAESolicitar',
        arca_wsfe_auth($ticket)
            . '<ar:FeCAEReq><ar:FeCabReq><ar:CantReg>1</ar:CantReg><ar:PtoVta>' . $pointOfSale . '</ar:PtoVta>'
            . '<ar:CbteTipo>' . $cbteTipo . '</ar:CbteTipo></ar:FeCabReq>'
            . '<ar:FeDetReq>' . arca_cae_detail($detail) . '</ar:FeDetReq></ar:FeCAEReq>'
    );

    $resultado = '';
    $det = $doc->getElementsByTagNameNS(ARCA_WSFE_NS, 'FECAEDetResponse')->item(0);
    if ($det !== null) {
        $resultado = trim((string) $det->getElementsByTagNameNS(ARCA_WSFE_NS, 'Resultado')->item(0)?->textContent);
    }

    return [
        'resultado' => $resultado !== '' ? $resultado : arca_wsfe_value($doc, 'Resultado'),
        'cae' => arca_wsfe_value($doc, 'CAE'),
        'vencimiento' => arca_wsfe_value($doc, 'CAEFchVto'),
        'observaciones' => arca_wsfe_messages($doc, 'Observaciones', 'Obs'),
        'errores' => arca_wsfe_messages($doc, 'Errors', 'Err'),
    ];
}

/**
 * Consulta un comprobante ya autorizado (FECompConsultar). Sirve para recuperar el CAE
 * si la respuesta de FECAESolicitar se perdió por un corte de conexión.
 *
 * @return array{cae: string, vencimiento: string, total: string, doc_nro: string, fecha: string}|null
 */
function arca_cae_lookup(PDO $pdo, array $config, string $environment, int $pointOfSale, int $cbteTipo, int $number): ?array
{
    $ticket = arca_ticket($pdo, $config, $environment);
    $doc = arca_wsfe(
        $environment,
        'FECompConsultar',
        arca_wsfe_auth($ticket) . '<ar:FeCompConsReq><ar:CbteTipo>' . $cbteTipo . '</ar:CbteTipo>'
            . '<ar:CbteNro>' . $number . '</ar:CbteNro><ar:PtoVta>' . $pointOfSale . '</ar:PtoVta></ar:FeCompConsReq>'
    );
    $cae = arca_wsfe_value($doc, 'CodAutorizacion');
    if ($cae === '') {
        return null;
    }

    return [
        'cae' => $cae,
        'vencimiento' => arca_wsfe_value($doc, 'FchVto'),
        'total' => arca_wsfe_value($doc, 'ImpTotal'),
        'doc_nro' => arca_wsfe_value($doc, 'DocNro'),
        'fecha' => arca_wsfe_value($doc, 'CbteFch'),
    ];
}
