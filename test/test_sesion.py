"""Pruebas de usuarios y sesión: ingreso, bloqueo, cookie segura, CSRF, roles,
datos compartidos entre usuarios y numeración asignada por el servidor.

Crean sus propios usuarios y datos en el entorno de pruebas.
"""
import concurrent.futures
import http.cookiejar
import json
import urllib.error
import urllib.request

from playwright.sync_api import sync_playwright

import base
from base import ADMIN_CLAVE, ADMIN_EMAIL, API, abrir, asegurar_admin, asegurar_usuario, caso, clave_maestra, ir_a, pedir

OPERADOR = ("operador.pruebas@intellihelp.test", "Operador de Pruebas", "Operador2026x")


class Sesion:
    """Cliente HTTP con cookies, como un navegador."""

    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = ""

    def pedir(self, ruta, metodo="GET", cuerpo=None, csrf=True):
        req = urllib.request.Request(API + ruta, method=metodo,
                                     data=json.dumps(cuerpo).encode() if cuerpo is not None else None)
        req.add_header("Content-Type", "application/json")
        if csrf and self.csrf:
            req.add_header("X-CSRF-Token", self.csrf)
        try:
            with self.op.open(req, timeout=25) as r:
                return r.status, json.loads(r.read().decode()), dict(r.headers)
        except urllib.error.HTTPError as e:
            return e.code, json.loads(e.read().decode() or "{}"), dict(e.headers)

    def ingresar(self, email, clave):
        s, d, h = self.pedir("/sesion.php", "POST", {"accion": "ingresar", "email": email, "clave": clave})
        self.csrf = d.get("csrf", "")
        return s, d, h


def _admin():
    asegurar_admin()
    s = Sesion()
    s.ingresar(ADMIN_EMAIL, ADMIN_CLAVE)
    return s


@caso("Sin sesión la aplicación muestra el ingreso y la API no entrega datos")
def sin_sesion():
    with sync_playwright() as pw:
        b = pw.chromium.launch()
        pg = b.new_page()
        try:
            pg.goto(base.url_app(), wait_until="networkidle")
            pg.wait_for_timeout(600)
            ingreso = pg.is_visible("#formIngreso")
            app = pg.is_visible(".navbar")
        finally:
            b.close()
    estados = {r: pedir(r)[0] for r in ("/clientes.php", "/remitos.php", "/comprobantes.php", "/ajustes.php")}
    ok = ingreso and not app and all(v == 401 for v in estados.values())
    return ok, f"ingreso={ingreso} app={app} api={estados}"


@caso("La cookie de sesión es HttpOnly y SameSite=Strict, y cerrar sesión la invalida")
def cookie_segura():
    s = _admin()
    _, _, h = Sesion().ingresar(ADMIN_EMAIL, ADMIN_CLAVE)
    cookie = h.get("Set-Cookie", "")
    flags = "httponly" in cookie.lower() and "samesite=strict" in cookie.lower()
    antes = s.pedir("/clientes.php")[0]
    s.pedir("/sesion.php", "POST", {"accion": "salir"})
    despues = s.pedir("/clientes.php")[0]
    return flags and antes == 200 and despues == 401, f"cookie={cookie[:90]!r} antes={antes} despues={despues}"


@caso("Una escritura con sesión pero sin token CSRF se rechaza")
def csrf_obligatorio():
    s = _admin()
    sin, _, _ = s.pedir("/clientes.php", "POST", {"razonSocial": "CSRF"}, csrf=False)
    con, d, _ = s.pedir("/clientes.php", "POST", {"razonSocial": "CON TOKEN CSRF"})
    if d.get("cliente"):
        s.pedir(f"/clientes.php?id={d['cliente']['id']}", "DELETE")
    return sin == 403 and con == 201, f"sin_token={sin} con_token={con}"


@caso("Cinco contraseñas incorrectas bloquean la cuenta y el mensaje no revela si existe")
def bloqueo_por_intentos():
    email = "bloqueo.pruebas@intellihelp.test"
    asegurar_usuario(email, "Bloqueo de Pruebas", "operador", "Bloqueo2026x")
    s = Sesion()
    malos = [s.ingresar(email, "incorrecta")[1].get("error") for _ in range(5)]
    _, d, _ = s.ingresar(email, "Bloqueo2026x")
    bloqueada = "bloqueada" in (d.get("error") or "")
    inexistente = s.ingresar("no.existe@intellihelp.test", "incorrecta")[1].get("error")
    asegurar_usuario(email, "Bloqueo de Pruebas", "operador", "Bloqueo2026x")  # deja la cuenta usable
    ok = bloqueada and malos[0] == inexistente == "Correo o contraseña incorrectos"
    return ok, f"bloqueada={bloqueada} mensaje={malos[0]!r} inexistente={inexistente!r}"


@caso("La contraseña temporal obliga a cambiarla y se aplica la política de contraseñas")
def clave_temporal():
    m = clave_maestra()
    email = "temporal.pruebas@intellihelp.test"
    _, lista = pedir("/usuarios.php", clave=m)
    previo = next((u for u in lista.get("usuarios", []) if u["email"] == email), None)
    if previo:
        pedir(f"/usuarios.php?id={previo['id']}", "DELETE", m)
    _, d = pedir("/usuarios.php", "POST", m, {"nombre": "Temporal", "email": email, "rol": "operador"})
    temporal = d["clave_temporal"]
    s = Sesion()
    s.ingresar(email, temporal)
    bloqueado = s.pedir("/clientes.php")[0]
    corta = s.pedir("/sesion.php", "POST", {"accion": "cambiar_clave", "actual": temporal, "nueva": "abc12"})[0]
    sin_numeros = s.pedir("/sesion.php", "POST", {"accion": "cambiar_clave", "actual": temporal, "nueva": "solamenteletras"})[0]
    st, d2, _ = s.pedir("/sesion.php", "POST", {"accion": "cambiar_clave", "actual": temporal, "nueva": "Definitiva2026"})
    s.csrf = d2.get("csrf", "")
    habilitado = s.pedir("/clientes.php")[0]
    pedir(f"/usuarios.php?id={d['usuario']['id']}", "DELETE", m)
    ok = len(temporal) >= 12 and bloqueado == 403 and corta == 422 and sin_numeros == 422 and st == 200 and habilitado == 200
    return ok, f"bloqueado={bloqueado} corta={corta} sin_numeros={sin_numeros} cambio={st} despues={habilitado}"


@caso("El operador trabaja con documentos pero no administra usuarios, ajustes ni certificados")
def permisos_operador():
    asegurar_usuario(*OPERADOR[:2], "operador", OPERADOR[2])
    s = Sesion()
    s.ingresar(OPERADOR[0], OPERADOR[2])
    guarda, d, _ = s.pedir("/remitos.php", "POST", {"cliente": {"razonSocial": "DEL OPERADOR"}})
    usuarios = s.pedir("/usuarios.php")[0]
    ajustes = s.pedir("/ajustes.php", "POST", {"seccion": "remito", "valores": {"ptoVenta": "9"}})[0]
    cert = s.pedir("/arca.php", "POST", {"accion": "quitar", "ambiente": "homologacion"})[0]
    if d.get("id"):
        pedir(f"/remitos.php?id={d['id']}", "DELETE", clave_maestra())
    with sync_playwright() as pw:
        b, pg, _ = abrir(pw, email=OPERADOR[0], clave=OPERADOR[2])
        try:
            ir_a(pg, "config")
            pestaña = pg.is_visible(".cfg-tab[data-tab=usuarios]")
            pg.click(".cfg-tab[data-tab=api]")
            deshabilitado = pg.evaluate("() => document.querySelector('#apiCuit').disabled")
        finally:
            b.close()
    ok = guarda == 201 and usuarios == 403 and ajustes == 403 and cert == 403 and not pestaña and deshabilitado
    return ok, f"guarda={guarda} usuarios={usuarios} ajustes={ajustes} cert={cert} pestaña={pestaña} campos_bloqueados={deshabilitado}"


@caso("Los datos son compartidos: lo que carga un usuario lo ve otro en otro equipo")
def datos_compartidos():
    asegurar_usuario(*OPERADOR[:2], "operador", OPERADOR[2])
    with sync_playwright() as pw:
        b1, admin, _ = abrir(pw)
        b2, operador, _ = abrir(pw, email=OPERADOR[0], clave=OPERADOR[2])
        try:
            ir_a(admin, "clientes")
            admin.click("#btnNuevoCliente")
            admin.fill("#cliRazon", "CLIENTE COMPARTIDO S.A.")
            admin.fill("#cliCuit", "30-55555555-5")
            admin.click("#cliSave")
            admin.wait_for_timeout(1000)
            operador.reload(wait_until="networkidle")
            operador.wait_for_selector(".navbar", state="visible")
            ir_a(operador, "clientes")
            visto = "CLIENTE COMPARTIDO S.A." in operador.inner_text("#cliGrid")
            local = operador.evaluate("() => JSON.stringify(localStorage)")
        finally:
            b1.close()
            b2.close()
    _, d = pedir("/clientes.php", clave=clave_maestra())
    for c in d.get("clientes", []):
        if c["cuit"] == "30-55555555-5":
            pedir(f"/clientes.php?id={c['id']}", "DELETE", clave_maestra())
    return visto and "CLIENTE COMPARTIDO" not in local, f"visto_por_operador={visto} en_localStorage={'CLIENTE COMPARTIDO' in local}"


@caso("Dos usuarios guardando a la vez nunca reciben el mismo número de remito")
def numeracion_concurrente():
    m = clave_maestra()

    def alta(i):
        return pedir("/remitos.php", "POST", m, {"cliente": {"razonSocial": f"CONCURRENTE {i}"}})[1]

    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as ex:
        res = list(ex.map(alta, range(12)))
    numeros = [r.get("remito", {}).get("numero") for r in res]
    for r in res:
        if r.get("id"):
            pedir(f"/remitos.php?id={r['id']}", "DELETE", m)
    return len(set(numeros)) == 12 and None not in numeros, f"números={sorted(numeros)}"


@caso("El sistema nunca queda sin administradores")
def siempre_un_admin():
    s = _admin()
    _, d, _ = s.pedir("/sesion.php")
    yo = d["usuario"]["id"]
    quitar_rol = s.pedir("/usuarios.php", "POST", {"accion": "editar", "id": yo, "rol": "operador"})[0]
    desactivarme = s.pedir("/usuarios.php", "POST", {"accion": "editar", "id": yo, "activo": False})[0]
    borrarme = s.pedir(f"/usuarios.php?id={yo}", "DELETE")[0]
    return quitar_rol == desactivarme == borrarme == 409, f"quitar_rol={quitar_rol} desactivar={desactivarme} borrar={borrarme}"


@caso("Un usuario desactivado pierde el acceso en el acto")
def desactivado_sin_acceso():
    email = "desactivado.pruebas@intellihelp.test"
    asegurar_usuario(email, "Desactivado de Pruebas", "operador", "Desactivado2026")
    s = Sesion()
    s.ingresar(email, "Desactivado2026")
    antes = s.pedir("/clientes.php")[0]
    _, lista = pedir("/usuarios.php", clave=clave_maestra())
    uid = next(u["id"] for u in lista["usuarios"] if u["email"] == email)
    pedir("/usuarios.php", "POST", clave_maestra(), {"accion": "editar", "id": uid, "activo": False})
    despues = s.pedir("/clientes.php")[0]
    reingreso = s.ingresar(email, "Desactivado2026")[0]
    pedir(f"/usuarios.php?id={uid}", "DELETE", clave_maestra())
    return antes == 200 and despues == 401 and reingreso == 401, f"antes={antes} después={despues} reingreso={reingreso}"


@caso("Los datos que la versión anterior dejó en el navegador se suben al servidor al ingresar")
def traspaso_de_datos_locales():
    with sync_playwright() as pw:
        b, pg, errores = abrir(pw)
        try:
            pg.evaluate("""() => {
                localStorage.setItem('remitos_clientes', JSON.stringify([{ razonSocial: 'CLIENTE LOCAL VIEJO', cuit: '30-77777777-7' }]));
                localStorage.setItem('remitos_docs', JSON.stringify([{ id: 'viejo01', numero: '00000005',
                    cliente: { razonSocial: 'REMITO LOCAL VIEJO' }, items: [{ descripcion: 'x' }] }]));
            }""")
            pg.on("dialog", lambda d: d.accept())
            pg.reload(wait_until="networkidle")
            pg.wait_for_selector(".navbar", state="visible")
            pg.wait_for_timeout(2000)
            quedan = pg.evaluate("() => [localStorage.getItem('remitos_clientes'), localStorage.getItem('remitos_docs')]")
        finally:
            b.close()
    m = clave_maestra()
    _, c = pedir("/clientes.php", clave=m)
    _, r = pedir("/remitos.php?por_pagina=200", clave=m)
    cli = [x for x in c.get("clientes", []) if x["cuit"] == "30-77777777-7"]
    rem = [x for x in r.get("remitos", []) if x["cliente"]["razonSocial"] == "REMITO LOCAL VIEJO"]
    for x in cli:
        pedir(f"/clientes.php?id={x['id']}", "DELETE", m)
    for x in rem:
        pedir(f"/remitos.php?id={x['id']}", "DELETE", m)
    ok = len(cli) == 1 and len(rem) == 1 and quedan == [None, None]
    return ok, f"clientes={len(cli)} remitos={len(rem)} quedan_en_navegador={quedan}"
