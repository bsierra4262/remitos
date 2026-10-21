<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Respuestas JSON, cabeceras y lectura del cuerpo de las peticiones.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/** Tamaño máximo aceptado para un cuerpo JSON (certificados incluidos). */
const API_MAX_BODY = 262144;

/**
 * Envía las cabeceras comunes y resuelve el preflight de CORS.
 *
 * La autenticación viaja en la cabecera X-API-Key (nunca en cookies), por lo que
 * habilitar CORS no expone sesiones: sin clave no hay acceso.
 */
function api_headers(string $methods = 'GET, OPTIONS'): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: ' . $methods);
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
    header('Access-Control-Max-Age: 600');

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
 * Responde con JSON y termina la ejecución.
 *
 * @param array<string, mixed> $data
 */
function api_respond(int $code, array $data): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
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

    return $data;
}

/** Recorta un texto a una longitud máxima (en caracteres) y le quita los espacios extremos. */
function api_str(mixed $value, int $max): string
{
    if (!is_scalar($value)) {
        return '';
    }

    return mb_substr(trim((string) $value), 0, $max);
}

/** Dirección IP del cliente (la que informa el servidor web). */
function api_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** URL base pública de la aplicación (para armar enlaces). */
function api_base_url(array $config): string
{
    if (!empty($config['app_url'])) {
        return rtrim((string) $config['app_url'], '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $path = rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api/x.php'), 2), '/\\');

    return ($https ? 'https' : 'http') . '://' . $host . $path;
}

/**
 * Corta la petición si no llegó por HTTPS. Se usa en las operaciones que reciben
 * secretos (certificado y clave privada). En el equipo local se permite HTTP.
 */
function api_require_https(): void
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $local = in_array(api_ip(), ['127.0.0.1', '::1'], true);
    if (!$https && !$local) {
        api_error(403, 'Esta operación solo se acepta por una conexión segura (HTTPS)');
    }
}
