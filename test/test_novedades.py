"""Funciones de facturación profesional: IVA, bonificación, código y unidad por renglón;
tipo de documento del receptor; recibo vinculado a las facturas que cancela; envío por
correo; agenda en tabla; vista previa de la importación; calibración con valor exacto y
acciones de Inicio en el celular.

Los envíos por correo del entorno de pruebas no salen: el servidor los escribe como .eml
en la carpeta REMITOS_CORREO_DIR, y estas pruebas los leen de ahí.
"""
import glob
import os
import tempfile
import time

from playwright.sync_api import sync_playwright

import http.cookiejar
import json
import urllib.error
import urllib.request

from base import API, ADMIN_EMAIL, abrir, asegurar_admin, caso, clave_maestra, ingresar_con_google, ir_a, pedir

CORREO_DIR = os.environ.get("REMITOS_CORREO_DIR", "")


class _SinRedireccion(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class _Sesion:
    """Cliente con cookies y token CSRF, como el navegador (ingresa con el Google simulado)."""

    def __init__(self, email):
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), _SinRedireccion)
        self.csrf = ""
        ingresar_con_google(self.get, email)
        self.csrf = self.pedir("/sesion.php")[1].get("csrf", "")

    def get(self, url):
        try:
            with self.op.open(url, timeout=25) as r:
                return r.status, dict(r.headers)
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers)

    def pedir(self, ruta, metodo="GET", cuerpo=None):
        req = urllib.request.Request(API + ruta, method=metodo, data=json.dumps(cuerpo).encode() if cuerpo is not None else None)
        req.add_header("Content-Type", "application/json")
        if self.csrf:
            req.add_header("X-CSRF-Token", self.csrf)
        try:
            with self.op.open(req, timeout=25) as r:
                return r.status, json.loads(r.read().decode())
        except urllib.error.HTTPError as e:
            return e.code, json.loads(e.read().decode() or "{}")


def _guardar(cuerpo):
    return pedir("/comprobantes.php", "POST", clave_maestra(), cuerpo)


def _borrar(*ids):
    for i in ids:
        if i:
            pedir(f"/comprobantes.php?id={i}", "DELETE", clave_maestra())


def _factura_x(total="1000", cliente="COBRANZA PRUEBA SA", cuit="30-71140975-7"):
    """Factura X guardada (numerada por el sistema): se puede cobrar con un recibo."""
    s, d = _guardar({"tipo": "factura", "letra": "X", "cliente": {"razonSocial": cliente, "cuit": cuit, "condIVA": "Responsable Inscripto"},
                     "items": [{"descripcion": "Servicio", "cantidad": "1", "precio": total}]})
    return d.get("comprobante", {})


def _recibo(cancela, monto, cliente="COBRANZA PRUEBA SA"):
    return _guardar({"tipo": "recibo", "letra": "X", "monto": monto, "concepto": "Pago",
                     "cliente": {"razonSocial": cliente, "condIVA": "Responsable Inscripto"}, "cancela": cancela})


def _con_pagina(fn, ancho=1440, alto=900):
    with sync_playwright() as pw:
        b, pg, errores = abrir(pw, ancho=ancho, alto=alto)
        try:
            return fn(pg, errores)
        finally:
            b.close()


@caso("IVA por renglón y bonificación: el servidor calcula neto, IVA y total por alícuota")
def iva_por_renglon_servidor():
    s, d = _guardar({"tipo": "factura", "letra": "A", "cliente": {"razonSocial": "IVA MIXTO SA", "cuit": "30-71140975-7",
                                                                   "condIVA": "Responsable Inscripto"},
                     "items": [{"codigo": "A-1", "descripcion": "Equipo", "cantidad": "1", "unidad": "unidades", "precio": "1.000",
                                "alicuota": "21"},
                               {"descripcion": "Libro técnico", "cantidad": "2", "unidad": "otra cosa", "precio": "500",
                                "bonif": "10", "alicuota": "10.5"}]})
    c = d.get("comprobante", {})
    _borrar(c.get("id"))
    imp = c.get("importes", {})
    it = (c.get("items") or [{}, {}])
    # 1000 al 21 % + (2 × 500 − 10 %) = 900 al 10,5 % → neto 1900, IVA 210 + 94,50
    ok = (s in (200, 201) and imp == {"neto": 190000, "iva": 30450, "total": 220450} and it[0].get("codigo") == "A-1"
          and it[1].get("unidad") == "unidades" and it[1].get("bonif") == "10")
    return ok, f"{s} importes={imp} unidad={it[1].get('unidad') if len(it) > 1 else None}"


@caso("Una bonificación o una alícuota de renglón inválidas se rechazan con el número de ítem")
def renglon_invalido():
    base = {"tipo": "factura", "letra": "B", "cliente": {"razonSocial": "X", "condIVA": "Consumidor Final"}}
    s1, d1 = _guardar(dict(base, items=[{"descripcion": "A", "cantidad": "1", "precio": "1", "bonif": "150"}]))
    s2, d2 = _guardar(dict(base, items=[{"descripcion": "A", "cantidad": "1", "precio": "1"},
                                        {"descripcion": "B", "cantidad": "1", "precio": "1", "alicuota": "19"}]))
    ok = s1 == 422 and "ítem 1" in d1.get("error", "") and s2 == 422 and "ítem 2" in d2.get("error", "")
    return ok, f"bonif={s1} {d1.get('error', '')[:50]} · alícuota={s2} {d2.get('error', '')[:50]}"


@caso("Tipo de documento: «Sin identificar» no guarda número; se conserva el tipo elegido")
def tipo_documento():
    base = {"tipo": "factura", "letra": "B", "items": [{"descripcion": "A", "cantidad": "1", "precio": "10"}]}
    _, a = _guardar(dict(base, cliente={"razonSocial": "CF", "docTipo": 99, "cuit": "30-71140975-7", "condIVA": "Consumidor Final"}))
    _, b = _guardar(dict(base, cliente={"razonSocial": "DNI", "docTipo": 96, "cuit": "20123456", "condIVA": "Consumidor Final"}))
    _, c = _guardar(dict(base, cliente={"razonSocial": "RARO", "docTipo": 55, "cuit": "", "condIVA": "Consumidor Final"}))
    ca, cb, cc = (x.get("comprobante", {}) for x in (a, b, c))
    _borrar(ca.get("id"), cb.get("id"), cc.get("id"))
    ok = (ca.get("cliente", {}).get("docTipo") == 99 and ca.get("cliente", {}).get("cuit") == ""
          and cb.get("cliente", {}).get("docTipo") == 96 and cb.get("cliente", {}).get("cuit") == "20123456"
          and cc.get("cliente", {}).get("docTipo") == 0)
    return ok, f"99={ca.get('cliente')} 96={cb.get('cliente', {}).get('cuit')} inválido={cc.get('cliente', {}).get('docTipo')}"


@caso("Recibo vinculado: cancela facturas emitidas sin pasar su saldo ni su propio monto")
def recibo_cancela():
    f = _factura_x("1000")
    fid = f.get("id")
    ids = [fid]
    try:
        s1, r1 = _recibo([{"id": fid, "importe": "600"}], "600")
        ids.append(r1.get("comprobante", {}).get("id"))
        s2, r2 = _recibo([{"id": fid, "importe": "500"}], "500")          # quedan 400
        s3, r3 = _recibo([{"id": fid, "importe": "400"}], "300")          # supera el monto del recibo
        _, b = _guardar({"tipo": "factura", "letra": "B", "cliente": {"razonSocial": "B", "condIVA": "Consumidor Final"},
                         "items": [{"descripcion": "A", "cantidad": "1", "precio": "10"}]})
        bid = b.get("comprobante", {}).get("id")
        ids.append(bid)
        s4, r4 = _recibo([{"id": bid, "importe": "10"}], "10")            # B sin CAE: no está emitida
        s5, r5 = _recibo([{"id": fid, "importe": "400"}], "400")          # salda la factura
        ids.append(r5.get("comprobante", {}).get("id"))
        etiqueta = (r1.get("comprobante", {}).get("cancela") or [{}])[0].get("etiqueta", "")
        ok = (s1 in (200, 201) and etiqueta.startswith("Factura X") and s2 == 422 and "400" in r2.get("error", "")
              and s3 == 422 and "supera" in r3.get("error", "") and s4 == 422 and s5 in (200, 201))
        return ok, f"600={s1} «{etiqueta}» · 500={s2} · >monto={s3} · B sin CAE={s4} · saldo={s5}"
    finally:
        _borrar(*reversed(ids))


@caso("Envío por correo: solo con sesión, solo documentos emitidos, direcciones y PDF controlados")
def correo_servidor():
    if not CORREO_DIR:
        return True, "sin REMITOS_CORREO_DIR: el servidor enviaría correos reales"
    asegurar_admin()
    s = _Sesion(ADMIN_EMAIL)
    f = _factura_x("2500", cliente="CORREO PRUEBA SA")
    _, b = _guardar({"tipo": "factura", "letra": "B", "cliente": {"razonSocial": "B", "condIVA": "Consumidor Final"},
                     "items": [{"descripcion": "A", "cantidad": "1", "precio": "10"}]})
    bid = b.get("comprobante", {}).get("id")
    try:
        antes = set(glob.glob(os.path.join(CORREO_DIR, "*.eml")))
        c_clave, _ = pedir("/correo.php", "POST", clave_maestra(), {"tipo": "comprobante", "id": f.get("id"), "para": "a@b.com"})
        c_ok, d_ok = s.pedir("/correo.php", "POST", {"tipo": "comprobante", "id": f.get("id"), "para": "cliente@ejemplo.com.ar",
                                                       "mensaje": "Adjuntamos la factura.", "copia": True,
                                                       "pdf": "JVBERi0xLjQKJcOkw7zDtsOfCg==", "pdfNombre": "factura.pdf"})
        nuevos = sorted(set(glob.glob(os.path.join(CORREO_DIR, "*.eml"))) - antes)
        eml = open(nuevos[-1], encoding="utf-8").read() if nuevos else ""
        c_b, _ = s.pedir("/correo.php", "POST", {"tipo": "comprobante", "id": bid, "para": "a@b.com"})
        c_mal, _ = s.pedir("/correo.php", "POST", {"tipo": "comprobante", "id": f.get("id"), "para": "no-es-correo"})
        c_pdf, _ = s.pedir("/correo.php", "POST", {"tipo": "comprobante", "id": f.get("id"), "para": "a@b.com", "pdf": "aG9sYQ=="})
        c_cab, _ = s.pedir("/correo.php", "POST", {"tipo": "comprobante", "id": f.get("id"),
                                                     "para": "a@b.com\r\nBcc: espia@ejemplo.com"})
        ok = (c_clave == 401 and c_ok == 200 and "To: cliente@ejemplo.com.ar" in eml and "Cc: " + ADMIN_EMAIL in eml
              and "application/pdf" in eml and "Reply-To: " + ADMIN_EMAIL in eml and c_b == 409 and c_mal == 422
              and c_pdf == 422 and c_cab == 422 and "espia" not in eml)
        return ok, (f"clave={c_clave} sesión={c_ok} {d_ok.get('error', '')} eml={'sí' if eml else 'no'} · B sin CAE={c_b} · "
                    f"dirección={c_mal} · no-PDF={c_pdf} · inyección={c_cab}")
    finally:
        _borrar(f.get("id"), bid)


@caso("Editor: código, unidad, bonificación e IVA por renglón; el documento muestra cada alícuota")
def editor_renglones():
    def prueba(pg, errores):
        ir_a(pg, "factura")
        pg.evaluate("""async () => { await nuevoComprobante('factura'); compDoc.letra = 'A';
            compDoc.cliente.condIVA = 'Responsable Inscripto'; renderComprobanteEditor(); renderComprobante(); }""")
        pg.fill('[data-ci="0"][data-ck="codigo"]', "SOP-1")
        pg.fill('[data-ci="0"][data-ck="descripcion"]', "Soporte")
        pg.fill('[data-ci="0"][data-ck="precio"]', "1000")
        pg.fill('[data-ci="0"][data-ck="bonif"]', "10")
        pg.click("#compAddItem")
        pg.fill('[data-ci="1"][data-ck="descripcion"]', "Libro")
        pg.fill('[data-ci="1"][data-ck="precio"]', "200")
        pg.select_option('[data-ci="1"][data-ck="alicuota"]', "10.5")
        pg.select_option('[data-ci="1"][data-ck="unidad"]', "pares")
        pg.wait_for_timeout(300)
        sub = pg.inner_text("#itemSub0")
        doc = pg.inner_text("#compPage").upper()
        pg.fill('[data-ci="0"][data-ck="bonif"]', "150")
        error = pg.locator('[data-ci="0"][data-ck="bonif"].con-error').count()
        ok = ("900,00" in sub and "SOP-1" in doc and "PARES" in doc and "IVA 21%" in doc and "189,00" in doc
              and "IVA 10,5%" in doc and "21,00" in doc and "SUBTOTAL C/IVA" in doc and error == 1 and not errores)
        return ok, f"subtotal={sub} error_bonif={error} errores={errores}"
    return _con_pagina(prueba)


@caso("Tipo de documento en el editor: DNI cambia el campo y «Sin identificar» lo deshabilita")
def editor_tipo_documento():
    def prueba(pg, errores):
        ir_a(pg, "factura")
        pg.evaluate("async () => { await nuevoComprobante('factura'); }")
        pg.select_option('[data-cpath="cliente.docTipo"]', "96")
        pg.wait_for_timeout(200)
        dni = pg.get_attribute('[data-cpath="cliente.cuit"]', "maxlength")
        pg.select_option('[data-cpath="cliente.docTipo"]', "99")
        pg.wait_for_timeout(200)
        sin = pg.locator('[data-cpath="cliente.cuit"]').count()
        doc = pg.inner_text("#compPage")
        ok = dni == "8" and sin == 0 and "Sin identificar" in doc and not errores
        return ok, f"maxlength DNI={dni} campo con 99={sin} errores={errores}"
    return _con_pagina(prueba)


@caso("Registrar el cobro desde una factura arma el recibo con su saldo y lo imprime detallado")
def recibo_desde_factura():
    f = _factura_x("1500", cliente="COBRO UI SA")
    try:
        def prueba(pg, errores):
            ir_a(pg, "factura")
            pg.evaluate("""async (id) => { const j = await api('comprobantes.php?id=' + id);
                const c = j.comprobante; datos.comprobantes = datos.comprobantes.filter(x => x.id !== c.id); datos.comprobantes.push(c);
                await cobrarDesde(c); }""", f.get("id"))
            pg.wait_for_timeout(400)
            r = pg.evaluate("() => ({ monto: compDoc.monto, cancela: compDoc.cancela, concepto: compDoc.concepto })")
            marcado = pg.locator(f'[data-cancela="{f.get("id")}"]:checked').count()
            doc = pg.inner_text("#compPage")
            ok = (r["monto"] == "1.500,00" and len(r["cancela"]) == 1 and r["concepto"].startswith("Cancelación de Factura X")
                  and marcado == 1 and "COMPROBANTE QUE CANCELA" in doc.upper() and not errores)
            return ok, f"{r} marcado={marcado} errores={errores}"
        return _con_pagina(prueba)
    finally:
        _borrar(f.get("id"))


@caso("Agenda en tabla: ordena por columna, busca y «Factura» abre una factura con el cliente")
def agenda_tabla():
    m = clave_maestra()
    creados = []
    for nombre, cuit in (("ZETA TABLA SA", "30-71140975-7"), ("ALFA TABLA SRL", "20-12345678-6")):
        _, d = pedir("/clientes.php", "POST", m, {"razonSocial": nombre, "cuit": cuit, "condIVA": "Responsable Inscripto"})
        creados.append((d.get("cliente") or {}).get("id"))
    try:
        def prueba(pg, errores):
            ir_a(pg, "clientes")
            pg.fill("#cliSearch", "TABLA")
            pg.wait_for_timeout(300)
            primero = pg.inner_text(".cli-tabla tbody tr:first-child b")
            pg.click('[data-orden="razonSocial"]')
            pg.wait_for_timeout(200)
            invertido = pg.inner_text(".cli-tabla tbody tr:first-child b")
            filas = pg.locator(".cli-tabla tbody tr").count()
            pg.click(".cli-tabla tbody tr:first-child [data-fact]")
            pg.wait_for_timeout(600)
            r = pg.evaluate("() => ({ vista: document.body.dataset.view, cliente: compDoc.cliente.razonSocial, letra: compDoc.letra })")
            ok = (primero == "ALFA TABLA SRL" and invertido == "ZETA TABLA SA" and filas == 2 and r["vista"] == "factura"
                  and r["cliente"] == "ZETA TABLA SA" and r["letra"] == "A" and not errores)
            return ok, f"asc={primero} desc={invertido} filas={filas} {r} errores={errores}"
        return _con_pagina(prueba)
    finally:
        for i in creados:
            if i:
                pedir(f"/clientes.php?id={i}", "DELETE", m)


@caso("Importar clientes muestra una vista previa con nuevos, actualizaciones y errores antes de cargar")
def importar_vista_previa():
    csv = ("Razón Social,CUIT,Condición frente al IVA\n"
           "PREVIA UNO SA,30-71140975-7,Responsable Inscripto\n"
           "PREVIA DOS SA,30-11111111-1,Responsable Inscripto\n"
           ",,\n"
           "PREVIA TRES SA,30-71140975-7,Responsable Inscripto\n")
    ruta = os.path.join(tempfile.gettempdir(), "previa-clientes.csv")
    open(ruta, "w", encoding="utf-8").write(csv)

    def prueba(pg, errores):
        ir_a(pg, "clientes")
        antes = pg.evaluate("() => datos.clientes.length")
        pg.set_input_files("#cliImportFile", ruta)
        pg.wait_for_selector("#importModal.open", timeout=8000)
        resumen = pg.inner_text("#importResumen")
        boton = pg.inner_text("#importConfirmar")
        despues = pg.evaluate("() => datos.clientes.length")
        pg.click("#importCancelar")
        ok = ("1 nuevos" in resumen and "2 con errores" in resumen and boton == "Importar 1 cliente" and antes == despues
              and not errores)
        return ok, f"resumen={resumen.splitlines()[:3]} botón={boton} sin cargar={antes == despues} errores={errores}"
    return _con_pagina(prueba)


@caso("Calibración: el valor exacto se escribe, se ajusta al rango y mueve el control deslizante")
def calibracion_valor_exacto():
    def prueba(pg, errores):
        ir_a(pg, "remito")
        pg.evaluate("() => openPanel($('#calib'), $('#backdrop'))")
        pg.wait_for_timeout(300)
        pg.fill("#calOffXv", "3,5")
        pg.press("#calOffXv", "Enter")
        rango = pg.input_value("#calOffX")
        pg.fill("#calOffXv", "99")
        pg.press("#calOffXv", "Enter")
        fuera = pg.locator("#calOffXv.con-error").count()
        pg.fill("#calOffXv", "0")
        pg.press("#calOffXv", "Enter")
        ok = rango == "3.5" and fuera == 1 and not errores
        return ok, f"rango={rango} fuera_de_rango_marcado={fuera} errores={errores}"
    return _con_pagina(prueba)


@caso("En el celular Inicio muestra fecha, importe y acciones de cada documento, y «Guardados» en el remito")
def inicio_celular():
    m = clave_maestra()
    f = _factura_x("100", cliente="CELULAR SA")
    try:
        def prueba(pg, errores):
            pg.evaluate("() => { showView('inicio'); renderInicio(); }")
            pg.wait_for_timeout(400)
            fila = pg.locator(f'.doc-row[data-comp="{f.get("id")}"]')
            meta = fila.locator(".doc-meta").is_visible()
            acc = fila.locator("[data-print]").is_visible()
            todos = pg.locator("#btnVerTodos").is_visible()
            pg.evaluate("() => showView('remito')")
            pg.wait_for_timeout(400)
            guardados = pg.locator("#btnGuardados").is_visible()
            ok = meta and acc and todos and guardados and not errores
            return ok, f"meta={meta} imprimir={acc} ver_todos={todos} guardados={guardados} errores={errores}"
        return _con_pagina(prueba, ancho=390, alto=844)
    finally:
        _borrar(f.get("id"))


@caso("El diálogo de correo valida la dirección y envía el comprobante guardado")
def correo_interfaz():
    if not CORREO_DIR:
        return True, "sin REMITOS_CORREO_DIR"
    f = _factura_x("800", cliente="DIALOGO CORREO SA")
    try:
        def prueba(pg, errores):
            ir_a(pg, "factura")
            pg.evaluate("""async (id) => { const j = await api('comprobantes.php?id=' + id);
                datos.comprobantes.push(j.comprobante); abrirComprobante(id); }""", f.get("id"))
            pg.wait_for_timeout(500)
            sin_cambios = pg.evaluate("() => !dirtyComp()")
            pg.click("#tbCompCorreo")
            pg.wait_for_selector("#correoModal.open")
            pg.fill("#correoPara", "no es correo")
            pg.click("#correoEnviar")
            marcado = pg.locator("#correoPara.con-error").count()
            antes = len(glob.glob(os.path.join(CORREO_DIR, "*.eml")))
            pg.fill("#correoPara", "compras@ejemplo.com.ar")
            pg.click("#correoEnviar")
            pg.wait_for_selector("#correoModal:not(.open)", timeout=8000)
            time.sleep(0.3)
            despues = len(glob.glob(os.path.join(CORREO_DIR, "*.eml")))
            ok = sin_cambios and marcado == 1 and despues == antes + 1 and not errores
            return ok, f"abierto sin cambios={sin_cambios} dirección inválida marcada={marcado} enviados={despues - antes} errores={errores}"
        return _con_pagina(prueba)
    finally:
        _borrar(f.get("id"))
