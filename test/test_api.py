"""Pruebas de la API: autenticación, permisos y ciclo de vida de las claves.

Trabajan contra el entorno bajo prueba. Crean una clave de prueba y la borran al final.
"""
from base import API, caso, clave_maestra, pedir

RECURSOS = ["/estado.php", "/claves.php?accion=listar", "/remitos.php", "/proximo.php?tipo=factura"]


@caso("Ningún endpoint responde sin credencial")
def sin_credencial():
    """Error corregido: proximo.php (numeración fiscal) no pedía ninguna credencial."""
    malos = []
    for r in RECURSOS:
        s, d = pedir(r)
        if s != 401:
            malos.append((r, s))
    return not malos, f"respondieron sin clave: {malos}" if malos else f"{len(RECURSOS)} endpoints protegidos"


@caso("Una clave inventada se rechaza")
def clave_falsa():
    s, d = pedir("/estado.php", clave="ith_" + "0" * 32)
    return s == 401, f"status={s} {d.get('error')}"


@caso("La clave maestra puede administrar claves")
def maestra_administra():
    m = clave_maestra()
    if not m:
        return False, "no se pudo leer la clave maestra de api/config.php"
    s, d = pedir("/claves.php?accion=listar", clave=m)
    return s == 200 and d.get("ok"), f"status={s}"


@caso("Ciclo completo de una clave: crear, usar, limitar, desactivar, borrar")
def ciclo_de_vida():
    m = clave_maestra()
    if not m:
        return False, "sin clave maestra"

    # crear con permiso de sólo lectura
    s, d = pedir("/claves.php", "POST", m, {"nombre": "PRUEBA AUTOMATICA", "permisos": ["leer"]})
    if s != 201 or not d.get("clave"):
        return False, f"no se pudo crear: {s} {d}"
    nueva, kid = d["clave"], d["id"]
    pasos = []

    try:
        # formato
        import re
        pasos.append(("formato", bool(re.fullmatch(r"ith_[0-9a-f]{32}", nueva))))

        # puede leer
        s, _ = pedir("/estado.php", clave=nueva)
        pasos.append(("puede leer", s == 200))

        # no puede crear
        s, _ = pedir("/remitos.php", "POST", nueva, {"cliente": {"razonSocial": "X"}})
        pasos.append(("no puede crear", s == 403))

        # no puede administrar
        s, _ = pedir("/claves.php?accion=listar", clave=nueva)
        pasos.append(("no puede administrar", s == 403))

        # se registra el uso
        s, d = pedir("/claves.php?accion=listar", clave=m)
        c = next((x for x in d.get("claves", []) if x["id"] == kid), None)
        pasos.append(("cuenta usos", bool(c and c["usos"] >= 1)))
        pasos.append(("no expone la clave", bool(c and nueva not in str(c))))

        # desactivar corta el acceso al instante
        pedir("/claves.php", "POST", m, {"id": kid, "activa": False})
        s, _ = pedir("/estado.php", clave=nueva)
        pasos.append(("desactivada no entra", s == 403))
    finally:
        pedir(f"/claves.php?id={kid}", "DELETE", m)

    # borrada de verdad
    s, _ = pedir("/estado.php", clave=nueva)
    pasos.append(("borrada no entra", s == 401))

    fallan = [n for n, ok in pasos if not ok]
    return not fallan, f"fallan={fallan}" if fallan else f"{len(pasos)} verificaciones OK"


@caso("La clave completa nunca vuelve a viajar después de crearla")
def clave_no_se_reexpone():
    m = clave_maestra()
    if not m:
        return False, "sin clave maestra"
    s, d = pedir("/claves.php", "POST", m, {"nombre": "PRUEBA FUGA", "permisos": ["leer"]})
    if s != 201:
        return False, f"no se pudo crear: {s}"
    nueva, kid = d["clave"], d["id"]
    try:
        s, lista = pedir("/claves.php?accion=listar", clave=m)
        s2, log = pedir("/claves.php?accion=registro&limite=50", clave=m)
        fuga = nueva in str(lista) or nueva in str(log)
        return not fuga, "la clave aparece en el listado o el registro" if fuga else "no se reexpone"
    finally:
        pedir(f"/claves.php?id={kid}", "DELETE", m)


@caso("El registro de actividad anota también los rechazos")
def registro_anota_rechazos():
    m = clave_maestra()
    if not m:
        return False, "sin clave maestra"
    pedir("/estado.php", clave="ith_" + "f" * 32)          # provoca un rechazo
    s, d = pedir("/claves.php?accion=registro&categoria=acceso", clave=m)
    hay = any(x.get("categoria") == "acceso" and x.get("nivel") == "error"
              for x in d.get("movimientos", []))
    return hay, "hay rechazos registrados" if hay else "el registro no anotó el rechazo"


@caso("Los timestamps de la auditoría están en hora de Buenos Aires")
def hora_buenos_aires():
    from datetime import datetime, timezone, timedelta
    m = clave_maestra()
    if not m:
        return False, "sin clave maestra"
    # provoca un evento (acceso rechazado) y lee su fecha
    pedir("/estado.php", clave="ith_" + "e" * 32)
    s, d = pedir("/claves.php?accion=registro&por_pagina=1", clave=m)
    movs = d.get("movimientos", [])
    if not movs:
        return False, "no se registró el evento"
    fecha = movs[0]["fecha"]
    try:
        fh = datetime.strptime(fecha, "%Y-%m-%d %H:%M:%S")
    except Exception:
        return False, f"formato de fecha inesperado: {fecha!r}"
    ba = (datetime.now(timezone.utc) + timedelta(hours=-3)).replace(tzinfo=None)
    diff = abs((fh - ba).total_seconds())
    return diff < 180, f"fecha log={fecha} vs BA={ba:%Y-%m-%d %H:%M} (dif {diff:.0f}s)"


@caso("Las lecturas de rutina no ensucian la auditoría")
def lecturas_no_registran():
    """Error corregido: en el panel, cada refresco/lectura se registraba como actividad."""
    m = clave_maestra()
    if not m:
        return False, "sin clave maestra"
    antes = pedir("/claves.php?accion=registro&por_pagina=1", clave=m)[1].get("total", 0)
    for _ in range(6):
        pedir("/estado.php", clave=m)
        pedir("/talonarios.php?accion=vigente&pv=1&tipo=91", clave=m)
    despues = pedir("/claves.php?accion=registro&por_pagina=1", clave=m)[1].get("total", 0)
    return antes == despues, f"total antes={antes} después de 12 lecturas={despues}"


@caso("La auditoría se pagina y filtra por categoría")
def auditoria_paginada():
    m = clave_maestra()
    if not m:
        return False, "sin clave maestra"
    s, d = pedir("/claves.php?accion=registro&por_pagina=5&pagina=1", clave=m)
    tiene = ("paginas" in d and "por_categoria" in d and "total" in d)
    s2, d2 = pedir("/claves.php?accion=registro&categoria=error", clave=m)
    solo_error = all(x.get("nivel") == "error" for x in d2.get("movimientos", []))
    return tiene and solo_error, f"paginado={tiene} filtro_error_ok={solo_error}"


@caso("Crear una clave sin nombre o sin permisos se rechaza")
def validacion_de_alta():
    m = clave_maestra()
    if not m:
        return False, "sin clave maestra"
    s1, _ = pedir("/claves.php", "POST", m, {"nombre": "", "permisos": ["leer"]})
    s2, _ = pedir("/claves.php", "POST", m, {"nombre": "Sin permisos", "permisos": []})
    s3, _ = pedir("/claves.php", "POST", m, {"nombre": "Permiso raro", "permisos": ["borrar_todo"]})
    return s1 == 400 and s2 == 400 and s3 == 400, f"sin_nombre={s1} sin_permisos={s2} permiso_invalido={s3}"
