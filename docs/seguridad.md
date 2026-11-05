# Seguridad, respaldos y retención de datos

Complementa la sección «Seguridad» del [README](../README.md) con el modelo de amenazas del circuito fiscal, los procedimientos de operación (respaldo, restauración, rotación de claves, respuesta a incidentes) y la política de retención de datos personales.

## 1. Activos

| Activo | Dónde está | Protección |
|---|---|---|
| Certificado y clave privada de ARCA | Tabla `arca_credenciales` | AES-256-GCM con subclave HKDF de `app_key`, ligado a ambiente y campo (AAD). Nunca sale del servidor |
| Tickets de acceso de WSAA | Tabla `arca_tickets` | Ídem; vencen a las 12 h |
| Secretos TOTP de los usuarios | `usuarios.totp_secret` | Ídem, ligados al id del usuario |
| `app_key` y hash de la clave maestra | `api/config.php` (permisos `0600`, fuera del repositorio, bloqueado para la web) | Copia en el gestor de contraseñas de la empresa |
| Contraseñas de usuarios | `usuarios.clave_hash` | bcrypt (`password_hash`), con rehash automático |
| Claves de API | `api_keys.hash` | Solo SHA-256; la clave se muestra una vez |
| Datos de clientes (PII, Ley 25.326) | Tablas `clientes`, `remitos`, `comprobantes` | Acceso con sesión o clave; exportación solo para administradores y auditada |
| Numeración fiscal (C.A.I. y CAE) | Tablas `remitos`, `comprobantes`, `cai_talonarios` | Asignada solo por el servidor; claves únicas en la base; documentos autorizados inmutables |
| Auditoría | Tabla `api_log` | Hash encadenado, retención por tiempo, sin escritura de texto libre desde la interfaz |

## 2. Modelo de amenazas del circuito fiscal

| Amenaza | Escenario | Control |
|---|---|---|
| Robo de la clave privada de ARCA | Acceso a la base (copia de respaldo, inyección SQL, hosting comprometido) | Cifrado con `app_key`, que no está en la base; consultas 100 % parametrizadas; respaldos cifrados |
| Emisión fraudulenta con una sesión robada | Un tercero usa la sesión de un operador | Cookie `HttpOnly`/`Secure`/`SameSite=Strict`, CSRF, vencimiento, segundo factor opcional; toda emisión queda auditada con usuario e IP |
| Comprobante con CAE o C.A.I. inventado | Un usuario intenta imprimir un documento «autorizado» sin pasar por ARCA | El CAE y el C.A.I. solo los asigna el servidor (o un administrador por API, auditado); sin autorización el documento sale marcado «SIN AUTORIZAR» |
| Números duplicados o salteados | Dos usuarios autorizan a la vez; se corta la conexión durante la emisión | Bloqueo por serie, transacción con bloqueo de fila, clave única en la base; idempotencia por `id_local`; recuperación del CAE con `FECompConsultar` antes de pedir un número nuevo |
| Alteración del historial | Borrar o cambiar eventos para ocultar una operación | Hash encadenado verificable desde el panel; la depuración es solo por antigüedad; los contadores de intentos están en otra tabla |
| Fuerza bruta de contraseñas | Desde internet o con una sesión robada (reconfirmación) | Freno por correo y conexión, por conexión y por cuenta; reconfirmación limitada a 5 intentos; mensaje único que no revela si la cuenta existe |
| Planilla manipulada al importar clientes | Excel diseñado para atacar el lector | SheetJS 0.20.3 (sin CVE conocidas), validación del CUIT y recorte de cada campo en el servidor |
| Suplantación de HTTPS | Cabecera `X-Forwarded-Proto` falsificada | Solo se acepta desde `trusted_proxies`; HSTS |
| Respuesta manipulada de ARCA | XML con entidades externas | `LIBXML_NONET`, rechazo de `DOCTYPE` y `ENTITY`, TLS verificado contra URLs fijas |

## 3. Respaldos

- **Automático y cifrado:** `php api/cli/respaldo.php` genera `~/backups/remitos/remitos-AAAAMMDD-HHMMSS.rmb` (permisos `0600`), cifrado con AES-256-GCM, lo verifica al terminar y conserva los últimos 30. Programarlo en el cron del hosting:

  ```
  15 3 * * * php /home/USUARIO/public_html/remitos/api/cli/respaldo.php >> /home/USUARIO/backups/remitos/respaldo.log 2>&1
  ```

- **Copia fuera del servidor:** descargar periódicamente (al menos semanal) el último `.rmb` a un almacenamiento de la empresa. Sin la `app_key` el archivo no se puede leer.
- **Prueba de restauración (mensual):** `php api/cli/restaurar.php archivo.rmb --verificar` descifra el respaldo y muestra las filas de cada tabla sin tocar la base. Registrar fecha y resultado.
- **Restauración real:** `php api/cli/restaurar.php archivo.rmb --confirmar`. Antes de reemplazar genera un respaldo del estado actual; la carga es transaccional y cierra todas las sesiones.

## 4. Rotación de claves

| Clave | Cuándo | Cómo |
|---|---|---|
| `app_key` | Ante sospecha de exposición, cambio de personal con acceso al servidor, o una vez al año | `php api/cli/rotar-clave.php --confirmar`: vuelve a cifrar certificados, tickets y secretos TOTP y actualiza `config.php` en una sola operación. Guardar la clave nueva en el gestor de contraseñas. Los respaldos anteriores se leen con la clave anterior: conservarla mientras se guarden esos respaldos |
| Clave maestra de la API | Ante sospecha de exposición o una vez al año | Generar una nueva (`config.sample.php`), reemplazar `master_key_hash` y actualizar las integraciones |
| Claves de servicio | Al dar de baja una integración | Panel → Claves: desactivar o eliminar |
| Certificado de ARCA | Antes de su vencimiento (2 años) o ante exposición | Generar el nuevo en ARCA, cargarlo en Configuración → Conexión con ARCA (reemplaza al anterior e invalida sus tickets) |
| Contraseña de la base y de SSH del hosting | Ante exposición | Panel del hosting; actualizar `config.php` |

## 5. Respuesta a incidentes

1. **Contener:** desactivar el usuario o la clave involucrados (sus sesiones se cierran en el acto). Si el servidor está comprometido, quitar el certificado de ARCA desde la aplicación y revocarlo en ARCA.
2. **Evidencia:** verificar la integridad de la auditoría (Panel → Actividad → Verificar integridad) y exportar los eventos del período.
3. **Erradicar:** rotar `app_key`, la clave maestra y las contraseñas de base de datos y SSH; generar y cargar un certificado nuevo.
4. **Recuperar:** restaurar desde el último respaldo íntegro si hace falta.
5. **Informar:** si hubo acceso a datos personales, evaluar la notificación a los titulares y a la Agencia de Acceso a la Información Pública.

## 6. Retención de datos personales (Ley 25.326)

| Dato | Plazo | Motivo |
|---|---|---|
| Comprobantes y remitos autorizados | 10 años desde su emisión | Conservación de documentación respaldatoria (Ley 11.683, art. 48; Código Civil y Comercial, art. 328) |
| Borradores sin autorizar | Hasta que se autoricen o se eliminen; revisar y depurar los de más de 1 año | No tienen valor fiscal |
| Agenda de clientes | Mientras exista relación comercial; revisión anual de clientes sin operaciones en 2 años | Minimización |
| Auditoría | 400 días (depuración automática) | Evidencia de seguridad de al menos un ejercicio completo |
| Intentos fallidos | 2 días | Solo para los frenos de fuerza bruta |
| Borradores en el navegador | Se borran al cerrar sesión y a los 7 días sin uso | Equipos compartidos |
| Respaldos | 30 en el servidor; las copias externas, según el plazo de los comprobantes | Recuperación |

Los titulares pueden ejercer sus derechos de acceso, rectificación y supresión pidiéndolo a la empresa; un administrador corrige o elimina los datos de la agenda. Los comprobantes autorizados no se eliminan antes del plazo legal.

## 7. Verificación de las cabeceras en producción

Después de cada despliegue:

```bash
curl -sI https://intellihelp.tech/remitos/ | grep -iE 'content-security|strict-transport|x-frame|cache-control'
curl -sI https://intellihelp.tech/remitos/api/sesion.php | grep -i cache-control   # debe decir no-store
```
