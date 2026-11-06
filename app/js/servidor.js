/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Capa de servidor: llamadas a la API, sesión de usuario, pantalla de ingreso,
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
  if (res.status === 403 && j.cambiar_clave) mostrarAcceso('cambio');
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
    pintarOpcionesIngreso(!!j.google);
    const aviso = avisoIngresoGoogle();
    if (!j.usuario) return mostrarAcceso(j.segundo_factor ? 'codigo' : 'ingreso', aviso);
    aplicarSesion(j);
    if (aviso) toast(aviso, 3200, true);
  } catch (e) {
    if (e.status === 0)
      return mostrarAcceso('ingreso', 'Sin conexión con el servidor. Revisá la conexión y volvé a intentar.');
    mostrarAcceso('ingreso');
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

/* Con Google habilitado, es la forma principal de ingresar; correo y contraseña quedan
   como alternativa. */
function pintarOpcionesIngreso(conGoogle) {
  $('#ingresoGoogleFila').hidden = !conGoogle;
  $('#ingresoClaveBloque').hidden = conGoogle;
  $('#ingresoSub').textContent = conGoogle ? 'Con tu cuenta de Google de Intellihelp.' : 'Con tu correo y contraseña.';
}
function mostrarIngresoConClave() {
  $('#ingresoClaveBloque').hidden = false;
  $('#ingresoConClave').hidden = true;
  $('#ingresoSub').textContent = 'Con tu correo y contraseña.';
  $('#ingresoEmail').focus();
}

/* Sesión abierta con Google: las operaciones sensibles no piden contraseña; pasados
   15 minutos del ingreso, el servidor pide volver a confirmar con Google. */
const URL_CONFIRMAR_GOOGLE = 'api/google.php?accion=inicio&confirmar=1';
const sesionGoogle = () => !!(sesion.usuario && sesion.usuario.ingreso === 'google');
/** Detalle de un error para avisoCert: si hay que reconfirmar con Google, ofrece hacerlo. */
function detalleError(err) {
  const texto = ' ' + esc(err && err.message ? err.message : 'Error inesperado');
  return err && err.respuesta && err.respuesta.reconfirmar_google
    ? texto + ` <a class="btn sm google-confirmar" href="${URL_CONFIRMAR_GOOGLE}">Confirmar con Google</a>`
    : texto;
}
function avisoIngresoGoogle() {
  const params = new URLSearchParams(location.search);
  const codigo = params.get('ingreso');
  if (!codigo) return '';
  params.delete('ingreso');
  history.replaceState(null, '', location.pathname + (params.toString() ? '?' + params : ''));
  return AVISOS_GOOGLE[codigo] || '';
}

function aplicarSesion(j) {
  sesion.usuario = j.usuario;
  sesion.csrf = j.csrf;
  if (j.usuario.debe_cambiar) return mostrarAcceso('cambio');
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
  mostrarAcceso('ingreso', 'La sesión venció. Ingresá de nuevo: el borrador en curso quedó guardado en este equipo.');
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
function mostrarAcceso(modo, aviso) {
  const panel = $('#acceso');
  panel.hidden = false;
  document.body.classList.add('sin-sesion');
  $('#formIngreso').hidden = modo !== 'ingreso';
  $('#formCodigo').hidden = modo !== 'codigo';
  $('#formCambio').hidden = modo !== 'cambio';
  const el = $('#accesoAviso');
  el.textContent = aviso || '';
  el.hidden = !aviso;
  setTimeout(() => {
    const foco =
      modo === 'cambio'
        ? $('#cambioActual')
        : modo === 'codigo'
          ? $('#ingresoCodigo')
          : $('#ingresoClaveBloque').hidden
            ? $('#ingresoGoogle')
            : $('#ingresoEmail');
    if (foco) foco.focus();
  }, 50);
}
function ocultarAcceso() {
  $('#acceso').hidden = true;
  document.body.classList.remove('sin-sesion');
  ['#ingresoClave', '#ingresoCodigo', '#cambioActual', '#cambioNueva', '#cambioRepetir'].forEach(
    id => ($(id).value = '')
  );
}
function avisoAcceso(texto) {
  const el = $('#accesoAviso');
  el.textContent = texto;
  el.hidden = !texto;
}

async function enviarIngreso(e) {
  e.preventDefault();
  const btn = $('#ingresoEnviar');
  const email = $('#ingresoEmail').value.trim();
  const clave = $('#ingresoClave').value;
  if (!email || !clave) return avisoAcceso('Ingresá el correo y la contraseña.');
  btn.disabled = true;
  try {
    const j = await api('sesion.php', { method: 'POST', body: { accion: 'ingresar', email, clave }, sinSesion: true });
    $('#ingresoClave').value = '';
    if (!j.usuario && j.segundo_factor) return mostrarAcceso('codigo');
    aplicarSesion(j);
  } catch (err) {
    avisoAcceso(err.message);
    $('#ingresoClave').select();
  } finally {
    btn.disabled = false;
  }
}

/** Segundo paso del ingreso: el código TOTP de la aplicación de autenticación. */
async function enviarCodigo(e) {
  e.preventDefault();
  const btn = $('#codigoEnviar');
  const codigo = $('#ingresoCodigo').value.replace(/\D/g, '');
  if (codigo.length !== 6) return avisoAcceso('El código tiene 6 dígitos.');
  btn.disabled = true;
  try {
    const j = await api('sesion.php', {
      method: 'POST',
      body: { accion: 'verificar_codigo', codigo },
      sinSesion: true,
    });
    aplicarSesion(j);
  } catch (err) {
    $('#ingresoCodigo').value = '';
    if (err.respuesta && err.respuesta.sesion === false) mostrarAcceso('ingreso', err.message);
    else avisoAcceso(err.message);
  } finally {
    btn.disabled = false;
  }
}

/** Cambio de contraseña: el obligatorio del primer ingreso y el de «Mi cuenta». */
async function cambiarClave(actual, nueva, repetir) {
  if (nueva !== repetir) throw new Error('Las contraseñas nuevas no coinciden');
  const j = await api('sesion.php', { method: 'POST', body: { accion: 'cambiar_clave', actual, nueva } });
  sesion.usuario = j.usuario;
  sesion.csrf = j.csrf;
  return j;
}
async function enviarCambio(e) {
  e.preventDefault();
  const btn = $('#cambioEnviar');
  btn.disabled = true;
  try {
    await cambiarClave($('#cambioActual').value, $('#cambioNueva').value, $('#cambioRepetir').value);
    entrarApp();
    toast('Contraseña actualizada', 1800, true);
  } catch (err) {
    avisoAcceso(err.message);
  } finally {
    btn.disabled = false;
  }
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
  ['#cuentaActual', '#cuentaNueva', '#cuentaRepetir'].forEach(id => ($(id).value = ''));
  const google = sesionGoogle();
  $('#cuentaActualFila').hidden = google;
  $('#cuentaActual').required = !google;
  $('#cuentaClaveGoogle').hidden = !google;
  $('#cuentaClaveTit').textContent = google ? 'Contraseña (opcional)' : 'Cambiar la contraseña';
  ocultar('#cuentaAlert');
  pintarSegundoFactor();
  openPanel($('#cuentaModal'), $('#backdrop6'));
}

/* ---------------------- Verificación en dos pasos (TOTP) ---------------------- */
function pintarSegundoFactor(enAlta = false) {
  const activo = !!sesion.usuario.segundo_factor;
  $('#tfaEstado').innerHTML = activo
    ? '<b>Activa.</b> Al ingresar se pide el código de tu aplicación de autenticación.'
    : 'Pide un código de una aplicación de autenticación (Google Authenticator, Microsoft Authenticator, 1Password…) además de la contraseña.';
  $('#tfaInicio').hidden = enAlta || sesionGoogle();
  $('#tfaAlta').hidden = !enAlta;
  $('#tfaCodigoFila').hidden = !(enAlta || activo);
  $('#tfaIniciar').hidden = activo || enAlta;
  $('#tfaConfirmar').hidden = !enAlta;
  $('#tfaDesactivar').hidden = !activo;
  ['#tfaClave', '#tfaCodigo'].forEach(id => ($(id).value = ''));
  if (!enAlta) $('#tfaQr').innerHTML = '';
  ocultar('#tfaAlert');
}
function actualizarSesion(j) {
  sesion.usuario = j.usuario;
  sesion.csrf = j.csrf;
}
async function iniciarSegundoFactor() {
  try {
    const j = await api('sesion.php', {
      method: 'POST',
      body: { accion: 'totp_iniciar', clave: $('#tfaClave').value },
    });
    pintarSegundoFactor(true);
    $('#tfaSecreto').textContent = j.secreto.replace(/(.{4})/g, '$1 ').trim();
    const q = qrcode(0, 'M');
    q.addData(j.uri);
    q.make();
    $('#tfaQr').innerHTML = `<img src="${q.createDataURL(5, 2)}" alt="Código QR para la aplicación de autenticación">`;
    $('#tfaCodigo').focus();
  } catch (err) {
    avisoCert('err', 'No se pudo activar', detalleError(err), '#tfaAlert');
  }
}
async function confirmarSegundoFactor() {
  try {
    actualizarSesion(
      await api('sesion.php', { method: 'POST', body: { accion: 'totp_activar', codigo: $('#tfaCodigo').value } })
    );
    pintarSegundoFactor();
    toast('Verificación en dos pasos activada. Se cerraron tus otras sesiones.', 3000, true);
  } catch (err) {
    avisoCert('err', 'El código no es correcto', ' ' + esc(err.message), '#tfaAlert');
  }
}
async function desactivarSegundoFactor() {
  try {
    actualizarSesion(
      await api('sesion.php', {
        method: 'POST',
        body: { accion: 'totp_desactivar', clave: $('#tfaClave').value, codigo: $('#tfaCodigo').value },
      })
    );
    pintarSegundoFactor();
    toast('Verificación en dos pasos desactivada', 2200, true);
  } catch (err) {
    avisoCert('err', 'No se pudo desactivar', detalleError(err), '#tfaAlert');
  }
}
async function guardarMiClave(e) {
  e.preventDefault();
  try {
    await cambiarClave($('#cuentaActual').value, $('#cuentaNueva').value, $('#cuentaRepetir').value);
    closePanels();
    toast('Contraseña actualizada', 1800, true);
  } catch (err) {
    avisoCert('err', 'No se pudo cambiar la contraseña', detalleError(err), '#cuentaAlert');
  }
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
      const estado = !u.activo
        ? '<span class="doc-state draft">Desactivado</span>'
        : u.bloqueado
          ? '<span class="doc-state draft">Ingreso frenado</span>'
          : u.debe_cambiar
            ? '<span class="doc-state saved">Contraseña temporal</span>'
            : `<span class="doc-state auth">Activo${u.segundo_factor ? ' · 2 pasos' : ''}</span>`;
      const propio = u.id === sesion.usuario.id ? ' <span class="note">(vos)</span>' : '';
      return `<div class="user-row" data-id="${escA(String(u.id))}">
        <span class="avatar">${esc(iniciales(u.nombre))}</span>
        <div class="doc-main"><strong>${esc(u.nombre)}${propio}</strong><small>${esc(u.email)} · ${u.rol === 'admin' ? 'Administrador' : 'Operador'}</small></div>
        <div class="doc-meta">${u.ultimo_ingreso ? 'Último ingreso ' + esc(fmtFechaHora(u.ultimo_ingreso)) : 'Sin ingresos'}</div>
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
  $('#usrDesbloquear').hidden = !(u && u.bloqueado);
  $('#usrQuitar2fa').hidden = !(u && u.segundo_factor);
  $('#usrTemporal').hidden = true;
  ocultar('#usuarioAlert');
  openPanel($('#usuarioModal'), $('#backdrop7'));
}

function mostrarTemporal(clave, aviso) {
  $('#usrTemporalClave').textContent = clave;
  $('#usrTemporalAviso').textContent = aviso;
  $('#usrTemporal').hidden = false;
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
    if (!u) {
      usuarioEditando = j.usuario;
      usuarios = usuarios.some(x => x.id === j.usuario.id) ? usuarios : [j.usuario, ...usuarios];
      $('#usrEmail').readOnly = true;
      $('#usrActivoFila').hidden = false;
      $('#usrAcciones').hidden = false;
      $('#usuarioTitulo').textContent = 'Editar usuario';
      mostrarTemporal(j.clave_temporal, j.aviso);
      toast('Usuario creado', 1800, true);
    } else {
      closePanels();
      toast('Usuario actualizado', 1600, true);
    }
  } catch (err) {
    avisoCert('err', 'No se pudo guardar el usuario', ' ' + esc(err.message), '#usuarioAlert');
  }
}

async function restablecerUsuario() {
  const u = usuarioEditando;
  if (!u || !confirm('¿Generar una contraseña temporal nueva para ' + u.nombre + '? La actual deja de funcionar.'))
    return;
  try {
    const j = await api('usuarios.php', { method: 'POST', body: { accion: 'restablecer', id: u.id } });
    mostrarTemporal(j.clave_temporal, j.aviso);
    renderUsuarios();
  } catch (err) {
    avisoCert('err', 'No se pudo restablecer la contraseña', ' ' + esc(err.message), '#usuarioAlert');
  }
}

async function accionUsuario(accion, pregunta, aviso) {
  const u = usuarioEditando;
  if (!u || !confirm(pregunta.replace('%s', u.nombre))) return;
  try {
    const j = await api('usuarios.php', { method: 'POST', body: { accion, id: u.id } });
    usuarioEditando = j.usuario;
    await renderUsuarios();
    abrirUsuario(u.id);
    toast(aviso, 2000, true);
  } catch (err) {
    avisoCert('err', 'No se pudo completar la acción', ' ' + esc(err.message), '#usuarioAlert');
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
  $('#formIngreso').addEventListener('submit', enviarIngreso);
  $('#ingresoConClave').addEventListener('click', mostrarIngresoConClave);
  $('#formCodigo').addEventListener('submit', enviarCodigo);
  $('#codigoVolver').addEventListener('click', cerrarSesion);
  $('#tfaIniciar').addEventListener('click', iniciarSegundoFactor);
  $('#tfaConfirmar').addEventListener('click', confirmarSegundoFactor);
  $('#tfaDesactivar').addEventListener('click', desactivarSegundoFactor);
  $('#usrDesbloquear').addEventListener('click', () =>
    accionUsuario('desbloquear', '¿Borrar los intentos de ingreso fallidos de %s?', 'Ingreso desbloqueado')
  );
  $('#usrQuitar2fa').addEventListener('click', () =>
    accionUsuario(
      'quitar_segundo_factor',
      '¿Quitar la verificación en dos pasos de %s? Hacelo solo si perdió el teléfono.',
      'Verificación en dos pasos quitada'
    )
  );
  $('#formCambio').addEventListener('submit', enviarCambio);
  $('#cambioSalir').addEventListener('click', cerrarSesion);
  $('#btnSalir').addEventListener('click', cerrarSesion);
  $('#btnCuenta').addEventListener('click', abrirMiCuenta);
  $('#cuentaForm').addEventListener('submit', guardarMiClave);
  $('#cuentaClose').addEventListener('click', closePanels);
  $('#backdrop6').addEventListener('click', closePanels);
  $('#btnNuevoUsuario').addEventListener('click', () => abrirUsuario(null));
  $('#usuarioForm').addEventListener('submit', guardarUsuario);
  $('#usrRestablecer').addEventListener('click', restablecerUsuario);
  $('#usrEliminar').addEventListener('click', eliminarUsuario);
  $('#usuarioClose').addEventListener('click', closePanels);
  $('#usuarioCancel').addEventListener('click', closePanels);
  $('#backdrop7').addEventListener('click', closePanels);
  $('#usrCopiar').addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText($('#usrTemporalClave').textContent);
      toast('Contraseña copiada', 1400, true);
    } catch (e) {
      toast('Copiala manualmente', 1600);
    }
  });
}
