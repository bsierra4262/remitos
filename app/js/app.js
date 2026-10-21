/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Lógica de la aplicación: edición, vista previa e impresión de remitos y
 * comprobantes, agenda de clientes, calibración por impresora, respaldo en el
 * servidor y conexión con ARCA.
 *
 * Los datos (clientes, remitos, comprobantes y ajustes) viven en el servidor y
 * los comparte todo el equipo (ver servidor.js). En el navegador solo quedan la
 * calibración de la impresora, las preferencias de pantalla y el borrador en
 * curso de cada usuario, que protege el trabajo ante un corte de conexión.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */
'use strict';

/* Claves del almacenamiento local. Las de borrador se vuelven propias de cada
   usuario al ingresar (servidor.js · aplicarClavesLocales). */
const LS = {
  calib: 'remitos_calib',
  draft: 'remitos_draft',
  compDraft: 'remitos_compdraft',
  sidebar: 'remitos_sidebar',
};
const MM_PER_PX = 25.4 / 96;
const APP_TITLE = 'Remitos y Comprobantes · Intellihelp';

/* ------------------------------- Utilidades ------------------------------ */
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const load = (k, def) => {
  try {
    return JSON.parse(localStorage.getItem(k)) ?? def;
  } catch {
    return def;
  }
};
/* Si el almacenamiento está lleno o bloqueado, se avisa en lugar de perder el dato en silencio. */
const save = (k, v) => {
  try {
    localStorage.setItem(k, JSON.stringify(v));
    return true;
  } catch (e) {
    toast('No se pudo guardar en este navegador: el almacenamiento está lleno o bloqueado', 4200, 'err');
    return false;
  }
};
const uid = () => Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
const clone = o => JSON.parse(JSON.stringify(o));

function getPath(o, p) {
  return p.split('.').reduce((a, k) => (a == null ? a : a[k]), o);
}
function setPath(o, p, v) {
  const ks = p.split('.');
  const last = ks.pop();
  let t = o;
  for (const k of ks) {
    t[k] = t[k] || {};
    t = t[k];
  }
  t[last] = v;
}

/* -------------------------------- Estado --------------------------------- */
function emptyRemito() {
  return {
    id: null,
    numero: '',
    fecha: '',
    estado: 'borrador',
    cai: '',
    caiVto: '',
    cliente: {
      senores: '',
      razonSocial: '',
      cuit: '',
      domicilio: '',
      localidad: '',
      telefono: '',
      cp: '',
      mail: '',
      nombre: '',
      otrosDatos: '',
    },
    items: [{ articulo: '', descripcion: '', cantidad: '' }],
    recibi: { tipoDoc: '', nombre: '', documentoNro: '', sector: '', puesto: '' },
    transporte: { nombre: '', vehiculo: '', chofer: '', lugarEntrega: '', fecha: '', hora: '', observaciones: '' },
    updated: Date.now(),
  };
}

/* ¿El borrador tiene datos cargados por el usuario? (cliente o ítems con contenido) */
function tieneContenido(b) {
  if (!b) return false;
  const c = b.cliente || {};
  const cli = c.razonSocial || c.senores || c.cuit || c.domicilio || c.localidad || c.mail || c.nombre;
  const it = (b.items || []).some(i => i.articulo || i.descripcion || i.cantidad);
  const rec = b.recibi && (b.recibi.nombre || b.recibi.documentoNro);
  const tr = b.transporte && (b.transporte.nombre || b.transporte.observaciones);
  return !!(cli || it || rec || tr);
}
/* ¿Hay TRABAJO SIN GUARDAR que valga la pena retomar? Es la clave de la coherencia:
   se retoma sólo si el borrador tiene contenido Y difiere de su versión guardada
   (o nunca se guardó). Si está vacío o es idéntico a lo ya guardado, NO se retoma
   (documento en blanco). Así un reload accidental no pierde trabajo, pero después
   de guardar/terminar la app arranca limpia. */
function hayTrabajoPendiente(b) {
  if (!tieneContenido(b)) return false;
  const guardado = datos.remitos.find(d => d.id === b.id);
  if (guardado && JSON.stringify(guardado) === JSON.stringify(b)) return false; // igual a lo guardado
  return true;
}

/* El borrador se retoma después del ingreso (appLista), cuando ya se conocen los
   remitos guardados y el usuario. */
let doc = emptyRemito();
/* Copia profunda de los valores de fábrica: retocar un campo nunca debe modificar DEFAULT_CALIB. */
let calib = Object.assign(clone(DEFAULT_CALIB), load(LS.calib, {}));
let zoom = 1;

/* Migración: el ajuste fino v1 quedó horneado en las coordenadas base (config).
   En v2 pasamos a traer de fábrica el ajuste del pre-impreso medido sobre impresiones
   reales, así un navegador nuevo imprime bien sin calibrar nada. */
if (calib.adjustBaked !== 'v2') {
  calib.adjust = clone(DEFAULT_CALIB.adjust);
  calib.adjustBaked = 'v2';
  save(LS.calib, calib);
}

/* Las correcciones del documento completo pasaron a estar horneadas en las coordenadas
   del modo PDF (FIELDS_FULL). El ajuste fino de ese modo vuelve a cero: el PDF ya sale
   bien de fábrica y los controles quedan libres para retoques puntuales. */
if (calib.adjustFullBaked !== 'v2') {
  calib.adjustFull = {};
  calib.adjustFullBaked = 'v2';
  save(LS.calib, calib);
}

/* Limpieza de claves de versiones anteriores que ya no se usan. La configuración de
   ARCA (incluido cualquier certificado que hubiera quedado) se descarta del navegador:
   ahora vive en el servidor. Los documentos y clientes se traspasan al ingresar. */
(function limpiarAlmacenamientoAnterior() {
  ['remitos_seed', 'remitos_compfiscal', 'remitos_api', 'remitos_outbox', 'remitos_panel_key'].forEach(k =>
    localStorage.removeItem(k)
  );
  const docs = load('remitos_docs', null);
  if (Array.isArray(docs) && docs.some(d => d && (d.id === 'ejemplo' || d.ejemplo))) {
    save(
      'remitos_docs',
      docs.filter(d => d && d.id !== 'ejemplo' && !d.ejemplo)
    );
  }
})();

/* --- Fechas -----------------------------------------------------------------
   Los <input type="date"> manejan ISO (aaaa-mm-dd) y el comprobante se imprime
   en dd/mm/aaaa, que es el formato de la RG 1415. Se convierte en el borde. */
const isoADmy = v =>
  /^\d{4}-\d{2}-\d{2}$/.test(v || '') ? `${v.slice(8, 10)}/${v.slice(5, 7)}/${v.slice(0, 4)}` : v || '';
const dmyAIso = v =>
  /^\d{1,2}\/\d{1,2}\/\d{4}$/.test(v || '')
    ? (([d, m, a]) => `${a}-${m.padStart(2, '0')}-${d.padStart(2, '0')}`)(v.split('/'))
    : '';
const esFecha = el => el.type === 'date';

/* ============================== BINDING FORM ============================== */
function bindForm() {
  $$('[data-path]').forEach(el => {
    el.addEventListener('input', () => {
      setPath(doc, el.dataset.path, esFecha(el) ? isoADmy(el.value) : el.value);
      touch();
      scheduleRender();
    });
  });
  $$('input[name=tipoDoc]').forEach(r =>
    r.addEventListener('change', () => {
      doc.recibi.tipoDoc = r.value;
      touch();
      scheduleRender();
    })
  );
  /* Si el usuario edita la fecha a mano, dejamos de auto-actualizarla */
  const f = $('[data-path="fecha"]');
  if (f)
    f.addEventListener('input', () => {
      fechaManual = true;
    });
}

/* El CUIT se guarda siempre como 99-99999999-9: se arman los guiones mientras
   se tipea, así entra igual desde el teclado que desde la agenda o el CSV. */
function wireFormatos() {
  const fmtCuit = v => {
    const d = (v || '').replace(/\D/g, '').slice(0, 11);
    if (d.length <= 2) return d;
    if (d.length <= 10) return d.slice(0, 2) + '-' + d.slice(2);
    return d.slice(0, 2) + '-' + d.slice(2, 10) + '-' + d.slice(10);
  };
  $$('[data-path="cliente.cuit"], #cliCuit, #apiCuit').forEach(el => {
    el.addEventListener('input', () => {
      const alFinal = el.selectionStart === el.value.length;
      const v = fmtCuit(el.value);
      if (v === el.value) return;
      el.value = v;
      if (alFinal) el.setSelectionRange(v.length, v.length);
      if (el.dataset.path) setPath(doc, el.dataset.path, v), touch(), scheduleRender();
    });
  });
  /* El C.P. argentino es 4 dígitos o CPA (B1636FDA): se normaliza en mayúsculas */
  $$('[data-path="cliente.cp"], #cliCp').forEach(el => {
    el.addEventListener('blur', () => {
      const v = el.value.trim().toUpperCase();
      if (v === el.value) return;
      el.value = v;
      if (el.dataset.path) setPath(doc, el.dataset.path, v), touch(), scheduleRender();
    });
  });
}

function formFromDoc() {
  $$('[data-path]').forEach(el => {
    const v = getPath(doc, el.dataset.path) ?? '';
    el.value = esFecha(el) ? dmyAIso(v) : v;
  });
  const radio = $(`input[name=tipoDoc][value="${doc.recibi.tipoDoc || ''}"]`);
  if (radio) radio.checked = true;
  renderItemsEditor();
}

/* ------------------------------ Editor ítems ----------------------------- */
function renderItemsEditor() {
  const list = $('#itemsList');
  list.innerHTML = '';
  doc.items.forEach((it, i) => {
    const card = document.createElement('div');
    card.className = 'item-card';
    card.innerHTML = `
      <div class="item-top">
        <span class="item-badge">Ítem ${i + 1}</span>
        <button class="item-del" data-del="${i}" title="Quitar ítem" aria-label="Quitar ítem">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
      </div>
      <div class="grid">
        <div class="field"><label>Artículo</label><input data-i="${i}" data-k="articulo" placeholder="1" value="${escA(it.articulo)}"></div>
        <div class="field"><label>Cantidad</label><input data-i="${i}" data-k="cantidad" placeholder="3 hs x 1 Téc." value="${escA(it.cantidad)}"></div>
        <div class="field full"><label>Descripción</label><textarea data-i="${i}" data-k="descripcion" rows="2" placeholder="Detalle del trabajo o producto">${esc(it.descripcion)}</textarea></div>
      </div>`;
    list.appendChild(card);
  });
  $$('#itemsList input, #itemsList textarea').forEach(t =>
    t.addEventListener('input', () => {
      doc.items[+t.dataset.i][t.dataset.k] = t.value;
      touch();
      scheduleRender();
    })
  );
  $$('#itemsList [data-del]').forEach(b =>
    b.addEventListener('click', () => {
      doc.items.splice(+b.dataset.del, 1);
      if (!doc.items.length) doc.items.push({ articulo: '', descripcion: '', cantidad: '' });
      touch();
      renderItemsEditor();
      renderPreview();
    })
  );
}
/* Iniciales de un nombre o razón social, para el avatar («Distribuidora Sur S.A.» → «DS»). */
const iniciales = s =>
  String(s || '')
    .trim()
    .replace(/\s+(S\.?A\.?S?\.?|S\.?R\.?L\.?)$/i, '')
    .split(/\s+/)
    .filter(w => /^[A-Za-zÁÉÍÓÚÑáéíóúñ0-9]/.test(w))
    .slice(0, 2)
    .map(w => w[0].toUpperCase())
    .join('') || '·';
const esc = s =>
  String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
const escA = s => esc(s).replace(/"/g, '&quot;');

/* ============================ RENDER PREVIEW ============================= */
const page = $('#page'),
  dataLayer = $('#dataLayer');

/* Campos que en modo documento completo dibuja la plantilla pegados a su rótulo.
   El resto (ítems, tipo de documento, Nº) sigue saliendo por la capa de datos. */
const FULL_INLINE = new Set([
  'fecha',
  'cliente.senores',
  'cliente.razonSocial',
  'cliente.cuit',
  'cliente.domicilio',
  'cliente.localidad',
  'cliente.otrosDatos',
  'cliente.telefono',
  'cliente.cp',
  'cliente.mail',
  'cliente.nombre',
  'recibi.nombre',
  'recibi.documentoNro',
  'recibi.sector',
  'recibi.puesto',
  'transporte.nombre',
  'transporte.vehiculo',
  'transporte.chofer',
  'transporte.lugarEntrega',
  'transporte.fecha',
  'transporte.hora',
]);

/* ---- Dos juegos de calibración independientes -------------------------------
   overlay : imprimir sobre el remito PRE-IMPRESO -> calib.offsetX/Y, scaleX/Y, adjust
   full    : documento completo / PDF             -> calib.fullOffsetX/Y, fullScaleX/Y, adjustFull
   Mover algo en el PDF NO toca la calibración del pre-impreso, y viceversa. */
const isFull = () => calib.docMode === 'full';
function curAdjSet() {
  if (isFull()) {
    calib.adjustFull = calib.adjustFull || {};
    return calib.adjustFull;
  }
  calib.adjust = calib.adjust || {};
  return calib.adjust;
}
function curCal() {
  return isFull()
    ? {
        ox: calib.fullOffsetX || 0,
        oy: calib.fullOffsetY || 0,
        sx: calib.fullScaleX || 100,
        sy: calib.fullScaleY || 100,
        fs: calib.fullFontScale || 100,
      }
    : {
        ox: calib.offsetX || 0,
        oy: calib.offsetY || 0,
        sx: calib.scaleX || 100,
        sy: calib.scaleY || 100,
        fs: calib.fontScale || 100,
      };
}
function curPaper() {
  return isFull()
    ? PAPER_FULL[calib.fullPaper] || PAPER_FULL.RemitoReal
    : PAPER_SIZES[calib.paper] || PAPER_SIZES.Remito;
}
/* Coordenadas según el modo: el PDF usa las suyas, ya corregidas de fábrica */
const curFields = () => (isFull() ? FIELDS_FULL : FIELDS);
const curItems = () => (isFull() ? ITEMS_REGION_FULL : ITEMS_REGION);
const curDocTypes = () => (isFull() ? DOC_TYPES_FULL : DOC_TYPES);
const curObs = () => (isFull() ? OBSERVACIONES_FULL : OBSERVACIONES);
function setCurCal(key, v) {
  const map = isFull()
    ? { ox: 'fullOffsetX', oy: 'fullOffsetY', sx: 'fullScaleX', sy: 'fullScaleY', fs: 'fullFontScale' }
    : { ox: 'offsetX', oy: 'offsetY', sx: 'scaleX', sy: 'scaleY', fs: 'fontScale' };
  calib[map[key]] = v;
}

function fontMM(size) {
  return ((size * curCal().fs) / 100).toFixed(3) + 'mm';
}

/* Ajuste fino individual por campo (mm), del juego activo según el modo. */
function adj(path) {
  const a = curAdjSet()[path] || {};
  return { dx: a.dx || 0, dy: a.dy || 0 };
}
/* % de la referencia de calibración -> mm absolutos (independiente del papel) */
function xMM(xpct, dx) {
  return ((xpct * REF.w) / 100 + (dx || 0)).toFixed(2) + 'mm';
}
function yMM(ypct, dy) {
  return ((ypct * REF.h) / 100 + (dy || 0)).toFixed(2) + 'mm';
}

function makeField(f, text) {
  const a = adj(f.path);
  const d = document.createElement('div');
  d.className = 'f';
  d.style.left = xMM(f.x, a.dx);
  d.style.top = yMM(f.y, a.dy);
  if (f.w) d.style.width = ((f.w * REF.w) / 100).toFixed(2) + 'mm';
  d.style.fontSize = fontMM(f.size);
  d.style.textAlign = f.align || 'left';
  d.textContent = text;
  return d;
}

/* Batching: coalesce múltiples cambios (tecleo rápido) en un render por frame */
let _rafPending = false;
function scheduleRender() {
  if (_rafPending) return;
  _rafPending = true;
  requestAnimationFrame(() => {
    _rafPending = false;
    renderPreview();
  });
}

function renderPreview() {
  const frag = document.createDocumentFragment();

  /* Campos escalares (coordenadas del modo activo).
     En documento completo los valores ya los dibuja la plantilla al lado de su
     rótulo, así que acá se saltean para no duplicarlos. */
  const enPlantilla = calib.docMode === 'full';
  for (const f of curFields()) {
    if (enPlantilla && FULL_INLINE.has(f.path)) continue;
    const val = getPath(doc, f.path);
    if (val) frag.appendChild(makeField(f, val));
  }

  /* Observaciones (multilínea) */
  const obs = enPlantilla ? '' : doc.transporte.observaciones;
  if (obs) {
    const o = curObs();
    const d = makeField({ path: o.path, x: o.x, y: o.y, w: o.w, size: o.size, align: 'left' }, obs);
    d.style.lineHeight = (o.lineH / o.size).toFixed(2);
    frag.appendChild(d);
  }

  /* Tipo de documento (X) */
  const dt = curDocTypes()[doc.recibi.tipoDoc];
  if (dt) {
    const a = adj('tipoDoc');
    const x = document.createElement('div');
    x.className = 'f x-mark';
    x.style.left = xMM(dt.x, a.dx);
    x.style.top = yMM(dt.y, a.dy);
    x.style.fontSize = fontMM(dt.size);
    x.textContent = '✕';
    frag.appendChild(x);
  }

  const box = buildItemsBox();
  frag.appendChild(box);
  dataLayer.replaceChildren(frag); // limpia + inserta en una sola operación
  if (box.scrollHeight > box.clientHeight + 2) markOverflow();
  /* La plantilla se redibuja acá SÓLO en documento completo, donde depende de los
     datos. En pre-impreso es el formulario en blanco (estático): se dibuja una vez
     al entrar al modo, no en cada tecla — así el tipeo no mueve 123 nodos del DOM. */
  if (isFull()) dibujarPlantilla();
}

/* Flujo de ítems: grid CSS de flujo natural (determinista en pantalla e impresión) */
function buildItemsBox() {
  const R = curItems(),
    C = R.cols,
    a = adj('items');
  const box = document.createElement('div');
  box.className = 'items-box';
  box.style.left = a.dx.toFixed(2) + 'mm';
  box.style.top = yMM(R.yTop, a.dy);
  box.style.height = (((R.yBottom - R.yTop) * REF.h) / 100).toFixed(2) + 'mm';
  box.style.fontSize = fontMM(R.size);
  box.style.lineHeight = (R.lineH / R.size).toFixed(2);

  /* columnas en % de la página: [pad] artículo [gap] descripción [gap] cantidad */
  const gap1 = C.descripcion.x - (C.articulo.x + C.articulo.w);
  const gap2 = C.cantidad.x - (C.descripcion.x + C.descripcion.w);
  const template = `${C.articulo.x}% ${C.articulo.w}% ${gap1}% ${C.descripcion.w}% ${gap2}% ${C.cantidad.w}%`;

  for (const it of doc.items) {
    if (!it.articulo && !it.descripcion && !it.cantidad) continue;
    const row = document.createElement('div');
    row.className = 'it-row';
    row.style.gridTemplateColumns = template;
    row.style.marginBottom = R.rowGap + 'mm';
    const cell = (txt, gridCol, align) => {
      const c = document.createElement('div');
      c.className = 'it-cell';
      c.style.gridColumn = gridCol;
      c.style.textAlign = align;
      c.textContent = txt || '';
      return c;
    };
    row.appendChild(cell(it.articulo, 2, C.articulo.align));
    row.appendChild(cell(it.descripcion, 4, C.descripcion.align));
    const cant = cell(it.cantidad, 6, C.cantidad.align);
    /* En el talonario pre-impreso la casilla de Cantidad cae 2 mm a la izquierda de
       donde la deja la grilla, y no hay control para moverla sola (el ajuste de
       "Ítems" mueve la tabla entera). El PDF lo dibujamos nosotros y ahí ya calza. */
    if (!isFull()) cant.style.transform = 'translateX(-2mm)';
    row.appendChild(cant);
    box.appendChild(row);
  }
  return box;
}
let overflowShown = false;
function markOverflow() {
  if (overflowShown) return;
  overflowShown = true;
  toast('⚠ Hay más ítems de los que entran en la hoja', 2600);
  setTimeout(() => (overflowShown = false), 3000);
}

/* ============================== CALIBRACIÓN ============================== */
function applyCalib() {
  const paper = curPaper(),
    c = curCal();
  page.style.setProperty('--paper-w', paper.w);
  page.style.setProperty('--paper-h', paper.h);
  page.style.setProperty('--off-x', c.ox);
  page.style.setProperty('--off-y', c.oy);
  page.style.setProperty('--scale-x', c.sx);
  page.style.setProperty('--scale-y', c.sy);
  page.style.setProperty('--guide-opacity', calib.guideOpacity);
  updatePageStyle(paper);
  resize();
}
function updatePageStyle(paper) {
  let st = $('#pageStyle');
  if (!st) {
    st = document.createElement('style');
    st.id = 'pageStyle';
    document.head.appendChild(st);
  }
  st.textContent = `@page{ size:${paper.w}mm ${paper.h}mm; margin:0 }`;
}

/* Punto de venta de los remitos (ajuste compartido) y ejemplares a imprimir
   (preferencia de cada equipo, junto con la calibración de su impresora). */
const fiscal = {
  get ptoVenta() {
    return datos.ajustes.remito.ptoVenta || REMITO_FISCAL.ptoVenta;
  },
  get copias() {
    return calib.copias === 1 ? 1 : 2;
  },
};
/* Correlativo del número de remito, tanto del borrador («00000012») como del
   autorizado («00001-00000012»): se toma siempre la parte posterior al guion. */
function correlativo(numero) {
  const s = String(numero || '');
  return parseInt(s.includes('-') ? s.split('-').pop() : s, 10) || 0;
}
/* Número que tendrá el próximo remito, para la vista previa: el definitivo lo
   asigna el servidor al guardar, así dos usuarios nunca obtienen el mismo. */
function nextRemitoNumero() {
  const n = datos.remitos.reduce((m, x) => Math.max(m, correlativo(x.numero)), 0) + 1;
  return String(n).padStart(8, '0');
}
function syncFiscalUI() {
  $('#remPv').value = fiscal.ptoVenta;
  $('#remCopias').value = String(fiscal.copias);
  const bloqueado = doc.estado === 'autorizado';
  ['#remNumero', '#remCai', '#remCaiVto'].forEach(id => {
    $(id).readOnly = bloqueado;
    $(id).title = bloqueado ? 'Asignado por la autorización: no se modifica' : '';
  });
}
function wireRemitoFiscal() {
  $('#remCopias').addEventListener('change', e => {
    calib.copias = parseInt(e.target.value, 10) === 1 ? 1 : 2;
    save(LS.calib, calib);
  });
}

/* Arma los ejemplares ORIGINAL / DUPLICADO para imprimir (RG 1415 art. 14: mínimo 2,
   original al destinatario, duplicado en poder del emisor). */
function buildRemitoCopies() {
  const wrap = $('#printCopies'),
    tpl = $('#template');
  if (!wrap || !tpl) return;
  wrap.innerHTML = '';
  const labels = fiscal.copias === 1 ? ['ORIGINAL'] : ['ORIGINAL', 'DUPLICADO'];
  labels.forEach(label => {
    tpl.dataset.copia = label;
    buildRemitoTemplate();
    const clone = page.cloneNode(true);
    clone.removeAttribute('id');
    clone.classList.add('copy-page');
    /* Mismo color de papel que el talonario: original blanco, duplicado beige */
    if (label === 'DUPLICADO') clone.classList.add('copy-beige');
    wrap.appendChild(clone);
  });
  tpl.dataset.copia = 'ORIGINAL';
  buildRemitoTemplate();
}

/* =================== Plantilla vectorial del remito ===================
   Dibuja el remito con el mismo diseño que el talonario, sobre el sistema de
   coordenadas en mm (REF 212 × 300). En «documento completo» se imprime entera;
   en «sobre pre-impreso» se muestra en blanco y tenue, solo como guía en pantalla. */
function dibujarPlantilla() {
  buildRemitoTemplate({ blanco: !isFull() });
}

function buildRemitoTemplate(opts = {}) {
  const blanco = !!opts.blanco; // formulario vacío: sólo rótulos, para usar de fondo en overlay
  const t = $('#template');
  if (!t) return;
  const X = p => ((p * REF.w) / 100).toFixed(2) + 'mm';
  const Y = p => ((p * REF.h) / 100).toFixed(2) + 'mm';
  const lab = (x, y, txt, o = {}) => {
    const { size = 2.3, w, align = 'left', bold = false, color = '#1c1830', up = false, it = false } = o;
    return (
      `<div style="position:absolute;left:${X(x)};top:${Y(y)};${w != null ? `width:${X(w)};` : ''}text-align:${align};` +
      `font-size:${size}mm;font-weight:${bold ? 700 : 600};color:${color};line-height:1.05;` +
      `${it ? 'font-style:italic;' : ''}${up ? 'text-transform:uppercase;letter-spacing:.03em;' : ''}white-space:nowrap">${txt}</div>`
    );
  };
  const hl = (x, y, w, o = {}) => {
    const { c = '#2a2540', th = 0.25 } = o;
    return `<div style="position:absolute;left:${X(x)};top:${Y(y)};width:${X(w)};height:${th}mm;background:${c}"></div>`;
  };
  const vl = (x, y, h, o = {}) => {
    const { c = '#2a2540', th = 0.25 } = o;
    return `<div style="position:absolute;left:${X(x)};top:${Y(y)};height:${Y(h)};width:${th}mm;background:${c}"></div>`;
  };
  const box = (x, y, w, h, o = {}) => {
    const { c = '#2a2540', th = 0.28, r = 1.4, bg = 'transparent' } = o;
    return `<div style="position:absolute;left:${X(x)};top:${Y(y)};width:${X(w)};height:${Y(h)};border:${th}mm solid ${c};border-radius:${r}mm;background:${bg};box-sizing:border-box"></div>`;
  };
  const chk = (x, y) =>
    `<div style="position:absolute;left:${X(x)};top:${Y(y)};width:2.5mm;height:2.5mm;border:.25mm solid #333;border-radius:.3mm"></div>`;
  /* Rótulo + valor en la misma línea: el dato se acomoda SOLO al lado de su rótulo.
     Es lo que permite que el PDF salga siempre bien sin depender de coordenadas. */
  const campo = (x, y, rot, valor, o = {}) => {
    const { size = 3.2, bold = true, w = 50, gap = 1.5, vsize } = o;
    return (
      `<div style="position:absolute;left:${X(x)};top:${Y(y)};width:${X(w)};display:flex;` +
      `align-items:baseline;gap:${gap}mm;line-height:1.05;white-space:nowrap;overflow:hidden">` +
      `<span style="font-size:${size}mm;font-weight:${bold ? 700 : 600};color:${lbl};flex:none">${rot}</span>` +
      `<span style="font-size:${vsize || size}mm;font-weight:500;color:#000;min-width:0;overflow:hidden;text-overflow:ellipsis">${esc(valor || '')}</span></div>`
    );
  };
  /* valor centrado dentro de un recuadro (Fecha / Hora del transportista) */
  const enCaja = (x, y, w, valor, size = 3) =>
    `<div style="position:absolute;left:${X(x)};top:${Y(y)};width:${X(w)};text-align:center;` +
    `font-size:${size}mm;font-weight:500;color:#000;line-height:1.05;white-space:nowrap">${esc(valor || '')}</div>`;
  const D = blanco ? emptyRemito() : doc; // en blanco: cliente/ítems vacíos
  const F = blanco ? { cai: '', caiVto: '' } : doc;
  const lbl = '#1a1a1a',
    val = '#000';
  const C = COMPANY,
    dt = DOC_TYPES;
  const numero = blanco ? '' : doc.numero || nextRemitoNumero();
  /* Un remito autorizado ya trae el número completo "00001-00000002"; el borrador
     sólo trae el correlativo, así que se le antepone el punto de venta. */
  const nroStr =
    numero === ''
      ? ''
      : /-/.test(String(numero))
        ? String(numero).replace('-', ' - ')
        : (fiscal.ptoVenta || '00001') + ' - ' + numero;
  const copia = t.dataset.copia || 'ORIGINAL';
  let h = '';
  /* Geometría medida sobre el remito original escaneado.
     Mapeo verificado: la línea del encabezado de ítems cae en ITEMS_REGION.yTop
     y el fondo de la tabla en ITEMS_REGION.yBottom. No hay marco exterior:
     el original son cajas independientes. */
  const L = 4.53,
    R2 = 95.64,
    Wb = R2 - L; // izquierda / derecha de todas las cajas

  /* ============ ENCABEZADO (dos cajas separadas) ============ */
  h += box(L, 1.89, 54.67, 13.71, { c: '#000', th: 0.3, r: 1.6 }); // datos del emisor (cierra 15.60%)
  h += box(60.2, 1.89, 35.44, 13.71, { c: '#000', th: 0.3, r: 1.6 }); // R / REMITO
  h += `<img src="${C.logo}" alt="" style="position:absolute;left:${X(6.35)};top:${Y(4.6)};width:${X(10.6)};height:auto">`;
  /* Cuerpos medidos sobre el escaneo: razón social ~4,7 mm · rótulos y valores ~2,95 mm */
  h += lab(18.2, 5.5, C.nombre, { size: 4.7, bold: true, color: val });
  const rows = [
    ['CUIT:', C.cuit],
    ['Teléfono', C.telefono],
    ['Cód. Postal:', C.cp],
    ['Dirección:', C.direccion],
    ['Localidad:', C.localidad],
    ['Mail:', C.mail],
  ];
  let ry = 7.03; /* pegado a la razón social, como el escaneo */
  rows.forEach(([l, v]) => {
    h += lab(18.2, ry, l, { size: 2.95, bold: true, color: lbl });
    h += lab(28.5, ry, v, { size: 2.95, bold: true, color: val });
    ry += 1.44;
  });
  /* caja "R" (pisa la separación) + caja "Código Nº" */
  /* RG 1415 Anexo II Ap.B: la letra va destacada en el centro del espacio superior
     y DEBAJO de la letra el código precedido de "Código Nº". */
  /* Fondo = color del papel del ejemplar (blanco en el original, beige en el duplicado) */
  h += box(55.1, 3.6, 6.4, 5.6, { c: '#000', th: 0.4, r: 0.4, bg: 'var(--paper-bg,#fff)' });
  h += lab(55.1, 4.0, 'R', { size: 5.6, bold: true, align: 'center', w: 6.4, color: '#000' });
  h += box(52.65, 9.25, 11.3, 2.2, { c: '#000', th: 0.22, r: 0.3, bg: 'var(--paper-bg,#fff)' });
  h += lab(52.65, 9.65, 'Código Nº ' + C.codigo, { size: 2, align: 'center', w: 11.3, color: '#000' });
  /* bloque derecho */
  h += lab(64.66, 3.4, 'DOCUMENTO NO VÁLIDO COMO FACTURA', { size: 2.25, color: '#000' });
  h += lab(64.66, 5.1, 'REMITO', { size: 5.5, color: '#000' });
  h += lab(64.66, 9.1, 'Nro:', { size: 2.95, bold: true, color: '#000' });
  h += lab(69.9, 9.1, esc(nroStr), { size: 2.95, color: '#000' });
  h += campo(64.66, 11.1, 'Fecha:', D.fecha, { size: 2.95, w: 30, gap: 2 });
  h += lab(64.66, 13.05, 'Ing. Brutos C.M. Nº ' + C.ingBrutos, { size: 2, bold: true, color: '#000' });
  h += lab(64.66, 14.2, 'Fecha inicio Act.: ' + C.inicioAct, { size: 2, bold: true, color: '#000' });

  /* ============ CLIENTE ============ */
  h += box(L, 16.6, Wb, 16.25, { c: '#000', th: 0.3, r: 1.6 }); /* medido del escaneo */
  h +=
    `<div style="position:absolute;left:${X(5.78)};top:${Y(17.65)};width:${X(52)};display:flex;align-items:baseline;gap:1.5mm;line-height:1.05;white-space:nowrap;overflow:hidden">` +
    `<span style="font-size:3.2mm;font-weight:700;font-style:italic;color:${lbl};flex:none">Señor/es</span>` +
    `<span style="font-size:3.2mm;font-weight:500;color:#000;min-width:0;overflow:hidden;text-overflow:ellipsis">${esc(D.cliente.senores || '')}</span></div>`;
  h += campo(5.78, 20.37, 'Razón Social', D.cliente.razonSocial, { w: 52 });
  h += campo(5.78, 22.96, 'CUIT:', D.cliente.cuit, { w: 52 });
  h += campo(5.78, 25.52, 'Domicilio:', D.cliente.domicilio, { w: 52 });
  h += campo(5.78, 28.14, 'Localidad:', D.cliente.localidad, { w: 52 });
  h += campo(5.78, 30.86, 'Otros Datos:', D.cliente.otrosDatos, { w: 52 });
  h += campo(65.5, 20.15, 'Teléfono:', D.cliente.telefono, { w: 29 });
  h += campo(65.5, 22.77, 'C.P.:', D.cliente.cp, { w: 29 });
  h += campo(65.5, 25.36, 'Mail:', D.cliente.mail, { w: 29 });
  h += campo(65.5, 28.05, 'Nombre:', D.cliente.nombre, { w: 29 });

  /* ============ TABLA DE ÍTEMS ============ */
  const iy = ITEMS_REGION.yTop,
    iy2 = ITEMS_REGION.yBottom,
    hTop = 34.92;
  h += box(L, hTop, Wb, iy2 - hTop, { c: '#000', th: 0.3, r: 0.4 });
  h += `<div style="position:absolute;left:${X(L)};top:${Y(hTop)};width:${X(Wb)};height:${Y(iy - hTop)};background:#d8d8d8"></div>`;
  h += hl(L, iy, Wb, { c: '#000', th: 0.3 });
  h += vl(16.8, hTop, iy2 - hTop, { c: '#000', th: 0.3 });
  h += vl(84.3, hTop, iy2 - hTop, { c: '#000', th: 0.3 });
  h += lab(L, hTop + 0.42, 'ARTICULO', { size: 2.95, bold: true, align: 'center', w: 16.8 - L, color: '#000' });
  h += lab(16.8, hTop + 0.42, 'DESCRIPCION', {
    size: 2.95,
    bold: true,
    align: 'center',
    w: 84.3 - 16.8,
    color: '#000',
  });
  h += lab(84.3, hTop + 0.42, 'CANTIDAD', { size: 2.95, bold: true, align: 'center', w: R2 - 84.3, color: '#000' });
  /* Leyenda vertical EXACTA del remito original (va tal cual, como en el talonario).
     Además, RG 1415 art.14 + Anexo V I)b) exigen la palabra ORIGINAL / DUPLICADO:
     se imprime al pie de cada ejemplar. */
  /* Medido en el escaneo: ocupa y 41.1%..59.2% (centro 50.2%), cuerpo ~2,8 mm.
     Con rotate(-90) y origen arriba-izquierda, el texto crece HACIA ARRIBA desde el ancla. */
  h += `<div style="position:absolute;left:${X(2.4)};top:${Y(59.2)};transform:rotate(-90deg);transform-origin:left top;font-size:2.8mm;font-weight:700;color:#000;white-space:nowrap">ORIGINAL BLANCO - DUPLICADO BEIGE</div>`;

  /* ============ RECIBÍ CONFORME ============ */
  h += box(L, 67.66, Wb, 11.53, { c: '#000', th: 0.3, r: 1.6 });
  h += lab(8.05, 68.25, 'RECIBI CONFORME', { size: 3.15, bold: true, color: '#000' });
  h += chk(dt.DNI.x, 68.4);
  h += lab(dt.DNI.x + 3.1, 68.6, 'DNI', { size: 2.4, bold: true, color: '#222' });
  h += chk(dt.CI.x, 68.4);
  h += lab(dt.CI.x + 3.1, 68.6, 'CI', { size: 2.4, bold: true, color: '#222' });
  h += chk(dt.Pasaporte.x, 68.4);
  h += lab(dt.Pasaporte.x + 3.1, 68.6, 'Pasaporte', { size: 2.4, bold: true, color: '#222' });
  h += chk(dt.Otro.x, 68.4);
  h += lab(dt.Otro.x + 3.1, 68.6, 'Otro:', { size: 2.4, bold: true, color: '#222' });
  h += campo(8.05, 70.35, 'Nombre y Apellido:', D.recibi.nombre, { w: 40 });
  h += campo(8.05, 72.5, 'Documento Nro.:', D.recibi.documentoNro, { w: 40 });
  h += campo(8.05, 74.55, 'Sector:', D.recibi.sector, { w: 40 });
  h += campo(8.05, 76.6, 'Puesto:', D.recibi.puesto, { w: 40 });
  h += `<div style="position:absolute;left:${X(47)};top:${Y(75.6)};width:${X(35)};border-top:.35mm dashed #000"></div>`;
  h += lab(47, 76.2, 'FIRMA Y SELLO', { size: 2.5, bold: true, align: 'center', w: 35, color: '#000' });

  /* ============ DATOS DEL TRANSPORTISTA ============ */
  /* Medido sobre el escaneo: rótulos en x=8.05%, encabezado 2.6mm en negrita,
     rótulos 2.4mm en peso NORMAL (en el original no son negrita). */
  h += box(L, 80.16, Wb, 13.44, { c: '#000', th: 0.3, r: 1.6 });
  h += lab(8.05, 80.55, 'DATOS DEL TRANSPORTISTA', { size: 3.15, bold: true, color: '#000' });
  h += campo(8.05, 81.9, 'Nombre:', D.transporte.nombre, { size: 2.7, bold: false, w: 38 });
  h += campo(8.05, 83.35, 'Datos del Vehículo:', D.transporte.vehiculo, { size: 2.7, bold: false, w: 38 });
  h += campo(8.05, 84.8, 'Chofer:', D.transporte.chofer, { size: 2.7, bold: false, w: 38 });
  h += campo(8.05, 86.25, 'Lugar de Entrega:', D.transporte.lugarEntrega, { size: 2.7, bold: false, w: 38 });
  h += lab(48, 84.35, 'Fecha:', { size: 2.5, bold: true, color: '#000' });
  h += box(53.5, 83.8, 17, 2.7, { c: '#000', th: 0.28, r: 0 });
  h += enCaja(53.5, 84.35, 17, D.transporte.fecha);
  h += lab(72.5, 84.35, 'Hora:', { size: 2.5, bold: true, color: '#000' });
  h += box(77.5, 83.8, 14, 2.7, { c: '#000', th: 0.28, r: 0 });
  h += enCaja(77.5, 84.35, 14, D.transporte.hora);
  h += lab(8.05, 87.7, 'Observaciones:', { size: 2.7, color: '#000' });
  h += box(6.6, 89.1, 86.5, 4.1, { c: '#000', th: 0.28, r: 0 });
  h +=
    `<div style="position:absolute;left:${X(7.6)};top:${Y(89.7)};width:${X(84)};font-size:2.9mm;` +
    `font-weight:500;color:#000;line-height:1.25;white-space:pre-wrap;overflow:hidden">${esc(D.transporte.observaciones || '')}</div>`;

  /* ============ PIE ============
     RG 1415 Anexo V V.8 (autoimpresores): NO se consignan los datos de la imprenta
     ni el rango "Imp. del … al …". Anexo II Ap.B h): el CAI y su vencimiento van en
     el espacio INFERIOR DERECHO, con los textos "C.A.I. Nº …" y "Fecha Vto.: …".
     El vencimiento, en cuerpo no inferior a 12 (≈4,2 mm). */
  h += box(L, 94.3, Wb, 4.3, { c: '#000', th: 0.3, r: 0.4 });
  h += `<img src="${C.logo}" alt="" style="position:absolute;left:${X(5.6)};top:${Y(95.6)};width:${X(6.6)};height:auto">`;
  /* Mismo bloque que la imprenta en el original, con los datos del emisor como autoimpresor */
  h += lab(13.2, 94.9, 'Impreso por ' + C.nombre + ' - C.U.I.T. ' + C.cuit, { size: 1.65, bold: true, color: '#000' });
  h += lab(13.2, 96.1, 'Autoimpresor (RG 100) - Ing. Brutos C.M. Nº ' + C.ingBrutos, {
    size: 1.65,
    bold: true,
    color: '#000',
  });
  h += lab(13.2, 97.3, C.mail + ' - ' + C.telefono + '   ·   ' + copia, { size: 1.65, bold: true, color: '#000' });
  /* Textos EXACTOS del remito original: "C.A.I." y "VTO." (sin "Nº" ni "Fecha") */
  h += lab(52, 94.9, 'C.A.I. ' + esc(F.cai || '—'), { size: 2.4, align: 'right', w: 42.5, color: '#000' });
  h += lab(52, 96.4, 'VTO. ' + esc(F.caiVto || '—'), { size: 3.9, align: 'right', w: 42.5, color: '#000' });

  /* Sello AUTORIZADO: translúcido para no tapar datos, sobre el área de ítems. */
  if (!blanco && doc.estado === 'autorizado') {
    h +=
      `<div style="position:absolute;left:${X(58)};top:${Y(52)};transform:rotate(-11deg);` +
      `border:.7mm solid #2e8b45;border-radius:1.6mm;color:#2e8b45;opacity:.72;` +
      `padding:1mm 3mm;font-size:5.2mm;font-weight:800;letter-spacing:.04em;` +
      `-webkit-print-color-adjust:exact;print-color-adjust:exact">AUTORIZADO</div>`;
  }

  t.innerHTML = h;
}

function setDocMode(mode) {
  calib.docMode = mode === 'full' ? 'full' : 'overlay';
  const full = calib.docMode === 'full';
  page.classList.toggle('mode-full', full);
  const pv = $('#previewPane');
  if (pv) pv.classList.toggle('m-full', full);
  $$('#modeSwitch button').forEach(b => b.classList.toggle('active', b.dataset.mode === calib.docMode));
  save(LS.calib, calib);
  /* cada modo tiene su propia hoja, offsets y ajustes → re-aplicar y re-dibujar */
  applyCalib();
  syncCalibUI();
  renderPreview();
  dibujarPlantilla();
  if ($('#adjList') && $('#adjList').children.length) renderAdjustPanel();
  requestAnimationFrame(fitZoom);
}
function wireDocMode() {
  $$('#modeSwitch button').forEach(b => b.addEventListener('click', () => setDocMode(b.dataset.mode)));
  const pdf = $('#btnPdf');
  if (pdf) pdf.addEventListener('click', descargarPDF);
}
function descargarPDF() {
  toast('En el destino de impresión elegí “Guardar como PDF”', 2800);
  setTimeout(() => {
    if (document.body.getAttribute('data-view') !== 'remito') return; // cambiaste de vista
    imprimir();
  }, 400);
}

/* Rellena el selector de papel con las opciones del modo activo */
function fillPaperSelect() {
  const sel = $('#calPaper');
  if (!sel) return;
  const opts = isFull() ? PAPER_FULL : PAPER_SIZES;
  sel.innerHTML = '';
  Object.entries(opts).forEach(([k, v]) => {
    const o = document.createElement('option');
    o.value = k;
    o.textContent = v.label;
    sel.appendChild(o);
  });
  sel.value = isFull() ? calib.fullPaper || 'RemitoReal' : calib.paper || 'Remito';
}
function syncCalibUI() {
  const c = curCal();
  fillPaperSelect();
  const badge = $('#calModeBadge');
  if (badge) {
    badge.textContent = isFull() ? 'Documento completo (PDF)' : 'Sobre pre-impreso';
    badge.className = 'cal-mode ' + (isFull() ? 'full' : 'ov');
  }
  $('#calOffX').value = c.ox;
  $('#calOffXv').textContent = c.ox + ' mm';
  $('#calOffY').value = c.oy;
  $('#calOffYv').textContent = c.oy + ' mm';
  $('#calScaleX').value = c.sx;
  $('#calScaleXv').textContent = c.sx + ' %';
  $('#calScaleY').value = c.sy;
  $('#calScaleYv').textContent = c.sy + ' %';
  $('#calFont').value = c.fs;
  $('#calFontv').textContent = c.fs + ' %';
  $('#calGuide').value = calib.guideOpacity;
  $('#calGuidev').textContent = calib.guideOpacity + ' %';
}
function wireCalib() {
  const paperSel = $('#calPaper');
  fillPaperSelect();
  const persist = () => {
    save(LS.calib, calib);
    applyCalib();
    renderPreview();
    dibujarPlantilla();
  };
  paperSel.addEventListener('change', e => {
    if (isFull()) calib.fullPaper = e.target.value;
    else calib.paper = e.target.value;
    persist();
  });
  /* Estos cinco escriben en el juego del MODO ACTIVO (overlay o documento completo) */
  const bindCur = (id, key, out, unit) =>
    $(id).addEventListener('input', e => {
      setCurCal(key, parseFloat(e.target.value));
      $(out).textContent = e.target.value + unit;
      persist();
    });
  bindCur('#calOffX', 'ox', '#calOffXv', ' mm');
  bindCur('#calOffY', 'oy', '#calOffYv', ' mm');
  bindCur('#calScaleX', 'sx', '#calScaleXv', ' %');
  bindCur('#calScaleY', 'sy', '#calScaleYv', ' %');
  bindCur('#calFont', 'fs', '#calFontv', ' %');
  /* La opacidad del formulario guía solo aplica sobre el pre-impreso */
  const bind = (id, key, out, unit) =>
    $(id).addEventListener('input', e => {
      calib[key] = parseFloat(e.target.value);
      $(out).textContent = e.target.value + unit;
      persist();
    });
  bind('#calGuide', 'guideOpacity', '#calGuidev', ' %');
  /* Restablece solo el juego del modo activo y conserva el resto de las preferencias. */
  $('#calReset').addEventListener('click', () => {
    const modo = isFull() ? 'del documento completo' : 'del pre-impreso';
    if (!confirm('¿Restablecer la calibración ' + modo + ' a los valores de fábrica?')) return;
    const fab = clone(DEFAULT_CALIB);
    const claves = isFull()
      ? ['fullPaper', 'fullOffsetX', 'fullOffsetY', 'fullScaleX', 'fullScaleY', 'fullFontScale', 'adjustFull']
      : ['paper', 'offsetX', 'offsetY', 'scaleX', 'scaleY', 'fontScale', 'guideOpacity', 'adjust'];
    claves.forEach(k => {
      calib[k] = fab[k];
    });
    save(LS.calib, calib);
    syncCalibUI();
    applyCalib();
    renderPreview();
    dibujarPlantilla();
    toast('Calibración restablecida', 1600, true);
  });
  /* Al imprimir, el CSS oculta la vista que no está activa: si estos botones se usan
     desde Facturas saldría una hoja en blanco. Por eso primero se vuelve al remito. */
  const desdeRemito = fn => () => {
    closePanels();
    if (document.body.getAttribute('data-view') !== 'remito') showView('remito');
    requestAnimationFrame(() => requestAnimationFrame(fn));
  };
  $('#calTest').addEventListener('click', desdeRemito(printTestSheet));
  $('#calPositions').addEventListener('click', desdeRemito(printPositions));
}

/* ================================= ZOOM ================================= */
function resize() {
  const paper = curPaper();
  const wrap = $('.page-wrap');
  page.style.transformOrigin = 'top left';
  wrap.style.width = paper.w * zoom + 'mm';
  wrap.style.height = paper.h * zoom + 'mm';
  page.style.setProperty('--zoom', zoom);
  $('#zoomVal').textContent = Math.round(zoom * 100) + '%';
}
function setZoom(z) {
  zoom = Math.min(2.5, Math.max(0.2, z));
  resize();
}
function fitZoom() {
  const paper = curPaper();
  const pane = $('#previewPane');
  if (!pane.clientWidth) return; // vista oculta: se ajusta al mostrarla
  const availW = pane.clientWidth - 56;
  const availH = pane.clientHeight - 120; // deja lugar a la barra de modo y al zoom
  const pw = paper.w / MM_PER_PX,
    ph = paper.h / MM_PER_PX;
  setZoom(Math.min(availW / pw, availH / ph));
}

/* ========================= AJUSTE FINO POR CAMPO ======================== */
let adjStep = 0.5;
const cssId = p => p.replace(/[^a-z0-9]/gi, '_');
const fmtAdj = v => {
  v = Math.round((v || 0) * 100) / 100;
  return !v ? '0' : (v > 0 ? '+' : '') + v;
};

function renderAdjustPanel() {
  const badge = $('#adjModeBadge');
  if (badge) {
    badge.textContent = isFull() ? 'Documento completo (PDF)' : 'Sobre pre-impreso';
    badge.className = 'cal-mode ' + (isFull() ? 'full' : 'ov');
  }
  const list = $('#adjList');
  list.innerHTML = '';
  ADJUSTABLE.forEach(item => {
    const row = document.createElement('div');
    row.className = 'adj-row';
    row.innerHTML = `
      <span class="adj-name">${esc(item.label)}</span>
      <span class="adj-ctrl">
        <button data-p="${item.path}" data-ax="dx" data-d="-1" title="Mover a la izquierda">◀</button>
        <output id="o-${cssId(item.path)}-dx"></output>
        <button data-p="${item.path}" data-ax="dx" data-d="1" title="Mover a la derecha">▶</button>
      </span>
      <span class="adj-ctrl">
        <button data-p="${item.path}" data-ax="dy" data-d="-1" title="Mover hacia arriba">▲</button>
        <output id="o-${cssId(item.path)}-dy"></output>
        <button data-p="${item.path}" data-ax="dy" data-d="1" title="Mover hacia abajo">▼</button>
      </span>`;
    list.appendChild(row);
  });
  $$('#adjList button').forEach(b =>
    b.addEventListener('click', () => nudge(b.dataset.p, b.dataset.ax, parseFloat(b.dataset.d) * adjStep))
  );
  updateAdjOutputs();
}
function nudge(path, ax, delta) {
  const set = curAdjSet(); // overlay o documento completo, según el modo
  const cur = set[path] || { dx: 0, dy: 0 };
  cur[ax] = Math.round(((cur[ax] || 0) + delta) * 100) / 100;
  if (!cur.dx && !cur.dy) delete set[path];
  else set[path] = cur;
  save(LS.calib, calib);
  renderPreview();
  dibujarPlantilla();
  updateAdjOutputs();
}
function updateAdjOutputs() {
  ADJUSTABLE.forEach(item => {
    const a = adj(item.path);
    ['dx', 'dy'].forEach(ax => {
      const o = document.getElementById(`o-${cssId(item.path)}-${ax}`);
      if (o) {
        o.textContent = fmtAdj(a[ax]);
        o.className = a[ax] ? 'on' : '';
      }
    });
  });
}
/* Cajón de navegación en móvil (drawer) */
function wireNavDrawer() {
  const app = document.querySelector('.app');
  const close = () => app.classList.remove('nav-open');
  $('#btnNavToggle').addEventListener('click', () => {
    if (window.matchMedia('(max-width:900px)').matches) {
      app.classList.toggle('nav-open');
    } // pantallas chicas: cajón
    else {
      app.classList.toggle('nav-collapsed');
      setTimeout(() => {
        fitZoom();
        fitCompZoom();
      }, 320);
    } // desktop: colapsa el nav
  });
  $('#navBackdrop').addEventListener('click', close);
  $$('.nav-item').forEach(n => n.addEventListener('click', close));
}

/* Barra lateral de datos plegable (remito + comprobantes) */
function wireSidebar() {
  const app = document.querySelector('.app');
  const apply = h => {
    app.classList.toggle('sidebar-hidden', h);
    setTimeout(() => {
      fitZoom();
      fitCompZoom();
    }, 360);
  };
  let hidden = load(LS.sidebar, false) === true;
  app.classList.toggle('sidebar-hidden', hidden);
  $('#btnToggleSidebar').addEventListener('click', () => {
    hidden = !hidden;
    save(LS.sidebar, hidden);
    apply(hidden);
  });
}

function wireAdjust() {
  $('#btnAjustar').addEventListener('click', () => {
    renderAdjustPanel();
    openPanel($('#adjust'), $('#backdrop3'));
  });
  $('#adjustClose').addEventListener('click', closePanels);
  $('#backdrop3').addEventListener('click', closePanels);
  $('#adjStep').addEventListener('change', e => {
    adjStep = parseFloat(e.target.value);
  });
  $('#adjResetAll').addEventListener('click', () => {
    if (!confirm('¿Restablecer TODOS los ajustes finos por campo?')) return;
    if (isFull()) calib.adjustFull = {};
    else calib.adjust = {};
    save(LS.calib, calib);
    renderPreview();
    dibujarPlantilla();
    renderAdjustPanel();
  });
}

/* ========================= DASHBOARD / VISTAS ========================== */
const ICN = {
  receipt:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 3h16v18l-3-2-2 2-3-2-3 2-2-2-3 2z"/><path d="M8 8h8M8 12h5"/></svg>',
  doc: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/></svg>',
  users:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>',
  clock:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
  edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
  print:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8" rx="1"/></svg>',
  trash:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M10 11v6M14 11v6"/></svg>',
  pin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg>',
  phone:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.1-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.4-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/></svg>',
  mail: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/></svg>',
};

/* ---- Impresión de prueba, compartida por todos los documentos ---------------
   La prueba reemplaza el documento por datos ficticios y lo devuelve a su lugar
   cuando terminás de imprimir. Esa restauración es diferida, así que si mientras
   tanto cambiás de tipo o de vista pisaría el documento nuevo con el anterior.
   Por eso cada prueba deja acá cómo deshacerse y se cierra apenas navegás. */
let _prueba = null;
function iniciarPrueba(restaurar) {
  cerrarPrueba(); // si había otra, se deshace primero
  _prueba = { restaurar, timers: [] };
  return _prueba;
}
function cerrarPrueba() {
  if (!_prueba) return;
  const p = _prueba;
  _prueba = null; // se limpia antes de restaurar
  p.timers.forEach(clearTimeout); // así el restaurar no se reentra
  p.restaurar();
}
/* Mientras hay datos de ejemplo cargados no se guarda nada: los ficticios no
   deben pisar el borrador real en localStorage. */
function enPrueba() {
  return _prueba !== null;
}

function showView(name) {
  cerrarPrueba();
  document.body.setAttribute('data-view', name);
  const comp = name === 'factura' || name === 'recibo' || name === 'nota';
  $$('.view').forEach(v => v.classList.remove('active'));
  const el = comp ? $('#viewComprobante') : $('#view' + name.charAt(0).toUpperCase() + name.slice(1));
  if (el) el.classList.add('active');
  $$('.nav-item[data-view]').forEach(n => n.classList.toggle('active', n.dataset.view === name));
  updateCrumb(name);
  if (name === 'inicio') {
    recentPage = 1;
    renderInicio();
  } else if (name === 'config') renderConfig();
  else if (name === 'clientes') renderClientesView();
  else if (comp) showComprobante(name);
  else requestAnimationFrame(() => fitZoom());
}

/* Migas de pan de la barra superior: sección y documento en edición. */
function updateCrumb(name = document.body.getAttribute('data-view')) {
  const comp = name === 'factura' || name === 'recibo' || name === 'nota';
  const titulo = { inicio: 'Inicio', config: 'Configuración', clientes: 'Clientes' }[name];
  if (titulo) {
    $('#crumb').innerHTML = '<b>' + titulo + '</b>';
    return;
  }
  const seccion = comp ? COMP_INFO[name].nav : 'Remitos';
  const actual = comp
    ? (compDoc && compDoc.cliente.razonSocial) || COMP_INFO[name].nuevo
    : doc.cliente.razonSocial || doc.cliente.senores || 'Nuevo remito';
  $('#crumb').innerHTML = '<span>' + esc(seccion) + '</span><i>›</i><b>' + esc(actual) + '</b>';
}

let recentPage = 1;
const RECENT_PER = 8;

/* Saludo según la hora de Buenos Aires y fecha larga, como encabezado del inicio. */
function renderSaludo() {
  const ahora = new Date(Date.now() + serverOffset);
  const hora = +new Intl.DateTimeFormat('es-AR', {
    timeZone: 'America/Argentina/Buenos_Aires',
    hour: 'numeric',
    hourCycle: 'h23',
  }).format(ahora);
  $('#inicioHi').textContent = hora < 12 ? 'Buen día' : hora < 20 ? 'Buenas tardes' : 'Buenas noches';
  const fecha = new Intl.DateTimeFormat('es-AR', {
    timeZone: 'America/Argentina/Buenos_Aires',
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(ahora);
  $('#inicioFecha').textContent = fecha.charAt(0).toUpperCase() + fecha.slice(1);
}

/* Se muestra arriba de "recientes" como recuperación cuando hay trabajo sin
   guardar (misma regla que usa el arranque de la app, para que sea coherente):
   aunque se corte internet o se cierre el navegador, está a un clic de retomarse. */
function borradorRecuperable() {
  const b = load(LS.draft, null);
  return hayTrabajoPendiente(b) ? b : null;
}

function renderInicio() {
  renderSaludo();
  const docs = datos.remitos;
  const stat = (icn, v, l) =>
    `<div class="stat"><div class="icn">${icn}</div><div><div class="v">${esc(String(v))}</div><div class="l">${l}</div></div></div>`;
  $('#inicioStats').innerHTML =
    stat(ICN.doc, datos.remitosTotal, 'Remitos') +
    stat(ICN.receipt, datos.comprobantesTotal, 'Comprobantes') +
    stat(ICN.users, datos.clientes.length, 'Clientes en la agenda');

  const list = $('#inicioRecent');
  const borrador = borradorRecuperable();
  const sorted = [...docs].sort((a, b) => (b.updated || 0) - (a.updated || 0));
  const totalPag = Math.max(1, Math.ceil(sorted.length / RECENT_PER));
  if (recentPage > totalPag) recentPage = totalPag;
  const desde = (recentPage - 1) * RECENT_PER;
  const pagina = sorted.slice(desde, desde + RECENT_PER);

  const cnt = $('#recentCount');
  cnt.textContent = datos.remitosTotal;
  cnt.hidden = !datos.remitosTotal;

  if (!docs.length && !borrador) {
    list.innerHTML = `<div class="empty">${ICN.doc}<span>Todavía no hay remitos guardados.<br>Creá el primero con «Nuevo remito».</span></div>`;
    return;
  }

  const fila = (d, esBorrador) => {
    const fecha = d.fecha || (d.updated ? new Date(d.updated).toLocaleDateString('es-AR') : '');
    const nItems = (d.items || []).filter(i => i.descripcion).length;
    const estado = esBorrador
      ? '<span class="doc-state draft">Borrador · sin guardar</span>'
      : d.estado === 'autorizado'
        ? '<span class="doc-state auth">Autorizado</span>'
        : '<span class="doc-state saved">Guardado</span>';
    const nro = d.numero ? 'Nº ' + d.numero : 'Sin número';
    const autor = !esBorrador && d.actualizado_por ? ' · ' + d.actualizado_por : '';
    return `<div class="doc-row${esBorrador ? ' is-draft' : ''}" data-id="${escA(d.id || '')}" data-borrador="${esBorrador ? 1 : ''}">
      <span class="doc-ico">${esBorrador ? ICN.edit : ICN.doc}</span>
      <div class="doc-main"><strong>${esc(d.cliente.razonSocial || d.cliente.senores || 'Sin razón social')}</strong><small>${esc(nro)} · ${esc(d.cliente.cuit || 'sin CUIT')}</small></div>
      <div class="doc-meta">${esc(fecha)}${nItems ? ' · ' + nItems + (nItems === 1 ? ' ítem' : ' ítems') : ''}${esc(autor)}</div>
      ${estado}
      <div class="doc-act">
        <button class="icon-btn" data-open title="${esBorrador ? 'Retomar' : 'Abrir'}">${ICN.edit}</button>
        ${esBorrador ? '' : `<button class="icon-btn" data-print title="Imprimir">${ICN.print}</button>`}
        ${!esBorrador && d.estado === 'autorizado' ? '' : `<button class="icon-btn danger" data-del title="${esBorrador ? 'Descartar borrador' : 'Eliminar'}">${ICN.trash}</button>`}
      </div></div>`;
  };

  let html = '';
  if (borrador && recentPage === 1) html += fila(borrador, true);
  html += pagina.map(d => fila(d, false)).join('');
  if (totalPag > 1) {
    html += `<div class="doc-pager">
      <button class="btn sm" data-rp="${recentPage - 1}" ${recentPage <= 1 ? 'disabled' : ''}>← Anteriores</button>
      <span class="pg">Página ${recentPage} de ${totalPag}</span>
      <button class="btn sm" data-rp="${recentPage + 1}" ${recentPage >= totalPag ? 'disabled' : ''}>Siguientes →</button>
    </div>`;
  }
  list.innerHTML = html;

  list.querySelectorAll('[data-rp]').forEach(b =>
    b.addEventListener('click', () => {
      recentPage = Math.max(1, +b.dataset.rp);
      renderInicio();
    })
  );
  list.querySelectorAll('.doc-row').forEach(r =>
    r.addEventListener('click', e => {
      const esBorr = r.dataset.borrador === '1';
      if (e.target.closest('[data-del]')) {
        if (esBorr) {
          if (!confirm('¿Descartar el borrador en curso? Se perderá lo que estabas cargando.')) return;
          doc = emptyRemito();
          save(LS.draft, doc);
          formFromDoc();
          renderInicio();
          toast('Borrador descartado', 1400);
          return;
        }
        eliminarRemito(r.dataset.id).then(renderInicio);
        return;
      }
      if (esBorr) {
        showView('remito');
        return;
      } // retomar el borrador en curso
      const p = e.target.closest('[data-print]');
      openDoc(r.dataset.id);
      if (p) setTimeout(imprimir, 150);
    })
  );
}
/* Abre un remito guardado conservando su fecha de emisión: la fecha automática
   solo corre en los remitos nuevos. */
function cargarRemito(d) {
  cerrarPrueba();
  doc = clone(d);
  fechaManual = true;
  if (!doc.fecha) applyCurrentDate(true);
  formFromDoc();
  renderPreview();
  dibujarPlantilla();
  save(LS.draft, doc);
}
/* Elimina un remito del servidor (con confirmación). Devuelve si se eliminó. */
async function eliminarRemito(id) {
  const d = datos.remitos.find(x => x.id === id);
  const nombre = d ? d.cliente.razonSocial || d.cliente.senores || 'este remito' : 'este remito';
  if (!confirm('¿Eliminar el remito de «' + nombre + '»? Se elimina para todo el equipo.')) return false;
  try {
    await api('remitos.php?id=' + encodeURIComponent(id), { method: 'DELETE' });
  } catch (e) {
    avisarError(e, 'No se pudo eliminar');
    return false;
  }
  datos.remitos = datos.remitos.filter(x => x.id !== id);
  datos.remitosTotal = Math.max(0, datos.remitosTotal - 1);
  if (doc.id === id) {
    doc = emptyRemito();
    save(LS.draft, doc);
    formFromDoc();
  }
  toast('Remito eliminado', 1400);
  return true;
}
function openDoc(id) {
  const d = datos.remitos.find(x => x.id === id);
  if (!d) return;
  cargarRemito(d);
  showView('remito');
  toast('Remito cargado', 1200);
}

/* ===================== CONFIGURACIÓN · Impresión, ARCA y usuarios =====================
   Los datos del emisor y la conexión con ARCA son ajustes compartidos: los ven todos
   y solo los modifica un administrador. El certificado y la clave privada se envían
   una única vez al servidor, que los guarda cifrados; nunca quedan en el navegador. */
const emisor = () => datos.ajustes.emisor;
let certEstado = { homologacion: null, produccion: null }; // datos públicos informados por el servidor

function renderConfig() {
  const e = emisor();
  $('#apiAmbiente').value = e.ambiente;
  $('#apiCuit').value = fmtCuit(e.cuit);
  $('#apiPv').value = e.ptoVenta || '';
  $('#remPvCfg').value = String(parseInt(fiscal.ptoVenta, 10) || '');
  $('#apiEnabled').checked = !!e.emision;
  const admin = esAdmin();
  ['#apiAmbiente', '#apiCuit', '#apiPv', '#remPvCfg', '#apiEnabled'].forEach(id => ($(id).disabled = !admin));
  ['#apiSave', '#certUpload', '#certDelete'].forEach(id => ($(id).hidden = !admin));
  updateModeUI();
  renderCertificado();
  consultarCertificado();
  if (admin) renderUsuarios();
}

/* Registra un evento en la auditoría del servidor (remito, comprobante, error…).
   No interrumpe nunca la operación del usuario: sin sesión o sin red, se omite. */
function auditar(categoria, accion, detalle, nivel) {
  if (!sesion.usuario) return;
  api('evento.php', {
    method: 'POST',
    body: { categoria, accion, detalle: detalle || '', nivel: nivel || 'info' },
  }).catch(() => {});
}

/* ============================ CONEXIÓN ============================
   Los datos viven en el servidor. Si se corta la conexión, el documento en curso
   se sigue guardando en el equipo como borrador y se sube solo al volver la red. */
function estaEnLinea() {
  return navigator.onLine !== false;
}

function wireConexion() {
  renderEstadoChip();
  window.addEventListener('online', () => {
    renderEstadoChip();
    toast('Conexión restablecida', 1500, true);
    reintentarPendiente();
  });
  window.addEventListener('offline', () => {
    renderEstadoChip();
    toast('Sin conexión: el documento en curso se sigue guardando en este equipo', 3000);
  });
}

/* Sube el borrador que quedó pendiente por un corte de conexión. */
async function reintentarPendiente() {
  if (!sesion.usuario || !doc.pendiente || !estaEnLinea()) return;
  if (await guardar({ silencioso: true })) toast('El remito pendiente se guardó en el servidor', 2400, true);
}

function updateModeUI() {
  const pill = $('#apiStatePill');
  if (pill) {
    pill.textContent = emisor().emision ? 'Activa' : 'Inactiva';
    pill.className = 'mode-pill ' + (emisor().emision ? 'api' : 'wait');
  }
  renderEstadoChip();
}
/* Un solo indicador para el modo de numeración y la conexión. */
function renderEstadoChip() {
  const chip = $('#modeChip'),
    txt = $('#modeChipT');
  if (!chip) return;
  const online = estaEnLinea();
  chip.classList.toggle('offline', !online);
  chip.classList.toggle('api', online && emisor().emision);
  txt.textContent = !online
    ? 'Sin conexión'
    : (emisor().emision ? 'Emisión electrónica' : 'Numeración propia') + (doc.pendiente ? ' · 1 por guardar' : '');
}

/* ----------------------------- Certificado ----------------------------- */
const ICN_CERT =
  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 4 6v6c0 5 3.4 8.3 8 9 4.6-.7 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/></svg>';
const fmtFechaHora = s => (s ? s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4) : '—');
const fmtCuit = c => {
  const d = String(c || '').replace(/\D/g, '');
  return d.length === 11 ? d.slice(0, 2) + '-' + d.slice(2, 10) + '-' + d.slice(10) : c || '';
};
const ambienteNombre = a => (a === 'produccion' ? 'producción' : 'homologación');

async function consultarCertificado() {
  try {
    certEstado = (await api('arca.php')).ambientes;
  } catch (e) {
    /* sin conexión: se muestra el último estado conocido */
  }
  renderCertificado();
}

function renderCertificado() {
  const amb = $('#apiAmbiente').value || emisor().ambiente;
  const c = certEstado[amb];
  const box = $('#certStatus'),
    pill = $('#certPill');
  if (c) {
    const vence = c.vencido
      ? 'Vencido el ' + fmtFechaHora(c.valido_hasta)
      : 'Vence el ' +
        fmtFechaHora(c.valido_hasta) +
        (c.dias_para_vencer <= 30 ? ' · faltan ' + c.dias_para_vencer + ' días' : '');
    box.innerHTML = `<div class="cert">
        <span class="cert-ico">${ICN_CERT}</span>
        <div class="cert-main"><strong>${esc(c.titular || 'Certificado')} · CUIT ${esc(fmtCuit(c.cuit))}</strong>
          <small>${esc(vence)} · emitido por ${esc(c.emisor || '—')}</small>
          <div class="cert-meta"><span>Huella SHA-256 <code>${esc(c.huella.slice(0, 16))}…</code></span>
            <span>Cargado el ${esc(fmtFechaHora(c.cargado))}${c.cargado_por ? ' · ' + esc(c.cargado_por) : ''}</span></div></div>
      </div>`;
    pill.textContent = c.vencido ? 'Vencido' : 'Cargado';
    pill.className = 'mode-pill ' + (c.vencido ? 'off' : 'on');
  } else {
    box.innerHTML = `<div class="cert empty-cert"><span class="cert-ico">${ICN_CERT}</span>
        <div class="cert-main"><strong>Sin certificado para ${ambienteNombre(amb)}</strong>
          <small>${esAdmin() ? 'Cargá el certificado emitido por ARCA y su clave privada.' : 'Lo carga un administrador.'}</small></div></div>`;
    pill.textContent = 'Sin cargar';
    pill.className = 'mode-pill wait';
  }
  $('#certTest').disabled = !c;
  $('#certDelete').disabled = !c;
}

function avisoCert(tipo, titulo, detalle, id = '#certAlert') {
  const el = $(id);
  if (!el) return;
  el.className = 'cfg-alert ' + tipo;
  el.innerHTML = '<div><b>' + titulo + '</b>' + (detalle || '') + '</div>';
  el.hidden = false;
}
const ocultar = id => {
  const el = $(id);
  if (el) el.hidden = true;
};

/* Lee un archivo PEM elegido por el usuario. Se valida la forma acá para dar un
   aviso inmediato; la validación criptográfica la hace el servidor. */
function leerPem(input, marca) {
  return new Promise((resolve, reject) => {
    const f = input.files && input.files[0];
    if (!f)
      return reject(
        new Error('Elegí el archivo ' + (marca === 'CERTIFICATE' ? 'del certificado' : 'de la clave privada'))
      );
    if (f.size > 16384) return reject(new Error('El archivo «' + f.name + '» es demasiado grande para ser un PEM'));
    const r = new FileReader();
    r.onload = () => {
      const t = String(r.result || '').trim();
      if (!t.includes('-----BEGIN') || !t.includes(marca)) {
        return reject(
          new Error(
            'El archivo «' +
              f.name +
              '» no parece ' +
              (marca === 'CERTIFICATE' ? 'un certificado PEM' : 'una clave privada PEM')
          )
        );
      }
      resolve(t);
    };
    r.onerror = () => reject(new Error('No se pudo leer «' + f.name + '»'));
    r.readAsText(f);
  });
}

function nombreArchivo(input) {
  const el = $('.file-name[data-for="' + input.id + '"]');
  if (!el) return;
  const f = input.files && input.files[0];
  el.textContent = f ? f.name : 'Ningún archivo elegido';
  el.classList.toggle('on', !!f);
}
function limpiarFormCert() {
  ['#certFile', '#keyFile', '#keyPass', '#certConfirm'].forEach(id => {
    const el = $(id);
    if (el) el.value = '';
  });
  ['#certFile', '#keyFile'].forEach(id => nombreArchivo($(id)));
  ocultar('#certFormAlert');
}
function abrirCargaCert() {
  limpiarFormCert();
  $('#certAmbiente').value = $('#apiAmbiente').value;
  openPanel($('#certModal'), $('#backdrop5'));
}
async function enviarCertificado(e) {
  e.preventDefault();
  const btn = $('#certSend');
  const ambiente = $('#certAmbiente').value;
  const confirmacion = $('#certConfirm').value;
  if (!confirmacion)
    return avisoCert('warn', 'Falta tu contraseña', ' Se pide para confirmar la operación.', '#certFormAlert');
  let certificado, clave;
  try {
    [certificado, clave] = await Promise.all([
      leerPem($('#certFile'), 'CERTIFICATE'),
      leerPem($('#keyFile'), 'PRIVATE KEY'),
    ]);
  } catch (err) {
    return avisoCert('warn', 'Revisá los archivos', ' ' + esc(err.message), '#certFormAlert');
  }
  btn.disabled = true;
  btn.textContent = 'Validando…';
  try {
    const j = await api('arca.php', {
      method: 'POST',
      body: {
        accion: 'cargar',
        ambiente,
        cuit: emisor().cuit,
        certificado,
        clave,
        frase: $('#keyPass').value,
        confirmacion,
      },
    });
    certificado = clave = null; // no se retienen en memoria más de lo necesario
    certEstado[ambiente] = j.credencial;
    limpiarFormCert();
    closePanels();
    $('#apiAmbiente').value = ambiente;
    renderCertificado();
    avisoCert(
      'ok',
      'Certificado cargado',
      ' Se validó contra su clave privada y quedó guardado cifrado en el servidor.'
    );
  } catch (err) {
    avisoCert('err', 'El certificado no se cargó', ' ' + esc(err.message), '#certFormAlert');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Validar y guardar';
  }
}
async function probarConexionArca() {
  const btn = $('#certTest'),
    ambiente = $('#apiAmbiente').value;
  btn.disabled = true;
  btn.textContent = 'Probando…';
  try {
    const j = await api('arca.php', { method: 'POST', body: { accion: 'probar', ambiente } });
    const s = j.servidores || {};
    avisoCert(
      'ok',
      'Conexión con ARCA verificada',
      ` Servidores: aplicación ${esc(s.app)}, base ${esc(s.db)}, autenticación ${esc(s.auth)}. Acceso vigente hasta el ${esc(fmtFechaHora(j.ticket_vence))} a las ${esc((j.ticket_vence || '').slice(11, 16))}.`
    );
  } catch (e) {
    avisoCert('err', 'ARCA no aceptó la conexión', ' ' + esc(e.message));
  } finally {
    btn.textContent = 'Probar conexión';
    renderCertificado();
  }
}
async function quitarCertificado() {
  const ambiente = $('#apiAmbiente').value;
  const confirmacion = prompt(
    'Para quitar el certificado de ' + ambienteNombre(ambiente) + ', confirmá con tu contraseña:'
  );
  if (!confirmacion) return;
  try {
    await api('arca.php', { method: 'POST', body: { accion: 'quitar', ambiente, confirmacion } });
    certEstado[ambiente] = null;
    renderCertificado();
    avisoCert(
      'ok',
      'Certificado quitado',
      ' Se eliminaron del servidor el certificado, la clave privada y los accesos vigentes.'
    );
  } catch (e) {
    avisoCert('err', 'No se pudo quitar el certificado', ' ' + esc(e.message));
  }
}
function avisoApi(tipo, titulo, detalle) {
  avisoCert(tipo, titulo, detalle, '#apiAlert');
}

/* Guarda los datos del emisor y el punto de venta de los remitos (solo administradores). */
async function saveApiCfg(silencioso) {
  const valores = {
    ambiente: $('#apiAmbiente').value,
    cuit: $('#apiCuit').value,
    ptoVenta: $('#apiPv').value.trim(),
    emision: $('#apiEnabled').checked,
  };
  try {
    await api('ajustes.php', { method: 'POST', body: { seccion: 'emisor', valores } });
    const j = await api('ajustes.php', {
      method: 'POST',
      body: { seccion: 'remito', valores: { ptoVenta: $('#remPvCfg').value || '1', copias: fiscal.copias } },
    });
    datos.ajustes = j.ajustes;
    updateModeUI();
    syncFiscalUI();
    if (compDoc) renderComprobanteEditor();
    if (!silencioso) toast('Configuración guardada', 1600, true);
    return true;
  } catch (e) {
    $('#apiEnabled').checked = emisor().emision;
    const faltan = e.respuesta && e.respuesta.faltan;
    if (faltan) {
      avisoApi(
        'warn',
        'Todavía no se puede activar la emisión electrónica',
        'Falta:<ul>' + faltan.map(x => '<li>' + esc(x) + '</li>').join('') + '</ul>'
      );
    } else {
      avisoApi('err', 'No se guardó la configuración', ' ' + esc(e.message));
    }
    return false;
  }
}
function wireConfig() {
  $$('.cfg-tab').forEach(t =>
    t.addEventListener('click', () => {
      $$('.cfg-tab').forEach(x => x.classList.toggle('active', x === t));
      $$('.cfg-panel').forEach(x => x.classList.remove('active'));
      const panel = { local: '#cfgLocal', api: '#cfgApi', usuarios: '#cfgUsuarios' }[t.dataset.tab];
      $(panel).classList.add('active');
      if (t.dataset.tab === 'usuarios') renderUsuarios();
    })
  );
  $('#cfgOpenCalib').addEventListener('click', () => {
    syncCalibUI();
    openPanel($('#calib'), $('#backdrop'));
  });
  $('#apiSave').addEventListener('click', () => saveApiCfg());
  $('#apiAmbiente').addEventListener('change', () => {
    ocultar('#certAlert');
    renderCertificado();
  });
  $('#certUpload').addEventListener('click', abrirCargaCert);
  $('#certTest').addEventListener('click', probarConexionArca);
  $('#certDelete').addEventListener('click', quitarCertificado);
  $('#certForm').addEventListener('submit', enviarCertificado);
  ['#certFile', '#keyFile'].forEach(id => $(id).addEventListener('change', e => nombreArchivo(e.target)));
  $('#certCancel').addEventListener('click', () => {
    limpiarFormCert();
    closePanels();
  });
  $('#certClose').addEventListener('click', () => {
    limpiarFormCert();
    closePanels();
  });
  $('#backdrop5').addEventListener('click', () => {
    limpiarFormCert();
    closePanels();
  });

  /* El interruptor solo se activa con todo lo necesario: el servidor lo verifica y,
     si falta algo, lo indica. */
  $('#apiEnabled').addEventListener('change', async e => {
    const activar = e.target.checked;
    if (await saveApiCfg(true)) {
      avisoApi(
        activar ? 'ok' : 'warn',
        activar ? 'Emisión electrónica activada' : 'Emisión electrónica desactivada',
        activar
          ? ' La numeración de cada comprobante se toma de ARCA.'
          : ' Los comprobantes vuelven a numerarse en el sistema.'
      );
    }
  });
  ['#apiCuit', '#apiPv', '#remPvCfg'].forEach(id => $(id).addEventListener('input', () => ocultar('#apiAlert')));
}

/* =============================== GUARDADO =============================== */
function touch() {
  if (enPrueba()) return; // los datos de ejemplo no se guardan
  doc.updated = Date.now();
  // El documento se captura ahora y no al disparar: si en esos 400 ms arranca una
  // impresión de prueba, igual se guarda el documento real.
  const d = doc;
  clearTimeout(touch._t);
  touch._t = setTimeout(() => save(LS.draft, d), 400);
}
/* Guarda el remito en el servidor, que asigna el número si falta. Sin conexión,
   el remito queda como borrador pendiente en el equipo y se sube al volver la red. */
let _guardando = false;
async function guardar(opts = {}) {
  cerrarPrueba(); // si había una prueba en curso, se guarda el documento real y no el de ejemplo
  clearTimeout(touch._t);
  if (_guardando) return false;
  _guardando = true;
  const cuerpo = clone(doc);
  delete cuerpo.pendiente;
  try {
    const j = await api('remitos.php', { method: 'POST', body: cuerpo });
    doc = j.remito;
    actualizarEnLista(datos.remitos, doc);
    if (!j.actualizado) datos.remitosTotal++;
    save(LS.draft, doc);
    formFromDoc();
    renderPreview();
    dibujarPlantilla();
    updateCrumb();
    renderEstadoChip();
    if (!opts.silencioso) toast('Remito guardado · Nº ' + doc.numero, 1900, true);
    return true;
  } catch (e) {
    if (e.status === 0) {
      doc.pendiente = true;
      save(LS.draft, doc);
      renderEstadoChip();
      toast('Sin conexión: el remito quedó en este equipo y se guarda solo al volver la red', 3600, 'err');
    } else {
      avisarError(e, 'No se guardó el remito');
    }
    return false;
  } finally {
    _guardando = false;
  }
}
function nuevo() {
  cerrarPrueba(); // primero vuelve el documento real: si no, dirty() compararía los datos de ejemplo
  if (dirty() && !confirm('¿Empezar un remito nuevo? Se perderán los cambios sin guardar.')) return false;
  doc = emptyRemito();
  formFromDoc();
  applyCurrentDate(true);
  save(LS.draft, doc);
  syncFiscalUI();
  updateCrumb();
  return true;
}
/* ¿Hay cambios sin guardar? Un remito vacío nunca guardado no cuenta como cambio. */
function dirty() {
  if (doc.pendiente) return true;
  const saved = datos.remitos.find(d => d.id === doc.id);
  if (!saved) return tieneContenido(doc);
  return JSON.stringify(saved) !== JSON.stringify(doc);
}

/* ---- Fecha automática: hora de Argentina (Buenos Aires) tomada del reloj del
 *      servidor (sincronizado por NTP). Cae al reloj local si no hay red. ---- */
let serverOffset = 0;
let fechaManual = false; // true si el usuario editó la fecha a mano
async function syncServerTime() {
  try {
    const r = await fetch(location.href, { method: 'HEAD', cache: 'no-store' });
    const d = r.headers.get('Date');
    if (d) {
      const t = new Date(d).getTime();
      if (!isNaN(t)) serverOffset = t - Date.now();
    }
  } catch (e) {
    /* sin conexión: se usa el reloj de la PC */
  }
}
function currentFecha() {
  const now = new Date(Date.now() + serverOffset);
  return new Intl.DateTimeFormat('es-AR', {
    timeZone: 'America/Argentina/Buenos_Aires',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(now);
}
/* Completa la fecha con la actual, salvo que el usuario la haya cambiado a mano */
function applyCurrentDate(force) {
  if (enPrueba()) return; // el reloj no debe escribir sobre los datos de ejemplo
  if (fechaManual && !force) return;
  if (force) fechaManual = false;
  doc.fecha = currentFecha();
  const inp = $('[data-path="fecha"]');
  if (inp) inp.value = esFecha(inp) ? dmyAIso(doc.fecha) : doc.fecha;
  renderPreview();
}

function renderSavedList() {
  const docs = datos.remitos;
  const list = $('#savedList');
  list.innerHTML = '';
  if (!docs.length) {
    list.innerHTML = `<div class="empty">${ICN.doc}<span>Todavía no hay remitos guardados.</span></div>`;
    return;
  }
  docs.forEach(d => {
    const el = document.createElement('div');
    el.className = 'saved-item';
    const fecha = new Date(d.updated).toLocaleDateString('es-AR');
    const n = (d.items || []).filter(i => i.descripcion).length;
    el.innerHTML = `<div>
        <strong>${esc(d.cliente.razonSocial || d.cliente.senores || 'Sin razón social')}</strong>
        <div class="meta">${esc(d.numero ? 'Nº ' + d.numero + ' · ' : '')}${esc(d.fecha || fecha)}${n ? ' · ' + n + (n === 1 ? ' ítem' : ' ítems') : ''}</div>
      </div>
      ${d.estado === 'autorizado' ? '<span class="doc-state auth">Autorizado</span>' : `<button class="icon-btn danger" data-del title="Eliminar" aria-label="Eliminar">${ICN.trash}</button>`}`;
    el.addEventListener('click', e => {
      if (e.target.closest('[data-del]')) return;
      cargarRemito(d);
      closePanels();
      if (document.body.getAttribute('data-view') !== 'remito') showView('remito');
      toast('Remito cargado', 1400);
    });
    const del = el.querySelector('[data-del]');
    if (del)
      del.addEventListener('click', async () => {
        if (!(await eliminarRemito(d.id))) return;
        renderSavedList();
        if (document.body.getAttribute('data-view') === 'inicio') renderInicio();
      });
    list.appendChild(el);
  });
}

/* ============================ AGENDA CLIENTES ============================
   La agenda es compartida: vive en el servidor y la usa todo el equipo. */
const CLIENTE_REMITO = [
  'senores',
  'razonSocial',
  'cuit',
  'domicilio',
  'localidad',
  'telefono',
  'cp',
  'mail',
  'nombre',
  'otrosDatos',
];
const clienteDeAgenda = id => datos.clientes.find(c => String(c.id) === String(id));
/* Solo los datos del cliente que van en el remito (sin id ni datos de auditoría). */
function clienteParaRemito(c) {
  const out = emptyRemito().cliente;
  CLIENTE_REMITO.forEach(k => (out[k] = c[k] || ''));
  return out;
}

function renderClientes() {
  const sel = $('#selCliente');
  sel.innerHTML = '<option value="">Cargar cliente de la agenda…</option>';
  datos.clientes.forEach(c => {
    const o = document.createElement('option');
    o.value = c.id;
    o.textContent = c.razonSocial || c.cuit || 'Cliente';
    sel.appendChild(o);
  });
}

/* Alta o edición de un cliente en el servidor. Devuelve el cliente guardado o null. */
async function guardarClienteServidor(c) {
  try {
    const j = await api('clientes.php', { method: 'POST', body: c });
    const i = datos.clientes.findIndex(x => x.id === j.cliente.id);
    if (i >= 0) datos.clientes[i] = j.cliente;
    else datos.clientes.push(j.cliente);
    datos.clientes.sort((a, b) => (a.razonSocial || '').localeCompare(b.razonSocial || '', 'es'));
    return j.cliente;
  } catch (e) {
    avisarError(e, 'No se guardó el cliente');
    return null;
  }
}

function wireClientes() {
  /* Guarda en la agenda el cliente cargado en el remito: si el CUIT ya existe, lo actualiza. */
  $('#btnGuardarCliente').addEventListener('click', async () => {
    const c = { ...doc.cliente };
    if (!c.razonSocial && !c.cuit) {
      toast('Completá razón social o CUIT', 1800);
      return;
    }
    const existente = c.cuit && datos.clientes.find(x => x.cuit === c.cuit);
    if (existente) Object.assign(c, { id: existente.id, condIVA: existente.condIVA });
    if (await guardarClienteServidor(c)) {
      renderClientes();
      toast(existente ? 'Cliente actualizado en la agenda' : 'Cliente guardado en la agenda', 1600, true);
    }
  });
  $('#selCliente').addEventListener('change', e => {
    const c = clienteDeAgenda(e.target.value);
    if (!c) return;
    doc.cliente = clienteParaRemito(c);
    formFromDoc();
    renderPreview();
    touch();
    updateCrumb();
    e.target.value = '';
  });
}

/* ---------------------- Vista Clientes (agenda completa) ---------------- */
let cliFilter = '',
  cliEditId = null;
const CLI_FIELDS = {
  razonSocial: 'cliRazon',
  cuit: 'cliCuit',
  telefono: 'cliTel',
  senores: 'cliSenores',
  domicilio: 'cliDom',
  localidad: 'cliLoc',
  cp: 'cliCp',
  mail: 'cliMail',
  nombre: 'cliNombre',
  otrosDatos: 'cliOtros',
};

function renderClientesView() {
  const grid = $('#cliGrid');
  if (!grid) return;
  const all = datos.clientes;
  const cnt = $('#cliCount');
  if (cnt) cnt.textContent = all.length ? all.length + (all.length === 1 ? ' cliente' : ' clientes') : '';
  const q = cliFilter.trim().toLowerCase();
  const list = all.filter(
    c =>
      !q ||
      [c.razonSocial, c.cuit, c.localidad, c.senores, c.mail, c.nombre, c.domicilio].some(v =>
        (v || '').toLowerCase().includes(q)
      )
  );
  if (!all.length) {
    grid.innerHTML = `<div class="cli-empty card empty">${ICN.users}<span>Todavía no hay clientes. Agregá el primero con «Nuevo cliente» o importá una planilla.</span></div>`;
    return;
  }
  if (!list.length) {
    grid.innerHTML = `<div class="cli-empty card empty">${ICN.users}<span>Ningún cliente coincide con «${esc(cliFilter)}».</span></div>`;
    return;
  }
  grid.innerHTML = list
    .map(c => {
      const id = escA(String(c.id));
      const dir = [c.domicilio, c.localidad].filter(Boolean).join(', ');
      const nombre = c.razonSocial || c.senores || 'Cliente sin nombre';
      return `<div class="cli-card" data-id="${id}">
      <div class="cli-top"><span class="avatar">${esc(iniciales(nombre))}</span>
        <div class="grow"><div class="cli-name">${esc(nombre)}</div>
        ${c.cuit ? `<div class="cli-cuit">CUIT ${esc(c.cuit)}</div>` : ''}</div></div>
      ${dir ? `<div class="cli-line">${ICN.pin}<span>${esc(dir)}</span></div>` : ''}
      ${c.telefono ? `<div class="cli-line">${ICN.phone}<span>${esc(c.telefono)}</span></div>` : ''}
      ${c.mail ? `<div class="cli-line">${ICN.mail}<span>${esc(c.mail)}</span></div>` : ''}
      <div class="cli-acts">
        <button class="btn sm soft grow" data-use="${id}">Usar en remito</button>
        <button class="btn sm ghost" data-edit="${id}">Editar</button>
        <button class="btn sm ghost icon danger" data-del="${id}" title="Eliminar" aria-label="Eliminar">${ICN.trash}</button>
      </div></div>`;
    })
    .join('');
  grid.querySelectorAll('[data-use]').forEach(b => b.addEventListener('click', () => useCliente(b.dataset.use)));
  grid
    .querySelectorAll('[data-edit]')
    .forEach(b => b.addEventListener('click', () => openClienteModal(b.dataset.edit)));
  grid.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', () => delCliente(b.dataset.del)));
}
function openClienteModal(id) {
  const c = id != null ? clienteDeAgenda(id) || null : null;
  cliEditId = c ? c.id : null;
  $('#cliModalTitle').textContent = c ? 'Editar cliente' : 'Nuevo cliente';
  for (const k in CLI_FIELDS) {
    const el = $('#' + CLI_FIELDS[k]);
    if (el) el.value = (c && c[k]) || '';
  }
  openPanel($('#clienteModal'), $('#backdrop4'));
  setTimeout(() => {
    const r = $('#cliRazon');
    if (r) r.focus();
  }, 60);
}
async function saveClienteModal() {
  const c = cliEditId ? { id: cliEditId } : {};
  for (const k in CLI_FIELDS) {
    const el = $('#' + CLI_FIELDS[k]);
    c[k] = el ? el.value.trim() : '';
  }
  if (!c.razonSocial && !c.cuit) {
    toast('Completá al menos razón social o CUIT', 2000);
    return;
  }
  const btn = $('#cliSave');
  btn.disabled = true;
  const guardado = await guardarClienteServidor(c);
  btn.disabled = false;
  if (!guardado) return;
  renderClientes();
  renderClientesView();
  closePanels();
  toast('Cliente guardado', 1600, true);
}
async function delCliente(id) {
  const c = clienteDeAgenda(id);
  if (!c) return;
  if (
    !confirm(
      '¿Eliminar a «' + (c.razonSocial || c.cuit || 'este cliente') + '» de la agenda? Se elimina para todo el equipo.'
    )
  )
    return;
  try {
    await api('clientes.php?id=' + encodeURIComponent(c.id), { method: 'DELETE' });
  } catch (e) {
    return avisarError(e, 'No se eliminó el cliente');
  }
  datos.clientes = datos.clientes.filter(x => x.id !== c.id);
  renderClientes();
  renderClientesView();
  toast('Cliente eliminado', 1400);
}
function useCliente(id) {
  const c = clienteDeAgenda(id);
  if (!c) return;
  doc.cliente = clienteParaRemito(c);
  formFromDoc();
  renderPreview();
  touch();
  showView('remito');
  toast('Cliente cargado en el remito', 1600, true);
}
/* ---- Importar clientes desde CSV / Excel ---- */
const CLI_HEADER_TO_FIELD = (() => {
  const syn = {
    razonSocial: ['razonsocial', 'razonsoc', 'razon', 'cliente', 'empresa', 'nombrecliente', 'nombreempresa', 'nombre'],
    cuit: ['cuit', 'cuil', 'cuitcuil', 'cuitocuil'],
    telefono: ['telefono', 'telefonos', 'tel', 'celular', 'movil', 'phone'],
    senores: ['senores', 'senor', 'sres', 'sr'],
    domicilio: ['domicilio', 'direccion', 'calle', 'dom'],
    localidad: ['localidad', 'ciudad', 'loc'],
    cp: ['cp', 'codigopostal', 'codpostal', 'postal'],
    mail: ['mail', 'email', 'correo', 'correoelectronico', 'emails'],
    nombre: ['contacto', 'nombrecontacto', 'nombredecontacto', 'referente', 'encargado', 'atencion'],
    otrosDatos: ['otrosdatos', 'otros', 'observaciones', 'observacion', 'notas', 'obs', 'comentarios', 'provincia'],
  };
  const m = {};
  for (const f in syn) syn[f].forEach(h => (m[h] = f));
  return m;
})();
function normHeader(s) {
  const t = (s == null ? '' : String(s)).toLowerCase().normalize('NFD');
  let out = '';
  for (let i = 0; i < t.length; i++) {
    const c = t.charCodeAt(i);
    if (c >= 768 && c <= 879) continue; // marcas de acento (U+0300..U+036F)
    const ch = t[i];
    if ((ch >= 'a' && ch <= 'z') || (ch >= '0' && ch <= '9')) out += ch;
  }
  return out;
}
function ensureXLSX(cb) {
  if (window.XLSX) return cb();
  const s = document.createElement('script');
  s.src = 'js/lib/xlsx.full.min.js?v=130';
  s.onload = () => cb();
  s.onerror = () => toast('No se pudo cargar el lector de Excel/CSV', 2800);
  document.head.appendChild(s);
}
function importClientesFile(file) {
  ensureXLSX(() => {
    const reader = new FileReader();
    reader.onload = e => {
      try {
        const buf = new Uint8Array(e.target.result);
        /* xlsx (zip 'PK') y xls (OLE 'ÐÏ') son binarios; el resto lo tratamos
           como CSV de texto UTF-8 (evita que se rompan los acentos de los títulos). */
        const bin = (buf[0] === 0x50 && buf[1] === 0x4b) || (buf[0] === 0xd0 && buf[1] === 0xcf);
        let wb;
        if (bin) {
          wb = XLSX.read(buf, { type: 'array' });
        } else {
          let text = new TextDecoder('utf-8').decode(buf);
          if (text.charCodeAt(0) === 0xfeff) text = text.slice(1); // quitar BOM
          wb = XLSX.read(text, { type: 'string' });
        }
        const ws = wb.Sheets[wb.SheetNames[0]];
        const rows = XLSX.utils.sheet_to_json(ws, { defval: '', raw: false });
        applyClienteImport(rows);
      } catch (err) {
        toast('No se pudo leer el archivo. ¿Es un CSV o Excel válido?', 3000);
      }
    };
    reader.onerror = () => toast('No se pudo abrir el archivo', 2600);
    reader.readAsArrayBuffer(file);
  });
}
/* Envía la planilla al servidor, que da de alta o actualiza cada cliente por CUIT. */
async function applyClienteImport(rows) {
  if (!rows || !rows.length) {
    toast('El archivo no tiene filas de datos', 2600);
    return;
  }
  const clientes = rows.map(row => {
    const c = {};
    for (const k in row) {
      const f = CLI_HEADER_TO_FIELD[normHeader(k)];
      if (f && c[f] == null) c[f] = String(row[k] ?? '').trim();
    }
    return c;
  });
  let added = 0,
    upd = 0,
    skip = 0;
  try {
    for (let i = 0; i < clientes.length; i += 2000) {
      const j = await api('clientes.php', {
        method: 'POST',
        body: { accion: 'importar', clientes: clientes.slice(i, i + 2000) },
      });
      added += j.agregados;
      upd += j.actualizados;
      skip += j.omitidos;
    }
    await refrescarClientes();
  } catch (e) {
    return avisarError(e, 'La importación no se completó');
  }
  renderClientes();
  renderClientesView();
  toast(`Importados: ${added} nuevos, ${upd} actualizados${skip ? `, ${skip} sin datos` : ''}`, 3600, true);
}
function descargarPlantillaClientes() {
  const headers = [
    'Razón Social',
    'CUIT',
    'Teléfono',
    'Domicilio',
    'Localidad',
    'C.P.',
    'Mail',
    'Nombre contacto',
    'Señor/es',
    'Otros datos',
  ];
  const ej = [
    'Distribuidora Ejemplo S.A.',
    '30-12345678-9',
    '011 4000-0000',
    'Av. Corrientes 1234',
    'CABA',
    'C1043',
    'compras@ejemplo.com.ar',
    'María Gómez',
    '',
    'Fila de ejemplo: reemplazar o borrar',
  ];
  const csv =
    String.fromCharCode(0xfeff) +
    [headers, ej].map(r => r.map(v => '"' + String(v).replace(/"/g, '""') + '"').join(',')).join('\r\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
  a.download = 'clientes-planilla-modelo.csv';
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 1500);
}

function wireClientesView() {
  const nb = $('#btnNuevoCliente');
  if (nb) nb.addEventListener('click', () => openClienteModal());
  const s = $('#cliSearch');
  if (s)
    s.addEventListener('input', e => {
      cliFilter = e.target.value;
      renderClientesView();
    });
  $('#cliSave').addEventListener('click', saveClienteModal);
  $('#cliCancel').addEventListener('click', closePanels);
  $('#cliModalClose').addEventListener('click', closePanels);
  $('#backdrop4').addEventListener('click', closePanels);
  const imp = $('#btnImportarCli'),
    impF = $('#cliImportFile'),
    plt = $('#btnPlantillaCli');
  if (imp && impF) {
    imp.addEventListener('click', () => impF.click());
    impF.addEventListener('change', e => {
      if (e.target.files[0]) {
        importClientesFile(e.target.files[0]);
        e.target.value = '';
      }
    });
  }
  if (plt) plt.addEventListener('click', descargarPlantillaClientes);
}

/* ============ COMPROBANTES: Factura / Recibo / Nota C/D (modo local) ============
   Documentos autogenerados con nuestro diseño (papel en blanco / PDF). El CAE y el
   QR fiscal se agregan al conectar ARCA; hasta entonces salen como BORRADOR local. */
const COMP_INFO = {
  factura: { nav: 'Facturas', badge: 'Factura', title: 'FACTURA', nuevo: 'Nueva factura', guardada: 'guardada' },
  recibo: { nav: 'Recibos', badge: 'Recibo', title: 'RECIBO', nuevo: 'Nuevo recibo', guardada: 'guardado' },
  nota: { nav: 'Notas de crédito y débito', badge: 'Nota', title: 'NOTA', nuevo: 'Nueva nota', guardada: 'guardada' },
};
let compDoc = null,
  compZoom = 1;

/* El CAE y su vencimiento forman parte de cada comprobante. Sin CAE, el documento
   se imprime marcado como BORRADOR. */
const apiActiva = () => !!emisor().emision;
const pv5 = v => String(parseInt(String(v || '').replace(/\D/g, ''), 10) || 1).padStart(5, '0');

/* Próximo número según ARCA (último autorizado + 1) con la emisión electrónica activa.
   Devuelve null si no corresponde (letra X, sin conexión o sin certificado): en ese
   caso se usa el correlativo local. */
async function proximoNumeroArca(d) {
  if (!apiActiva() || !/^[ABC]$/.test(d.letra)) return null;
  const pv = (d.ptoVenta || emisor().ptoVenta || '').replace(/\D/g, '');
  if (!pv) return null;
  const q = new URLSearchParams({
    ambiente: emisor().ambiente,
    tipo: d.tipo,
    subtipo: d.subtipo || '',
    letra: d.letra,
    pv,
  });
  try {
    return (await api('proximo.php?' + q)).numero;
  } catch (e) {
    if (e.status !== 0) toast('No se pudo consultar ARCA: ' + e.message, 3600, 'err');
  }
  return null;
}
/* Propone el número del comprobante en edición (sin pisar uno cargado a mano). */
async function proponerNumero() {
  const d = compDoc;
  if (!d || d.numero || d.id) return;
  const n = await proximoNumeroArca(d);
  if (n && compDoc === d && !d.numero) {
    d.numero = n;
    touchComp();
    renderComprobanteEditor();
    renderComprobante();
  }
}

function emptyComprobante(tipo) {
  return {
    id: null,
    tipo,
    letra: 'X',
    subtipo: 'credito',
    ptoVenta: '0001',
    numero: '',
    fecha: '',
    cliente: { razonSocial: '', cuit: '', condIVA: 'Consumidor Final', domicilio: '', localidad: '', cp: '' },
    condVenta: 'Contado',
    items: [{ descripcion: '', cantidad: '1', precio: '' }],
    alicuota: '21',
    concepto: '',
    monto: '',
    medioPago: 'Efectivo',
    compAsoc: { tipo: 'Factura', letra: 'B', pv: '0001', nro: '' },
    cae: '',
    caeVto: '',
    observaciones: '',
    updated: Date.now(),
  };
}
const fmtMoney = n => new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(Number(n) || 0);
const numF = v => {
  const n = parseFloat(String(v).replace(',', '.'));
  return isNaN(n) ? 0 : n;
};

function compTotals(d) {
  const neto = (d.items || []).reduce((s, it) => s + numF(it.cantidad) * numF(it.precio), 0);
  if (d.letra === 'A') {
    const iva = (neto * numF(d.alicuota)) / 100;
    return { neto, iva, total: neto + iva, disc: true };
  }
  return { neto, iva: 0, total: neto, disc: false };
}
/* Próximo número de la serie (tipo, letra y punto de venta), para la vista previa:
   el definitivo lo asigna el servidor al guardar. */
function nextCompNumero(d) {
  const serie = x =>
    x.tipo === d.tipo &&
    (x.subtipo || '') === (d.tipo === 'nota' ? d.subtipo || 'credito' : '') &&
    x.letra === d.letra &&
    pv5(x.ptoVenta) === pv5(d.ptoVenta);
  const n = datos.comprobantes.filter(serie).reduce((m, x) => Math.max(m, correlativo(x.numero)), 0) + 1;
  return String(n).padStart(8, '0');
}

/* ---- número a letras (para el recibo) ---- */
function enteroALetras(n) {
  const U = [
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
  const D = ['', '', 'veinti', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
  const C = [
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
  if (n === 0) return 'cero';
  if (n <= 20) return U[n];
  if (n < 30) return 'veinti' + U[n - 20];
  if (n < 100) {
    const d = Math.floor(n / 10),
      u = n % 10;
    return D[d] + (u ? ' y ' + U[u] : '');
  }
  if (n === 100) return 'cien';
  if (n < 1000) {
    const c = Math.floor(n / 100),
      r = n % 100;
    return C[c] + (r ? ' ' + enteroALetras(r) : '');
  }
  if (n < 1000000) {
    const m = Math.floor(n / 1000),
      r = n % 1000;
    return (m === 1 ? 'mil' : enteroALetras(m) + ' mil') + (r ? ' ' + enteroALetras(r) : '');
  }
  const mm = Math.floor(n / 1000000),
    r = n % 1000000;
  return (mm === 1 ? 'un millón' : enteroALetras(mm) + ' millones') + (r ? ' ' + enteroALetras(r) : '');
}
function montoALetras(v) {
  const num = numF(v),
    ent = Math.floor(num),
    cent = Math.round((num - ent) * 100);
  let s = enteroALetras(ent) + ' peso' + (ent === 1 ? '' : 's');
  if (cent > 0) s += ' con ' + String(cent).padStart(2, '0') + '/100';
  return s.charAt(0).toUpperCase() + s.slice(1);
}

/* ---- estado / navegación ---- */
function loadCompDraft(tipo) {
  const drafts = load(LS.compDraft, {});
  return drafts[tipo] ? Object.assign(emptyComprobante(tipo), clone(drafts[tipo])) : emptyComprobante(tipo);
}
function saveCompDraft(d) {
  if (!d && enPrueba()) return; // sin argumento es el compDoc actual, que puede ser de ejemplo
  const t = d || compDoc;
  if (!t || !t.tipo) return;
  const drafts = load(LS.compDraft, {});
  drafts[t.tipo] = t;
  save(LS.compDraft, drafts);
}
function touchComp() {
  if (enPrueba()) return;
  compDoc.updated = Date.now();
  // Se guarda el borrador que se estaba editando aunque se cambie de tipo antes de los 400 ms.
  const d = compDoc;
  clearTimeout(touchComp._t);
  touchComp._t = setTimeout(() => saveCompDraft(d), 400);
}
let _compRaf = false;
function scheduleCompRender() {
  if (_compRaf) return;
  _compRaf = true;
  requestAnimationFrame(() => {
    _compRaf = false;
    renderComprobante();
  });
}

function showComprobante(tipo) {
  cerrarPrueba(); // devuelve el documento anterior antes de cambiar
  if (!compDoc || compDoc.tipo !== tipo) compDoc = loadCompDraft(tipo);
  if (!compDoc.fecha) compDoc.fecha = currentFecha();
  if (!compDoc.ptoVenta && emisor().ptoVenta) compDoc.ptoVenta = pv5(emisor().ptoVenta);
  $('#compBadge').textContent = COMP_INFO[tipo].badge;
  renderComprobanteEditor();
  renderComprobante();
  updateCrumb(tipo);
  requestAnimationFrame(fitCompZoom);
  setTimeout(fitCompZoom, 80);
  proponerNumero();
}

/* Empieza un comprobante vacío del tipo indicado. */
function nuevoComprobante(tipo) {
  cerrarPrueba(); // si no, showComprobante() revertiría el comprobante nuevo al anterior
  compDoc = emptyComprobante(tipo);
  compDoc.fecha = currentFecha();
  saveCompDraft();
  showComprobante(tipo);
}

/* ---- editor ---- */
function renderComprobanteEditor() {
  const d = compDoc,
    t = d.tipo,
    ed = $('#compEditor');
  const fac = t === 'factura',
    rec = t === 'recibo',
    nota = t === 'nota';
  let html = `
    <div class="section"><h3>${COMP_INFO[t].badge}</h3><div class="section-body">
      <div class="grid">
        <div class="field"><label>Comprobante (letra)</label><select data-cpath="letra">
          ${['A', 'B', 'C', 'X'].map(l => `<option ${d.letra === l ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
        ${
          nota
            ? `<div class="field"><label>Clase</label><select data-cpath="subtipo">
          <option value="credito" ${d.subtipo === 'credito' ? 'selected' : ''}>Crédito</option>
          <option value="debito" ${d.subtipo === 'debito' ? 'selected' : ''}>Débito</option></select></div>`
            : ''
        }
        <div class="field"><label>Punto de venta</label><input data-cpath="ptoVenta" inputmode="numeric" pattern="[0-9]{1,5}" maxlength="5" value="${escA(d.ptoVenta)}"></div>
        <div class="field"><label>Número <span class="note-auto">${apiActiva() ? 'según ARCA' : 'correlativo'}</span></label><input data-cpath="numero" inputmode="numeric" pattern="[0-9]{1,8}" maxlength="8" value="${escA(d.numero)}" placeholder="${nextCompNumero(d)}"></div>
        <div class="field full"><label>Fecha <span class="note-auto">automática</span></label><input type="date" data-cpath="fecha" value="${escA(dmyAIso(d.fecha))}"></div>
      </div>
    </div></div>

    <div class="section"><h3>Cliente</h3><div class="section-body">
      <select id="compSelCliente" class="select-sm mb-md" aria-label="Cargar un cliente de la agenda"><option value="">Cargar cliente de la agenda…</option></select>
      <div class="grid">
        <div class="field full"><label>Razón Social / Nombre</label><input data-cpath="cliente.razonSocial" value="${escA(d.cliente.razonSocial)}"></div>
        <div class="field"><label>CUIT / DNI</label><input data-cpath="cliente.cuit" inputmode="numeric" maxlength="13" pattern="[0-9]{2}-?[0-9]{8}-?[0-9]|[0-9]{7,8}" placeholder="30-71140975-7" value="${escA(d.cliente.cuit)}"></div>
        <div class="field"><label>Condición IVA</label><select data-cpath="cliente.condIVA">
          ${['Consumidor Final', 'Responsable Inscripto', 'Monotributo', 'Exento'].map(o => `<option ${d.cliente.condIVA === o ? 'selected' : ''}>${o}</option>`).join('')}</select></div>
        <div class="field full"><label>Domicilio</label><input data-cpath="cliente.domicilio" value="${escA(d.cliente.domicilio)}"></div>
        <div class="field"><label>Localidad</label><input data-cpath="cliente.localidad" value="${escA(d.cliente.localidad)}"></div>
        <div class="field"><label>C.P.</label><input data-cpath="cliente.cp" maxlength="8" pattern="[A-Za-z]?[0-9]{4}[A-Za-z]{0,3}" placeholder="B1636 o 1636" value="${escA(d.cliente.cp)}"></div>
      </div>
    </div></div>`;

  if (fac || nota) {
    if (nota)
      html += `
      <div class="section"><h3>Comprobante asociado</h3><div class="section-body"><div class="grid">
        <div class="field"><label>Tipo</label><input data-cpath="compAsoc.tipo" value="${escA(d.compAsoc.tipo)}"></div>
        <div class="field"><label>Letra</label><input data-cpath="compAsoc.letra" value="${escA(d.compAsoc.letra)}"></div>
        <div class="field"><label>Pto. venta</label><input data-cpath="compAsoc.pv" inputmode="numeric" pattern="[0-9]{1,5}" maxlength="5" value="${escA(d.compAsoc.pv)}"></div>
        <div class="field"><label>Número</label><input data-cpath="compAsoc.nro" inputmode="numeric" pattern="[0-9]{1,8}" maxlength="8" value="${escA(d.compAsoc.nro)}"></div>
      </div></div></div>`;
    html += `
      <div class="section"><h3>Ítems</h3><div class="section-body">
        <div id="compItems"></div>
        <button class="btn sm soft" id="compAddItem">＋ Agregar ítem</button>
        <div class="grid mt-sm">
          <div class="field"><label>Condición de venta</label><input data-cpath="condVenta" value="${escA(d.condVenta)}"></div>
          <div class="field"><label>Alícuota de IVA (%) <span class="note">letra A</span></label><input data-cpath="alicuota" inputmode="decimal" pattern="[0-9]{1,2}([.,][0-9]{1,2})?" maxlength="5" value="${escA(d.alicuota)}"></div>
        </div>
      </div></div>`;
  }
  if (rec) {
    html += `
      <div class="section"><h3>Recibo</h3><div class="section-body"><div class="grid">
        <div class="field"><label>Monto ($)</label><input data-cpath="monto" inputmode="decimal" pattern="[0-9.]{1,12}([,][0-9]{1,2})?" value="${escA(d.monto)}" placeholder="0,00"></div>
        <div class="field"><label>Forma de pago</label><select data-cpath="medioPago">
          ${['Efectivo', 'Transferencia', 'Cheque', 'Tarjeta', 'Otro'].map(o => `<option ${d.medioPago === o ? 'selected' : ''}>${o}</option>`).join('')}</select></div>
        <div class="field full"><label>En concepto de</label><textarea data-cpath="concepto" rows="2">${esc(d.concepto)}</textarea></div>
      </div></div></div>`;
  }
  const api = apiActiva();
  html += `
    <div class="section"><h3>Autorización fiscal (CAE)</h3><div class="section-body">
      <p class="cal-mode-wrap">Numeración:
        <span class="cal-mode ${api ? 'full' : 'ov'}">${api ? 'ARCA · ' + ambienteNombre(emisor().ambiente) : 'Correlativa del sistemo'}</span></p>
      <div class="grid">
        <div class="field"><label for="compCae">CAE Nº</label><input id="compCae" inputmode="numeric" pattern="[0-9]{14}" maxlength="14" value="${escA(d.cae)}" placeholder="14 dígitos"></div>
        <div class="field"><label for="compCaeVto">Vencimiento del CAE</label><input type="date" id="compCaeVto" value="${escA(dmyAIso(d.caeVto))}"></div>
      </div>
      <p class="hint">Sin CAE, el comprobante se imprime marcado como <b>BORRADOR</b>.</p>
    </div></div>
    <div class="section"><h3>Observaciones</h3><div class="section-body">
      <div class="field full"><textarea data-cpath="observaciones" rows="2" maxlength="600">${esc(d.observaciones)}</textarea></div>
    </div></div>`;
  ed.innerHTML = html;
  const cae = $('#compCae'),
    caeV = $('#compCaeVto');
  if (cae)
    cae.addEventListener('input', () => {
      d.cae = cae.value.trim();
      touchComp();
      renderComprobante();
    });
  if (caeV)
    caeV.addEventListener('input', () => {
      d.caeVto = esFecha(caeV) ? isoADmy(caeV.value) : caeV.value.trim();
      touchComp();
      renderComprobante();
    });

  if (fac || nota) renderCompItems();
  if (fac || nota)
    $('#compAddItem').addEventListener('click', () => {
      d.items.push({ descripcion: '', cantidad: '1', precio: '' });
      touchComp();
      renderCompItems();
      renderComprobante();
    });
  renderCompClientesSelect();
}

function renderCompItems() {
  const box = $('#compItems');
  if (!box) return;
  box.innerHTML = '';
  compDoc.items.forEach((it, i) => {
    const card = document.createElement('div');
    card.className = 'item-card';
    card.innerHTML = `
      <div class="item-top"><span class="item-badge">Ítem ${i + 1}</span>
        <button class="item-del" data-del="${i}" title="Quitar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button></div>
      <div class="grid">
        <div class="field full"><label>Descripción</label><input data-ci="${i}" data-ck="descripcion" value="${escA(it.descripcion)}"></div>
        <div class="field"><label>Cantidad</label><input data-ci="${i}" data-ck="cantidad" inputmode="decimal" pattern="[0-9]{1,7}([.,][0-9]{1,3})?" value="${escA(it.cantidad)}"></div>
        <div class="field"><label>Precio unit.</label><input data-ci="${i}" data-ck="precio" inputmode="decimal" pattern="[0-9.]{1,12}([,][0-9]{1,2})?" value="${escA(it.precio)}" placeholder="0,00"></div>
      </div>`;
    box.appendChild(card);
  });
  box.querySelectorAll('input').forEach(inp =>
    inp.addEventListener('input', () => {
      compDoc.items[+inp.dataset.ci][inp.dataset.ck] = inp.value;
      touchComp();
      scheduleCompRender();
    })
  );
  box.querySelectorAll('[data-del]').forEach(b =>
    b.addEventListener('click', () => {
      compDoc.items.splice(+b.dataset.del, 1);
      if (!compDoc.items.length) compDoc.items.push({ descripcion: '', cantidad: '1', precio: '' });
      touchComp();
      renderCompItems();
      renderComprobante();
    })
  );
}
function renderCompClientesSelect() {
  const sel = $('#compSelCliente');
  if (!sel) return;
  sel.innerHTML = '<option value="">Cargar cliente de la agenda…</option>';
  datos.clientes.forEach(c => {
    const o = document.createElement('option');
    o.value = c.id;
    o.textContent = c.razonSocial || c.cuit || 'Cliente';
    sel.appendChild(o);
  });
  sel.addEventListener('change', e => {
    const c = clienteDeAgenda(e.target.value);
    if (!c) return;
    Object.assign(compDoc.cliente, {
      razonSocial: c.razonSocial || '',
      cuit: c.cuit || '',
      condIVA: c.condIVA || compDoc.cliente.condIVA,
      domicilio: c.domicilio || '',
      localidad: c.localidad || '',
      cp: c.cp || '',
    });
    touchComp();
    renderComprobanteEditor();
    renderComprobante();
    updateCrumb();
    e.target.value = '';
  });
}
function bindCompForm() {
  $('#compEditor').addEventListener('input', e => {
    const el = e.target.closest('[data-cpath]');
    if (!el) return;
    setPath(compDoc, el.dataset.cpath, esFecha(el) ? isoADmy(el.value) : el.value);
    const p = el.dataset.cpath;
    if (p === 'letra' || p === 'subtipo' || p === 'ptoVenta') {
      touchComp();
      renderComprobante();
      if (!compDoc.id) {
        compDoc.numero = '';
        const n = $('[data-cpath="numero"]');
        if (n) n.value = '';
        proponerNumero();
      }
      return;
    }
    if (p === 'cliente.razonSocial') updateCrumb();
    touchComp();
    scheduleCompRender();
  });
}

/* ---- documento ---- */
function renderComprobante() {
  const d = compDoc,
    page = $('#compPage');
  if (!d || !page) return;
  const C = COMPANY,
    rec = d.tipo === 'recibo';
  let title = COMP_INFO[d.tipo].title;
  if (d.tipo === 'nota') title = 'NOTA DE ' + (d.subtipo === 'debito' ? 'DÉBITO' : 'CRÉDITO');
  const numero = d.numero || nextCompNumero(d);
  const cli = d.cliente;
  /* Diseño tomado de facturas emitidas por ARCA:
     banda "ORIGINAL" arriba · emisor izq · letra + código al centro · tipo y numeración der. */
  const codigo = codigoComprobante(d.tipo, d.subtipo, d.letra);
  const emisor = `
    <div class="cmp-emisor">
      <div class="cmp-emisor-top">
        <img class="cmp-logo" src="${C.logo}" alt="">
        <div class="cmp-emisor-nom">${esc(C.nombre)}</div>
      </div>
      <div class="l"><b>Razón Social:</b> ${esc(C.nombre)}</div>
      <div class="l"><b>Domicilio Comercial:</b> ${esc(C.direccion)} - ${esc(C.localidad)}</div>
      <div class="l"><b>Condición frente al IVA:</b> IVA Responsable Inscripto</div>
    </div>`;
  const head = `
    <div class="cmp-head">
      ${emisor}
      <div class="cmp-letra"><span class="L">${esc(d.letra || 'X')}</span><span class="cod">COD. ${esc(codigo)}</span></div>
      <div class="cmp-tipo">
        <h1>${esc(title)}</h1>
        <p><b>Punto de Venta:</b> ${esc(d.ptoVenta || '0001')} &nbsp;&nbsp; <b>Comp. Nro:</b> ${esc(numero)}</p>
        <p><b>Fecha de Emisión:</b> ${esc(d.fecha || '')}</p>
        <p><b>CUIT:</b> ${esc(C.cuit)}</p>
        <p><b>Ingresos Brutos:</b> ${esc(C.ingBrutos)}</p>
        <p><b>Fecha de Inicio de Actividades:</b> ${esc(C.inicioAct)}</p>
      </div>
    </div>`;
  const periodo = rec
    ? ''
    : `
    <div class="cmp-periodo">
      <span><b>Período Facturado Desde:</b> ${esc(d.fecha || '')} &nbsp; <b>Hasta:</b> ${esc(d.fecha || '')}</span>
      <span><b>Fecha de Vto. para el pago:</b> ${esc(d.vtoPago || d.fecha || '')}</span>
    </div>`;
  const receptor = `
    <div class="cmp-receptor">
      <div class="rc"><b>CUIT:</b> ${esc(cli.cuit || '—')}</div>
      <div class="rc"><b>Apellido y Nombre / Razón Social:</b> ${esc(cli.razonSocial || '—')}</div>
      <div class="rc"><b>Condición frente al IVA:</b> ${esc(cli.condIVA || '—')}</div>
      <div class="rc"><b>Domicilio:</b> ${esc([cli.domicilio, cli.localidad, cli.cp].filter(Boolean).join(' - ') || '—')}</div>
      <div class="rc full"><b>Condición de venta:</b> ${esc(rec ? d.medioPago || '—' : d.condVenta || '—')}</div>
    </div>`;

  let body = '';
  if (rec) {
    body = `
      <div class="cmp-recibo">
        Recibí de <span class="fill">${esc(cli.razonSocial || '—')}</span>
        la suma de pesos:
        <div class="big">${esc(montoALetras(d.monto))}</div>
        <p>En concepto de: <span class="fill">${esc(d.concepto || '—')}</span></p>
        <p>Forma de pago: <span class="fill">${esc(d.medioPago || '—')}</span></p>
        <div class="big" style="text-align:right;margin-top:8mm">${esc(fmtMoney(d.monto))}</div>
      </div>`;
  } else {
    const tot = compTotals(d);
    /* Columnas iguales a la factura real de ARCA */
    const rows = (d.items || [])
      .map((it, i) => {
        const sub = numF(it.cantidad) * numF(it.precio);
        const vacio = !it.descripcion && !it.precio;
        return `<tr>
        <td class="num">${vacio ? '' : String(i + 1).padStart(3, '0')}</td>
        <td>${esc(it.descripcion || '')}</td>
        <td class="num">${esc(it.cantidad || '')}</td>
        <td>${vacio ? '' : 'unidades'}</td>
        <td class="num">${it.precio !== '' ? esc(fmtMoney(it.precio)) : ''}</td>
        <td class="num">${vacio ? '' : '0,00'}</td>
        <td class="num">${vacio ? '' : esc(fmtMoney(sub))}</td></tr>`;
      })
      .join('');
    body = `
      ${d.tipo === 'nota' ? `<div class="cmp-periodo"><span><b>Comprobante asociado:</b> ${esc(d.compAsoc.tipo)} ${esc(d.compAsoc.letra)} — ${esc(d.compAsoc.pv)}-${esc(d.compAsoc.nro || '—')}</span></div>` : ''}
      <table class="cmp-items">
        <thead><tr>
          <th class="num" style="width:13mm">Código</th>
          <th>Producto / Servicio</th>
          <th class="num" style="width:16mm">Cantidad</th>
          <th style="width:18mm">U. Medida</th>
          <th class="num" style="width:26mm">Precio Unit.</th>
          <th class="num" style="width:17mm">% Bonif</th>
          <th class="num" style="width:28mm">Subtotal</th>
        </tr></thead>
        <tbody>${rows}</tbody>
      </table>
      <div class="cmp-totes">
        ${
          tot.disc
            ? `<div class="row"><span>Importe Neto Gravado: $</span><span class="n">${esc(fmtMoney(tot.neto))}</span></div>
        <div class="row"><span>IVA ${esc(d.alicuota)}%: $</span><span class="n">${esc(fmtMoney(tot.iva))}</span></div>`
            : `<div class="row"><span>Subtotal: $</span><span class="n">${esc(fmtMoney(tot.neto))}</span></div>`
        }
        <div class="row"><span>Importe Otros Tributos: $</span><span class="n">${esc(fmtMoney(0))}</span></div>
        <div class="row total"><span>Importe Total: $</span><span class="n">${esc(fmtMoney(tot.total))}</span></div>
      </div>`;
  }
  /* RG 1415 art.14: cada ejemplar lleva la palabra ORIGINAL / DUPLICADO.
     En la factura real de ARCA va en una BANDA ARRIBA, centrada. */
  const copia = page.dataset.copia || 'ORIGINAL';
  const obs = d.observaciones ? `<div class="cmp-obs"><b>Observaciones:</b> ${esc(d.observaciones)}</div>` : '';
  const cae = d.cae,
    caeVto = d.caeVto;
  const foot = `
    ${obs}
    <div class="cmp-foot">
      <div class="cmp-qr">QR<br>fiscal</div>
      <div class="cmp-arca"><b>ARCA</b><br><i>Comprobante Autorizado</i>
        <div class="nota">Esta Agencia no se responsabiliza por los datos ingresados en el detalle de la operación</div></div>
      <div class="cmp-cae">
        <b>CAE N°:</b> ${esc(cae || '—')}<br>
        <b>Fecha de Vto. de CAE:</b> ${esc(caeVto || '—')}
      </div>
    </div>`;
  page.innerHTML = `<div class="cmp-doc">${cae ? '' : '<div class="cmp-borrador">BORRADOR</div>'}
    <div class="cmp-copia">${esc(copia)}</div>${head}${periodo}${receptor}${body}${foot}</div>`;
}

/* Datos ficticios por tipo de comprobante, para la impresión de prueba */
function sampleComprobante(tipo) {
  const base = emptyComprobante(tipo);
  base.fecha = currentFecha();
  base.numero = nextCompNumero(base);
  base.cliente = {
    razonSocial: 'ACME DISTRIBUCIONES S.A.',
    cuit: '30-11111111-1',
    condIVA: 'Responsable Inscripto',
    domicilio: 'Av. Corrientes 1234',
    localidad: 'CABA',
    cp: 'C1043',
  };
  base.observaciones = 'Documento de PRUEBA — datos ficticios.';
  if (tipo === 'recibo') {
    base.letra = 'X';
    base.monto = '158750.50';
    base.medioPago = 'Transferencia';
    base.concepto = 'Pago parcial de servicios de soporte técnico — mes de julio.';
  } else {
    base.letra = 'A';
    base.condVenta = 'Cuenta corriente 30 días';
    base.items = [
      { descripcion: 'Soporte técnico mensual — 40 horas', cantidad: '1', precio: '120000' },
      { descripcion: 'Reparación de control de acceso biométrico', cantidad: '2', precio: '35000' },
      { descripcion: 'Materiales varios (cableado, fichas, canaleta)', cantidad: '1', precio: '18750.5' },
    ];
    if (tipo === 'nota') {
      base.subtipo = 'credito';
      base.compAsoc = { tipo: 'Factura', letra: 'A', pv: '0001', nro: '00000123' };
    }
  }
  return base;
}
function imprimirTestComprobante() {
  // Si ya había una prueba abierta, primero se restaura el comprobante real:
  // de lo contrario, la copia de respaldo serían los datos de ejemplo.
  cerrarPrueba();
  const tipo = compDoc.tipo,
    backup = clone(compDoc);
  const p = iniciarPrueba(() => {
    compDoc = backup;
    renderComprobanteEditor();
    renderComprobante();
  });
  compDoc = sampleComprobante(tipo);
  renderComprobanteEditor();
  renderComprobante();
  toast('Imprimiendo prueba de ' + COMP_INFO[tipo].badge + ' con datos de ejemplo…', 2200);
  p.timers.push(
    setTimeout(() => {
      if (_prueba !== p) return; // se cambió de vista antes de imprimir
      imprimirComprobante();
      alTerminarDeImprimir(() => {
        if (_prueba !== p) return; // ya la cerró un cambio de vista
        cerrarPrueba();
        toast('Prueba enviada; se restauró el documento en edición', 2000, true);
      });
    }, 350)
  );
}

/* Guarda el comprobante en el servidor. Con la emisión electrónica activa el número
   se toma de ARCA; si no, lo asigna el servidor (correlativo de la serie). */
async function guardarComprobante() {
  cerrarPrueba(); // nunca se guardan los datos de ejemplo
  const d = compDoc;
  if (!d.numero && !d.id) d.numero = (await proximoNumeroArca(d)) || '';
  if (compDoc !== d) return; // se cambió de documento mientras se esperaba a ARCA
  try {
    const j = await api('comprobantes.php', { method: 'POST', body: d });
    if (compDoc !== d) return;
    compDoc = Object.assign(emptyComprobante(d.tipo), j.comprobante);
    actualizarEnLista(datos.comprobantes, compDoc);
    if (!d.id) datos.comprobantesTotal++;
  } catch (e) {
    return avisarError(e, 'No se guardó el comprobante');
  }
  saveCompDraft();
  renderComprobanteEditor();
  renderComprobante();
  updateCrumb();
  const info = COMP_INFO[compDoc.tipo];
  toast(info.badge + ' Nº ' + compDoc.ptoVenta + '-' + compDoc.numero + ' ' + info.guardada, 2000, true);
}
/* Ejemplares ORIGINAL / DUPLICADO del comprobante (RG 1415 art. 14) */
function buildCompCopies() {
  const wrap = $('#compPrintCopies'),
    page = $('#compPage');
  if (!wrap || !page) return;
  wrap.innerHTML = '';
  /* Siempre los dos ejemplares: la RG 1415 art. 14 pide como mínimo original y
     duplicado, y el duplicado sale en beige igual que el talonario del remito. */
  ['ORIGINAL', 'DUPLICADO'].forEach(label => {
    page.dataset.copia = label;
    renderComprobante();
    const clone = page.cloneNode(true);
    clone.removeAttribute('id');
    clone.classList.add('copy-page');
    if (label === 'DUPLICADO') clone.classList.add('copy-beige');
    clone.style.removeProperty('--czoom');
    wrap.appendChild(clone);
  });
  page.dataset.copia = 'ORIGINAL';
  renderComprobante();
}
function imprimirComprobante() {
  const st =
    $('#pageStyle') ||
    (() => {
      const s = document.createElement('style');
      s.id = 'pageStyle';
      document.head.appendChild(s);
      return s;
    })();
  st.textContent = '@page{ size:210mm 297mm; margin:0 }';
  const app = document.querySelector('.app');
  buildCompCopies();
  app.classList.add('copies-on');
  document.title =
    COMP_INFO[compDoc.tipo].badge + ' ' + (compDoc.numero || '') + ' ' + (compDoc.cliente.razonSocial || '');
  auditar(
    'comprobante',
    (enPrueba() ? 'Prueba de ' : '') +
      COMP_INFO[compDoc.tipo].badge +
      (enPrueba() ? ' impresa' : ' impresa Nº ' + (compDoc.numero || '—')),
    compDoc.cliente.razonSocial || ''
  );
  window.print();
  alTerminarDeImprimir(() => {
    document.title = APP_TITLE;
    app.classList.remove('copies-on');
    $('#compPrintCopies').innerHTML = '';
  });
}
function setCompZoom(z) {
  const page = $('#compPage');
  if (!page) return;
  compZoom = Math.max(0.25, Math.min(2.5, z));
  page.style.setProperty('--czoom', compZoom);
  const out = $('#compZoomVal');
  if (out) out.textContent = Math.round(compZoom * 100) + '%';
}
function fitCompZoom() {
  const pv = $('#compPreview');
  if (!pv || !$('#compPage')) return;
  const avail = pv.clientWidth - 72,
    pageW = (210 * 96) / 25.4;
  setCompZoom(Math.min(1, avail / pageW));
}

/* ============================== IMPRESIÓN =============================== */
/* Imprime el documento con DATOS FICTICIOS para verificar que todo encaje,
   y después restaura lo que estabas cargando. */
/* Autoriza el remito: le toma el próximo número y el C.A.I. del talonario vigente
   (endpoint talonarios.php, que consume el número en una transacción) y lo marca
   como autorizado. La gestión del talonario es manual —el CAI lo saca el contador
   en ARCA y se carga en el Panel—; de ahí para adelante esto sale solo. */
async function autorizarRemito() {
  if (doc.estado === 'autorizado') {
    return toast('Este remito ya está autorizado · Nº ' + (doc.numero || ''), 2200);
  }
  const btn = $('#btnAutorizar');
  btn.disabled = true;
  try {
    /* Se autoriza un remito guardado: el número y el C.A.I. quedan escritos en el
       servidor en la misma operación, y no pueden perderse aunque se cierre la ventana. */
    if (!(await guardar({ silencioso: true }))) return;
    const pv = parseInt(String(fiscal.ptoVenta).replace(/\D/g, ''), 10) || 1;
    const fecha = doc.fecha || currentFecha(); // dd/mm/aaaa → aaaa-mm-dd
    const iso = /^\d{1,2}\/\d{1,2}\/\d{4}$/.test(fecha) ? fecha.split('/').reverse().join('-') : '';
    const pedido = { accion: 'autorizar', pv, tipo: 91, fecha: iso, remito_id: doc.id };
    let j;
    try {
      j = await api('talonarios.php', { method: 'POST', body: pedido });
    } catch (e) {
      if (!(e.respuesta && e.respuesta.vencido && confirm(e.message + '\n\n¿Autorizar igual con este C.A.I.?'))) {
        return avisarError(e, 'No se autorizó');
      }
      j = await api('talonarios.php', { method: 'POST', body: Object.assign(pedido, { confirmar: true }) });
    }
    doc = (await api('remitos.php?id=' + encodeURIComponent(doc.id))).remito;
    actualizarEnLista(datos.remitos, doc);
    save(LS.draft, doc);
    formFromDoc();
    syncFiscalUI();
    renderPreview();
    dibujarPlantilla();
    const av = j.restantes <= 20 ? ` · quedan ${j.restantes} números en el talonario` : '';
    toast('Remito autorizado · Nº ' + j.numero_formato + av, 3200, true);
  } catch (e) {
    avisarError(e, 'No se autorizó');
  } finally {
    btn.disabled = false;
  }
}

function imprimirTestRemito() {
  cerrarPrueba(); // el respaldo debe ser el remito real, no una muestra anterior
  const backup = clone(doc);
  const p = iniciarPrueba(() => {
    doc = backup;
    formFromDoc();
    renderPreview();
    dibujarPlantilla();
  });
  const t = clone(SAMPLE_REMITO);
  t.id = doc.id;
  t.numero = doc.numero || nextRemitoNumero();
  t.fecha = currentFecha();
  t.transporte.fecha = t.fecha;
  doc = t;
  formFromDoc();
  renderPreview();
  dibujarPlantilla();
  toast('Imprimiendo prueba con datos de ejemplo…', 2200);
  p.timers.push(
    setTimeout(() => {
      if (_prueba !== p) return;
      imprimir();
      alTerminarDeImprimir(() => {
        if (_prueba !== p) return;
        cerrarPrueba();
        toast('Prueba enviada; se restauró el documento en edición', 2000, true);
      });
    }, 350)
  );
}

/* El navegador avisa con 'afterprint' cuando cerraste el diálogo. El temporizador
   queda sólo de red de seguridad por si ese evento no llega.
   Si se imprime otra vez antes de que la anterior se haya desmontado, la limpieza
   vieja se descarta: si no, borraría los ejemplares de la impresión nueva. */
let _limpieza = null;
function alTerminarDeImprimir(fn) {
  if (_limpieza) _limpieza.cancelar();
  let hecho = false;
  const run = () => {
    if (hecho) return;
    hecho = true;
    window.removeEventListener('afterprint', run);
    clearTimeout(reserva);
    if (_limpieza && _limpieza.run === run) _limpieza = null;
    fn();
  };
  const reserva = setTimeout(run, 1500);
  window.addEventListener('afterprint', run);
  _limpieza = {
    run,
    cancelar() {
      hecho = true;
      window.removeEventListener('afterprint', run);
      clearTimeout(reserva);
    },
  };
}

function imprimir() {
  applyCurrentDate();
  applyCalib();
  /* En "documento completo" se imprimen los ejemplares ORIGINAL y DUPLICADO.
     En overlay se imprime una sola pasada (el talonario preimpreso ya trae sus copias). */
  const app = document.querySelector('.app');
  const conCopias = calib.docMode === 'full';
  if (conCopias) {
    buildRemitoCopies();
    app.classList.add('copies-on');
  }
  document.title = 'Remito ' + (doc.numero || '') + ' ' + (doc.cliente.razonSocial || doc.cliente.senores || '');
  auditar(
    'remito',
    enPrueba() ? 'Prueba de remito impresa' : 'Remito impreso Nº ' + (doc.numero || '—'),
    doc.cliente.razonSocial || doc.cliente.senores || ''
  );
  window.print();
  alTerminarDeImprimir(() => {
    document.title = APP_TITLE;
    if (conCopias) {
      app.classList.remove('copies-on');
      $('#printCopies').innerHTML = '';
    }
  });
}
/* Imprime la grilla en mm por el MISMO pipeline que el remito, dentro del
   data-layer (mismo sistema de coordenadas y calibración que los campos).
   Así lo que imprimís y escaneás coincide exactamente con dónde van los datos. */
function printTestSheet() {
  const paper = PAPER_SIZES[calib.paper] || PAPER_SIZES.Remito;
  const W = paper.w,
    H = paper.h;
  applyCalib();
  dataLayer.innerHTML = '';
  const g = document.createElement('div');
  g.style.cssText = 'position:absolute;inset:0;-webkit-print-color-adjust:exact;print-color-adjust:exact';
  let html = '';
  for (let x = 0; x <= W; x += 5) {
    const maj = x % 10 === 0;
    html += `<div style="position:absolute;left:${x}mm;top:0;width:${maj ? 0.3 : 0.12}mm;height:${H}mm;background:${maj ? '#222' : '#8a8a8a'}"></div>`;
    if (maj)
      html +=
        `<div style="position:absolute;left:${x + 0.3}mm;top:1mm;font-size:2.4mm;color:#c0392b;font-weight:700">${x}</div>` +
        `<div style="position:absolute;left:${x + 0.3}mm;top:${H - 4}mm;font-size:2.4mm;color:#c0392b;font-weight:700">${x}</div>`;
  }
  for (let y = 0; y <= H; y += 5) {
    const maj = y % 10 === 0;
    html += `<div style="position:absolute;top:${y}mm;left:0;height:${maj ? 0.3 : 0.12}mm;width:${W}mm;background:${maj ? '#222' : '#8a8a8a'}"></div>`;
    if (maj)
      html +=
        `<div style="position:absolute;top:${y + 0.3}mm;left:1mm;font-size:2.4mm;color:#c0392b;font-weight:700">${y}</div>` +
        `<div style="position:absolute;top:${y + 0.3}mm;left:${W - 8}mm;font-size:2.4mm;color:#c0392b;font-weight:700">${y}</div>`;
  }
  g.innerHTML = html;
  dataLayer.appendChild(g);
  const tpl = $('#template');
  const tplDisp = tpl ? tpl.style.display : '';
  if (tpl) tpl.style.display = 'none'; // grilla limpia, sin el formulario de fondo
  document.title = 'Hoja de prueba - grilla mm';
  const restore = () => {
    if (tpl) tpl.style.display = tplDisp;
    document.title = APP_TITLE;
    renderPreview();
    window.removeEventListener('afterprint', restore);
  };
  window.addEventListener('afterprint', restore);
  window.print();
}

/* Imprime el NOMBRE de cada campo en su posición, para verificar el calce
   sobre un remito real (cada nombre debe caer sobre su etiqueta del formulario). */
function printPositions() {
  const saved = JSON.stringify(doc);
  const savedManual = fechaManual;
  doc = emptyRemito();
  doc.fecha = 'FECHA';
  doc.cliente = {
    senores: 'Señor/es',
    razonSocial: 'Razón Social',
    cuit: 'CUIT',
    domicilio: 'Domicilio',
    localidad: 'Localidad',
    telefono: 'Teléfono',
    cp: 'C.P.',
    mail: 'Mail',
    nombre: 'Nombre',
    otrosDatos: 'Otros Datos',
  };
  doc.items = [
    { articulo: 'Art', descripcion: 'Descripción — renglón 1', cantidad: 'Cant' },
    { articulo: '2', descripcion: 'Descripción — renglón 2', cantidad: 'Cant' },
    { articulo: '3', descripcion: 'Descripción — renglón 3', cantidad: 'Cant' },
  ];
  doc.recibi = {
    tipoDoc: 'DNI',
    nombre: 'Nombre y Apellido',
    documentoNro: 'Documento',
    sector: 'Sector',
    puesto: 'Puesto',
  };
  doc.transporte = {
    nombre: 'Nombre',
    vehiculo: 'Vehículo',
    chofer: 'Chofer',
    lugarEntrega: 'Lugar entrega',
    fecha: 'FECHA',
    hora: 'HORA',
    observaciones: 'Observaciones — texto de prueba para verificar el recuadro',
  };
  renderPreview();
  const restore = () => {
    doc = JSON.parse(saved);
    fechaManual = savedManual;
    formFromDoc();
    renderPreview();
    window.removeEventListener('afterprint', restore);
  };
  window.addEventListener('afterprint', restore);
  applyCalib();
  window.print();
}

/* ============================ EXPORT / IMPORT =========================== */
/* Copia de seguridad de los datos del servidor (remitos, comprobantes y clientes)
   y de la calibración de este equipo. No incluye secretos. */
async function exportAll() {
  let remitos, comprobantes;
  try {
    [remitos, comprobantes] = await Promise.all([
      api('remitos.php?por_pagina=200'),
      api('comprobantes.php?por_pagina=200'),
    ]);
    const juntar = async (ruta, campo, r) => {
      const todos = [...r[campo]];
      for (let p = 2; p <= r.paginas; p++) todos.push(...(await api(ruta + '&pagina=' + p))[campo]);
      return todos;
    };
    remitos = await juntar('remitos.php?por_pagina=200', 'remitos', remitos);
    comprobantes = await juntar('comprobantes.php?por_pagina=200', 'comprobantes', comprobantes);
  } catch (e) {
    return avisarError(e, 'No se pudo armar la copia');
  }
  const data = {
    formato: 'remitos-intellihelp',
    version: 3,
    exportado: new Date().toISOString(),
    docs: remitos,
    comprobantes,
    clientes: datos.clientes,
    calib,
  };
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }));
  a.download = 'remitos-copia-' + currentFecha().split('/').reverse().join('-') + '.json';
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 1500);
}
/* Importa una copia de seguridad al servidor (solo administradores). Los documentos se
   agregan sin pisar los existentes: un remito ya importado se reconoce y se actualiza. */
function importAll(file) {
  if (!esAdmin()) return toast('Solo un administrador puede importar una copia de seguridad', 3000, 'err');
  if (file.size > 20 * 1024 * 1024) {
    return toast('El archivo es demasiado grande para ser una copia de seguridad', 3000, 'err');
  }
  const r = new FileReader();
  r.onload = async () => {
    let d;
    try {
      d = JSON.parse(r.result);
    } catch {
      return toast('El archivo no es una copia de seguridad válida', 2600, 'err');
    }
    const lista = v => Array.isArray(v) && v.every(x => x && typeof x === 'object');
    const docsOk = lista(d.docs) && d.docs.every(x => x.cliente && Array.isArray(x.items));
    if (!d || typeof d !== 'object' || (!docsOk && !lista(d.clientes))) {
      return toast('El archivo no tiene el formato de una copia de seguridad de esta aplicación', 3200, 'err');
    }
    const n = docsOk ? d.docs.length : 0;
    if (!confirm('Se van a agregar al servidor ' + n + ' remitos y los clientes de la copia. ¿Continuar?')) return;
    let fallas = 0;
    try {
      if (lista(d.clientes) && d.clientes.length) {
        await api('clientes.php', { method: 'POST', body: { accion: 'importar', clientes: d.clientes } });
      }
      for (const x of docsOk ? d.docs : []) {
        try {
          await api('remitos.php', {
            method: 'POST',
            body: Object.assign({}, x, {
              id: undefined,
              id_local: 'copia' + x.id,
              numero: x.estado === 'autorizado' ? x.numero : '',
            }),
          });
        } catch (e) {
          if (e.status === 0) throw e;
          fallas++;
        }
      }
      for (const x of lista(d.comprobantes) ? d.comprobantes : []) {
        try {
          await api('comprobantes.php', {
            method: 'POST',
            body: Object.assign({}, x, { id: undefined, numero: x.cae ? x.numero : '' }),
          });
        } catch (e) {
          if (e.status === 0) throw e;
          fallas++;
        }
      }
      await cargarDatos();
    } catch (e) {
      return avisarError(e, 'La importación no se completó');
    }
    renderSavedList();
    renderClientes();
    if (document.body.getAttribute('data-view') === 'inicio') renderInicio();
    toast(
      fallas ? 'Copia importada; ' + fallas + ' documentos no se pudieron importar' : 'Copia de seguridad importada',
      3000,
      fallas ? 'err' : true
    );
  };
  r.readAsText(file);
}

/* =============================== PANELES ================================ */
function openPanel(el, bd) {
  el.classList.add('open');
  bd.classList.add('show');
}
function closePanels() {
  $$('.calib').forEach(e => e.classList.remove('open'));
  $$('.backdrop').forEach(e => e.classList.remove('show'));
}

/* ================================ TOAST ================================ */
/* Aviso breve. tipo: true (éxito), 'err' (error) o nada (informativo). */
function toast(msg, ms = 2000, tipo = false) {
  const t = $('#toast');
  t.textContent = msg;
  t.className = 'toast show' + (tipo === 'err' ? ' err' : tipo ? ' ok' : '');
  clearTimeout(toast._t);
  toast._t = setTimeout(() => {
    t.className = 'toast';
  }, ms);
}

/* ================================ INIT ================================= */
function wire() {
  $('#btnNuevo').addEventListener('click', nuevo);
  $('#btnGuardar').addEventListener('click', guardar);
  $('#btnImprimir').addEventListener('click', imprimir);
  $('#btnAddItem').addEventListener('click', () => {
    doc.items.push({ articulo: '', descripcion: '', cantidad: '' });
    touch();
    renderItemsEditor();
    renderPreview();
  });
  $('#btnFechaHoy').addEventListener('click', () => applyCurrentDate(true));
  $('#btnCalibrar').addEventListener('click', () => {
    syncCalibUI();
    openPanel($('#calib'), $('#backdrop'));
  });
  $('#calibClose').addEventListener('click', closePanels);
  /* navegación del dashboard */
  $$('.nav-item[data-view]').forEach(n =>
    n.addEventListener('click', () => {
      if (!n.disabled) showView(n.dataset.view);
    })
  );
  $$('[data-quick]').forEach(b =>
    b.addEventListener('click', () => {
      const q = b.dataset.quick;
      if (q === 'remito') {
        if (nuevo()) showView('remito');
        return;
      }
      if (q === 'cliente') {
        showView('clientes');
        openClienteModal();
        return;
      }
      showView(q);
      nuevoComprobante(q);
    })
  );
  const abrirGuardados = () => {
    renderSavedList();
    openPanel($('#saved'), $('#backdrop2'));
  };
  $('#btnGestionar').addEventListener('click', abrirGuardados);
  $('#btnGuardados').addEventListener('click', abrirGuardados);
  $('#savedClose').addEventListener('click', closePanels);
  $('#backdrop').addEventListener('click', closePanels);
  $('#backdrop2').addEventListener('click', closePanels);
  $('#btnExport').addEventListener('click', exportAll);
  $('#btnImport').addEventListener('click', () => $('#importFile').click());
  $('#importFile').addEventListener('change', e => {
    if (e.target.files[0]) importAll(e.target.files[0]);
    e.target.value = '';
  });
  $('#zoomIn').addEventListener('click', () => setZoom(zoom + 0.1));
  $('#zoomOut').addEventListener('click', () => setZoom(zoom - 0.1));
  $('#zoomFit').addEventListener('click', fitZoom);
  /* Atajos: Ctrl+P imprime y Ctrl+S guarda el documento de la vista activa. */
  document.addEventListener('keydown', e => {
    const mod = e.ctrlKey || e.metaKey,
      k = (e.key || '').toLowerCase();
    const v = document.body.getAttribute('data-view');
    const comp = v === 'factura' || v === 'recibo' || v === 'nota';
    if (mod && (k === 'p' || k === 's')) {
      e.preventDefault();
      if (v !== 'remito' && !comp) return;
      if (k === 'p') (comp ? imprimirComprobante : imprimir)();
      else (comp ? guardarComprobante : guardar)();
    }
    if (e.key === 'Escape') closePanels();
  });
  window.addEventListener('resize', () => {
    resize();
    if (document.body.getAttribute('data-view') === 'remito') fitZoom();
  });
  wireCalib();
  wireClientes();
  wireClientesView();
  wireAdjust();
  wireSidebar();
  wireConfig();
  wireNavDrawer();
  wireDocMode();
  bindCompForm();
  wireRemitoFiscal();
  /* Barra superior: mismos botones y mismo lugar para todos los documentos */
  $('#btnTestRemito').addEventListener('click', imprimirTestRemito);
  $('#btnAutorizar').addEventListener('click', autorizarRemito);
  $('#tbCompNuevo').addEventListener('click', () => nuevoComprobante(compDoc ? compDoc.tipo : 'factura'));
  $('#tbCompGuardar').addEventListener('click', guardarComprobante);
  $('#tbCompImprimir').addEventListener('click', imprimirComprobante);
  $('#tbCompPdf').addEventListener('click', () => {
    const tipo = compDoc.tipo; // el tipo que se estaba viendo
    toast('En el destino de impresión elegí “Guardar como PDF”', 2800);
    setTimeout(() => {
      if (compDoc && compDoc.tipo === tipo) imprimirComprobante();
    }, 400);
  });
  $('#tbCompTest').addEventListener('click', imprimirTestComprobante);
  const cf = $('#btnCompFit');
  if (cf) cf.addEventListener('click', fitCompZoom);
  const czi = $('#compZoomIn'),
    czo = $('#compZoomOut');
  if (czi) czi.addEventListener('click', () => setCompZoom(compZoom + 0.1));
  if (czo) czo.addEventListener('click', () => setCompZoom(compZoom - 0.1));
  window.addEventListener('resize', () => fitCompZoom());
}

/* Abre un remito por su enlace (?open=<id>), por ejemplo el que devuelve la API al
   crearlo desde otro sistema. */
async function openFromApi(id) {
  history.replaceState(null, '', location.pathname); // el enlace no vuelve a abrirse al recargar
  if (!/^[0-9a-f]{16}$/.test(id)) return toast('El enlace del remito no es válido', 2400, 'err');
  try {
    const rem = (await api('remitos.php?id=' + encodeURIComponent(id))).remito;
    actualizarEnLista(datos.remitos, rem);
    cargarRemito(rem);
    showView('remito');
    toast('Remito abierto', 1600, true);
  } catch (e) {
    avisarError(e, 'No se pudo abrir el remito');
  }
}

/* Cualquier error de JavaScript que se escape queda en la auditoría, para poder
   verlo desde el panel sin depender de que el usuario abra la consola. */
function wireAuditoriaErrores() {
  const registrar = (msg, extra) => auditar('error', String(msg).slice(0, 170), extra || '', 'error');
  window.addEventListener('error', e =>
    registrar(
      e.message || 'Error de JavaScript',
      (e.filename || '').split('/').pop() + (e.lineno ? ':' + e.lineno : '')
    )
  );
  window.addEventListener('unhandledrejection', e =>
    registrar((e.reason && (e.reason.message || e.reason)) || 'Promesa rechazada', '')
  );
}

/* Arranque: se preparan los controles y se verifica la sesión. Los datos se cargan
   recién después del ingreso (appLista). */
async function init() {
  wireAuditoriaErrores();
  wire();
  wireServidor();
  bindForm();
  wireFormatos();
  syncCalibUI();
  applyCalib();
  dibujarPlantilla();
  setDocMode(calib.docMode || 'overlay');
  wireConexion();
  document.fonts &&
    document.fonts.ready.then(() => {
      renderPreview();
    });
  await syncServerTime();
  iniciarSesionApp();
  setInterval(
    async () => {
      await syncServerTime();
      applyCurrentDate();
    },
    5 * 60 * 1000
  );
  /* Sin la función flecha, el evento llegaría como argumento «force» y pisaría
     la fecha que el usuario cargó a mano. */
  window.addEventListener('focus', () => applyCurrentDate());
}

/* Con la sesión iniciada y los datos cargados: se retoma el borrador del usuario y se
   muestra la vista inicial (o el remito del enlace ?open=). */
function appLista() {
  const b = load(LS.draft, null);
  doc = b && (b.pendiente || hayTrabajoPendiente(b)) ? Object.assign(emptyRemito(), b) : emptyRemito();
  fechaManual = !!doc.id;
  compDoc = null;
  formFromDoc();
  applyCurrentDate();
  syncFiscalUI();
  renderClientes();
  renderPreview();
  updateModeUI();
  const openId = new URLSearchParams(location.search).get('open');
  showView(openId ? 'remito' : 'inicio');
  if (openId) openFromApi(openId);
  reintentarPendiente();
}
document.addEventListener('DOMContentLoaded', init);
