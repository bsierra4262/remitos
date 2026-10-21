"""Pruebas de Inicio: sin ejemplo, paginación de recientes y recuperación de borrador.

Todo local (localStorage): no toca el servidor.
"""
from playwright.sync_api import sync_playwright

from base import abrir, caso, ir_a


@caso("La app no siembra ningún remito de ejemplo, y limpia el viejo")
def sin_ejemplo():
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            # sembrar un ejemplo viejo como el de versiones anteriores
            pg.evaluate("""() => {
                save(LS.docs, [{ id:'ejemplo', cliente:{razonSocial:'VIEJO',cuit:'1'}, items:[], updated:Date.now() }]);
            }""")
            pg.reload(wait_until="networkidle")
            pg.wait_for_timeout(900)
            quedan = pg.evaluate("() => JSON.parse(localStorage.getItem('remitos_docs')||'[]')"
                                 ".filter(d => d.id === 'ejemplo' || d.ejemplo).length")
            return quedan == 0, f"ejemplos que quedaron: {quedan}"
        finally:
            b.close()


@caso("Documentos recientes se paginan cuando hay muchos")
def recientes_paginados():
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            pg.evaluate("""() => {
                const docs = [];
                for (let i = 1; i <= 11; i++)
                    docs.push({ id:'r'+i, cliente:{razonSocial:'Cliente '+i, cuit:'30-000000'+i},
                                items:[{descripcion:'x'}], updated: Date.now() - i*1000 });
                save(LS.docs, docs);
            }""")
            ir_a(pg, "inicio", 700)
            r = pg.evaluate("""() => ({
                filas: document.querySelectorAll('#inicioRecent .doc-row').length,
                pager: !!document.querySelector('#inicioRecent .doc-pager'),
                texto: (document.querySelector('#inicioRecent .pg')||{}).textContent || ''
            })""")
            if not r["pager"] or r["filas"] > 8:
                return False, f"filas={r['filas']} pager={r['pager']}"
            # ir a la página 2
            pg.click("#inicioRecent [data-rp]:not([disabled])")
            pg.wait_for_timeout(300)
            t2 = pg.eval_on_selector("#inicioRecent .pg", "e => e.textContent")
            return "2 de 2" in t2, f"pág1='{r['texto']}' pág2='{t2}'"
        finally:
            b.close()


@caso("Un borrador en curso sin guardar se recupera desde Inicio")
def borrador_recuperable():
    """Si se corta internet o se cierra el navegador cargando un remito, el
    trabajo queda a un clic de retomarse (auto-guardado local)."""
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            ir_a(pg, "remito")
            pg.evaluate("() => { doc.cliente.razonSocial = 'TRABAJO SIN GUARDAR SA';"
                        " doc.cliente.cuit = '30-99999999-9'; touch(); }")
            pg.wait_for_timeout(700)
            # simular "cerrar y volver": recargar la página
            pg.reload(wait_until="networkidle")
            pg.wait_for_timeout(900)
            ir_a(pg, "inicio", 600)
            fila = pg.query_selector(".doc-row.is-draft")
            if not fila:
                return False, "el borrador no aparece como recuperable"
            txt = fila.inner_text()
            # retomarlo
            pg.click(".doc-row.is-draft [data-open]")
            pg.wait_for_timeout(500)
            rs = pg.evaluate("() => doc.cliente.razonSocial")
            return "TRABAJO SIN GUARDAR" in txt and rs == "TRABAJO SIN GUARDAR SA", \
                f"texto={txt[:40]!r} retomado={rs!r}"
        finally:
            b.close()


@caso("Retomar borrador: sólo si hay trabajo sin guardar, si no queda en blanco")
def retomar_coherente():
    """Casos: reload vacío→blanco · editar sin guardar→restaura · guardar→blanco ·
    editar un guardado sin re-guardar→restaura. Nunca pierde trabajo, nunca revive
    algo terminado."""
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            def blanco():
                return pg.evaluate("() => !tieneContenido(doc)")

            ir_a(pg, "remito")
            # 1) app vacía, reload → blanco
            pg.reload(wait_until="networkidle"); pg.wait_for_timeout(900)
            if not blanco():
                return False, "caso 1: reload en vacío no quedó en blanco"

            # 2) editar sin guardar, reload → restaura
            ir_a(pg, "remito")
            pg.evaluate("() => { doc.cliente.razonSocial = 'SIN GUARDAR SA'; touch(); }")
            pg.wait_for_timeout(700)
            pg.reload(wait_until="networkidle"); pg.wait_for_timeout(900)
            if pg.evaluate("() => doc.cliente.razonSocial") != "SIN GUARDAR SA":
                return False, "caso 2: perdió el trabajo sin guardar al recargar"

            # 3) guardar, reload → blanco (a salvo en Guardados)
            ir_a(pg, "remito")
            pg.evaluate("() => guardar()")
            pg.wait_for_timeout(600)
            pg.reload(wait_until="networkidle"); pg.wait_for_timeout(900)
            if not blanco():
                return False, "caso 3: tras guardar y recargar no quedó en blanco"

            # 4) abrir el guardado, cambiar sin re-guardar, reload → restaura el cambio
            pg.evaluate("() => { const d = JSON.parse(localStorage.getItem('remitos_docs'))[0]; openDoc(d.id); }")
            pg.wait_for_timeout(400)
            pg.evaluate("() => { doc.cliente.otrosDatos = 'CAMBIO PENDIENTE'; touch(); }")
            pg.wait_for_timeout(700)
            pg.reload(wait_until="networkidle"); pg.wait_for_timeout(900)
            if pg.evaluate("() => doc.cliente.otrosDatos") != "CAMBIO PENDIENTE":
                return False, "caso 4: perdió el cambio sobre un remito guardado"

            return True, "4 casos coherentes: blanco/restaura según haya trabajo sin guardar"
        finally:
            b.close()


@caso("El chip de modo refleja también la conexión (unificado)")
def indicador_conexion():
    """El chip 'Modo local' pasa a 'Sin conexión' en rojo cuando no hay internet:
    un solo indicador para modo + conexión, no dos."""
    with sync_playwright() as pw:
        b = pw.chromium.launch()
        ctx = b.new_context(viewport={"width": 1400, "height": 950})
        pg = ctx.new_page()
        try:
            import base as _b
            pg.goto(_b.url_app(), wait_until="networkidle", timeout=60000)
            pg.wait_for_timeout(1200)
            # ya no debe existir el chip separado
            separado = pg.query_selector("#connChip") is not None
            on_txt = pg.eval_on_selector("#modeChipT", "e => e.textContent")
            ctx.set_offline(True)
            pg.evaluate("() => window.dispatchEvent(new Event('offline'))")
            pg.wait_for_timeout(400)
            off_txt = pg.eval_on_selector("#modeChipT", "e => e.textContent")
            off_cls = pg.eval_on_selector("#modeChip", "e => e.className.includes('offline')")
            ctx.set_offline(False)
            pg.evaluate("() => window.dispatchEvent(new Event('online'))")
            pg.wait_for_timeout(300)
            back_txt = pg.eval_on_selector("#modeChipT", "e => e.textContent")
            ok = (not separado and "local" in on_txt.lower() and off_cls
                  and off_txt.lower() == "sin conexión" and "local" in back_txt.lower())
            return ok, f"sep={separado} on={on_txt!r} off={off_txt!r}({off_cls}) back={back_txt!r}"
        finally:
            b.close()
