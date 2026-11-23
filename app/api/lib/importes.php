<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Importes y totales de los comprobantes. Es la misma lógica que app/js/calculos.js:
 * el servidor recalcula todo antes de pedir el CAE y nunca confía en los totales
 * que envía la interfaz.
 *
 *  - Los importes se leen en formato argentino («1.234,56»; también «1234.56» y
 *    «1.500» como mil quinientos) y se operan en centavos enteros.
 *  - Cada renglón (cantidad × precio, menos la bonificación) se redondea a centavos.
 *  - Cada renglón tiene su alícuota (si no la tiene, vale la del comprobante). El IVA
 *    se calcula por alícuota, sobre la suma de los renglones de esa alícuota, y ARCA
 *    recibe un importe por alícuota (Iva/AlicIva de WSFEv1).
 *  - Letra A: precios netos, IVA discriminado. Letra B: precios finales con IVA
 *    incluido (se discrimina solo para ARCA). Letra X: sin IVA.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/** Alícuotas de IVA admitidas y su código en WSFEv1. */
const IVA_ALICUOTAS = ['0' => 3, '2.5' => 9, '5' => 8, '10.5' => 4, '21' => 5, '27' => 6];

/** Número en formato argentino o internacional → float, o null si no es un número válido. */
function importe_numero(mixed $value): ?float
{
    $text = str_replace([' ', '$', "\u{00A0}"], '', trim((string) (is_scalar($value) ? $value : '')));
    if ($text === '') {
        return 0.0;
    }
    if (str_contains($text, ',')) {
        $text = str_replace(['.', ','], ['', '.'], $text);
    } elseif (preg_match('/^[1-9]\d{0,2}(\.\d{3})+$/', $text)) {   // 1.500 = mil quinientos; 0.500 = medio
        $text = str_replace('.', '', $text);
    }

    return preg_match('/^\d+(\.\d+)?$/', $text) ? (float) $text : null;
}

/** Importe → centavos enteros, o null si no es válido. */
function importe_centavos(mixed $value): ?int
{
    $n = importe_numero($value);

    return $n === null ? null : (int) round($n * 100);
}

/** Alícuota normalizada («21», «10.5»…) o null si no es una de las admitidas. */
function importe_alicuota(mixed $value): ?string
{
    $n = importe_numero($value);
    if ($n === null) {
        return null;
    }
    $key = rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');

    return array_key_exists($key, IVA_ALICUOTAS) ? $key : null;
}

/** Unidades de medida admitidas en los renglones (las mismas que UNIDADES de calculos.js). */
const IMPORTE_UNIDADES = [
    'unidades', 'horas', 'servicio', 'kilogramos', 'gramos', 'metros', 'metros cuadrados',
    'metros cúbicos', 'litros', 'pares', 'docenas', 'otras unidades',
];

/** Bonificación en porcentaje (0 a 100), o null si no es válida. Vacío = 0. */
function importe_bonificacion(mixed $value): ?float
{
    $n = importe_numero(str_replace('%', '', is_scalar($value) ? (string) $value : ''));

    return $n === null || $n > 100 ? null : $n;
}

/** Importe de un renglón en centavos (cantidad × precio menos la bonificación), o null si algo es inválido. */
function importe_item(array $item): ?int
{
    $cantidad = importe_numero($item['cantidad'] ?? '');
    $precio = importe_centavos($item['precio'] ?? '');
    $bonif = importe_bonificacion($item['bonif'] ?? '');
    if ($cantidad === null || $precio === null || $bonif === null) {
        return null;
    }

    return (int) round($cantidad * $precio * (1 - $bonif / 100));
}

/**
 * Totales de un comprobante en centavos, con el detalle por alícuota.
 *
 * @return array{neto: int, iva: int, total: int, alicuota: string,
 *               alicuotas: array<int, array{alicuota: string, neto: int, iva: int}>, errores: string[]}
 */
function importe_totales(array $doc): array
{
    $errores = [];
    $alicuotaDoc = $doc['alicuota'] ?? '21';
    $general = importe_alicuota($alicuotaDoc === '' ? '21' : $alicuotaDoc);
    if ($general === null) {
        $errores[] = 'La alícuota de IVA no es válida (0, 2,5, 5, 10,5, 21 o 27)';
        $general = '21';
    }
    $grupos = [];   // alícuota → suma de los renglones en centavos
    if (($doc['tipo'] ?? '') === 'recibo') {
        $monto = importe_centavos($doc['monto'] ?? '');
        if ($monto === null) {
            $errores[] = 'El monto del recibo no es un importe válido';
        }
        $grupos[$general] = $monto ?? 0;
    } else {
        foreach (array_values((array) ($doc['items'] ?? [])) as $i => $item) {
            $item = (array) $item;
            $n = $i + 1;
            $alicuota = $general;
            if (($item['alicuota'] ?? '') !== '' && ($item['alicuota'] ?? null) !== null) {
                $alicuota = importe_alicuota($item['alicuota']);
                if ($alicuota === null) {
                    $errores[] = 'El ítem ' . $n . ' tiene una alícuota de IVA inválida';
                    continue;
                }
            }
            if (importe_bonificacion($item['bonif'] ?? '') === null) {
                $errores[] = 'El ítem ' . $n . ' tiene una bonificación inválida (0 a 100 %)';
                continue;
            }
            $importe = importe_item($item);
            if ($importe === null) {
                $errores[] = 'El ítem ' . $n . ' tiene una cantidad o un precio inválido';
                continue;
            }
            $grupos[$alicuota] = ($grupos[$alicuota] ?? 0) + $importe;
        }
    }
    if ($grupos === []) {
        $grupos[$general] = 0;
    }
    uksort($grupos, static fn ($a, $b): int => (float) $b <=> (float) $a);

    $letra = strtoupper((string) ($doc['letra'] ?? 'X'));
    $neto = $iva = 0;
    $alicuotas = [];
    foreach ($grupos as $alicuota => $suma) {
        $alicuota = (string) $alicuota;
        $tasa = (float) $alicuota / 100;
        if ($letra === 'A') {
            $n = $suma;
            $v = (int) round($n * $tasa);
        } elseif ($letra === 'B') {
            $n = (int) round($suma / (1 + $tasa));
            $v = $suma - $n;
        } else {
            $n = $suma;
            $v = 0;
        }
        $neto += $n;
        $iva += $v;
        $alicuotas[] = ['alicuota' => $alicuota, 'neto' => $n, 'iva' => $v];
    }

    return [
        'neto' => $neto,
        'iva' => $iva,
        'total' => $neto + $iva,
        'alicuota' => count($alicuotas) === 1 ? $alicuotas[0]['alicuota'] : $general,
        'alicuotas' => $alicuotas,
        'errores' => $errores,
    ];
}
