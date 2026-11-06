<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Ingreso con Google (OpenID Connect, flujo de código de autorización con PKCE).
 *
 * - Todo ocurre en el servidor: el navegador nunca ve el secreto del cliente ni los
 *   tokens de Google.
 * - Solo se aceptan cuentas del dominio de Google Workspace configurado (`hd`) con el
 *   correo verificado, y solo si el correo corresponde a un usuario activo de la
 *   aplicación: Google identifica a la persona, los permisos los da la aplicación.
 * - El estado del pedido (state, nonce y verificador PKCE) viaja cifrado en una cookie
 *   propia de 10 minutos con SameSite=Lax, porque la vuelta desde Google es una
 *   navegación entre sitios y la cookie de sesión (SameSite=Strict) no se envía.
 * - El token de identidad se obtiene directo del endpoint de tokens de Google por TLS
 *   verificado, por lo que su origen está garantizado (OpenID Connect Core, 3.1.3.7);
 *   igual se validan emisor, audiencia, vigencia, nonce, dominio y correo verificado.
 *
 * Es la única forma de ingresar a la aplicación (no hay contraseñas ni segundo factor
 * propios).
 *
 * Configuración (config.php):
 *   'google' => ['client_id' => '…apps.googleusercontent.com', 'client_secret' => '…', 'dominio' => 'intellihelp.tech']
 * El entorno de pruebas agrega 'token_url' => 'http://127.0.0.1:<puerto>/token' (un Google
 * simulado); solo se acepta una dirección local.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const GOOGLE_ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];
const GOOGLE_COOKIE = 'remitos_oauth';
const GOOGLE_COOKIE_SECONDS = 600;

/** Configuración del ingreso con Google, o null si no está configurado. */
function google_config(array $config): ?array
{
    $g = $config['google'] ?? null;
    if (!is_array($g) || empty($g['client_id']) || empty($g['client_secret']) || empty($g['dominio'])) {
        return null;
    }

    // Solo el entorno de pruebas reemplaza el endpoint de tokens, por uno local que simula
    // a Google; en cualquier otro caso se usa el de Google por HTTPS.
    $tokenUrl = (string) ($g['token_url'] ?? '');
    if (!preg_match('#^http://(127\.0\.0\.1|localhost):\d+/#', $tokenUrl)) {
        $tokenUrl = GOOGLE_TOKEN_URL;
    }

    return [
        'client_id' => (string) $g['client_id'],
        'client_secret' => (string) $g['client_secret'],
        'dominio' => strtolower((string) $g['dominio']),
        'token_url' => $tokenUrl,
    ];
}

/** Texto aleatorio en base64url (sin relleno). */
function google_random(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/** base64url → binario. */
function google_b64decode(string $text): string
{
    return (string) base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
}

/**
 * URL base de la aplicación en el host de esta petición. Se usa el mismo host por el que
 * entró el usuario (la aplicación responde en más de un dominio y la cookie de sesión es
 * de cada host).
 */
function google_base_url(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if (!preg_match('/^[a-z0-9.-]+(:\d+)?$/', $host)) {
        $host = 'localhost';
    }
    $path = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api/google.php'), 2)), '/');

    return (api_is_https() ? 'https' : 'http') . '://' . $host . $path;
}

/** URI de retorno registrada en Google: …/api/google.php. */
function google_redirect_uri(): string
{
    return google_base_url() . '/api/google.php';
}

/**
 * URL de autorización de Google para iniciar el ingreso. Para el ingreso se ofrece elegir
 * la cuenta; para reconfirmar la identidad se sugiere la cuenta de la sesión (login_hint)
 * y Google la usa directamente si ya está abierta en el navegador.
 */
function google_auth_url(array $g, string $state, string $nonce, string $verifier, string $redirect, string $loginHint = ''): string
{
    $params = [
        'client_id' => $g['client_id'],
        'redirect_uri' => $redirect,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'nonce' => $nonce,
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
        'hd' => $g['dominio'],
        'access_type' => 'online',
    ];
    if ($loginHint !== '') {
        $params['login_hint'] = $loginHint;
    } else {
        $params['prompt'] = 'select_account';
    }

    return GOOGLE_AUTH_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/** Guarda el estado del pedido en la cookie cifrada de 10 minutos. */
function google_save_state(array $config, array $state): void
{
    $value = crypto_encrypt(json_encode($state + ['exp' => time() + GOOGLE_COOKIE_SECONDS]), $config, 'oauth:google');
    setcookie(GOOGLE_COOKIE, $value, [
        'expires' => time() + GOOGLE_COOKIE_SECONDS,
        'path' => session_cookie_path() . 'api/',
        'secure' => api_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Lee y borra el estado del pedido. Null si no está, venció o fue alterado. */
function google_take_state(array $config): ?array
{
    $raw = (string) ($_COOKIE[GOOGLE_COOKIE] ?? '');
    setcookie(GOOGLE_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => session_cookie_path() . 'api/',
        'secure' => api_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if ($raw === '') {
        return null;
    }
    try {
        $state = json_decode(crypto_decrypt($raw, $config, 'oauth:google'), true);
    } catch (RuntimeException) {
        return null;
    }

    return is_array($state) && (int) ($state['exp'] ?? 0) >= time() ? $state : null;
}

/**
 * Canjea el código de autorización por los tokens (POST al endpoint de Google).
 *
 * @return array<string, mixed>
 */
function google_exchange(array $g, string $code, string $verifier, string $redirect): array
{
    $ch = curl_init($g['token_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => $code,
            'client_id' => $g['client_id'],
            'client_secret' => $g['client_secret'],
            'redirect_uri' => $redirect,
            'grant_type' => 'authorization_code',
            'code_verifier' => $verifier,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_PROTOCOLS => $g['token_url'] === GOOGLE_TOKEN_URL ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    $data = is_string($body) ? json_decode($body, true) : null;
    if ($status !== 200 || !is_array($data) || empty($data['id_token'])) {
        error_log('[remitos] Google token HTTP ' . $status . ' ' . $error . ' ' . (is_array($data) ? ($data['error'] ?? '') : ''));
        throw new RuntimeException('Google no confirmó el ingreso');
    }

    return $data;
}

/** Datos (claims) del token de identidad. */
function google_claims(string $idToken): array
{
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) {
        return [];
    }
    $claims = json_decode(google_b64decode($parts[1]), true);

    return is_array($claims) ? $claims : [];
}

/** Motivo por el que el token de identidad no se acepta, o null si es válido. */
function google_claims_problem(array $claims, array $g, string $nonce, ?int $now = null): ?string
{
    $now ??= time();
    $email = strtolower((string) ($claims['email'] ?? ''));
    if (!in_array($claims['iss'] ?? '', GOOGLE_ISSUERS, true)) {
        return 'emisor del token inválido';
    }
    $aud = $claims['aud'] ?? '';
    if (!(is_string($aud) ? $aud === $g['client_id'] : (is_array($aud) && in_array($g['client_id'], $aud, true)))) {
        return 'el token es para otra aplicación';
    }
    if ((int) ($claims['exp'] ?? 0) < $now - 60 || (int) ($claims['iat'] ?? 0) > $now + 300) {
        return 'token vencido';
    }
    if (!hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
        return 'el token no corresponde a este ingreso';
    }
    if (($claims['email_verified'] ?? false) !== true) {
        return 'el correo de Google no está verificado';
    }
    if (strtolower((string) ($claims['hd'] ?? '')) !== $g['dominio'] || !str_ends_with($email, '@' . $g['dominio'])) {
        return 'la cuenta no pertenece a ' . $g['dominio'];
    }

    return null;
}
