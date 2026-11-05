<p align="center">
  <img src="docs/banner.png" alt="Remitos y Comprobantes — Intellihelp" width="100%">
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.2+-777BB4?logo=php&logoColor=white" alt="PHP 8.2+">
  <img src="https://img.shields.io/badge/MariaDB-11-003545?logo=mariadb&logoColor=white" alt="MariaDB">
  <img src="https://img.shields.io/badge/JavaScript-sin%20frameworks-F7DF1E?logo=javascript&logoColor=black" alt="JavaScript sin frameworks">
  <img src="https://img.shields.io/badge/ARCA-WSAA%20%C2%B7%20WSFEv1-0B4C8C" alt="ARCA WSAA y WSFEv1">
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
- Las **facturas, recibos y notas** se **emiten con CAE** a través de los web services de **ARCA** (WSFEv1), con número asignado por ARCA y **QR fiscal**.
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

> Las capturas usan datos ficticios de demostración.

### Ingreso

<p align="center"><img src="docs/screenshots/ingreso.png" alt="Ingreso" width="100%"></p>

### Inicio

Accesos rápidos, totales y documentos recientes con su estado (autorizado, facturado, sin autorizar o borrador sin guardar) y quién los modificó por última vez.

<p align="center"><img src="docs/screenshots/inicio.png" alt="Inicio" width="100%"></p>

### Remito

El formulario se completa a la izquierda y la vista previa se actualiza en el acto. El modo **sobre pre-impreso** muestra el talonario como guía tenue e imprime solo los datos; el **documento completo** genera el remito entero con el sello de autorizado.

<p align="center"><img src="docs/screenshots/remito-documento-completo.png" alt="Remito en documento completo" width="100%"></p>

<table>
  <tr>
    <td width="50%"><b>Sobre el talonario pre-impreso</b><br><img src="docs/screenshots/remito-preimpreso.png" alt="Remito sobre pre-impreso"></td>
    <td width="50%"><b>Calibración por impresora</b><br><img src="docs/screenshots/calibracion.png" alt="Calibración"></td>
  </tr>
  <tr>
    <td width="50%"><b>Ajuste fino de cada campo</b><br><img src="docs/screenshots/ajuste-por-campo.png" alt="Ajuste por campo"></td>
    <td width="50%"><b>Agenda de clientes</b><br><img src="docs/screenshots/clientes.png" alt="Clientes"></td>
  </tr>
</table>

### Facturas, recibos y notas

Diseño tomado de los comprobantes que emite ARCA: banda de ejemplar, letra y código, emisor, receptor, detalle, totales con IVA discriminado en la letra A (e «IVA contenido» en la B, Ley 27.743) y pie con CAE y QR fiscal. Sin CAE, el documento se imprime marcado «SIN AUTORIZAR».

<table>
  <tr>
    <td width="50%"><b>Factura A</b><br><img src="docs/screenshots/factura.png" alt="Factura"></td>
    <td width="50%"><b>Recibo con importe en letras</b><br><img src="docs/screenshots/recibo.png" alt="Recibo"></td>
  </tr>
</table>

### Conexión con ARCA

El certificado y la clave privada se cargan una vez por ambiente. El servidor valida que se correspondan y los guarda cifrados; la pantalla solo muestra titular, CUIT, vigencia y huella.

<table>
  <tr>
    <td width="50%"><b>Datos del emisor y certificado</b><br><img src="docs/screenshots/conexion-arca.png" alt="Conexión con ARCA"></td>
    <td width="50%"><b>Carga segura del certificado</b><br><img src="docs/screenshots/carga-certificado.png" alt="Carga del certificado"></td>
  </tr>
</table>

### Usuarios

<table>
  <tr>
    <td width="50%"><b>Usuarios y roles</b><br><img src="docs/screenshots/usuarios.png" alt="Usuarios"></td>
    <td width="50%"><b>Alta con contraseña temporal</b><br><img src="docs/screenshots/usuario-nuevo.png" alt="Nuevo usuario"></td>
  </tr>
</table>

### Panel de la API

<table>
  <tr>
    <td width="50%"><b>Talonarios de C.A.I.</b><br><img src="docs/screenshots/panel-cai.png" alt="Talonarios"></td>
    <td width="50%"><b>Claves de servicio</b><br><img src="docs/screenshots/panel-claves.png" alt="Claves"></td>
  </tr>
  <tr>
    <td width="50%"><b>Estado de los documentos</b><br><img src="docs/screenshots/panel-estado.png" alt="Estado"></td>
    <td width="50%"><b>Auditoría</b><br><img src="docs/screenshots/panel-actividad.png" alt="Actividad"></td>
  </tr>
</table>

### Celular

<p align="center">
  <img src="docs/screenshots/mobile-inicio.png" alt="Inicio en celular" width="30%">
  &nbsp;
  <img src="docs/screenshots/mobile-menu.png" alt="Menú en celular" width="30%">
  &nbsp;
  <img src="docs/screenshots/mobile-remito.png" alt="Remito en celular" width="30%">
</p>

## Funcionalidades

### Remitos
- Remito clase **R**, código **091**, con la leyenda «Documento no válido como factura» y los datos de autoimpresor del emisor.
- Dos modos de impresión con **calibraciones independientes**: sobre el talonario pre-impreso (24 ajustes de fábrica medidos sobre impresiones reales) y documento completo.
- Ejemplares **original y duplicado** (RG 1415, art. 14); el duplicado se imprime en beige, como el talonario.
- **Autorización** con el C.A.I.: número correlativo dentro del rango, código y vencimiento; bloqueo si el C.A.I. está vencido y avisos por pocos números o vencimiento próximo.
- El número fiscal solo existe en los remitos autorizados (único por punto de venta en la base); los borradores llevan una referencia interna. Un remito autorizado no se modifica y se marca como **facturado** cuando tiene su factura.
- **Contenido obligatorio de la RG 1415 (Anexo V)**: condición frente al IVA del emisor y del destinatario, CUIT y domicilio del destinatario, bienes con su cantidad, y razón social, CUIT y domicilio del transportista cuando el traslado lo hace un tercero. El sistema no autoriza un remito incompleto ni con un C.A.I. vencido.
- **Constatación del C.A.I.** con el web service de ARCA (WSCDC) al cargar cada talonario: CUIT, punto de venta, tipo, rango y vigencia. ARCA no ofrece un servicio para *pedir* el C.A.I.: se tramita con Clave Fiscal y el sistema avisa con tiempo cuando hace falta el próximo.
- **Remito digital** (RG 5678/2025): PDF de un solo ejemplar sin leyendas ORIGINAL/DUPLICADO, con el QR del enlace de conformidad; el destinatario confirma con nombre y documento y queda registrado con fecha y hora. Detalle de cada requisito en [`docs/normativa.md`](docs/normativa.md).
- Fecha automática en hora de Buenos Aires tomada del reloj del servidor; editable.
- Impresión de prueba con datos ficticios, hoja con grilla en milímetros y prueba de posiciones para calibrar.

### Facturas, recibos y notas de crédito y débito
- Letras A, B, C y X con el código oficial de cada comprobante.
- Ítems, alícuotas de IVA de la tabla de ARCA, concepto (productos o servicios con su período), condición frente al IVA del receptor, comprobante asociado en las notas e importe en letras en el recibo.
- Importes en formato argentino (`1.234,56`) con cuentas en centavos: la interfaz y el servidor calculan exactamente lo mismo y el servidor recalcula antes de emitir.
- **Emisión electrónica**: el servidor toma el número siguiente al último autorizado y pide el **CAE** (`FECAESolicitar`); si la respuesta se pierde, el reintento la recupera de ARCA sin pedir otro número.
- **QR fiscal** (RG 4892) generado en el navegador. Un comprobante autorizado no se modifica: se corrige con una nota de crédito.

### Datos y operación
- **Agenda de clientes compartida** con búsqueda e importación desde **CSV o Excel** (detección de encabezados en español, con o sin acentos; actualiza por CUIT sin duplicar).
- **Numeración asignada por el servidor**: dos personas autorizando a la vez nunca reciben el mismo número, y un reintento tras un corte no duplica el documento (`id_local`).
- **Respaldo cifrado** del servidor por consola (diario con `cron`), con verificación y restauración probada; copia descargable para administradores.
- **Borrador protegido**: el documento en curso se guarda en el equipo mientras se escribe; ante un corte, queda pendiente y se sube solo al volver la red. Se borra al cerrar sesión y a los 7 días sin uso.
- Atajos **Ctrl+S** y **Ctrl+P** según el documento en pantalla.

### Usuarios y acceso
- Ingreso con **correo y contraseña** y **verificación en dos pasos** opcional (TOTP); contraseña temporal en el alta, con cambio obligatorio en el primer ingreso.
- Roles **operador** (documentos y clientes) y **administrador** (además usuarios, ajustes, certificado de ARCA, talonarios y panel).
- «Mi cuenta» para cambiar la contraseña; el administrador genera contraseñas temporales y activa o desactiva usuarios.
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
| **WSFEv1 · FECAESolicitar** | Emisión: CAE y vencimiento de facturas, notas y recibos A, B y C |
| **WSFEv1 · FECompConsultar** | Recupera el CAE de un comprobante cuya respuesta se perdió, antes de pedir otro número |
| **WSCDC · ComprobanteConstatar** | Constata cada talonario de C.A.I. (CUIT, punto de venta, tipo, rango y vigencia) al cargarlo |
| **C.A.I.** | ARCA no ofrece web service para solicitarlo: se tramita con Clave Fiscal y se carga en el panel una vez por rango |

Ambientes de **homologación** y **producción**, cada uno con su propio certificado. El instructivo paso a paso para obtener el certificado está en [`app/docs/guia-credenciales-arca.pdf`](app/docs/guia-credenciales-arca.pdf) y la referencia de la API en [`docs/api.md`](docs/api.md).

## Arquitectura

Aplicación de **una sola página** en HTML, CSS y JavaScript sin frameworks, con una **API en PHP 8 sin framework** sobre MySQL/MariaDB, pensada para hosting compartido.

```
├── app/                     # Se publica tal cual en public_html/remitos
│   ├── index.html           # Aplicación
│   ├── panel.html           # Panel de administración de la API
│   ├── conformidad.html     # Página pública para confirmar la recepción de un remito digital
│   ├── css/                 # Estilos de la aplicación y del panel, y la fuente autoalojada
│   ├── js/
│   │   ├── config.js        # Papel, posición de cada campo, calibración de fábrica, datos del emisor
│   │   ├── calculos.js      # Importes, IVA, CUIT y montos en letras (también se prueba con Node)
│   │   ├── app.js           # Edición, vista previa, impresión, emisión, agenda y configuración
│   │   ├── servidor.js      # API, sesión, ingreso, segundo factor, usuarios y datos compartidos
│   │   ├── panel.js         # Panel de la API
│   │   └── lib/             # SheetJS y generador de QR (versión y hash en docs/dependencias.md)
│   ├── api/
│   │   ├── bootstrap.php    # Arranque común y manejo de errores
│   │   ├── lib/             # http · db (migraciones) · session · users · totp · auth · audit · crypto
│   │   │                    # · importes · ajustes · normativa · arca (WSAA, WSFEv1, WSCDC)
│   │   ├── sesion.php       # Ingreso, segundo factor, salida y cambio de contraseña
│   │   ├── usuarios.php     # Administración de usuarios
│   │   ├── clientes.php     # Agenda de clientes
│   │   ├── remitos.php      # Remitos (numeración asignada por el servidor)
│   │   ├── comprobantes.php # Facturas, recibos y notas; emisión con CAE
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
├── docs/                    # API, normativa, seguridad, dependencias, remediación, banner y capturas
├── test/                    # Batería (Playwright) y pruebas unitarias (Node y PHP)
├── tools/                   # Generación del instructivo en PDF
└── .github/workflows/       # Integración continua
```

| Capa | Tecnología |
|---|---|
| Interfaz | HTML + CSS propio + JavaScript sin frameworks; SheetJS (solo al importar Excel) y un generador de QR, incluidos con versión y hash |
| Datos | En el servidor; en el equipo solo la calibración de la impresora y el borrador en curso |
| Servidor | PHP 8.2+ (PDO, OpenSSL, cURL, DOM) |
| Base de datos | MariaDB 10.6+ / MySQL 8, esquema versionado con migraciones idempotentes |
| Integraciones | ARCA WSAA y WSFEv1 (SOAP) |
| Calidad | PSR-12 (PHP-CS-Fixer), Prettier, batería de pruebas de API e interfaz, pruebas unitarias y CI en GitHub Actions |
| Identidad | Tipografía Saira · paleta `#663399` · `#9f88b1` · `#fff6ff` · `#fffade` · colores planos |

## Seguridad

**Usuarios y sesión**
- Contraseñas con `password_hash` (bcrypt); mínimo 12 caracteres, sin palabras comunes, secuencias ni el nombre de usuario; contraseña temporal con cambio obligatorio.
- **Verificación en dos pasos** (TOTP, RFC 6238) opcional, con secreto cifrado y códigos de un solo uso.
- Cookie de sesión `HttpOnly`, `Secure` y `SameSite=Strict`, limitada a la ruta de la aplicación; identificador regenerado al ingresar.
- **Token CSRF** obligatorio en toda escritura, que además debe ser JSON y venir del propio origen (también el ingreso); vencimiento por inactividad (8 h) y absoluto (12 h).
- **Freno de ingresos fallidos** por correo y conexión, por conexión y por cuenta, sin bloquear la cuenta a los demás; el mensaje no revela si el correo existe y el tiempo de respuesta es el mismo. La reconfirmación de contraseña también tiene límite.
- Cambiar la contraseña, el rol o el segundo factor cierra las demás sesiones del usuario. Siempre queda al menos un administrador.

**Credenciales de ARCA**
- El certificado y la clave privada **nunca se guardan en el navegador**: los carga un administrador, que reconfirma su contraseña, y se envían una sola vez al servidor por **HTTPS obligatorio**.
- El servidor valida que sean PEM correctos, que la clave sea RSA de 2048 bits o más y corresponda al certificado, que esté vigente y que el CUIT coincida con el del emisor.
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
- `config.php`, `bootstrap.php` y `lib/` bloqueados para la web; la clave nunca viaja en la URL.
- **Auditoría** de los eventos relevantes (accesos rechazados, emisiones, ediciones, exportaciones) con usuario, IP y **hash encadenado**; se conserva 400 días y se depura solo por antigüedad. Los intentos fallidos se cuentan en otra tabla.
- Los CAE y C.A.I. solo los asigna el servidor; la numeración fiscal es única en la base y los documentos autorizados son inmutables.
- Migraciones del esquema por consola en el despliegue: la web nunca ejecuta DDL.

**Aplicación**
- **Content-Security-Policy** sin recursos de terceros (fuente incluida) y sin scripts ni hojas de estilo inline, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, HSTS y redirección a HTTPS.
- Dependencias incluidas con versión fija y hash verificado (SheetJS 0.20.3, sin CVE conocidas).
- Escape de todo dato mostrado en pantalla.
- El panel de la API se abre con la sesión de un administrador; una clave de API solo se conserva mientras la pestaña está abierta (`sessionStorage`).

Modelo de amenazas, respaldos, rotación de claves, respuesta a incidentes y retención de datos personales: [`docs/seguridad.md`](docs/seguridad.md). Estado de la revisión de noviembre de 2026: [`docs/remediacion-2026-11.md`](docs/remediacion-2026-11.md).

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

# 4. Primer administrador:
php app/api/cli/usuario.php "Nombre Apellido" correo@intellihelp.tech admin
#    imprime una contraseña temporal que se cambia en el primer ingreso

# 5. Respaldo diario cifrado (cron del hosting):
#    15 3 * * * php ~/public_html/remitos/api/cli/respaldo.php >> ~/backups/remitos/respaldo.log 2>&1
```

Después:
1. Ingresar a la aplicación con el administrador y crear los usuarios del equipo en **Configuración → Usuarios**.
2. En **Configuración → Conexión con ARCA**: CUIT, puntos de venta y el certificado.
3. Cargar el talonario de C.A.I. desde el **Panel de la API** según el instructivo.

Los recursos se referencian con `?v=NNN`: al publicar cambios en CSS o JS se incrementa la versión en `index.html` y `panel.html`. En cada despliegue: publicar `app/`, correr `php api/cli/migrar.php` y verificar las cabeceras (ver [`docs/seguridad.md`](docs/seguridad.md)).

## Pruebas

```bash
pip install playwright pymupdf cryptography && playwright install chromium
python test/correr.py                         # batería de API e interfaz
node --test test/unitarias/calculos.test.js   # unitarias de la interfaz
php test/unitarias/importes.test.php          # unitarias del servidor
```

**97 casos** automatizados sobre la aplicación en el navegador y la API —impresión y ejemplares, formularios y formatos, documentos PDF, inicio y recuperación de borradores, C.A.I. y autorizaciones simultáneas, inmutabilidad de lo autorizado, contenido obligatorio del remito, remito digital y conformidad del destinatario, usuarios, sesión, segundo factor y roles, datos compartidos, autenticación y permisos, credenciales de ARCA (incluida la firma real contra WSAA de homologación) y validación de datos— más pruebas unitarias en Node y PHP sobre los mismos casos de importes, IVA y CUIT. La integración continua las corre en cada cambio. Ver [`test/LEEME.md`](test/LEEME.md).

## Autor

**Brian Gabriel Sierra**
Jefe de Sistemas e Infraestructura IT — Intellihelp

[![LinkedIn](https://img.shields.io/badge/LinkedIn-brian--gabriel--sierra-0A66C2?logo=linkedin&logoColor=white)](https://www.linkedin.com/in/brian-gabriel-sierra)

---

<p align="center">
  <sub>© 2026 Intellihelp · Software de uso interno. Todos los derechos reservados.</sub>
</p>
