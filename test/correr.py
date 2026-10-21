"""Batería de pruebas — Remitos y Comprobantes · Intellihelp

    python test/correr.py              -> todos los módulos (entorno local)
    python test/correr.py api cai      -> solo los módulos que contengan esos nombres
    python test/correr.py -v           -> muestra el detalle de cada caso

Por defecto apunta al entorno local (ver test/LEEME.md). Las pruebas crean y
borran datos: contra otro entorno (REMITOS_URL) hay que pedir los módulos por
nombre o con «all».
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

MODULOS_INTERFAZ = ["test_impresion", "test_formulario", "test_documentos", "test_inicio", "test_interfaz"]
MODULOS_API = ["test_api", "test_seguridad", "test_sesion", "test_cai"]
MODULOS = MODULOS_INTERFAZ + MODULOS_API

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
    # Todas las pruebas crean y borran datos: fuera del entorno local se piden explícitamente.
    if not base.es_local() and not todo and not args:
        print(f"{AMAR}Las pruebas escriben datos: contra {base.SITIO} indicá los módulos o «all».{FIN}")
        return 2
    if args and not todo:
        modulos = [m for m in MODULOS if any(a in m for a in args)]      # pedidos por nombre
    else:
        modulos = MODULOS

    print(f"\n{GRIS}Probando {base.url_app()}{FIN}")
    destino = "la base local de pruebas" if base.es_local() else "la base del entorno " + base.SITIO
    print(f"{AMAR}⚠ Las pruebas crean y borran datos en {destino}{FIN}")
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
