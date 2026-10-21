"""Piezas comunes de la batería de pruebas.

Por defecto las pruebas corren contra el entorno local (`php -S` + base de
pruebas). Para apuntar a otro entorno:

    REMITOS_URL=https://intellihelp.tech/remitos
    REMITOS_MASTER_KEY=<clave maestra de ese entorno>
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
def abrir(pw, ancho=1500, alto=1000, capturar_print=True):
    """Abre la app publicada. Devuelve (browser, page, errores)."""
    errores = []
    b = pw.chromium.launch()
    pg = b.new_page(viewport={"width": ancho, "height": alto})
    pg.on("pageerror", lambda e: errores.append(str(e)))
    pg.on("console",
          lambda m: errores.append("console: " + m.text) if m.type == "error" else None)
    pg.goto(url_app(), wait_until="networkidle", timeout=60000)
    pg.wait_for_timeout(1200)
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
    pg.click(f"button[data-view={vista}]")
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
