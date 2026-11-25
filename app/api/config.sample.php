<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Plantilla de configuración de la API. Copiar como config.php y completar.
 * config.php contiene credenciales: no se versiona y no se sirve por web.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

return [
    // URL pública de la aplicación (se usa para armar los enlaces de los remitos).
    'app_url' => 'https://intellihelp.tech/remitos',

    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => '',
        'user' => '',
        'pass' => '',
    ],

    // Clave maestra de la API (cabecera X-API-Key). Se guarda solo su SHA-256:
    //   php -r "$k = 'ith_' . bin2hex(random_bytes(24)); echo $k, PHP_EOL, hash('sha256', $k), PHP_EOL;"
    // La primera línea es la clave (guardarla en un gestor de contraseñas); la segunda va acá.
    'master_key_hash' => '',

    // Clave de cifrado de los secretos almacenados (certificado, clave privada y tickets de ARCA)
    // y de los respaldos: 64 caracteres hexadecimales.
    // Generar con: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    // Guardarla también en un gestor de contraseñas: sin ella no se leen los respaldos y hay que
    // volver a cargar el certificado de ARCA. Para cambiarla: php api/cli/rotar-clave.php --confirmar
    'app_key' => '',

    // Orígenes (esquema://host[:puerto]) autorizados a llamar a la API desde un navegador
    // con CORS. La aplicación usa su propio origen y no necesita ninguno.
    'allowed_origins' => [],

    // Ingreso con Google: es la única forma de ingresar a la aplicación. Cliente OAuth «Aplicación web» de Google Cloud con la URI de
    // redirección https://<dominio>/remitos/api/google.php (una por cada dominio de la aplicación).
    // Solo ingresan cuentas del dominio de Google Workspace indicado que ya sean usuarios activos.
    // El secreto del cliente solo vive acá: no se versiona ni llega al navegador.
    'google' => [
        'client_id' => '',
        'client_secret' => '',
        'dominio' => 'intellihelp.tech',
    ],

    // Ingreso con ITHub (sesión única de Intellihelp). Con client_id y client_secret, la aplicación
    // entra solo por ITHub: quién accede y con qué rol (admin | operador) lo decide ITHub, y Google
    // ya no abre sesiones por su cuenta. Sin esta sección (o vacía), sigue el ingreso con Google.
    // Alta en ITHub → Aplicaciones → Remitos: URI de vuelta https://<dominio>/api/ithub.php (una por
    // dominio), URI después de salir https://<dominio>/ y aviso de cierre https://<dominio>/api/ithub-logout.php.
    // 'ithub' => [
    //     'client_id' => 'remitos',
    //     'client_secret' => 'ith_...',                 // NUNCA en git
    // ],

    // Envío de comprobantes y remitos por correo (función mail() del hosting). El remitente
    // tiene que ser una casilla que exista en el dominio: recibe los rebotes y las respuestas.
    // Vacío, el mensaje sale desde la casilla de quien lo envía. Siempre con el nombre de la empresa.
    'correo' => [
        'remitente' => 'soporte@intellihelp.tech',
        'empresa' => 'INTELLIHELP S.A.',
    ],

    // IP de los proxies inversos de confianza. Solo desde estas IP se acepta la cabecera
    // X-Forwarded-Proto para saber si la conexión original fue HTTPS. Sin proxy: vacío.
    'trusted_proxies' => [],
];
