"""Controles de seguridad y de datos que no tenían cobertura: integridad de la auditoría,
freno de claves de API, exportación, registro de CAE externo, CORS e importación de clientes.

Algunos casos alteran la base local de pruebas con SQL (y la dejan como estaba): solo
corren en el entorno local.
"""
import urllib.error
import urllib.request

from base import API, _sql, caso, clave_maestra, es_local, pedir


def _clave_servicio(permisos):
    _, d = pedir("/claves.php", "POST", clave_maestra(), {"nombre": "PRUEBA CONTROLES", "permisos": permisos})
    return d.get("clave"), d.get("id")


def _borrar_clave(kid):
    if kid:
        pedir(f"/claves.php?id={kid}", "DELETE", clave_maestra())


@caso("La verificación de integridad detecta un evento de auditoría alterado")
def auditoria_alterada():
    if not es_local():
        return True, "solo en el entorno local (altera la base)"
    m = clave_maestra()
    pedir("/clientes.php", "POST", m, {"razonSocial": "EVENTO PARA AUDITAR"})  # garantiza un evento reciente
    _, antes = pedir("/claves.php?accion=integridad", clave=m)
    _sql("UPDATE api_log SET accion = CONCAT(accion, '#') ORDER BY id DESC LIMIT 1")
    try:
        _, despues = pedir("/claves.php?accion=integridad", clave=m)
    finally:
        _sql("UPDATE api_log SET accion = LEFT(accion, CHAR_LENGTH(accion) - 1) WHERE accion LIKE '%#'")
    _, otra_vez = pedir("/claves.php?accion=integridad", clave=m)
    _, lista = pedir("/clientes.php", clave=m)
    for c in lista.get("clientes", []):
        if c.get("razonSocial") == "EVENTO PARA AUDITAR":
            pedir(f"/clientes.php?id={c['id']}", "DELETE", m)
    i0, i1, i2 = (x.get("integridad", {}) for x in (antes, despues, otra_vez))
    falla = (i1.get("falla") or {}).get("motivo")
    ok = i0.get("ok") is True and i1.get("ok") is False and falla == "evento alterado" and i2.get("ok") is True
    return ok, f"antes={i0.get('ok')} alterado={i1.get('ok')} ({falla}) restaurado={i2.get('ok')}"


@caso("Tras 20 claves de API rechazadas desde una conexión, la siguiente responde 429")
def freno_claves_api():
    if not es_local():
        return True, "solo en el entorno local (bloquea la conexión)"
    _sql("DELETE FROM intentos WHERE tipo = 'clave_api'")
    try:
        codigos = [pedir("/clientes.php", clave=f"ith_invalida_{i:02d}")[0] for i in range(20)]
        s, d = pedir("/clientes.php", clave="ith_invalida_final")
        ok = set(codigos) == {401} and s == 429
        return ok, f"rechazos={sorted(set(codigos))} siguiente={s} {d.get('error', '')[:60]}"
    finally:
        _sql("DELETE FROM intentos WHERE tipo = 'clave_api'")


@caso("Exportar los datos exige administrador; una clave de servicio sin «admin» recibe 403")
def exportar_permisos():
    llave, kid = _clave_servicio(["leer", "crear", "borrar"])
    try:
        s_serv, _ = pedir("/exportar.php", clave=llave)
        s_admin, d = pedir("/exportar.php", clave=clave_maestra())
        _, reg = pedir("/claves.php?accion=registro&por_pagina=5", clave=clave_maestra())
        auditado = any("xport" in (x.get("accion") or "") for x in reg.get("movimientos", []))
        ok = s_serv == 403 and s_admin == 200 and isinstance(d.get("docs"), list) and isinstance(d.get("comprobantes"), list) and auditado
        return ok, f"servicio={s_serv} admin={s_admin} auditado={auditado}"
    finally:
        _borrar_clave(kid)


@caso("Registrar un CAE obtenido fuera del sistema es solo para administradores")
def registrar_cae_permiso():
    llave, kid = _clave_servicio(["leer", "crear"])
    try:
        s, _ = pedir("/comprobantes.php", "POST", llave,
                     {"accion": "registrar_cae", "id": "0123456789abcdef", "numero": "1", "cae": "12345678901234",
                      "caeVto": "31/12/2026"})
        return s == 403, f"clave de servicio={s}"
    finally:
        _borrar_clave(kid)


@caso("CORS: un origen no autorizado no recibe Access-Control-Allow-Origin")
def cors_origen_ajeno():
    req = urllib.request.Request(API + "/estado.php", method="GET")
    req.add_header("Origin", "https://sitio-ajeno.example")
    req.add_header("X-API-Key", clave_maestra())
    try:
        with urllib.request.urlopen(req, timeout=15) as r:
            cab = dict(r.headers)
    except urllib.error.HTTPError as e:
        cab = dict(e.headers)
    acao = {k.lower(): v for k, v in cab.items()}.get("access-control-allow-origin")
    return acao is None, f"Access-Control-Allow-Origin={acao!r}"


@caso("Importar clientes: un CUIT inválido se informa y no se carga; sin CUIT, reimportar no duplica")
def importar_clientes_validacion():
    m = clave_maestra()
    lista = [{"razonSocial": "CUIT MALO SA", "cuit": "20-12345678-0"},
             {"razonSocial": "SIN CUIT PRUEBA SRL", "cuit": ""}]
    _, d1 = pedir("/clientes.php", "POST", m, {"accion": "importar", "clientes": lista})
    _, d2 = pedir("/clientes.php", "POST", m, {"accion": "importar", "clientes": lista})
    _, todos = pedir("/clientes.php", clave=m)
    nombres = [c.get("razonSocial") for c in todos.get("clientes", [])]
    for c in todos.get("clientes", []):
        if c.get("razonSocial") in ("CUIT MALO SA", "SIN CUIT PRUEBA SRL"):
            pedir(f"/clientes.php?id={c['id']}", "DELETE", m)
    invalido = any("CUIT MALO" in x for x in d1.get("cuit_invalidos", []))
    ok = (invalido and "CUIT MALO SA" not in nombres and nombres.count("SIN CUIT PRUEBA SRL") == 1
          and d1.get("agregados") == 1 and d2.get("actualizados") == 1)
    return ok, f"invalido={invalido} sin_cuit={nombres.count('SIN CUIT PRUEBA SRL')} 1ra={d1} 2da={d2}"


@caso("Una factura recuerda el remito que factura; otro tipo de comprobante no lo guarda")
def factura_vinculada_a_remito():
    m = clave_maestra()
    base = {"letra": "B", "ptoVenta": "00001", "cliente": {"razonSocial": "VINCULO REMITO", "condIVA": "Consumidor Final"},
            "items": [{"descripcion": "Servicio", "cantidad": "1", "precio": "100"}], "remitoId": "0123456789abcdef"}
    _, f = pedir("/comprobantes.php", "POST", m, dict(base, tipo="factura"))
    _, n = pedir("/comprobantes.php", "POST", m, dict(base, tipo="nota", subtipo="credito"))
    _, x = pedir("/comprobantes.php", "POST", m, dict(base, tipo="factura", remitoId="no-es-un-id"))
    ids = [d.get("comprobante", {}).get("id") for d in (f, n, x)]
    for i in ids:
        if i:
            pedir(f"/comprobantes.php?id={i}", "DELETE", m)
    rf, rn, rx = (d.get("comprobante", {}).get("remitoId") for d in (f, n, x))
    ok = rf == "0123456789abcdef" and not rn and not rx
    return ok, f"factura={rf!r} nota={rn!r} id_invalido={rx!r}"
