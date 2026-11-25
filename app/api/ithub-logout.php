<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Aviso de cierre de ITHub (OpenID Connect back-channel logout, ver lib/ithub.php).
 *
 *   POST ithub-logout.php  logout_token=<JWT firmado por ITHub>
 *
 * Lo llama el servidor de ITHub, no un navegador: no hay cookie ni token CSRF; lo que se
 * valida es la firma y los datos del logout_token. Anota el `sid` como cerrado y
 * session_user() corta en su pedido siguiente toda sesión de la aplicación abierta con él.
 *
 * Responde 200 si se procesó y 400 si el token no es válido (lo pide el estándar).
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$config = require __DIR__ . '/bootstrap.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$ic = ithub_config($config);
if ($ic === null || api_method() !== 'POST') {
    http_response_code(400);
    exit;
}
$pdo = api_db($config);

try {
    $claims = ithub_validar_jwt((string) ($_POST['logout_token'] ?? ''), $ic, null, true);
} catch (RuntimeException $e) {
    audit_event($pdo, 'Aviso de cierre de ITHub rechazado', [
        'categoria' => 'acceso', 'nivel' => 'error', 'recurso' => 'sesion', 'clave' => 'ITHub',
        'detalle' => 'logout_token: ' . $e->getMessage(), 'estado' => 400,
    ]);
    http_response_code(400);
    exit;
}

$sid = mb_substr((string) ($claims['sid'] ?? ''), 0, 64);
if ($sid !== '') {
    ithub_cerrar_sid($pdo, $sid);
}
// El logout_token no trae el correo (solo `sub`, el id de ITHub): la salida de la persona
// queda en la auditoría cuando su sesión cae en session_user().
audit_event($pdo, 'Aviso de cierre de ITHub', ['categoria' => 'acceso', 'recurso' => 'sesion', 'clave' => 'ITHub']);
http_response_code(200);
