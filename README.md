<p align="center">
  <img src="docs/imagenes/banner.png" alt="Remitos y Comprobantes — Intellihelp" width="100%">
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.2+-777BB4?logo=php&logoColor=white" alt="PHP 8.2+">
  <img src="https://img.shields.io/badge/MariaDB-11-003545?logo=mariadb&logoColor=white" alt="MariaDB">
  <img src="https://img.shields.io/badge/JavaScript-sin%20frameworks-F7DF1E?logo=javascript&logoColor=black" alt="JavaScript sin frameworks">
  <img src="https://img.shields.io/badge/ARCA-WSAA%20%C2%B7%20WSFEv1%20%C2%B7%20WSFECRED%20%C2%B7%20WSCDC-0B4C8C" alt="ARCA WSAA, WSFEv1, WSFECRED y WSCDC">
  <img src="https://img.shields.io/badge/Hosting-Hostinger-673DE6?logo=hostinger&logoColor=white" alt="Hostinger">
  <img src="https://img.shields.io/badge/uso-interno-663399" alt="Uso interno">
</p>

<p align="center">
  <b><a href="https://intellihelp.tech/remitos">intellihelp.tech/remitos</a></b>
</p>

---

## Descripción

**Remitos y Comprobantes de Intellihelp**: aplicación interna para emitir **remitos**, **facturas**, **recibos** y **notas de crédito y débito** con el diseño del talonario de la empresa, imprimirlos y guardarlos en PDF.

- El **remito** se imprime **sobre el talonario pre-impreso**, con cada dato calibrado al milímetro, o como **documento completo** en papel en blanco o PDF, con original y duplicado.
- Se **autoriza** con el **C.A.I.** del talonario vigente: número correlativo, código y vencimiento se asignan solos. Hasta entonces es un borrador que se imprime «SIN AUTORIZAR». Cada talonario se **constata con ARCA** (WSCDC) al cargarlo.
- **Remito digital** (RG 5678/2025): el remito autorizado puede viajar como PDF en el celular, con un QR para que el destinatario **confirme la recepción** desde su teléfono.
- Las **facturas y notas** A y B (y la **Factura de Crédito MiPyME**) se **emiten con CAE** a través de los web services de **ARCA** (WSFEv1), con número asignado por ARCA y **QR fiscal**. El **recibo de cobranza** es letra X, sin CAE (no es válido como factura, RG 1415 art. 10).
- **Datos compartidos**: clientes, remitos, comprobantes y ajustes viven en el servidor; todo el equipo trabaja sobre la misma información, con **usuarios y roles**.
- Si se corta la conexión, el documento en curso se sigue guardando en el equipo y se sube solo al volver la red.
- Otros sistemas pueden **crear remitos por API** y recibir un enlace que los abre listos para imprimir.

## Índice

- [Capturas](#capturas)
- [Funcionalidades](#funcionalidades)
- [Circuito de un remito](#circuito-de-un-remito)
- [Integración con ARCA](#integración-con-arca)
- [Arquitectura](#arquitectura)
- [Seguridad](#seguridad)
- [Instalación y despliegue](#instalación-y-despliegue)
- [Pruebas](#pruebas)
- [Autor](#autor)

## Capturas

> Las capturas usan datos ficticios de demostración y se regeneran con `python tools/capturas.py` contra el entorno local de pruebas.

### Ingreso

<p align="center"><img src="docs/imagenes/capturas/ingreso.png" alt="Ingreso" width="100%"></p>

### Inicio

Indicadores del día —**facturado en el mes** con CAE (notas de crédito restadas), **remitos autorizados sin facturar**, facturas y notas **sin autorizar** y números de **C.A.I. disponibles** con su vencimiento; las tarjetas con pendientes abren el listado—, accesos rápidos y documentos recientes con su estado (autorizado, facturado, sin autorizar o borrador sin guardar) y quién los modificó por última vez.

<p align="center"><img src="docs/imagenes/capturas/inicio.png" alt="Inicio" width="100%"></p>

### Remito

El formulario se completa a la izquierda y la vista previa se actualiza en el acto. El modo **sobre pre-impreso** muestra el talonario como guía tenue e imprime solo los datos; el **documento completo** genera el remito entero con el sello de autorizado.

<p align="center"><img src="docs/imagenes/capturas/remito-documento-completo.png" alt="Remito en documento completo" width="100%"></p>

<table>
  <tr>
    <td width="50%"><b>Sobre el talonario pre-impreso</b><br><img src="docs/imagenes/capturas/remito-preimpreso.png" alt="Remito sobre pre-impreso"></td>
    <td width="50%"><b>Calibración por impresora</b><br><img src="docs/imagenes/capturas/calibracion.png" alt="Calibración"></td>
  </tr>
  <tr>
    <td width="50%"><b>Ajuste fino de cada campo</b><br><img src="docs/imagenes/capturas/ajuste-por-campo.png" alt="Ajuste por campo"></td>
    <td width="50%"><b>Agenda de clientes</b><br><img src="docs/imagenes/capturas/clientes.png" alt="Clientes"></td>
  </tr>
</table>

### Facturas, recibos y notas

Diseño tomado de los comprobantes que emite ARCA: banda de ejemplar, letra y código, emisor, receptor, detalle con código, unidad, bonificación y alícuota de cada renglón, totales con el IVA de cada alícuota en la letra A (e «IVA contenido» en la B, Ley 27.743) y pie con CAE y QR fiscal. Sin CAE, el documento se imprime marcado «SIN AUTORIZAR». El recibo detalla las facturas que cancela.

<table>
  <tr>
    <td width="50%"><b>Factura A autorizada</b><br><img src="docs/imagenes/capturas/factura.png" alt="Factura"></td>
    <td width="50%"><b>Factura de Crédito Electrónica MiPyME</b><br><img src="docs/imagenes/capturas/factura-credito.png" alt="Factura de Crédito MiPyME"></td>
  </tr>
  <tr>
    <td width="50%"><b>Nota de crédito asociada a una factura</b><br><img src="docs/imagenes/capturas/nota-credito.png" alt="Nota de crédito"></td>
    <td width="50%"><b>Recibo de cobranza (no válido como factura)</b><br><img src="docs/imagenes/capturas/recibo.png" alt="Recibo"></td>
  </tr>
  <tr>
    <td width="50%"><b>Envío por correo, con el PDF adjunto</b><br><img src="docs/imagenes/capturas/correo.png" alt="Envío por correo"></td>
    <td width="50%"><b>Vista previa de la importación de clientes</b><br><img src="docs/imagenes/capturas/importar-clientes.png" alt="Importar clientes"></td>
  </tr>
  <tr>
    <td width="50%"><b>Comprobantes guardados, con importe y estado</b><br><img src="docs/imagenes/capturas/comprobantes-guardados.png" alt="Comprobantes guardados"></td>
    <td width="50%"><b>Confirmaciones con diseño propio</b><br><img src="docs/imagenes/capturas/dialogo-confirmacion.png" alt="Diálogo de confirmación"></td>
  </tr>
</table>

### Conexión con ARCA

El certificado y la clave privada se cargan una vez por ambiente, como archivo o pegando su texto. El servidor valida que se correspondan y los guarda cifrados; la pantalla solo muestra titular, CUIT, vigencia y huella. «Probar conexión» lista los puntos de venta habilitados y, si al certificado le falta un servicio de ARCA, una ventana indica cuál y cómo activarlo.

<table>
  <tr>
    <td width="50%"><b>Datos del emisor y certificado</b><br><img src="docs/imagenes/capturas/conexion-arca.png" alt="Conexión con ARCA"></td>
    <td width="50%"><b>Carga del certificado (archivo o texto)</b><br><img src="docs/imagenes/capturas/carga-certificado.png" alt="Carga del certificado"></td>
  </tr>
  <tr>
    <td width="50%"><b>Aviso de servicio de ARCA sin activar</b><br><img src="docs/imagenes/capturas/aviso-servicio-arca.png" alt="Servicio de ARCA sin activar"></td>
    <td width="50%"></td>
  </tr>
</table>

### Usuarios

<table>
  <tr>
    <td width="50%"><b>Usuarios y roles</b><br><img src="docs/imagenes/capturas/usuarios.png" alt="Usuarios"></td>
    <td width="50%"><b>Alta de usuario</b><br><img src="docs/imagenes/capturas/usuario-nuevo.png" alt="Nuevo usuario"></td>
  </tr>
</table>

### Panel de la API

<table>
  <tr>
    <td width="50%"><b>Talonarios de C.A.I.</b><br><img src="docs/imagenes/capturas/panel-cai.png" alt="Talonarios"></td>
    <td width="50%"><b>Claves de servicio</b><br><img src="docs/imagenes/capturas/panel-claves.png" alt="Claves"></td>
  </tr>
  <tr>
    <td width="50%"><b>Estado de los documentos</b><br><img src="docs/imagenes/capturas/panel-estado.png" alt="Estado"></td>
    <td width="50%"><b>Auditoría</b><br><img src="docs/imagenes/capturas/panel-actividad.png" alt="Actividad"></td>
  </tr>
</table>

### Celular

<p align="center">
  <img src="docs/imagenes/capturas/mobile-inicio.png" alt="Inicio en celular" width="30%">
  &nbsp;
  <img src="docs/imagenes/capturas/mobile-menu.png" alt="Menú en celular" width="30%">
  &nbsp;
  <img src="docs/imagenes/capturas/mobile-remito.png" alt="Remito en celular" width="30%">
</p>

## Funcionalidades

### Remitos
- Remito clase **R**, código **091**, con la leyenda «Documento no válido como factura» y los datos de autoimpresor del emisor.
- Dos modos de impresión con **calibraciones independientes**: sobre el talonario pre-impreso (24 ajustes de fábrica medidos sobre impresiones reales) y documento completo.
- Ejemplares **original y duplicado** (RG 1415, art. 14); el duplicado se imprime en beige, como el talonario.
- **Autorización** con el C.A.I.: número correlativo dentro del rango, código y vencimiento; bloqueo si el C.A.I. está vencido y avisos por pocos números o vencimiento próximo.
- El número fiscal solo existe en los remitos autorizados (único por punto de venta en la base); los borradores llevan una referencia interna. Un remito autorizado no se modifica.
- **Facturar este remito**: arma la factura con el cliente y los ítems del remito; cuando la factura obtiene el CAE en producción, el remito queda **facturado** solo (una factura de homologación no lo marca, porque no tiene validez fiscal).
- La fecha de un remito no puede ser posterior a hoy ni anterior a la del último autorizado del punto de venta (numeración correlativa), y un talonario vencido se saltea si hay otro vigente.
- **Contenido obligatorio de la RG 1415 (Anexo V)**: condición frente al IVA del emisor y del destinatario, CUIT y domicilio del destinatario, bienes con su cantidad, y razón social, CUIT y domicilio del transportista cuando el traslado lo hace un tercero. El sistema no autoriza un remito incompleto ni con un C.A.I. vencido.
- **Constatación del C.A.I.** con el web service de ARCA (WSCDC) al cargar cada talonario: CUIT, punto de venta, tipo, rango y vigencia. ARCA no ofrece un servicio para *pedir* el C.A.I.: se tramita con Clave Fiscal y el sistema avisa con tiempo cuando hace falta el próximo.
- **Remito digital** (RG 5678/2025): PDF de un solo ejemplar sin leyendas ORIGINAL/DUPLICADO, con el QR del enlace de conformidad; el destinatario confirma con nombre y documento y queda registrado con fecha y hora. Detalle de cada requisito en [`docs/normativa.md`](docs/normativa.md).
- Fecha automática en hora de Buenos Aires tomada del reloj del servidor; editable.
- Impresión de prueba con datos ficticios, hoja con grilla en milímetros y prueba de posiciones para calibrar.

### Facturas, recibos y notas de crédito y débito
- Letras **A y B**, las que emite Intellihelp como Responsable Inscripto (la C, de monotributistas y exentos, se rechaza), y X para documentos internos «no válidos como factura», con el código oficial de cada comprobante.
- **Condición frente al IVA del receptor** con la tabla oficial de ARCA (RG 5616): la letra se ajusta sola (A para inscriptos y monotributistas, B para el resto) y se valida antes de pedir el CAE.
- Renglones con **código, unidad de medida, bonificación y alícuota de IVA propia**, con el subtotal de cada uno a la vista. El IVA se calcula por alícuota y ARCA recibe un importe por cada una; la factura A impresa muestra la alícuota y el subtotal con IVA de cada renglón y el IVA de cada tasa, como el diseño de ARCA.
- **Tipo de documento del receptor** de la tabla de ARCA: CUIT, CUIL, DNI o consumidor final sin identificar.
- Concepto (productos o servicios con su período) y comprobante asociado obligatorio en las notas, **elegido de los comprobantes con CAE** (primero los del mismo cliente). Desde una factura autorizada, «Nota de crédito de este comprobante» arma la nota asociada.
- **Recibo de cobranza** (RG 1415, art. 10): respalda el pago de facturas ya emitidas, con importe en letras; no vale como factura ni lleva CAE. Se eligen las **facturas que cancela** con su saldo (o «Registrar el cobro» desde la factura), el monto y el concepto se completan solos, y los listados muestran cada factura como **cobrada** o con **cobro parcial**.
- **Envío por correo** desde la aplicación: el comprobante emitido o el remito autorizado sale desde la casilla de la empresa con su detalle, el enlace de verificación de ARCA (o el de conformidad del remito digital) y, si se quiere, el PDF adjunto. Las respuestas le llegan a quien lo envió.
- **Factura de Crédito Electrónica MiPyME** (Ley 27.440): si la empresa es MiPyME, al facturar a un CUIT se consulta a ARCA si el cliente está obligado a recibirla y sale como tal, con CBU y opción de transferencia; sus notas siguen al comprobante asociado.
- Una factura B desde $ 10.000.000 exige identificar al comprador, como valida ARCA.
- Importes en formato argentino (`1.234,56`) con cuentas en centavos: la interfaz y el servidor calculan exactamente lo mismo y el servidor recalcula antes de emitir.
- **Emisión electrónica**: el servidor toma el número siguiente al último autorizado y pide el **CAE** (`FECAESolicitar`) en nombre del CUIT de la empresa, aunque el certificado sea de un representante; si la respuesta se pierde, el reintento la recupera de ARCA sin pedir otro número.
- **QR fiscal** (RG 4892) generado en el navegador. Un comprobante autorizado no se modifica: se corrige con una nota de crédito.

### Datos y operación
- **Agenda de clientes compartida** en tabla, con búsqueda, orden por columna y accesos directos a remito y factura. Importación desde **CSV o Excel** con **vista previa** (nuevos, actualizaciones por CUIT y filas con error, antes de cargar nada; detección de encabezados en español, con o sin acentos).
- **Calibración** con controles deslizantes y el **valor exacto** escrito a mano (en milímetros o porcentaje).
- En el **celular**, Inicio muestra la fecha, el importe y las acciones de cada documento.
- **Numeración asignada por el servidor**: dos personas autorizando a la vez nunca reciben el mismo número, y un reintento tras un corte no duplica el documento (`id_local`).
- **Respaldo cifrado** del servidor por consola (diario con `cron`), con verificación y restauración probada; copia descargable para administradores.
- **Borrador protegido**: el documento en curso se guarda en el equipo mientras se escribe; ante un corte, queda pendiente y se sube solo al volver la red. Se borra al cerrar sesión y a los 7 días sin uso.
- Listados de comprobantes guardados con **búsqueda** (cliente, CUIT o número), filtro por estado (con CAE, sin autorizar, internos) y **total** de lo que se ve.
- Formularios con controles propios (desplegables, calendario, máscaras de fecha, hora, CUIT, importes): los **errores se marcan en el campo** con su explicación y el foco va al primero; nada de globos ni selectores nativos del navegador. Los paneles laterales son diálogos accesibles (foco atrapado y devuelto al cerrar).
- Atajos **Ctrl+S** y **Ctrl+P** según el documento en pantalla.

### Usuarios y acceso
- Ingreso con **ITHub**, la sesión única de Intellihelp: si la persona ya entró a ITHub, abre Remitos sin ver ninguna pantalla. ITHub valida la cuenta de Google de Intellihelp y decide **quién entra y con qué rol**; salir de Remitos cierra la sesión única (todas las aplicaciones) y, cuando ITHub cierra una sesión o le quita Remitos a alguien, la sesión de Remitos se corta sola. La aplicación no tiene contraseñas ni segundo factor propios. Las operaciones sensibles se confirman con el ingreso de los últimos 15 minutos.
- Una instalación sin ITHub configurado ingresa con la **cuenta de Google** de Intellihelp (`@intellihelp.tech`), como antes.
- Roles **operador** (documentos y clientes) y **administrador** (además usuarios, ajustes, certificado de ARCA, talonarios y panel). Con ITHub se asignan en **ITHub → Permisos** y se aplican en cada ingreso; el usuario de Remitos se crea solo la primera vez.
- Sin ITHub, el administrador da de alta a cada persona con su correo de Intellihelp y su rol, y la activa o desactiva.
- La calibración de impresión queda en cada equipo, porque depende de su impresora.

### Panel de la API
- Estado de los remitos y pendientes de facturar.
- **Talonarios de C.A.I.**: alta con validación de rango y superposición, semáforo de vencimiento y uso.
- **Claves de servicio** con permisos `leer`, `crear` y `borrar`, contador de uso y revocación inmediata.
- **Auditoría** por categoría, paginada, con origen (aplicación o API), usuario e IP, encadenada con hashes y verificable desde el panel.

## Circuito de un remito

```mermaid
flowchart LR
    A[Carga del remito<br>o alta por API] --> B[Vista previa]
    B --> C{¿Talonario<br>de C.A.I. vigente?}
    C -- Sí --> D[Autorizar]
    D -->|Número correlativo<br>C.A.I. y vencimiento| E[Remito autorizado]
    C -- No --> F[Sin autorizar]
    E --> G[Impresión<br>original y duplicado]
    F --> G
    E --> H[(Servidor:<br>visible para todo el equipo)]
    F --> H
```

| Estado | Significado |
|---|---|
| Borrador sin guardar | En edición; se guarda en el equipo de quien lo carga y se recupera tras un corte |
| Sin autorizar | Guardado en el servidor con una referencia interna; se imprime con el sello SIN AUTORIZAR |
| Autorizado | Con número del talonario, C.A.I. y vencimiento; lleva el sello AUTORIZADO y ya no se modifica |
| Facturado | Autorizado y con su factura; sale de los pendientes de facturar |

## Integración con ARCA

| Servicio | Uso |
|---|---|
| **WSAA** | Autenticación: el servidor firma el pedido de acceso (CMS/PKCS#7) con el certificado y guarda el ticket mientras está vigente (hasta 12 horas) |
| **WSFEv1 · FEDummy** | Verificación del estado de los servidores de ARCA |
| **WSFEv1 · FECompUltimoAutorizado** | Último número autorizado por punto de venta y tipo: el comprobante toma el siguiente |
| **WSFEv1 · FECAESolicitar** | Emisión: CAE y vencimiento de facturas y notas A y B, y de la Factura de Crédito MiPyME y sus notas (con sus opcionales) |
| **WSFEv1 · FEParamGetPtosVenta** | Puntos de venta habilitados para web services, que muestra «Probar conexión» |
| **WSFEv1 · FECompConsultar** | Recupera el CAE de un comprobante cuya respuesta se perdió, antes de pedir otro número |
| **WSFECRED · consultarMontoObligadoRecepcion** | Si el cliente está obligado a recibir Factura de Crédito MiPyME y desde qué importe |
| **WSCDC · ComprobanteConstatar** | Constata cada talonario de C.A.I. (CUIT, punto de venta, tipo, rango y vigencia) al cargarlo |
| **C.A.I.** | ARCA no ofrece web service para solicitarlo: se tramita con Clave Fiscal y se carga en el panel una vez por rango |

Todos los servicios se invocan en nombre del CUIT de la empresa (`Auth.Cuit`): el certificado puede ser de la empresa o de un representante con los servicios delegados. Ambientes de **homologación** y **producción**, cada uno con su propio certificado. El instructivo paso a paso para obtener el certificado está en [`app/docs/guia-credenciales-arca.pdf`](app/docs/guia-credenciales-arca.pdf) y la referencia de la API en [`docs/api.md`](docs/api.md).

## Arquitectura

Aplicación de **una sola página** en HTML, CSS y JavaScript sin frameworks, con una **API en PHP 8 sin framework** sobre MySQL/MariaDB, pensada para hosting compartido.

```
├── app/                     # Se publica tal cual en public_html/remitos
│   ├── index.html           # Aplicación
│   ├── panel.html           # Panel de administración de la API
│   ├── conformidad.html     # Página pública para confirmar la recepción de un remito digital
│   ├── css/                 # styles (aplicación) · panel · conformidad · controles (diálogos y controles
│   │                        # de formulario comunes) · fuentes (Saira autoalojada)
│   ├── js/
│   │   ├── config.js        # Papel, posición de cada campo, calibración de fábrica, datos del emisor
│   │   ├── calculos.js      # Importes, IVA, CUIT y montos en letras (también se prueba con Node)
│   │   ├── app.js           # Edición, vista previa, impresión, emisión, agenda y configuración
│   │   ├── servidor.js      # API, sesión, ingreso con ITHub o Google, usuarios y datos compartidos
│   │   ├── dialogo.js       # Diálogo de confirmación propio (en lugar del nativo del navegador)
│   │   ├── conformidad.js   # Página pública de conformidad del remito digital
│   │   ├── panel.js         # Panel de la API
│   │   └── lib/             # SheetJS y generador de QR (versión y hash en docs/dependencias.md)
│   ├── api/
│   │   ├── bootstrap.php    # Arranque común y manejo de errores
│   │   ├── lib/             # http · db (migraciones) · session · users · ithub · google · auth · audit · crypto
│   │   │                    # · importes · ajustes · normativa · arca (WSAA, WSFEv1, WSFECRED, WSCDC)
│   │   ├── ithub.php        # Ingreso con ITHub (sesión única) y vuelta desde ITHub
│   │   ├── ithub-logout.php # Aviso de cierre de ITHub (back-channel logout)
│   │   ├── google.php       # Ingreso con Google (sin ITHub configurado)
│   │   ├── sesion.php       # Estado de la sesión y salida
│   │   ├── usuarios.php     # Administración de usuarios
│   │   ├── clientes.php     # Agenda de clientes
│   │   ├── remitos.php      # Remitos (numeración asignada por el servidor)
│   │   ├── comprobantes.php # Facturas, notas (emisión con CAE) y recibos X
│   │   ├── ajustes.php      # Datos del emisor y punto de venta
│   │   ├── talonarios.php   # C.A.I. y autorización de remitos
│   │   ├── arca.php         # Credenciales y conexión con ARCA
│   │   ├── proximo.php      # Próximo número según ARCA
│   │   ├── estado.php       # Seguimiento sin datos confidenciales
│   │   ├── claves.php       # Claves de servicio y auditoría
│   │   ├── conformidad.php  # Conformidad del destinatario de un remito digital (público, con token)
│   │   ├── evento.php       # Eventos de la interfaz (catálogo cerrado)
│   │   ├── exportar.php     # Copia de los datos (solo administradores, auditada)
│   │   ├── cli/             # migrar · usuario · respaldo · restaurar · rotar-clave
│   │   └── config.sample.php
│   ├── docs/                # Instructivo de alta en ARCA (HTML y PDF)
│   └── assets/              # Logos y fuente Saira
├── docs/
│   ├── api.md · normativa.md · seguridad.md · dependencias.md
│   ├── historial/           # Revisiones y remediaciones anteriores
│   └── imagenes/            # Banner y capturas de pantalla del README
├── test/                    # Batería (Playwright) y pruebas unitarias (Node y PHP)
├── tools/
│   ├── capturas.py          # Regenera todas las capturas del README contra el entorno local
│   └── guia_pdf.py          # Genera el instructivo de ARCA en PDF desde su HTML
└── .github/workflows/       # Integración continua
```

| Capa | Tecnología |
|---|---|
| Interfaz | HTML + CSS propio + JavaScript sin frameworks; SheetJS (solo al importar Excel) y un generador de QR, incluidos con versión y hash |
| Datos | En el servidor; en el equipo solo la calibración de la impresora y el borrador en curso |
| Servidor | PHP 8.2+ (PDO, OpenSSL, cURL, DOM) |
| Base de datos | MariaDB 10.6+ / MySQL 8, esquema versionado con migraciones idempotentes |
| Integraciones | ARCA WSAA, WSFEv1, WSCDC y WSFECRED (SOAP) |
| Calidad | PSR-12 (PHP-CS-Fixer), Prettier, batería de pruebas de API e interfaz, pruebas unitarias y CI en GitHub Actions |
| Identidad | Tipografía Saira · paleta `#663399` · `#9f88b1` · `#fff6ff` · `#fffade` · colores planos |

## Seguridad

**Usuarios y sesión**
- **Ingreso con ITHub** (sesión única de Intellihelp), sin contraseñas propias: OpenID Connect con PKCE resuelto en el servidor; el `id_token` se valida entero (firma RS256 con la clave pública de ITHub, emisor, destinatario, vencimiento y nonce) y el rol se toma solo de un token válido. El secreto del cliente vive solo en `config.php`. **Aviso de cierre** (back-channel logout): cuando ITHub cierra una sesión, la de Remitos con ese `sid` se corta en el pedido siguiente.
- Sin ITHub configurado, **ingreso con Google**: OpenID Connect con PKCE resuelto en el servidor, limitado al dominio `intellihelp.tech` y a usuarios dados de alta. En ningún caso hay contraseñas ni secretos de segundo factor que puedan filtrarse de la base.
- **Operaciones sensibles** (certificados de ARCA, claves de API, alta de usuarios y rol de administrador, exportación, CUIT/CBU/ambiente del emisor): exigen haber confirmado la identidad (con ITHub o Google) en los últimos 15 minutos, con la misma cuenta de la sesión.
- Cookie de sesión `HttpOnly`, `Secure` y `SameSite=Strict`, limitada a la ruta de la aplicación; identificador regenerado al ingresar.
- **Token CSRF** obligatorio en toda escritura, que además debe ser JSON y venir del propio origen (también el ingreso); vencimiento por inactividad (8 h) y absoluto (12 h).
- **Freno de ingresos fallidos**: 20 vueltas fallidas desde ITHub o desde Google por conexión en 15 minutos.
- Cambiar el rol o desactivar a un usuario cierra sus sesiones en el acto. Siempre queda al menos un administrador.

**Credenciales de ARCA**
- El certificado y la clave privada **nunca se guardan en el navegador**: los carga un administrador que confirmó su identidad con Google en los últimos 15 minutos, y se envían una sola vez al servidor por **HTTPS obligatorio**.
- El servidor valida que sean PEM correctos, que la clave sea RSA de 2048 bits o más y corresponda al certificado, y que esté vigente. El certificado puede ser del emisor o de un representante con los servicios delegados: la relación la confirma «Probar conexión» (los pedidos van siempre a nombre del CUIT del emisor, `Auth.Cuit`).
- Se almacenan **cifrados con AES-256-GCM**. La clave de cifrado vive solo en `config.php` (fuera del repositorio y de la base) y se deriva una subclave por propósito con **HKDF-SHA256**. Cada valor queda ligado a su ambiente y su campo como dato autenticado: un valor copiado a otra fila no se descifra.
- Los tickets de WSAA también se guardan cifrados y se piden de a uno (bloqueo). La firma se hace en el servidor: los archivos temporales solo contienen el pedido y la firma, con permisos `0600`, y se eliminan siempre.
- El XML de ARCA se interpreta sin acceso a red ni entidades externas; los errores de ARCA o de la conexión se informan de forma genérica.
- `app_key` se puede rotar con un comando que vuelve a cifrar todos los secretos en una sola operación.
- Las respuestas de la API nunca incluyen el certificado ni la clave: solo titular, CUIT, vigencia y huella SHA-256.

**API**
- Claves con prefijo `ith_` y 128 bits de entropía. De la maestra y de las de servicio se guarda solo el **SHA-256**; la comparación es en tiempo constante.
- Permisos mínimos por clave, revocación inmediata y **bloqueo temporal** por IP tras 20 intentos rechazados en 10 minutos. CORS solo para orígenes configurados.
- Consultas **parametrizadas** (PDO sin emulación), validación y recorte de todos los datos de entrada y límite de tamaño de 256 KB por petición.
- Errores internos registrados solo en el log del servidor; el cliente recibe un mensaje genérico.
- `config.php` (y cualquier copia `config.php.*`), `bootstrap.php`, `lib/` y `cli/` bloqueados para la web; la clave nunca viaja en la URL.
- **Auditoría** de los eventos relevantes (accesos rechazados, emisiones, ediciones, exportaciones) con usuario, IP y **hash encadenado**; se conserva 400 días y se depura solo por antigüedad. Los intentos fallidos se cuentan en otra tabla.
- Los CAE y C.A.I. solo los asigna el servidor; la numeración fiscal es única en la base y los documentos autorizados no se modifican ni se eliminan (ni siquiera un administrador): se conservan por el plazo legal.
- Migraciones del esquema por consola en el despliegue: la web nunca ejecuta DDL.
- Los bloqueos con nombre (GET_LOCK) llevan el nombre de la base, porque en el hosting compartido valen para todo el servidor MySQL. La conexión fija `wait_timeout = 300`: el hosting corta a los 20 s y una emisión con ARCA puede tardar más.

**Aplicación**
- **Content-Security-Policy** sin recursos de terceros (fuente incluida) y sin scripts ni hojas de estilo inline, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, HSTS y redirección a HTTPS.
- Dependencias incluidas con versión fija y hash verificado (SheetJS 0.20.3, sin CVE conocidas).
- Escape de todo dato mostrado en pantalla.
- El panel de la API se abre con la sesión de un administrador; una clave de API solo se conserva mientras la pestaña está abierta (`sessionStorage`).

Modelo de amenazas, respaldos, rotación de claves, respuesta a incidentes y retención de datos personales: [`docs/seguridad.md`](docs/seguridad.md). Historial de la revisión de noviembre de 2026 (anterior al ingreso solo con Google): [`docs/historial/remediacion-2026-11.md`](docs/historial/remediacion-2026-11.md).

## Instalación y despliegue

### Requisitos
- PHP 8.2+ con `pdo_mysql`, `openssl`, `curl`, `dom` y `mbstring`
- MariaDB 10.6+ o MySQL 8
- Servidor web Apache o LiteSpeed con `.htaccess` habilitado

### Pasos

```bash
# 1. Publicar el contenido de app/ en public_html/remitos/

# 2. Configuración de la API
cp app/api/config.sample.php app/api/config.php
#    completar la base de datos y generar las claves:
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"                                   # app_key
php -r "\$k='ith_'.bin2hex(random_bytes(24)); echo \$k, PHP_EOL, hash('sha256',\$k), PHP_EOL;"   # clave maestra y su hash

# 3. Esquema de la base (también en cada despliegue posterior):
php app/api/cli/migrar.php

# 4. Primer administrador (sin ITHub; con ITHub, el acceso y el rol se dan en ITHub → Permisos):
php app/api/cli/usuario.php "Nombre Apellido" correo@intellihelp.tech admin
#    después ingresa con «Ingresar con Google» usando esa cuenta

# 5. Respaldo diario cifrado (cron del hosting):
#    15 3 * * * php ~/public_html/remitos/api/cli/respaldo.php >> ~/backups/remitos/respaldo.log 2>&1
```

Después:
1. Ingresar a la aplicación con el administrador y crear los usuarios del equipo en **Configuración → Usuarios**.
2. En **Configuración → Conexión con ARCA**: CUIT, puntos de venta y el certificado.
3. Cargar el talonario de C.A.I. desde el **Panel de la API** según el instructivo.

### Ingreso con ITHub (sesión única)

Con ITHub, Remitos no tiene pantalla de ingreso propia: la aplicación manda a ITHub y, si la persona ya entró ahí, pasa sin ver nada. El acceso y el rol (`admin` u `operador`) se administran en **ITHub → Permisos**.

1. En **ITHub → Aplicaciones → Remitos y Comprobantes**:

   | Campo | Valor |
   | --- | --- |
   | Roles | `admin`, `operador` (el de más jerarquía primero) |
   | URIs de vuelta | `https://remitos.intellihelp.tech/api/ithub.php` y `https://intellihelp.tech/remitos/api/ithub.php` |
   | URIs después de salir | `https://remitos.intellihelp.tech/` y `https://intellihelp.tech/remitos/` |
   | Aviso de cierre | `https://remitos.intellihelp.tech/api/ithub-logout.php` |

   y **Generar secreto** (se muestra una sola vez).

2. En `config.php`, la sección `ithub` (ver `config.sample.php`) con `client_id` `remitos` y ese secreto.

3. `php api/cli/migrar.php` (versión 7 del esquema: tabla `ithub_sesiones_cerradas`, donde el aviso de cierre anota las sesiones de ITHub que se cerraron).

Sin la sección `ithub`, la aplicación sigue ingresando con Google como siempre. Para ver la pantalla de ingreso sin que redirija sola: `https://remitos.intellihelp.tech/?manual=1`.

Los recursos se referencian con `?v=NNN`: al publicar cambios en CSS o JS se incrementa la versión en `index.html`, `panel.html` y `conformidad.html`. En cada despliegue: publicar `app/`, correr `php api/cli/migrar.php` y verificar las cabeceras (ver [`docs/seguridad.md`](docs/seguridad.md)).

## Pruebas

```bash
pip install -r test/requirements.txt && playwright install chromium
python test/correr.py                         # batería de API e interfaz
node --test test/unitarias/calculos.test.js   # unitarias de la interfaz
php test/unitarias/importes.test.php          # unitarias del servidor
php test/unitarias/ithub.test.php             # unitarias del ingreso con ITHub
```

**132 casos** automatizados sobre la aplicación en el navegador y la API —impresión y ejemplares, reglas oficiales de emisión de ARCA (letra según la condición frente al IVA, Factura de Crédito MiPyME, recibo de cobranza), IVA y bonificación por renglón, recibo que cancela facturas, envío por correo, formularios y formatos, documentos PDF, inicio y recuperación de borradores, C.A.I. y autorizaciones simultáneas, inmutabilidad de lo autorizado, contenido obligatorio del remito, remito digital y conformidad del destinatario, usuarios, ingreso con Google (contra un Google simulado) e ingreso con ITHub (contra un ITHub simulado que firma con RS256: alta con el rol del token, rechazos, salida por la sesión única y aviso de cierre) y roles, datos compartidos, autenticación y permisos, credenciales de ARCA (incluida la firma real contra WSAA de homologación) y validación de datos— más pruebas unitarias en Node y PHP sobre los mismos casos de importes, IVA y CUIT. La integración continua las corre en cada cambio. Ver [`test/LEEME.md`](test/LEEME.md).

## Autor

**Brian Sierra** — Administrador de sistemas (SysAdmin) en Intellihelp · [LinkedIn](https://www.linkedin.com/in/brian-gabriel-sierra/) · [GitHub](https://github.com/bsierra4262)

Diseño, desarrollo e implementación completos: emisión de remitos y comprobantes, integración con los web services de ARCA, remito digital con QR, usuarios y roles, API para otros sistemas y despliegue.

---

<p align="center">
  <sub>© 2026 Intellihelp · Software de uso interno. Todos los derechos reservados.</sub>
</p>
