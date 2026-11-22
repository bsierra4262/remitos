/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Controles de formulario propios, con el mismo diseño en todos los navegadores:
 *
 *  - Desplegables: cada <select> se dibuja con un botón y una lista propia. El <select>
 *    original queda debajo, transparente: sigue siendo el que tiene el valor, recibe los
 *    eventos «input» y «change» y lo usan el resto del código, los formularios y las pruebas.
 *  - Fechas: los campos .fecha guardan dd/mm/aaaa (el formato que exige la RG 1415), con
 *    máscara al tipear y un calendario propio (lunes primero, «Hoy» y «Borrar»).
 *  - Hora: los campos .hora guardan hh:mm con máscara.
 *  - Tipos de dato (data-tipo): solo se aceptan los caracteres válidos para cada uno.
 *      entero   dígitos (números de comprobante, punto de venta, C.A.I., cantidades enteras)
 *      importe  dígitos, punto de miles y coma decimal (1.234,56)
 *      decimal  dígitos y coma decimal (cantidades)
 *      telefono dígitos, espacios, +, - y paréntesis
 *      cp       letras y dígitos (C.P. de 4 dígitos o CPA), en mayúsculas
 *      cuit     dígitos y guiones (CUIT o DNI)
 *
 *  - Etiquetas: cada <label> sin «for» se asocia con su campo (le da un id si no tiene),
 *    así el lector de pantalla lo nombra y un clic en el rótulo lo enfoca.
 *  - Errores por campo: controles.marcarError(campo, texto) pinta el campo, escribe el
 *    mensaje debajo (aria-invalid + aria-describedby) y se limpia al corregirlo. Los
 *    formularios no muestran los globos nativos del navegador (novalidate): sus errores
 *    salen con este mismo componente.
 *
 * Los controles que se agregan después (por ejemplo, al dibujar el editor de un
 * comprobante) se mejoran solos: un MutationObserver los detecta.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */
'use strict';

(function () {
  const MESES = [
    'enero',
    'febrero',
    'marzo',
    'abril',
    'mayo',
    'junio',
    'julio',
    'agosto',
    'septiembre',
    'octubre',
    'noviembre',
    'diciembre',
  ];
  const DIAS = ['lu', 'ma', 'mi', 'ju', 'vi', 'sá', 'do'];
  const PERMITIDOS = {
    entero: /[0-9]/,
    importe: /[0-9.,]/,
    decimal: /[0-9,]/,
    telefono: /[0-9 +()-]/,
    cp: /[0-9A-Za-z]/,
    cuit: /[0-9-]/,
    fecha: /[0-9/-]/,
    hora: /[0-9:]/,
  };

  /* ------------------------------------------------------------ utilidades */
  const pad = n => String(n).padStart(2, '0');
  function avisar(el) {
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }
  /** dd/mm/aaaa → Date (o null si no es una fecha del calendario). */
  function leerFecha(v) {
    const m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(String(v || '').trim());
    if (!m) return null;
    const d = new Date(+m[3], +m[2] - 1, +m[1]);
    return d.getDate() === +m[1] && d.getMonth() === +m[2] - 1 ? d : null;
  }
  const escribirFecha = d => pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear();
  /** Fecha de hoy en Buenos Aires, aunque el equipo tenga otra zona horaria. */
  function hoyAR() {
    const [d, m, a] = new Intl.DateTimeFormat('es-AR', {
      timeZone: 'America/Argentina/Buenos_Aires',
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
    })
      .format(new Date())
      .split('/');
    return new Date(+a, +m - 1, +d);
  }

  /* ------------------------------------------------------------ filtros por tipo */
  function filtrar(el) {
    if (el._filtrado) return;
    const tipo = el.classList.contains('fecha') ? 'fecha' : el.classList.contains('hora') ? 'hora' : el.dataset.tipo;
    const re = PERMITIDOS[tipo];
    if (!re) return;
    el._filtrado = true;
    if (['entero', 'importe', 'decimal', 'fecha', 'hora', 'cuit'].includes(tipo))
      el.setAttribute('inputmode', tipo === 'importe' || tipo === 'decimal' ? 'decimal' : 'numeric');
    el.addEventListener('beforeinput', e => {
      if (!e.data || e.inputType.startsWith('delete')) return;
      if ([...e.data].some(c => !re.test(c))) e.preventDefault();
    });
    el.addEventListener('paste', e => {
      const t = (e.clipboardData || window.clipboardData).getData('text');
      const limpio = [...t].filter(c => re.test(c)).join('');
      if (limpio === t) return;
      e.preventDefault();
      document.execCommand('insertText', false, limpio);
    });
    if (tipo === 'cp')
      el.addEventListener('input', () => {
        const up = el.value.toUpperCase();
        if (up !== el.value) {
          const p = el.selectionStart;
          el.value = up;
          el.setSelectionRange(p, p);
        }
      });
  }

  /* ------------------------------------------------------------ desplegables */
  let abierto = null; // { sel, lista, boton }

  function cerrarLista() {
    if (!abierto) return;
    abierto.lista.remove();
    abierto.boton.setAttribute('aria-expanded', 'false');
    abierto.envoltura.classList.remove('abierto');
    abierto = null;
  }

  function sincronizar(sel) {
    const b = sel._boton;
    if (!b) return;
    const op = sel.options[sel.selectedIndex];
    b.querySelector('.sel-texto').textContent = op ? op.textContent : '';
    b.classList.toggle('vacio', !op || op.value === '');
    b.disabled = sel.disabled;
    b.title = op ? op.textContent : '';
  }

  function abrirLista(sel) {
    cerrarLista();
    const env = sel.parentElement;
    const boton = sel._boton;
    const lista = document.createElement('div');
    lista.className = 'sel-lista';
    lista.setAttribute('role', 'listbox');
    lista.id = 'sel-lista-' + Math.random().toString(36).slice(2);
    [...sel.options].forEach((op, i) => {
      if (op.hidden) return;
      const it = document.createElement('div');
      it.className = 'sel-op' + (i === sel.selectedIndex ? ' elegido' : '') + (op.value === '' ? ' vacio' : '');
      it.setAttribute('role', 'option');
      it.setAttribute('aria-selected', String(i === sel.selectedIndex));
      it.dataset.i = i;
      it.textContent = op.textContent;
      if (op.disabled) it.setAttribute('aria-disabled', 'true');
      lista.appendChild(it);
    });
    document.body.appendChild(lista);
    const r = boton.getBoundingClientRect();
    const alto = Math.min(lista.scrollHeight, 320);
    const abajo = window.innerHeight - r.bottom > alto + 12 || r.top < alto + 12;
    lista.style.left = Math.round(Math.min(r.left, window.innerWidth - Math.max(r.width, 220) - 8)) + 'px';
    lista.style.minWidth = Math.round(r.width) + 'px';
    lista.style.top = Math.round(abajo ? r.bottom + 6 : r.top - alto - 6) + 'px';
    lista.style.maxHeight = alto + 'px';
    boton.setAttribute('aria-expanded', 'true');
    boton.setAttribute('aria-controls', lista.id);
    env.classList.add('abierto');
    abierto = { sel, lista, boton, envoltura: env, activo: sel.selectedIndex };
    marcar(abierto.activo, true);
    lista.addEventListener('mousedown', e => e.preventDefault());
    lista.addEventListener('click', e => {
      const it = e.target.closest('.sel-op');
      if (!it || it.getAttribute('aria-disabled')) return;
      elegir(sel, +it.dataset.i);
    });
  }

  function marcar(i, centrar) {
    if (!abierto) return;
    abierto.lista.querySelectorAll('.sel-op').forEach(o => o.classList.toggle('activo', +o.dataset.i === i));
    const it = abierto.lista.querySelector(`.sel-op[data-i="${i}"]`);
    if (it) {
      abierto.activo = i;
      abierto.boton.setAttribute('aria-activedescendant', '');
      if (centrar) it.scrollIntoView({ block: 'nearest' });
    }
  }

  function elegir(sel, i) {
    cerrarLista();
    sel._boton.focus();
    if (i === sel.selectedIndex) return;
    sel.selectedIndex = i;
    sincronizar(sel);
    avisar(sel);
  }

  function mover(sel, paso) {
    const habilitados = [...sel.options].map((o, i) => (o.disabled || o.hidden ? -1 : i)).filter(i => i >= 0);
    const actual = abierto ? abierto.activo : sel.selectedIndex;
    const pos = Math.max(0, Math.min(habilitados.length - 1, habilitados.indexOf(actual) + paso));
    if (abierto) marcar(habilitados[pos], true);
    else if (habilitados[pos] !== sel.selectedIndex) {
      sel.selectedIndex = habilitados[pos];
      sincronizar(sel);
      avisar(sel);
    }
  }

  let busqueda = '',
    busquedaT = 0;
  function buscar(sel, letra) {
    clearTimeout(busquedaT);
    busqueda += letra.toLowerCase();
    busquedaT = setTimeout(() => (busqueda = ''), 700);
    const norm = s => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    const i = [...sel.options].findIndex(o => !o.disabled && norm(o.textContent).startsWith(norm(busqueda)));
    if (i < 0) return;
    if (abierto) marcar(i, true);
    else {
      sel.selectedIndex = i;
      sincronizar(sel);
      avisar(sel);
    }
  }

  function mejorarSelect(sel) {
    if (sel._boton || sel.multiple || sel.size > 1 || sel.closest('.sel-sin-mejorar')) return;
    const env = document.createElement('span');
    env.className =
      'sel' + (sel.classList.contains('select-sm') ? ' sm' : '') + (sel.classList.contains('grow') ? ' grow' : '');
    sel.parentNode.insertBefore(env, sel);
    env.appendChild(sel);
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'sel-boton';
    b.setAttribute('aria-haspopup', 'listbox');
    b.setAttribute('aria-expanded', 'false');
    const etiqueta = sel.id && document.querySelector(`label[for="${CSS.escape(sel.id)}"]`);
    if (etiqueta) b.setAttribute('aria-label', etiqueta.textContent.trim());
    else if (sel.getAttribute('aria-label')) b.setAttribute('aria-label', sel.getAttribute('aria-label'));
    b.innerHTML = '<span class="sel-texto"></span>';
    env.appendChild(b);
    sel._boton = b;
    sel.tabIndex = -1;
    sel.setAttribute('aria-hidden', 'true');
    sel.classList.add('sel-nativo');
    if (etiqueta) etiqueta.addEventListener('click', e => (e.preventDefault(), b.focus()));
    sincronizar(sel);
    sel.addEventListener('change', () => sincronizar(sel));
    sel.addEventListener('input', () => sincronizar(sel));
    b.addEventListener('click', () => (abierto && abierto.sel === sel ? cerrarLista() : abrirLista(sel)));
    b.addEventListener('keydown', e => {
      const k = e.key;
      if (k === 'ArrowDown' || k === 'ArrowUp') {
        e.preventDefault();
        if (!abierto && e.altKey) return abrirLista(sel);
        mover(sel, k === 'ArrowDown' ? 1 : -1);
      } else if (k === 'Enter' || k === ' ') {
        e.preventDefault();
        if (abierto) elegir(sel, abierto.activo);
        else abrirLista(sel);
      } else if (k === 'Escape' && abierto) {
        e.preventDefault();
        cerrarLista();
      } else if (k === 'Tab') cerrarLista();
      else if (k === 'Home' || k === 'End') {
        e.preventDefault();
        mover(sel, k === 'Home' ? -999 : 999);
      } else if (k.length === 1 && !e.ctrlKey && !e.metaKey) buscar(sel, k);
    });
  }

  /* El código a veces cambia el valor por programa (select.value = …), sin eventos:
     se intercepta para que el botón muestre siempre lo elegido. */
  ['value', 'selectedIndex'].forEach(prop => {
    const d = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, prop);
    Object.defineProperty(HTMLSelectElement.prototype, prop, {
      configurable: true,
      enumerable: d.enumerable,
      get() {
        return d.get.call(this);
      },
      set(v) {
        d.set.call(this, v);
        if (this._boton) sincronizar(this);
      },
    });
  });
  const dDis = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'disabled');
  Object.defineProperty(HTMLSelectElement.prototype, 'disabled', {
    configurable: true,
    get() {
      return dDis.get.call(this);
    },
    set(v) {
      dDis.set.call(this, v);
      if (this._boton) this._boton.disabled = !!v;
    },
  });

  /* ------------------------------------------------------------ fechas */
  let cal = null; // { inp, caja, mes }

  function cerrarCalendario() {
    if (!cal) return;
    cal.caja.remove();
    cal.inp.setAttribute('aria-expanded', 'false');
    cal = null;
  }

  function pintarCalendario() {
    const { caja, mes, inp } = cal;
    const elegido = leerFecha(inp.value);
    const hoy = hoyAR();
    const primero = new Date(mes.getFullYear(), mes.getMonth(), 1);
    const desde = new Date(primero);
    desde.setDate(1 - ((primero.getDay() + 6) % 7));
    let html =
      '<div class="cal-cab"><button type="button" class="cal-nav" data-m="-1" aria-label="Mes anterior">' +
      '<svg viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></svg></button>' +
      `<b>${MESES[mes.getMonth()]} ${mes.getFullYear()}</b>` +
      '<button type="button" class="cal-nav" data-m="1" aria-label="Mes siguiente"><svg viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg></button></div>' +
      '<div class="cal-grilla" role="grid">' +
      DIAS.map(d => `<span class="cal-dia-sem">${d}</span>`).join('');
    for (let i = 0; i < 42; i++) {
      const d = new Date(desde);
      d.setDate(desde.getDate() + i);
      const cls = ['cal-dia'];
      if (d.getMonth() !== mes.getMonth()) cls.push('fuera');
      if (+d === +hoy) cls.push('hoy');
      if (elegido && +d === +elegido) cls.push('elegido');
      html += `<button type="button" class="${cls.join(' ')}" data-f="${escribirFecha(d)}" tabindex="-1">${d.getDate()}</button>`;
    }
    html +=
      '</div><div class="cal-pie"><button type="button" class="cal-accion" data-a="borrar">Borrar</button>' +
      '<button type="button" class="cal-accion" data-a="hoy">Hoy</button></div>';
    caja.innerHTML = html;
  }

  function abrirCalendario(inp) {
    if (inp.readOnly || inp.disabled) return;
    cerrarCalendario();
    cerrarLista();
    const caja = document.createElement('div');
    caja.className = 'cal';
    caja.setAttribute('role', 'dialog');
    caja.setAttribute('aria-label', 'Elegir fecha');
    document.body.appendChild(caja);
    const base = leerFecha(inp.value) || hoyAR();
    cal = { inp, caja, mes: new Date(base.getFullYear(), base.getMonth(), 1) };
    pintarCalendario();
    const r = inp.getBoundingClientRect();
    const alto = caja.offsetHeight;
    caja.style.left = Math.round(Math.max(8, Math.min(r.left, window.innerWidth - caja.offsetWidth - 8))) + 'px';
    caja.style.top = Math.round(window.innerHeight - r.bottom > alto + 12 ? r.bottom + 6 : r.top - alto - 6) + 'px';
    inp.setAttribute('aria-expanded', 'true');
    caja.addEventListener('mousedown', e => e.preventDefault());
    caja.addEventListener('click', e => {
      const nav = e.target.closest('[data-m]');
      if (nav) {
        cal.mes.setMonth(cal.mes.getMonth() + +nav.dataset.m);
        return pintarCalendario();
      }
      const dia = e.target.closest('[data-f]');
      const acc = e.target.closest('[data-a]');
      if (!dia && !acc) return;
      inp.value = dia ? dia.dataset.f : acc.dataset.a === 'hoy' ? escribirFecha(hoyAR()) : '';
      inp.setCustomValidity('');
      avisar(inp);
      cerrarCalendario();
      inp.focus();
    });
  }

  function mejorarFecha(inp) {
    if (inp._fecha) return;
    inp._fecha = true;
    if (!inp.placeholder) inp.placeholder = 'dd/mm/aaaa';
    inp.maxLength = 10;
    inp.autocomplete = 'off';
    inp.setAttribute('aria-haspopup', 'dialog');
    filtrar(inp);
    const env = document.createElement('span');
    env.className = 'fecha-env' + (inp.classList.contains('grow') ? ' grow' : '');
    inp.parentNode.insertBefore(env, inp);
    env.appendChild(inp);
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'fecha-boton';
    b.tabIndex = -1;
    b.setAttribute('aria-label', 'Abrir calendario');
    b.innerHTML =
      '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>';
    env.appendChild(b);
    b.addEventListener('mousedown', e => e.preventDefault());
    b.addEventListener('click', () => (cal && cal.inp === inp ? cerrarCalendario() : abrirCalendario(inp)));
    inp.addEventListener('keydown', e => {
      if (e.key === 'ArrowDown' && e.altKey) {
        e.preventDefault();
        abrirCalendario(inp);
      } else if (e.key === 'Escape' && cal) cerrarCalendario();
    });
    /* Máscara: 15112026 → 15/11/2026; también acepta aaaa-mm-dd pegado. */
    inp.addEventListener('input', e => {
      if (e.inputType && e.inputType.startsWith('delete')) return;
      let v = inp.value;
      if (/^\d{4}-\d{2}-\d{2}$/.test(v)) v = v.slice(8, 10) + '/' + v.slice(5, 7) + '/' + v.slice(0, 4);
      else {
        const dig = v.replace(/\D/g, '').slice(0, 8);
        if (!v.includes('/') || /^\d+$/.test(v))
          v =
            dig.length > 4
              ? `${dig.slice(0, 2)}/${dig.slice(2, 4)}/${dig.slice(4)}`
              : dig.length > 2
                ? `${dig.slice(0, 2)}/${dig.slice(2)}`
                : dig;
      }
      if (v !== inp.value) inp.value = v;
      inp.setCustomValidity('');
    });
    inp.addEventListener('blur', () => {
      const v = inp.value.trim();
      const m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(v);
      if (m && leerFecha(v)) {
        const n = pad(m[1]) + '/' + pad(m[2]) + '/' + m[3];
        if (n !== inp.value) {
          inp.value = n;
          avisar(inp);
        }
      }
      inp.setCustomValidity(v && !leerFecha(inp.value) ? 'La fecha no es válida (dd/mm/aaaa)' : '');
    });
  }

  /* ------------------------------------------------------------ hora */
  function mejorarHora(inp) {
    if (inp._hora) return;
    inp._hora = true;
    if (!inp.placeholder) inp.placeholder = 'hh:mm';
    inp.maxLength = 5;
    filtrar(inp);
    inp.addEventListener('input', e => {
      if (e.inputType && e.inputType.startsWith('delete')) return;
      const dig = inp.value.replace(/\D/g, '').slice(0, 4);
      const v = dig.length > 2 ? dig.slice(0, 2) + ':' + dig.slice(2) : dig;
      if (v !== inp.value && !inp.value.includes(':')) inp.value = v;
    });
    inp.addEventListener('blur', () => {
      const m = /^(\d{1,2}):(\d{2})$/.exec(inp.value.trim());
      const ok = !inp.value.trim() || (m && +m[1] < 24 && +m[2] < 60);
      inp.setCustomValidity(ok ? '' : 'La hora no es válida (hh:mm)');
    });
  }

  /* ------------------------------------------------------------ etiquetas */
  let idAuto = 0;
  const CAMPO = 'input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select, textarea';
  function asociarEtiqueta(label) {
    if (label.htmlFor || label.querySelector('input, select, textarea')) return;
    let campo = label.nextElementSibling;
    if (campo && !campo.matches(CAMPO)) campo = campo.querySelector(CAMPO);
    if (!campo && label.parentElement) campo = label.parentElement.querySelector(CAMPO);
    if (!campo) return;
    if (!campo.id) campo.id = 'campo-' + ++idAuto;
    label.htmlFor = campo.id;
  }

  /* ------------------------------------------------------------ errores por campo */
  /** Elemento visible del campo (el botón propio de un <select> mejorado). */
  const visible = el => (el._boton ? el.closest('.sel') || el : el);
  function marcarError(el, texto) {
    if (!el) return;
    limpiarError(el);
    const ancla = visible(el);
    const msg = document.createElement('div');
    msg.className = 'error-campo';
    msg.id = (el.id || 'campo-' + ++idAuto) + '-error';
    if (!el.id) el.id = msg.id.replace(/-error$/, '');
    msg.textContent = texto;
    ancla.insertAdjacentElement('afterend', msg);
    el.setAttribute('aria-invalid', 'true');
    el.setAttribute('aria-describedby', msg.id);
    ancla.classList.add('con-error');
    el._error = msg;
    const limpiar = () => {
      limpiarError(el);
      el.removeEventListener('input', limpiar);
      el.removeEventListener('change', limpiar);
    };
    el.addEventListener('input', limpiar);
    el.addEventListener('change', limpiar);
  }
  function limpiarError(el) {
    if (!el || !el._error) return;
    el._error.remove();
    el._error = null;
    el.removeAttribute('aria-invalid');
    el.removeAttribute('aria-describedby');
    visible(el).classList.remove('con-error');
  }
  function limpiarErrores(raiz) {
    (raiz || document).querySelectorAll('[aria-invalid="true"]').forEach(limpiarError);
  }
  /** Lleva el foco al campo con error y lo muestra. */
  function enfocar(el) {
    if (!el) return;
    const v = visible(el);
    v.scrollIntoView({ block: 'center', behavior: 'smooth' });
    (el._boton || el).focus({ preventScroll: true });
  }
  /** Valida un formulario con las reglas HTML (required, pattern, maxlength…) sin globos nativos. */
  function validarFormulario(form) {
    limpiarErrores(form);
    const malos = [...form.querySelectorAll(CAMPO)].filter(c => !c.disabled && !c.checkValidity());
    malos.forEach(c => marcarError(c, c.validity.valueMissing ? 'Completá este dato' : c.validationMessage));
    if (malos.length) enfocar(malos[0]);
    return !malos.length;
  }
  function mejorarFormulario(form) {
    if (form._val) return;
    form._val = true;
    form.noValidate = true;
    form.addEventListener(
      'submit',
      e => {
        if (!validarFormulario(form)) {
          e.preventDefault();
          e.stopImmediatePropagation();
        }
      },
      true
    );
  }
  /* Un campo de fecha u hora que queda inválido al salir muestra el error en el campo. */
  document.addEventListener(
    'blur',
    e => {
      const c = e.target;
      if (c && c.matches && c.matches('input.fecha, input.hora') && c.validationMessage)
        marcarError(c, c.validationMessage);
    },
    true
  );

  /* ------------------------------------------------------------ arranque */
  function mejorar(raiz) {
    if (!raiz.querySelectorAll) return;
    const todos = sel => (raiz.matches && raiz.matches(sel) ? [raiz] : []).concat([...raiz.querySelectorAll(sel)]);
    todos('label').forEach(asociarEtiqueta); // antes de los desplegables: el botón toma el rótulo
    todos('select').forEach(mejorarSelect);
    todos('input.fecha').forEach(mejorarFecha);
    todos('input.hora').forEach(mejorarHora);
    todos('input[data-tipo]').forEach(filtrar);
    todos('form').forEach(mejorarFormulario);
  }
  function iniciar() {
    mejorar(document.body);
    new MutationObserver(ms =>
      ms.forEach(m =>
        m.addedNodes.forEach(
          n => n.nodeType === 1 && !n.classList.contains('sel-lista') && !n.classList.contains('cal') && mejorar(n)
        )
      )
    ).observe(document.body, { childList: true, subtree: true });
    document.addEventListener('mousedown', e => {
      if (abierto && !abierto.lista.contains(e.target) && !abierto.envoltura.contains(e.target)) cerrarLista();
      if (cal && !cal.caja.contains(e.target) && e.target !== cal.inp) cerrarCalendario();
    });
    window.addEventListener('resize', () => (cerrarLista(), cerrarCalendario()));
    document.addEventListener(
      'scroll',
      e => {
        if (abierto && !abierto.lista.contains(e.target)) cerrarLista();
        if (cal && !cal.caja.contains(e.target)) cerrarCalendario();
      },
      true
    );
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
  else iniciar();

  window.controles = {
    leerFecha,
    escribirFecha,
    hoyAR,
    sincronizar,
    marcarError,
    limpiarError,
    limpiarErrores,
    enfocar,
    validarFormulario,
  };
})();
