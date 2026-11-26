<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Pruebas unitarias del ingreso con ITHub (api/lib/ithub.php) que no necesitan base de
 * datos ni red: configuración, dirección de autorización con PKCE, PEM armado desde el JWK,
 * validación del id_token y del logout_token, y dirección de salida. Se firma con una clave
 * RSA generada en el momento: no se llama a ITHub (las claves se pasan directamente).
 *
 *   php test/unitarias/ithub.test.php
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$faltan = array_filter(['mbstring', 'openssl', 'json', 'curl'], static fn (string $e): bool => !extension_loaded($e));
if ($faltan) {
    fwrite(STDERR, 'Faltan extensiones de PHP: ' . implode(', ', $faltan) . ' (ver test/LEEME.md)' . PHP_EOL);
    exit(2);
}

$api = dirname(__DIR__, 2) . '/app/api/lib';
require $api . '/http.php';
require $api . '/google.php';
require $api . '/users.php';
require $api . '/ithub.php';

$fallas = 0;
$total = 0;

function verificar(bool $condicion, string $nombre): void
{
    global $fallas, $total;
    $total++;
    if (!$condicion) {
        $fallas++;
        echo "  ✗ $nombre\n";
    }
}

/** Motivo con que se rechaza el token, o '' si pasa. */
function falla(callable $fn): string
{
    try {
        $fn();

        return '';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
}

// ---- Configuración ----
verificar(ithub_config([]) === null, 'sin la sección ithub, el ingreso sigue con Google');
verificar(ithub_config(['ithub' => ['client_id' => 'remitos', 'client_secret' => '']]) === null, 'sin secreto no hay ITHub');
$ic = ithub_config(['ithub' => ['client_id' => 'remitos', 'client_secret' => 'ith_prueba']]);
verificar($ic !== null && $ic['issuer'] === 'https://ithub.intellihelp.tech', 'emisor por defecto');
verificar(ithub_config(['ithub' => ['client_id' => 'x', 'client_secret' => 'y', 'issuer' => 'http://127.0.0.1:8767']])['issuer'] === 'http://127.0.0.1:8767', 'ITHub simulado local');
verificar(ithub_config(['ithub' => ['client_id' => 'x', 'client_secret' => 'y', 'issuer' => 'http://malicioso.example']])['issuer'] === ITHUB_ISSUER
    && ithub_config(['ithub' => ['client_id' => 'x', 'client_secret' => 'y', 'issuer' => 'https://x.example/ruta?a=1']])['issuer'] === ITHUB_ISSUER, 'emisor ajeno o sin HTTPS ignorado');

// ---- Autorización ----
parse_str((string) parse_url(ithub_auth_url($ic, 'S', 'N', 'verificador', 'https://remitos.intellihelp.tech/api/ithub.php'), PHP_URL_QUERY), $q);
verificar(str_starts_with(ithub_auth_url($ic, 'S', 'N', 'v', 'r'), 'https://ithub.intellihelp.tech/oauth/authorize?'), 'va a /oauth/authorize de ITHub');
verificar(($q['client_id'] ?? '') === 'remitos' && ($q['response_type'] ?? '') === 'code' && ($q['scope'] ?? '') === 'openid email profile', 'cliente, código y scopes');
verificar(($q['state'] ?? '') === 'S' && ($q['nonce'] ?? '') === 'N', 'state y nonce');
verificar(($q['code_challenge_method'] ?? '') === 'S256'
    && ($q['code_challenge'] ?? '') === rtrim(strtr(base64_encode(hash('sha256', 'verificador', true)), '+/', '-_'), '='), 'PKCE S256');
verificar(!isset($q['client_secret']), 'el secreto no viaja al navegador');

// ---- Claves y firma ----
$cnf = [];
$ruta = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf'; // en Windows, openssl_pkey_new necesita el openssl.cnf de PHP
if (is_file($ruta)) {
    $cnf['config'] = $ruta;
}
$clave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $cnf);
$det = openssl_pkey_get_details($clave);
$b64 = static fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
$jwk = ['kty' => 'RSA', 'kid' => 'k1', 'alg' => 'RS256', 'use' => 'sig', 'n' => $b64($det['rsa']['n']), 'e' => $b64($det['rsa']['e'])];
$claves = ['k1' => $jwk];
verificar(str_replace(["\r", "\n"], '', ithub_jwk_a_pem($jwk)) === str_replace(["\r", "\n"], '', $det['key']), 'el PEM armado desde el JWK es la misma clave pública');

$firmar = static function (array $claims, string $kid = 'k1', string $alg = 'RS256') use ($clave, $b64): string {
    $h = $b64(json_encode(['alg' => $alg, 'typ' => 'JWT', 'kid' => $kid]));
    $p = $b64(json_encode($claims));
    openssl_sign("$h.$p", $firma, $clave, OPENSSL_ALGO_SHA256);

    return "$h.$p." . $b64($firma);
};
$base = ['iss' => 'https://ithub.intellihelp.tech', 'aud' => 'remitos', 'sub' => '7', 'sid' => str_repeat('a', 32),
    'email' => 'ana@intellihelp.tech', 'rol' => 'operador', 'nonce' => 'n1', 'iat' => time(), 'exp' => time() + 300];
$validar = static fn (string $t, ?string $nonce = 'n1', bool $logout = false): array => ithub_validar_jwt($t, $ic, $nonce, $logout, $claves);

verificar(($validar($firmar($base))['rol'] ?? '') === 'operador', 'un id_token bien firmado pasa y trae el rol');
verificar(($validar($firmar(['aud' => ['otra', 'remitos']] + $base))['sub'] ?? '') === '7', 'destinatario en lista');
$partes = explode('.', $firmar($base));
$partes[1] = $b64(json_encode(['rol' => 'admin'] + $base));
verificar(falla(fn () => $validar(implode('.', $partes))) === 'firma', 'cambiar el contenido (subirse el rol) rompe la firma');
verificar(falla(fn () => $validar($firmar(['aud' => 'crm'] + $base))) === 'iss/aud', 'un token para otra aplicación no sirve');
verificar(falla(fn () => $validar($firmar(['iss' => 'https://malicioso.example'] + $base))) === 'iss/aud', 'otro emisor no sirve');
verificar(falla(fn () => $validar($firmar(['exp' => time() - 120] + $base))) === 'vencido', 'vencido no sirve');
verificar(falla(fn () => $validar($firmar($base), 'otro')) === 'nonce', 'con otro nonce no sirve (repetición)');
verificar(falla(fn () => $validar($firmar($base, 'k1', 'none'))) === 'alg' && falla(fn () => $validar($firmar($base, 'k1', 'HS256'))) === 'alg', 'solo RS256');
verificar(falla(fn () => $validar($firmar($base, 'k9'))) === 'kid', 'un kid desconocido no sirve');
verificar(falla(fn () => $validar('no.es-un.token.jwt')) === 'formato' && falla(fn () => $validar('basura')) === 'formato', 'basura no es un token');

$otra = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $cnf);
$h = $b64(json_encode(['alg' => 'RS256', 'kid' => 'k1']));
$p = $b64(json_encode($base));
openssl_sign("$h.$p", $firmaAjena, $otra, OPENSSL_ALGO_SHA256);
verificar(falla(fn () => $validar("$h.$p." . $b64($firmaAjena))) === 'firma', 'firmado con otra clave no sirve');

// ---- Aviso de cierre (logout_token) ----
$logout = ['iss' => 'https://ithub.intellihelp.tech', 'aud' => 'remitos', 'sub' => '7', 'sid' => str_repeat('b', 32),
    'iat' => time(), 'exp' => time() + 120, 'jti' => 'x', 'events' => [ITHUB_EVENTO_LOGOUT => new stdClass()]];
verificar(($validar($firmar($logout), null, true)['sid'] ?? '') === str_repeat('b', 32), 'el aviso de cierre válido trae el sid');
verificar(falla(fn () => $validar($firmar($base), null, true)) === 'logout_token', 'un id_token no sirve como aviso de cierre');
verificar(falla(fn () => $validar($firmar(['nonce' => 'x'] + $logout), null, true)) === 'logout_token', 'un aviso de cierre con nonce se rechaza');
verificar(falla(fn () => $validar($firmar(['aud' => 'crm'] + $logout), null, true)) === 'iss/aud', 'un aviso de cierre para otra aplicación se rechaza');

// ---- Salida ----
$salida = ithub_url_salida($ic, 'tok.en.x', 'https://remitos.intellihelp.tech/');
parse_str((string) parse_url($salida, PHP_URL_QUERY), $qs);
verificar(str_starts_with($salida, 'https://ithub.intellihelp.tech/oauth/logout?'), 'la salida va a /oauth/logout de ITHub');
verificar(($qs['id_token_hint'] ?? '') === 'tok.en.x', 'con el id_token como pista (no pregunta)');
verificar(($qs['post_logout_redirect_uri'] ?? '') === 'https://remitos.intellihelp.tech/' && ($qs['client_id'] ?? '') === 'remitos', 'y vuelve a la aplicación');
verificar(!str_contains(ithub_url_salida($ic, '', 'x'), 'id_token_hint'), 'sin id_token no manda pista');

echo $fallas === 0 ? "✓ $total pruebas del ingreso con ITHub\n" : "✗ $fallas de $total pruebas fallaron\n";
exit($fallas === 0 ? 0 : 1);
