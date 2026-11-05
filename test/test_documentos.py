"""Pruebas de los PDF que genera el proyecto: guía de credenciales y remito."""
import os

import fitz
from playwright.sync_api import sync_playwright

from base import APP, abrir, caso, ir_a, modo_documento

GUIA = os.path.join(APP, "docs", "guia-credenciales-arca.pdf")


@caso("La guía existe, es A4 y entra en pocas carillas")
def guia_formato():
    if not os.path.exists(GUIA):
        return False, "no está el PDF de la guía"
    d = fitz.open(GUIA)
    mm = [round(v / 2.834, 1) for v in (d[0].rect.width, d[0].rect.height)]
    ok = 3 <= d.page_count <= 5 and abs(mm[0] - 210) < 2 and abs(mm[1] - 297) < 2
    return ok, f"{d.page_count} páginas de {mm[0]}×{mm[1]} mm"


@caso("El encabezado de la guía llega al borde, sin banda blanca")
def guia_encabezado_a_sangre():
    """Error corregido: el margen negativo no sangraba; Chromium recorta lo que invade el
    margen de página. Se resuelve con @page:first{margin-top:0} y sin pasar
    `margin` a page.pdf, que lo pisaría."""
    d = fitz.open(GUIA)
    p = d[0].get_pixmap(dpi=50)
    fila = [p.pixel(x, 1) for x in range(20, p.width - 20, 12)]
    violeta = [c for c in fila if c[2] > 80 and c[0] < 200]
    return len(violeta) == len(fila), f"{len(violeta)}/{len(fila)} muestras violetas en el borde superior"


@caso("Las páginas siguientes de la guía tienen aire arriba")
def guia_margen_interior():
    d = fitz.open(GUIA)
    p = d[1].get_pixmap(dpi=50)
    y = next((y for y in range(p.height)
              if min(p.pixel(x, y)[0] for x in range(20, p.width - 20)) < 200), None)
    # 17 mm a 50 dpi ≈ 33 px
    return y is not None and 25 <= y <= 45, f"el contenido de la hoja 2 empieza en y={y} px"


@caso("Ningún título de la guía queda partido por el salto de página")
def guia_sin_titulos_cortados():
    """Error corregido: el badge numerado del paso se partía al pie de la hoja."""
    d = fitz.open(GUIA)
    problemas = []
    for i in range(d.page_count):
        alto = d[i].rect.height
        for b in d[i].get_text("blocks"):
            y0, y1, txt = b[1], b[3], b[4].strip()
            if not txt:
                continue
            # un bloque que arranca a menos de 12 pt del borde inferior quedó cortado
            if y1 > alto - 4 and y0 > alto - 26:
                problemas.append((i + 1, txt[:28]))
    return not problemas, f"cortados={problemas}" if problemas else "sin cortes"


@caso("La guía numera sus páginas")
def guia_numeracion():
    d = fitz.open(GUIA)
    faltan = [i + 1 for i in range(d.page_count)
              if f"Página {i+1} de {d.page_count}" not in d[i].get_text()]
    return not faltan, f"sin numeración: {faltan}" if faltan else "todas numeradas"


@caso("En el remito PDF los datos van pegados a su rótulo, dentro del recuadro")
def remito_datos_en_su_lugar():
    """Error corregido: el mail largo atravesaba el recuadro del cliente porque al <span>
    del valor le faltaba min-width:0 dentro del contenedor flex."""
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            ir_a(pg, "remito")
            modo_documento(pg, "full")
            pg.evaluate("""() => {
                Object.assign(doc.cliente, {
                    mail: 'administracion.compras@parquesnacionales.gob.ar',
                    razonSocial: 'ADMINISTRACION DE PARQUES NACIONALES',
                    senores: 'APN - Direccion Regional Centro Patagonia' });
                renderPreview();
            }""")
            pg.wait_for_timeout(900)
            r = pg.evaluate("""() => {
                const p = document.querySelector('#page').getBoundingClientRect();
                return [...document.querySelectorAll('#template div')].map(e => {
                    const b = e.getBoundingClientRect();
                    return { t: e.textContent.trim().slice(0, 24),
                             x2: (b.right - p.left) / p.width * 100,
                             y1: (b.top - p.top) / p.height * 100,
                             y2: (b.bottom - p.top) / p.height * 100 };
                }).filter(o => o.y1 > 16.2 && o.y2 < 33.5 && o.t.length > 2);
            }""")
            # el recuadro del cliente llega hasta x = 95.64 %
            fuera = [o for o in r if o["x2"] > 95.9]
            return len(r) >= 10 and not fuera, \
                f"{len(r)} datos en el recuadro, {len(fuera)} desbordados"
        finally:
            b.close()


@caso("El bloque del emisor entra en el encabezado del remito")
def remito_encabezado():
    """Error corregido: «Mail: info@intellihelp.tech» quedaba cortado por el borde del recuadro."""
    with sync_playwright() as pw:
        b, pg, errs = abrir(pw)
        try:
            ir_a(pg, "remito")
            modo_documento(pg, "full")
            r = pg.evaluate("""() => {
                const p = document.querySelector('#page').getBoundingClientRect();
                const els = [...document.querySelectorAll('#template div')];
                const o = {};
                for (const t of ['CUIT:', 'Teléfono', 'Cód. Postal', 'Dirección', 'Localidad', 'Mail:']) {
                    const e = els.find(x => x.textContent.trim().startsWith(t));
                    if (e) { const b = e.getBoundingClientRect();
                             o[t] = (b.bottom - p.top) / p.height * 100; }
                }
                return o;
            }""")
            # el recuadro del encabezado cierra en 15.60 %
            fuera = {k: v for k, v in r.items() if v > 15.75}
            return len(r) == 6 and not fuera, f"fuera del recuadro: {fuera}" if fuera else "los 6 renglones adentro"
        finally:
            b.close()
