<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Arranque común de los scripts de consola y formato de los respaldos cifrados.
 *
 * Respaldo: «RMB1» · iv (12 bytes) · tag (16 bytes) · AES-256-GCM(gzip(JSON)), con
 * una subclave de `app_key` (propósito «respaldo») y el encabezado como dato
 * autenticado. Sin la app_key no se puede leer ni alterar sin que se note.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const BACKUP_MAGIC = 'RMB1';

/** Tablas que entran en un respaldo (los tickets de ARCA y los intentos son efímeros). */
const BACKUP_TABLES = [
    'app_meta', 'usuarios', 'ajustes', 'api_keys', 'clientes', 'remitos', 'comprobantes',
    'cai_talonarios', 'arca_credenciales', 'api_log',
];

$config = require dirname(__DIR__) . '/bootstrap.php';
$pdo = api_db($config);

/** Escribe un mensaje de error y termina. */
function cli_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

/** Corta si el esquema de la base no está al día. */
function cli_require_schema(PDO $pdo): void
{
    $version = api_schema_version($pdo);
    if ($version < API_SCHEMA_VERSION) {
        cli_fail("La base está en la versión $version y el código espera la " . API_SCHEMA_VERSION . '. Ejecutá primero: php api/cli/migrar.php');
    }
}

/** ¿Se pasó la opción --nombre? */
function cli_flag(array $argv, string $name): bool
{
    return in_array('--' . $name, $argv, true);
}

/** Cifra el contenido de un respaldo. */
function backup_encrypt(string $plain, array $config): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt(gzencode($plain, 9), 'aes-256-gcm', crypto_key($config, 'respaldo'), OPENSSL_RAW_DATA, $iv, $tag, BACKUP_MAGIC, 16);
    if ($cipher === false) {
        cli_fail('No se pudo cifrar el respaldo');
    }

    return BACKUP_MAGIC . $iv . $tag . $cipher;
}

/** Descifra un respaldo. */
function backup_decrypt(string $blob, array $config): array
{
    if (!str_starts_with($blob, BACKUP_MAGIC) || strlen($blob) < 33) {
        cli_fail('El archivo no es un respaldo de Remitos');
    }
    $plain = openssl_decrypt(
        substr($blob, 32),
        'aes-256-gcm',
        crypto_key($config, 'respaldo'),
        OPENSSL_RAW_DATA,
        substr($blob, 4, 12),
        substr($blob, 16, 16),
        BACKUP_MAGIC
    );
    if ($plain === false) {
        cli_fail('No se pudo descifrar el respaldo: la app_key no es la misma con la que se generó o el archivo fue alterado');
    }
    $data = json_decode((string) gzdecode($plain), true);
    if (!is_array($data) || !isset($data['tablas']) || !is_array($data['tablas'])) {
        cli_fail('El respaldo está dañado');
    }

    return $data;
}
