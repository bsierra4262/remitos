<?php

/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Conexión a MySQL/MariaDB y migraciones del esquema.
 *
 * El esquema se versiona en la tabla `app_meta`. Cada migración es idempotente:
 * puede aplicarse sobre una base nueva o sobre una instalación existente sin
 * perder datos. Solo se ejecutan las que todavía no se aplicaron.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/** Versión del esquema que espera este código. */
const API_SCHEMA_VERSION = 1;

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
    api_migrate($pdo);

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
