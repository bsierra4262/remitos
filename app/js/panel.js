/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Panel de administración de la API: estado de los documentos, talonarios de
 * C.A.I., claves de servicio y auditoría.
 *
 * La clave se conserva en sessionStorage: se pierde al cerrar la pestaña y no
 * queda guardada en el equipo.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */
(() => {
  'use strict';

  const $ = s => document.querySelector(s);
  const SK = 'remitos_panel_key';
  /* «2026-10-21 14:05:00» → «21/10/2026 14:05:00» */
  const fecha = iso =>
    /^\d{4}-\d{2}-\d{2}/.test(iso || '')
      ? iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4) + iso.slice(10)
      : iso || '';
  const esc = s =>
    String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');

  let clave = '',
    esMaestra = false,
    tab = 'estado',
    ultimaNueva = null;

  /* ---------------------------------------------------------------------------
     Caché de lecturas, solo en memoria y de vida corta: muestra al instante lo
     último que se vio mientras se pide la versión fresca, que siempre la reemplaza.
     Cualquier escritura la vacía por completo.
  --------------------------------------------------------------------------- */
  const cache = new Map();
  const CACHE_TTL = 12000;
  const cacheGet = url => {
    const e = cache.get(url);
    if (!e || Date.now() - e.t > CACHE_TTL) {
      cache.delete(url);
      return null;
    }
    return e.d;
  };
  const cacheSet = (url, d) => cache.set(url, { d, t: Date.now() });

  async function api(ruta, opts = {}) {
    const escritura = opts.method && opts.method !== 'GET';
    const headers = Object.assign({ 'X-API-Key': clave }, opts.body ? { 'Content-Type': 'application/json' } : {});
    let res, d;
    try {
      res = await fetch(ruta, Object.assign({ cache: 'no-store', credentials: 'omit' }, opts, { headers }));
    } catch (e) {
      return { status: 0, d: { ok: false, error: 'Sin conexión con el servidor' } };
    }
    try {
      d = await res.json();
    } catch (e) {
      d = { ok: false, error: 'Respuesta inesperada del servidor (HTTP ' + res.status + ')' };
    }
    if (escritura) cache.clear();
    return { status: res.status, d };
  }
  function avisar(msg, tipo = 'err') {
    $('#aviso').innerHTML = msg ? `<div class="${tipo}">${esc(msg)}</div>` : '';
  }

  /* Evita redibujar (y el parpadeo) si los datos no cambiaron desde el último refresco. */
  const ultimoRender = {};
  function sinCambios(clave_, d) {
    const s = JSON.stringify(d);
    if (ultimoRender[clave_] === s) return true;
    ultimoRender[clave_] = s;
    return false;
  }
  async function verStale(url, pintar) {
    const c = cacheGet(url);
    if (c) pintar(c);
    const { status, d } = await api(url);
    if (!d || !d.ok) return { status, d, cache: c };
    cacheSet(url, d);
    pintar(d);
    return { status, d };
  }

  /* -------------------------------- Estado -------------------------------- */
  async function verEstado() {
    const { d, cache: c } = await verStale('api/estado.php', pintarEstado);
    if ((!d || !d.ok) && !c) avisar((d && d.error) || 'No se pudo leer el estado');
  }
  function pintarEstado(d) {
    if (sinCambios('estado', d)) return;
    avisar('');
    const r = d.resumen || {},
      p = d.pendientes || [];
    let h = `<div class="stats">
      <div class="stat"><div class="v">${esc(r.remitos_totales ?? '—')}</div><div class="l">Remitos en el servidor</div></div>
      <div class="stat"><div class="v">${esc(r.remitos_de_hoy ?? '—')}</div><div class="l">Emitidos hoy</div></div>
      <div class="stat w"><div class="v">${esc(r.pendientes_facturar ?? '—')}</div><div class="l">Pendientes de facturar</div></div>
    </div><div class="panel"><h2>Pendientes de facturar</h2>`;
    if (!p.length) h += '<div class="empty">No hay remitos pendientes de facturar.</div>';
    else
      p.forEach(x => {
        const dias = x.dias == null ? '' : x.dias === 0 ? 'hoy' : x.dias + (x.dias === 1 ? ' día' : ' días');
        h += `<div class="row"><span class="num">Nº ${esc(x.numero)}</span>
        <span class="cli">${esc(x.cliente)}</span>
        <span class="fec">${esc(x.fecha)}${dias ? ' · ' + esc(dias) : ''}</span>
        <span class="pill">${esc(x.estado)}</span></div>`;
      });
    h += `</div><p class="foot">Actualizado: ${esc(d.generado || '')}</p>`;
    $('#cont').innerHTML = h;
  }

  /* -------------------------------- Claves -------------------------------- */
  function filaClave(c) {
    const usado = c.ultimo_uso ? c.ultimo_uso : 'sin uso';
    return `<div class="krow">
      <div>
        <div class="kname">${esc(c.nombre)}</div>
        <div class="kmeta">
          <span class="mono">${esc(c.prefijo)}</span>
          ${c.permisos.map(p => `<span class="pill perm">${esc(p)}</span>`).join('')}
          <span>· ${esc(c.usos)} ${c.usos === 1 ? 'uso' : 'usos'} · último: ${esc(c.ultimo_uso ? fecha(c.ultimo_uso) : usado)}</span>
        </div>
      </div>
      <span class="pill ${c.activa ? 'on' : 'off'}">${c.activa ? 'Activa' : 'Desactivada'}</span>
      <span class="kacc">
        <button class="btn sm sec" data-toggle="${esc(c.id)}" data-activa="${c.activa ? 0 : 1}">${c.activa ? 'Desactivar' : 'Activar'}</button>
        <button class="btn sm dang" data-borrar="${esc(c.id)}" data-nombre="${esc(c.nombre)}">Eliminar</button>
      </span>
    </div>`;
  }
  async function verClaves() {
    const { status, d, cache: c } = await verStale('api/claves.php?accion=listar', pintarClaves);
    if ((!d || !d.ok) && !c) {
      $('#cont').innerHTML = '';
      avisar(
        status === 403 ? 'Esta sección requiere la clave maestra.' : (d && d.error) || 'No se pudieron leer las claves'
      );
    }
  }
  function pintarClaves(d) {
    if (sinCambios('claves', { d, nueva: ultimaNueva ? ultimaNueva.clave : null })) return;
    avisar('');
    const r = d.resumen;
    $('#cont').innerHTML = `<div class="stats">
      <div class="stat"><div class="v">${esc(r.activas)}</div><div class="l">Claves activas</div></div>
      <div class="stat"><div class="v">${esc(r.inactivas)}</div><div class="l">Desactivadas</div></div>
      <div class="stat"><div class="v">${esc(r.usos)}</div><div class="l">Llamadas totales</div></div>
      <div class="stat d"><div class="v">${esc(r.rechazos_7d)}</div><div class="l">Accesos rechazados (7 días)</div></div>
    </div>
    <div class="panel">
      <h2>Claves de servicio <span class="hint">Cada clave se muestra una sola vez, al crearla</span></h2>
      <div class="alta">
        <div class="campos">
          <input type="text" id="nvNombre" placeholder="Nombre: Aplicación, Depósito, Contaduría…" maxlength="80" aria-label="Nombre de la clave">
          <button class="btn" id="btnCrear">Crear clave</button>
        </div>
        <div class="perms">
          <label><input type="checkbox" value="leer" checked> Leer: consultar remitos y estado</label>
          <label><input type="checkbox" value="crear"> Crear: dar de alta remitos y autorizar</label>
          <label><input type="checkbox" value="borrar"> Borrar: eliminar remitos</label>
        </div>
      </div>
      ${
        ultimaNueva
          ? `<div class="nueva">
        <b>Clave creada: ${esc(ultimaNueva.nombre)}</b><br>${esc(ultimaNueva.aviso)}
        <div class="cod"><code>${esc(ultimaNueva.clave)}</code><button class="btn sm" id="btnCopiar">Copiar</button></div>
      </div>`
          : ''
      }
      ${d.claves.length ? d.claves.map(filaClave).join('') : '<div class="empty">Todavía no hay claves de servicio.</div>'}
    </div>`;

    $('#btnCrear').addEventListener('click', crearClave);
    $('#nvNombre').addEventListener('keydown', e => {
      if (e.key === 'Enter') crearClave();
    });
    const cp = $('#btnCopiar');
    if (cp)
      cp.addEventListener('click', async () => {
        try {
          await navigator.clipboard.writeText(ultimaNueva.clave);
          cp.textContent = 'Copiada';
        } catch (e) {
          cp.textContent = 'Copiala manualmente';
        }
      });
    $('#cont')
      .querySelectorAll('[data-toggle]')
      .forEach(b =>
        b.addEventListener('click', async () => {
          b.disabled = true;
          const { d: r2 } = await api('api/claves.php', {
            method: 'POST',
            body: JSON.stringify({ id: +b.dataset.toggle, activa: +b.dataset.activa === 1 }),
          });
          if (!r2.ok) avisar(r2.error);
          ultimaNueva = null;
          verClaves();
        })
      );
    $('#cont')
      .querySelectorAll('[data-borrar]')
      .forEach(b =>
        b.addEventListener('click', async () => {
          if (!confirm('¿Eliminar la clave «' + b.dataset.nombre + '»?\n\nQuien la use pierde el acceso en el acto.'))
            return;
          b.disabled = true;
          const { d: r2 } = await api('api/claves.php?id=' + encodeURIComponent(b.dataset.borrar), {
            method: 'DELETE',
          });
          if (!r2.ok) avisar(r2.error);
          ultimaNueva = null;
          verClaves();
        })
      );
  }
  async function crearClave() {
    const nombre = $('#nvNombre').value.trim();
    const permisos = [...document.querySelectorAll('.perms input:checked')].map(i => i.value);
    if (!nombre) return avisar('Indicá un nombre para identificar la clave');
    if (!permisos.length) return avisar('Elegí al menos un permiso');
    const { d } = await api('api/claves.php', { method: 'POST', body: JSON.stringify({ nombre, permisos }) });
    if (!d.ok) return avisar(d.error || 'No se pudo crear la clave');
    ultimaNueva = d;
    verClaves();
  }

  /* ------------------------------- Actividad ------------------------------ */
  let logCat = '',
    logPag = 1;
  const CATS = [
    { k: '', et: 'Todo' },
    { k: 'remito', et: 'Remitos' },
    { k: 'comprobante', et: 'Comprobantes' },
    { k: 'cai', et: 'C.A.I.' },
    { k: 'arca', et: 'ARCA' },
    { k: 'clave', et: 'Claves' },
    { k: 'acceso', et: 'Accesos' },
    { k: 'error', et: 'Errores' },
  ];
  const CAT_COLOR = {
    remito: '#663399',
    comprobante: '#522980',
    cai: '#8a6d00',
    arca: '#3d1f5c',
    clave: '#9f88b1',
    acceso: '#6b6f7b',
    error: '#c0392b',
    sistema: '#6b6f7b',
  };
  async function verRegistro() {
    const q =
      `api/claves.php?accion=registro&por_pagina=25&pagina=${logPag}` +
      (logCat ? `&categoria=${encodeURIComponent(logCat)}` : '');
    const { status, d, cache: c } = await verStale(q, pintarRegistro);
    if ((!d || !d.ok) && !c) {
      $('#cont').innerHTML = '';
      avisar(
        status === 403
          ? 'La actividad solo se ve con la clave maestra.'
          : (d && d.error) || 'No se pudo leer la actividad'
      );
    }
  }
  function pintarRegistro(d) {
    if (sinCambios('registro', { d, cat: logCat, pag: logPag })) return;
    avisar('');
    const pc = d.por_categoria || {};
    const cuenta = k =>
      k === '' ? Object.values(pc).reduce((a, b) => a + b, 0) : k === 'error' ? d.errores || 0 : pc[k] || 0;
    let h =
      '<div class="folders">' +
      CATS.map(
        c =>
          `<button class="folder ${logCat === c.k ? 'sel' : ''}" data-cat="${c.k}">${esc(c.et)} <b>${cuenta(c.k)}</b></button>`
      ).join('') +
      '</div>';
    const titulo = logCat ? ' · ' + esc(CATS.find(c => c.k === logCat).et) : '';
    h += `<div class="panel"><h2>Actividad${titulo} <span class="hint">${esc(d.total)} ${d.total === 1 ? 'evento' : 'eventos'}</span></h2>`;
    const m = d.movimientos || [];
    if (!m.length) h += '<div class="empty">No hay actividad en esta categoría.</div>';
    else
      m.forEach(x => {
        const err = x.nivel === 'error';
        const col = CAT_COLOR[x.categoria] || '#6b6f7b';
        h += `<div class="ev ${err ? 'ev-err' : ''}">
        <span class="ev-cat" style="--c:${col}">${esc(x.categoria)}</span>
        <div class="ev-main">
          <div class="ev-acc">${esc(x.accion)}</div>
          ${x.detalle ? `<div class="ev-det">${esc(x.detalle)}</div>` : ''}
          <div class="ev-meta"><span class="ev-org">${x.origen === 'app' ? 'Aplicación' : 'API'}</span>
            ${x.clave ? '· ' + esc(x.clave) : ''}${x.ip ? ' · ' + esc(x.ip) : ''}</div>
        </div>
        <div class="ev-right"><span class="est ${err ? 'no' : ''}">${esc(x.estado)}</span><span class="fec">${esc(fecha(x.fecha))}</span></div>
      </div>`;
      });
    h += '</div>';
    if (d.paginas > 1) {
      h += `<div class="pager">
        <button class="btn sm sec" data-pag="${logPag - 1}" ${logPag <= 1 ? 'disabled' : ''}>← Anteriores</button>
        <span class="pg">Página ${esc(d.pagina)} de ${esc(d.paginas)}</span>
        <button class="btn sm sec" data-pag="${logPag + 1}" ${logPag >= d.paginas ? 'disabled' : ''}>Siguientes →</button>
      </div>`;
    }
    $('#cont').innerHTML = h;
    $('#cont')
      .querySelectorAll('[data-cat]')
      .forEach(b =>
        b.addEventListener('click', () => {
          logCat = b.dataset.cat;
          logPag = 1;
          verRegistro();
        })
      );
    $('#cont')
      .querySelectorAll('[data-pag]')
      .forEach(b =>
        b.addEventListener('click', () => {
          logPag = Math.max(1, +b.dataset.pag);
          verRegistro();
        })
      );
  }

  /* -------------------------------- C.A.I. -------------------------------- */
  const pad = (n, l) => String(n).padStart(l, '0');
  const semaforo = t => (t.vencido || t.agotado ? 'r' : t.por_vencer || t.pocos_numeros ? 'a' : 'v');
  function filaTalonario(t) {
    const total = t.numero_hasta - t.numero_desde + 1;
    const usados = total - t.restantes;
    const pct = total ? Math.round((usados / total) * 100) : 0;
    const rango = `${pad(t.punto_venta, 5)}-${pad(t.numero_desde, 8)} … ${pad(t.numero_hasta, 8)}`;
    const alertas = [];
    if (t.vencido) alertas.push('<span class="pill bad">C.A.I. vencido</span>');
    else if (t.por_vencer) alertas.push(`<span class="pill">vence en ${esc(t.dias_para_vto)} días</span>`);
    if (t.agotado) alertas.push('<span class="pill bad">agotado</span>');
    else if (t.pocos_numeros) alertas.push(`<span class="pill">quedan ${esc(t.restantes)}</span>`);
    return `<div class="trow">
      <div>
        <div class="tname"><span class="sem ${semaforo(t)}"></span>Punto de venta ${pad(t.punto_venta, 5)} · Remito R</div>
        <div class="tmeta"><span class="mono">${rango}</span><span>C.A.I. ${esc(t.cai)}</span><span>· vence ${esc(fecha(t.cai_vencimiento))}</span>${alertas.join('')}</div>
        <div class="tmeta">Próximo: <b class="mono">${esc(t.proximo_formato)}</b> · ${esc(t.restantes)} de ${total} disponibles</div>
        <div class="barra"><i style="width:${pct}%"></i></div>
      </div>
      <span class="pill ${t.activa ? 'on' : 'off'}">${t.activa ? 'Activo' : 'Inactivo'}</span>
      <span class="kacc">
        <button class="btn sm sec" data-tactiva="${esc(t.id)}" data-a="${t.activa ? 0 : 1}">${t.activa ? 'Desactivar' : 'Activar'}</button>
        <button class="btn sm dang" data-tborrar="${esc(t.id)}">Eliminar</button>
      </span>
    </div>`;
  }
  async function verCai() {
    const { status, d, cache: c } = await verStale('api/talonarios.php?accion=listar', pintarCai);
    if ((!d || !d.ok) && !c) {
      $('#cont').innerHTML = '';
      avisar(
        status === 403
          ? 'La gestión del C.A.I. requiere la clave maestra.'
          : (d && d.error) || 'No se pudieron leer los talonarios'
      );
    }
  }
  function pintarCai(d) {
    if (sinCambios('cai', d)) return;
    avisar('');
    const r = d.resumen;
    $('#cont').innerHTML = `<div class="stats">
      <div class="stat"><div class="v">${esc(r.activos)}</div><div class="l">Talonarios activos</div></div>
      <div class="stat"><div class="v">${esc(r.remitos_autorizados)}</div><div class="l">Remitos autorizados</div></div>
      <div class="stat w"><div class="v">${esc(r.por_vencer)}</div><div class="l">Por vencer (30 días)</div></div>
      <div class="stat d"><div class="v">${esc(r.vencidos)}</div><div class="l">Vencidos</div></div>
    </div>
    <div class="panel">
      <h2>Cargar un C.A.I. <span class="hint">Se tramita en ARCA con Clave Fiscal, una vez por rango</span></h2>
      <div class="talta">
        <div class="grid">
          <div><label for="tPv">Punto de venta</label><input id="tPv" inputmode="numeric" value="1"></div>
          <div><label for="tDesde">Número desde</label><input id="tDesde" inputmode="numeric" placeholder="1"></div>
          <div><label for="tHasta">Número hasta</label><input id="tHasta" inputmode="numeric" placeholder="500"></div>
          <div><label for="tCai">C.A.I. (14 dígitos)</label><input id="tCai" inputmode="numeric" maxlength="14"></div>
          <div><label for="tVto">Vencimiento</label><input id="tVto" type="date"></div>
        </div>
        <div class="acc"><button class="btn" id="btnTalonario">Cargar talonario</button></div>
      </div>
      ${
        d.talonarios.length
          ? d.talonarios.map(filaTalonario).join('')
          : '<div class="empty">Todavía no hay talonarios. Cargá el primero para que los remitos se autoricen.</div>'
      }
    </div>`;
    $('#btnTalonario').addEventListener('click', cargarTalonario);
    $('#cont')
      .querySelectorAll('[data-tactiva]')
      .forEach(b =>
        b.addEventListener('click', async () => {
          b.disabled = true;
          const { d: r2 } = await api('api/talonarios.php', {
            method: 'POST',
            body: JSON.stringify({ id: +b.dataset.tactiva, activa: +b.dataset.a === 1 }),
          });
          if (!r2.ok) avisar(r2.error);
          verCai();
        })
      );
    $('#cont')
      .querySelectorAll('[data-tborrar]')
      .forEach(b =>
        b.addEventListener('click', async () => {
          if (!confirm('¿Eliminar este talonario?')) return;
          b.disabled = true;
          const { d: r2 } = await api('api/talonarios.php?id=' + encodeURIComponent(b.dataset.tborrar), {
            method: 'DELETE',
          });
          if (!r2.ok) avisar(r2.error);
          verCai();
        })
      );
  }
  async function cargarTalonario() {
    const cuerpo = {
      punto_venta: +$('#tPv').value || 0,
      numero_desde: +$('#tDesde').value || 0,
      numero_hasta: +$('#tHasta').value || 0,
      cai: $('#tCai').value.replace(/\D/g, ''),
      cai_vencimiento: $('#tVto').value,
    };
    const { d } = await api('api/talonarios.php', { method: 'POST', body: JSON.stringify(cuerpo) });
    if (!d.ok) return avisar(d.error || 'No se pudo cargar el talonario');
    verCai();
  }

  /* ------------------------------ Navegación ------------------------------ */
  function pintar() {
    if (tab === 'estado') return verEstado();
    if (tab === 'cai') return verCai();
    if (tab === 'claves') return verClaves();
    if (tab === 'registro') return verRegistro();
  }
  $('#tabs').addEventListener('click', e => {
    const b = e.target.closest('.tab');
    if (!b) return;
    tab = b.dataset.tab;
    document.querySelectorAll('#tabs .tab').forEach(t => t.setAttribute('aria-selected', String(t === b)));
    if (tab !== 'claves') ultimaNueva = null;
    if (tab === 'registro') {
      logCat = '';
      logPag = 1;
    }
    ultimoRender[tab] = null;
    pintar();
  });

  /* Refresco de la pestaña activa. No interrumpe mientras se escribe en un campo
     ni consume red con la pestaña del navegador en segundo plano. */
  let poll = null;
  function iniciarTiempoReal() {
    clearInterval(poll);
    poll = setInterval(() => {
      if (!clave || document.hidden) return;
      const ae = document.activeElement;
      if (ae && /^(INPUT|TEXTAREA|SELECT)$/.test(ae.tagName) && $('#cont').contains(ae)) return;
      pintar();
    }, 6000);
  }
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && clave) {
      cache.clear();
      pintar();
    }
  });

  function mostrarSesion(activa) {
    $('#login').hidden = activa;
    $('#top').hidden = !activa;
    $('#foot').hidden = !activa;
  }
  async function entrar(e) {
    if (e) e.preventDefault();
    clave = $('#apiKey').value.trim();
    if (!clave) return avisar('Ingresá la clave de la API');
    const [est, adm] = await Promise.all([api('api/estado.php'), api('api/claves.php?accion=listar')]);
    if (!est.d || !est.d.ok) {
      clave = '';
      sessionStorage.removeItem(SK);
      return avisar((est.d && est.d.error) || 'No se pudo validar la clave');
    }
    esMaestra = !!(adm.d && adm.d.ok);
    $('#modoBadge').textContent = esMaestra ? 'Administración' : 'Consulta';
    $('#apiKey').value = '';
    sessionStorage.setItem(SK, clave);
    mostrarSesion(true);
    ultimoRender[tab] = null;
    pintar();
    iniciarTiempoReal();
  }
  function salir() {
    clearInterval(poll);
    sessionStorage.removeItem(SK);
    cache.clear();
    clave = '';
    esMaestra = false;
    tab = 'estado';
    ultimaNueva = null;
    Object.keys(ultimoRender).forEach(k => delete ultimoRender[k]);
    document
      .querySelectorAll('#tabs .tab')
      .forEach(t => t.setAttribute('aria-selected', String(t.dataset.tab === 'estado')));
    $('#cont').innerHTML = '';
    avisar('');
    $('#modoBadge').textContent = 'Consulta';
    mostrarSesion(false);
  }

  $('#loginForm').addEventListener('submit', entrar);
  $('#btnSalir').addEventListener('click', salir);
  localStorage.removeItem(SK); // versiones anteriores la guardaban de forma persistente
  const guardada = sessionStorage.getItem(SK);
  if (guardada) {
    $('#apiKey').value = guardada;
    entrar();
  }
})();
