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
#            'app_key' => str_repeat('ab', 32)];

# 3. Servidor
REMITOS_CONFIG=/tmp/config.test.php php -S 127.0.0.1:8765 -t app

# 4. Pruebas
python test/correr.py            # todos los módulos
python test/correr.py seguridad  # solo los módulos que contengan ese nombre
python test/correr.py -v         # con el detalle de cada caso
```

Requisitos: `playwright` (con `playwright install chromium`), `pymupdf` y `cryptography`.

## Otro entorno

```bash
REMITOS_URL=https://intellihelp.tech/remitos REMITOS_MASTER_KEY=… python test/correr.py
```

Contra un entorno que no sea el local solo corren los módulos que no escriben en la base; los de API se ejecutan pidiéndolos por nombre o con `all`. Crean y borran sus propios datos.

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
| `test_cai.py` | Talonarios, numeración correlativa, C.A.I. vencido y autorización desde la aplicación |

## Agregar un caso

```python
@caso("Descripción de lo que debe cumplirse")
def nombre_corto():
    ...
    return condicion_ok, "detalle que se muestra si falla"
```

El decorador `@caso` lo registra y `correr.py` lo ejecuta sin más configuración.
