"""Pruebas de normativa de remitos: contenido obligatorio (RG 1415, Anexo V), constatación
del C.A.I. (WSCDC) y remito digital con conformidad del destinatario (RG 5678/2025).

Crean talonarios y remitos en el entorno de pruebas y los limpian al terminar.
"""
import json
import random
import urllib.error
import urllib.request

from playwright.sync_api import sync_playwright

from base import (API, REMITO_COMPLETO, SITIO, _borrar_talonario, _sql, _talonario, abrir, caso, clave_maestra, es_local,
                  ir_a, modo_documento, pedir)


def _autorizado(pv, **cambios):
    """Talonario nuevo y un remito completo autorizado en ese punto de venta."""
    m = clave_maestra()
    _, t = _talonario(desde=1, hasta=20, pv=pv)
    cuerpo = dict(REMITO_COMPLETO)
    cuerpo.update(cambios)
    _, r = pedir("/remitos.php", "POST", m, cuerpo)
    s, a = pedir("/talonarios.php", "POST", m, {"accion": "autorizar", "pv": pv, "tipo": 91, "remito_id": r["id"]})
    if s != 200:
        raise RuntimeError(f"no se autorizó: {a}")
    return t["id"], r["id"]


def _publico(cuerpo, origen=SITIO):
    """Petición a la API pública de conformidad, como la página del destinatario."""
    req = urllib.request.Request(API + "/conformidad.php", data=json.dumps(cuerpo).encode(), method="POST")
    req.add_header("Content-Type", "application/json")
    req.add_header("Origin", origen)
    try:
        with urllib.request.urlopen(req, timeout=20) as r:
            return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode() or "{}")


@caso("Un remito sin los datos del Anexo V no se autoriza y el borrador informa qué le falta")
def anexo_v_obligatorio():
    m = clave_maestra()
    pv = random.randint(300, 399)
    _, t = _talonario(desde=1, hasta=10, pv=pv)
    try:
        _, r = pedir("/remitos.php", "POST", m, {"cliente": {"razonSocial": "INCOMPLETO"}, "transporte": {"tipo": "tercero"}})
        faltantes = r["remito"]["faltantes"]
        s, d = pedir("/talonarios.php", "POST", m, {"accion": "autorizar", "pv": pv, "tipo": 91, "remito_id": r["id"]})
        cf = dict(REMITO_COMPLETO, cliente={"razonSocial": "CONSUMIDOR", "condIVA": "Consumidor Final"})
        _, r2 = pedir("/remitos.php", "POST", m, cf)
        s2, _ = pedir("/talonarios.php", "POST", m, {"accion": "autorizar", "pv": pv, "tipo": 91, "remito_id": r2["id"]})
        esperados = {"la condición del destinatario frente al IVA", "el CUIT del transportista", "la descripción de los bienes que se trasladan"}
        ok = esperados <= set(faltantes) and s == 422 and set(d.get("faltan", [])) == set(faltantes) and s2 == 200
        return ok, f"faltantes={faltantes} autorizar_incompleto={s} consumidor_final={s2}"
    finally:
        _borrar_talonario(t.get("id"))
        if es_local():
            _sql("DELETE FROM remitos WHERE cliente IN ('INCOMPLETO', 'CONSUMIDOR')")


@caso("Sin certificado de producción el talonario queda «sin constatar» con ARCA y se informa")
def talonario_sin_constatar():
    m = clave_maestra()
    pv = random.randint(400, 499)
    s, d = _talonario(desde=1, hasta=10, pv=pv)
    try:
        v = d.get("verificacion", {})
        s2, d2 = pedir("/talonarios.php", "POST", m, {"accion": "verificar", "id": d.get("id")})
        _, lista = pedir("/talonarios.php?accion=listar", clave=m)
        t = next(x for x in lista["talonarios"] if x["id"] == d.get("id"))
        ok = (s == 201 and v.get("estado") == "sin_credenciales" and d2.get("verificacion", {}).get("estado") == "sin_credenciales"
              and t["verificado_arca"] is None and "producción" in (t["verificacion"] or ""))
        return ok, f"alta={s} {v} verificar={s2} talonario={t.get('verificacion')}"
    finally:
        _borrar_talonario(d.get("id"))


@caso("Remito digital: el enlace de conformidad es de un solo uso, se renueva y registra quién recibió")
def conformidad_api():
    m = clave_maestra()
    tid, rid = _autorizado(random.randint(500, 599))
    try:
        _, e1 = pedir("/remitos.php", "POST", m, {"accion": "conformidad", "id": rid})
        _, e2 = pedir("/remitos.php", "POST", m, {"accion": "conformidad", "id": rid})
        t1, t2 = e1["url"].split("#")[1], e2["url"].split("#")[1]
        viejo = _publico({"accion": "ver", "token": t1})[0]
        s_ver, ver = _publico({"accion": "ver", "token": t2})
        otro_origen = _publico({"accion": "ver", "token": t2}, origen="https://otro.example")[0]
        sin_check = _publico({"accion": "conformar", "token": t2, "nombre": "Laura Méndez", "documento": "34323455"})[0]
        s_ok, ok = _publico({"accion": "conformar", "token": t2, "nombre": "Laura Méndez", "documento": "34.323.455", "conforme": True})
        doble = _publico({"accion": "conformar", "token": t2, "nombre": "Otra", "documento": "11111111", "conforme": True})[0]
        _, rem = pedir(f"/remitos.php?id={rid}", clave=m)
        c = rem["remito"]["conformidad"]
        sin_enlace_nuevo = pedir("/remitos.php", "POST", m, {"accion": "conformidad", "id": rid})[0]
        res = (viejo == 404 and s_ver == 200 and ver["remito"]["items"] and otro_origen == 403 and sin_check == 422
               and s_ok == 200 and doble == 409 and c["nombre"] == "Laura Méndez" and c["documento"] == "34323455"
               and sin_enlace_nuevo == 409)
        return res, (f"viejo={viejo} ver={s_ver} otro_origen={otro_origen} sin_check={sin_check} confirmar={s_ok} "
                     f"doble={doble} conformidad={c} enlace_tras_confirmar={sin_enlace_nuevo}")
    finally:
        _borrar_talonario(tid)


@caso("El destinatario confirma la recepción desde la página pública, en el celular")
def conformidad_pagina():
    m = clave_maestra()
    tid, rid = _autorizado(random.randint(600, 699))
    try:
        _, e = pedir("/remitos.php", "POST", m, {"accion": "conformidad", "id": rid})
        with sync_playwright() as pw:
            b = pw.chromium.launch()
            pg = b.new_page(viewport={"width": 390, "height": 844})
            errores = []
            pg.on("pageerror", lambda x: errores.append(str(x)))
            pg.on("console", lambda x: errores.append(x.text) if x.type == "error" else None)
            try:
                pg.goto(e["url"], wait_until="networkidle")
                pg.wait_for_selector("#formConformar", state="visible", timeout=10000)
                numero = pg.inner_text("#rNumero")
                pg.fill("#cNombre", "Laura Méndez")
                pg.fill("#cDoc", "34323455")
                pg.fill("#cObs", "Llegó completo")
                pg.check("#cConforme")
                pg.click("#cEnviar")
                pg.wait_for_selector("#conformado", state="visible", timeout=10000)
                texto = pg.inner_text("#confTexto")
                ancho = pg.evaluate("() => document.documentElement.scrollWidth")
                pg.goto(SITIO + "/conformidad.html#tokeninvalido", wait_until="networkidle")
                pg.wait_for_selector("#error", state="visible", timeout=10000)
                invalido = pg.inner_text("#errorTexto")
            finally:
                b.close()
        ok = (numero.startswith("Nº ") and "Laura Méndez" in texto and "Llegó completo" in texto and ancho <= 392
              and "no es válido" in invalido and not errores)
        return ok, f"numero={numero!r} texto={texto!r} ancho={ancho} invalido={invalido!r} errores={errores}"
    finally:
        _borrar_talonario(tid)


@caso("El remito impreso lleva los datos obligatorios y el PDF digital reemplaza ORIGINAL/DUPLICADO por el QR de conformidad")
def remito_digital_documento():
    pv = random.randint(700, 799)
    tid, rid = _autorizado(pv, transporte={"tipo": "tercero", "nombre": "Transportes del Sur", "cuit": "20-12345678-6",
                                           "domicilio": "Ruta 3 km 40"})
    try:
        with sync_playwright() as pw:
            b, pg, errs = abrir(pw)
            try:
                ir_a(pg, "remito")
                pg.evaluate(f"""async () => {{ const r = (await api('remitos.php?id={rid}')).remito;
                    actualizarEnLista(datos.remitos, r); cargarRemito(r); }}""")
                modo_documento(pg, "full")
                textos = pg.evaluate("() => [...document.querySelectorAll('#template div')].map(e => e.textContent.trim())")
                impreso = {
                    "iva_emisor": "IVA RESPONSABLE INSCRIPTO" in textos,
                    "iva_destinatario": any("IVA RESPONSABLE INSCRIPTO" in t and "IVA:" in t for t in textos),
                    "cai_n": any(t.startswith("C.A.I. Nº 75041234567890") for t in textos),
                    "vto": any(t.startswith("Fecha de Vto.") for t in textos),
                    "transportista": any("20-12345678-6" in t for t in textos) and any("Ruta 3 km 40" in t for t in textos),
                    "original": "ORIGINAL BLANCO - DUPLICADO BEIGE" in textos,
                }
                pg.click("#btnDigital")
                pg.wait_for_selector("#digitalModal.open", timeout=10000)
                pg.wait_for_timeout(500)
                modal = pg.evaluate("() => ({ url: document.querySelector('#digitalUrl').value, qr: !!document.querySelector('#digitalQr img') })")
                pg.evaluate("""() => { window.__pdf = null; window.print = () => { window.__pdf = {
                    textos: [...document.querySelectorAll('#template div')].map(e => e.textContent.trim()),
                    qr: [...document.querySelectorAll('#template img')].some(i => i.src.startsWith('data:image')) }; }; }""")
                pg.click("#digitalPdf")
                pg.wait_for_timeout(800)
                pdf = pg.evaluate("() => window.__pdf")
            finally:
                b.close()
        digital = pdf and "REMITO DIGITAL - RG 5678/2025" in pdf["textos"] and pdf["qr"] and "CONFORMIDAD DIGITAL" in pdf["textos"] \
            and "ORIGINAL BLANCO - DUPLICADO BEIGE" not in pdf["textos"]
        ok = all(impreso.values()) and "/conformidad.html#" in modal["url"] and modal["qr"] and digital and not errs
        return ok, f"impreso={impreso} modal={modal} pdf_digital={bool(digital)} errores={errs}"
    finally:
        _borrar_talonario(tid)


@caso("Autorizar desde la aplicación avisa los datos que faltan sin consumir número")
def autorizar_incompleto_ui():
    pv = random.randint(800, 899)
    _, t = _talonario(desde=1, hasta=10, pv=pv)
    pedir("/ajustes.php", "POST", clave_maestra(), {"seccion": "remito", "valores": {"ptoVenta": str(pv), "copias": 2}})
    try:
        with sync_playwright() as pw:
            b, pg, errs = abrir(pw)
            try:
                ir_a(pg, "remito")
                pg.evaluate("() => { nuevo(); doc.cliente.razonSocial = 'SIN DATOS'; formFromDoc(); }")
                pg.click("#btnAutorizar")
                pg.wait_for_timeout(800)
                aviso = pg.inner_text("#toast")
                estado = pg.evaluate("() => doc.estado")
            finally:
                b.close()
        _, lista = pedir("/talonarios.php?accion=listar", clave=clave_maestra())
        proximo = next(x for x in lista["talonarios"] if x["id"] == t["id"])["proximo_numero"]
        ok = "Anexo V" in aviso and "condición del destinatario" in aviso and estado != "autorizado" and proximo == 1
        return ok, f"aviso={aviso!r} estado={estado} proximo={proximo}"
    finally:
        pedir("/ajustes.php", "POST", clave_maestra(), {"seccion": "remito", "valores": {"ptoVenta": "1", "copias": 2}})
        _borrar_talonario(t.get("id"))
