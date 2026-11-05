"""Piezas comunes de la batería de pruebas.

Las pruebas corren contra el entorno local (`php -S` + base de pruebas), porque
crean y borran datos. Para apuntar a otro entorno de pruebas:

    REMITOS_URL=https://pruebas.ejemplo/remitos
    REMITOS_MASTER_KEY=<clave maestra de ese entorno>

Las pruebas de interfaz ingresan con un administrador de pruebas que se crea
solo (ADMIN_EMAIL), usando la clave maestra.
"""
import json
import os
import re
import urllib.error
import urllib.request

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
APP = os.path.join(RAIZ, "app")
SITIO = os.environ.get("REMITOS_URL", "http://127.0.0.1:8765").rstrip("/")
CLAVE_MAESTRA_LOCAL = "ith_test_master_key_0123456789abcdef"
API = SITIO + "/api"
ADMIN_EMAIL = "admin.pruebas@intellihelp.test"
ADMIN_CLAVE = "Brujula-Norte-58Tq"

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
def abrir(pw, ancho=1500, alto=1000, capturar_print=True, email=None, clave=None):
    """Abre la app con la sesión iniciada. Devuelve (browser, page, errores)."""
    errores = []
    b = pw.chromium.launch()
    ctx = b.new_context(viewport={"width": ancho, "height": alto})
    pg = ctx.new_page()
    pg.on("pageerror", lambda e: errores.append(str(e)))
    pg.on("console",
          lambda m: errores.append("console: " + m.text) if m.type == "error" else None)
    if email is None:
        asegurar_admin()
        email, clave = ADMIN_EMAIL, ADMIN_CLAVE
    r = ctx.request.post(API + "/sesion.php", data={"accion": "ingresar", "email": email, "clave": clave})
    if r.status != 200:
        raise RuntimeError(f"No se pudo ingresar con {email}: {r.status} {r.text()[:200]}")
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


def asegurar_usuario(email, nombre, rol, clave):
    """Crea (o restablece) un usuario con contraseña conocida y sin cambio pendiente.

    En la base local se borran los intentos fallidos acumulados: todas las pruebas
    salen de 127.0.0.1 y, si no, el freno de ingresos por conexión las cortaría."""
    import http.cookiejar
    if es_local():
        _sql("DELETE FROM intentos")
    m = clave_maestra()
    _, lista = pedir("/usuarios.php", clave=m)
    existente = next((u for u in lista.get("usuarios", []) if u["email"] == email), None)
    if existente:
        _, d = pedir("/usuarios.php", "POST", m, {"accion": "restablecer", "id": existente["id"]})
        pedir("/usuarios.php", "POST", m, {"accion": "editar", "id": existente["id"], "rol": rol, "activo": True})
    else:
        _, d = pedir("/usuarios.php", "POST", m, {"nombre": nombre, "email": email, "rol": rol})
    temporal = d["clave_temporal"]
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def post(cuerpo, csrf=""):
        req = urllib.request.Request(API + "/sesion.php", data=json.dumps(cuerpo).encode(), method="POST")
        req.add_header("Content-Type", "application/json")
        if csrf:
            req.add_header("X-CSRF-Token", csrf)
        with op.open(req, timeout=20) as r:
            return json.loads(r.read().decode())

    sesion = post({"accion": "ingresar", "email": email, "clave": temporal})
    post({"accion": "cambiar_clave", "actual": temporal, "nueva": clave}, sesion["csrf"])


def asegurar_admin():
    global _admin_listo
    if not _admin_listo:
        asegurar_usuario(ADMIN_EMAIL, "Admin de Pruebas", "admin", ADMIN_CLAVE)
        _admin_listo = True


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
