"""Pruebas del C.A.I. / talonarios y de la autorización automática del remito.

Crean un talonario y una clave de prueba, y limpian al terminar.
"""
import concurrent.futures
from datetime import date, timedelta

from playwright.sync_api import sync_playwright

from base import (DOC_COMPLETO_JS, REMITO_COMPLETO, VTO, _borrar_talonario, _remito, _talonario, abrir, caso,
                  clave_maestra, ir_a, modo_documento, pedir)

def _autorizar(pv, **extra):
    cuerpo = {"accion": "autorizar", "pv": pv, "tipo": 91, "remito_id": _remito()}
    cuerpo.update(extra)
    return pedir("/talonarios.php", "POST", clave_maestra(), cuerpo)


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
        ("tipo inválido", {"punto_venta": 90, "numero_desde": 1, "numero_hasta": 9, "cai": "75041234567890",
                           "cai_vencimiento": VTO, "tipo_comprobante": 777}),
        ("fecha imposible", {"punto_venta": 90, "numero_desde": 1, "numero_hasta": 9, "cai": "75041234567890",
                             "cai_vencimiento": "2027-02-31"}),
    ]
    malos = []
    for etq, cuerpo in casos:
        s, d = pedir("/talonarios.php", "POST", m, cuerpo)
        if s != 400:
            malos.append((etq, s))
    return not malos, f"no rechazó: {malos}" if malos else "las 6 validaciones OK"


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
            s, d = _autorizar(91)
            if d.get("ok"):
                nums.append(d["numero_formato"])
        sin_remito = pedir("/talonarios.php", "POST", m, {"accion": "autorizar", "pv": 91, "tipo": 91})[0]
        esperado = ["00091-00000001", "00091-00000002", "00091-00000003"]
        return nums == esperado and sin_remito == 400, f"emitió {nums} sin_remito={sin_remito}"
    finally:
        _borrar_talonario(tid)


@caso("Un C.A.I. vencido a la fecha de emisión no autoriza, ni siquiera confirmando")
def cai_vencido_bloquea():
    m = clave_maestra()
    if not m:
        return False, "sin clave maestra"
    vto_manana = (date.today() + timedelta(days=1)).isoformat()
    s, d = _talonario(desde=1, hasta=5, vto=vto_manana, cai="75049999999999", pv=92)
    tid = d.get("id")
    try:
        futuro = (date.today() + timedelta(days=10)).isoformat()
        rid = _remito()
        cuerpo = {"accion": "autorizar", "pv": 92, "tipo": 91, "fecha": futuro, "remito_id": rid}
        s, d = pedir("/talonarios.php", "POST", m, cuerpo)
        bloqueo = s == 409 and d.get("vencido")
        s2, d2 = pedir("/talonarios.php", "POST", m, dict(cuerpo, confirmar=True))
        sigue = s2 == 409
        return bloqueo and sigue, f"bloqueó={bloqueo} con_confirmar={s2}"
    finally:
        _borrar_talonario(tid)


@caso("Un talonario usado no se borra (se desactiva); uno cargado por error y sin usar, sí")
def talonario_activo_emitido():
    m = clave_maestra()
    if not m:
        return False, "sin clave maestra"
    s, d = _talonario(desde=1, hasta=5, pv=93)
    tid = d.get("id")
    _, d2 = _talonario(desde=100, hasta=105, pv=93)
    sin_uso = d2.get("id")
    try:
        _autorizar(93)
        s1, _ = pedir(f"/talonarios.php?id={tid}", "DELETE", m)          # activo y usado → 409
        desactivar = pedir("/talonarios.php", "POST", m, {"id": tid, "activa": "false"})[1].get("activa")
        s2, _ = pedir(f"/talonarios.php?id={tid}", "DELETE", m)          # desactivado pero usado → 409
        s3, _ = pedir(f"/talonarios.php?id={sin_uso}", "DELETE", m)      # nunca usado → 200
        _, lista = pedir("/talonarios.php?accion=listar", clave=m)
        contados = lista.get("resumen", {}).get("remitos_autorizados")
        _, est = pedir("/estado.php", clave=m)
        ok = s1 == 409 and desactivar is False and s2 == 409 and s3 == 200 and contados == est["resumen"]["remitos_autorizados"]
        return ok, f"activo={s1} desactivar('false')={desactivar} desactivado={s2} sin_uso={s3} autorizados={contados}"
    finally:
        _borrar_talonario(tid)
        _borrar_talonario(sin_uso)


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
                pg.evaluate("() => { nuevo(); " + DOC_COMPLETO_JS + " doc.fecha = '21/07/2026'; formFromDoc(); renderPreview(); }")
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


@caso("Un remito autorizado no se puede volver a autorizar, modificar ni eliminar")
def autorizado_protegido():
    m = clave_maestra()
    s, d = _talonario(desde=1, hasta=5, pv=96)
    tid = d.get("id")
    try:
        _, r = pedir("/remitos.php", "POST", m, dict(REMITO_COMPLETO, cliente=dict(REMITO_COMPLETO["cliente"], razonSocial="PROTEGIDO")))
        rid = r["id"]
        s1, _ = pedir("/talonarios.php", "POST", m, {"accion": "autorizar", "pv": 96, "tipo": 91, "remito_id": rid})
        s2, _ = pedir("/talonarios.php", "POST", m, {"accion": "autorizar", "pv": 96, "tipo": 91, "remito_id": rid})
        s_ed, _ = pedir("/remitos.php", "POST", m, {"id": rid, "numero": "99999999", "cai": "1", "cliente": {"razonSocial": "EDITADO"}})
        s3, _ = pedir(f"/remitos.php?id={rid}", "DELETE", m)
        rem = pedir(f"/remitos.php?id={rid}", clave=m)[1].get("remito", {})
        ok = (s1 == 200 and s2 == 409 and s_ed == 409 and s3 == 409 and rem.get("numero") == "00096-00000001"
              and rem.get("cliente", {}).get("razonSocial") == "PROTEGIDO")
        return ok, f"autorizar={s1} reautorizar={s2} editar={s_ed} borrar={s3} numero={rem.get('numero')}"
    finally:
        _borrar_talonario(tid)


@caso("Autorizaciones simultáneas nunca repiten número y el borrador no se confunde con un autorizado")
def autorizacion_concurrente():
    m = clave_maestra()
    s, d = _talonario(desde=1, hasta=40, pv=97)
    tid = d.get("id")
    try:
        ids = [_remito(f"SIMULTANEO {i}") for i in range(10)]
        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as ex:
            res = list(ex.map(lambda rid: pedir("/talonarios.php", "POST", m,
                                                {"accion": "autorizar", "pv": 97, "tipo": 91, "remito_id": rid})[1], ids))
        numeros = [r.get("numero_formato") for r in res]
        # un borrador nuevo no toma ningún número fiscal: no puede coincidir con un autorizado
        _, b = pedir("/remitos.php", "POST", m, {"cliente": {"razonSocial": "BORRADOR"}, "numero": "00000001"})
        pedir(f"/remitos.php?id={b['id']}", "DELETE", m)
        ok = len(set(numeros)) == 10 and None not in numeros and b["remito"]["numero"] == ""
        return ok, f"números={sorted(n for n in numeros if n)} borrador={b['remito']['numero']!r}"
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
                pg.evaluate("() => { nuevo(); " + DOC_COMPLETO_JS + " doc.cliente.razonSocial = 'SIN TALONARIO'; formFromDoc(); }")
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
