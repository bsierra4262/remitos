"""Capturas de pantalla de la aplicación para el README y la revisión de diseño.

Arma un escenario de demostración en el entorno LOCAL de pruebas (el mismo de
test/LEEME.md: php -S en 127.0.0.1:8765, base de pruebas y Google simulado), saca cada
pantalla y al terminar borra los datos de demostración. Vacía también los talonarios y la
auditoría de la base LOCAL de pruebas (nunca se usa contra producción).

    python tools/capturas.py                  # todas, en docs/imagenes/capturas
    python tools/capturas.py factura recibo   # solo las que contengan esos nombres
    python tools/capturas.py --salida /tmp/x  # en otra carpeta

Escritorio a 1440 × 900 y celular a 390 × 844, con densidad 1,5 y 3.
Los datos son ficticios: clientes, CUIT y CAE de ejemplo.
"""
import argparse
import os
import sys
import tempfile

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(RAIZ, 'test'))

import base  # noqa: E402
from playwright.sync_api import sync_playwright  # noqa: E402
from test_seguridad import _certificado  # noqa: E402

ADMIN = 'martin.pereyra@intellihelp.tech'
OPERADORES = [('Camila Ortiz', 'camila.ortiz'), ('Julián Ferreyra', 'julian.ferreyra'),
              ('Lucía Romero', 'lucia.romero'), ('Sofía Acosta', 'sofia.acosta')]
CLIENTES = [
    {'razonSocial': 'DISTRIBUIDORA DEL SUR S.A.', 'cuit': '30-50001091-2', 'condIVA': 'Responsable Inscripto',
     'domicilio': 'Av. Montes de Oca 1450', 'localidad': 'CABA', 'cp': 'C1270', 'telefono': '11 4302-5500',
     'mail': 'compras@delsur.com.ar', 'nombre': 'Laura Méndez'},
    {'razonSocial': 'INDUSTRIAS DEL PLATA S.A.', 'cuit': '30-70000000-8', 'condIVA': 'Responsable Inscripto',
     'domicilio': 'Ruta 8 km 52', 'localidad': 'Pilar', 'cp': 'B1629', 'telefono': '0230 442-1000',
     'mail': 'proveedores@delplata.com.ar', 'nombre': 'Diego Ruiz'},
    {'razonSocial': 'ESTUDIO CONTABLE ARIAS', 'cuit': '20-12345678-6', 'condIVA': 'Monotributo',
     'domicilio': 'Tucumán 1580 3° B', 'localidad': 'CABA', 'cp': 'C1050', 'telefono': '11 4371-2290',
     'mail': 'estudio@arias.com.ar', 'nombre': 'Marcela Arias'},
]
ITEMS = [{'codigo': 'SOP-40', 'descripcion': 'Soporte técnico mensual', 'cantidad': '40', 'unidad': 'horas',
          'precio': '30.000', 'alicuota': '21'},
         {'codigo': 'BIO-F22', 'descripcion': 'Reparación de control de acceso biométrico', 'cantidad': '2',
          'unidad': 'unidades', 'precio': '135.000', 'bonif': '10', 'alicuota': '21'},
         {'codigo': 'MAT-01', 'descripcion': 'Materiales (cableado, fichas, canaleta)', 'cantidad': '1',
          'unidad': 'unidades', 'precio': '48.750,50', 'alicuota': '21'},
         {'codigo': 'MAN-02', 'descripcion': 'Manual técnico impreso de la instalación', 'cantidad': '2',
          'unidad': 'unidades', 'precio': '18.000', 'alicuota': '10.5'}]
IMPORTAR = ('Razón Social,CUIT,Condición frente al IVA,Localidad\n'
            'LOGÍSTICA ANDINA S.R.L.,30-71589630-9,Responsable Inscripto,Mendoza\n'
            'DISTRIBUIDORA DEL SUR S.A.,30-50001091-2,Responsable Inscripto,CABA\n'
            'FERRETERÍA EL TORNILLO,20-30123456-3,Monotributo,Rosario\n'
            'CLÍNICA SAN MARTÍN S.A.,30-12345678-0,IVA Exento,Córdoba\n'
            ',,,\n')
CAI = '75041234567890'


def preparar():
    """Escenario de demostración. Devuelve los ids que usan las capturas."""
    m = base.clave_maestra()
    base.limpiar_datos()
    base._sql('DELETE FROM cai_talonarios')
    base._sql('DELETE FROM api_log')
    base.asegurar_usuario(ADMIN, 'Martín Pereyra', 'admin')
    _, lista = base.pedir('/usuarios.php', clave=m)
    for u in lista.get('usuarios', []):
        if u['email'] != ADMIN:
            base.pedir(f"/usuarios.php?id={u['id']}", 'DELETE', m)
    for nombre, local in OPERADORES:
        base.asegurar_usuario(local + '@intellihelp.tech', nombre, 'operador')

    cert, clave = _certificado()   # certificado de un representante (20-12345678-6) del emisor
    base.pedir('/arca.php', 'POST', m, {'accion': 'cargar', 'ambiente': 'homologacion', 'certificado': cert, 'clave': clave})
    base.pedir('/ajustes.php', 'POST', m, {'seccion': 'emisor', 'valores': {
        'ambiente': 'homologacion', 'cuit': '30-71140975-7', 'ptoVenta': '3', 'emision': False,
        'mipyme': True, 'cbu': '0170099222000000678900', 'transferencia': 'SCA'}})
    base.pedir('/ajustes.php', 'POST', m, {'seccion': 'remito', 'valores': {'ptoVenta': '1', 'copias': 2}})
    base.pedir('/clientes.php', 'POST', m, {'accion': 'importar', 'clientes': CLIENTES})

    ids = {}
    base.pedir('/talonarios.php', 'POST', m, {'punto_venta': 1, 'numero_desde': 1, 'numero_hasta': 500, 'cai': CAI,
                                             'cai_vencimiento': base.VTO, 'sin_verificar': True})
    remito = dict(base.REMITO_COMPLETO,
                  cliente=dict(base.REMITO_COMPLETO['cliente'], **{k: CLIENTES[0][k] for k in ('razonSocial', 'cuit', 'domicilio', 'localidad')}),
                  items=[{'articulo': '1', 'descripcion': 'Control de acceso biométrico ZK F22', 'cantidad': '2'},
                         {'articulo': '2', 'descripcion': 'Fuente 12 V 5 A con respaldo', 'cantidad': '2'},
                         {'articulo': '3', 'descripcion': 'Cable UTP Cat. 6 (metros)', 'cantidad': '40'}],
                  transporte={'tipo': 'propio'}, observaciones='Entrega en recepción, piso 3.')
    for i in range(3):
        _, d = base.pedir('/remitos.php', 'POST', m, remito)
        base.pedir('/talonarios.php', 'POST', m, {'accion': 'autorizar', 'pv': 1, 'tipo': 91, 'remito_id': d['id']})
        ids['remito'] = d['id']
    base.pedir('/remitos.php', 'POST', m, dict(remito, cliente=dict(remito['cliente'], razonSocial=CLIENTES[1]['razonSocial'])))

    def comprobante(cuerpo, numero=None, cae=None):
        _, d = base.pedir('/comprobantes.php', 'POST', m, cuerpo)
        cid = d['comprobante']['id']
        if numero:
            base.pedir('/comprobantes.php', 'POST', m, {'accion': 'registrar_cae', 'id': cid, 'numero': numero,
                                                       'cae': cae, 'caeVto': '01/12/2026'})
        return cid

    cli = {k: CLIENTES[0][k] for k in ('razonSocial', 'cuit', 'condIVA', 'domicilio', 'localidad', 'cp')}
    ids['factura'] = comprobante({'tipo': 'factura', 'letra': 'A', 'ptoVenta': '3', 'conceptoTipo': 2, 'cliente': cli,
                                  'items': ITEMS, 'condVenta': 'Cuenta corriente', 'servDesde': '01/11/2026',
                                  'servHasta': '30/11/2026', 'vtoPago': '21/12/2026'}, '158', '86471092872193')
    ids['factura_credito'] = comprobante({
        'tipo': 'factura', 'letra': 'A', 'ptoVenta': '3', 'conceptoTipo': 1, 'fce': True,
        'cliente': {k: CLIENTES[1][k] for k in ('razonSocial', 'cuit', 'condIVA', 'domicilio', 'localidad', 'cp')},
        'items': [{'descripcion': 'Servidores y licencias — proyecto anual', 'cantidad': '1', 'precio': '8.500.000'}],
        'condVenta': 'Transferencia bancaria', 'vtoPago': '21/12/2026'}, '12', '86471092874771')
    ids['nota'] = comprobante({'tipo': 'nota', 'subtipo': 'credito', 'letra': 'A', 'ptoVenta': '3', 'conceptoTipo': 2,
                               'cliente': cli, 'items': [{'descripcion': 'Bonificación por demora en el servicio', 'cantidad': '1', 'precio': '120.000'}],
                               'compAsoc': {'tipo': 'Factura', 'letra': 'A', 'pv': '3', 'nro': '158'},
                               'servDesde': '01/11/2026', 'servHasta': '30/11/2026', 'vtoPago': '21/12/2026'}, '7', '86471092872208')
    ids['recibo'] = comprobante({'tipo': 'recibo', 'letra': 'X', 'cliente': cli, 'monto': '1.000.000,00',
                                 'medioPago': 'Transferencia', 'concepto': 'Pago a cuenta de la Factura A 00003-00000158',
                                 'cancela': [{'id': ids['factura'], 'importe': '1.000.000,00'}]})
    return ids


def limpiar():
    m = base.clave_maestra()
    base.limpiar_datos()
    base._sql('DELETE FROM cai_talonarios')
    base.pedir('/arca.php?ambiente=homologacion', 'DELETE', m)
    base.pedir('/ajustes.php', 'POST', m, {'seccion': 'emisor', 'valores': {'ambiente': 'homologacion', 'cuit': '', 'ptoVenta': '', 'emision': False}})
    _, lista = base.pedir('/usuarios.php', clave=m)
    for u in lista.get('usuarios', []):
        if u['email'] != base.ADMIN_EMAIL:
            base.pedir(f"/usuarios.php?id={u['id']}", 'DELETE', m)


def capturar(salida, filtros):
    ids = preparar()
    hechas = []

    def quiero(nombre):
        return not filtros or any(f in nombre for f in filtros)

    with sync_playwright() as pw:
        b = pw.chromium.launch()

        def contexto(ancho, alto, escala, sesion=True):
            ctx = b.new_context(viewport={'width': ancho, 'height': alto}, device_scale_factor=escala)
            if sesion:
                def get(url):
                    r = ctx.request.get(url, max_redirects=0)
                    return r.status, r.headers
                base.ingresar_con_google(get, ADMIN)
            return ctx

        def foto(pg, nombre):
            if quiero(nombre):
                pg.wait_for_timeout(500)
                pg.screenshot(path=os.path.join(salida, nombre + '.png'))
                hechas.append(nombre)

        def abrir_app(ctx, extra=''):
            pg = ctx.new_page()
            pg.goto(base.url_app() + extra, wait_until='networkidle')
            pg.wait_for_selector('.navbar', state='visible')
            pg.wait_for_timeout(900)
            return pg

        def abrir_comp(pg, tipo, cid):
            base.ir_a(pg, tipo, 900)
            pg.evaluate("""([tipo, id]) => {
                compDoc = Object.assign(emptyComprobante(tipo), datos.comprobantes.find(c => c.id === id));
                renderComprobanteEditor(); renderComprobante(); updateCrumb();
            }""", [tipo, cid])
            pg.wait_for_timeout(700)

        # ---------------------------------------------------------- escritorio
        anon = contexto(1440, 900, 1.5, sesion=False)
        pg = anon.new_page()
        pg.goto(base.SITIO + '/', wait_until='networkidle')
        pg.wait_for_selector('#ingresoGoogle', state='visible')
        foto(pg, 'ingreso')
        anon.close()

        ctx = contexto(1440, 900, 1.5)
        pg = abrir_app(ctx)
        foto(pg, 'inicio')

        pg = abrir_app(ctx, '&open=' + ids['remito'])
        pg.wait_for_timeout(800)
        base.modo_documento(pg, 'full', 900)
        foto(pg, 'remito-documento-completo')
        base.modo_documento(pg, 'overlay', 900)
        foto(pg, 'remito-preimpreso')
        pg.click('#btnCalibrar')
        foto(pg, 'calibracion')
        pg.evaluate('closePanels()')
        pg.click('#btnAjustar')
        foto(pg, 'ajuste-por-campo')
        pg.evaluate('closePanels()')

        base.ir_a(pg, 'clientes', 900)
        foto(pg, 'clientes')
        csv = os.path.join(tempfile.gettempdir(), 'clientes-nuevos.csv')
        open(csv, 'w', encoding='utf-8').write(IMPORTAR)
        pg.set_input_files('#cliImportFile', csv)
        pg.wait_for_selector('#importModal.open')
        pg.wait_for_timeout(400)
        foto(pg, 'importar-clientes')
        pg.evaluate('closePanels()')

        abrir_comp(pg, 'factura', ids['factura'])
        foto(pg, 'factura')
        abrir_comp(pg, 'factura', ids['factura_credito'])
        foto(pg, 'factura-credito')
        abrir_comp(pg, 'nota', ids['nota'])
        foto(pg, 'nota-credito')
        abrir_comp(pg, 'recibo', ids['recibo'])
        foto(pg, 'recibo')
        abrir_comp(pg, 'factura', ids['factura'])
        pg.click('#tbCompCorreo')
        pg.wait_for_selector('#correoModal.open')
        pg.wait_for_timeout(400)
        foto(pg, 'correo')
        pg.evaluate('closePanels()')
        pg.click('#tbCompGuardados')
        foto(pg, 'comprobantes-guardados')
        pg.evaluate('closePanels()')
        pg.evaluate("""() => { confirmar({ titulo: '¿Eliminar el cliente?', aceptar: 'Eliminar', peligro: true,
            mensaje: '«DISTRIBUIDORA DEL SUR S.A.» se elimina de la agenda de todo el equipo.' }); }""")
        foto(pg, 'dialogo-confirmacion')
        pg.keyboard.press('Escape')

        base.ir_a(pg, 'config', 700)
        pg.click('.cfg-tab[data-tab=api]')
        pg.wait_for_timeout(1200)
        foto(pg, 'conexion-arca')
        pg.click('#certUpload')
        pg.wait_for_timeout(500)
        pg.evaluate("() => document.querySelectorAll('#certModal .pegar-pem')[1].open = true")
        pg.fill('#keyText', '-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7…\n-----END PRIVATE KEY-----')
        foto(pg, 'carga-certificado')
        pg.evaluate('closePanels()')
        pg.evaluate("""() => avisoServicioArca('El certificado no tiene autorizado el servicio de constatación de comprobantes (wscdc)'
            + ' en homologación. Hay que asociarlo desde el Administrador de Relaciones de ARCA (en homologación, desde WSASS).', 'homologacion')""")
        foto(pg, 'aviso-servicio-arca')
        pg.click('#avisoArcaCerrar')

        pg.click('.cfg-tab[data-tab=usuarios]')
        pg.wait_for_timeout(900)
        foto(pg, 'usuarios')
        pg.click('#btnNuevoUsuario')
        pg.fill('#usrNombre', 'Tomás Herrera')
        pg.fill('#usrEmail', 'tomas.herrera@intellihelp.tech')
        foto(pg, 'usuario-nuevo')
        pg.evaluate('closePanels()')

        panel = ctx.new_page()
        panel.goto(base.SITIO + '/panel.html', wait_until='networkidle')
        panel.wait_for_timeout(1500)
        for tab, nombre in (('estado', 'panel-estado'), ('cai', 'panel-cai'), ('claves', 'panel-claves'), ('registro', 'panel-actividad')):
            panel.click(f'[data-tab={tab}]')
            panel.wait_for_timeout(1000)
            foto(panel, nombre)
        ctx.close()

        # ---------------------------------------------------------- celular
        cel = contexto(390, 844, 3)
        pg = abrir_app(cel)
        foto(pg, 'mobile-inicio')
        pg.click('#btnNavToggle')
        foto(pg, 'mobile-menu')
        pg = abrir_app(cel, '&open=' + ids['remito'])
        foto(pg, 'mobile-remito')
        cel.close()
        b.close()
    limpiar()
    return hechas


if __name__ == '__main__':
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('filtros', nargs='*')
    ap.add_argument('--salida', default=os.path.join(RAIZ, 'docs', 'imagenes', 'capturas'))
    a = ap.parse_args()
    if not base.es_local():
        sys.exit('Las capturas se hacen solo contra el entorno local de pruebas')
    os.makedirs(a.salida, exist_ok=True)
    print('capturas:', ', '.join(capturar(a.salida, a.filtros)))
