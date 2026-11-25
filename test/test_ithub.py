"""Pruebas del ingreso con ITHub, la sesión única de Intellihelp (api/ithub.php,
api/ithub-logout.php y lib/ithub.php), de punta a punta contra un ITHub simulado.

Necesitan una segunda instancia de la aplicación con la sección `ithub` en su
configuración (con ITHub configurado, la aplicación ya no ingresa con Google, así que
no puede ser la misma del resto de la batería):

    REMITOS_ITHUB_URL=http://127.0.0.1:8767        instancia con ITHub (misma base de pruebas)
    REMITOS_ITHUB_PUERTO=8768                      puerto del ITHub simulado (por omisión)

    'ithub' => ['client_id' => 'remitos', 'client_secret' => 'secreto-de-pruebas',
                'issuer' => 'http://127.0.0.1:8768'],

Sin REMITOS_ITHUB_URL el módulo se omite (y lo informa). El ITHub simulado firma los
tokens con RS256 con una clave generada en el momento y publica la pública en
/oauth/jwks: la aplicación valida la firma de verdad.
"""
import base64
import hashlib
import http.cookiejar
import http.server
import json
import os
import secrets
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import padding, rsa

import base
from base import caso, clave_maestra

SITIO = os.environ.get("REMITOS_ITHUB_URL", "").rstrip("/")
API = SITIO + "/api"
PUERTO = int(os.environ.get("REMITOS_ITHUB_PUERTO", "8768"))
EMISOR = f"http://127.0.0.1:{PUERTO}"
CLIENTE = "remitos"
EVENTO = "http://schemas.openid.net/event/backchannel-logout"
NUEVO = "ithub.nuevo@intellihelp.tech"
OTRO = "ithub.otro@intellihelp.tech"

_clave = rsa.generate_private_key(public_exponent=65537, key_size=2048)
_kid = "k-" + secrets.token_hex(4)  # nuevo en cada corrida: la aplicación vuelve a bajar las claves


def _b64(datos):
    return base64.urlsafe_b64encode(datos).rstrip(b"=").decode()


def _entero(n):
    return _b64(n.to_bytes((n.bit_length() + 7) // 8, "big"))


def firmar(claims, kid=None, clave=None):
    """JWT RS256 como los de ITHub."""
    h = _b64(json.dumps({"alg": "RS256", "typ": "JWT", "kid": kid or _kid}).encode())
    p = _b64(json.dumps(claims).encode())
    firma = (clave or _clave).sign(f"{h}.{p}".encode(), padding.PKCS1v15(), hashes.SHA256())
    return f"{h}.{p}.{_b64(firma)}"


def _claims(email, rol, nonce, sid, extra=None):
    ahora = int(time.time())
    c = {"iss": EMISOR, "aud": CLIENTE, "sub": hashlib.sha256(email.encode()).hexdigest()[:8], "sid": sid,
         "email": email, "email_verified": True, "name": email.split("@")[0].replace(".", " ").title(),
         "hd": "intellihelp.tech", "rol": rol, "roles": [rol], "nonce": nonce, "iat": ahora, "exp": ahora + 300,
         "auth_time": ahora}
    c.update(extra or {})
    return c


class _IthubSimulado(http.server.BaseHTTPRequestHandler):
    """/oauth/token y /oauth/jwks. El código de autorización trae (en JSON, base64url) el
    correo, el rol, el nonce, el sid y el reto PKCE del pedido, más lo que una prueba quiera
    alterar del token."""

    def do_GET(self):
        if self.path.startswith("/oauth/jwks"):
            pub = _clave.public_key().public_numbers()
            return self._responder(200, {"keys": [{"kty": "RSA", "kid": _kid, "alg": "RS256", "use": "sig",
                                                   "n": _entero(pub.n), "e": _entero(pub.e)}]})
        self._responder(404, {})

    def do_POST(self):
        largo = int(self.headers.get("Content-Length") or 0)
        form = urllib.parse.parse_qs(self.rfile.read(largo).decode())
        campo = lambda k: (form.get(k) or [""])[0]  # noqa: E731
        esperado = "Basic " + base64.b64encode(b"remitos:secreto-de-pruebas").decode()
        if self.headers.get("Authorization") != esperado:
            return self._responder(401, {"error": "invalid_client"})
        try:
            pedido = json.loads(base64.urlsafe_b64decode(campo("code") + "=" * (-len(campo("code")) % 4)))
        except Exception:
            return self._responder(400, {"error": "invalid_grant"})
        reto = _b64(hashlib.sha256(campo("code_verifier").encode()).digest())
        if campo("grant_type") != "authorization_code" or reto != pedido.get("challenge") \
                or not campo("redirect_uri").endswith("/api/ithub.php"):
            return self._responder(400, {"error": "invalid_grant"})
        claims = _claims(pedido["email"], pedido["rol"], pedido["nonce"], pedido["sid"], pedido.get("claims"))
        token = firmar(claims, clave=rsa.generate_private_key(public_exponent=65537, key_size=2048)
                       if pedido.get("firma_ajena") else None)
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


_servidor = None


def _iniciar():
    global _servidor
    if _servidor is None:
        _servidor = http.server.ThreadingHTTPServer(("127.0.0.1", PUERTO), _IthubSimulado)
        threading.Thread(target=_servidor.serve_forever, daemon=True).start()


class _SinRedireccion(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Navegador:
    """Cliente HTTP con cookies contra la instancia con ITHub."""

    def __init__(self):
        self.op = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), _SinRedireccion)
        self.csrf = ""
        self.sid = ""

    def get(self, url):
        try:
            with self.op.open(url, timeout=25) as r:
                return r.status, r.headers.get("Location", ""), r.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.headers.get("Location", ""), e.read().decode()

    def api(self, ruta, metodo="GET", cuerpo=None):
        req = urllib.request.Request(API + ruta, method=metodo,
                                     data=json.dumps(cuerpo).encode() if cuerpo is not None else None)
        req.add_header("Content-Type", "application/json")
        if self.csrf:
            req.add_header("X-CSRF-Token", self.csrf)
        try:
            with self.op.open(req, timeout=25) as r:
                return r.status, json.loads(r.read().decode())
        except urllib.error.HTTPError as e:
            return e.code, json.loads(e.read().decode() or "{}")

    def ingresar(self, email, rol="operador", claims=None, firma_ajena=False, confirmar=False, error=None, state=None):
        """Recorre el ingreso con ITHub. Devuelve (Location del pedido a ITHub, a dónde volvió)."""
        _iniciar()
        _, autorizar, _ = self.get(API + "/ithub.php?accion=inicio" + ("&confirmar=1" if confirmar else ""))
        q = urllib.parse.parse_qs(urllib.parse.urlparse(autorizar).query)
        self.sid = secrets.token_hex(16)
        pedido = {"email": email, "rol": rol, "nonce": q["nonce"][0], "sid": self.sid,
                  "challenge": q["code_challenge"][0], "claims": claims or {}, "firma_ajena": firma_ajena}
        vuelta = "state=" + urllib.parse.quote(state or q["state"][0])
        vuelta += "&error=" + error if error else "&code=" + _b64(json.dumps(pedido).encode())
        _, destino, _ = self.get(API + "/ithub.php?" + vuelta)
        _, d = self.api("/sesion.php")
        self.csrf = d.get("csrf", "")
        return autorizar, destino


def _error(destino):
    return urllib.parse.parse_qs(urllib.parse.urlparse(destino).query).get("ingreso", [""])[0]


def _maestra(ruta, metodo="GET", cuerpo=None):
    req = urllib.request.Request(API + ruta, method=metodo,
                                 data=json.dumps(cuerpo).encode() if cuerpo is not None else None)
    req.add_header("Content-Type", "application/json")
    req.add_header("X-API-Key", clave_maestra())
    try:
        with urllib.request.urlopen(req, timeout=25) as r:
            return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode() or "{}")


def _usuario(email):
    _, d = _maestra("/usuarios.php")
    return next((u for u in d.get("usuarios", []) if u["email"] == email), None)


def _borrar(email):
    u = _usuario(email)
    if u:
        _maestra(f"/usuarios.php?id={u['id']}", "DELETE")


def _aviso(token):
    """POST del aviso de cierre, como lo hace el servidor de ITHub (sin cookie ni CSRF)."""
    datos = urllib.parse.urlencode({"logout_token": token}).encode()
    try:
        with urllib.request.urlopen(urllib.request.Request(API + "/ithub-logout.php", data=datos), timeout=25) as r:
            return r.status
    except urllib.error.HTTPError as e:
        return e.code


def _logout_token(sid, extra=None):
    ahora = int(time.time())
    c = {"iss": EMISOR, "aud": CLIENTE, "sub": "1", "sid": sid, "iat": ahora, "exp": ahora + 120,
         "jti": secrets.token_hex(8), "events": {EVENTO: {}}}
    c.update(extra or {})
    return firmar(c)


if not SITIO:
    @caso("Ingreso con ITHub (se omite sin REMITOS_ITHUB_URL, ver test/LEEME.md)")
    def omitido():
        return True, "omitido: falta REMITOS_ITHUB_URL (instancia con la sección ithub)"
else:
    if base.es_local():
        base._sql("DELETE FROM intentos")

    @caso("Con ITHub configurado, la sesión lo informa y Google ya no ingresa por su cuenta")
    def configuracion():
        n = Navegador()
        _, d = n.api("/sesion.php")
        _, destino, _ = n.get(API + "/google.php?accion=inicio&confirmar=1")
        _, vuelta, _ = n.get(API + "/google.php?state=x&code=y")
        ok = d.get("ithub") is True and d.get("google") is False \
            and destino.endswith("/api/ithub.php?accion=inicio&confirmar=1") and vuelta.endswith("/")
        return ok, f"sesion={d} google→{destino} vuelta→{vuelta}"

    @caso("Ingresar manda a /oauth/authorize de ITHub con PKCE S256, state, nonce y la URI de vuelta")
    def autorizar():
        n = Navegador()
        estado, destino, _ = n.get(API + "/ithub.php?accion=inicio")
        q = urllib.parse.parse_qs(urllib.parse.urlparse(destino).query)
        ok = estado == 302 and destino.startswith(EMISOR + "/oauth/authorize?") \
            and q.get("code_challenge_method") == ["S256"] and q.get("client_id") == [CLIENTE] \
            and q.get("redirect_uri") == [API + "/ithub.php"] and q.get("state") and q.get("nonce") \
            and "client_secret" not in q
        return ok, f"{estado} {destino}"

    @caso("Primer ingreso: crea el usuario con el rol que manda ITHub y abre la sesión")
    def primer_ingreso():
        _borrar(NUEVO)
        n = Navegador()
        _, destino = n.ingresar(NUEVO, "operador")
        _, d = n.api("/sesion.php")
        u = d.get("usuario") or {}
        ok = _error(destino) == "" and u.get("email") == NUEVO and u.get("rol") == "operador" \
            and d.get("ithub") is True and u.get("confirmado") is True
        return ok, f"vuelta={destino} sesion={d}"

    @caso("El rol lo decide ITHub: cambia en el ingreso siguiente y un usuario desactivado se reactiva")
    def rol_de_ithub():
        n = Navegador()
        n.ingresar(NUEVO, "admin")
        rol1 = (n.api("/sesion.php")[1].get("usuario") or {}).get("rol")
        u = _usuario(NUEVO)
        _maestra("/usuarios.php", "POST", {"accion": "editar", "id": u["id"], "activo": False})
        n2 = Navegador()
        n2.ingresar(NUEVO, "operador")
        d = n2.api("/sesion.php")[1].get("usuario") or {}
        return rol1 == "admin" and d.get("rol") == "operador" and _usuario(NUEVO)["activo"] is True, f"{rol1} → {d}"

    @caso("Un rol que la aplicación no conoce, un token mal firmado o para otra aplicación no abren sesión")
    def rechazos():
        res = {}
        for nombre, kw in {
            "rol": {"rol": "superadmin"},
            "firma": {"firma_ajena": True},
            "aud": {"claims": {"aud": "crm"}},
            "iss": {"claims": {"iss": "https://malicioso.example"}},
            "vencido": {"claims": {"exp": int(time.time()) - 600}},
            "nonce": {"claims": {"nonce": "otro"}},
        }.items():
            n = Navegador()
            _, destino = n.ingresar(OTRO, **kw)
            res[nombre] = (_error(destino), n.api("/sesion.php")[1].get("usuario"))
        ok = res["rol"][0] == "ithub_rol" and all(v[0] == "ithub_error" for k, v in res.items() if k != "rol") \
            and all(v[1] is None for v in res.values()) and _usuario(OTRO) is None
        return ok, str(res)

    @caso("Sin acceso en ITHub o con el estado manipulado se vuelve al ingreso con el aviso")
    def sin_acceso():
        n = Navegador()
        _, a = n.ingresar(OTRO, error="access_denied")
        _, b = n.ingresar(OTRO, state="manipulado")
        return _error(a) == "ithub_sin_acceso" and _error(b) == "ithub_vencido", f"{a} · {b}"

    @caso("Salir cierra la sesión única: responde la salida de ITHub con id_token_hint y la vuelta registrada")
    def salir():
        n = Navegador()
        n.ingresar(NUEVO, "operador")
        _, d = n.api("/sesion.php", "POST", {"accion": "salir"})
        q = urllib.parse.parse_qs(urllib.parse.urlparse(d.get("salida", "")).query)
        sigue = n.api("/sesion.php")[1].get("usuario")
        ok = d.get("salida", "").startswith(EMISOR + "/oauth/logout?") and q.get("id_token_hint") \
            and q.get("post_logout_redirect_uri") == [SITIO + "/"] and sigue is None
        return ok, str(d)

    @caso("El aviso de cierre de ITHub corta la sesión con ese sid; uno inválido responde 400")
    def aviso_de_cierre():
        n = Navegador()
        n.ingresar(NUEVO, "operador")
        antes = n.api("/sesion.php")[1].get("usuario") is not None
        basura = _aviso("basura")
        id_token = _aviso(firmar(_claims(NUEVO, "operador", "n", n.sid)))  # un id_token no es un aviso
        ajeno = _aviso(_logout_token(n.sid, {"aud": "crm"}))
        sigue = n.api("/sesion.php")[1].get("usuario") is not None
        valido = _aviso(_logout_token(n.sid))
        despues = n.api("/sesion.php")[1].get("usuario")
        try:
            with urllib.request.urlopen(API + "/ithub-logout.php", timeout=25) as r:
                get = r.status
        except urllib.error.HTTPError as e:
            get = e.code
        ok = antes and sigue and basura == 400 and id_token == 400 and ajeno == 400 and valido == 200 \
            and despues is None and get == 400
        return ok, f"antes={antes} basura={basura} id_token={id_token} ajeno={ajeno} sigue={sigue} " \
                   f"válido={valido} después={despues} GET={get}"

    @caso("Reconfirmar la identidad exige la misma cuenta de la sesión")
    def reconfirmar():
        n = Navegador()
        n.ingresar(NUEVO, "admin")
        _, otra = n.ingresar(OTRO, "admin", confirmar=True)
        sigue = (n.api("/sesion.php")[1].get("usuario") or {}).get("email")
        _, misma = n.ingresar(NUEVO, "admin", confirmar=True)
        _borrar(NUEVO)
        _borrar(OTRO)
        return _error(otra) == "ithub_otra_cuenta" and sigue == NUEVO and _error(misma) == "confirmado", \
            f"otra={otra} sigue={sigue} misma={misma}"
