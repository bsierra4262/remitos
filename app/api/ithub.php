<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Ingreso con ITHub, la sesión única de Intellihelp (ver lib/ithub.php).
 *
 *   GET ithub.php?accion=inicio              Redirige a ITHub; con la sesión única abierta, vuelve al instante.
 *   GET ithub.php?accion=inicio&confirmar=1  Igual, para reconfirmar la identidad de la sesión antes de una
 *                                            operación sensible (vuelve con ?ingreso=confirmado).
 *   GET ithub.php?code=…&state=…             Vuelta desde ITHub: valida, abre la sesión y vuelve a la aplicación.
 *
 * ITHub ya validó la cuenta de Google y el dominio y decide quién entra y con qué rol: si la
 * persona no tiene Remitos habilitado, ITHub le muestra su pantalla «Sin acceso» y ni llega
 * acá. Cualquier error vuelve a la pantalla de ingreso con un código (?ingreso=…) que la
 * aplicación traduce.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$config = require __DIR__ . '/bootstrap.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

const ITHUB_MAX_FALLOS = 20;
/** Páginas a las que se puede volver después de ITHub (lista cerrada: nada de redirecciones abiertas). */
const ITHUB_VOLVER = ['panel' => 'panel.html'];

/** Vuelve a la aplicación (en el mismo host) con un resultado para mostrar. */
function ithub_back(string $result = '', string $page = ''): never
{
    header('Location: ' . google_base_url() . '/' . (ITHUB_VOLVER[$page] ?? '')
        . ($result !== '' ? '?ingreso=' . rawurlencode($result) : ''), true, 302);
    exit;
}

if (api_method() !== 'GET') {
    http_response_code(405);
    exit;
}

$ic = ithub_config($config);
if ($ic === null) {
    ithub_back('ithub_no_configurado');
}
$pdo = api_db($config);

if (($_GET['accion'] ?? '') === 'inicio') {
    if (attempts_count($pdo, 'ithub', null, true, 15) >= ITHUB_MAX_FALLOS) {
        ithub_back('ithub_limitado');
    }
    // La cookie de sesión (SameSite=Strict) llega porque es una navegación desde la propia
    // aplicación. Con sesión abierta, solo se pasa por ITHub para reconfirmar la identidad.
    $current = session_user($pdo);
    if ($current !== null && empty($_GET['confirmar'])) {
        ithub_back();
    }
    $state = google_random();
    $nonce = google_random();
    $verifier = google_random(48);
    google_save_state($config, [
        'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier,
        'confirmar' => $current !== null, 'uid' => $current['id'] ?? 0,
        'volver' => isset(ITHUB_VOLVER[$_GET['volver'] ?? '']) ? (string) $_GET['volver'] : '',
    ], ITHUB_COOKIE, 'oauth:ithub');
    header('Location: ' . ithub_auth_url($ic, $state, $nonce, $verifier, ithub_redirect_uri()), true, 302);
    exit;
}

// Vuelta desde ITHub.
$saved = google_take_state($config, ITHUB_COOKIE, 'oauth:ithub');
$state = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
if ($saved === null || $state === '' || !hash_equals((string) $saved['state'], $state)) {
    attempts_record($pdo, 'ithub');
    ithub_back('ithub_vencido');
}
if (isset($_GET['error'])) {
    // access_denied: la persona no tiene Remitos habilitado en ITHub.
    ithub_back(($_GET['error'] ?? '') === 'access_denied' ? 'ithub_sin_acceso' : 'ithub_error', (string) ($saved['volver'] ?? ''));
}
$code = is_string($_GET['code'] ?? null) ? $_GET['code'] : '';
if ($code === '' || strlen($code) > 512) {
    attempts_record($pdo, 'ithub');
    ithub_back('ithub_vencido');
}

try {
    $tokens = ithub_exchange($ic, $code, (string) $saved['verifier'], ithub_redirect_uri());
    $claims = ithub_validar_jwt((string) $tokens['id_token'], $ic, (string) $saved['nonce']);
} catch (RuntimeException $e) {
    attempts_record($pdo, 'ithub');
    audit_event($pdo, 'Ingreso con ITHub rechazado', [
        'categoria' => 'acceso', 'nivel' => 'error', 'recurso' => 'sesion', 'detalle' => 'id_token: ' . $e->getMessage(), 'estado' => 401,
    ]);
    ithub_back('ithub_error');
}

$email = user_normalize_email($claims['email'] ?? '');
$confirm = !empty($saved['confirmar']);
if ($confirm) {
    // Confirmar la identidad es volver a probar que quien opera es el dueño de la sesión:
    // con otra cuenta no se confirma nada (y no se cambia de usuario por la puerta lateral).
    $st = $pdo->prepare('SELECT * FROM usuarios WHERE id = ? AND email = ? AND activo = 1');
    $st->execute([(int) ($saved['uid'] ?? 0), $email]);
    $user = $st->fetch() ?: null;
    if ($user === null) {
        audit_event($pdo, 'Confirmación de identidad rechazada', [
            'categoria' => 'acceso', 'nivel' => 'error', 'recurso' => 'sesion', 'clave' => mb_substr($email, 0, 80),
            'detalle' => 'la sesión de ITHub es de otra cuenta', 'estado' => 403,
        ]);
        ithub_back('ithub_otra_cuenta', (string) ($saved['volver'] ?? ''));
    }
}

$user = ithub_usuario_local($pdo, $claims);
if ($user === null) {
    attempts_record($pdo, 'ithub', $email);
    audit_event($pdo, 'Ingreso con ITHub rechazado', [
        'categoria' => 'acceso', 'nivel' => 'error', 'recurso' => 'sesion', 'clave' => mb_substr($email, 0, 80) ?: null,
        'detalle' => 'ITHub mandó un rol que la aplicación no conoce: ' . mb_substr((string) ($claims['rol'] ?? ''), 0, 40), 'estado' => 403,
    ]);
    ithub_back('ithub_rol');
}

$pdo->prepare('UPDATE usuarios SET ultimo_ingreso = NOW() WHERE id = ?')->execute([$user['id']]);
attempts_clear($pdo, 'ithub', $email);
session_login($pdo, (int) $user['id'], [
    'ithub_sid' => mb_substr((string) ($claims['sid'] ?? ''), 0, 64),
    'ithub_id_token' => (string) $tokens['id_token'],
]);
audit_event($pdo, $confirm ? 'Identidad confirmada con ITHub' : 'Ingreso al sistema', [
    'categoria' => 'acceso', 'recurso' => 'sesion', 'usuario_id' => (int) $user['id'], 'clave' => $user['nombre'],
    'detalle' => 'con ITHub',
]);
ithub_back($confirm ? 'confirmado' : '', (string) ($saved['volver'] ?? ''));
