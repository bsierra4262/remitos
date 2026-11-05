<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Respaldo cifrado de la base de datos.
 *
 *   php api/cli/respaldo.php [carpeta] [--conservar=30]
 *
 * Genera «remitos-AAAAMMDD-HHMMSS.rmb» (permisos 0600) en la carpeta indicada
 * (por defecto ~/backups/remitos) y conserva los últimos N respaldos. Pensado para
 * correr a diario con cron:
 *
 *   15 3 * * * php /home/usuario/public_html/remitos/api/cli/respaldo.php >> ~/backups/remitos/respaldo.log 2>&1
 *
 * El archivo está cifrado con una subclave de app_key: guardá la app_key en un
 * gestor de contraseñas, fuera del servidor. La restauración se prueba con
 * api/cli/restaurar.php --verificar.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

require __DIR__ . '/comun.php';
cli_require_schema($pdo);

$args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => !str_starts_with($a, '--')));
$keep = 30;
foreach ($argv as $arg) {
    if (preg_match('/^--conservar=(\d+)$/', $arg, $m)) {
        $keep = max(1, (int) $m[1]);
    }
}
$home = getenv('HOME') ?: (getenv('USERPROFILE') ?: sys_get_temp_dir());
$dir = rtrim($args[0] ?? $home . '/backups/remitos', '/\\');
if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
    cli_fail("No se pudo crear la carpeta $dir");
}

$data = ['formato' => 1, 'esquema' => api_schema_version($pdo), 'generado' => date('c'), 'tablas' => []];
$rows = 0;
foreach (BACKUP_TABLES as $table) {
    $data['tablas'][$table] = $pdo->query("SELECT * FROM `$table`")->fetchAll();
    $rows += count($data['tablas'][$table]);
}

$file = $dir . '/remitos-' . date('Ymd-His') . '.rmb';
$blob = backup_encrypt(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $config);
$umask = umask(0077);
$written = file_put_contents($file, $blob, LOCK_EX);
umask($umask);
if ($written === false) {
    cli_fail("No se pudo escribir $file");
}
chmod($file, 0600);

// Verificación inmediata: el respaldo se puede descifrar y tiene las mismas filas.
$check = backup_decrypt((string) file_get_contents($file), $config);
$checkRows = array_sum(array_map('count', $check['tablas']));
if ($checkRows !== $rows) {
    cli_fail("El respaldo $file no pasó la verificación ($checkRows filas en lugar de $rows)");
}

$old = glob($dir . '/remitos-*.rmb') ?: [];
rsort($old);
foreach (array_slice($old, $keep) as $stale) {
    unlink($stale);
}

audit_event($pdo, 'Respaldo cifrado generado', [
    'categoria' => 'sistema', 'clave' => 'línea de comandos', 'detalle' => basename($file) . " · $rows filas",
]);
printf("Respaldo verificado: %s (%d filas, %s KB). Se conservan los últimos %d.\n", $file, $rows, number_format(strlen($blob) / 1024, 1), $keep);
