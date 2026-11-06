/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Capa de servidor: llamadas a la API, sesión de usuario, pantalla de ingreso (solo
 * con la cuenta de Google de Intellihelp),
 * datos compartidos (clientes, remitos, comprobantes y ajustes), administración
 * de usuarios y traspaso al servidor de los datos de versiones anteriores que
 * habían quedado guardados en el navegador.
 *
 * En el equipo solo quedan la calibración de la impresora, las preferencias de
 * pantalla y el borrador en curso de cada usuario.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */
'use strict';

/* ================================ API ================================ */
const sesion = { usuario: null, csrf: '' };

const datos = {
  clientes: [],
  remitos: [],
  remitosTotal: 0,
  comprobantes: [],
  comprobantesTotal: 0,
  ajustes: {
    emisor: { ambiente: 'homologacion', cuit: '', ptoVenta: '', emision: false },
    remito: { ptoVenta: '00001', copias: 2 },
  },
};

/** Error de la API con el código HTTP y la respuesta completa. */
class ErrorApi extends Error {
  constructor(mensaje, status, respuesta) {
    super(mensaje);
    this.status = status;
    this.respuesta = respuesta || {};
  }
}

/**
 * Llama a la API con la sesión del usuario. Devuelve la respuesta JSON o lanza
 * ErrorApi. Toda escritura lleva el token CSRF de la sesión.
 */
async function api(ruta, opciones = {}) {
  const metodo = (opciones.method || 'GET').toUpperCase();
  const headers = {};
  if (opciones.body !== undefined) headers['Content-Type'] = 'application/json';
  if (metodo !== 'GET') headers['X-CSRF-Token'] = sesion.csrf;
  let res;
  try {
    res = await fetch('api/' + ruta, {
      method: metodo,
      headers,
      body: opciones.body !== undefined ? JSON.stringify(opciones.body) : undefined,
      cache: 'no-store',
      credentials: 'same-origin',
    });
  } catch (e) {
    throw new ErrorApi('Sin conexión con el servidor', 0);
  }
  let j;
  try {
    j = await res.json();
  } catch (e) {
    j = { ok: false, error: 'Respuesta inesperada del servidor (HTTP ' + res.status + ')' };
  }
  if (res.status === 401 && j.sesion === false && !opciones.sinSesion) sesionVencida();
  if (!res.ok || !j.ok) throw new ErrorApi(j.error || 'Error del servidor (HTTP ' + res.status + ')', res.status, j);
  return j;
}

/** Muestra el error de una operación sin interrumpir la aplicación. */
function avisarError(e, prefijo) {
  if (e && e.status === 401) return; // la pantalla de ingreso ya lo explica
  toast((prefijo ? prefijo + ': ' : '') + (e && e.message ? e.message : 'Error inesperado'), 4200, 'err');
}

const esAdmin = () => !!(sesion.usuario && sesion.usuario.rol === 'admin');

/* ============================ Datos compartidos ============================ */
/** Trae todas las páginas de un listado (de a 200), para que los listados, el
    paginador y las búsquedas trabajen siempre sobre el total y no sobre un recorte. */
async function todasLasPaginas(ruta, campo) {
  const primera = await api(ruta + '?por_pagina=200&pagina=1');
  const todos = [...primera[campo]];
  const paginas = Math.min(primera.paginas || 1, 100);
  for (let p = 2; p <= paginas; p++) todos.push(...(await api(ruta + '?por_pagina=200&pagina=' + p))[campo]);
  return { lista: todos, total: primera.total };
}
function aplicarClientes(c) {
  datos.clientes = c.clientes;
  if (c.completo === false)
    toast(
      'La agenda tiene ' + c.total + ' clientes: se muestran los primeros ' + c.clientes.length + '. Usá la búsqueda.',
      5000,
      'err'
    );
}
async function cargarDatos() {
  const [c, r, k, a] = await Promise.all([
    api('clientes.php'),
    todasLasPaginas('remitos.php', 'remitos'),
    todasLasPaginas('comprobantes.php', 'comprobantes'),
    api('ajustes.php'),
  ]);
  aplicarClientes(c);
  datos.remitos = r.lista;
  datos.remitosTotal = r.total;
  datos.comprobantes = k.lista;
  datos.comprobantesTotal = k.total;
  datos.ajustes = a.ajustes;
}
async function refrescarClientes() {
  aplicarClientes(await api('clientes.php'));
}
async function refrescarRemitos() {
  const r = await todasLasPaginas('remitos.php', 'remitos');
  datos.remitos = r.lista;
  datos.remitosTotal = r.total;
}
async function refrescarComprobantes() {
  const k = await todasLasPaginas('comprobantes.php', 'comprobantes');
  datos.comprobantes = k.lista;
  datos.comprobantesTotal = k.total;
}

/** Reemplaza (o agrega al principio) un documento en una lista en memoria. */
function actualizarEnLista(lista, item) {
  const i = lista.findIndex(x => x.id === item.id);
  if (i >= 0) lista.splice(i, 1);
  lista.unshift(item);
}

/* ================================ Sesión ================================ */
/** Un borrador local sin tocar por más de 7 días se descarta (contiene datos de clientes). */
const BORRADOR_DIAS = 7;
function borradorVencido(clave) {
  const b = load(clave, null);
  if (!b) return false;
  const fechas = b.updated ? [b.updated] : Object.values(b).map(x => (x && x.updated) || 0);
  return !fechas.some(t => Date.now() - t < BORRADOR_DIAS * 864e5);
}
/** Claves locales por usuario: en un equipo compartido cada uno ve su propio borrador. */
function aplicarClavesLocales(uid) {
  const propias = { draft: 'remitos_draft_u' + uid, compDraft: 'remitos_compdraft_u' + uid };
  // El borrador de la versión anterior (sin usuario) pasa al primero que ingresa en este equipo.
  if (localStorage.getItem('remitos_draft') && !localStorage.getItem(propias.draft)) {
    localStorage.setItem(propias.draft, localStorage.getItem('remitos_draft'));
  }
  if (localStorage.getItem('remitos_compdraft') && !localStorage.getItem(propias.compDraft)) {
    localStorage.setItem(propias.compDraft, localStorage.getItem('remitos_compdraft'));
  }
  localStorage.removeItem('remitos_draft');
  localStorage.removeItem('remitos_compdraft');
  LS.draft = propias.draft;
  LS.compDraft = propias.compDraft;
  [LS.draft, LS.compDraft].forEach(k => borradorVencido(k) && localStorage.removeItem(k));
}

async function iniciarSesionApp() {
  try {
    const j = await api('sesion.php', { sinSesion: true });
    const aviso = avisoIngresoGoogle();
    if (!j.usuario) {
      $('#ingresoGoogleFila').hidden = !j.google;
      return mostrarAcceso(
        j.google
          ? aviso
          : 'El ingreso con Google todavía no está configurado en el servidor. Avisale a un administrador.'
      );
    }
    aplicarSesion(j);
    if (aviso) toast(aviso, 3200, true);
  } catch (e) {
    mostrarAcceso(e.status === 0 ? 'Sin conexión con el servidor. Revisá la conexión y volvé a intentar.' : e.message);
  }
}

/* Resultado de la vuelta desde Google (?ingreso=…): se muestra una vez y se limpia de la URL. */
const AVISOS_GOOGLE = {
  google_no_configurado: 'El ingreso con Google todavía no está configurado.',
  google_cancelado: 'Se canceló el ingreso con Google.',
  google_vencido: 'El ingreso con Google venció o no se pudo verificar. Probá de nuevo.',
  google_error: 'Google no pudo confirmar el ingreso. Probá de nuevo en unos minutos.',
  google_dominio: 'Solo se puede ingresar con una cuenta de Intellihelp (@intellihelp.tech).',
  google_sin_usuario:
    'Esa cuenta no tiene usuario en el sistema o está desactivada. Pedile el alta a un administrador.',
  google_limitado: 'Demasiados intentos de ingreso con Google. Esperá unos minutos.',
  confirmado: 'Identidad confirmada con Google. Ya podés repetir la operación.',
};
function avisoIngresoGoogle() {
  const params = new URLSearchParams(location.search);
  const codigo = params.get('ingreso');
  if (!codigo) return '';
  params.delete('ingreso');
  history.replaceState(null, '', location.pathname + (params.toString() ? '?' + params : ''));
  return AVISOS_GOOGLE[codigo] || '';
}

/* Las operaciones sensibles valen dentro de los 15 minutos del ingreso con Google;
   después el servidor pide volver a confirmar la identidad con Google. */
const URL_CONFIRMAR_GOOGLE = 'api/google.php?accion=inicio&confirmar=1';
/** Detalle de un error para avisoCert: si hay que reconfirmar con Google, ofrece hacerlo. */
function detalleError(err) {
  const texto = ' ' + esc(err && err.message ? err.message : 'Error inesperado');
  return err && err.respuesta && err.respuesta.reconfirmar_google
    ? texto + ` <a class="btn sm google-confirmar" href="${URL_CONFIRMAR_GOOGLE}">Confirmar con Google</a>`
    : texto;
}

function aplicarSesion(j) {
  sesion.usuario = j.usuario;
  sesion.csrf = j.csrf;
  entrarApp();
}

async function entrarApp() {
  aplicarClavesLocales(sesion.usuario.id);
  ocultarAcceso();
  pintarUsuario();
  try {
    await cargarDatos();
  } catch (e) {
    return avisarError(e, 'No se pudieron cargar los datos');
  }
  await traspasarDatosLocales();
  appLista();
}

function sesionVencida() {
  if (!sesion.usuario) return;
  sesion.usuario = null;
  sesion.csrf = '';
  mostrarAcceso('La sesión venció. Ingresá de nuevo: el borrador en curso quedó guardado en este equipo.');
}

/* Al salir se borran del equipo los borradores del usuario (tienen datos de clientes).
   Si hay trabajo sin guardar, se pide confirmación antes de perderlo. */
async function cerrarSesion() {
  const pendiente = sesion.usuario && typeof dirty === 'function' && (doc.pendiente || dirty());
  if (
    pendiente &&
    !confirm('Hay un remito con cambios sin guardar. Si salís, se descarta de este equipo. ¿Salir igual?')
  )
    return;
  try {
    await api('sesion.php', { method: 'POST', body: { accion: 'salir' } });
  } catch (e) {
    /* aunque falle la red, la sesión local se descarta */
  }
  if (sesion.usuario) [LS.draft, LS.compDraft].forEach(k => localStorage.removeItem(k));
  location.replace(location.pathname);
}

/* ---------------------------- Pantalla de acceso ---------------------------- */
function mostrarAcceso(aviso) {
  $('#acceso').hidden = false;
  document.body.classList.add('sin-sesion');
  const el = $('#accesoAviso');
  el.textContent = aviso || '';
  el.hidden = !aviso;
  setTimeout(() => {
    if (!$('#ingresoGoogleFila').hidden) $('#ingresoGoogle').focus();
  }, 50);
}
function ocultarAcceso() {
  $('#acceso').hidden = true;
  document.body.classList.remove('sin-sesion');
}

/* ------------------------------ Mi cuenta ------------------------------ */
function pintarUsuario() {
  const u = sesion.usuario;
  $('#navAva').textContent = iniciales(u.nombre);
  $('#navUser').textContent = u.nombre;
  $('#navRol').textContent = u.rol === 'admin' ? 'Administrador' : 'Operador';
  document.body.classList.toggle('es-admin', u.rol === 'admin');
}
function abrirMiCuenta() {
  const u = sesion.usuario;
  $('#cuentaNombre').textContent = u.nombre;
  $('#cuentaEmail').textContent = u.email;
  $('#cuentaRol').textContent = u.rol === 'admin' ? 'Administrador' : 'Operador';
  openPanel($('#cuentaModal'), $('#backdrop6'));
}

/* ============================ Usuarios (admin) ============================ */
let usuarios = [];
let usuarioEditando = null;

async function renderUsuarios() {
  const box = $('#usuariosLista');
  if (!box || !esAdmin()) return;
  try {
    usuarios = (await api('usuarios.php')).usuarios;
  } catch (e) {
    return avisarError(e, 'No se pudieron leer los usuarios');
  }
  box.innerHTML = usuarios
    .map(u => {
      const estado = u.activo
        ? '<span class="doc-state auth">Activo</span>'
        : '<span class="doc-state draft">Desactivado</span>';
      const propio = u.id === sesion.usuario.id ? ' <span class="note">(vos)</span>' : '';
      return `<div class="user-row" data-id="${escA(String(u.id))}">
        <span class="avatar">${esc(iniciales(u.nombre))}</span>
        <div class="doc-main"><strong>${esc(u.nombre)}${propio}</strong><small>${esc(u.email)} · ${u.rol === 'admin' ? 'Administrador' : 'Operador'}</small></div>
        <div class="doc-meta">${u.ultimo_ingreso ? 'Último ingreso ' + esc(fmtFechaHora(u.ultimo_ingreso)) : 'Todavía no ingresó'}</div>
        ${estado}
        <button class="btn sm" data-editar="${escA(String(u.id))}">Editar</button>
      </div>`;
    })
    .join('');
  box
    .querySelectorAll('[data-editar]')
    .forEach(b => b.addEventListener('click', () => abrirUsuario(+b.dataset.editar)));
}

function abrirUsuario(id) {
  usuarioEditando = id ? usuarios.find(u => u.id === id) || null : null;
  const u = usuarioEditando;
  $('#usuarioTitulo').textContent = u ? 'Editar usuario' : 'Nuevo usuario';
  $('#usrNombre').value = u ? u.nombre : '';
  $('#usrEmail').value = u ? u.email : '';
  $('#usrEmail').readOnly = !!u;
  $('#usrRol').value = u ? u.rol : 'operador';
  $('#usrActivo').checked = u ? u.activo : true;
  $('#usrActivoFila').hidden = !u;
  $('#usrAcciones').hidden = !u;
  ocultar('#usuarioAlert');
  openPanel($('#usuarioModal'), $('#backdrop7'));
}

async function guardarUsuario(e) {
  e.preventDefault();
  const u = usuarioEditando;
  const body = u
    ? {
        accion: 'editar',
        id: u.id,
        nombre: $('#usrNombre').value,
        rol: $('#usrRol').value,
        activo: $('#usrActivo').checked,
      }
    : { accion: 'crear', nombre: $('#usrNombre').value, email: $('#usrEmail').value, rol: $('#usrRol').value };
  try {
    const j = await api('usuarios.php', { method: 'POST', body });
    await renderUsuarios();
    closePanels();
    toast(u ? 'Usuario actualizado' : j.aviso || 'Usuario creado', u ? 1600 : 3200, true);
  } catch (err) {
    avisoCert('err', 'No se pudo guardar el usuario', ' ' + esc(err.message), '#usuarioAlert');
  }
}

async function eliminarUsuario() {
  const u = usuarioEditando;
  if (!u || !confirm('¿Eliminar el usuario de ' + u.nombre + '? Sus documentos se conservan.')) return;
  try {
    await api('usuarios.php?id=' + encodeURIComponent(u.id), { method: 'DELETE' });
    closePanels();
    renderUsuarios();
    toast('Usuario eliminado', 1600);
  } catch (err) {
    avisoCert('err', 'No se pudo eliminar el usuario', ' ' + esc(err.message), '#usuarioAlert');
  }
}

/* ================= Traspaso de datos de versiones anteriores =================
   Antes, clientes, remitos y comprobantes se guardaban en el navegador de cada
   equipo. Al ingresar se ofrece subirlos al servidor para que los vea todo el
   equipo; recién cuando el servidor confirma se borran del navegador. */
async function traspasarDatosLocales() {
  const viejos = {
    remitos: load('remitos_docs', []),
    clientes: load('remitos_clientes', []),
    comprobantes: load('remitos_comprobantes', []),
  };
  const n = viejos.remitos.length + viejos.clientes.length + viejos.comprobantes.length;
  if (!n || sessionStorage.getItem('remitos_traspaso_omitido')) return;
  const detalle = [
    viejos.clientes.length && viejos.clientes.length + ' clientes',
    viejos.remitos.length && viejos.remitos.length + ' remitos',
    viejos.comprobantes.length && viejos.comprobantes.length + ' comprobantes',
  ]
    .filter(Boolean)
    .join(', ');
  if (
    !confirm(
      'En este navegador hay datos de la versión anterior (' +
        detalle +
        ').\n\n¿Subirlos al servidor para que los vea todo el equipo?'
    )
  ) {
    sessionStorage.setItem('remitos_traspaso_omitido', '1');
    return;
  }
  let fallas = 0;
  try {
    if (viejos.clientes.length) {
      await api('clientes.php', { method: 'POST', body: { accion: 'importar', clientes: viejos.clientes } });
      localStorage.removeItem('remitos_clientes');
    }
    /* Cada documento lleva su id_local: si el traspaso se corta, el reintento no
       duplica lo que ya subió. Solo se borra del navegador lo que el servidor
       confirmó; lo que falló queda para revisarlo y reintentarlo. */
    const subir = async (lista, ruta, clave, cuerpo) => {
      const quedan = [];
      for (const d of lista) {
        try {
          await api(ruta, { method: 'POST', body: cuerpo(d) });
        } catch (e) {
          if (e.status === 0) throw e;
          quedan.push(Object.assign({}, d, { error_traspaso: e.message }));
        }
      }
      if (quedan.length) save(clave, quedan);
      else localStorage.removeItem(clave);
      return quedan.length;
    };
    // El número fiscal y el CAE los asigna el servidor: los documentos suben como borradores.
    fallas += await subir(viejos.remitos, 'remitos.php', 'remitos_docs', d =>
      Object.assign({}, d, { id: undefined, id_local: 'local-' + d.id, numero: '', cai: '', caiVto: '' })
    );
    fallas += await subir(viejos.comprobantes, 'comprobantes.php', 'remitos_comprobantes', d =>
      Object.assign({}, d, { id: undefined, id_local: 'local-' + d.id, numero: '', cae: '', caeVto: '' })
    );
    localStorage.removeItem('remitos_fiscal');
    await cargarDatos();
    toast(
      fallas
        ? 'Datos subidos; ' + fallas + ' documentos no se pudieron subir y quedaron en este equipo para reintentar'
        : 'Datos subidos al servidor',
      4200,
      fallas ? 'err' : true
    );
  } catch (e) {
    avisarError(e, 'El traspaso quedó incompleto; se reintenta en el próximo ingreso');
  }
}

/* ================================ Eventos ================================ */
function wireServidor() {
  $('#btnSalir').addEventListener('click', cerrarSesion);
  $('#btnCuenta').addEventListener('click', abrirMiCuenta);
  $('#cuentaSalir').addEventListener('click', cerrarSesion);
  $('#cuentaClose').addEventListener('click', closePanels);
  $('#backdrop6').addEventListener('click', closePanels);
  $('#btnNuevoUsuario').addEventListener('click', () => abrirUsuario(null));
  $('#usuarioForm').addEventListener('submit', guardarUsuario);
  $('#usrEliminar').addEventListener('click', eliminarUsuario);
  $('#usuarioClose').addEventListener('click', closePanels);
  $('#usuarioCancel').addEventListener('click', closePanels);
  $('#backdrop7').addEventListener('click', closePanels);
}
