"""Batería de pruebas — Remitos y Comprobantes · Intellihelp

    python test/correr.py              -> todos los módulos (entorno local)
    python test/correr.py api cai      -> solo los módulos que contengan esos nombres
    python test/correr.py -v           -> muestra el detalle de cada caso

Por defecto apunta al entorno local (ver test/LEEME.md). Contra otro entorno
(REMITOS_URL) solo corren los módulos que no escriben en la base, salvo que se
pidan por nombre o con «all».
"""
import importlib
import os
import sys
import time
import traceback

AQUI = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, AQUI)

# La consola de Windows viene en cp1252 y no sabe escribir ✓ ni acentos.
try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass

import base  # noqa: E402

# Los módulos de API escriben en la base del entorno bajo prueba (crean y borran
# datos). En el entorno local corren siempre; contra otro entorno, solo a pedido.
MODULOS_LOCALES = ["test_impresion", "test_formulario", "test_documentos", "test_inicio", "test_interfaz"]
MODULOS_API = ["test_api", "test_seguridad", "test_cai"]
MODULOS = MODULOS_LOCALES + MODULOS_API

VERDE, ROJO, GRIS, AMAR, FIN = "\033[32m", "\033[31m", "\033[90m", "\033[33m", "\033[0m"
if os.name == "nt" and not os.environ.get("WT_SESSION"):
    try:
        import ctypes
        ctypes.windll.kernel32.SetConsoleMode(ctypes.windll.kernel32.GetStdHandle(-11), 7)
    except Exception:
        VERDE = ROJO = GRIS = AMAR = FIN = ""


def main():
    args = [a for a in sys.argv[1:] if not a.startswith("-")]
    verboso = "-v" in sys.argv or "--verbose" in sys.argv
    todo = "--todo" in sys.argv or "all" in args
    if args and not todo:
        modulos = [m for m in MODULOS if any(a in m for a in args)]      # pedidos por nombre
    elif todo:
        modulos = MODULOS                                                # incluye API/C.A.I.
    elif base.es_local():
        modulos = MODULOS                                                # entorno local: todo
    else:
        modulos = MODULOS_LOCALES                                        # entorno remoto: sin escribir

    print(f"\n{GRIS}Probando {base.url_app()}{FIN}")
    if any(m in MODULOS_API for m in modulos):
        destino = "la base local de pruebas" if base.es_local() else "la base del entorno remoto"
        print(f"{AMAR}⚠ Incluye pruebas que escriben en {destino} (crean y borran datos de prueba){FIN}")
    print()

    total = fallados = 0
    rotos = []
    arranque = time.time()

    for nombre_mod in modulos:
        base._casos.clear()
        try:
            if nombre_mod in sys.modules:
                importlib.reload(sys.modules[nombre_mod])
            else:
                importlib.import_module(nombre_mod)
        except Exception:
            print(f"{ROJO}✗ no se pudo cargar {nombre_mod}{FIN}")
            traceback.print_exc()
            fallados += 1
            continue

        casos = base.registrados()
        print(f"{nombre_mod.replace('test_', '').upper()}")
        for nombre, fn in casos:
            total += 1
            t0 = time.time()
            try:
                ok, detalle = fn()
            except Exception as e:
                ok, detalle = False, f"excepción: {e}"
                if verboso:
                    traceback.print_exc()
            seg = time.time() - t0
            marca = f"{VERDE}✓{FIN}" if ok else f"{ROJO}✗{FIN}"
            print(f"  {marca} {nombre}  {GRIS}{seg:.1f}s{FIN}")
            if not ok:
                fallados += 1
                rotos.append((nombre_mod, nombre, detalle))
                print(f"      {ROJO}{detalle}{FIN}")
            elif verboso:
                print(f"      {GRIS}{detalle}{FIN}")
        print()

    dur = time.time() - arranque
    if fallados:
        print(f"{ROJO}{fallados} de {total} fallaron{FIN}  {GRIS}({dur:.0f}s){FIN}\n")
        for m, n, d in rotos:
            print(f"  {ROJO}·{FIN} [{m}] {n}\n    {GRIS}{d}{FIN}")
        print()
        return 1
    print(f"{VERDE}Los {total} casos pasaron{FIN}  {GRIS}({dur:.0f}s){FIN}\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
