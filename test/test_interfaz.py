"""Pruebas de la interfaz: comportamiento de la aplicación en el navegador.

Cada caso reproduce un error corregido para que no vuelva a aparecer. Trabajan
sobre localStorage; los que usan la API crean y borran sus propios datos.
"""
import os
import tempfile

from playwright.sync_api import sync_playwright

from base import abrir, caso, clave_maestra, es_local, ir_a, modo_documento, pedir


def _con_pagina(fn, ancho=1440, alto=900):
    with sync_playwright() as pw:
        b, pg, errores = abrir(pw, ancho=ancho, alto=alto)
        try:
            return fn(pg, errores)
        finally:
            b.close()


@caso("Reabrir un remito guardado conserva su fecha de emisión")
def reabrir_conserva_fecha():
    def prueba(pg, _):
        ir_a(pg, "remito")
        pg.evaluate("""() => { doc.fecha = '05/03/2026'; doc.cliente.razonSocial = 'CLIENTE FECHA';
                               formFromDoc(); guardar({ silencioso: true }); }""")
        rid = pg.evaluate("() => doc.id")
        pg.evaluate("() => nuevo()")
        ir_a(pg, "inicio")
        pg.click(f".doc-row[data-id='{rid}'] [data-open]")
        pg.wait_for_timeout(500)
        fecha = pg.evaluate("() => doc.fecha")
        pg.evaluate("() => window.dispatchEvent(new Event('focus'))")
        pg.wait_for_timeout(200)
        despues = pg.evaluate("() => doc.fecha")
        return fecha == despues == "05/03/2026", f"al abrir={fecha} tras foco={despues}"
    return _con_pagina(prueba)


@caso("Volver a la ventana no pisa una fecha cargada a mano")
def foco_no_pisa_fecha():
    def prueba(pg, _):
        ir_a(pg, "remito")
        pg.fill("#remFecha", "2026-01-15")
        pg.dispatch_event("#remFecha", "input")
        pg.evaluate("() => window.dispatchEvent(new Event('focus'))")
        pg.wait_for_timeout(200)
        f = pg.evaluate("() => doc.fecha")
        return f == "15/01/2026", f"fecha={f}"
    return _con_pagina(prueba)


@caso("Ctrl+S en una factura guarda la factura y no un remito")
def ctrl_s_por_vista():
    def prueba(pg, _):
        ir_a(pg, "factura")
        antes_rem = pg.evaluate("() => load(LS.docs, []).length")
        pg.fill("[data-cpath='cliente.razonSocial']", "CLIENTE FACTURA")
        pg.keyboard.press("Control+s")
        pg.wait_for_timeout(600)
        comps = pg.evaluate("() => load(LS.comp, []).length")
        rem = pg.evaluate("() => load(LS.docs, []).length")
        return comps == 1 and rem == antes_rem, f"comprobantes={comps} remitos {antes_rem}->{rem}"
    return _con_pagina(prueba)


@caso("Después de un remito autorizado el número sigue la correlación")
def numero_tras_autorizado():
    def prueba(pg, _):
        n = pg.evaluate("""() => { save(LS.docs, [{ id: 'a1', numero: '00001-00000041', cliente: {}, items: [] }]);
                                   return nextRemitoNumero(); }""")
        return n == "00000042", f"siguiente={n}"
    return _con_pagina(prueba)


@caso("«Nuevo» sobre un remito vacío no pide confirmación")
def nuevo_sin_confirmar():
    def prueba(pg, _):
        ir_a(pg, "remito")
        dialogos = []
        pg.on("dialog", lambda d: (dialogos.append(d.message), d.dismiss()))
        pg.click("#btnNuevo")
        pg.wait_for_timeout(300)
        return not dialogos, f"diálogos={dialogos}"
    return _con_pagina(prueba)


@caso("Ajustar un campo no modifica los valores de fábrica")
def ajuste_no_toca_fabrica():
    def prueba(pg, _):
        ir_a(pg, "remito")
        r = pg.evaluate("""() => { const antes = JSON.stringify(DEFAULT_CALIB.adjust);
            nudge('fecha', 'dx', 3);
            return { fabrica: JSON.stringify(DEFAULT_CALIB.adjust) === antes, actual: calib.adjust.fecha.dx }; }""")
        return r["fabrica"] and r["actual"] != -3.5, f"{r}"
    return _con_pagina(prueba)


@caso("El CAE de una factura no aparece en un recibo")
def cae_por_comprobante():
    def prueba(pg, _):
        ir_a(pg, "factura")
        pg.fill("#compCae", "12345678901234")
        pg.wait_for_timeout(500)
        ir_a(pg, "recibo")
        cae_recibo = pg.input_value("#compCae")
        borrador = pg.evaluate("() => !!document.querySelector('#compPage .cmp-borrador')")
        ir_a(pg, "factura")
        cae_factura = pg.input_value("#compCae")
        ok = cae_recibo == "" and borrador and cae_factura == "12345678901234"
        return ok, f"recibo={cae_recibo!r} borrador={borrador} factura={cae_factura!r}"
    return _con_pagina(prueba)


@caso("Una copia de seguridad inválida no borra los datos")
def importar_invalido():
    def prueba(pg, _):
        pg.evaluate("() => save(LS.docs, [{ id: 'x1', numero: '1', cliente: { razonSocial: 'A' }, items: [] }])")
        ruta = os.path.join(tempfile.gettempdir(), "copia-invalida.json")
        with open(ruta, "w", encoding="utf-8") as f:
            f.write('{"docs": "no es una lista"}')
        pg.set_input_files("#importFile", ruta)
        pg.wait_for_timeout(500)
        n = pg.evaluate("() => load(LS.docs, []).length")
        return n == 1, f"remitos tras importar={n}"
    return _con_pagina(prueba)


@caso("El certificado de ARCA nunca queda en el navegador")
def certificado_fuera_del_navegador():
    def prueba(pg, _):
        pg.evaluate("""() => localStorage.setItem('remitos_api', JSON.stringify({
            cert: '-----BEGIN CERTIFICATE-----x', key: '-----BEGIN PRIVATE KEY-----y', cuit: '1' }))""")
        pg.reload(wait_until="networkidle")
        pg.wait_for_timeout(600)
        crudo = pg.evaluate("() => JSON.stringify(localStorage)")
        return "BEGIN" not in crudo, "localStorage limpio" if "BEGIN" not in crudo else "quedó un PEM en localStorage"
    return _con_pagina(prueba)


@caso("Cargar el certificado desde la interfaz lo valida en el servidor")
def carga_certificado_interfaz():
    if not es_local():
        return True, "solo en el entorno local"
    from test_seguridad import _certificado
    cert, clave = _certificado()
    carpeta = tempfile.mkdtemp()
    rc, rk = os.path.join(carpeta, "prueba.crt"), os.path.join(carpeta, "prueba.key")
    open(rc, "w").write(cert)
    open(rk, "w").write(clave)
    s, d = pedir("/claves.php", "POST", clave_maestra(), {"nombre": "PRUEBA INTERFAZ", "permisos": ["leer", "crear"]})
    llave, kid = d.get("clave"), d.get("id")

    def prueba(pg, errores):
        ir_a(pg, "config")
        pg.click(".cfg-tab[data-tab=api]")
        pg.fill("#apiCuit", "20-12345678-6")
        pg.fill("#apiPv", "1")
        pg.fill("#apiPanelKey", llave)
        pg.click("#apiSave")
        pg.wait_for_timeout(700)
        pg.click("#certUpload")
        pg.set_input_files("#certFile", rc)
        pg.set_input_files("#keyFile", rk)
        pg.fill("#certMaster", clave_maestra())
        pg.click("#certSend")
        pg.wait_for_timeout(1500)
        estado = pg.inner_text("#certStatus")
        en_memoria = pg.evaluate("() => JSON.stringify(localStorage)")
        campos_vacios = pg.evaluate("() => !document.querySelector('#certMaster').value && !document.querySelector('#certFile').value")
        ok = "20-12345678-6" in estado and "BEGIN" not in en_memoria and campos_vacios and not errores
        return ok, f"estado={estado[:80]!r} campos_vacios={campos_vacios} errores={errores}"
    try:
        return _con_pagina(prueba)
    finally:
        pedir("/arca.php?ambiente=homologacion", "DELETE", clave_maestra())
        pedir(f"/claves.php?id={kid}", "DELETE", clave_maestra())


@caso("El respaldo en el servidor no duplica un remito guardado dos veces")
def respaldo_sin_duplicar():
    s, d = pedir("/claves.php", "POST", clave_maestra(), {"nombre": "PRUEBA RESPALDO", "permisos": ["leer", "crear", "borrar"]})
    llave, kid = d.get("clave"), d.get("id")

    def prueba(pg, _):
        pg.evaluate("(k) => { apiCfg.panelKey = k; save(LS.api, apiCfg); }", llave)
        ir_a(pg, "remito")
        pg.fill("[data-path='cliente.razonSocial']", "RESPALDO DOBLE S.A.")
        pg.click("#btnGuardar")
        pg.wait_for_timeout(1200)
        pg.fill("[data-path='cliente.localidad']", "CABA")
        pg.click("#btnGuardar")
        pg.wait_for_timeout(1200)
        _, lista = pedir("/remitos.php", clave=llave)
        propios = [x for x in lista.get("items", []) if x.get("cliente") == "RESPALDO DOBLE S.A."]
        for x in propios:
            pedir(f"/remitos.php?id={x['id']}", "DELETE", llave)
        cola = pg.evaluate("() => load(LS.outbox, []).length")
        return len(propios) == 1 and cola == 0, f"en servidor={len(propios)} pendientes={cola}"
    try:
        return _con_pagina(prueba)
    finally:
        pedir(f"/claves.php?id={kid}", "DELETE", clave_maestra())


@caso("Los paneles laterales cerrados no se ven ni proyectan sombra")
def paneles_cerrados_invisibles():
    def prueba(pg, _):
        vis = pg.evaluate("() => [...document.querySelectorAll('.calib')].map(e => getComputedStyle(e).visibility)")
        return all(v == "hidden" for v in vis), f"visibilidad={vis}"
    return _con_pagina(prueba)


@caso("En el celular ninguna vista tiene desplazamiento horizontal")
def movil_sin_scroll_horizontal():
    def prueba(pg, errores):
        malos = []
        for vista in ["inicio", "remito", "factura", "clientes", "config"]:
            pg.evaluate("(v) => showView(v)", vista)
            pg.wait_for_timeout(400)
            ancho = pg.evaluate("() => document.documentElement.scrollWidth")
            if ancho > 392:
                malos.append((vista, ancho))
        return not malos and not errores, f"desbordan={malos} errores={errores}"
    return _con_pagina(prueba, ancho=390, alto=844)


@caso("El panel de la API no deja la clave guardada en el equipo")
def panel_clave_en_sesion():
    import base as _b
    with sync_playwright() as pw:
        b = pw.chromium.launch()
        pg = b.new_page()
        try:
            pg.goto(_b.SITIO + "/panel.html", wait_until="networkidle")
            pg.fill("#apiKey", clave_maestra())
            pg.click("#btnVer")
            pg.wait_for_timeout(1200)
            local = pg.evaluate("() => localStorage.getItem('remitos_panel_key')")
            sesion = pg.evaluate("() => sessionStorage.getItem('remitos_panel_key')")
            dentro = pg.is_visible("#tabs")
            return local is None and sesion and dentro, f"local={local!r} sesion={bool(sesion)} dentro={dentro}"
        finally:
            b.close()


@caso("El documento completo se imprime en una sola hoja por ejemplar")
def una_hoja_por_ejemplar():
    def prueba(pg, _):
        ir_a(pg, "remito")
        modo_documento(pg, "full")
        pg.evaluate("() => { buildRemitoCopies(); document.querySelector('.app').classList.add('copies-on'); }")
        pg.emulate_media(media="print")
        altos = pg.evaluate("() => [...document.querySelectorAll('#printCopies .copy-page')].map(e => e.getBoundingClientRect().height)")
        hoja = pg.evaluate("() => curPaper().h * 96 / 25.4")
        ok = len(altos) == 2 and all(a <= hoja for a in altos)
        return ok, f"ejemplares={len(altos)} altos={[round(a) for a in altos]} hoja={round(hoja)}"
    return _con_pagina(prueba)
