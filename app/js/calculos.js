/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Cálculos puros de importes, CUIT y montos en letras. Es la misma lógica que
 * api/lib/importes.php: el servidor recalcula todo antes de guardar y de pedir el
 * CAE. No depende del navegador, así que también se prueba con Node
 * (test/unitarias/calculos.test.js).
 *
 *  - Importes en formato argentino («1.234,56»), y también «1234.56» o «1.500»
 *    (mil quinientos). Las cuentas se hacen en centavos enteros.
 *  - Cada renglón se redondea a centavos; el IVA se calcula sobre el neto total.
 *  - Letra A: precios netos e IVA discriminado. Letra B: precios finales con IVA
 *    incluido. Letras C y X: sin IVA.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */
'use strict';

/** Alícuotas de IVA admitidas (las de la tabla de ARCA). */
const ALICUOTAS_IVA = ['0', '2.5', '5', '10.5', '21', '27'];

/** Número en formato argentino o internacional; NaN si no es un número válido. Vacío = 0. */
function numeroAR(v) {
  let t = String(v == null ? '' : v)
    .replace(/[\s$ ]/g, '')
    .trim();
  if (t === '') return 0;
  if (t.includes(',')) t = t.replace(/\./g, '').replace(',', '.');
  else if (/^\d{1,3}(\.\d{3})+$/.test(t)) t = t.replace(/\./g, '');
  return /^\d+(\.\d+)?$/.test(t) ? parseFloat(t) : NaN;
}

/** Como numeroAR, pero un valor inválido cuenta como 0 (para la vista previa). */
const numF = v => {
  const n = numeroAR(v);
  return isNaN(n) ? 0 : n;
};

/** Importe → centavos enteros (NaN si no es válido). */
const centavos = v => {
  const n = numeroAR(v);
  return isNaN(n) ? NaN : Math.round(n * 100);
};

/** Alícuota normalizada («21», «10.5»…) o null si no es una de las admitidas. */
function alicuotaIVA(v) {
  const n = numeroAR(v);
  if (isNaN(n)) return null;
  const k = String(Math.round(n * 10) / 10);
  return ALICUOTAS_IVA.includes(k) ? k : null;
}

/**
 * Totales de un comprobante. Devuelve los importes en pesos (neto, iva, total), los
 * mismos en centavos y la lista de errores de carga.
 */
function compTotals(d) {
  const errores = [];
  let alic = alicuotaIVA(d.alicuota == null ? '21' : d.alicuota);
  if (alic === null) {
    errores.push('La alícuota de IVA no es válida');
    alic = '21';
  }
  let suma = 0;
  if (d.tipo === 'recibo') {
    const m = centavos(d.monto);
    if (isNaN(m)) errores.push('El monto no es un importe válido');
    else suma = m;
  } else {
    (d.items || []).forEach((it, i) => {
      const cant = numeroAR(it.cantidad);
      const precio = centavos(it.precio);
      if (isNaN(cant) || isNaN(precio)) errores.push('El ítem ' + (i + 1) + ' tiene una cantidad o un precio inválido');
      else suma += Math.round(cant * precio);
    });
  }
  const tasa = parseFloat(alic) / 100;
  let neto, iva, total;
  if (d.letra === 'A') {
    neto = suma;
    iva = Math.round(neto * tasa);
    total = neto + iva;
  } else if (d.letra === 'B') {
    total = suma;
    neto = Math.round(total / (1 + tasa));
    iva = total - neto;
  } else {
    neto = total = suma;
    iva = 0;
  }
  return {
    neto: neto / 100,
    iva: iva / 100,
    total: total / 100,
    disc: d.letra === 'A',
    centavos: { neto, iva, total },
    alicuota: alic,
    errores,
  };
}

/** Subtotal de un renglón en pesos (redondeado a centavos). */
function subtotalItem(it) {
  const cant = numeroAR(it.cantidad);
  const precio = centavos(it.precio);
  return isNaN(cant) || isNaN(precio) ? 0 : Math.round(cant * precio) / 100;
}

/* ---- número a letras (para el recibo) ---- */
const _U = [
  '',
  'uno',
  'dos',
  'tres',
  'cuatro',
  'cinco',
  'seis',
  'siete',
  'ocho',
  'nueve',
  'diez',
  'once',
  'doce',
  'trece',
  'catorce',
  'quince',
  'dieciséis',
  'diecisiete',
  'dieciocho',
  'diecinueve',
  'veinte',
];
const _D = ['', '', 'veinti', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
const _C = [
  '',
  'ciento',
  'doscientos',
  'trescientos',
  'cuatrocientos',
  'quinientos',
  'seiscientos',
  'setecientos',
  'ochocientos',
  'novecientos',
];
const _VEINTI = { dos: 'dós', tres: 'trés', seis: 'séis' };

/** «uno» delante de un sustantivo: «un peso», «veintiún mil», «ciento un millones». */
const apocope = s => s.replace(/veintiuno$/, 'veintiún').replace(/(^| )uno$/, '$1un');

function enteroALetras(n) {
  if (n === 0) return 'cero';
  if (n <= 20) return _U[n];
  if (n < 30) return 'veinti' + (_VEINTI[_U[n - 20]] || _U[n - 20]);
  if (n < 100) {
    const d = Math.floor(n / 10),
      u = n % 10;
    return _D[d] + (u ? ' y ' + _U[u] : '');
  }
  if (n === 100) return 'cien';
  if (n < 1000) {
    const c = Math.floor(n / 100),
      r = n % 100;
    return _C[c] + (r ? ' ' + enteroALetras(r) : '');
  }
  if (n < 1000000) {
    const m = Math.floor(n / 1000),
      r = n % 1000;
    return (m === 1 ? 'mil' : apocope(enteroALetras(m)) + ' mil') + (r ? ' ' + enteroALetras(r) : '');
  }
  const mm = Math.floor(n / 1000000),
    r = n % 1000000;
  return (mm === 1 ? 'un millón' : apocope(enteroALetras(mm)) + ' millones') + (r ? ' ' + enteroALetras(r) : '');
}

/** Importe en letras: «Mil novecientos noventa y cinco pesos con 50/100». */
function montoALetras(v) {
  const c = centavos(v);
  const total = isNaN(c) ? 0 : c;
  const ent = Math.floor(total / 100),
    cent = total % 100;
  const millones = ent >= 1000000 && ent % 1000000 === 0;
  let s = apocope(enteroALetras(ent)) + (millones ? ' de' : '') + ' peso' + (ent === 1 ? '' : 's');
  if (cent > 0) s += ' con ' + String(cent).padStart(2, '0') + '/100';
  return s.charAt(0).toUpperCase() + s.slice(1);
}

/* ---- CUIT ---- */

/** ¿CUIT/CUIL de 11 dígitos con dígito verificador correcto (módulo 11)? */
function cuitValido(v) {
  const d = String(v || '').replace(/\D/g, '');
  if (d.length !== 11) return false;
  const pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
  const suma = pesos.reduce((s, p, i) => s + p * Number(d[i]), 0);
  let dv = 11 - (suma % 11);
  if (dv === 11) dv = 0;
  if (dv === 10) dv = 9;
  return dv === Number(d[10]);
}

/** CUIT con guiones mientras se tipea (99-99999999-9). */
function cuitConGuiones(v) {
  const d = String(v || '')
    .replace(/\D/g, '')
    .slice(0, 11);
  if (d.length <= 2) return d;
  if (d.length <= 10) return d.slice(0, 2) + '-' + d.slice(2);
  return d.slice(0, 2) + '-' + d.slice(2, 10) + '-' + d.slice(10);
}

/** Mientras se tipea: un DNI (hasta 8 dígitos) queda tal cual; un CUIT lleva guiones. */
function formatoCuitODni(v) {
  const d = String(v || '').replace(/\D/g, '');
  return d.length > 8 ? cuitConGuiones(d) : d;
}

/** Problema del CUIT/DNI ingresado, o '' si está bien (vacío también está bien). */
function problemaCuit(v) {
  const d = String(v || '').replace(/\D/g, '');
  if (d === '' || /^\d{7,8}$/.test(d)) return '';
  if (d.length !== 11) return 'El CUIT debe tener 11 dígitos (o 7 u 8 si es un DNI)';
  return cuitValido(d) ? '' : 'El CUIT no es válido: el dígito verificador no coincide';
}

/* ---- Remitos: contenido obligatorio (RG 1415, Anexo V) ----
   Mismas reglas que api/lib/normativa.php: el servidor no autoriza un remito incompleto. */

/** Condición del destinatario frente al IVA → leyenda que se imprime en el remito. */
const LEYENDAS_IVA = {
  'Responsable Inscripto': 'IVA RESPONSABLE INSCRIPTO',
  Exento: 'IVA EXENTO',
  'No Responsable': 'NO RESPONSABLE IVA',
  Monotributo: 'RESPONSABLE MONOTRIBUTO',
  'Consumidor Final': 'A CONSUMIDOR FINAL',
  'Sujeto no categorizado': 'SUJETO NO CATEGORIZADO',
};

/** Lo que le falta a un remito para poder autorizarse (vacío si está completo). */
function faltantesRemito(d) {
  const faltan = [];
  const c = d.cliente || {},
    t = d.transporte || {};
  const vacio = v => !String(v || '').trim();
  if (!/^\d{2}\/\d{2}\/\d{4}$/.test(d.fecha || '')) faltan.push('la fecha de emisión');
  if (vacio(c.razonSocial) && vacio(c.senores)) faltan.push('el nombre o la razón social del destinatario');
  if (!LEYENDAS_IVA[c.condIVA]) faltan.push('la condición del destinatario frente al IVA');
  else if (c.condIVA !== 'Consumidor Final') {
    if (!cuitValido(c.cuit)) faltan.push('el CUIT del destinatario');
    if (vacio(c.domicilio)) faltan.push('el domicilio comercial del destinatario');
  }
  const items = (d.items || []).filter(i => !vacio(i.descripcion));
  if (!items.length) faltan.push('la descripción de los bienes que se trasladan');
  items.forEach((i, n) => vacio(i.cantidad) && faltan.push('la cantidad del ítem ' + (n + 1)));
  if (t.tipo === 'tercero') {
    if (vacio(t.nombre)) faltan.push('el nombre o la razón social del transportista');
    if (!cuitValido(t.cuit)) faltan.push('el CUIT del transportista');
    if (vacio(t.domicilio)) faltan.push('el domicilio comercial del transportista');
  } else if (t.tipo !== 'propio') faltan.push('si el traslado es con transporte propio o de un tercero');
  return faltan;
}

if (typeof module !== 'undefined' && module.exports) {
  module.exports = {
    numeroAR,
    numF,
    centavos,
    alicuotaIVA,
    compTotals,
    subtotalItem,
    enteroALetras,
    montoALetras,
    cuitValido,
    cuitConGuiones,
    formatoCuitODni,
    problemaCuit,
    LEYENDAS_IVA,
    faltantesRemito,
  };
}
