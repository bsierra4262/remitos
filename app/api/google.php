<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Ingreso con Google (ver lib/google.php).
 *
 *   GET google.php?accion=inicio              Redirige a Google para elegir la cuenta.
 *   GET google.php?accion=inicio&confirmar=1  Igual, con la cuenta de la sesión: reconfirma la identidad
 *                                             para operaciones sensibles (vuelve con ?ingreso=confirmado).
 *   GET google.php?code=…&state=…             Vuelta desde Google: valida, abre la sesión y vuelve a la aplicación.
 *
 * Es la única forma de ingresar: la aplicación no tiene contraseñas ni segundo factor
 * propios (es de uso interno y Google ya protege la cuenta de Workspace). Solo ingresa
 * quien ya es usuario activo de la aplicación con ese correo: los usuarios los da de
 * alta un administrador. Cualquier error vuelve a la pantalla de ingreso con un código
 * (?ingreso=…) que la aplicación traduce.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$config = require __DIR__ . '/bootstrap.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

const GOOGLE_MAX_FALLOS = 20;

/** Vuelve a la aplicación (en el mismo host) con un resultado para mostrar. */
function google_back(string $result = ''): never
{
    header('Location: ' . google_base_url() . '/' . ($result !== '' ? '?ingreso=' . rawurlencode($result) : ''), true, 302);
    exit;
}

if (api_method() !== 'GET') {
    http_response_code(405);
    exit;
}

$g = google_config($config);
if ($g === null) {
    google_back('google_no_configurado');
}
$pdo = api_db($config);

if (($_GET['accion'] ?? '') === 'inicio') {
    if (attempts_count($pdo, 'google', null, true, 15) >= GOOGLE_MAX_FALLOS) {
        google_back('google_limitado');
    }
    // Reconfirmación: la cookie de sesión (SameSite=Strict) llega porque es una navegación
    // desde la propia aplicación; se sugiere a Google la cuenta de esa sesión.
    $current = !empty($_GET['confirmar']) ? session_user($pdo) : null;
    $state = google_random();
    $nonce = google_random();
    $verifier = google_random(48);
    google_save_state($config, ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'confirmar' => $current !== null]);
    header('Location: ' . google_auth_url($g, $state, $nonce, $verifier, google_redirect_uri(), $current['email'] ?? ''), true, 302);
    exit;
}

// Vuelta desde Google.
$saved = google_take_state($config);
$state = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
if ($saved === null || $state === '' || !hash_equals((string) $saved['state'], $state)) {
    attempts_record($pdo, 'google');
    google_back('google_vencido');
}
if (isset($_GET['error'])) {
    google_back('google_cancelado');
}
$code = is_string($_GET['code'] ?? null) ? $_GET['code'] : '';
if ($code === '' || strlen($code) > 512) {
    attempts_record($pdo, 'google');
    google_back('google_vencido');
}

try {
    $tokens = google_exchange($g, $code, (string) $saved['verifier'], google_redirect_uri());
} catch (RuntimeException) {
    google_back('google_error');
}
$claims = google_claims((string) $tokens['id_token']);
$problem = google_claims_problem($claims, $g, (string) $saved['nonce']);
$email = user_normalize_email($claims['email'] ?? '');
if ($problem !== null) {
    attempts_record($pdo, 'google', $email);
    audit_event($pdo, 'Ingreso con Google rechazado', [
        'categoria' => 'acceso', 'nivel' => 'error', 'recurso' => 'sesion', 'clave' => mb_substr($email, 0, 80) ?: null,
        'detalle' => $problem, 'estado' => 401,
    ]);
    google_back(str_contains($problem, 'no pertenece') ? 'google_dominio' : 'google_error');
}

$st = $pdo->prepare('SELECT * FROM usuarios WHERE email = ?');
$st->execute([$email]);
$user = $st->fetch() ?: null;
if ($user === null || !(int) $user['activo']) {
    attempts_record($pdo, 'google', $email);
    audit_event($pdo, 'Ingreso con Google rechazado', [
        'categoria' => 'acceso', 'nivel' => 'error', 'recurso' => 'sesion', 'clave' => mb_substr($email, 0, 80),
        'detalle' => $user === null ? 'no es usuario de la aplicación' : 'usuario desactivado', 'estado' => 403,
    ]);
    google_back('google_sin_usuario');
}

$pdo->prepare('UPDATE usuarios SET ultimo_ingreso = NOW() WHERE id = ?')->execute([$user['id']]);
attempts_clear($pdo, 'google', $email);
session_login($pdo, (int) $user['id']);
$confirm = !empty($saved['confirmar']);
audit_event($pdo, $confirm ? 'Identidad confirmada con Google' : 'Ingreso al sistema', [
    'categoria' => 'acceso', 'recurso' => 'sesion', 'usuario_id' => (int) $user['id'], 'clave' => $user['nombre'],
    'detalle' => 'con Google',
]);
google_back($confirm ? 'confirmado' : '');
