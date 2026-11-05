/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Configuración de diseño: tamaños de papel, posición de cada campo del remito,
 * calibración de fábrica y datos fiscales del emisor.
 *
 * Coordenadas: x, y, w en % de la hoja de referencia; tamaños de letra en mm.
 * Las posiciones del modo «sobre pre-impreso» se midieron sobre el talonario
 * impreso y se afinan desde el panel de calibración de cada equipo.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */
'use strict';

const APP_VERSION = '2.0.0';

/* Sistema de coordenadas de calibración (mm). Los % de los campos se
   interpretan SIEMPRE sobre este alto/ancho de referencia, sin importar el
   tamaño real de la hoja. Así la página puede ajustarse a A4 (297 mm) para
   entrar en una sola hoja sin mover ningún dato de su lugar calibrado. */
const REF = { w: 212, h: 300 };

/* Tamaños de papel (mm). El remito entra en A4; usamos alto 297 para no
   generar una 2ª hoja en impresoras A4. */
const PAPER_SIZES = {
  Remito: { w: 212, h: 297, label: 'Remito / A4 (212 × 297 mm)' },
  A4: { w: 210, h: 297, label: 'A4 (210 × 297 mm)' },
  Oficio: { w: 216, h: 330, label: 'Oficio (216 × 330 mm)' },
};

/* Códigos oficiales de tipo de comprobante (tabla de ARCA · TABLACOMPROBANTES.xls).
   Verificados contra las facturas reales de ejemplo: Factura A = 001, Factura C = 011,
   Remito R = 091. Se muestran DEBAJO de la letra, como exige el Anexo II Ap. B. */
const COMP_CODIGOS = {
  factura: { A: '001', B: '006', C: '011', M: '051', X: '—' },
  nd: { A: '002', B: '007', C: '012', M: '052', X: '—' }, // Nota de Débito
  nc: { A: '003', B: '008', C: '013', M: '053', X: '—' }, // Nota de Crédito
  recibo: { A: '004', B: '009', C: '015', M: '054', X: '—' },
};
function codigoComprobante(tipo, subtipo, letra) {
  const fam = tipo === 'recibo' ? 'recibo' : tipo === 'nota' ? (subtipo === 'debito' ? 'nd' : 'nc') : 'factura';
  return (COMP_CODIGOS[fam] && COMP_CODIGOS[fam][letra]) || '—';
}

/* Tamaño del DOCUMENTO COMPLETO (lo generamos nosotros, no hay formulario preimpreso).
   "RemitoReal" = medida del remito físico (212 × 300 mm), obtenida midiendo la hoja del
   duplicado en el escaneo (204 × 288 mm) y corrigiendo la reducción del escáner (~96%).
   RG 1415 arts. 19 y 30: sólo exigen un mínimo de 15 × 20 cm; no imponen A4 ni otro formato. */
const PAPER_FULL = {
  RemitoReal: { w: 212, h: 300, label: 'Remito real (212 × 300 mm)' },
  A4: { w: 210, h: 297, label: 'A4 (210 × 297 mm)' },
  Oficio: { w: 216, h: 330, label: 'Oficio (216 × 330 mm)' },
};

/* Calibración por defecto (se guarda por navegador en localStorage). */
const DEFAULT_CALIB = {
  paper: 'Remito',
  offsetX: 0, // mm  (+ derecha)
  offsetY: 0, // mm  (+ abajo)
  scaleX: 100, // %   estiramiento horizontal fino
  scaleY: 100, // %   estiramiento vertical fino
  guideOpacity: 22, // %   opacidad del formulario guía en pantalla
  fontScale: 100, // %   escala global de las tipografías
  /* Ajuste fino de fábrica para el talonario PRE-IMPRESO, medido sobre impresiones
     reales. Es el punto de partida; cada equipo puede retocarlo desde "Ajustar campos"
     y ese retoque se guarda aparte, sin pisar estos valores. */
  adjust: {
    fecha: { dx: -3.5, dy: 5 },
    'cliente.senores': { dx: 2, dy: 3.5 },
    'cliente.razonSocial': { dx: 2, dy: 3.5 },
    'cliente.cuit': { dx: 2, dy: 2.5 },
    'cliente.domicilio': { dx: 2, dy: 2.5 },
    'cliente.localidad': { dx: 2, dy: 2.5 },
    'cliente.otrosDatos': { dx: 2, dy: 2 },
    'cliente.telefono': { dx: -2.5, dy: 3 },
    'cliente.cp': { dx: -2.5, dy: 3 },
    'cliente.mail': { dx: -2.5, dy: 3 },
    'cliente.nombre': { dx: -2.5, dy: 2.5 },
    items: { dx: -1, dy: 1 },
    tipoDoc: { dx: -1, dy: -2 },
    'recibi.nombre': { dx: 2, dy: -2.5 },
    'recibi.documentoNro': { dx: 2, dy: -3 },
    'recibi.sector': { dx: 2, dy: -3 },
    'recibi.puesto': { dx: 2, dy: -3 },
    'transporte.nombre': { dx: 2.5, dy: -5.5 },
    'transporte.vehiculo': { dx: 2.5, dy: -5.5 },
    'transporte.chofer': { dx: 2.5, dy: -5.5 },
    'transporte.lugarEntrega': { dx: 2.5, dy: -5.5 },
    'transporte.fecha': { dx: -2, dy: -4.5 },
    'transporte.hora': { dx: -2, dy: -4.5 },
    'transporte.observaciones': { dx: 3, dy: -5.5 },
  },
  docMode: 'overlay', // 'overlay' = imprimir sobre el pre-impreso · 'full' = documento completo autogenerado (papel en blanco / PDF)

  /* --- Ajustes del DOCUMENTO COMPLETO (independientes del pre-impreso) ---------
     Son un juego aparte: mover algo en el PDF no toca la calibración del overlay. */
  fullPaper: 'RemitoReal',
  fullOffsetX: 0,
  fullOffsetY: 0, // mm
  fullScaleX: 100,
  fullScaleY: 100, // %
  fullFontScale: 100, // %
  adjustFull: {}, // arranca en cero: el PDF ya sale bien de fábrica
};

/* Campos con ajuste fino individual (X/Y en mm) — orden del panel "Ajustar campos" */
const ADJUSTABLE = [
  { path: 'fecha', label: 'Fecha (encabezado)' },
  { path: 'cliente.senores', label: 'Señor/es' },
  { path: 'cliente.razonSocial', label: 'Razón Social' },
  { path: 'cliente.cuit', label: 'CUIT' },
  { path: 'cliente.domicilio', label: 'Domicilio' },
  { path: 'cliente.localidad', label: 'Localidad' },
  { path: 'cliente.otrosDatos', label: 'Otros Datos' },
  { path: 'cliente.telefono', label: 'Teléfono' },
  { path: 'cliente.cp', label: 'C.P.' },
  { path: 'cliente.mail', label: 'Mail' },
  { path: 'cliente.nombre', label: 'Nombre (contacto)' },
  { path: 'items', label: 'Ítems (toda la tabla)' },
  { path: 'tipoDoc', label: 'Tipo de documento (X)' },
  { path: 'recibi.nombre', label: 'Recibí · Nombre y Apellido' },
  { path: 'recibi.documentoNro', label: 'Recibí · Documento Nro.' },
  { path: 'recibi.sector', label: 'Recibí · Sector' },
  { path: 'recibi.puesto', label: 'Recibí · Puesto' },
  { path: 'transporte.nombre', label: 'Transp. · Nombre' },
  { path: 'transporte.vehiculo', label: 'Transp. · Vehículo' },
  { path: 'transporte.chofer', label: 'Transp. · Chofer' },
  { path: 'transporte.lugarEntrega', label: 'Transp. · Lugar de entrega' },
  { path: 'transporte.fecha', label: 'Transp. · Fecha' },
  { path: 'transporte.hora', label: 'Transp. · Hora' },
  { path: 'transporte.observaciones', label: 'Observaciones' },
];

/* ---------------------------------------------------------------------------
 *  Campos escalares  ->  { path, x, y, w, size, align, upper }
 *  path apunta al modelo de datos del remito (ver app.js).
 * ------------------------------------------------------------------------- */
const FIELDS = [
  /* Coordenadas medidas sobre el REMITO REAL con la grilla mm impresa encima
     (ya incluye el comportamiento físico de la impresora). % de página 212×300. */

  /* Fecha (encabezado, en el renglón bajo "Fecha:") */
  { path: 'fecha', label: 'Fecha', x: 71.76, y: 11.23, w: 22, size: 3.5, align: 'left' },

  /* --- Datos del cliente (caja "Señor/es") --- */
  { path: 'cliente.senores', label: 'Señor/es', x: 12.5, y: 18.87, w: 46, size: 3.5, align: 'left' },
  { path: 'cliente.razonSocial', label: 'Razón Social', x: 15.53, y: 21.43, w: 40, size: 3.5, align: 'left' },
  { path: 'cliente.cuit', label: 'CUIT', x: 10.08, y: 23.83, w: 30, size: 3.5, align: 'left' },
  { path: 'cliente.domicilio', label: 'Domicilio', x: 13.17, y: 26.33, w: 40, size: 3.5, align: 'left' },
  { path: 'cliente.localidad', label: 'Localidad', x: 13.46, y: 28.83, w: 40, size: 3.5, align: 'left' },
  { path: 'cliente.otrosDatos', label: 'Otros Datos', x: 15.1, y: 31.37, w: 40, size: 3.3, align: 'left' },
  { path: 'cliente.telefono', label: 'Teléfono', x: 73.12, y: 20.93, w: 22, size: 3.5, align: 'left' },
  { path: 'cliente.cp', label: 'C.P.', x: 69.65, y: 23.33, w: 25, size: 3.5, align: 'left' },
  { path: 'cliente.mail', label: 'Mail', x: 70.12, y: 25.67, w: 25, size: 3.5, align: 'left' },
  { path: 'cliente.nombre', label: 'Nombre', x: 72.89, y: 28.33, w: 22, size: 3.5, align: 'left' },

  /* --- Recibí conforme --- */
  { path: 'recibi.nombre', label: 'Nombre y Apellido', x: 22.37, y: 70.2, w: 30, size: 3.4, align: 'left' },
  { path: 'recibi.documentoNro', label: 'Documento Nro.', x: 21.01, y: 72.4, w: 26, size: 3.4, align: 'left' },
  { path: 'recibi.sector', label: 'Sector', x: 13.48, y: 74.43, w: 30, size: 3.4, align: 'left' },
  { path: 'recibi.puesto', label: 'Puesto', x: 13.68, y: 76.53, w: 30, size: 3.4, align: 'left' },

  /* --- Datos del transportista --- */
  { path: 'transporte.nombre', label: 'Nombre', x: 13.85, y: 81.47, w: 33, size: 3.1, align: 'left' },
  { path: 'transporte.vehiculo', label: 'Datos del Vehículo', x: 20.93, y: 83.0, w: 24, size: 3.1, align: 'left' },
  { path: 'transporte.chofer', label: 'Chofer', x: 12.61, y: 84.27, w: 33, size: 3.1, align: 'left' },
  { path: 'transporte.lugarEntrega', label: 'Lugar de Entrega', x: 19.7, y: 85.8, w: 24, size: 3.1, align: 'left' },
  { path: 'transporte.fecha', label: 'Fecha (transp.)', x: 58.74, y: 84.67, w: 12, size: 3.2, align: 'center' },
  { path: 'transporte.hora', label: 'Hora', x: 79.82, y: 84.67, w: 12.5, size: 3.2, align: 'center' },
];

/* Observaciones: bloque multilínea (dentro del recuadro grande) */
const OBSERVACIONES = { path: 'transporte.observaciones', x: 7.5, y: 89.37, w: 85, size: 3.0, lineH: 3.7 };

/* Tipo de documento: posición de la "X" dentro de cada casillero */
const DOC_TYPES = {
  DNI: { x: 50.86, y: 68.67, size: 3.4 },
  CI: { x: 57.16, y: 68.67, size: 3.4 },
  Pasaporte: { x: 62.36, y: 68.67, size: 3.4 },
  Otro: { x: 73.46, y: 68.67, size: 3.4 },
};

/* ---------------------------------------------------------------------------
 *  Tabla de ítems (ARTÍCULO | DESCRIPCIÓN | CANTIDAD)
 *  Región de flujo libre; cada ítem puede ocupar varias líneas.
 *  Columnas medidas: art 10-36.5 mm · desc 36.5-182 mm · cant 182-207 mm.
 * ------------------------------------------------------------------------- */
const ITEMS_REGION = {
  yTop: 36.33,
  yBottom: 67.03,
  cols: {
    articulo: { x: 4.06, w: 12.0, align: 'center' },
    descripcion: { x: 17.56, w: 66.0, align: 'left' },
    cantidad: { x: 85.06, w: 11.0, align: 'center' },
  },
  size: 3.1,
  lineH: 3.9,
  rowGap: 1.4,
};

/* El documento completo (PDF) lo generamos nosotros, así que sale bien DE FÁBRICA:
   estas correcciones (en mm) se hornean en las coordenadas del modo PDF y no quedan
   como "ajustes" encima. El panel de ajuste fino arranca en cero, igual que en
   Factura y Recibo. El modo overlay no se toca: sigue con sus propias coordenadas. */
const FULL_FIX = {
  fecha: [0, 0],
  'cliente.senores': [0, 0],
  'cliente.razonSocial': [0, 0],
  'cliente.cuit': [0, 0],
  'cliente.domicilio': [0, 0],
  'cliente.localidad': [0, 0],
  'cliente.otrosDatos': [0, 0],
  'cliente.telefono': [0, 0],
  'cliente.cp': [0, 0],
  'cliente.mail': [0, 0],
  'cliente.nombre': [0, 0],
  items: [0, 0],
  tipoDoc: [0, -1] /* la X del casillero, ajustada sobre el PDF */,
  'recibi.nombre': [0, 0],
  'recibi.documentoNro': [0, 0],
  'recibi.sector': [0, 0],
  'recibi.puesto': [0, 0],
  'transporte.nombre': [0, 0],
  'transporte.vehiculo': [0, 0],
  'transporte.chofer': [0, 0],
  'transporte.lugarEntrega': [0, 0],
  'transporte.fecha': [0, 0],
  'transporte.hora': [0, 0],
};
const _mmX = mm => (mm / REF.w) * 100; // mm -> % de página
const _mmY = mm => (mm / REF.h) * 100;
const _r3 = n => Math.round(n * 1000) / 1000;

/* Coordenadas del modo PDF, con la corrección ya incorporada */
const FIELDS_FULL = FIELDS.map(f => {
  const d = FULL_FIX[f.path];
  return d ? Object.assign({}, f, { x: _r3(f.x + _mmX(d[0])), y: _r3(f.y + _mmY(d[1])) }) : f;
});
const ITEMS_REGION_FULL = (() => {
  const [dx, dy] = FULL_FIX['items'] || [0, 0];
  const c = ITEMS_REGION.cols;
  return Object.assign({}, ITEMS_REGION, {
    yTop: _r3(ITEMS_REGION.yTop + _mmY(dy)),
    yBottom: _r3(ITEMS_REGION.yBottom + _mmY(dy)),
    cols: {
      articulo: Object.assign({}, c.articulo, { x: _r3(c.articulo.x + _mmX(dx)) }),
      descripcion: Object.assign({}, c.descripcion, { x: _r3(c.descripcion.x + _mmX(dx)) }),
      cantidad: Object.assign({}, c.cantidad, { x: _r3(c.cantidad.x + _mmX(dx)) }),
    },
  });
})();
const DOC_TYPES_FULL = (() => {
  const [dx, dy] = FULL_FIX['tipoDoc'] || [0, 0];
  const o = {};
  for (const k in DOC_TYPES)
    o[k] = Object.assign({}, DOC_TYPES[k], { x: _r3(DOC_TYPES[k].x + _mmX(dx)), y: _r3(DOC_TYPES[k].y + _mmY(dy)) });
  return o;
})();
const OBSERVACIONES_FULL = (() => {
  const [dx, dy] = FULL_FIX['transporte.observaciones'] || [0, 0];
  return Object.assign({}, OBSERVACIONES, { x: _r3(OBSERVACIONES.x + _mmX(dx)), y: _r3(OBSERVACIONES.y + _mmY(dy)) });
})();

/* Remito con datos ficticios para la impresión de prueba (botón «Prueba»):
   permite verificar el calce sobre el talonario sin tocar el documento en curso. */
const SAMPLE_REMITO = {
  fecha: '',
  cliente: {
    senores: 'Sres. Compras',
    razonSocial: 'DISTRIBUIDORA EJEMPLO S.A.',
    cuit: '30-50001091-2',
    domicilio: 'Av. Corrientes 1234, piso 5',
    localidad: 'CABA',
    telefono: '011 4000-0000',
    cp: 'C1043',
    mail: 'compras@ejemplo.com.ar',
    nombre: 'María Gómez',
    otrosDatos: 'Orden de compra 4471',
  },
  items: [
    {
      articulo: '1',
      descripcion: 'Reparación de cerradura electromagnética del acceso principal',
      cantidad: '3 hs x 1 téc.',
    },
    {
      articulo: '2',
      descripcion: 'Reconfiguración de lector biométrico del sector administración',
      cantidad: '4 hs x 1 téc.',
    },
    {
      articulo: '3',
      descripcion: 'Revisión eléctrica de sala de servidores: tensiones, tableros y UPS',
      cantidad: '6 hs x 2 téc.',
    },
  ],
  recibi: { tipoDoc: 'DNI', nombre: 'María Gómez', documentoNro: '30.123.456', sector: 'Compras', puesto: 'Jefa' },
  transporte: {
    nombre: 'Transportes Ejemplo',
    vehiculo: 'Utilitario AB123CD',
    chofer: 'Juan Pérez',
    lugarEntrega: 'Av. Corrientes 1234',
    fecha: '',
    hora: '14:30',
    observaciones: 'Entregar en recepción.',
  },
};

/* Datos fiscales del emisor, impresos en el documento completo (papel en blanco o PDF). */
const COMPANY = {
  nombre: 'INTELLIHELP S.A.',
  cuit: '30-71140975-7',
  telefono: '(+5411) 3986-0462',
  cp: 'C1414CKM',
  direccion: 'Dorrego 1789 6to piso of 604',
  localidad: 'CABA',
  mail: 'info@intellihelp.tech',
  ingBrutos: '901-30-71140975-7', // Ing. Brutos C.M. Nº
  condIVA: 'IVA RESPONSABLE INSCRIPTO', // Leyenda obligatoria del emisor (RG 1415, Anexo V, I.a.5)
  inicioAct: '10/03/2010', // Fecha inicio de actividades
  codigo: '091', // Código de comprobante (Remito R)
  logo: 'assets/logos/ITH-logo-139x75.png',
};

/* Punto de venta por defecto de los remitos. El número fiscal, el C.A.I. y su
   vencimiento los asigna «Autorizar» contra el talonario cargado en el panel. */
const REMITO_FISCAL = {
  ptoVenta: '00001',
};
