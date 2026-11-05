<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Exportación completa de los datos compartidos (remitos, comprobantes y clientes)
 * en JSON, para una copia descargable desde la aplicación.
 *
 *   GET exportar.php   (admin)
 *
 * Incluye datos personales de los clientes (Ley 25.326): solo la obtiene un
 * administrador y cada exportación queda en la auditoría. No incluye secretos
 * (certificados, claves ni usuarios). El respaldo completo y cifrado del servidor
 * se hace con api/cli/respaldo.php.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$config = require __DIR__ . '/bootstrap.php';
api_headers('GET, OPTIONS');
if (api_method() !== 'GET') {
    api_error(405, 'Método no permitido');
}
$pdo = api_db($config);
auth_require($pdo, $config, 'admin', 'exportar');

$decode = static function (array $row): array {
    $doc = json_decode((string) $row['data'], true) ?: [];

    return $doc + ['id' => $row['id']];
};

$remitos = [];
foreach ($pdo->query('SELECT * FROM remitos ORDER BY created, id') as $row) {
    $doc = $decode($row);
    $doc['estado'] = $row['estado'];
    $doc['referencia'] = $row['referencia'] !== null ? (int) $row['referencia'] : null;
    $doc['numero'] = $row['estado'] === 'autorizado' ? (string) $row['numero'] : '';
    $doc['facturado'] = (bool) (int) $row['facturado'];
    $remitos[] = $doc;
}
$comprobantes = [];
foreach ($pdo->query('SELECT * FROM comprobantes WHERE data IS NOT NULL ORDER BY created, id') as $row) {
    $doc = $decode($row);
    $doc['estado'] = $row['estado'];
    $doc['numero'] = (string) ($row['numero'] ?? '');
    $doc['cae'] = (string) ($row['cae'] ?? '');
    $doc['caeVto'] = (string) ($row['cae_vto'] ?? '');
    $comprobantes[] = $doc;
}
$clientes = [];
foreach ($pdo->query('SELECT id, data FROM clientes ORDER BY razon_social, id') as $row) {
    $clientes[] = ['id' => (int) $row['id']] + (json_decode((string) $row['data'], true) ?: []);
}

audit_event($pdo, 'Exportación completa de datos', [
    'categoria' => 'sistema',
    'detalle' => count($remitos) . ' remitos · ' . count($comprobantes) . ' comprobantes · ' . count($clientes) . ' clientes',
]);

api_respond(200, [
    'ok' => true,
    'formato' => 'remitos-intellihelp',
    'version' => 4,
    'exportado' => date('c'),
    'docs' => $remitos,
    'comprobantes' => $comprobantes,
    'clientes' => $clientes,
]);
