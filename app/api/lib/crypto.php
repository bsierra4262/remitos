<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Cifrado autenticado de secretos almacenados (AES-256-GCM).
 *
 * - La clave raíz (`app_key`) vive solo en config.php, fuera del repositorio y
 *   de la base de datos: un volcado de la base por sí solo no alcanza para leer
 *   el certificado, la clave privada ni los tickets de ARCA.
 * - De la clave raíz se deriva una subclave por propósito (HKDF-SHA256), de modo
 *   que cada tipo de secreto se cifra con una clave distinta.
 * - Cada valor se liga a su contexto (por ejemplo «arca:produccion:clave») como
 *   dato adicional autenticado: un valor copiado a otra fila o a otro campo no
 *   se descifra.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

const CRYPTO_CIPHER = 'aes-256-gcm';
const CRYPTO_VERSION = 'v2';
const CRYPTO_IV_BYTES = 12;
const CRYPTO_TAG_BYTES = 16;

/** Subclave de 32 bytes para un propósito, derivada de `app_key`. */
function crypto_key(array $config, string $purpose): string
{
    $hex = (string) ($config['app_key'] ?? '');
    if (!preg_match('/^[0-9a-f]{64}$/i', $hex)) {
        throw new RuntimeException('Falta configurar app_key (64 caracteres hexadecimales) en config.php');
    }

    return hash_hkdf('sha256', (string) hex2bin($hex), 32, 'remitos:' . $purpose);
}

/**
 * Cifra un texto ligado a un contexto.
 *
 * Formato: «v2:» + base64(iv · tag · texto cifrado).
 *
 * @param string $context Identifica dónde se guarda el valor (p. ej. «arca:produccion:clave»).
 */
function crypto_encrypt(string $plain, array $config, string $context): string
{
    $iv = random_bytes(CRYPTO_IV_BYTES);
    $tag = '';
    $cipher = openssl_encrypt(
        $plain,
        CRYPTO_CIPHER,
        crypto_key($config, crypto_purpose($context)),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        $context,
        CRYPTO_TAG_BYTES
    );
    if ($cipher === false) {
        throw new RuntimeException('No se pudo cifrar el dato');
    }

    return CRYPTO_VERSION . ':' . base64_encode($iv . $tag . $cipher);
}

/** Descifra un valor producido por crypto_encrypt() con el mismo contexto. */
function crypto_decrypt(string $blob, array $config, string $context): string
{
    $prefix = CRYPTO_VERSION . ':';
    if (!str_starts_with($blob, $prefix)) {
        throw new RuntimeException('Formato de dato cifrado desconocido');
    }
    $raw = base64_decode(substr($blob, strlen($prefix)), true);
    if ($raw === false || strlen($raw) <= CRYPTO_IV_BYTES + CRYPTO_TAG_BYTES) {
        throw new RuntimeException('Dato cifrado dañado');
    }
    $plain = openssl_decrypt(
        substr($raw, CRYPTO_IV_BYTES + CRYPTO_TAG_BYTES),
        CRYPTO_CIPHER,
        crypto_key($config, crypto_purpose($context)),
        OPENSSL_RAW_DATA,
        substr($raw, 0, CRYPTO_IV_BYTES),
        substr($raw, CRYPTO_IV_BYTES, CRYPTO_TAG_BYTES),
        $context
    );
    if ($plain === false) {
        throw new RuntimeException('No se pudo descifrar el dato: la app_key no coincide o el dato fue alterado');
    }

    return $plain;
}

/** Propósito de la subclave: el primer tramo del contexto («arca», …). */
function crypto_purpose(string $context): string
{
    return strtok($context, ':') ?: 'general';
}

/** Compara la clave recibida con la maestra configurada (en claro o como SHA-256). */
function crypto_matches_master(array $config, string $key): bool
{
    $hash = strtolower((string) ($config['master_key_hash'] ?? ''));
    if (preg_match('/^[0-9a-f]{64}$/', $hash)) {
        return hash_equals($hash, hash('sha256', $key));
    }
    $plain = (string) ($config['master_key'] ?? '');

    return $plain !== '' && strlen($plain) >= 24 && hash_equals($plain, $key);
}
