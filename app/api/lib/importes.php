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
 *  - Cada renglón se redondea a centavos; el IVA se calcula sobre el neto total.
 *  - Letra A: precios netos, IVA discriminado. Letra B: precios finales con IVA
 *    incluido (se discrimina solo para ARCA). Letras C y X: sin IVA.
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
    } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $text)) {
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

/**
 * Totales de un comprobante en centavos.
 *
 * @return array{neto: int, iva: int, total: int, alicuota: string, errores: string[]}
 */
function importe_totales(array $doc): array
{
    $errores = [];
    $alicuota = importe_alicuota($doc['alicuota'] ?? '21');
    if ($alicuota === null) {
        $errores[] = 'La alícuota de IVA no es válida (0, 2,5, 5, 10,5, 21 o 27)';
        $alicuota = '21';
    }
    $suma = 0;
    if (($doc['tipo'] ?? '') === 'recibo') {
        $monto = importe_centavos($doc['monto'] ?? '');
        if ($monto === null) {
            $errores[] = 'El monto del recibo no es un importe válido';
        }
        $suma = $monto ?? 0;
    } else {
        foreach ((array) ($doc['items'] ?? []) as $i => $item) {
            $cantidad = importe_numero($item['cantidad'] ?? '');
            $precio = importe_centavos($item['precio'] ?? '');
            if ($cantidad === null || $precio === null) {
                $errores[] = 'El ítem ' . ($i + 1) . ' tiene una cantidad o un precio inválido';
                continue;
            }
            $suma += (int) round($cantidad * $precio);
        }
    }

    $letra = strtoupper((string) ($doc['letra'] ?? 'X'));
    $tasa = (float) $alicuota / 100;
    if ($letra === 'A') {
        $neto = $suma;
        $iva = (int) round($neto * $tasa);
        $total = $neto + $iva;
    } elseif ($letra === 'B') {
        $total = $suma;
        $neto = (int) round($total / (1 + $tasa));
        $iva = $total - $neto;
    } else {
        $neto = $total = $suma;
        $iva = 0;
    }

    return ['neto' => $neto, 'iva' => $iva, 'total' => $total, 'alicuota' => $alicuota, 'errores' => $errores];
}
