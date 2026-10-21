<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Estado de seguimiento (solo lectura): cuántos remitos hay, cuántos son del día
 * y cuáles quedan pendientes de facturar. No expone importes, ítems, CUIT ni
 * domicilios, por lo que puede consultarse desde tableros o integraciones.
 *
 *   GET estado.php                 (leer) Resumen y pendientes en JSON.
 *   GET estado.php?formato=texto   (leer) El mismo resumen en texto plano.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

$config = require __DIR__ . '/bootstrap.php';
api_headers('GET, OPTIONS');
if (api_method() !== 'GET') {
    api_error(405, 'Solo lectura: usá GET');
}
$pdo = api_db($config);
auth_require($pdo, $config, 'leer', 'estado');

$today = date('d/m/Y');
$total = (int) $pdo->query('SELECT COUNT(*) FROM remitos')->fetchColumn();
$st = $pdo->prepare('SELECT COUNT(*) FROM remitos WHERE fecha = ?');
$st->execute([$today]);
$ofToday = (int) $st->fetchColumn();
$pendingCount = (int) $pdo->query('SELECT COUNT(*) FROM remitos WHERE facturado = 0')->fetchColumn();

$rows = $pdo->query(
    'SELECT numero, fecha, cliente FROM remitos WHERE facturado = 0 ORDER BY updated DESC LIMIT 50'
)->fetchAll();

$now = new DateTimeImmutable('today');
$pending = [];
foreach ($rows as $row) {
    $date = DateTimeImmutable::createFromFormat('!d/m/Y', (string) $row['fecha']);
    $pending[] = [
        'tipo' => 'remito',
        'numero' => $row['numero'] ?: '(sin número)',
        'fecha' => $row['fecha'],
        'cliente' => $row['cliente'] ?: '(sin cliente)',
        'estado' => 'Pendiente de facturar',
        'dias' => $date ? (int) $date->diff($now)->format('%r%a') : null,
    ];
}

$generated = date('d/m/Y H:i');

if (($_GET['formato'] ?? '') === 'texto') {
    header('Content-Type: text/plain; charset=utf-8');
    $text = "ESTADO INTELLIHELP · {$generated}\n"
        . "Remitos totales: {$total} · De hoy: {$ofToday} · Pendientes de facturar: {$pendingCount}\n";
    if ($pending) {
        $text .= "\nPendientes de facturar:\n";
        foreach (array_slice($pending, 0, 15) as $p) {
            $text .= sprintf(
                "  · Remito %s — %s — %s (%s días)\n",
                $p['numero'],
                $p['cliente'],
                $p['fecha'],
                $p['dias'] ?? '?'
            );
        }
    } else {
        $text .= "\nNo hay remitos pendientes de facturar.\n";
    }
    echo $text;
    exit;
}

api_respond(200, [
    'ok' => true,
    'generado' => $generated,
    'aviso' => 'Solo seguimiento. No incluye importes ni datos confidenciales.',
    'resumen' => [
        'remitos_totales' => $total,
        'remitos_de_hoy' => $ofToday,
        'pendientes_facturar' => $pendingCount,
    ],
    'pendientes' => $pending,
]);
