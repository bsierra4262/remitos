<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Registro de auditoría y contadores de intentos fallidos.
 *
 * Auditoría: un renglón por evento relevante (altas, ediciones, autorizaciones,
 * cambios de claves, usuarios y certificados, accesos rechazados y errores). Las
 * lecturas de rutina no se registran.
 *  - Cada evento guarda el usuario (id y nombre) y un hash SHA-256 encadenado con
 *    el del evento anterior: modificar o borrar un renglón intermedio rompe la
 *    cadena y la verificación del panel lo detecta.
 *  - La retención es por tiempo (AUDIT_RETENTION_DAYS). Nadie puede «empujar»
 *    eventos viejos fuera de la tabla generando eventos nuevos; los que informa la
 *    aplicación tienen además una cuota por usuario (evento.php).
 *
 * Intentos fallidos: tabla propia, independiente de la auditoría, que alimenta los
 * bloqueos por fuerza bruta (claves de API, ingreso con Google, enlaces de conformidad).
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/** Categorías válidas de la auditoría. */
const AUDIT_CATEGORIES = ['remito', 'comprobante', 'cliente', 'cai', 'arca', 'clave', 'usuario', 'acceso', 'error', 'sistema'];

/** Días que se conservan los eventos de auditoría. */
const AUDIT_RETENTION_DAYS = 400;

/** Días que se conservan los intentos fallidos (solo sirven para los bloqueos). */
const ATTEMPTS_RETENTION_DAYS = 2;

/**
 * Usuario o clave que está actuando en la petición; lo fija auth_require() y lo usa
 * audit_event() cuando el llamador no lo indica.
 *
 * @return array{id: ?int, nombre: ?string}
 */
function audit_actor(?array $actor = null): array
{
    static $current = ['id' => null, 'nombre' => null];
    if ($actor !== null) {
        $current = ['id' => $actor['id'] ?? null, 'nombre' => $actor['nombre'] ?? null];
    }

    return $current;
}

/** Hash de un evento encadenado con el anterior. */
function audit_hash(?string $prev, array $row): string
{
    $fields = [
        $prev ?? '', $row['categoria'], $row['nivel'], $row['origen'], (string) ($row['usuario_id'] ?? ''),
        (string) ($row['clave'] ?? ''), $row['accion'], (string) ($row['detalle'] ?? ''), (string) ($row['recurso'] ?? ''),
        (string) $row['estado'], (string) ($row['ip'] ?? ''), $row['fecha'],
    ];

    return hash('sha256', implode("\x1f", $fields));
}

/**
 * Registra un evento. Nunca interrumpe la operación que lo origina.
 *
 * @param array{categoria?: string, nivel?: string, origen?: string, clave?: ?string, usuario_id?: ?int,
 *              detalle?: ?string, recurso?: ?string, estado?: int} $options
 */
function audit_event(PDO $pdo, string $action, array $options = []): void
{
    $actor = audit_actor();
    $row = [
        'categoria' => in_array($options['categoria'] ?? '', AUDIT_CATEGORIES, true) ? $options['categoria'] : 'sistema',
        'nivel' => ($options['nivel'] ?? 'info') === 'error' ? 'error' : 'info',
        'origen' => ($options['origen'] ?? 'api') === 'app' ? 'app' : 'api',
        'usuario_id' => array_key_exists('usuario_id', $options) ? $options['usuario_id'] : $actor['id'],
        'clave' => mb_substr((string) (array_key_exists('clave', $options) ? $options['clave'] : $actor['nombre']), 0, 80) ?: null,
        'accion' => mb_substr($action, 0, 180),
        'detalle' => isset($options['detalle']) && $options['detalle'] !== '' ? mb_substr((string) $options['detalle'], 0, 255) : null,
        'recurso' => $options['recurso'] ?? null,
        'estado' => (int) ($options['estado'] ?? 200),
        'ip' => api_ip(),
        'fecha' => date('Y-m-d H:i:s'),
    ];

    try {
        $pdo->query("SELECT GET_LOCK('remitos_auditoria', 5)");
        try {
            $prev = $pdo->query('SELECT hash FROM api_log ORDER BY id DESC LIMIT 1')->fetchColumn() ?: null;
            $pdo->prepare(
                'INSERT INTO api_log (categoria, nivel, origen, usuario_id, clave, accion, detalle, recurso, estado, ip, fecha, prev_hash, hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([...array_values($row), $prev, audit_hash($prev, $row)]);
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('remitos_auditoria')");
        }
        if (random_int(1, 200) === 1) {
            $pdo->exec('DELETE FROM api_log WHERE fecha < NOW() - INTERVAL ' . AUDIT_RETENTION_DAYS . ' DAY');
        }
    } catch (Throwable $e) {
        error_log('[remitos] Auditoría: ' . $e->getMessage());
    }
}

/** Recalcula la cadena de integridad completa (migración inicial). */
function audit_rechain(PDO $pdo): void
{
    $prev = null;
    $rows = $pdo->query(
        'SELECT id, categoria, nivel, origen, usuario_id, clave, accion, detalle, recurso, estado, ip, fecha FROM api_log ORDER BY id'
    )->fetchAll();
    $st = $pdo->prepare('UPDATE api_log SET prev_hash = ?, hash = ? WHERE id = ?');
    foreach ($rows as $row) {
        $hash = audit_hash($prev, $row);
        $st->execute([$prev, $hash, $row['id']]);
        $prev = $hash;
    }
}

/**
 * Verifica la cadena de integridad desde el evento más antiguo conservado.
 *
 * @return array{ok: bool, eventos: int, desde: ?string, falla: ?array}
 */
function audit_verify(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT id, categoria, nivel, origen, usuario_id, clave, accion, detalle, recurso, estado, ip, fecha, prev_hash, hash
           FROM api_log ORDER BY id'
    );
    $count = 0;
    $previous = null;
    $first = null;
    foreach ($rows as $row) {
        $count++;
        $first ??= $row['fecha'];
        $linked = $previous === null || hash_equals((string) $previous, (string) $row['prev_hash']);
        if (!$linked || !hash_equals(audit_hash($row['prev_hash'], $row), (string) $row['hash'])) {
            return [
                'ok' => false,
                'eventos' => $count,
                'desde' => $first,
                'falla' => ['id' => (int) $row['id'], 'fecha' => $row['fecha'], 'motivo' => $linked ? 'evento alterado' : 'falta un evento anterior'],
            ];
        }
        $previous = $row['hash'];
    }

    return ['ok' => true, 'eventos' => $count, 'desde' => $first, 'falla' => null];
}

/* ---------------------------- Intentos fallidos ---------------------------- */

/** Registra un intento fallido. */
function attempts_record(PDO $pdo, string $type, string $key = ''): void
{
    $pdo->prepare('INSERT INTO intentos (tipo, clave, ip, fecha) VALUES (?, ?, ?, NOW())')
        ->execute([$type, mb_substr($key, 0, 190), api_ip()]);
    if (random_int(1, 100) === 1) {
        $pdo->exec('DELETE FROM intentos WHERE fecha < NOW() - INTERVAL ' . ATTEMPTS_RETENTION_DAYS . ' DAY');
    }
}

/** Intentos fallidos recientes de un tipo, filtrados por clave y/o por la IP actual. */
function attempts_count(PDO $pdo, string $type, ?string $key, bool $byIp, int $minutes): int
{
    $sql = 'SELECT COUNT(*) FROM intentos WHERE tipo = ? AND fecha > NOW() - INTERVAL ' . $minutes . ' MINUTE';
    $params = [$type];
    if ($key !== null) {
        $sql .= ' AND clave = ?';
        $params[] = mb_substr($key, 0, 190);
    }
    if ($byIp) {
        $sql .= ' AND ip = ?';
        $params[] = api_ip();
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return (int) $st->fetchColumn();
}

/** Borra los intentos fallidos de una clave (al acertar o al desbloquear). */
function attempts_clear(PDO $pdo, string $type, string $key): void
{
    $pdo->prepare('DELETE FROM intentos WHERE tipo = ? AND clave = ?')->execute([$type, mb_substr($key, 0, 190)]);
}
