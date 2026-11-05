# Normativa aplicada

Cómo cumple el sistema cada requisito de ARCA sobre remitos y comprobantes. Las referencias son a la **RG 1415/2003** (régimen de emisión de comprobantes) con sus modificaciones, salvo que se indique otra norma.

## Remito clase «R» (documento completo, autoimpresor)

### Contenido obligatorio (Anexo V)

| Requisito | Dónde lo cumple el sistema |
|---|---|
| Razón social, domicilio comercial y CUIT del emisor | Encabezado (`COMPANY` en `config.js`) |
| Inscripción en ingresos brutos | «Ing. Brutos C.M. Nº» en el encabezado |
| Leyenda de la condición del emisor frente al IVA | «IVA RESPONSABLE INSCRIPTO» en el bloque del comprobante |
| Numeración consecutiva y progresiva de 13 dígitos (5 de punto de venta + 8) | La asigna solo la autorización contra el talonario, única por punto de venta en la base |
| Fecha de inicio de actividades | «Fecha inicio Act.» en el encabezado |
| Letra «R» y código del tipo de comprobante (091) | Recuadro central y «Código Nº 091» |
| Leyenda «DOCUMENTO NO VÁLIDO COMO FACTURA» | Bloque del comprobante |
| «C.A.I. Nº …» y «Fecha de Vto. …» | Pie del remito, con los textos del Anexo V |
| Datos del impresor | Autoimpresor: «Impreso por INTELLIHELP S.A. – C.U.I.T. …» (el rango de impresión no se consigna para autoimpresores) |
| Palabras «ORIGINAL» y «DUPLICADO» | Un ejemplar de cada uno (art. 14); el duplicado se imprime en beige como el talonario |
| Fecha de emisión | Encabezado |
| Destinatario: nombre, domicilio, CUIT y leyenda de su condición frente al IVA (o «A CONSUMIDOR FINAL») | Recuadro del cliente; la condición IVA se elige en el formulario |
| Descripción y cantidad de los bienes | Tabla de ítems |
| Transportista (cuando el traslado lo hace un tercero): razón social, domicilio comercial y CUIT | «Datos del transportista»; con transporte propio se indica «Transporte propio» |

**Control al autorizar.** El servidor no autoriza un remito al que le falte alguno de esos datos (`api/lib/normativa.php`) y la interfaz avisa antes con las mismas reglas (`app/js/calculos.js`). Un remito sin autorizar se imprime con el sello «SIN AUTORIZAR».

**C.A.I. vencido.** Un comprobante emitido con C.A.I. vencido no es válido: el sistema nunca autoriza con un talonario vencido.

### C.A.I.

- **Solicitud:** ARCA no ofrece un web service para pedir el C.A.I. Se tramita con Clave Fiscal en el servicio interactivo «Autorización de Impresión de Comprobantes», indicando punto de venta, tipo de comprobante y cantidad. No se automatiza: haría falta guardar la Clave Fiscal en el sistema.
- **Constatación:** al cargar el talonario, el sistema lo verifica con el web service de **constatación de comprobantes (WSCDC)** en modo `CAI`, con el primer y el último número del rango. ARCA comprueba que el C.A.I. exista y corresponda al CUIT del emisor, al tipo de comprobante y al punto de venta, que el número esté en el rango autorizado y que la fecha esté dentro del período habilitado (validaciones 100 a 105 y 108 del manual de WSCDC). Si ARCA lo rechaza, no se carga. Requiere un certificado de producción con el servicio `wscdc` asociado.
- **Avisos:** el sistema avisa cuando quedan 20 números o menos en el talonario y cuando faltan 30 días o menos para su vencimiento.

### Remito digital (RG 5678/2025)

La RG 5678/2025 modificó los artículos 27 y 30 de la RG 1415: el remito emitido por sistemas informáticos **puede no imprimirse** si se exhibe en formato digital durante el traslado. Es optativo.

| Requisito | Cómo lo cumple el sistema |
|---|---|
| Emitido por un sistema del art. 12, inc. b), excepto controlador fiscal | Autoimpresión desde el propio sistema |
| Sigue siendo obligatorio el C.A.I. | Solo un remito autorizado (con número y C.A.I.) se puede enviar en formato digital |
| Exhibir la representación gráfica digital durante el traslado | «Descargar PDF digital»: un solo ejemplar para llevar en el celular |
| Se pueden omitir las leyendas «ORIGINAL» y «DUPLICADO» | El PDF digital las reemplaza por «REMITO DIGITAL – RG 5678/2025» |
| No rige el tamaño mínimo de 15 × 20 cm | El PDF conserva el formato del talonario |
| Medio para que el destinatario conforme la recepción | Enlace y QR de conformidad: el destinatario confirma con nombre y documento; queda registrado con fecha, hora y conexión, en el remito y en la auditoría |
| Copia digital a disposición de ARCA por al menos 2 años | Los remitos autorizados no se eliminan y entran en los respaldos (ver [`seguridad.md`](seguridad.md)) |

### Sobre talonario pre-impreso

En el modo «sobre pre-impreso», el número, el C.A.I. y los datos fijos los trae el talonario de la imprenta; el sistema imprime solo los datos variables en su lugar.

## Facturas, notas de crédito y débito y recibos

| Requisito | Norma | Cómo lo cumple el sistema |
|---|---|---|
| Autorización electrónica (CAE) | RG 4291 y complementarias | `FECAESolicitar` con número siguiente al último autorizado; el CAE no se carga a mano |
| Condición frente al IVA del receptor | RG 5616/2024 | Se informa a ARCA (`CondicionIVAReceptorId`) y se imprime |
| Código QR | RG 4892/2020 | Generado con los datos del comprobante autorizado |
| IVA contenido en las facturas B a consumidores | Ley 27.743 (Régimen de Transparencia Fiscal al Consumidor) | Leyenda con el IVA contenido |
| Comprobante asociado en las notas | RG 4291 | `CbtesAsoc` con tipo, punto de venta y número |
| Corrección de un comprobante autorizado | RG 1415 | No se modifica: se emite una nota de crédito |

## Fuentes

- [RG 1415/2003, texto actualizado](https://www.argentina.gob.ar/normativa/nacional/resoluci%C3%B3n-1415-2003-81316/actualizacion) y [Anexo V (biblioteca de ARCA)](https://biblioteca.afip.gob.ar/pdfp/ANEXO_V_RG1415_V4290.pdf)
- [RG ARCA 5678/2025](https://contadoresenred.com/resolucion-general-5678-2025/) ([PDF, Consejo de Salta](https://consejosalta.org.ar/wp-content/uploads/ARCA-5678.pdf))
- [Manual del desarrollador de WSCDC v4 (ARCA)](https://www.afip.gob.ar/ws/WSCDCV1/WSCDC-manual-desarrollador-v4.pdf)
- [Autorización de Impresión de Comprobantes: instructivo](https://www.cpcelapampa.org.ar/media/Varios/Instructivos/AFIP/Autorizacion%20Impresion%20Comprobantes.pdf)
