<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Rotación de la clave de cifrado (app_key).
 *
 *   php api/cli/rotar-clave.php --confirmar
 *
 * Genera una app_key nueva, vuelve a cifrar con ella todos los secretos guardados
 * (certificados y claves privadas de ARCA, tickets de acceso y secretos TOTP) y
 * actualiza config.php. Todo ocurre en una transacción: si algo falla, la base y
 * config.php quedan como estaban.
 *
 * Después de rotar:
 *  - Guardá la app_key nueva en el gestor de contraseñas (se imprime una sola vez).
 *  - Los respaldos anteriores se descifran con la clave anterior: conservala junto
 *    a ellos mientras se guarden, o generá un respaldo nuevo (se hace al terminar).
 *
 * La clave maestra de la API se rota aparte: generá una nueva (ver config.sample.php),
 * reemplazá master_key_hash en config.php y actualizá las integraciones.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

require __DIR__ . '/comun.php';
cli_require_schema($pdo);

if (!cli_flag($argv, 'confirmar')) {
    cli_fail('Este comando reemplaza la app_key. Ejecutalo con --confirmar.');
}
$configFile = getenv('REMITOS_CONFIG') ?: dirname(__DIR__) . '/config.php';
$source = (string) file_get_contents($configFile);
$pattern = "/('app_key'\\s*=>\\s*)'([0-9a-fA-F]{64})'/";
if (preg_match_all($pattern, $source) !== 1) {
    cli_fail("No se encontró una única línea 'app_key' => '…' en $configFile");
}

$newKey = bin2hex(random_bytes(32));
$newConfig = ['app_key' => $newKey] + $config;

/** Campos cifrados: [tabla, columnas clave, columna, función de contexto]. */
$fields = [
    ['arca_credenciales', ['ambiente'], 'certificado', static fn (array $r): string => 'arca:' . $r['ambiente'] . ':certificado'],
    ['arca_credenciales', ['ambiente'], 'clave', static fn (array $r): string => 'arca:' . $r['ambiente'] . ':clave'],
    ['arca_tickets', ['ambiente', 'servicio'], 'token', static fn (array $r): string => 'arca:' . $r['ambiente'] . ':' . $r['servicio'] . ':token'],
    ['arca_tickets', ['ambiente', 'servicio'], 'sign', static fn (array $r): string => 'arca:' . $r['ambiente'] . ':' . $r['servicio'] . ':sign'],
    ['usuarios', ['id'], 'totp_secret', static fn (array $r): string => 'totp:' . $r['id']],
];

$count = 0;
$pdo->beginTransaction();
try {
    foreach ($fields as [$table, $keys, $column, $context]) {
        $rows = $pdo->query("SELECT * FROM `$table` WHERE `$column` IS NOT NULL AND `$column` <> ''")->fetchAll();
        $where = implode(' AND ', array_map(static fn (string $k): string => "`$k` = ?", $keys));
        $update = $pdo->prepare("UPDATE `$table` SET `$column` = ? WHERE $where");
        foreach ($rows as $row) {
            $plain = crypto_decrypt((string) $row[$column], $config, $context($row));
            $update->execute([crypto_encrypt($plain, $newConfig, $context($row)), ...array_map(static fn (string $k) => $row[$k], $keys)]);
            $count++;
        }
    }

    $temp = $configFile . '.tmp';
    $updated = preg_replace($pattern, "\${1}'" . $newKey . "'", $source);
    if (file_put_contents($temp, $updated, LOCK_EX) === false || !chmod($temp, 0600) || !rename($temp, $configFile)) {
        throw new RuntimeException("No se pudo escribir $configFile");
    }
    try {
        $pdo->commit();
    } catch (Throwable $e) {
        file_put_contents($configFile, $source, LOCK_EX);
        throw $e;
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    cli_fail('La rotación falló y no se modificó nada: ' . $e->getMessage());
}

audit_event($pdo, 'Clave de cifrado rotada', ['categoria' => 'sistema', 'clave' => 'línea de comandos', 'detalle' => "$count secretos cifrados de nuevo"]);
echo "Listo: $count secretos cifrados con la clave nueva y config.php actualizado.\n";
echo "app_key nueva (guardala en el gestor de contraseñas; no se vuelve a mostrar):\n$newKey\n\n";
echo "Generando un respaldo con la clave nueva…\n";
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/respaldo.php'));
