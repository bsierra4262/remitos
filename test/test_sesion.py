"""Pruebas de usuarios y sesión: ingreso con Google, cookie segura, CSRF, roles,
datos compartidos entre usuarios y numeración asignada por el servidor.

Se ingresa solo con Google: las pruebas usan el Google simulado de base.py y recorren
el flujo real. Crean sus propios usuarios y datos en el entorno de pruebas.
"""
import concurrent.futures
import http.cookiejar
import json
import urllib.error
import urllib.parse
import urllib.request

from playwright.sync_api import sync_playwright

import base
from base import (ADMIN_EMAIL, API, abrir, asegurar_admin, asegurar_usuario, caso, clave_maestra, ingresar_con_google,
                  ir_a, pedir)

OPERADOR = ("operador.pruebas@intellihelp.tech", "Operador de Pruebas")


class _SinRedireccion(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Sesion:
    """Cliente HTTP con cookies, como un navegador."""

    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), _SinRedireccion)
        self.csrf = ""
        self.cookies = []

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

    def get(self, url):
        """GET sin seguir redirecciones: (status, headers)."""
        try:
            with self.op.open(url, timeout=25) as r:
                return r.status, dict(r.headers)
        except urllib.error.HTTPError as e:
            self.cookies = e.headers.get_all("Set-Cookie") or []
            return e.code, dict(e.headers)

    def ingresar(self, email, **kw):
        """Ingresa con Google. Devuelve a dónde volvió (con ?ingreso=<error> si falló)."""
        destino = ingresar_con_google(self.get, email, **kw)
        _, d, _ = self.pedir("/sesion.php")
        self.csrf = d.get("csrf", "")
        return destino


def _admin():
    asegurar_admin()
    s = Sesion()
    s.ingresar(ADMIN_EMAIL)
    return s


def _error(destino):
    return urllib.parse.parse_qs(urllib.parse.urlparse(destino).query).get("ingreso", [""])[0]


@caso("Sin sesión la aplicación muestra el ingreso y la API no entrega datos")
def sin_sesion():
    with sync_playwright() as pw:
        b = pw.chromium.launch()
        pg = b.new_page()
        try:
            pg.goto(base.url_app(), wait_until="networkidle")
            pg.wait_for_timeout(600)
            ingreso = pg.is_visible("#ingresoGoogle")
            sin_clave = pg.locator("#acceso input").count() == 0  # solo el botón de Google
            app = pg.is_visible(".navbar")
        finally:
            b.close()
    estados = {r: pedir(r)[0] for r in ("/clientes.php", "/remitos.php", "/comprobantes.php", "/ajustes.php")}
    ok = ingreso and sin_clave and not app and all(v == 401 for v in estados.values())
    return ok, f"boton_google={ingreso} sin_campos_de_contraseña={sin_clave} app={app} api={estados}"


@caso("La cookie de sesión es HttpOnly y SameSite=Strict, y cerrar sesión la invalida")
def cookie_segura():
    s = _admin()
    otra = Sesion()
    otra.ingresar(ADMIN_EMAIL)
    cookie = next((c for c in otra.cookies if c.startswith("remitos_sid=")), "")
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


@caso("Se ingresa con Google y la aplicación no tiene contraseñas ni segundo factor")
def ingreso_solo_google():
    asegurar_usuario(*OPERADOR, "operador")
    s = Sesion()
    destino = s.ingresar(OPERADOR[0])
    _, d, _ = s.pedir("/sesion.php")
    datos = s.pedir("/clientes.php")[0]
    viejas = {a: s.pedir("/sesion.php", "POST", {"accion": a, "email": OPERADOR[0], "clave": "x", "codigo": "123456"})[0]
              for a in ("ingresar", "verificar_codigo", "cambiar_clave", "totp_iniciar")}
    _, lista = pedir("/usuarios.php", clave=clave_maestra())
    campos = set().union(*(u.keys() for u in lista.get("usuarios", [])))
    restos = campos & {"clave_temporal", "debe_cambiar", "segundo_factor", "bloqueado", "clave_hash"}
    _, alta = pedir("/usuarios.php", "POST", clave_maestra(), {"nombre": "X", "email": "x.pruebas@intellihelp.tech", "rol": "operador"})
    if alta.get("usuario"):
        pedir(f"/usuarios.php?id={alta['usuario']['id']}", "DELETE", clave_maestra())
    usuario = d.get("usuario") or {}
    ok = (_error(destino) == "" and usuario.get("email") == OPERADOR[0] and usuario.get("confirmado") is True
          and datos == 200 and set(viejas.values()) == {400} and not restos and "clave_temporal" not in alta)
    return ok, f"vuelta={destino} usuario={usuario.get('email')} datos={datos} acciones_viejas={viejas} restos={restos}"


@caso("Google: sin usuario, de otro dominio, sin verificar, de otra aplicación o con PKCE ajeno no ingresa")
def ingreso_google_rechazos():
    asegurar_usuario(*OPERADOR, "operador")
    casos = {
        "sin usuario": ("nadie.pruebas@intellihelp.tech", {}, None),
        "otro dominio": ("operador.pruebas@gmail.com", {"hd": "gmail.com"}, None),
        "sin hd": (OPERADOR[0], {"hd": ""}, None),
        "sin verificar": (OPERADOR[0], {"email_verified": False}, None),
        "otra aplicación": (OPERADOR[0], {"aud": "otra.apps.googleusercontent.com"}, None),
        "vencido": (OPERADOR[0], {"exp": 1000, "iat": 900}, None),
        "PKCE ajeno": (OPERADOR[0], {}, "reto-que-no-corresponde"),
    }
    resultados = {}
    for etq, (email, claims, reto) in casos.items():
        s = Sesion()
        destino = s.ingresar(email, claims=claims, reto=reto)
        resultados[etq] = (_error(destino), s.pedir("/clientes.php")[0])
    esperados = {"sin usuario": "google_sin_usuario", "otro dominio": "google_dominio", "sin hd": "google_dominio"}
    ok = all(r[1] == 401 and r[0] == esperados.get(e, "google_error") for e, r in resultados.items())
    asegurar_usuario(*OPERADOR, "operador")  # borra los intentos fallidos acumulados
    return ok, f"{resultados}"


@caso("Solo se dan de alta correos de Intellihelp, y reconfirmar con Google vuelve con la cuenta de la sesión")
def alta_dominio_y_reconfirmacion():
    m = clave_maestra()
    ajeno = pedir("/usuarios.php", "POST", m, {"nombre": "Ajeno", "email": "alguien@gmail.com", "rol": "operador"})[0]
    s = _admin()
    _, h = s.get(API + "/google.php?accion=inicio&confirmar=1")
    q = urllib.parse.parse_qs(urllib.parse.urlparse(h.get("Location", "")).query)
    sugerida = q.get("login_hint") == [ADMIN_EMAIL] and "prompt" not in q
    s2 = Sesion()
    _, h2 = s2.get(API + "/google.php?accion=inicio")
    q2 = urllib.parse.parse_qs(urllib.parse.urlparse(h2.get("Location", "")).query)
    elegir = q2.get("prompt") == ["select_account"] and "login_hint" not in q2
    ok = ajeno == 400 and sugerida and elegir
    return ok, f"alta_gmail={ajeno} reconfirmar_con_la_cuenta={sugerida} ingreso_elige_cuenta={elegir}"


@caso("El operador trabaja con documentos pero no administra usuarios, ajustes ni certificados")
def permisos_operador():
    asegurar_usuario(*OPERADOR, "operador")
    s = Sesion()
    s.ingresar(OPERADOR[0])
    guarda, d, _ = s.pedir("/remitos.php", "POST", {"cliente": {"razonSocial": "DEL OPERADOR"}})
    usuarios = s.pedir("/usuarios.php")[0]
    ajustes = s.pedir("/ajustes.php", "POST", {"seccion": "remito", "valores": {"ptoVenta": "9"}})[0]
    cert = s.pedir("/arca.php", "POST", {"accion": "quitar", "ambiente": "homologacion"})[0]
    if d.get("id"):
        pedir(f"/remitos.php?id={d['id']}", "DELETE", clave_maestra())
    with sync_playwright() as pw:
        b, pg, _ = abrir(pw, email=OPERADOR[0])
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
    asegurar_usuario(*OPERADOR, "operador")
    with sync_playwright() as pw:
        b1, admin, _ = abrir(pw)
        b2, operador, _ = abrir(pw, email=OPERADOR[0])
        try:
            ir_a(admin, "clientes")
            admin.click("#btnNuevoCliente")
            admin.fill("#cliRazon", "CLIENTE COMPARTIDO S.A.")
            admin.fill("#cliCuit", "30-55555555-1")
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
        if c["cuit"] == "30-55555555-1":
            pedir(f"/clientes.php?id={c['id']}", "DELETE", clave_maestra())
    return visto and "CLIENTE COMPARTIDO" not in local, f"visto_por_operador={visto} en_localStorage={'CLIENTE COMPARTIDO' in local}"


@caso("Dos usuarios guardando a la vez nunca reciben la misma referencia, y el borrador no tiene número fiscal")
def numeracion_concurrente():
    m = clave_maestra()

    def alta(i):
        return pedir("/remitos.php", "POST", m, {"cliente": {"razonSocial": f"CONCURRENTE {i}"}, "numero": "00000001"})[1]

    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as ex:
        res = list(ex.map(alta, range(12)))
    refs = [r.get("remito", {}).get("referencia") for r in res]
    numeros = {r.get("remito", {}).get("numero") for r in res}
    for r in res:
        if r.get("id"):
            pedir(f"/remitos.php?id={r['id']}", "DELETE", m)
    ok = len(set(refs)) == 12 and None not in refs and numeros == {""}
    return ok, f"referencias={sorted(x for x in refs if x)} números={numeros}"


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
    email = "desactivado.pruebas@intellihelp.tech"
    asegurar_usuario(email, "Desactivado de Pruebas", "operador")
    s = Sesion()
    s.ingresar(email)
    antes = s.pedir("/clientes.php")[0]
    _, lista = pedir("/usuarios.php", clave=clave_maestra())
    uid = next(u["id"] for u in lista["usuarios"] if u["email"] == email)
    pedir("/usuarios.php", "POST", clave_maestra(), {"accion": "editar", "id": uid, "activo": False})
    despues = s.pedir("/clientes.php")[0]
    reingreso = _error(s.ingresar(email))
    sin_datos = s.pedir("/clientes.php")[0]
    pedir(f"/usuarios.php?id={uid}", "DELETE", clave_maestra())
    ok = antes == 200 and despues == 401 and reingreso == "google_sin_usuario" and sin_datos == 401
    return ok, f"antes={antes} después={despues} reingreso={reingreso} datos={sin_datos}"


@caso("Los datos que la versión anterior dejó en el navegador se suben al servidor al ingresar")
def traspaso_de_datos_locales():
    with sync_playwright() as pw:
        b, pg, errores = abrir(pw)
        try:
            pg.evaluate("""() => {
                localStorage.setItem('remitos_clientes', JSON.stringify([{ razonSocial: 'CLIENTE LOCAL VIEJO', cuit: '30-77777777-3' }]));
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
    cli = [x for x in c.get("clientes", []) if x["cuit"] == "30-77777777-3"]
    rem = [x for x in r.get("remitos", []) if x["cliente"]["razonSocial"] == "REMITO LOCAL VIEJO"]
    for x in cli:
        pedir(f"/clientes.php?id={x['id']}", "DELETE", m)
    for x in rem:
        pedir(f"/remitos.php?id={x['id']}", "DELETE", m)
    ok = len(cli) == 1 and len(rem) == 1 and quedan == [None, None]
    return ok, f"clientes={len(cli)} remitos={len(rem)} quedan_en_navegador={quedan}"


@caso("Cambiar el rol de un usuario cierra sus sesiones abiertas")
def cambio_de_rol_cierra_sesiones():
    email = "sesiones.pruebas@intellihelp.tech"
    uid = asegurar_usuario(email, "Sesiones de Pruebas", "operador")
    a, b = Sesion(), Sesion()
    a.ingresar(email)
    b.ingresar(email)
    antes = [a.pedir("/clientes.php")[0], b.pedir("/clientes.php")[0]]
    st = pedir("/usuarios.php", "POST", clave_maestra(), {"accion": "editar", "id": uid, "rol": "admin"})[0]
    despues = [a.pedir("/clientes.php")[0], b.pedir("/clientes.php")[0]]
    a.ingresar(email)
    _, d, _ = a.pedir("/sesion.php")
    pedir(f"/usuarios.php?id={uid}", "DELETE", clave_maestra())
    ok = antes == [200, 200] and st == 200 and despues == [401, 401] and (d.get("usuario") or {}).get("rol") == "admin"
    return ok, f"antes={antes} cambio={st} después={despues} nuevo_rol={(d.get('usuario') or {}).get('rol')}"


@caso("La sesión rechaza formularios de otro origen o que no son JSON")
def ingreso_mismo_origen():
    cuerpo = json.dumps({"accion": "salir"}).encode()

    def post(tipo, origen=None):
        req = urllib.request.Request(API + "/sesion.php", data=cuerpo, method="POST")
        req.add_header("Content-Type", tipo)
        if origen:
            req.add_header("Origin", origen)
        try:
            with urllib.request.urlopen(req, timeout=20) as r:
                return r.status
        except urllib.error.HTTPError as e:
            return e.code

    formulario = post("text/plain")
    otro = post("application/json", "https://sitio-malicioso.example")
    propio = post("application/json", base.SITIO)
    return formulario == 415 and otro == 403 and propio == 200, f"text/plain={formulario} otro_origen={otro} propio={propio}"


@caso("Ingreso con Google: va a Google con PKCE y dominio, y una vuelta manipulada no abre sesión")
def ingreso_google():
    """El entorno de pruebas tiene un cliente de Google ficticio: se prueba todo lo que
    ocurre de este lado (la redirección, la cookie de estado y el rechazo de una vuelta
    sin estado válido). El canje con Google se prueba en producción."""
    import urllib.parse

    class SinRedireccion(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *args, **kwargs):
            return None

    jar = http.cookiejar.CookieJar()
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), SinRedireccion)

    def get(ruta):
        try:
            with op.open(API + ruta, timeout=20) as r:
                return r.status, dict(r.headers)
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers)

    s1, h1 = get("/google.php?accion=inicio")
    destino = urllib.parse.urlparse(h1.get("Location", ""))
    q = urllib.parse.parse_qs(destino.query)
    cookie = h1.get("Set-Cookie", "")
    s2, h2 = get("/google.php?state=otro&code=abc")
    s3, h3 = get("/google.php?accion=inicio")
    sin_cookie = urllib.request.build_opener(SinRedireccion)
    try:
        sin_cookie.open(API + "/google.php?state=" + q.get("state", [""])[0] + "&code=abc", timeout=20)
        s4, h4 = 200, {}
    except urllib.error.HTTPError as e:
        s4, h4 = e.code, dict(e.headers)
    _, sesion = pedir("/sesion.php")
    ok = (s1 == 302 and destino.netloc == "accounts.google.com" and q.get("code_challenge_method") == ["S256"]
          and q.get("hd") == ["intellihelp.tech"] and "samesite=lax" in cookie.lower() and "httponly" in cookie.lower()
          and s2 == 302 and "ingreso=google_vencido" in h2.get("Location", "")
          and s4 == 302 and "ingreso=google_vencido" in h4.get("Location", "") and sesion.get("google") is True)
    return ok, f"inicio={s1} {destino.netloc} cookie={cookie[:60]!r} vuelta_manipulada={h2.get('Location')} sin_cookie={h4.get('Location')}"
