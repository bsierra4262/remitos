<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Registro de auditoría: un renglón por evento relevante (alta de un remito,
 * autorización, carga de un C.A.I., cambios de claves, accesos rechazados y
 * errores). Las lecturas de rutina no se registran.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/** Categorías válidas de la auditoría. */
const AUDIT_CATEGORIES = ['remito', 'comprobante', 'cai', 'arca', 'clave', 'acceso', 'error', 'sistema'];

/** Cantidad de eventos que se conservan; los más antiguos se depuran. */
const AUDIT_KEEP = 2000;

/**
 * Registra un evento. Nunca interrumpe la operación que lo origina.
 *
 * @param array{categoria?: string, nivel?: string, origen?: string, clave?: ?string,
 *              detalle?: ?string, recurso?: ?string, estado?: int} $options
 */
function audit_event(PDO $pdo, string $action, array $options = []): void
{
    $category = in_array($options['categoria'] ?? '', AUDIT_CATEGORIES, true) ? $options['categoria'] : 'sistema';
    $detail = isset($options['detalle']) ? mb_substr((string) $options['detalle'], 0, 255) : null;

    try {
        $pdo->prepare(
            'INSERT INTO api_log (categoria, nivel, origen, clave, accion, detalle, recurso, estado, ip, fecha)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $category,
            ($options['nivel'] ?? 'info') === 'error' ? 'error' : 'info',
            ($options['origen'] ?? 'api') === 'app' ? 'app' : 'api',
            isset($options['clave']) ? mb_substr((string) $options['clave'], 0, 80) : null,
            mb_substr($action, 0, 180),
            $detail !== '' ? $detail : null,
            $options['recurso'] ?? null,
            (int) ($options['estado'] ?? 200),
            api_ip(),
        ]);

        // Depuración ocasional para que la tabla no crezca sin límite en hosting compartido.
        if (random_int(1, 40) === 1) {
            $pdo->exec(
                'DELETE FROM api_log WHERE id < (
                    SELECT id FROM (SELECT id FROM api_log ORDER BY id DESC LIMIT 1 OFFSET ' . (AUDIT_KEEP - 1) . ') t
                 )'
            );
        }
    } catch (Throwable $e) {
        error_log('[remitos] Auditoría: ' . $e->getMessage());
    }
}
