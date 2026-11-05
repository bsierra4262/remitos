<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Segundo factor de autenticación con códigos de un solo uso basados en tiempo
 * (TOTP, RFC 6238): 6 dígitos, período de 30 segundos, HMAC-SHA1. Compatible con
 * Google Authenticator, Microsoft Authenticator, 1Password y similares.
 *
 * El secreto se guarda cifrado (AES-256-GCM, contexto «totp:<usuario>») y cada
 * código se acepta una sola vez.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

const TOTP_PERIOD = 30;
const TOTP_DIGITS = 6;
const TOTP_ISSUER = 'Intellihelp Remitos';

/** Secreto nuevo de 160 bits en base32. */
function totp_new_secret(): string
{
    return totp_base32_encode(random_bytes(20));
}

function totp_base32_encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }

    return $out;
}

function totp_base32_decode(string $text): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $text))) as $char) {
        $bits .= str_pad(decbin(strpos($alphabet, $char)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $out .= chr(bindec($chunk));
        }
    }

    return $out;
}

/** Código para un contador de tiempo. */
function totp_code(string $secret, int $counter): string
{
    $hmac = hash_hmac('sha1', pack('J', $counter), totp_base32_decode($secret), true);
    $offset = ord($hmac[19]) & 0x0f;
    $value = ((ord($hmac[$offset]) & 0x7f) << 24) | (ord($hmac[$offset + 1]) << 16)
        | (ord($hmac[$offset + 2]) << 8) | ord($hmac[$offset + 3]);

    return str_pad((string) ($value % (10 ** TOTP_DIGITS)), TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/**
 * Verifica un código con tolerancia de ±1 período. Devuelve el contador usado (para
 * impedir que el mismo código se use dos veces) o null.
 */
function totp_verify(string $secret, string $code, ?int $lastUsed): ?int
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== TOTP_DIGITS) {
        return null;
    }
    $now = intdiv(time(), TOTP_PERIOD);
    for ($delta = -1; $delta <= 1; $delta++) {
        $counter = $now + $delta;
        if (($lastUsed === null || $counter > $lastUsed) && hash_equals(totp_code($secret, $counter), $code)) {
            return $counter;
        }
    }

    return null;
}

/** URI otpauth:// para cargar el secreto en la aplicación de autenticación. */
function totp_uri(string $secret, string $account): string
{
    return 'otpauth://totp/' . rawurlencode(TOTP_ISSUER . ':' . $account)
        . '?secret=' . $secret . '&issuer=' . rawurlencode(TOTP_ISSUER) . '&digits=' . TOTP_DIGITS . '&period=' . TOTP_PERIOD;
}

/** Secreto descifrado de un usuario, o null si no tiene. */
function totp_user_secret(array $config, array $row): ?string
{
    if (empty($row['totp_secret'])) {
        return null;
    }

    return crypto_decrypt((string) $row['totp_secret'], $config, 'totp:' . $row['id']);
}
