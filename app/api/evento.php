<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Auditoría de la aplicación: permite que la interfaz registre lo que hace el
 * operador (guardar o imprimir un remito, emitir un comprobante, errores) en la
 * misma auditoría que la API.
 *
 *   POST evento.php { categoria, accion, detalle?, nivel? }   (crear)
 *
 * La aplicación solo puede registrar categorías operativas; las de seguridad
 * (claves, accesos, ARCA) las genera exclusivamente el servidor.
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

$action = api_str($body['accion'] ?? '', 180);
if ($action === '') {
    api_error(400, 'Falta la acción a registrar');
}
$category = in_array($body['categoria'] ?? '', ['remito', 'comprobante', 'cai', 'error', 'sistema'], true)
    ? $body['categoria']
    : 'sistema';
$level = ($body['nivel'] ?? 'info') === 'error' ? 'error' : 'info';
if ($level === 'error' && $category === 'sistema') {
    $category = 'error';
}

audit_event($pdo, $action, [
    'categoria' => $category,
    'nivel' => $level,
    'origen' => 'app',
    'clave' => $auth['nombre'],
    'detalle' => api_str($body['detalle'] ?? '', 255),
    'estado' => $level === 'error' ? 500 : 200,
]);

api_respond(200, ['ok' => true]);
