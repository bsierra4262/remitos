<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Respuestas JSON, cabeceras, lectura del cuerpo y validaciones comunes
 * (fechas, CUIT, booleanos) de las peticiones.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/** Tamaño máximo aceptado para un cuerpo JSON (certificados incluidos). */
const API_MAX_BODY = 262144;

/**
 * Respuesta lista para enviar. Se lanza como excepción para que los bloques
 * `finally` (liberación de bloqueos, transacciones) siempre se ejecuten antes de
 * responder; el manejador de bootstrap.php la envía.
 */
final class ApiResponse extends RuntimeException
{
    /** @param array<string, mixed> $data */
    public function __construct(public readonly int $status, public readonly array $data)
    {
        parent::__construct('respuesta HTTP ' . $status);
    }
}

/** Configuración de la aplicación, disponible para las librerías. */
function api_config(?array $config = null): array
{
    static $current = [];
    if ($config !== null) {
        $current = $config;
    }

    return $current;
}

/**
 * Envía las cabeceras comunes y resuelve el preflight de CORS.
 *
 * La aplicación consume la API desde su propio origen, por lo que CORS solo se
 * habilita para los orígenes listados en `allowed_origins` (config.php). Las
 * integraciones de servidor a servidor no necesitan CORS.
 */
function api_headers(string $methods = 'GET, OPTIONS'): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');

    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $allowed = (array) (api_config()['allowed_origins'] ?? []);
    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: ' . $methods);
        header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
        header('Access-Control-Max-Age: 600');
    }

    if (api_method() === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/** Método HTTP de la petición, en mayúsculas. */
function api_method(): string
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

/**
 * Responde con JSON. Lanza ApiResponse: el envío lo hace bootstrap.php.
 *
 * @param array<string, mixed> $data
 */
function api_respond(int $code, array $data): never
{
    throw new ApiResponse($code, $data);
}

/** Envía una respuesta (lo usa el manejador de bootstrap.php). */
function api_send(ApiResponse $response): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($response->status);
    }
    echo json_encode($response->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

/** Atajo para responder un error con el formato estándar de la API. */
function api_error(int $code, string $message, array $extra = []): never
{
    api_respond($code, ['ok' => false, 'error' => $message] + $extra);
}

/**
 * Devuelve el cuerpo JSON de la petición como arreglo asociativo.
 *
 * @return array<string, mixed>
 */
function api_body(): array
{
    static $body = null;
    if ($body !== null) {
        return $body;
    }
    $raw = file_get_contents('php://input', false, null, 0, API_MAX_BODY + 1);
    if ($raw === false || $raw === '') {
        api_error(400, 'El cuerpo de la petición está vacío');
    }
    if (strlen($raw) > API_MAX_BODY) {
        api_error(413, 'El cuerpo de la petición es demasiado grande');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        api_error(400, 'El cuerpo debe ser JSON válido');
    }

    return $body = $data;
}

/** Recorta un texto a una longitud máxima (en caracteres) y le quita los espacios extremos. */
function api_str(mixed $value, int $max): string
{
    if (!is_scalar($value)) {
        return '';
    }

    return mb_substr(trim((string) $value), 0, $max);
}

/** Valor booleano estricto: true/false, 1/0, "true"/"false", "si"/"no"; cualquier otro valor da $default. */
function api_bool(mixed $value, bool $default = false): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_string($value) && in_array(strtolower(trim($value)), ['si', 'sí'], true)) {
        return true;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    return $parsed ?? $default;
}

/** Fecha en formato dd/mm/aaaa válida en el calendario, o cadena vacía. */
function api_date_dmy(mixed $value): string
{
    $text = api_str($value, 10);
    if (!preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $text, $m) || !checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
        return '';
    }

    return $text;
}

/** dd/mm/aaaa → aaaa-mm-dd (o null si la fecha no es válida). */
function api_date_iso(string $dmy): ?string
{
    $valid = api_date_dmy($dmy);

    return $valid === '' ? null : substr($valid, 6, 4) . '-' . substr($valid, 3, 2) . '-' . substr($valid, 0, 2);
}

/** ¿Es un CUIT/CUIL de 11 dígitos con dígito verificador correcto (módulo 11)? */
function api_cuit_valid(string $value): bool
{
    $digits = preg_replace('/\D/', '', $value);
    if (strlen($digits) !== 11) {
        return false;
    }
    $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    $sum = 0;
    foreach ($weights as $i => $w) {
        $sum += (int) $digits[$i] * $w;
    }
    $check = 11 - ($sum % 11);
    $check = $check === 11 ? 0 : ($check === 10 ? 9 : $check);

    return $check === (int) $digits[10];
}

/** CUIT con guiones (99-99999999-9); el valor se devuelve recortado si no tiene 11 dígitos. */
function api_cuit_format(string $value): string
{
    $digits = preg_replace('/\D/', '', $value);
    if (strlen($digits) === 11) {
        return substr($digits, 0, 2) . '-' . substr($digits, 2, 8) . '-' . substr($digits, 10);
    }

    return mb_substr(trim($value), 0, 13);
}

/** Exige un CUIT válido o vacío; responde 422 si no lo es. */
function api_require_cuit(string $value, string $field = 'CUIT', bool $allowDni = false): string
{
    if ($value === '') {
        return '';
    }
    $digits = preg_replace('/\D/', '', $value);
    if ($allowDni && preg_match('/^\d{7,8}$/', $digits)) {
        return $digits;
    }
    if (!api_cuit_valid($value)) {
        api_error(422, 'El ' . $field . ' ' . $value . ' no es válido (dígito verificador incorrecto)');
    }

    return api_cuit_format($value);
}

/** Dirección IP del cliente (la que informa el servidor web). */
function api_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/**
 * ¿La petición llegó por HTTPS? La cabecera X-Forwarded-Proto solo se considera
 * si la petición viene de un proxy listado en `trusted_proxies` (config.php):
 * de lo contrario cualquier cliente podría falsificarla.
 */
function api_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    $proxies = (array) (api_config()['trusted_proxies'] ?? []);

    return in_array(api_ip(), $proxies, true)
        && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** URL base pública de la aplicación (para armar enlaces). */
function api_base_url(array $config): string
{
    if (!empty($config['app_url'])) {
        return rtrim((string) $config['app_url'], '/');
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $path = rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api/x.php'), 2), '/\\');

    return (api_is_https() ? 'https' : 'http') . '://' . $host . $path;
}

/**
 * Corta la petición si no llegó por HTTPS. Se usa en las operaciones que reciben
 * secretos (certificado y clave privada). En el equipo local se permite HTTP.
 */
function api_require_https(): void
{
    $local = in_array(api_ip(), ['127.0.0.1', '::1'], true);
    if (!api_is_https() && !$local) {
        api_error(403, 'Esta operación solo se acepta por una conexión segura (HTTPS)');
    }
}

/**
 * Exige que una petición con sesión venga de la propia aplicación: cuerpo JSON y,
 * si el navegador informa el origen, que coincida con el host. Evita que otro sitio
 * envíe formularios (por ejemplo, un ingreso forzado con otra cuenta).
 */
function api_require_same_origin(): void
{
    $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    $hasBody = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 || api_method() === 'POST';
    if ($hasBody && !str_starts_with($type, 'application/json')) {
        api_error(415, 'La petición debe enviarse como application/json');
    }
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '' || $origin === 'null') {
        return;
    }
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : ''));
    if ($originHost !== $host && !in_array($origin, (array) (api_config()['allowed_origins'] ?? []), true)) {
        api_error(403, 'Origen de la petición no permitido');
    }
}

/**
 * Toma un bloqueo con nombre (GET_LOCK). Si no se obtiene en el plazo, responde 503:
 * nunca se continúa sin el bloqueo.
 */
function api_lock(PDO $pdo, string $name, int $seconds = 10): void
{
    $st = $pdo->prepare('SELECT GET_LOCK(?, ?)');
    $st->execute([$name, $seconds]);
    if ((int) $st->fetchColumn() !== 1) {
        api_error(503, 'El servidor está ocupado con otra operación igual. Volvé a intentar en unos segundos.');
    }
}

/** Libera un bloqueo tomado con api_lock(). */
function api_unlock(PDO $pdo, string $name): void
{
    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
}

/** ¿La excepción es una violación de clave única (SQLSTATE 23000)? */
function api_is_duplicate(Throwable $e): bool
{
    return $e instanceof PDOException && (string) $e->getCode() === '23000';
}
