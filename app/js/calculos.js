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
 *  - Cada renglón (cantidad × precio, menos la bonificación) se redondea a centavos.
 *  - Cada renglón tiene su alícuota de IVA (si no la tiene, vale la del comprobante).
 *    El IVA se calcula por alícuota, sobre la suma de los renglones de esa alícuota,
 *    y ARCA recibe un importe por alícuota (Iva/AlicIva de WSFEv1).
 *  - Letra A: precios netos e IVA discriminado. Letra B: precios finales con IVA
 *    incluido. Letra X: sin IVA.
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
  else if (/^[1-9]\d{0,2}(\.\d{3})+$/.test(t)) t = t.replace(/\./g, ''); // 1.500 = mil quinientos; 0.500 = medio
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

/** Unidades de medida para los renglones de facturas y notas. */
const UNIDADES = [
  'unidades',
  'horas',
  'servicio',
  'kilogramos',
  'gramos',
  'metros',
  'metros cuadrados',
  'metros cúbicos',
  'litros',
  'pares',
  'docenas',
  'otras unidades',
];

/** Bonificación de un renglón en porcentaje (0 a 100); NaN si no es válida. Vacío = 0. */
function bonificacion(v) {
  const n = numeroAR(String(v == null ? '' : v).replace('%', ''));
  return isNaN(n) || n > 100 ? NaN : n;
}

/** Importe de un renglón en centavos: cantidad × precio menos la bonificación (NaN si algo es inválido). */
function centavosItem(it) {
  const cant = numeroAR(it.cantidad);
  const precio = centavos(it.precio);
  const bonif = bonificacion(it.bonif);
  if (isNaN(cant) || isNaN(precio) || isNaN(bonif)) return NaN;
  return Math.round(cant * precio * (1 - bonif / 100));
}

/**
 * Totales de un comprobante. Devuelve los importes en pesos (neto, iva, total), los
 * mismos en centavos, el detalle por alícuota y la lista de errores de carga.
 */
function compTotals(d) {
  const errores = [];
  let general = alicuotaIVA(d.alicuota == null || d.alicuota === '' ? '21' : d.alicuota);
  if (general === null) {
    errores.push('La alícuota de IVA no es válida');
    general = '21';
  }
  const grupos = {}; // alícuota → suma de los renglones en centavos
  if (d.tipo === 'recibo') {
    const m = centavos(d.monto);
    if (isNaN(m)) errores.push('El monto no es un importe válido');
    else grupos[general] = m;
  } else {
    (d.items || []).forEach((it, i) => {
      const n = i + 1;
      let alic = general;
      if (it.alicuota != null && it.alicuota !== '') {
        alic = alicuotaIVA(it.alicuota);
        if (alic === null) {
          errores.push('El ítem ' + n + ' tiene una alícuota de IVA inválida');
          return;
        }
      }
      if (isNaN(bonificacion(it.bonif))) {
        errores.push('El ítem ' + n + ' tiene una bonificación inválida (0 a 100 %)');
        return;
      }
      const imp = centavosItem(it);
      if (isNaN(imp)) errores.push('El ítem ' + n + ' tiene una cantidad o un precio inválido');
      else grupos[alic] = (grupos[alic] || 0) + imp;
    });
  }
  if (!Object.keys(grupos).length) grupos[general] = 0;
  const alicuotas = [];
  let neto = 0,
    iva = 0;
  Object.keys(grupos)
    .sort((a, b) => parseFloat(b) - parseFloat(a))
    .forEach(alic => {
      const suma = grupos[alic],
        tasa = parseFloat(alic) / 100;
      let n, v;
      if (d.letra === 'A') {
        n = suma;
        v = Math.round(n * tasa);
      } else if (d.letra === 'B') {
        n = Math.round(suma / (1 + tasa));
        v = suma - n;
      } else {
        n = suma;
        v = 0;
      }
      neto += n;
      iva += v;
      alicuotas.push({ alicuota: alic, neto: n / 100, iva: v / 100, centavos: { neto: n, iva: v } });
    });
  const total = neto + iva;
  return {
    neto: neto / 100,
    iva: iva / 100,
    total: total / 100,
    disc: d.letra === 'A',
    centavos: { neto, iva, total },
    alicuota: alicuotas.length === 1 ? alicuotas[0].alicuota : general,
    alicuotas,
    errores,
  };
}

/** Subtotal de un renglón en pesos, con la bonificación aplicada (redondeado a centavos). */
function subtotalItem(it) {
  const c = centavosItem(it);
  return isNaN(c) ? 0 : c / 100;
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

/** Condición frente al IVA del receptor: tabla oficial de ARCA (RG 5616), con la clase de
   comprobante que admite y la leyenda que se imprime. Misma tabla que api/lib/normativa.php. */
const CONDICIONES_IVA = {
  'Responsable Inscripto': { codigo: 1, clase: 'A', leyenda: 'IVA RESPONSABLE INSCRIPTO' },
  Monotributo: { codigo: 6, clase: 'A', leyenda: 'RESPONSABLE MONOTRIBUTO' },
  'Monotributista Social': { codigo: 13, clase: 'A', leyenda: 'MONOTRIBUTISTA SOCIAL' },
  'Monotributo Trabajador Independiente Promovido': {
    codigo: 16,
    clase: 'A',
    leyenda: 'MONOTRIBUTO TRABAJADOR INDEPENDIENTE PROMOVIDO',
  },
  Exento: { codigo: 4, clase: 'B', leyenda: 'IVA SUJETO EXENTO' },
  'Consumidor Final': { codigo: 5, clase: 'B', leyenda: 'A CONSUMIDOR FINAL' },
  'Sujeto no categorizado': { codigo: 7, clase: 'B', leyenda: 'SUJETO NO CATEGORIZADO' },
  'Proveedor del Exterior': { codigo: 8, clase: 'B', leyenda: 'PROVEEDOR DEL EXTERIOR' },
  'Cliente del Exterior': { codigo: 9, clase: 'B', leyenda: 'CLIENTE DEL EXTERIOR' },
  'IVA Liberado - Ley 19.640': { codigo: 10, clase: 'B', leyenda: 'IVA LIBERADO - LEY N° 19.640' },
  'IVA No Alcanzado': { codigo: 15, clase: 'B', leyenda: 'IVA NO ALCANZADO' },
};
/** Leyenda que se imprime en el remito (RG 1415, Anexo V, apartado II). */
const LEYENDAS_IVA = Object.fromEntries(Object.entries(CONDICIONES_IVA).map(([k, v]) => [k, v.leyenda]));
/** Condición frente al IVA escrita de cualquier forma (planillas, sistemas viejos) →
   nombre de la tabla oficial, o '' si no se reconoce. */
function normalizarCondIVA(texto) {
  const t = String(texto || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9 ]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
  if (!t) return '';
  const exacta = Object.keys(CONDICIONES_IVA).find(
    k =>
      k
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '') === t
  );
  if (exacta) return exacta;
  if (/^(ri|r i|resp(onsable)? inscripto|iva responsable inscripto|inscripto)$/.test(t)) return 'Responsable Inscripto';
  if (/trabajador independiente/.test(t)) return 'Monotributo Trabajador Independiente Promovido';
  if (/monotribut(ista|o) social/.test(t)) return 'Monotributista Social';
  if (/^(mt|monotributo|monotributista|responsable monotributo|rm)$/.test(t)) return 'Monotributo';
  if (/^(ex|exento|iva exento|iva sujeto exento|sujeto exento)$/.test(t)) return 'Exento';
  if (/^(cf|consumidor final|a consumidor final|final)$/.test(t)) return 'Consumidor Final';
  if (/no categorizado/.test(t)) return 'Sujeto no categorizado';
  if (/proveedor del exterior/.test(t)) return 'Proveedor del Exterior';
  if (/cliente del exterior|exterior/.test(t)) return 'Cliente del Exterior';
  if (/liberado|19 ?640/.test(t)) return 'IVA Liberado - Ley 19.640';
  if (/no alcanzado/.test(t)) return 'IVA No Alcanzado';
  return '';
}
/** Condiciones de receptores sin CUIT argentino. */
const IVA_SIN_CUIT = ['Consumidor Final', 'Cliente del Exterior', 'Proveedor del Exterior'];

/** Lo que le falta a un remito para poder autorizarse (vacío si está completo). */
function faltantesRemito(d) {
  const faltan = [];
  const c = d.cliente || {},
    t = d.transporte || {};
  const vacio = v => !String(v || '').trim();
  if (!/^\d{2}\/\d{2}\/\d{4}$/.test(d.fecha || '')) faltan.push('la fecha de emisión');
  if (vacio(c.razonSocial) && vacio(c.senores)) faltan.push('el nombre o la razón social del destinatario');
  if (!LEYENDAS_IVA[c.condIVA]) faltan.push('la condición del destinatario frente al IVA');
  else if (!IVA_SIN_CUIT.includes(c.condIVA)) {
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
    bonificacion,
    centavosItem,
    UNIDADES,
    compTotals,
    subtotalItem,
    enteroALetras,
    montoALetras,
    cuitValido,
    cuitConGuiones,
    formatoCuitODni,
    problemaCuit,
    LEYENDAS_IVA,
    CONDICIONES_IVA,
    normalizarCondIVA,
    faltantesRemito,
  };
}
