<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Arranque común de los endpoints y de los comandos de consola: configuración,
 * manejo de errores y librerías. Devuelve la configuración normalizada.
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
require __DIR__ . '/lib/session.php';
require __DIR__ . '/lib/users.php';
require __DIR__ . '/lib/totp.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/arca.php';
require __DIR__ . '/lib/importes.php';
require __DIR__ . '/lib/ajustes.php';
require __DIR__ . '/lib/normativa.php';
require __DIR__ . '/lib/google.php';

/*
 * Las respuestas se lanzan como ApiResponse (así los `finally` liberan bloqueos y
 * transacciones). Cualquier otro error responde JSON genérico; el detalle queda
 * solo en el log del servidor.
 */
set_exception_handler(static function (Throwable $e): void {
    if ($e instanceof ApiResponse) {
        api_send($e);

        return;
    }
    error_log('[remitos] ' . $e::class . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    api_send(new ApiResponse(500, ['ok' => false, 'error' => 'Error interno del servidor']));
});

// REMITOS_CONFIG permite usar otra configuración (por ejemplo, la del entorno de pruebas).
$configFile = getenv('REMITOS_CONFIG') ?: __DIR__ . '/config.php';
if (!is_file($configFile)) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Falta api/config.php (ver config.sample.php)\n");
        exit(1);
    }
    api_headers();
    api_error(503, 'La API no está configurada: falta api/config.php (ver config.sample.php)');
}

$config = require $configFile;
$config = is_array($config) ? $config : [];
api_config($config);

return $config;
