<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Aplica las migraciones pendientes del esquema. Se ejecuta en cada despliegue,
 * después de subir el código:
 *
 *   php api/cli/migrar.php
 *
 * La aplicación web no modifica el esquema: mientras la base esté desactualizada,
 * la API responde 503 («el sistema se está actualizando»).
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

require __DIR__ . '/comun.php';

$before = api_schema_version($pdo);
if ($before >= API_SCHEMA_VERSION) {
    echo "La base ya está en la versión $before. No hay migraciones pendientes.\n";
    exit(0);
}

echo "Migrando la base de la versión $before a la " . API_SCHEMA_VERSION . "…\n";
$started = microtime(true);
try {
    api_migrate($pdo);
} catch (Throwable $e) {
    cli_fail('La migración falló en la versión ' . (api_schema_version($pdo) + 1) . ': ' . $e->getMessage()
        . "\nLas migraciones son idempotentes: corregí la causa y volvé a ejecutar este script.");
}
$after = api_schema_version($pdo);
audit_event($pdo, 'Esquema de la base actualizado', [
    'categoria' => 'sistema', 'clave' => 'línea de comandos', 'detalle' => "versión $before → $after",
]);
printf("Listo: versión %d (%.1f s).\n", $after, microtime(true) - $started);
