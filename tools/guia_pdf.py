"""Genera el instructivo de alta en ARCA en PDF a partir de su versión HTML.

    python tools/guia_pdf.py

Requiere Playwright con Chromium (`pip install playwright && playwright install chromium`).
El PDF resultante se publica junto con la aplicación en app/docs/.
"""
import os
import pathlib

from playwright.sync_api import sync_playwright

RAIZ = pathlib.Path(__file__).resolve().parent.parent
ORIGEN = RAIZ / "app" / "docs" / "guia-credenciales-arca.html"
DESTINO = RAIZ / "app" / "docs" / "guia-credenciales-arca.pdf"

PIE = """
<div style="width:100%;font-family:Saira,Arial,sans-serif;font-size:8px;color:#6b6f7b;
            padding:0 17mm;display:flex;justify-content:space-between">
  <span>Intellihelp S.A. · Alta de credenciales ARCA</span>
  <span>Página <span class="pageNumber"></span> de <span class="totalPages"></span></span>
</div>"""


def main():
    with sync_playwright() as pw:
        navegador = pw.chromium.launch()
        pagina = navegador.new_page()
        pagina.goto(ORIGEN.as_uri(), wait_until="networkidle")
        pagina.pdf(
            path=str(DESTINO),
            format="A4",
            print_background=True,
            prefer_css_page_size=True,
            display_header_footer=True,
            header_template="<span></span>",
            footer_template=PIE,
        )
        navegador.close()
    print(f"Generado {os.path.relpath(DESTINO, RAIZ)}")


if __name__ == "__main__":
    main()
