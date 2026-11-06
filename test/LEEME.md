# Pruebas automatizadas

Cada caso reproduce un comportamiento esperado o un error ya corregido: si vuelve a aparecer, la prueba falla antes de llegar a producción.

## Entorno local

Por defecto las pruebas corren contra `http://127.0.0.1:8765` con una base de datos de pruebas.

```bash
# 1. Base de datos de pruebas (MariaDB o MySQL)
mysql -u root -p -e "CREATE DATABASE remitos_test CHARACTER SET utf8mb4"

# 2. Configuración de pruebas (fuera del repositorio), por ejemplo /tmp/config.test.php:
#    return ['app_url' => 'http://127.0.0.1:8765',
#            'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'remitos_test', 'user' => 'root', 'pass' => '…'],
#            'master_key_hash' => hash('sha256', 'ith_test_master_key_0123456789abcdef'),
#            'app_key' => str_repeat('ab', 32),
#            'google' => [… ver «Ingreso con Google» más abajo …]];

# 3. Esquema (la web no migra la base: se hace por consola, igual que en el despliegue)
REMITOS_CONFIG=/tmp/config.test.php php app/api/cli/migrar.php

# 4. Servidor
REMITOS_CONFIG=/tmp/config.test.php php -S 127.0.0.1:8765 -t app

# 5. Pruebas
python test/correr.py            # todos los módulos
python test/correr.py seguridad  # solo los módulos que contengan ese nombre
python test/correr.py -v         # con el detalle de cada caso

# Pruebas unitarias (no necesitan servidor ni base)
node --test test/unitarias/calculos.test.js
php test/unitarias/importes.test.php
```

Requisitos: `playwright` (con `playwright install chromium`), `pymupdf` y `cryptography`; Node 20+ para las unitarias.

### Variables de entorno

Algunas pruebas limpian en la base local datos que la API protege a propósito (remitos autorizados, talonarios usados, intentos fallidos). Para eso usan el cliente de línea de comandos de MariaDB/MySQL:

| Variable | Por defecto | Uso |
|---|---|---|
| `REMITOS_MYSQL` | `mariadb` (del PATH) | Ruta del cliente (`mysql` también sirve) |
| `REMITOS_DB` | `remitos_test` | Base de pruebas |
| `REMITOS_DB_PORT` | `3307` | Puerto del servidor de pruebas |
| `REMITOS_DB_PASS` | `testroot` | Contraseña de `root` en el servidor de pruebas |
| `REMITOS_SIN_RED` | — | `1` omite (y lo informa) el caso que firma contra WSAA de homologación; sin esta variable, la falta de conexión con ARCA hace fallar el caso |

Ejemplo en Linux: `REMITOS_MYSQL=mysql REMITOS_DB_PORT=3306 REMITOS_DB_PASS=secreto python test/correr.py`.

La integración continua (`.github/workflows/ci.yml`) corre todo esto en cada cambio contra MariaDB 11.

Se ingresa solo con Google. Las pruebas levantan un **Google simulado** en `127.0.0.1:8766` (variable `REMITOS_GOOGLE_PUERTO`) que hace de endpoint de tokens, y recorren el flujo real: la redirección, la cookie de estado, el canje del código con PKCE y la validación del token. La configuración de pruebas lo indica con `token_url`, que solo se acepta si es una dirección local:

```php
'google' => ['client_id' => 'pruebas.apps.googleusercontent.com', 'client_secret' => 'secreto-de-pruebas',
             'dominio' => 'intellihelp.tech', 'token_url' => 'http://127.0.0.1:8766/token'],
```

Las pruebas de interfaz ingresan con un administrador de pruebas (`admin.pruebas@intellihelp.tech`) que se crea solo con la clave maestra del entorno.

## Otro entorno de pruebas

```bash
REMITOS_URL=https://pruebas.ejemplo/remitos REMITOS_MASTER_KEY=… python test/correr.py all
```

Las pruebas crean usuarios y documentos y los borran al terminar: no se corren contra producción.

## Módulos

| Módulo | Cubre |
|---|---|
| `test_impresion.py` | Impresión de prueba, ejemplares original y duplicado, color del duplicado, impresiones seguidas |
| `test_formulario.py` | Tipos de dato, fechas dd/mm/aaaa, CUIT, ajustes de fábrica, independencia entre modos |
| `test_documentos.py` | Instructivo en PDF y remito en documento completo |
| `test_inicio.py` | Inicio, paginado, recuperación de borradores e indicador de conexión |
| `test_interfaz.py` | Fechas, atajos, numeración, CAE por comprobante, copias de seguridad, certificado, respaldo, celular |
| `test_api.py` | Autenticación, permisos y ciclo de vida de las claves, auditoría |
| `test_seguridad.py` | Credenciales de ARCA (incluida la firma contra WSAA), validación de datos, cabeceras, archivos internos |
| `test_sesion.py` | Ingreso con Google de punta a punta y sus rechazos (sin usuario, otro dominio, correo sin verificar, otra aplicación, token vencido, PKCE ajeno, estado manipulado), ausencia de contraseñas y segundo factor, alta solo con correos del dominio, cookie y CSRF, otro origen, roles, datos compartidos, referencias concurrentes, invalidación de sesiones, traspaso de datos de versiones anteriores |
| `test_cai.py` | Talonarios, numeración correlativa, autorizaciones simultáneas, inmutabilidad del autorizado, C.A.I. vencido, talonarios usados y autorización desde la aplicación |
| `test_normativa.py` | Contenido obligatorio del remito (Anexo V), talonario sin constatar, conformidad del remito digital por API y desde la página pública en celular, PDF digital con QR |
| `unitarias/` | Importes en formato argentino, IVA, totales, montos en letras, CUIT, XML para ARCA, token de Google y plazo de confirmación de la sesión (mismos casos en JS y PHP) |

## Agregar un caso

```python
@caso("Descripción de lo que debe cumplirse")
def nombre_corto():
    ...
    return condicion_ok, "detalle que se muestra si falla"
```

El decorador `@caso` lo registra y `correr.py` lo ejecuta sin más configuración.
