"""Pruebas de seguridad de la API: credenciales de ARCA, validación de datos,
cabeceras y protección de los archivos internos.

Escriben en la base del entorno bajo prueba (por defecto, la local) y limpian
lo que crean.
"""
import datetime
import urllib.parse
import os
import tempfile

from base import SITIO, caso, clave_maestra, es_local, pedir

AMBIENTE = "homologacion"
CUIT = "20123456786"


def _certificado(cuit=CUIT, dias=30, bits=2048):
    """Par certificado / clave autofirmado con el CUIT en el campo serialNumber."""
    from cryptography import x509
    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.asymmetric import rsa
    from cryptography.x509.oid import NameOID

    clave = rsa.generate_private_key(public_exponent=65537, key_size=bits)
    nombre = x509.Name([
        x509.NameAttribute(NameOID.COUNTRY_NAME, "AR"),
        x509.NameAttribute(NameOID.ORGANIZATION_NAME, "PRUEBA"),
        x509.NameAttribute(NameOID.COMMON_NAME, "prueba"),
        x509.NameAttribute(NameOID.SERIAL_NUMBER, f"CUIT {cuit}"),
    ])
    ahora = datetime.datetime.now(datetime.timezone.utc)
    desde = ahora - datetime.timedelta(days=2)
    hasta = ahora + datetime.timedelta(days=dias)
    cert = (x509.CertificateBuilder().subject_name(nombre).issuer_name(nombre)
            .public_key(clave.public_key()).serial_number(x509.random_serial_number())
            .not_valid_before(desde).not_valid_after(hasta).sign(clave, hashes.SHA256()))
    pem_cert = cert.public_bytes(serialization.Encoding.PEM).decode()
    pem_clave = clave.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8,
                                    serialization.NoEncryption()).decode()
    return pem_cert, pem_clave


def _cargar(cert, clave, llave=None, **extra):
    cuerpo = {"accion": "cargar", "ambiente": AMBIENTE, "certificado": cert, "clave": clave, **extra}
    return pedir("/arca.php", "POST", llave or clave_maestra(), cuerpo)


def _clave_servicio(permisos):
    s, d = pedir("/claves.php", "POST", clave_maestra(), {"nombre": "PRUEBA SEGURIDAD", "permisos": permisos})
    return d.get("clave"), d.get("id")


@caso("ARCA: un certificado válido se guarda y nunca se devuelve")
def arca_carga_valida():
    cert, clave = _certificado()
    try:
        s, d = _cargar(cert, clave, cuit="20-12345678-6")
        if s != 201:
            return False, f"no se cargó: {s} {d}"
        s, estado = pedir("/arca.php", clave=clave_maestra())
        info = estado.get("ambientes", {}).get(AMBIENTE) or {}
        crudo = str(d) + str(estado)
        fuga = "BEGIN" in crudo or clave.split("\n")[1] in crudo
        ok = info.get("cuit") == CUIT and len(info.get("huella", "")) == 64 and not fuga
        return ok, f"cuit={info.get('cuit')} huella={info.get('huella', '')[:12]}… fuga={fuga}"
    finally:
        pedir(f"/arca.php?ambiente={AMBIENTE}", "DELETE", clave_maestra())


@caso("ARCA: se rechazan clave ajena, PEM inválido, CUIT distinto y RSA débil")
def arca_validaciones():
    cert, clave = _certificado()
    _, otra_clave = _certificado()
    casos = {
        "clave ajena": _cargar(cert, otra_clave)[0],
        "certificado inválido": _cargar("hola", clave)[0],
        "clave inválida": _cargar(cert, "-----BEGIN PRIVATE KEY-----\nxx\n-----END PRIVATE KEY-----")[0],
        "CUIT distinto": _cargar(cert, clave, cuit="30711409757")[0],
        "RSA 1024": _cargar(*_certificado(bits=1024))[0],
        "ambiente inválido": pedir("/arca.php", "POST", clave_maestra(),
                                   {"accion": "cargar", "ambiente": "x", "certificado": cert, "clave": clave})[0],
    }
    malos = {k: v for k, v in casos.items() if v not in (400, 422)}
    pedir(f"/arca.php?ambiente={AMBIENTE}", "DELETE", clave_maestra())
    return not malos, f"no rechazó: {malos}" if malos else f"{len(casos)} rechazos correctos"


@caso("ARCA: solo la clave maestra carga o borra credenciales")
def arca_solo_maestra():
    llave, kid = _clave_servicio(["leer", "crear", "borrar"])
    try:
        cert, clave = _certificado()
        s1, _ = _cargar(cert, clave, llave=llave)
        s2, _ = pedir(f"/arca.php?ambiente={AMBIENTE}", "DELETE", llave)
        s3, _ = pedir("/arca.php", clave=llave)
        return s1 == 403 and s2 == 403 and s3 == 200, f"cargar={s1} borrar={s2} consultar={s3}"
    finally:
        pedir(f"/claves.php?id={kid}", "DELETE", clave_maestra())


@caso("ARCA: la conexión firma con el certificado guardado y traduce el rechazo")
def arca_probar_conexion():
    """Con un certificado autofirmado, WSAA debe rechazarlo como no confiable: eso
    prueba que el pedido se firmó y llegó bien formado. Requiere salida a internet."""
    cert, clave = _certificado()
    try:
        _cargar(cert, clave)
        s, d = pedir("/arca.php", "POST", clave_maestra(), {"accion": "probar", "ambiente": AMBIENTE}, timeout=60)
        error = d.get("error", "")
        if "No se pudo conectar" in error:
            return True, "sin salida a internet: se omite la verificación con ARCA"
        return s == 502 and "no reconoce el certificado" in error, f"status={s} error={error!r}"
    finally:
        pedir(f"/arca.php?ambiente={AMBIENTE}", "DELETE", clave_maestra())


@caso("Próximo número: sin certificado avisa y la letra X no se numera en ARCA")
def proximo_sin_credenciales():
    pedir(f"/arca.php?ambiente={AMBIENTE}", "DELETE", clave_maestra())
    s1, d1 = pedir(f"/proximo.php?ambiente={AMBIENTE}&tipo=factura&letra=A&pv=1", clave=clave_maestra())
    s2, _ = pedir(f"/proximo.php?ambiente={AMBIENTE}&tipo=factura&letra=X&pv=1", clave=clave_maestra())
    s3, _ = pedir("/proximo.php?ambiente=otro&tipo=factura&letra=A&pv=1", clave=clave_maestra())
    ok = s1 == 409 and d1.get("sin_credenciales") and s2 == 422 and s3 == 400
    return ok, f"sin_cert={s1} letraX={s2} ambiente_malo={s3}"


@caso("Remitos: el respaldo de la app actualiza en lugar de duplicar")
def remito_respaldo_sin_duplicar():
    m = clave_maestra()
    cuerpo = {"id_local": "pruebaLocal01", "numero": "00000077", "fecha": "21/10/2026",
              "cliente": {"razonSocial": "CLIENTE DE PRUEBA S.A."}, "items": [{"descripcion": "Item"}]}
    s1, d1 = pedir("/remitos.php", "POST", m, cuerpo)
    cuerpo["cliente"]["razonSocial"] = "CLIENTE DE PRUEBA EDITADO"
    s2, d2 = pedir("/remitos.php", "POST", m, cuerpo)
    try:
        s3, d3 = pedir(f"/remitos.php?id={d1.get('id')}", clave=m)
        nombre = d3.get("remito", {}).get("cliente", {}).get("razonSocial")
        ok = s1 == 201 and s2 == 200 and d1.get("id") == d2.get("id") and nombre == "CLIENTE DE PRUEBA EDITADO"
        return ok, f"alta={s1} reenvío={s2} mismo_id={d1.get('id') == d2.get('id')} cliente={nombre!r}"
    finally:
        pedir(f"/remitos.php?id={d1.get('id')}", "DELETE", m)


@caso("Remitos: los datos se recortan y no se aceptan campos extra")
def remito_sanitiza():
    m = clave_maestra()
    s, d = pedir("/remitos.php", "POST", m, {
        "cliente": {"razonSocial": "A" * 5000, "<script>": "x"},
        "items": [{"descripcion": "x"}] * 500, "recibi": {"tipoDoc": "Cualquiera"},
        "fecha": "no es fecha", "admin": True,
    })
    try:
        _, r = pedir(f"/remitos.php?id={d.get('id')}", clave=m)
        rem = r.get("remito", {})
        ok = (len(rem["cliente"]["razonSocial"]) == 160 and "<script>" not in rem["cliente"]
              and len(rem["items"]) == 60 and rem["recibi"]["tipoDoc"] == "" and "admin" not in rem
              and len(rem["fecha"]) == 10)
        return ok, f"razon={len(rem['cliente']['razonSocial'])} items={len(rem['items'])} fecha={rem['fecha']}"
    finally:
        pedir(f"/remitos.php?id={d.get('id')}", "DELETE", m)


@caso("Remitos: borrar exige el permiso «borrar», leer exige credencial y el id se valida")
def remito_borrar_permiso():
    m = clave_maestra()
    llave, kid = _clave_servicio(["leer", "crear"])
    _, d = pedir("/remitos.php", "POST", m, {"cliente": {"razonSocial": "BORRAR"}})
    try:
        s1, _ = pedir(f"/remitos.php?id={d.get('id')}", "DELETE", llave)
        s2, _ = pedir("/remitos.php?id=../../etc", "DELETE", m)
        s3, _ = pedir("/remitos.php?id=" + urllib.parse.quote("' OR 1=1 --"), clave=m)
        s4, _ = pedir(f"/remitos.php?id={d.get('id')}")
        return (s1 == 403 and s2 == 400 and s3 == 404 and s4 == 401,
                f"sin_permiso={s1} id_malo={s2} lectura_inyeccion={s3} lectura_sin_credencial={s4}")
    finally:
        pedir(f"/remitos.php?id={d.get('id')}", "DELETE", m)
        pedir(f"/claves.php?id={kid}", "DELETE", m)


@caso("Las respuestas llevan cabeceras de seguridad y no se cachean")
def cabeceras_seguras():
    s, d, h = pedir("/estado.php", clave=clave_maestra(), cabeceras=True)
    h = {k.lower(): v for k, v in h.items()}
    ok = h.get("x-content-type-options") == "nosniff" and "no-store" in h.get("cache-control", "")
    return ok, f"nosniff={h.get('x-content-type-options')} cache={h.get('cache-control')}"


@caso("Un JSON inválido o demasiado grande se rechaza sin error interno")
def cuerpo_invalido():
    import urllib.error
    import urllib.request

    def crudo(datos):
        req = urllib.request.Request(SITIO + "/api/remitos.php", data=datos, method="POST")
        req.add_header("X-API-Key", clave_maestra())
        req.add_header("Content-Type", "application/json")
        try:
            with urllib.request.urlopen(req, timeout=20) as r:
                return r.status
        except urllib.error.HTTPError as e:
            return e.code

    s1 = crudo(b"{no es json")
    s2 = crudo(b'{"x":"' + b"a" * 300000 + b'"}')
    return s1 == 400 and s2 == 413, f"json_malo={s1} enorme={s2}"


@caso("El código del servidor no expone la configuración")
def config_no_expuesta():
    """En Apache/LiteSpeed el .htaccess deniega el acceso; en cualquier caso, ningún
    archivo interno debe devolver contenido."""
    import urllib.error
    import urllib.request
    expuestos = []
    for ruta in ("/api/config.php", "/api/config.sample.php", "/api/bootstrap.php", "/api/lib/crypto.php"):
        try:
            with urllib.request.urlopen(SITIO + ruta, timeout=15) as r:
                cuerpo = r.read()
            if cuerpo.strip():
                expuestos.append(ruta)
        except urllib.error.HTTPError:
            pass
    return not expuestos, f"devuelven contenido: {expuestos}" if expuestos else "sin contenido expuesto"


@caso("La clave privada de prueba no queda en archivos temporales")
def sin_restos_temporales():
    if not es_local():
        return True, "solo se verifica en el entorno local"
    antes = set(os.listdir(tempfile.gettempdir()))
    cert, clave = _certificado()
    _cargar(cert, clave)
    pedir("/arca.php", "POST", clave_maestra(), {"accion": "probar", "ambiente": AMBIENTE}, timeout=60)
    pedir(f"/arca.php?ambiente={AMBIENTE}", "DELETE", clave_maestra())
    nuevos = [f for f in set(os.listdir(tempfile.gettempdir())) - antes if f.startswith(("tra", "cms"))]
    return not nuevos, f"quedaron: {nuevos}" if nuevos else "sin restos"
