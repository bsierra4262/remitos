"""Pruebas del formulario: tipos de dato, fechas y calibración de fábrica."""
from playwright.sync_api import sync_playwright

from base import abrir, caso, ir_a, modo_documento

# Los 24 ajustes del pre-impreso, medidos sobre impresiones reales.
AJUSTES = {
    'fecha': (-3.5, 5), 'cliente.senores': (2, 3.5), 'cliente.razonSocial': (2, 3.5),
    'cliente.cuit': (2, 2.5), 'cliente.domicilio': (2, 2.5), 'cliente.localidad': (2, 2.5),
    'cliente.otrosDatos': (2, 2), 'cliente.telefono': (-2.5, 3), 'cliente.cp': (-2.5, 3),
    'cliente.mail': (-2.5, 3), 'cliente.nombre': (-2.5, 2.5), 'items': (-1, 1),
    'tipoDoc': (-1, -2), 'recibi.nombre': (2, -2.5), 'recibi.documentoNro': (2, -3),
    'recibi.sector': (2, -3), 'recibi.puesto': (2, -3), 'transporte.nombre': (2.5, -5.5),
    'transporte.vehiculo': (2.5, -5.5), 'transporte.chofer': (2.5, -5.5),
    'transporte.lugarEntrega': (2.5, -5.5), 'transporte.fecha': (-2, -4.5),
    'transporte.hora': (-2, -4.5), 'transporte.observaciones': (3, -5.5),
}


@caso("Los campos tienen el tipo de dato correcto, no todos texto libre")
def tipos_de_dato():
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            faltan = []
            # El vencimiento del C.A.I. y del CAE ya no se cargan a mano: los asigna la autorización.
            for vista, esperado in [("remito", {"date": 2, "time": 1, "email": 1, "tel": 1}),
                                    ("factura", {"date": 1}),
                                    ("recibo", {"date": 1}),
                                    ("nota", {"date": 1})]:
                ir_a(pg, vista)
                r = pg.evaluate("""() => {
                    const v = [...document.querySelectorAll('.view')]
                        .find(e => getComputedStyle(e).display !== 'none');
                    const ins = [...v.querySelectorAll('input')];
                    const cuenta = t => ins.filter(i => i.type === t).length;
                    return { date: cuenta('date'), time: cuenta('time'),
                             email: cuenta('email'), tel: cuenta('tel'),
                             numericos: ins.filter(i => i.inputMode === 'numeric').length };
                }""")
                for k, n in esperado.items():
                    if r.get(k, 0) < n:
                        faltan.append(f"{vista}.{k}={r.get(k)}<{n}")
                if r["numericos"] < 2:
                    faltan.append(f"{vista}.numericos={r['numericos']}")
            return not faltan, f"faltan={faltan}" if faltan else "todas las vistas tipadas"
        finally:
            b.close()


@caso("Las fechas se guardan en dd/mm/aaaa aunque el input use ISO")
def fechas_en_formato_fiscal():
    """El <input type=date> maneja aaaa-mm-dd y la RG 1415 exige dd/mm/aaaa:
    la conversión va en el borde del binding."""
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            ir_a(pg, "remito")
            pg.evaluate("""() => {
                const i = document.querySelector('[data-path="fecha"]');
                i.value = '2026-03-09';
                i.dispatchEvent(new Event('input', { bubbles: true }));
            }""")
            pg.wait_for_timeout(400)
            f = pg.evaluate("() => doc.fecha")
            return f == "09/03/2026", f"doc.fecha={f!r} (se esperaba 09/03/2026)"
        finally:
            b.close()


@caso("Un dato con formato inválido se marca, uno válido no")
def validacion_visual():
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            ir_a(pg, "remito")
            r = pg.evaluate("""() => {
                const m = document.querySelector('[data-path="cliente.mail"]');
                m.value = 'no-es-un-mail'; const malo = !m.checkValidity();
                m.value = 'compras@apn.gob.ar'; const bueno = m.checkValidity();
                m.value = '';
                return { malo, bueno };
            }""")
            return r["malo"] and r["bueno"], f"invalido_detectado={r['malo']} valido_aceptado={r['bueno']}"
        finally:
            b.close()


@caso("El CUIT arma los guiones solo mientras se tipea")
def cuit_autoformato():
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            ir_a(pg, "remito")
            pg.fill('[data-path="cliente.cuit"]', "")
            pg.type('[data-path="cliente.cuit"]', "30711409757", delay=12)
            pg.wait_for_timeout(350)
            v = pg.input_value('[data-path="cliente.cuit"]')
            return v == "30-71140975-7", f"quedó {v!r}"
        finally:
            b.close()


@caso("Los 24 ajustes del pre-impreso vienen de fábrica")
def ajustes_de_fabrica():
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            got = pg.evaluate("() => calib.adjust")
            mal = []
            for k, (dx, dy) in AJUSTES.items():
                g = got.get(k)
                if not g or abs(g.get("dx", 0) - dx) > 1e-6 or abs(g.get("dy", 0) - dy) > 1e-6:
                    mal.append((k, g))
            return not mal, f"discrepancias={mal}" if mal else f"{len(AJUSTES)}/24 correctos"
        finally:
            b.close()


@caso("El modo PDF no hereda los ajustes del pre-impreso")
def modos_independientes():
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            full = pg.evaluate("() => calib.adjustFull")
            return full == {}, f"adjustFull={full} (debe arrancar vacío)"
        finally:
            b.close()


@caso("La columna Cantidad se corre 2 mm sólo en pre-impreso")
def cantidad_solo_en_overlay():
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            ir_a(pg, "remito")
            # la app arranca vacía: hace falta un ítem con contenido para que haya fila
            pg.evaluate("() => { doc.items = [{ articulo:'1', descripcion:'Servicio', cantidad:'2' }];"
                        " renderItemsEditor(); renderPreview(); }")
            pg.wait_for_timeout(300)
            leer = lambda: pg.evaluate(
                "() => { const c = document.querySelector('.items-box .it-row .it-cell:last-child');"
                " return c ? getComputedStyle(c).transform : 'sin filas'; }")
            modo_documento(pg, "overlay")
            ov = leer()
            modo_documento(pg, "full")
            fu = leer()
            # 2 mm a 96 dpi = 7.559 px
            ok_ov = "matrix" in ov and "-7.5" in ov
            ok_fu = fu == "none"
            return ok_ov and ok_fu, f"overlay={ov} full={fu}"
        finally:
            b.close()


@caso("Se puede navegar por todas las vistas sin errores de JavaScript")
def navegacion_limpia():
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            for v in ["inicio", "remito", "factura", "recibo", "nota", "clientes", "config"]:
                ir_a(pg, v, 450)
            return not errs, f"errores={errs}" if errs else "7 vistas sin errores"
        finally:
            b.close()
