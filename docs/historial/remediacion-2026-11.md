# Remediación de la revisión de noviembre de 2026

Estado de cada hallazgo de la revisión de código y auditoría de seguridad sobre el commit `9c22eb6`. Todos los puntos técnicos quedaron resueltos; los controles organizativos de ISO/IEC 27001 que no se evalúan desde el código quedan documentados en [`seguridad.md`](seguridad.md).

## Prioridades (resumen ejecutivo)

| # | Hallazgo | Resolución |
|---|---|---|
| 1 | F1 · Importes con punto de miles mal interpretados | Parser argentino común (`app/js/calculos.js` y `api/lib/importes.php`) con cuentas en centavos; el servidor recalcula y rechaza importes inválidos. Mismos casos de prueba para ambos lados |
| 2 | B1 · Dos remitos impresos con el mismo número | Los borradores ya no tienen número fiscal: llevan una referencia interna y se imprimen «SIN AUTORIZAR». El número solo lo asigna la autorización y la base lo hace único por `(punto_venta, numero_int)` |
| 3 | H-01 · Auditoría destruible | `evento.php` con catálogo cerrado y cuota; retención por tiempo (400 días), nunca por cantidad; intentos fallidos en su propia tabla; hash encadenado verificable |
| 4 | F3 · Doble emisión de comprobantes | Guarda de reentrada en guardar y emitir, botones deshabilitados durante la operación e idempotencia por `id_local` en el servidor |
| 5 | H-02 · SheetJS 0.18.5 con CVE | SheetJS 0.20.3, con versión y SHA-256 registrados y verificados en CI |

## Rol tester

### 2.1 Ejecución de la suite

| Observación | Resolución |
|---|---|
| Variables `REMITOS_MYSQL`, `REMITOS_DB_PORT`, `REMITOS_DB_PASS` sin documentar | Documentadas en `test/LEEME.md` (con `REMITOS_DB`); el cliente por defecto es `mariadb` del PATH |
| Caso de ARCA que pasa vacuamente sin internet | Ahora se marca como fallido si no hay conexión con WSAA, salvo `REMITOS_SIN_RED=1`, que lo omite de forma explícita |
| Esperas fijas | Las pruebas nuevas esperan respuestas concretas de la API; CI corre la batería completa en cada cambio |

### 2.2 Backend

| # | Resolución |
|---|---|
| B1 | Ver prioridad 2. Clave única `uq_numero (punto_venta, numero_int)` y migración que limpia los números de los borradores existentes |
| B2 | El número enviado por el cliente se ignora en remitos; en comprobantes se compara por `numero_int` dentro de la serie |
| B3 | `00001-00000012` → se toma la parte posterior al guion y luego los dígitos; más de 8 dígitos → `422` |
| B4 | Marca de facturado (`POST remitos.php {accion: "facturado"}` y en la interfaz); `pendientes_facturar` cuenta autorizados sin facturar |
| B5 | La búsqueda por `id_local` y el alta ocurren bajo el mismo bloqueo |
| B6 | `api_lock()` verifica el resultado de `GET_LOCK` (`503` si no se obtiene); las respuestas se lanzan como excepción, así que los `finally` liberan siempre bloqueos y transacciones |
| B7 | Fechas validadas con `checkdate` en remitos, comprobantes y talonarios |
| B8 | Dígito verificador del CUIT (módulo 11) en PHP y JS, en clientes, remitos, comprobantes, importación y ajustes del emisor |
| B9 | Tipos de comprobante del talonario validados; un talonario con remitos autorizados no se borra (se desactiva) y el resumen cuenta los remitos autorizados reales |
| B10 | `api_bool()` con `FILTER_VALIDATE_BOOLEAN`: `"false"` es falso |
| B11 | Ver H-08; comentario de CORS actualizado |
| B12 | `GET_LOCK` alrededor de la obtención del ticket de WSAA, con doble verificación |
| B13 | `total` real y `completo` en `clientes.php`, búsqueda por servidor y aviso en la interfaz si la agenda no entra completa |
| B14 | Las ediciones de borradores se auditan con los grupos de datos que cambiaron; un remito autorizado es inmutable (salvo la marca de facturado) |
| B15 | `DELETE arca.php` documentado como operación exclusiva de la clave maestra; con sesión responde `405` e indica usar `quitar` |

### 2.3 Frontend

| # | Resolución |
|---|---|
| F1 | Ver prioridad 1 |
| F2 | `actualizarEnLista(..., clone(doc))` en todos los guardados |
| F3 | Ver prioridad 4 |
| F4 | `id_local` generado en cada remito y comprobante nuevo |
| F5 | El traspaso borra del navegador solo lo que el servidor confirmó; lo que falla queda para reintentar |
| F6 | `id_local` en comprobantes (alta, traspaso e importación de copias) |
| F7 | `montoALetras` trabaja en centavos enteros (y corrige «un peso», «veintiún mil», «veintidós», «un millón de pesos») |
| F8 | La aplicación trae todas las páginas de remitos y comprobantes; listados, paginador y búsquedas trabajan sobre el total |
| F9 | CUIT/DNI con formato automático y validación también en el comprobante |
| F10 | El panel se actualiza solo también con la sesión de un administrador |
| F11 | «sistemo» corregido; punto de venta con 5 dígitos |
| F12 | Totales en centavos con redondeo por renglón, iguales a los del servidor |
| F13 | Restos de la cola offline y del C.A.I. editable eliminados. `FULL_FIX` se conserva: contiene la corrección medida del casillero de tipo de documento |

### 2.4 Esquema

| # | Resolución |
|---|---|
| S1 | `UNIQUE (punto_venta, numero_int)` en remitos y `UNIQUE (referencia)` para los borradores |
| S2 | Las migraciones corren por consola en el despliegue (`php api/cli/migrar.php`), con mensaje claro si fallan; la web nunca ejecuta DDL y responde `503` mientras el esquema esté desactualizado |
| S3 | Columna `fecha_emision DATE` indexada en remitos y comprobantes |

### 2.5 Documentación

`docs/api.md` reescrito: `remito` en la respuesta del alta, todos los usos de `422`, `DELETE arca.php`, frenos de ingreso por correo, conexión y cuenta, `pendientes_facturar`, emisión con CAE, exportación y comandos de operación.

### 2.6 Cobertura

Nuevas pruebas: unitarias en Node y PHP sobre los mismos casos (importes, IVA, montos en letras, CUIT, TOTP, XML para ARCA); y en la batería: convivencia borrador/autorizado, autorizaciones simultáneas, inmutabilidad, talonarios usados, freno de ingresos sin oráculo, invalidación de sesiones, reconfirmación limitada, ingreso desde otro origen, segundo factor, validación de fechas, horas y CUIT.

## Rol auditor

| ID | Resolución |
|---|---|
| H-01 | Ver prioridad 3 |
| H-02 | Ver prioridad 5 |
| H-03 | Reconfirmación con contador propio: cinco fallos en 15 minutos cierran la sesión (cambio de contraseña, segundo factor, certificado) |
| H-04 | CAE solo por emisión (`FECAESolicitar`) o registro de un administrador; C.A.I. solo por autorización; los documentos sin autorizar se imprimen «SIN AUTORIZAR» |
| H-05 | Sin bloqueo duro de cuenta: freno por correo y conexión, por conexión y por cuenta, con el mismo mensaje exista o no el correo |
| H-06 | Versión de sesión por usuario: cambiar contraseña, rol, segundo factor o desactivar invalida las demás sesiones |
| H-07 | `exportar.php` solo para administradores y auditado; botón visible solo para ellos |
| H-08 | `X-Forwarded-Proto` solo desde `trusted_proxies` |
| H-09 | `usuario_id`, catálogo de acciones desde la interfaz, ediciones auditadas y hash encadenado |
| H-10 | Escrituras con sesión (incluido el ingreso) solo como `application/json` y desde el propio origen |
| H-11 | Mínimo 12 caracteres, lista de contraseñas comunes, secuencias y partes del correo; segundo factor TOTP opcional |
| H-12 | Migraciones por consola; el usuario web puede quedar sin permisos de DDL |
| H-13 | Borradores locales borrados al cerrar sesión y a los 7 días sin uso |
| H-14 | CORS solo para `allowed_origins` |
| H-15 | Fuente autoalojada; CSP sin terceros y sin `<style>` inline (`style-src 'self'`; solo atributos de estilo, que usa el motor de impresión); HSTS incondicional; `Cache-Control` del sitio aplicado solo al HTML, así la API conserva su `no-store` |
| H-16 | Mensajes de ARCA y cURL genéricos para el cliente; detalle en el log |
| H-17 | XML con `LIBXML_NONET` y rechazo de `DOCTYPE`/`ENTITY` |
| H-18 | La clave privada debe ser RSA |
| H-19 | La clave maestra solo se acepta por hash |
| H-20 | Número de más de 8 dígitos → `422` |
| H-21 | CUIT con dígito verificador (B8) |
| H-22/23 | CI en GitHub Actions (sintaxis, estilo, hashes de dependencias, búsqueda de secretos, unitarias y batería completa con MariaDB) y manifiesto de dependencias (`docs/dependencias.md`) |

## Plan de largo plazo

| # | Estado |
|---|---|
| 19 | CI implementado (`.github/workflows/ci.yml`) |
| 20 | Fuente autoalojada y CSP endurecida (H-15) |
| 21 | Respaldos cifrados automatizables con verificación y restauración probada (`api/cli/respaldo.php`, `restaurar.php`); rotación de `app_key` (`rotar-clave.php`) y procedimientos en `seguridad.md` |
| 22 | Política de retención (Ley 25.326) y modelo de amenazas del circuito fiscal en `seguridad.md` |
