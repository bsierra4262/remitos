<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Conexión a MySQL/MariaDB y migraciones del esquema.
 *
 * El esquema se versiona en la tabla `app_meta`. Cada migración es idempotente:
 * puede aplicarse sobre una base nueva o sobre una instalación existente sin
 * perder datos. Las migraciones se ejecutan por consola durante el despliegue
 * (`php api/cli/migrar.php`): las peticiones web nunca modifican el esquema y,
 * si la base está desactualizada, responden 503 hasta que se migre.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/** Versión del esquema que espera este código. */
const API_SCHEMA_VERSION = 5;

/** Zona horaria de la sesión de base de datos (Argentina, UTC−3 todo el año). */
const API_DB_TIMEZONE = '-03:00';

/** Conexión compartida (una por petición). */
function api_db(array $config): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = $config['db'] ?? [];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $db['host'] ?? 'localhost',
        (int) ($db['port'] ?? 3306),
        $db['name'] ?? ''
    );

    try {
        $pdo = new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        error_log('[remitos] Conexión a la base de datos: ' . $e->getMessage());
        api_error(503, 'No se pudo conectar a la base de datos');
    }

    $pdo->exec("SET time_zone = '" . API_DB_TIMEZONE . "'");
    if (PHP_SAPI !== 'cli' && api_schema_version($pdo) < API_SCHEMA_VERSION) {
        error_log('[remitos] La base está en la versión ' . api_schema_version($pdo) . ' y el código espera la ' . API_SCHEMA_VERSION);
        api_error(503, 'El sistema se está actualizando. Volvé a intentar en unos minutos.');
    }

    return $pdo;
}

/** Aplica las migraciones pendientes. Usa un bloqueo para no correrlas dos veces en paralelo. */
function api_migrate(PDO $pdo): void
{
    if (api_schema_version($pdo) >= API_SCHEMA_VERSION) {
        return;
    }

    $pdo->query("SELECT GET_LOCK('remitos_schema', 15)");
    try {
        $current = api_schema_version($pdo);
        $migrations = [
            1 => 'api_migration_1',
            2 => 'api_migration_2',
            3 => 'api_migration_3',
            4 => 'api_migration_4',
            5 => 'api_migration_5',
        ];
        foreach ($migrations as $version => $migration) {
            if ($version > $current) {
                $migration($pdo);
                $pdo->prepare(
                    "INSERT INTO app_meta (clave, valor) VALUES ('schema_version', ?)
                     ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
                )->execute([(string) $version]);
            }
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('remitos_schema')");
    }
}

/** Versión del esquema instalada (0 si la base está vacía). */
function api_schema_version(PDO $pdo): int
{
    try {
        $value = $pdo->query("SELECT valor FROM app_meta WHERE clave = 'schema_version'")->fetchColumn();

        return (int) $value;
    } catch (PDOException) {
        return 0;
    }
}

/** ¿Existe la columna en la tabla? */
function api_column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$table, $column]);

    return (int) $st->fetchColumn() > 0;
}

/** ¿Existe el índice en la tabla? */
function api_index_exists(PDO $pdo, string $table, string $index): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $st->execute([$table, $index]);

    return (int) $st->fetchColumn() > 0;
}

/**
 * Esquema base: claves, auditoría, remitos, talonarios de C.A.I. y credenciales de ARCA.
 * Sobre una instalación anterior solo agrega lo que falta.
 */
function api_migration_1(PDO $pdo): void
{
    $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $pdo->exec("CREATE TABLE IF NOT EXISTS app_meta (
        clave VARCHAR(40)  NOT NULL PRIMARY KEY,
        valor VARCHAR(255) NOT NULL
    ) $engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS api_keys (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        nombre     VARCHAR(80)  NOT NULL,
        prefijo    VARCHAR(16)  NOT NULL,
        hash       CHAR(64)     NOT NULL,
        permisos   VARCHAR(60)  NOT NULL DEFAULT 'leer',
        activa     TINYINT(1)   NOT NULL DEFAULT 1,
        nota       VARCHAR(160) DEFAULT NULL,
        creada     DATETIME     NOT NULL,
        ultimo_uso DATETIME     DEFAULT NULL,
        usos       INT          NOT NULL DEFAULT 0,
        UNIQUE KEY uq_hash (hash)
    ) $engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS api_log (
        id        BIGINT AUTO_INCREMENT PRIMARY KEY,
        categoria VARCHAR(20)  NOT NULL DEFAULT 'sistema',
        nivel     VARCHAR(10)  NOT NULL DEFAULT 'info',
        origen    VARCHAR(6)   NOT NULL DEFAULT 'api',
        clave     VARCHAR(80)  DEFAULT NULL,
        accion    VARCHAR(180) NOT NULL,
        detalle   VARCHAR(255) DEFAULT NULL,
        recurso   VARCHAR(60)  DEFAULT NULL,
        estado    SMALLINT     NOT NULL DEFAULT 200,
        ip        VARCHAR(45)  DEFAULT NULL,
        fecha     DATETIME     NOT NULL,
        INDEX idx_fecha (fecha),
        INDEX idx_categoria (categoria),
        INDEX idx_nivel (nivel),
        INDEX idx_ip_fecha (ip, fecha)
    ) $engine");
    if (!api_index_exists($pdo, 'api_log', 'idx_ip_fecha')) {
        $pdo->exec('ALTER TABLE api_log ADD INDEX idx_ip_fecha (ip, fecha)');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS remitos (
        id        VARCHAR(16)  NOT NULL PRIMARY KEY,
        id_local  VARCHAR(40)  DEFAULT NULL,
        numero    VARCHAR(40)  DEFAULT NULL,
        fecha     VARCHAR(12)  DEFAULT NULL,
        cliente   VARCHAR(255) DEFAULT NULL,
        data      LONGTEXT     NOT NULL,
        origen    VARCHAR(20)  NOT NULL DEFAULT 'api',
        facturado TINYINT(1)   NOT NULL DEFAULT 0,
        created   BIGINT       NOT NULL,
        updated   BIGINT       NOT NULL,
        INDEX idx_updated (updated),
        UNIQUE KEY uq_id_local (id_local)
    ) $engine");
    if (!api_column_exists($pdo, 'remitos', 'facturado')) {
        $pdo->exec('ALTER TABLE remitos ADD COLUMN facturado TINYINT(1) NOT NULL DEFAULT 0');
    }
    if (!api_column_exists($pdo, 'remitos', 'id_local')) {
        $pdo->exec('ALTER TABLE remitos ADD COLUMN id_local VARCHAR(40) DEFAULT NULL AFTER id');
    }
    if (!api_index_exists($pdo, 'remitos', 'uq_id_local')) {
        $pdo->exec('ALTER TABLE remitos ADD UNIQUE KEY uq_id_local (id_local)');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS cai_talonarios (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        punto_venta      INT UNSIGNED NOT NULL,
        tipo_comprobante SMALLINT     NOT NULL DEFAULT 91,
        numero_desde     INT UNSIGNED NOT NULL,
        numero_hasta     INT UNSIGNED NOT NULL,
        proximo_numero   INT UNSIGNED NOT NULL,
        cai              CHAR(14)     NOT NULL,
        cai_vencimiento  DATE         NOT NULL,
        activa           TINYINT(1)   NOT NULL DEFAULT 1,
        nota             VARCHAR(160) DEFAULT NULL,
        creado           DATETIME     NOT NULL,
        UNIQUE KEY uq_rango (punto_venta, tipo_comprobante, numero_desde)
    ) $engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS arca_credenciales (
        ambiente     VARCHAR(12)  NOT NULL PRIMARY KEY,
        cuit         CHAR(11)     NOT NULL,
        titular      VARCHAR(160) NOT NULL,
        emisor       VARCHAR(160) NOT NULL,
        serie        VARCHAR(64)  NOT NULL,
        huella       CHAR(64)     NOT NULL,
        valido_desde DATETIME     NOT NULL,
        valido_hasta DATETIME     NOT NULL,
        certificado  TEXT         NOT NULL,
        clave        TEXT         NOT NULL,
        cargado      DATETIME     NOT NULL,
        cargado_por  VARCHAR(80)  DEFAULT NULL
    ) $engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS arca_tickets (
        ambiente VARCHAR(12) NOT NULL,
        servicio VARCHAR(20) NOT NULL,
        token    TEXT        NOT NULL,
        sign     TEXT        NOT NULL,
        expira   DATETIME    NOT NULL,
        PRIMARY KEY (ambiente, servicio)
    ) $engine");
}

/** Agrega una columna si no existe. */
function api_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!api_column_exists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
}

/**
 * Datos compartidos: usuarios con sesión, clientes, comprobantes y ajustes en el
 * servidor (antes vivían en el navegador de cada equipo), y numeración de remitos
 * asignada por el servidor.
 */
function api_migration_2(PDO $pdo): void
{
    $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        nombre           VARCHAR(120) NOT NULL,
        email            VARCHAR(160) NOT NULL,
        clave_hash       VARCHAR(255) NOT NULL,
        rol              VARCHAR(12)  NOT NULL DEFAULT 'operador',
        activo           TINYINT(1)   NOT NULL DEFAULT 1,
        debe_cambiar     TINYINT(1)   NOT NULL DEFAULT 1,
        intentos         SMALLINT     NOT NULL DEFAULT 0,
        bloqueado_hasta  DATETIME     DEFAULT NULL,
        ultimo_ingreso   DATETIME     DEFAULT NULL,
        clave_cambiada   DATETIME     DEFAULT NULL,
        creado           DATETIME     NOT NULL,
        UNIQUE KEY uq_email (email)
    ) $engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ajustes (
        clave           VARCHAR(40)  NOT NULL PRIMARY KEY,
        valor           TEXT         NOT NULL,
        actualizado     DATETIME     NOT NULL,
        actualizado_por VARCHAR(120) DEFAULT NULL
    ) $engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS clientes (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        razon_social    VARCHAR(160) NOT NULL DEFAULT '',
        cuit            VARCHAR(13)  DEFAULT NULL,
        data            TEXT         NOT NULL,
        creado          DATETIME     NOT NULL,
        actualizado     DATETIME     NOT NULL,
        creado_por      VARCHAR(120) DEFAULT NULL,
        actualizado_por VARCHAR(120) DEFAULT NULL,
        UNIQUE KEY uq_cuit (cuit),
        INDEX idx_razon (razon_social)
    ) $engine");

    api_add_column($pdo, 'remitos', 'numero_int', 'INT UNSIGNED DEFAULT NULL');
    api_add_column($pdo, 'remitos', 'punto_venta', 'INT UNSIGNED DEFAULT NULL');
    api_add_column($pdo, 'remitos', 'estado', "VARCHAR(12) NOT NULL DEFAULT 'borrador'");
    api_add_column($pdo, 'remitos', 'creado_por', 'VARCHAR(120) DEFAULT NULL');
    api_add_column($pdo, 'remitos', 'actualizado_por', 'VARCHAR(120) DEFAULT NULL');
    if (!api_index_exists($pdo, 'remitos', 'idx_numero_int')) {
        $pdo->exec('ALTER TABLE remitos ADD INDEX idx_numero_int (numero_int)');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS comprobantes (
        id       VARCHAR(16)  NOT NULL PRIMARY KEY,
        tipo     VARCHAR(12)  NOT NULL,
        letra    VARCHAR(2)   NOT NULL,
        pto_vta  VARCHAR(5)   NOT NULL,
        numero   VARCHAR(8)   NOT NULL,
        fecha    VARCHAR(12)  DEFAULT NULL,
        cliente  VARCHAR(255) DEFAULT NULL,
        cae      VARCHAR(20)  DEFAULT NULL,
        cae_vto  VARCHAR(12)  DEFAULT NULL,
        created  BIGINT       NOT NULL
    ) $engine");
    api_add_column($pdo, 'comprobantes', 'subtipo', "VARCHAR(8) NOT NULL DEFAULT '' AFTER tipo");
    api_add_column($pdo, 'comprobantes', 'numero_int', 'INT UNSIGNED NOT NULL DEFAULT 0');
    api_add_column($pdo, 'comprobantes', 'data', 'LONGTEXT NULL');
    api_add_column($pdo, 'comprobantes', 'updated', 'BIGINT NOT NULL DEFAULT 0');
    api_add_column($pdo, 'comprobantes', 'creado_por', 'VARCHAR(120) DEFAULT NULL');
    api_add_column($pdo, 'comprobantes', 'actualizado_por', 'VARCHAR(120) DEFAULT NULL');
    if (api_index_exists($pdo, 'comprobantes', 'uq_num')) {
        $pdo->exec('ALTER TABLE comprobantes DROP INDEX uq_num');
    }
    $pdo->exec('ALTER TABLE comprobantes ADD UNIQUE KEY uq_num (tipo, subtipo, letra, pto_vta, numero_int)');
    if (!api_index_exists($pdo, 'comprobantes', 'idx_updated')) {
        $pdo->exec('ALTER TABLE comprobantes ADD INDEX idx_updated (updated)');
    }
}

/** Modifica la definición de una columna existente. */
function api_column_type(PDO $pdo, string $table, string $column): string
{
    $st = $pdo->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$table, $column]);

    return strtolower((string) $st->fetchColumn());
}

/**
 * Revisión de noviembre de 2026:
 * - Intentos fallidos en su propia tabla, separados de la auditoría.
 * - Auditoría con usuario e integridad encadenada (hash de cada evento con el anterior).
 * - Sesiones invalidables por usuario y segundo factor (TOTP).
 * - Remitos: el número fiscal solo existe en los autorizados y es único por punto de
 *   venta; los borradores llevan una referencia interna. Fecha de emisión como DATE.
 * - Comprobantes: número nulo hasta que se asigna, idempotencia por id_local, estado y fecha.
 */
function api_migration_3(PDO $pdo): void
{
    $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $pdo->exec("CREATE TABLE IF NOT EXISTS intentos (
        id    BIGINT AUTO_INCREMENT PRIMARY KEY,
        tipo  VARCHAR(20)  NOT NULL,
        clave VARCHAR(190) NOT NULL DEFAULT '',
        ip    VARCHAR(45)  NOT NULL DEFAULT '',
        fecha DATETIME     NOT NULL,
        INDEX idx_tipo_clave (tipo, clave, fecha),
        INDEX idx_tipo_ip (tipo, ip, fecha)
    ) $engine");

    api_add_column($pdo, 'api_log', 'usuario_id', 'INT DEFAULT NULL AFTER origen');
    api_add_column($pdo, 'api_log', 'prev_hash', 'CHAR(64) DEFAULT NULL');
    api_add_column($pdo, 'api_log', 'hash', 'CHAR(64) DEFAULT NULL');
    audit_rechain($pdo);

    api_add_column($pdo, 'usuarios', 'sesion_version', 'INT NOT NULL DEFAULT 1');
    api_add_column($pdo, 'usuarios', 'totp_secret', 'TEXT DEFAULT NULL');
    api_add_column($pdo, 'usuarios', 'totp_activo', 'TINYINT(1) NOT NULL DEFAULT 0');
    api_add_column($pdo, 'usuarios', 'totp_ultimo', 'BIGINT DEFAULT NULL');

    // Remitos
    if (str_starts_with(api_column_type($pdo, 'remitos', 'estado'), 'enum')) {
        $pdo->exec("ALTER TABLE remitos MODIFY estado VARCHAR(12) NOT NULL DEFAULT 'borrador'");
    }
    api_add_column($pdo, 'remitos', 'referencia', 'INT UNSIGNED DEFAULT NULL AFTER id_local');
    api_add_column($pdo, 'remitos', 'fecha_emision', 'DATE DEFAULT NULL');
    $pdo->exec('UPDATE remitos SET referencia = NULL');
    $rows = $pdo->query('SELECT id, estado, fecha, data FROM remitos ORDER BY created, id')->fetchAll();
    $ref = 0;
    foreach ($rows as $row) {
        $doc = json_decode((string) $row['data'], true) ?: [];
        $autorizado = $row['estado'] === 'autorizado';
        $ref++;
        if (!$autorizado) {
            $doc['numero'] = '';
            $doc['cai'] = '';
            $doc['caiVto'] = '';
        }
        $doc['referencia'] = $ref;
        $pdo->prepare(
            'UPDATE remitos SET referencia = ?, data = ?, fecha_emision = ?' .
            ($autorizado ? '' : ', numero = NULL, numero_int = NULL, punto_venta = NULL') . ' WHERE id = ?'
        )->execute([$ref, json_encode($doc, JSON_UNESCAPED_UNICODE), api_date_iso((string) $row['fecha']), $row['id']]);
    }
    if (!api_index_exists($pdo, 'remitos', 'uq_referencia')) {
        $pdo->exec('ALTER TABLE remitos ADD UNIQUE KEY uq_referencia (referencia)');
    }
    if (!api_index_exists($pdo, 'remitos', 'uq_numero')) {
        $pdo->exec('ALTER TABLE remitos ADD UNIQUE KEY uq_numero (punto_venta, numero_int)');
    }
    if (!api_index_exists($pdo, 'remitos', 'idx_fecha_emision')) {
        $pdo->exec('ALTER TABLE remitos ADD INDEX idx_fecha_emision (fecha_emision)');
    }

    // Comprobantes
    $pdo->exec('ALTER TABLE comprobantes MODIFY numero VARCHAR(8) DEFAULT NULL');
    $pdo->exec('ALTER TABLE comprobantes MODIFY numero_int INT UNSIGNED DEFAULT NULL');
    api_add_column($pdo, 'comprobantes', 'id_local', 'VARCHAR(40) DEFAULT NULL AFTER id');
    api_add_column($pdo, 'comprobantes', 'estado', "VARCHAR(12) NOT NULL DEFAULT 'borrador'");
    api_add_column($pdo, 'comprobantes', 'fecha_emision', 'DATE DEFAULT NULL');
    $pdo->exec("UPDATE comprobantes SET numero = NULL, numero_int = NULL WHERE numero = '' OR numero_int = 0");
    $pdo->exec("UPDATE comprobantes SET estado = 'autorizado' WHERE cae IS NOT NULL AND cae <> ''");
    foreach ($pdo->query('SELECT id, fecha FROM comprobantes')->fetchAll() as $row) {
        $pdo->prepare('UPDATE comprobantes SET fecha_emision = ? WHERE id = ?')
            ->execute([api_date_iso((string) $row['fecha']), $row['id']]);
    }
    if (!api_index_exists($pdo, 'comprobantes', 'uq_id_local')) {
        $pdo->exec('ALTER TABLE comprobantes ADD UNIQUE KEY uq_id_local (id_local)');
    }
}

/**
 * Noviembre de 2026, normativa de remitos:
 * - Talonarios verificados con ARCA (WSCDC, constatación de comprobantes con C.A.I.).
 * - Remito digital (RG 5678/2025): enlace de conformidad para el destinatario y registro
 *   de quién confirmó la recepción, cuándo y desde dónde.
 */
function api_migration_4(PDO $pdo): void
{
    api_add_column($pdo, 'cai_talonarios', 'verificado_arca', 'DATETIME DEFAULT NULL');
    api_add_column($pdo, 'cai_talonarios', 'verificacion', 'VARCHAR(255) DEFAULT NULL');

    api_add_column($pdo, 'remitos', 'conformidad_hash', 'CHAR(64) DEFAULT NULL');
    api_add_column($pdo, 'remitos', 'conformidad_desde', 'DATETIME DEFAULT NULL');
    api_add_column($pdo, 'remitos', 'conformado', 'DATETIME DEFAULT NULL');
    api_add_column($pdo, 'remitos', 'conformado_nombre', 'VARCHAR(120) DEFAULT NULL');
    api_add_column($pdo, 'remitos', 'conformado_doc', 'VARCHAR(20) DEFAULT NULL');
    api_add_column($pdo, 'remitos', 'conformado_obs', 'VARCHAR(300) DEFAULT NULL');
    api_add_column($pdo, 'remitos', 'conformado_ip', 'VARCHAR(45) DEFAULT NULL');
    if (!api_index_exists($pdo, 'remitos', 'uq_conformidad')) {
        $pdo->exec('ALTER TABLE remitos ADD UNIQUE KEY uq_conformidad (conformidad_hash)');
    }
}

/**
 * Noviembre de 2026: se ingresa solo con la cuenta de Google de Intellihelp. Se borran
 * de la base las contraseñas, las contraseñas temporales y los secretos del segundo
 * factor (TOTP), con sus intentos fallidos; se cierran todas las sesiones abiertas.
 */
function api_migration_5(PDO $pdo): void
{
    foreach (['clave_hash', 'debe_cambiar', 'intentos', 'bloqueado_hasta', 'clave_cambiada', 'totp_secret', 'totp_activo', 'totp_ultimo'] as $column) {
        if (api_column_exists($pdo, 'usuarios', $column)) {
            $pdo->exec("ALTER TABLE usuarios DROP COLUMN $column");
        }
    }
    $pdo->exec("DELETE FROM intentos WHERE tipo IN ('ingreso', 'totp', 'reconfirmar')");
    $pdo->exec('UPDATE usuarios SET sesion_version = sesion_version + 1');
}
