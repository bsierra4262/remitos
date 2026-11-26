<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Ingreso con ITHub, la sesión única de Intellihelp (OpenID Connect, flujo de código de
 * autorización con PKCE).
 *
 * Con la sección `ithub` en config.php (client_id y client_secret), la aplicación deja
 * de tener ingreso propio: ITHub ya validó la cuenta de Google y el dominio, decide si la
 * persona tiene acceso a Remitos y con qué rol (claim `rol`: admin | operador), y vuelve a
 * api/ithub.php con un código. Si la persona ya tenía la sesión de ITHub abierta, entra
 * sin ver ninguna pantalla.
 *
 * - Todo ocurre en el servidor: el secreto del cliente y los tokens nunca llegan al
 *   navegador. El estado del pedido (state, nonce y verificador PKCE) viaja cifrado en una
 *   cookie propia de 10 minutos con SameSite=Lax, igual que en el ingreso con Google.
 * - El id_token se valida entero: firma RS256 con la clave pública de ITHub (/oauth/jwks,
 *   PEM armado desde el JWK sin dependencias y guardado una hora), emisor, destinatario,
 *   vencimiento y nonce.
 * - El usuario local se crea o se actualiza con el rol del token en cada ingreso: los
 *   permisos los da ITHub.
 * - Salir de la aplicación cierra la sesión única (/oauth/logout con id_token_hint). Al
 *   revés, cuando ITHub cierra una sesión (o le quita Remitos a la persona) avisa por detrás
 *   a api/ithub-logout.php (back-channel logout): el `sid` queda anotado en
 *   `ithub_sesiones_cerradas` y session_user() corta en el pedido siguiente toda sesión de
 *   la aplicación abierta con él.
 *
 * Sin la sección `ithub`, la aplicación sigue con su ingreso con Google de siempre.
 *
 * Configuración (config.php):
 *   'ithub' => ['client_id' => 'remitos', 'client_secret' => 'ith_…']
 * `issuer` es opcional (https://ithub.intellihelp.tech); el entorno de pruebas lo apunta a
 * un ITHub simulado y solo se acepta una dirección local por HTTP.
 *
 * Contrato completo: md/INTEGRAR-APLICACION.md del repositorio de ITHub.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

const ITHUB_ISSUER = 'https://ithub.intellihelp.tech';
const ITHUB_COOKIE = 'remitos_ithub';
const ITHUB_EVENTO_LOGOUT = 'http://schemas.openid.net/event/backchannel-logout';
const ITHUB_JWKS_SEGUNDOS = 3600;
/** Roles de la aplicación que puede mandar ITHub (los mismos de USER_ROLES). */
const ITHUB_ROLES = ['admin', 'operador'];

/** Configuración del ingreso con ITHub, o null si no está configurado. */
function ithub_config(array $config): ?array
{
    $c = $config['ithub'] ?? null;
    if (!is_array($c) || empty($c['client_id']) || empty($c['client_secret'])) {
        return null;
    }
    $issuer = rtrim((string) ($c['issuer'] ?? ITHUB_ISSUER), '/');
    // Solo HTTPS, salvo un ITHub simulado en la propia máquina (entorno de pruebas).
    if (!preg_match('#^https://[a-z0-9.-]+(:\d+)?$#i', $issuer) && !preg_match('#^http://(127\.0\.0\.1|localhost):\d+$#', $issuer)) {
        $issuer = ITHUB_ISSUER;
    }

    return [
        'issuer' => $issuer,
        'client_id' => (string) $c['client_id'],
        'client_secret' => (string) $c['client_secret'],
    ];
}

/** URI de retorno registrada en ITHub: …/api/ithub.php (una por cada dominio de la aplicación). */
function ithub_redirect_uri(): string
{
    return google_base_url() . '/api/ithub.php';
}

/** Adónde vuelve la persona después de cerrar la sesión única: la aplicación (registrada en ITHub). */
function ithub_post_logout_uri(): string
{
    return google_base_url() . '/';
}

/** URL de /oauth/authorize de ITHub para iniciar el ingreso. */
function ithub_auth_url(array $ic, string $state, string $nonce, string $verifier, string $redirect): string
{
    return $ic['issuer'] . '/oauth/authorize?' . http_build_query([
        'client_id' => $ic['client_id'],
        'redirect_uri' => $redirect,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'nonce' => $nonce,
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ], '', '&', PHP_QUERY_RFC3986);
}

/**
 * Dirección de salida de ITHub: cierra la sesión única (en todas las aplicaciones) y
 * vuelve a la aplicación. Con el id_token como pista, ITHub no pregunta.
 */
function ithub_url_salida(array $ic, string $idToken, string $volver): string
{
    $q = ['client_id' => $ic['client_id'], 'post_logout_redirect_uri' => $volver];
    if ($idToken !== '') {
        $q['id_token_hint'] = $idToken;
    }

    return $ic['issuer'] . '/oauth/logout?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Pedido HTTP a ITHub. Devuelve [código, cuerpo decodificado]; sin conexión, [0, []].
 * Con $post se autentica con el client_id y el secreto (client_secret_basic).
 */
function ithub_http(array $ic, string $url, ?array $post = null): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_PROTOCOLS => str_starts_with($ic['issuer'], 'https://') ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ];
    if ($post !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        $opts[CURLOPT_USERPWD] = rawurlencode($ic['client_id']) . ':' . rawurlencode($ic['client_secret']);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = is_string($body) ? json_decode($body, true) : null;

    return [$status, is_array($data) ? $data : []];
}

/**
 * Canjea el código de autorización por los tokens (con el verificador PKCE).
 *
 * @return array<string, mixed>
 */
function ithub_exchange(array $ic, string $code, string $verifier, string $redirect): array
{
    [$status, $data] = ithub_http($ic, $ic['issuer'] . '/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirect,
        'code_verifier' => $verifier,
    ]);
    if ($status !== 200 || empty($data['id_token'])) {
        error_log('[remitos] ITHub token HTTP ' . $status . ' ' . (string) ($data['error'] ?? ''));
        throw new RuntimeException('ITHub no confirmó el ingreso');
    }

    return $data;
}

/* ------------------------------ Claves públicas ------------------------------ */

/** Largo DER (forma corta o larga). */
function ithub_der_largo(int $n): string
{
    if ($n < 0x80) {
        return chr($n);
    }
    $b = ltrim(pack('N', $n), "\0");

    return chr(0x80 | strlen($b)) . $b;
}

function ithub_der(int $tag, string $valor): string
{
    return chr($tag) . ithub_der_largo(strlen($valor)) . $valor;
}

/** Entero DER positivo (se antepone un 0 si el primer bit está prendido). */
function ithub_der_entero(string $bin): string
{
    $bin = ltrim($bin, "\0");
    if ($bin === '' || ord($bin[0]) > 0x7F) {
        $bin = "\0" . $bin;
    }

    return ithub_der(0x02, $bin);
}

/** PEM de una clave pública RSA a partir del JWK (n y e en base64url). */
function ithub_jwk_a_pem(array $jwk): string
{
    $rsa = ithub_der(0x30, ithub_der_entero(google_b64decode((string) ($jwk['n'] ?? '')))
        . ithub_der_entero(google_b64decode((string) ($jwk['e'] ?? ''))));
    $alg = ithub_der(0x30, ithub_der(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00");
    $spki = ithub_der(0x30, $alg . ithub_der(0x03, "\0" . $rsa));

    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** Archivo de caché de las claves de ITHub (temporal del sistema, propio de cada emisor). */
function ithub_jwks_archivo(array $ic): string
{
    return rtrim(sys_get_temp_dir(), '/\\') . '/remitos-ithub-jwks-' . substr(hash('sha256', $ic['issuer']), 0, 16) . '.json';
}

/**
 * Claves de ITHub por kid. Se guardan una hora; si el kid pedido no está (ITHub rotó la
 * clave), se vuelven a bajar.
 *
 * @return array<string, array> kid → JWK
 */
function ithub_jwks(array $ic, string $kid = ''): array
{
    $archivo = ithub_jwks_archivo($ic);
    if (is_file($archivo) && filemtime($archivo) > time() - ITHUB_JWKS_SEGUNDOS) {
        $claves = json_decode((string) file_get_contents($archivo), true);
        if (is_array($claves) && $claves !== [] && ($kid === '' || isset($claves[$kid]))) {
            return $claves;
        }
    }
    [$status, $data] = ithub_http($ic, $ic['issuer'] . '/oauth/jwks');
    $claves = [];
    foreach ((array) ($status === 200 ? ($data['keys'] ?? []) : []) as $k) {
        if (is_array($k) && ($k['kty'] ?? '') === 'RSA' && isset($k['kid'], $k['n'], $k['e'])) {
            $claves[(string) $k['kid']] = $k;
        }
    }
    if ($claves !== []) {
        @file_put_contents($archivo, json_encode($claves), LOCK_EX);
        @chmod($archivo, 0600);
    }

    return $claves;
}

/**
 * Valida un JWT de ITHub (id_token o logout_token) y devuelve sus claims.
 *
 * Comprueba: RS256, firma con la clave del kid, emisor, destinatario (nuestro
 * client_id), vencimiento (60 s de tolerancia) y, si se pasa, el nonce. Un logout_token
 * además tiene que traer el evento de back-channel logout, un sid o un sub, y no puede
 * traer nonce.
 *
 * @param array<string, array>|null $claves kid → JWK (las pruebas las pasan; null = las de ITHub)
 * @throws RuntimeException con un motivo corto (va al log, nunca a la pantalla)
 */
function ithub_validar_jwt(string $jwt, array $ic, ?string $nonce = null, bool $esLogout = false, ?array $claves = null, ?int $now = null): array
{
    $now ??= time();
    $partes = explode('.', $jwt);
    if (count($partes) !== 3) {
        throw new RuntimeException('formato');
    }
    [$h, $p, $s] = $partes;
    $cab = json_decode(google_b64decode($h), true);
    $c = json_decode(google_b64decode($p), true);
    if (!is_array($cab) || !is_array($c)) {
        throw new RuntimeException('formato');
    }
    if (($cab['alg'] ?? '') !== 'RS256') {
        throw new RuntimeException('alg');
    }
    $kid = (string) ($cab['kid'] ?? '');
    $claves ??= ithub_jwks($ic, $kid);
    if (!isset($claves[$kid])) {
        throw new RuntimeException('kid');
    }
    if (openssl_verify("$h.$p", google_b64decode($s), ithub_jwk_a_pem($claves[$kid]), OPENSSL_ALGO_SHA256) !== 1) {
        throw new RuntimeException('firma');
    }
    $aud = $c['aud'] ?? '';
    $audOk = is_array($aud) ? in_array($ic['client_id'], $aud, true) : $aud === $ic['client_id'];
    if (($c['iss'] ?? '') !== $ic['issuer'] || !$audOk) {
        throw new RuntimeException('iss/aud');
    }
    if ((int) ($c['exp'] ?? 0) < $now - 60) {
        throw new RuntimeException('vencido');
    }
    if ($esLogout) {
        if (!isset($c['events'][ITHUB_EVENTO_LOGOUT]) || isset($c['nonce']) || (empty($c['sid']) && empty($c['sub']))) {
            throw new RuntimeException('logout_token');
        }
    } elseif ($nonce !== null && !hash_equals($nonce, (string) ($c['nonce'] ?? ''))) {
        throw new RuntimeException('nonce');
    }

    return $c;
}

/* ------------------------------- Usuario local ------------------------------- */

/**
 * Busca (o crea) el usuario de la aplicación para la persona que mandó ITHub y le aplica
 * el nombre y el rol del token: ITHub decide los permisos. Un cambio de rol o una
 * reactivación queda en la auditoría.
 *
 * @return array|null la fila del usuario, o null si el token no trae un correo o un rol de la aplicación
 */
function ithub_usuario_local(PDO $pdo, array $claims): ?array
{
    $email = user_normalize_email($claims['email'] ?? '');
    $rol = (string) ($claims['rol'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($rol, ITHUB_ROLES, true)) {
        return null;
    }
    $nombre = mb_substr(trim((string) ($claims['name'] ?? '')), 0, 120) ?: $email;

    $st = $pdo->prepare('SELECT * FROM usuarios WHERE email = ?');
    $st->execute([$email]);
    $user = $st->fetch() ?: null;
    if ($user === null) {
        $pdo->prepare('INSERT INTO usuarios (nombre, email, rol, activo, creado) VALUES (?, ?, ?, 1, NOW())')
            ->execute([$nombre, $email, $rol]);
        audit_event($pdo, 'Usuario creado: ' . $nombre, [
            'categoria' => 'usuario', 'clave' => 'ITHub', 'detalle' => $email . ' · ' . $rol . ' (primer ingreso con ITHub)',
        ]);
    } else {
        if ($user['rol'] !== $rol || !(int) $user['activo']) {
            audit_event($pdo, 'Usuario actualizado: ' . $nombre, [
                'categoria' => 'usuario', 'clave' => 'ITHub',
                'detalle' => 'rol y estado tomados de ITHub: ' . $user['rol'] . ((int) $user['activo'] ? '' : ' (desactivado)') . ' → ' . $rol,
            ]);
        }
        $pdo->prepare('UPDATE usuarios SET nombre = ?, rol = ?, activo = 1 WHERE id = ?')->execute([$nombre, $rol, $user['id']]);
    }
    $st->execute([$email]);

    return $st->fetch() ?: null;
}

/* ----------------------- Cierre por detrás (back-channel) ----------------------- */

/** Anota que la sesión de ITHub `sid` se cerró: las de la aplicación con ese sid caen. */
function ithub_cerrar_sid(PDO $pdo, string $sid): void
{
    $pdo->prepare('INSERT INTO ithub_sesiones_cerradas (sid, cerrada) VALUES (?, NOW())
                   ON DUPLICATE KEY UPDATE cerrada = NOW()')->execute([$sid]);
    // Con la sesión máxima de la aplicación (12 h), nada más viejo que dos días sirve.
    $pdo->exec('DELETE FROM ithub_sesiones_cerradas WHERE cerrada < NOW() - INTERVAL 2 DAY');
}

/** ¿La sesión de ITHub `sid` ya se cerró? */
function ithub_sid_cerrado(PDO $pdo, string $sid): bool
{
    $st = $pdo->prepare('SELECT 1 FROM ithub_sesiones_cerradas WHERE sid = ? LIMIT 1');
    $st->execute([$sid]);

    return $st->fetchColumn() !== false;
}
