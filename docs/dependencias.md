# Dependencias

La aplicación no usa gestores de paquetes en producción: las pocas dependencias de terceros se incluyen en el repositorio, con versión fija y hash verificado en cada ejecución de la integración continua (`sha256sum -c test/dependencias.sha256`).

## Incluidas en la aplicación

| Componente | Versión | Licencia | Archivo | Uso | Origen |
|---|---|---|---|---|---|
| SheetJS Community Edition | 0.20.3 | Apache-2.0 | `app/js/lib/xlsx.full.min.js` | Lectura de planillas Excel/CSV al importar clientes. Se carga solo al importar | `https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js` |
| qrcode-generator | 2.0.4 | MIT | `app/js/lib/qrcode.js` | QR fiscal de los comprobantes (RG 4892/2020) y QR de conformidad del remito digital, generados en el navegador | `https://registry.npmjs.org/qrcode-generator/-/qrcode-generator-2.0.4.tgz` (`dist/qrcode.js`) |
| Saira (fuente variable 400–800) | Google Fonts v23 | SIL OFL 1.1 (`app/assets/fonts/OFL.txt`) | `app/assets/fonts/saira-latin*.woff2` | Tipografía de la interfaz y de los documentos, servida desde el propio sitio | Google Fonts |

SHA-256 de cada archivo: [`test/dependencias.sha256`](../test/dependencias.sha256).

### Vulnerabilidades conocidas

- SheetJS 0.18.5 (la versión anterior) estaba afectada por **CVE-2023-30533** (contaminación del prototipo al leer un archivo manipulado) y **CVE-2024-22363** (denegación de servicio por expresión regular). Ambas están corregidas desde la 0.20.2; la aplicación usa la **0.20.3**. SheetJS no publica versiones nuevas en npm: se descarga de su CDN oficial.
- qrcode-generator y Saira no tienen vulnerabilidades publicadas a la fecha de la última revisión.

## Servidor

PHP 8.2 o superior con las extensiones `pdo_mysql`, `openssl`, `curl`, `dom` y `mbstring`, y MariaDB 10.6+ o MySQL 8. Sin dependencias de Composer.

## Herramientas de desarrollo (no se publican)

| Herramienta | Versión | Uso |
|---|---|---|
| PHP-CS-Fixer | 3.64 | Estilo PSR-12 del código PHP |
| Prettier | 3.3.3 | Estilo de JavaScript, CSS y HTML |
| Node.js | 22 | Pruebas unitarias de `calculos.js` |
| Playwright (Python) | 1.47 | Pruebas de la interfaz en Chromium |
| PyMuPDF, cryptography | 1.24, 43 | Lectura de PDF y certificados de prueba |

## Actualizar una dependencia

1. Descargar la versión nueva desde el origen indicado y revisar sus notas de versión y avisos de seguridad.
2. Reemplazar el archivo en `app/` y actualizar esta tabla.
3. Regenerar el hash: `sha256sum app/js/lib/*.js app/assets/fonts/*.woff2 > test/dependencias.sha256`.
4. Subir la versión de caché (`?v=NNN`) en `index.html` y `panel.html`, y correr la batería completa.
