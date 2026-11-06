"""Pruebas de la interfaz: comportamiento de la aplicación en el navegador.

Cada caso reproduce un error corregido para que no vuelva a aparecer. Crean y
borran sus propios datos en el entorno de pruebas.
"""
import os
import tempfile
import uuid

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
        rid = pg.evaluate("""async () => { nuevo(); doc.fecha = '05/03/2026'; doc.cliente.razonSocial = 'CLIENTE FECHA';
                               formFromDoc(); await guardar({ silencioso: true }); return doc.id; }""")
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
        antes_rem = pg.evaluate("() => datos.remitosTotal")
        antes_comp = pg.evaluate("() => datos.comprobantesTotal")
        pg.fill("[data-cpath='cliente.razonSocial']", "CLIENTE FACTURA")
        pg.keyboard.press("Control+s")
        pg.wait_for_timeout(1200)
        comps = pg.evaluate("() => datos.comprobantesTotal")
        rem = pg.evaluate("() => datos.remitosTotal")
        return comps == antes_comp + 1 and rem == antes_rem, f"comprobantes {antes_comp}->{comps} remitos {antes_rem}->{rem}"
    return _con_pagina(prueba)


@caso("Un borrador no muestra número fiscal ni C.A.I. y se imprime SIN AUTORIZAR; el autorizado queda de solo lectura")
def numero_solo_autorizado():
    def prueba(pg, _):
        ir_a(pg, "remito")
        modo_documento(pg, "full")
        r = pg.evaluate("""() => {
            nuevo();
            doc.referencia = 7; doc.numero = '00000042'; doc.cai = '12345678901234';
            syncFiscalUI(); renderPreview();
            const textos = () => [...document.querySelectorAll('#template div')].map(e => e.textContent.trim());
            const borrador = { numero: document.querySelector('#remNumero').value, cai: document.querySelector('#remCai').value,
                               sello: textos().includes('SIN AUTORIZAR'), nro42: textos().some(t => t.includes('00000042')),
                               editable: !document.querySelector('[data-path="cliente.razonSocial"]').disabled };
            doc = Object.assign(emptyRemito(), { id: 'a1', estado: 'autorizado', numero: '00001-00000041',
                                                 cai: '12345678901234', caiVto: '31/12/2027', referencia: 7 });
            formFromDoc(); syncFiscalUI(); renderPreview();
            const autorizado = { numero: document.querySelector('#remNumero').value,
                                 sello: textos().includes('AUTORIZADO'),
                                 bloqueado: document.querySelector('[data-path="cliente.razonSocial"]').disabled,
                                 aviso: !document.querySelector('#remBloqueado').hidden };
            doc = emptyRemito(); formFromDoc(); syncFiscalUI();
            return { borrador, autorizado, liberado: !document.querySelector('[data-path="cliente.razonSocial"]').disabled };
        }""")
        b, a = r["borrador"], r["autorizado"]
        ok = (b["numero"] == "Borrador 7" and b["cai"] == "" and b["sello"] and not b["nro42"] and b["editable"]
              and a["numero"] == "00001-00000041" and a["sello"] and a["bloqueado"] and a["aviso"] and r["liberado"])
        return ok, f"{r}"
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


@caso("El CAE no se carga a mano; sin CAE se imprime SIN AUTORIZAR y con CAE lleva el QR fiscal")
def cae_por_comprobante():
    def prueba(pg, _):
        ir_a(pg, "factura")
        r = pg.evaluate("""() => {
            const real = clone(compDoc);
            compDoc.letra = 'A'; renderComprobanteEditor(); renderComprobante();
            const sin = { soloLectura: document.querySelector('#compCae').readOnly,
                          marca: (document.querySelector('#compPage .cmp-borrador') || {}).textContent || '',
                          qr: !!document.querySelector('#compPage .cmp-qr img') };
            Object.assign(compDoc, { estado: 'autorizado', cae: '74123456789012', caeVto: '14/11/2026', numero: '00000015',
                                     items: [{ descripcion: 'x', cantidad: '1', precio: '1.000' }] });
            renderComprobanteEditor(); renderComprobante();
            const con = { marca: !!document.querySelector('#compPage .cmp-borrador'),
                          qr: !!document.querySelector('#compPage .cmp-qr img'),
                          bloqueado: document.querySelector('[data-cpath="cliente.razonSocial"]').disabled };
            compDoc = real; renderComprobanteEditor(); renderComprobante();
            return { sin, con };
        }""")
        ok = (r["sin"]["soloLectura"] and r["sin"]["marca"] == "SIN AUTORIZAR" and not r["sin"]["qr"]
              and not r["con"]["marca"] and r["con"]["qr"] and r["con"]["bloqueado"])
        return ok, f"{r}"
    return _con_pagina(prueba)


@caso("Los importes en formato argentino dan el total correcto en la factura")
def importes_formato_argentino():
    def prueba(pg, _):
        ir_a(pg, "factura")
        total = pg.evaluate("""() => {
            const real = clone(compDoc);
            Object.assign(compDoc, { letra: 'A', alicuota: '21', items: [{ descripcion: 'x', cantidad: '2', precio: '1.234,56' }] });
            renderComprobante();
            const t = document.querySelector('#compPage .cmp-totes .row.total .n').textContent;
            compDoc = real; renderComprobante();
            return t;
        }""")
        return "2.987,64" in total, f"total={total!r}"
    return _con_pagina(prueba)


@caso("Guardar dos veces seguidas un comprobante crea uno solo")
def comprobante_sin_doble_guardado():
    def prueba(pg, _):
        ir_a(pg, "factura")
        r = pg.evaluate("""async () => {
            nuevoComprobante('factura');
            compDoc.letra = 'X';
            compDoc.cliente.razonSocial = 'DOBLE GUARDADO';
            compDoc.items = [{ descripcion: 'x', cantidad: '1', precio: '10' }];
            await Promise.all([guardarComprobante(), guardarComprobante()]);
            const lista = (await api('comprobantes.php?buscar=DOBLE%20GUARDADO')).comprobantes;
            for (const c of lista) await api('comprobantes.php?id=' + c.id, { method: 'DELETE' });
            return { creados: lista.length };
        }""")
        return r["creados"] == 1, f"{r}"
    return _con_pagina(prueba)


@caso("Una copia de seguridad inválida no cambia los datos")
def importar_invalido():
    def prueba(pg, _):
        antes = pg.evaluate("() => datos.remitosTotal")
        ruta = os.path.join(tempfile.gettempdir(), "copia-invalida.json")
        with open(ruta, "w", encoding="utf-8") as f:
            f.write('{"docs": "no es una lista"}')
        pg.set_input_files("#importFile", ruta)
        pg.wait_for_timeout(800)
        despues = pg.evaluate("() => datos.remitosTotal")
        aviso = pg.inner_text("#toast")
        return despues == antes and "formato" in aviso, f"remitos {antes}->{despues} aviso={aviso!r}"
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


@caso("Cargar el certificado desde la interfaz lo valida en el servidor, confirmado por el ingreso con Google")
def carga_certificado_interfaz():
    if not es_local():
        return True, "solo en el entorno local"
    from test_seguridad import _certificado
    cert, clave = _certificado()
    carpeta = tempfile.mkdtemp()
    rc, rk = os.path.join(carpeta, "prueba.crt"), os.path.join(carpeta, "prueba.key")
    open(rc, "w").write(cert)
    open(rk, "w").write(clave)

    def prueba(pg, errores):
        ir_a(pg, "config")
        pg.click(".cfg-tab[data-tab=api]")
        pg.fill("#apiCuit", "20-12345678-6")
        pg.fill("#apiPv", "1")
        pg.click("#apiSave")
        pg.wait_for_timeout(800)
        pg.click("#certUpload")
        pg.set_input_files("#certFile", rc)
        pg.set_input_files("#keyFile", rk)
        sin_clave = pg.locator("#certModal input[type=password]").count() == 1  # solo la frase opcional de la .key
        pg.click("#certSend")
        pg.wait_for_timeout(1500)
        estado = pg.inner_text("#certStatus")
        en_memoria = pg.evaluate("() => JSON.stringify(localStorage)")
        campos_vacios = pg.evaluate("() => !document.querySelector('#certFile').value && !document.querySelector('#keyFile').value")
        ok = "20-12345678-6" in estado and "BEGIN" not in en_memoria and campos_vacios and sin_clave and not errores
        return ok, f"estado={estado[:60]!r} campos_vacios={campos_vacios} sin_contraseña={sin_clave} errores={errores}"
    try:
        return _con_pagina(prueba)
    finally:
        pedir("/arca.php?ambiente=homologacion", "DELETE", clave_maestra())
        pedir("/ajustes.php", "POST", clave_maestra(), {"seccion": "emisor", "valores": {"ambiente": "homologacion", "cuit": "", "ptoVenta": "", "emision": False}})


@caso("Guardar dos veces el mismo remito lo actualiza en el servidor, sin duplicarlo")
def guardar_sin_duplicar():
    nombre = f"GUARDADO DOBLE {uuid.uuid4().hex[:8].upper()}"

    def prueba(pg, _):
        ir_a(pg, "remito")
        pg.evaluate("() => nuevo()")
        pg.fill("[data-path='cliente.razonSocial']", nombre)
        pg.click("#btnGuardar")
        pg.wait_for_timeout(1200)
        numero = pg.evaluate("() => doc.numero")
        pg.fill("[data-path='cliente.localidad']", "CABA")
        pg.click("#btnGuardar")
        pg.wait_for_timeout(1200)
        _, lista = pedir("/remitos.php?por_pagina=200", clave=clave_maestra())
        propios = [x for x in lista.get("remitos", []) if x["cliente"]["razonSocial"] == nombre]
        for x in propios:
            pedir(f"/remitos.php?id={x['id']}", "DELETE", clave_maestra())
        ok = len(propios) == 1 and propios[0]["cliente"]["localidad"] == "CABA" and propios[0]["numero"] == numero
        return ok, f"en servidor={len(propios)} numero={numero}"
    return _con_pagina(prueba)


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
