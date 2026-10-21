"""Pruebas de impresión: botón de prueba, ejemplares y color del duplicado.

Cada caso reproduce un error ya corregido.
"""
from playwright.sync_api import sync_playwright

from base import abrir, caso, ir_a, modo_documento


@caso("El botón de prueba imprime el tipo que estás viendo, no el anterior")
def prueba_no_queda_cacheada():
    """Error corregido: estando en Recibo, el botón de prueba imprimía la Factura anterior.
    Causa: la restauración diferida seguía viva al cambiar de tipo y pisaba compDoc."""
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            secuencia = []
            for vista in ["factura", "recibo", "nota", "factura"]:
                ir_a(pg, vista, 600)
                pg.evaluate("() => window.__imp = []")
                pg.click("#tbCompTest")
                pg.wait_for_timeout(650)
                imp = pg.evaluate("() => window.__imp")
                secuencia.append((vista, imp[0]["tipo"] if imp else None))
                pg.wait_for_timeout(200)   # se cambia ANTES de que termine la restauración
            malas = [(v, t) for v, t in secuencia if v != t]
            return not malas, f"secuencia={secuencia}"
        finally:
            b.close()


@caso("Dos pruebas seguidas no dejan los datos de ejemplo como documento real")
def prueba_doble_no_pisa():
    """Error corregido: el backup se clonaba antes de cerrar la prueba anterior, así que la
    segunda prueba tomaba los datos ficticios como si fueran tuyos."""
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            ir_a(pg, "factura")
            pg.evaluate("() => { compDoc.cliente.razonSocial = 'CLIENTE REAL SRL'; touchComp(); }")
            pg.wait_for_timeout(600)
            pg.click("#tbCompTest"); pg.wait_for_timeout(420)
            pg.click("#tbCompTest"); pg.wait_for_timeout(2600)
            rs = pg.evaluate("() => compDoc.cliente.razonSocial")
            return rs == "CLIENTE REAL SRL", f"razonSocial={rs!r}"
        finally:
            b.close()


@caso("Guardar durante una prueba guarda tu documento, no el de ejemplo")
def guardar_en_prueba():
    """Error corregido: compDoc son los ficticios durante la prueba; Guardar los persistía."""
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            ir_a(pg, "factura")
            pg.evaluate("() => { compDoc.cliente.razonSocial = 'CLIENTE REAL SRL'; touchComp(); }")
            pg.wait_for_timeout(600)
            pg.click("#tbCompTest"); pg.wait_for_timeout(420)
            pg.evaluate("() => guardarComprobante()")
            pg.wait_for_timeout(800)
            r = pg.evaluate("""() => {
                const l = JSON.parse(localStorage.getItem('remitos_comprobantes') || '[]');
                return l.length ? l[0].cliente.razonSocial : null;
            }""")
            return r == "CLIENTE REAL SRL", f"guardado={r!r}"
        finally:
            b.close()


@caso("Un guardado ya agendado no persiste los datos de la prueba")
def guardado_pendiente():
    """Error corregido: el guard enPrueba() se evaluaba al PROGRAMAR el autoguardado, no al
    dispararlo; si la prueba arrancaba dentro de esos 400 ms, se guardaban los ficticios."""
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            ir_a(pg, "remito")
            pg.evaluate("() => { doc.cliente.razonSocial = 'MI REMITO REAL SA'; touch(); }")
            pg.wait_for_timeout(80)                      # menos que los 400 ms del autoguardado
            pg.evaluate("() => imprimirTestRemito()")
            pg.wait_for_timeout(800)
            r = pg.evaluate("""() => { const d = JSON.parse(localStorage.getItem('remitos_draft') || '{}');
                return d.cliente ? d.cliente.razonSocial : null; }""")
            return r == "MI REMITO REAL SA", f"borrador={r!r}"
        finally:
            b.close()


@caso("Cambiar de tipo dentro de los 400 ms no pierde lo tecleado")
def tecleo_no_se_pierde():
    """Error corregido: el debounce leía la variable global al dispararse, no al agendarse."""
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            ir_a(pg, "factura")
            pg.evaluate("() => { compDoc.cliente.razonSocial = 'TECLEADO EN FACTURA'; touchComp(); }")
            pg.wait_for_timeout(100)
            ir_a(pg, "recibo", 900)
            ir_a(pg, "factura", 800)
            rs = pg.evaluate("() => compDoc.cliente.razonSocial")
            return rs == "TECLEADO EN FACTURA", f"razonSocial={rs!r}"
        finally:
            b.close()


@caso("Los comprobantes siempre salen con ORIGINAL y DUPLICADO")
def siempre_duplicado():
    """Error corregido: los comprobantes heredaban fiscal.copias, el selector del REMITO; puesto
    en «Sólo Original» desaparecía el duplicado de facturas, recibos y notas."""
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            fallas = []
            for vista in ["factura", "recibo", "nota"]:
                ir_a(pg, vista)
                pg.evaluate("() => { fiscal.copias = 1; buildCompCopies(); }")  # peor caso
                pg.wait_for_timeout(500)
                r = pg.eval_on_selector_all(
                    "#compPrintCopies .copy-page",
                    "e => e.map(x => (x.dataset.copia || '?') + ':' + "
                    "(x.classList.contains('copy-beige') ? 'beige' : 'blanco'))")
                if r != ["ORIGINAL:blanco", "DUPLICADO:beige"]:
                    fallas.append((vista, r))
            return not fallas, f"fallas={fallas}" if fallas else "los tres tipos OK"
        finally:
            b.close()


@caso("El duplicado sale beige de punta a punta, y el original blanco")
def duplicado_beige_completo():
    """Error corregido (doble): el duplicado salía blanco (faltaba .copy-beige) y, ya en beige,
    el color no llegaba al pie por el height:auto del comprobante."""
    import fitz, os, tempfile
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            ir_a(pg, "factura", 800)
            pg.evaluate("""() => { compDoc = sampleComprobante('factura');
                renderComprobanteEditor(); renderCompClientesSelect(); renderComprobante();
                buildCompCopies(); document.querySelector('.app').classList.add('copies-on'); }""")
            pg.wait_for_timeout(900)
            out = os.path.join(tempfile.gettempdir(), "ith_test_dup.pdf")
            pg.pdf(path=out, format="A4", print_background=True, prefer_css_page_size=True)
        finally:
            b.close()

    d = fitz.open(out)
    if d.page_count != 2:
        return False, f"se esperaban 2 ejemplares y salieron {d.page_count}"
    zonas = {}
    for i in (0, 1):
        p = d[i].get_pixmap(dpi=60)
        h, w = p.height, p.width
        zonas[i] = {k: p.pixel(x, y) for k, (x, y) in {
            "arriba": (w // 2, 6), "medio": (w // 2, h // 2), "abajo": (w // 2, h - 7),
            "abajo-izq": (6, h - 7), "abajo-der": (w - 7, h - 7)}.items()}
    blanco = all(v == (255, 255, 255) for v in zonas[0].values())
    beige = all(abs(v[0] - 241) < 5 and abs(v[1] - 227) < 6 and abs(v[2] - 217) < 6
                for v in zonas[1].values())
    return blanco and beige, f"original={zonas[0]['medio']} duplicado={zonas[1]['abajo']}"


@caso("El remito en documento completo emite los dos ejemplares")
def remito_dos_ejemplares():
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            ir_a(pg, "remito")
            modo_documento(pg, "full")
            pg.evaluate("() => window.__imp = []")
            pg.click("#btnPdf")
            pg.wait_for_timeout(1100)
            imp = pg.evaluate("() => window.__imp")
            if not imp:
                return False, "el botón PDF no llegó a imprimir"
            ej = imp[0]["ejemplares"]
            return len(ej) == 2 and ej[1].endswith("beige"), f"ejemplares={ej}"
        finally:
            b.close()


@caso("Imprimir dos veces seguidas no borra los ejemplares de la segunda")
def limpieza_no_interfiere():
    """Error corregido: el temporizador de limpieza de una impresión anterior vaciaba
    #printCopies mientras la nueva todavía se estaba armando."""
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            ir_a(pg, "remito")
            modo_documento(pg, "full")
            pg.click("#btnPdf"); pg.wait_for_timeout(600)
            pg.evaluate("() => window.__imp = []")
            pg.click("#btnPdf"); pg.wait_for_timeout(1100)
            imp = pg.evaluate("() => window.__imp")
            if not imp:
                return False, "la segunda impresión no salió"
            return len(imp[0]["ejemplares"]) == 2, f"ejemplares={imp[0]['ejemplares']}"
        finally:
            b.close()
