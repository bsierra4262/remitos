/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Página pública de conformidad de recepción de un remito digital (RG 5678/2025). El
 * enlace trae un token en el fragmento (#…), que nunca viaja en la URL hacia el
 * servidor ni en el encabezado Referer: se envía solo en el cuerpo de la petición.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */
(() => {
  'use strict';

  const $ = s => document.querySelector(s);
  const token = location.hash.slice(1);
  const fecha = s => (s ? s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4) + ' ' + s.slice(11, 16) : '');

  async function api(cuerpo) {
    let res, d;
    try {
      res = await fetch('api/conformidad.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ token }, cuerpo)),
        cache: 'no-store',
        credentials: 'omit',
      });
      d = await res.json();
    } catch {
      return { ok: false, error: 'Sin conexión con el servidor. Revisá la conexión y volvé a intentar.' };
    }
    return d || { ok: false, error: 'Respuesta inesperada del servidor' };
  }

  function mostrarError(texto) {
    $('#cargando').hidden = true;
    $('#remito').hidden = true;
    $('#error').hidden = false;
    $('#errorTexto').textContent = texto;
  }

  function pintar(r) {
    $('#cargando').hidden = true;
    $('#remito').hidden = false;
    $('#rNumero').textContent = 'Nº ' + r.numero;
    $('#rFecha').textContent = r.fecha;
    $('#rDestinatario').textContent = [r.destinatario.razonSocial, r.destinatario.cuit].filter(Boolean).join(' · ');
    $('#rEntrega').textContent = r.lugar_entrega || r.destinatario.domicilio;
    $('#rEntregaFila').hidden = !(r.lugar_entrega || r.destinatario.domicilio);
    $('#rCai').textContent = r.cai + (r.cai_vto ? ' · vence ' + r.cai_vto : '');
    const filas = r.items.map(i => {
      const tr = document.createElement('tr');
      [i.articulo, i.descripcion, i.cantidad].forEach(v => {
        const td = document.createElement('td');
        td.textContent = v;
        tr.appendChild(td);
      });
      return tr;
    });
    $('#rItems').replaceChildren(...filas);
    $('#rObs').hidden = !r.observaciones;
    $('#rObs').textContent = r.observaciones ? 'Observaciones: ' + r.observaciones : '';
    const hecho = !!r.conformado;
    $('#formConformar').hidden = hecho;
    $('#conformado').hidden = !hecho;
    if (hecho) {
      $('#confTexto').textContent =
        'Confirmada el ' +
        fecha(r.conformado) +
        ' por ' +
        r.conformado_nombre +
        ' (documento ' +
        r.conformado_doc +
        ')' +
        (r.conformado_obs ? '. Observaciones: ' + r.conformado_obs : '') +
        '.';
    }
  }

  async function conformar(e) {
    e.preventDefault();
    const aviso = $('#cAviso');
    aviso.hidden = true;
    const btn = $('#cEnviar');
    btn.disabled = true;
    const d = await api({
      accion: 'conformar',
      nombre: $('#cNombre').value.trim(),
      documento: $('#cDoc').value.trim(),
      observaciones: $('#cObs').value.trim(),
      conforme: $('#cConforme').checked,
    });
    btn.disabled = false;
    if (d.ok || d.remito) return pintar(d.remito);
    aviso.textContent = d.error || 'No se pudo registrar la confirmación';
    aviso.hidden = false;
  }

  async function iniciar() {
    if (!/^[A-Za-z0-9_-]{43}$/.test(token))
      return mostrarError('El enlace no es válido. Pedile uno nuevo a quien te envió el remito.');
    const d = await api({ accion: 'ver' });
    if (!d.ok) return mostrarError(d.error || 'No se pudo abrir el remito');
    pintar(d.remito);
  }

  $('#formConformar').addEventListener('submit', conformar);
  // Otro enlace en la misma pestaña (cambia solo el fragmento): se carga ese remito.
  window.addEventListener('hashchange', () => location.reload());
  iniciar();
})();
