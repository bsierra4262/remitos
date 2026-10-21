<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Arranque común de los endpoints: configuración, manejo de errores y librerías.
 * Devuelve la configuración normalizada.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('America/Argentina/Buenos_Aires');

require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/crypto.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/arca.php';

// Cualquier error no previsto responde JSON genérico; el detalle queda solo en el log del servidor.
set_exception_handler(static function (Throwable $e): void {
    error_log('[remitos] ' . $e::class . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno del servidor'], JSON_UNESCAPED_UNICODE);
});

// REMITOS_CONFIG permite usar otra configuración (por ejemplo, la del entorno de pruebas).
$configFile = getenv('REMITOS_CONFIG') ?: __DIR__ . '/config.php';
if (!is_file($configFile)) {
    api_headers();
    api_error(503, 'La API no está configurada: falta api/config.php (ver config.sample.php)');
}

$config = require $configFile;

return is_array($config) ? $config : [];
