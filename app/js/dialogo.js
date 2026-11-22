/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Diálogo de confirmación propio, en lugar de window.confirm(): mismo diseño en todos
 * los navegadores, título y botones con el verbo de la acción, y la acción peligrosa
 * en rojo. Es un <dialog> modal: captura el foco, se cierra con Escape (equivale a
 * cancelar) y devuelve el foco al elemento que lo abrió.
 *
 *   if (!(await confirmar({ titulo: '¿Eliminar el remito?', mensaje: '…', aceptar: 'Eliminar', peligro: true }))) return;
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */
'use strict';

/**
 * @param {{titulo: string, mensaje?: string, aceptar?: string, cancelar?: string, peligro?: boolean}} o
 * @returns {Promise<boolean>} true si se confirmó
 */
function confirmar(o) {
  let d = document.getElementById('dlgConfirmar');
  if (!d) {
    d = document.createElement('dialog');
    d.id = 'dlgConfirmar';
    d.className = 'dlg';
    d.setAttribute('aria-labelledby', 'dlgConfirmarTitulo');
    d.setAttribute('aria-describedby', 'dlgConfirmarMensaje');
    d.innerHTML =
      '<form method="dialog" class="dlg-cuerpo">' +
      '<span class="dlg-icono" aria-hidden="true"></span>' +
      '<div class="dlg-texto"><h3 id="dlgConfirmarTitulo"></h3><p id="dlgConfirmarMensaje"></p></div>' +
      '<div class="dlg-acciones">' +
      '<button class="dlg-btn dlg-cancelar" value="no" id="dlgConfirmarNo"></button>' +
      '<button class="dlg-btn dlg-aceptar" value="si" id="dlgConfirmarSi"></button>' +
      '</div></form>';
    document.body.appendChild(d);
  }
  const previo = document.activeElement;
  d.classList.toggle('peligro', !!o.peligro);
  d.querySelector('#dlgConfirmarTitulo').textContent = o.titulo;
  const msg = d.querySelector('#dlgConfirmarMensaje');
  msg.textContent = o.mensaje || '';
  msg.hidden = !o.mensaje;
  const si = d.querySelector('#dlgConfirmarSi');
  si.textContent = o.aceptar || 'Aceptar';
  d.querySelector('#dlgConfirmarNo').textContent = o.cancelar || 'Cancelar';
  d.returnValue = '';
  return new Promise(resolve => {
    d.addEventListener(
      'close',
      () => {
        resolve(d.returnValue === 'si');
        if (previo && typeof previo.focus === 'function') previo.focus();
      },
      { once: true }
    );
    d.showModal();
    // Lo seguro por defecto: en una acción peligrosa el foco queda en «Cancelar».
    (o.peligro ? d.querySelector('#dlgConfirmarNo') : si).focus();
  });
}
