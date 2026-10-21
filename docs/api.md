# API de Remitos y Comprobantes

API REST/JSON para crear y consultar remitos desde otros sistemas, administrar los talonarios de C.A.I., las claves de acceso y las credenciales de ARCA.

| | |
|---|---|
| **URL base** | `https://intellihelp.tech/remitos/api/` |
| **Formato** | JSON (UTF-8) |
| **Autenticación** | Cabecera `X-API-Key` |
| **Errores** | `{ "ok": false, "error": "mensaje" }` con el código HTTP correspondiente |

## Autenticación

Cada petición lleva la clave en la cabecera `X-API-Key`. La clave nunca viaja en la URL.

```
X-API-Key: ith_0123456789abcdef0123456789abcdef
```

| Clave | Dónde se define | Alcance |
|---|---|---|
| **Maestra** | `api/config.php` (se guarda solo su SHA-256) | Todo, incluida la administración de claves, talonarios y certificados |
| **De servicio** | Panel → Claves | Permisos acotados: `leer`, `crear`, `borrar`. Se guarda solo su SHA-256 y se muestra una única vez |

Una IP que acumula 20 accesos rechazados en 10 minutos queda bloqueada temporalmente (`429`).

## Flujo típico de integración

```mermaid
sequenceDiagram
    participant S as Sistema externo
    participant A as API
    participant U as Aplicación
    S->>A: POST remitos.php (X-API-Key)
    A-->>S: 201 { id, url }
    S->>U: Abre url (?open=id)
    U->>A: GET remitos.php?id=…
    A-->>U: remito
    U->>U: Vista previa e impresión
```

## Remitos

### `POST remitos.php` — crear · permiso `crear`

```json
{
  "fecha": "21/10/2026",
  "cliente": {
    "razonSocial": "DISTRIBUIDORA EJEMPLO S.A.",
    "cuit": "30-12345678-9",
    "domicilio": "Av. Corrientes 1234",
    "localidad": "CABA",
    "cp": "C1043",
    "mail": "compras@ejemplo.com.ar"
  },
  "items": [
    { "articulo": "1", "descripcion": "Servicio técnico en sitio", "cantidad": "2 hs" }
  ],
  "recibi": { "tipoDoc": "DNI", "nombre": "María Gómez", "documentoNro": "30.123.456" },
  "transporte": { "nombre": "Transportes Ejemplo", "hora": "14:30", "observaciones": "Entregar en recepción" }
}
```

Respuesta `201`:

```json
{ "ok": true, "id": "9f2c1a77bd0e4a13", "actualizado": false, "url": "https://intellihelp.tech/remitos/?open=9f2c1a77bd0e4a13" }
```

- Todos los campos son opcionales; la fecha (`dd/mm/aaaa`) toma el día actual si falta o es inválida.
- Los textos se recortan a su longitud máxima y los campos desconocidos se descartan. Se aceptan hasta 60 ítems.
- Si el cuerpo trae `id_local` (identificador del remito en la aplicación) y ya existe, el remito se **actualiza** (`200`, `"actualizado": true`): así funciona el respaldo de la aplicación sin generar duplicados.

| Objeto | Campos |
|---|---|
| `cliente` | `senores`, `razonSocial`, `cuit`, `domicilio`, `localidad`, `telefono`, `cp`, `mail`, `nombre`, `otrosDatos` |
| `items[]` | `articulo`, `descripcion`, `cantidad` |
| `recibi` | `tipoDoc` (`DNI`, `CI`, `Pasaporte`, `Otro`), `nombre`, `documentoNro`, `sector`, `puesto` |
| `transporte` | `nombre`, `vehiculo`, `chofer`, `lugarEntrega`, `fecha`, `hora`, `observaciones` |

### `GET remitos.php?id={id}` — leer · público

El identificador es aleatorio de 64 bits y no se puede adivinar: es el enlace que abre el remito listo para imprimir.

### `GET remitos.php` — listar · permiso `leer`

Los últimos 100 remitos, del más reciente al más antiguo (`id`, `numero`, `fecha`, `cliente`, `origen`, `facturado`, `updated`).

### `DELETE remitos.php?id={id}` — eliminar · permiso `borrar`

## Estado

### `GET estado.php` — seguimiento · permiso `leer`

Totales, remitos del día y pendientes de facturar. No incluye importes, ítems, CUIT ni domicilios. Con `?formato=texto` devuelve el mismo resumen en texto plano.

## Talonarios de C.A.I.

| Método | Operación | Permiso |
|---|---|---|
| `GET talonarios.php?accion=listar` | Talonarios con restantes, vencimiento y alertas | maestra |
| `GET talonarios.php?accion=vigente&pv=1&tipo=91` | Talonario activo, sin consumir número | `leer` |
| `POST talonarios.php` `{punto_venta, numero_desde, numero_hasta, cai, cai_vencimiento}` | Alta (valida rango, superposición, C.A.I. de 14 dígitos y vigencia) | maestra |
| `POST talonarios.php` `{accion: "autorizar", pv, tipo, fecha, confirmar?}` | Asigna el próximo número y el C.A.I. dentro de una transacción con bloqueo de fila | `crear` |
| `POST talonarios.php` `{id, activa}` | Activar o desactivar | maestra |
| `DELETE talonarios.php?id={id}` | Eliminar (un talonario activo que ya emitió debe desactivarse antes) | maestra |

Si el C.A.I. está vencido a la fecha de emisión, `autorizar` responde `409` con `"vencido": true` y solo continúa con `"confirmar": true`.

## ARCA

| Método | Operación | Permiso |
|---|---|---|
| `GET arca.php` | Datos públicos del certificado de cada ambiente (titular, CUIT, vigencia, huella) | `leer` |
| `POST arca.php` `{accion: "cargar", ambiente, cuit?, certificado, clave, frase?}` | Valida el par y lo guarda cifrado. Solo por HTTPS | maestra |
| `POST arca.php` `{accion: "probar", ambiente}` | Verifica los servidores de WSFEv1 y obtiene el ticket de WSAA | `crear` |
| `DELETE arca.php?ambiente={ambiente}` | Elimina el certificado, la clave y los tickets del ambiente | maestra |
| `GET proximo.php?ambiente=&tipo=&subtipo=&letra=&pv=` | Próximo número según ARCA (último autorizado + 1) | `leer` |

`ambiente`: `homologacion` o `produccion`. `tipo`: `factura`, `recibo` o `nota` (`subtipo` `credito` o `debito`). `letra`: `A`, `B` o `C`.

Al cargar un certificado se verifica que sea un PEM X.509 válido y vigente, que la clave privada (RSA de 2048 bits o más) le corresponda y que el CUIT del titular coincida con el informado. Las respuestas nunca incluyen el certificado ni la clave.

## Auditoría y claves

| Método | Operación | Permiso |
|---|---|---|
| `GET claves.php?accion=listar` | Claves de servicio y resumen de uso | maestra |
| `GET claves.php?accion=registro&pagina=&por_pagina=&categoria=` | Auditoría paginada | maestra |
| `POST claves.php` `{nombre, permisos}` | Crea una clave y la devuelve una única vez | maestra |
| `POST claves.php` `{id, activa}` | Activa o desactiva | maestra |
| `DELETE claves.php?id={id}` | Elimina | maestra |
| `POST evento.php` `{categoria, accion, detalle?, nivel?}` | La aplicación registra sus propios eventos | `crear` |

Categorías de la auditoría: `remito`, `comprobante`, `cai`, `arca`, `clave`, `acceso`, `error`, `sistema`. Se registran los eventos relevantes, no las lecturas de rutina, y se conservan los últimos 2000.

## Ejemplos

**curl**

```bash
curl -X POST https://intellihelp.tech/remitos/api/remitos.php \
  -H "X-API-Key: $REMITOS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"cliente":{"razonSocial":"DISTRIBUIDORA EJEMPLO S.A."},"items":[{"articulo":"1","descripcion":"Servicio técnico","cantidad":"2 hs"}]}'
```

**Python**

```python
import os
import requests

API = "https://intellihelp.tech/remitos/api/remitos.php"

def crear_remito(cliente: dict, items: list[dict]) -> str:
    r = requests.post(API, json={"cliente": cliente, "items": items},
                      headers={"X-API-Key": os.environ["REMITOS_API_KEY"]}, timeout=15)
    r.raise_for_status()
    return r.json()["url"]
```

**JavaScript**

```javascript
const res = await fetch('https://intellihelp.tech/remitos/api/remitos.php', {
  method: 'POST',
  headers: { 'X-API-Key': process.env.REMITOS_API_KEY, 'Content-Type': 'application/json' },
  body: JSON.stringify({ cliente: { razonSocial: 'DISTRIBUIDORA EJEMPLO S.A.' }, items: [] }),
});
const { url } = await res.json();
```

## Códigos de respuesta

| Código | Significado |
|---|---|
| `200` / `201` | Operación correcta / recurso creado |
| `400` | Datos inválidos |
| `401` | Falta la clave o es inválida |
| `403` | La clave no tiene el permiso necesario, está desactivada o la operación exige HTTPS |
| `404` | Recurso inexistente |
| `409` | Conflicto (talonario agotado o vencido, rango superpuesto, sin certificado) |
| `413` | Cuerpo demasiado grande |
| `422` | Certificado o clave privada rechazados |
| `429` | Demasiados intentos rechazados desde la misma IP |
| `502` | ARCA rechazó la operación o no respondió |
