<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Restaura un respaldo cifrado generado con respaldo.php.
 *
 *   php api/cli/restaurar.php archivo.rmb --verificar    Solo descifra y muestra el contenido.
 *   php api/cli/restaurar.php archivo.rmb --confirmar    Reemplaza los datos actuales.
 *
 * La restauración ocurre en una transacción: si algo falla, la base queda como
 * estaba. Después se aplican las migraciones que falten (un respaldo de una
 * versión anterior queda actualizado). Antes de reemplazar se genera un respaldo
 * de los datos actuales.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

require __DIR__ . '/comun.php';

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    cli_fail('Uso: php api/cli/restaurar.php archivo.rmb (--verificar | --confirmar)');
}
$data = backup_decrypt((string) file_get_contents($file), $config);

echo 'Respaldo del ' . ($data['generado'] ?? '?') . ' · esquema ' . ($data['esquema'] ?? '?') . "\n";
foreach ($data['tablas'] as $table => $rows) {
    printf("  %-18s %6d filas\n", $table, count($rows));
}

if (!cli_flag($argv, 'confirmar')) {
    echo cli_flag($argv, 'verificar') ? "Verificación correcta: el respaldo se puede restaurar.\n" : "Agregá --confirmar para restaurarlo.\n";
    exit(0);
}
if ((int) ($data['esquema'] ?? 0) > API_SCHEMA_VERSION) {
    cli_fail('El respaldo es de una versión más nueva del sistema: actualizá el código antes de restaurarlo');
}

// La estructura tiene que existir para recibir los datos.
api_migrate($pdo);
echo "Generando un respaldo de los datos actuales antes de reemplazarlos…\n";
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/respaldo.php'), $code);
if ($code !== 0) {
    cli_fail('No se pudo respaldar el estado actual: no se restauró nada');
}

$pdo->beginTransaction();
try {
    foreach (BACKUP_TABLES as $table) {
        if (!isset($data['tablas'][$table])) {
            continue;
        }
        $columns = array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(), 'Field');
        $pdo->exec("DELETE FROM `$table`");
        foreach ($data['tablas'][$table] as $row) {
            $row = array_intersect_key($row, array_flip($columns));
            if ($row === []) {
                continue;
            }
            $names = implode(', ', array_map(static fn (string $c): string => "`$c`", array_keys($row)));
            $marks = implode(', ', array_fill(0, count($row), '?'));
            $pdo->prepare("INSERT INTO `$table` ($names) VALUES ($marks)")->execute(array_values($row));
        }
    }
    $pdo->exec('DELETE FROM arca_tickets');
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    cli_fail('La restauración falló y no se modificó nada: ' . $e->getMessage());
}

// Si el respaldo era de un esquema anterior, se completa la migración.
api_migrate($pdo);
audit_event($pdo, 'Respaldo restaurado', ['categoria' => 'sistema', 'nivel' => 'error', 'clave' => 'línea de comandos', 'detalle' => basename($file)]);
echo "Restauración completa. Todas las sesiones abiertas deben volver a ingresar.\n";
$pdo->exec('UPDATE usuarios SET sesion_version = sesion_version + 1');
