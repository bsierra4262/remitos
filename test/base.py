"""Piezas comunes de la batería de pruebas.

Las pruebas corren contra el entorno local (`php -S` + base de pruebas), porque
crean y borran datos. Para apuntar a otro entorno de pruebas:

    REMITOS_URL=https://pruebas.ejemplo/remitos
    REMITOS_MASTER_KEY=<clave maestra de ese entorno>

Se ingresa solo con Google. Las pruebas usan un Google simulado (un servidor local que
hace de endpoint de tokens, `token_url` en la configuración de pruebas) y recorren el
flujo real: la redirección a Google, la cookie de estado, el canje del código con PKCE
y la validación del token. Los usuarios de prueba se crean con la clave maestra.
"""
import base64
import hashlib
import http.server
import json
import os
import re
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
APP = os.path.join(RAIZ, "app")
SITIO = os.environ.get("REMITOS_URL", "http://127.0.0.1:8765").rstrip("/")
CLAVE_MAESTRA_LOCAL = "ith_test_master_key_0123456789abcdef"
API = SITIO + "/api"
ADMIN_EMAIL = "admin.pruebas@intellihelp.tech"
DOMINIO = "intellihelp.tech"
GOOGLE_PUERTO = int(os.environ.get("REMITOS_GOOGLE_PUERTO", "8766"))

_casos = []


def caso(nombre):
    """Registra una prueba. La función devuelve (ok, detalle)."""
    def deco(fn):
        _casos.append((nombre, fn))
        return fn
    return deco


def registrados():
    return list(_casos)


# --------------------------------------------------------------------------- #
#  Versión publicada
# --------------------------------------------------------------------------- #
def version():
    """Lee la versión de cache-busting del index para pegarle al build actual."""
    s = open(os.path.join(APP, "index.html"), encoding="utf-8").read()
    return re.search(r"app\.js\?v=(\d+)", s).group(1)


def url_app():
    return f"{SITIO}/?v={version()}"


# --------------------------------------------------------------------------- #
#  Navegador
# --------------------------------------------------------------------------- #
def abrir(pw, ancho=1500, alto=1000, capturar_print=True, email=None):
    """Abre la app con la sesión iniciada (con Google). Devuelve (browser, page, errores)."""
    errores = []
    b = pw.chromium.launch()
    ctx = b.new_context(viewport={"width": ancho, "height": alto})
    pg = ctx.new_page()
    pg.on("pageerror", lambda e: errores.append(str(e)))
    pg.on("console",
          lambda m: errores.append("console: " + m.text) if m.type == "error" else None)
    if email is None:
        asegurar_admin()
        email = ADMIN_EMAIL

    def get(url):
        r = ctx.request.get(url, max_redirects=0)
        return r.status, r.headers

    destino = ingresar_con_google(get, email)
    if "ingreso=" in destino:
        raise RuntimeError(f"No se pudo ingresar con {email}: {destino}")
    pg.goto(url_app(), wait_until="networkidle", timeout=60000)
    pg.wait_for_selector(".navbar", state="visible", timeout=15000)
    pg.wait_for_timeout(600)
    if capturar_print:
        # window.print() bloquearía; lo anulamos y anotamos qué se iba a imprimir
        pg.evaluate("""() => {
            window.__imp = [];
            window.print = () => window.__imp.push({
                vista: document.body.getAttribute('data-view'),
                tipo: (typeof compDoc !== 'undefined' && compDoc) ? compDoc.tipo : null,
                titulo: document.title,
                ejemplares: [...document.querySelectorAll('#printCopies .copy-page, #compPrintCopies .copy-page')]
                    .map(e => (e.dataset.copia || '?') + ':' +
                              (e.classList.contains('copy-beige') ? 'beige' : 'blanco')),
            });
        }""")
    return b, pg, errores


def ir_a(pg, vista, espera=700):
    pg.click(f"button.nav-item[data-view={vista}]")
    pg.wait_for_timeout(espera)


def modo_documento(pg, modo, espera=600):
    """modo: 'overlay' (sobre pre-impreso) o 'full' (documento completo)."""
    pg.evaluate("""(m) => [...document.querySelectorAll('input[name=docMode],[data-mode]')]
        .find(e => (e.value || e.dataset.mode) === m).click()""", modo)
    pg.wait_for_timeout(espera)


# --------------------------------------------------------------------------- #
#  API
# --------------------------------------------------------------------------- #
def clave_maestra():
    """Clave maestra del entorno bajo prueba (config.php solo guarda su hash)."""
    if "REMITOS_MASTER_KEY" in os.environ:
        return os.environ["REMITOS_MASTER_KEY"]
    return CLAVE_MAESTRA_LOCAL if es_local() else None


def es_local():
    return SITIO.startswith(("http://127.0.0.1", "http://localhost"))


def pedir(ruta, metodo="GET", clave=None, cuerpo=None, timeout=25, cabeceras=False):
    """Llama a la API. Devuelve (status, dict) o, con cabeceras=True, (status, dict, headers)."""
    req = urllib.request.Request(API + ruta, method=metodo)
    if clave:
        req.add_header("X-API-Key", clave)
    data = None
    if cuerpo is not None:
        data = json.dumps(cuerpo).encode()
        req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, data, timeout=timeout) as r:
            res = (r.status, json.loads(r.read().decode()), dict(r.headers))
    except urllib.error.HTTPError as e:
        crudo = e.read().decode()
        try:
            res = (e.code, json.loads(crudo), dict(e.headers))
        except Exception:
            res = (e.code, {"crudo": crudo[:300]}, dict(e.headers))
    except Exception as e:
        res = (0, {"error": str(e)}, {})
    return res if cabeceras else res[:2]


# --------------------------------------------------------------------------- #
#  Usuarios y datos de prueba
# --------------------------------------------------------------------------- #
_admin_listo = False


def asegurar_usuario(email, nombre, rol):
    """Crea (o reactiva con ese rol) un usuario de pruebas.

    En la base local se borran los intentos fallidos acumulados: todas las pruebas
    salen de 127.0.0.1 y, si no, el freno de ingresos por conexión las cortaría."""
    if es_local():
        _sql("DELETE FROM intentos")
    m = clave_maestra()
    _, lista = pedir("/usuarios.php", clave=m)
    existente = next((u for u in lista.get("usuarios", []) if u["email"] == email), None)
    if existente:
        pedir("/usuarios.php", "POST", m, {"accion": "editar", "id": existente["id"], "rol": rol, "activo": True})
        return existente["id"]
    _, d = pedir("/usuarios.php", "POST", m, {"nombre": nombre, "email": email, "rol": rol})
    return d["usuario"]["id"]


def asegurar_admin():
    global _admin_listo
    if not _admin_listo:
        asegurar_usuario(ADMIN_EMAIL, "Admin de Pruebas", "admin")
        _admin_listo = True


# --------------------------------------------------------------------------- #
#  Google simulado
# --------------------------------------------------------------------------- #
def _b64(datos):
    return base64.urlsafe_b64encode(datos).rstrip(b"=").decode()


class _GoogleSimulado(http.server.BaseHTTPRequestHandler):
    """Endpoint de tokens de Google para las pruebas. El código de autorización trae
    (en JSON, base64url) el correo y el nonce del pedido, más los datos que una prueba
    quiera alterar: así cada caso arma la respuesta de Google que necesita."""

    def do_POST(self):
        largo = int(self.headers.get("Content-Length") or 0)
        form = urllib.parse.parse_qs(self.rfile.read(largo).decode())
        campo = lambda k: (form.get(k) or [""])[0]  # noqa: E731
        try:
            pedido = json.loads(base64.urlsafe_b64decode(campo("code") + "=" * (-len(campo("code")) % 4)))
        except Exception:
            return self._responder(400, {"error": "invalid_grant"})
        reto = _b64(hashlib.sha256(campo("code_verifier").encode()).digest())
        if campo("grant_type") != "authorization_code" or reto != pedido.get("challenge") or not campo("client_secret"):
            return self._responder(400, {"error": "invalid_grant"})
        ahora = int(time.time())
        email = pedido["email"]
        claims = {"iss": "https://accounts.google.com", "aud": campo("client_id"), "iat": ahora, "exp": ahora + 3600,
                  "nonce": pedido["nonce"], "email": email, "email_verified": True, "hd": email.split("@")[-1],
                  "sub": hashlib.sha256(email.encode()).hexdigest()[:21]}
        claims.update(pedido.get("claims", {}))
        token = "e30." + _b64(json.dumps(claims).encode()) + ".firma"
        self._responder(200, {"id_token": token, "access_token": "simulado", "token_type": "Bearer", "expires_in": 3600})

    def _responder(self, estado, cuerpo):
        datos = json.dumps(cuerpo).encode()
        self.send_response(estado)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(datos)))
        self.end_headers()
        self.wfile.write(datos)

    def log_message(self, *args):
        pass


_google = None


def iniciar_google_simulado():
    global _google
    if _google is None:
        _google = http.server.ThreadingHTTPServer(("127.0.0.1", GOOGLE_PUERTO), _GoogleSimulado)
        threading.Thread(target=_google.serve_forever, daemon=True).start()


def _cabecera(headers, nombre):
    return next((v for k, v in headers.items() if k.lower() == nombre.lower()), "")


def ingresar_con_google(get, email, claims=None, reto=None):
    """Recorre el ingreso con Google. `get(url)` hace un GET sin seguir redirecciones,
    con las cookies del cliente, y devuelve (status, headers). Devuelve a dónde redirige
    la vuelta (la aplicación, o la aplicación con ?ingreso=<error>)."""
    iniciar_google_simulado()
    _, h = get(API + "/google.php?accion=inicio")
    q = urllib.parse.parse_qs(urllib.parse.urlparse(_cabecera(h, "Location")).query)
    pedido = {"email": email, "nonce": q["nonce"][0], "challenge": reto or q["code_challenge"][0], "claims": claims or {}}
    codigo = _b64(json.dumps(pedido).encode())
    _, h = get(API + "/google.php?state=" + q["state"][0] + "&code=" + codigo)
    return _cabecera(h, "Location")


def limpiar_datos():
    """Borra remitos, comprobantes y clientes del entorno de pruebas (solo local)."""
    if not es_local():
        raise RuntimeError("limpiar_datos solo se usa en el entorno local")
    m = clave_maestra()
    for ruta, campo in (("/remitos.php?por_pagina=200", "remitos"), ("/comprobantes.php?por_pagina=200", "comprobantes")):
        while True:
            _, d = pedir(ruta, clave=m)
            items = d.get(campo, [])
            if not items:
                break
            for x in items:
                base = ruta.split("?")[0]
                s, _ = pedir(f"{base}?id={x['id']}", "DELETE", m)
                if s == 409:  # remito autorizado: se elimina directo en la base de pruebas
                    _sql(f"DELETE FROM remitos WHERE id = '{x['id']}'")
    _, d = pedir("/clientes.php", clave=m)
    for c in d.get("clientes", []):
        pedir(f"/clientes.php?id={c['id']}", "DELETE", m)


def _sql(sentencia):
    """Ejecuta SQL en la base local de pruebas (solo para limpiar datos que la API protege)."""
    import subprocess
    cliente = os.environ.get("REMITOS_MYSQL", "mariadb")
    subprocess.run([cliente, "-h", "127.0.0.1", "-P", os.environ.get("REMITOS_DB_PORT", "3307"), "-uroot",
                    "-p" + os.environ.get("REMITOS_DB_PASS", "testroot"), os.environ.get("REMITOS_DB", "remitos_test"),
                    "-e", sentencia], check=True, capture_output=True)


# --------------------------------------------------------------------------- #
#  Talonarios y remitos completos (compartidos por test_cai y test_normativa)
# --------------------------------------------------------------------------- #
from datetime import date, timedelta  # noqa: E402

VTO = (date.today() + timedelta(days=200)).isoformat()


def _talonario(desde=1, hasta=50, cai="75041234567890", vto=VTO, pv=1):
    if es_local() and desde == 1:  # restos de una corrida interrumpida en ese punto de venta
        _sql(f"DELETE FROM remitos WHERE punto_venta = {int(pv)}")
        _sql(f"DELETE FROM cai_talonarios WHERE punto_venta = {int(pv)}")
    return pedir("/talonarios.php", "POST", clave_maestra(), {
        "punto_venta": pv, "numero_desde": desde, "numero_hasta": hasta,
        "cai": cai, "cai_vencimiento": vto})


def _borrar_talonario(tid):
    """Un talonario usado no se puede borrar por la API (es respaldo de los remitos
    autorizados): en la base local de pruebas se limpian directo con SQL."""
    if not tid:
        return
    if es_local():
        _sql(f"DELETE r FROM remitos r JOIN cai_talonarios t ON r.punto_venta = t.punto_venta "
             f"AND r.numero_int BETWEEN t.numero_desde AND t.numero_hasta WHERE t.id = {int(tid)}")
        _sql(f"DELETE FROM cai_talonarios WHERE id = {int(tid)}")
    else:
        pedir("/talonarios.php", "POST", clave_maestra(), {"id": tid, "activa": False})


# Remito con todos los datos que exige la RG 1415 (Anexo V) para poder autorizarse.
REMITO_COMPLETO = {
    "cliente": {"razonSocial": "PARA AUTORIZAR", "cuit": "30-50001091-2", "condIVA": "Responsable Inscripto",
                "domicilio": "Av. Montes de Oca 1450"},
    "items": [{"articulo": "1", "descripcion": "Servicio técnico", "cantidad": "2 hs"}],
    "transporte": {"tipo": "propio"},
}
DOC_COMPLETO_JS = """Object.assign(doc.cliente, { razonSocial: 'CLIENTE AUTORIZADO', cuit: '30-50001091-2',
    condIVA: 'Responsable Inscripto', domicilio: 'Av. Montes de Oca 1450' });
    doc.items = [{ articulo: '1', descripcion: 'Servicio técnico', cantidad: '2 hs' }];
    doc.transporte.tipo = 'propio';"""


def _remito(nombre="PARA AUTORIZAR"):
    cuerpo = dict(REMITO_COMPLETO, cliente=dict(REMITO_COMPLETO["cliente"], razonSocial=nombre))
    return pedir("/remitos.php", "POST", clave_maestra(), cuerpo)[1]["id"]
