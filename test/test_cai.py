"""Pruebas del C.A.I. / talonarios y de la autorización automática del remito.

Crean un talonario y una clave de prueba, y limpian al terminar.
"""
from datetime import date, timedelta

from playwright.sync_api import sync_playwright

from base import abrir, caso, clave_maestra, ir_a, modo_documento, pedir

VTO = (date.today() + timedelta(days=200)).isoformat()


def _talonario(desde=1, hasta=50, cai="75041234567890", vto=VTO, pv=1):
    return pedir("/talonarios.php", "POST", clave_maestra(), {
        "punto_venta": pv, "numero_desde": desde, "numero_hasta": hasta,
        "cai": cai, "cai_vencimiento": vto})


def _borrar_talonario(tid):
    if not tid:
        return
    # se desactiva primero: un desactivado siempre se puede purgar (el remito ya
    # conserva el CAI), así el test no deja basura en la base
    pedir("/talonarios.php", "POST", clave_maestra(), {"id": tid, "activa": False})
    pedir(f"/talonarios.php?id={tid}", "DELETE", clave_maestra())


@caso("El alta de talonario valida CAI, rango y vencimiento")
def alta_valida():
    m = clave_maestra()
    if not m:
        return False, "sin clave maestra"
    casos = [
        ("CAI corto",   {"punto_venta": 90, "numero_desde": 1, "numero_hasta": 9, "cai": "123", "cai_vencimiento": VTO}),
        ("vto pasado",  {"punto_venta": 90, "numero_desde": 1, "numero_hasta": 9, "cai": "75041234567890",
                         "cai_vencimiento": (date.today() - timedelta(days=1)).isoformat()}),
        ("rango malo",  {"punto_venta": 90, "numero_desde": 9, "numero_hasta": 9, "cai": "75041234567890", "cai_vencimiento": VTO}),
        ("pv inválido", {"punto_venta": 0, "numero_desde": 1, "numero_hasta": 9, "cai": "75041234567890", "cai_vencimiento": VTO}),
    ]
    malos = []
    for etq, cuerpo in casos:
        s, d = pedir("/talonarios.php", "POST", m, cuerpo)
        if s != 400:
            malos.append((etq, s))
    return not malos, f"no rechazó: {malos}" if malos else "las 4 validaciones OK"


@caso("La numeración es correlativa y se corta al agotar el rango")
def numeracion_correlativa():
    m = clave_maestra()
    if not m:
        return False, "sin clave maestra"
    s, d = _talonario(desde=1, hasta=3, pv=91)
    tid = d.get("id")
    if not tid:
        return False, f"no se pudo crear el talonario: {d}"
    try:
        nums = []
        for _ in range(4):                    # el 4º debe fallar
            s, d = pedir("/talonarios.php", "POST", m, {"accion": "autorizar", "pv": 91, "tipo": 91})
            if d.get("ok"):
                nums.append(d["numero_formato"])
        esperado = ["00091-00000001", "00091-00000002", "00091-00000003"]
        return nums == esperado, f"emitió {nums}"
    finally:
        _borrar_talonario(tid)


@caso("Un C.A.I. vencido a la fecha de emisión no autoriza sin confirmar")
def cai_vencido_bloquea():
    m = clave_maestra()
    if not m:
        return False, "sin clave maestra"
    vto_manana = (date.today() + timedelta(days=1)).isoformat()
    s, d = _talonario(desde=1, hasta=5, vto=vto_manana, cai="75049999999999", pv=92)
    tid = d.get("id")
    try:
        futuro = (date.today() + timedelta(days=10)).isoformat()
        s, d = pedir("/talonarios.php", "POST", m, {"accion": "autorizar", "pv": 92, "tipo": 91, "fecha": futuro})
        bloqueo = s == 409 and d.get("vencido")
        s, d = pedir("/talonarios.php", "POST", m, {"accion": "autorizar", "pv": 92, "tipo": 91, "fecha": futuro, "confirmar": True})
        con_confirmar = d.get("ok")
        return bloqueo and con_confirmar, f"bloqueó={bloqueo} confirmar_permite={con_confirmar}"
    finally:
        _borrar_talonario(tid)


@caso("Un talonario activo que emitió no se borra hasta desactivarlo")
def talonario_activo_emitido():
    m = clave_maestra()
    if not m:
        return False, "sin clave maestra"
    s, d = _talonario(desde=1, hasta=5, pv=93)
    tid = d.get("id")
    try:
        pedir("/talonarios.php", "POST", m, {"accion": "autorizar", "pv": 93, "tipo": 91})
        s1, _ = pedir(f"/talonarios.php?id={tid}", "DELETE", m)          # activo+emitido → 409
        pedir("/talonarios.php", "POST", m, {"id": tid, "activa": False})
        s2, _ = pedir(f"/talonarios.php?id={tid}", "DELETE", m)          # desactivado → 200
        return s1 == 409 and s2 == 200, f"activo={s1} desactivado={s2}"
    finally:
        _borrar_talonario(tid)


def _punto_de_venta_remitos(pv):
    pedir("/ajustes.php", "POST", clave_maestra(), {"seccion": "remito", "valores": {"ptoVenta": str(pv), "copias": 2}})


@caso("El remito se autoriza solo: número + C.A.I. + sello, guardado en el servidor")
def remito_autoriza_solo():
    m = clave_maestra()
    s, d = _talonario(desde=1, hasta=50, pv=94)
    tid = d.get("id")
    _punto_de_venta_remitos(94)
    try:
        with sync_playwright() as pw:
            b, pg, errs = abrir(pw)
            try:
                ir_a(pg, "remito")
                pg.evaluate("() => { nuevo(); doc.cliente.razonSocial = 'CLIENTE AUTORIZADO'; doc.fecha = '21/07/2026'; formFromDoc(); renderPreview(); }")
                pg.click("#btnAutorizar")
                pg.wait_for_timeout(2500)
                est = pg.evaluate("() => ({estado: doc.estado, numero: doc.numero, cai: doc.cai, id: doc.id})")
                modo_documento(pg, "full")
                sello = pg.evaluate("""() => [...document.querySelectorAll('#template div')]
                    .some(e => e.textContent.trim() === 'AUTORIZADO')""")
                cai_ok = pg.evaluate("""() => [...document.querySelectorAll('#template div')]
                    .some(e => e.textContent.includes('75041234567890'))""")
                nro = pg.evaluate("""() => { const e = [...document.querySelectorAll('#template div')]
                    .find(x => x.textContent.includes('00000001')); return e ? e.textContent.trim() : ''; }""")
                doble = nro.count("00094") > 1
                # quedó así en el servidor, no solo en pantalla
                _, srv = pedir(f"/remitos.php?id={est['id']}", clave=m)
                guardado = srv.get("remito", {})
                en_servidor = guardado.get("estado") == "autorizado" and guardado.get("numero") == "00094-00000001"
                ok = (est["estado"] == "autorizado" and est["numero"] == "00094-00000001"
                      and est["cai"] == "75041234567890" and sello and cai_ok and not doble and en_servidor)
                return ok, f"estado={est} sello={sello} cai={cai_ok} nro={nro!r} doble={doble} servidor={en_servidor}"
            finally:
                b.close()
    finally:
        _punto_de_venta_remitos(1)
        _borrar_talonario(tid)


@caso("Un remito autorizado no se puede volver a autorizar, editar su número ni eliminar")
def autorizado_protegido():
    m = clave_maestra()
    s, d = _talonario(desde=1, hasta=5, pv=96)
    tid = d.get("id")
    try:
        _, r = pedir("/remitos.php", "POST", m, {"cliente": {"razonSocial": "PROTEGIDO"}})
        rid = r["id"]
        s1, _ = pedir("/talonarios.php", "POST", m, {"accion": "autorizar", "pv": 96, "tipo": 91, "remito_id": rid})
        s2, _ = pedir("/talonarios.php", "POST", m, {"accion": "autorizar", "pv": 96, "tipo": 91, "remito_id": rid})
        _, e = pedir("/remitos.php", "POST", m, {"id": rid, "numero": "99999999", "cai": "1", "cliente": {"razonSocial": "EDITADO"}})
        s3, _ = pedir(f"/remitos.php?id={rid}", "DELETE", m)
        rem = e.get("remito", {})
        ok = s1 == 200 and s2 == 409 and rem.get("numero") == "00096-00000001" and rem.get("cliente", {}).get("razonSocial") == "EDITADO" and s3 == 409
        return ok, f"autorizar={s1} reautorizar={s2} numero={rem.get('numero')} borrar={s3}"
    finally:
        _borrar_talonario(tid)


@caso("Sin talonario vigente, Autorizar avisa y el remito queda como estaba")
def autorizar_sin_talonario():
    _punto_de_venta_remitos(95)
    try:
        with sync_playwright() as pw:
            b, pg, errs = abrir(pw)
            try:
                ir_a(pg, "remito")
                pg.evaluate("() => { nuevo(); doc.cliente.razonSocial = 'SIN TALONARIO'; formFromDoc(); }")
                pg.click("#btnAutorizar")
                pg.wait_for_timeout(1500)
                aviso = pg.evaluate("""() => { const t = document.querySelector('.toast');
                    return t ? t.textContent : ''; }""")
                sigue = pg.evaluate("() => doc.estado")
                return "talonario" in aviso.lower() and sigue != "autorizado", f"aviso={aviso!r} estado={sigue}"
            finally:
                b.close()
    finally:
        _punto_de_venta_remitos(1)
