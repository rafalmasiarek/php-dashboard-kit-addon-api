<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitApi\Schema;

use PDO;

/**
 * Creates the database tables required by the API token system.
 *
 * Tables created:
 *   user_tokens  — opaque bearer tokens tied to a user_id.
 *   scopes       — scope name dictionary (id, name UNIQUE).
 *   token_scopes — many-to-many junction between tokens and scopes.
 *
 * Called directly by ApiAddon::register() on every boot (all statements use IF NOT EXISTS).
 *
 * @package rafalmasiarek\DashboardKitApi\Schema
 */
final class ApiSchemaProvider
{
    /**
     * {@inheritDoc}
     */
    public function createSchema(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $this->createMysql($pdo);
        } else {
            $this->createSqlite($pdo);
        }
    }

    /**
     * Create tables for MySQL.
     *
     * @param PDO $pdo
     * @return void
     */
    private function createMysql(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `user_tokens` (
                `token`      VARCHAR(255) NOT NULL,
                `user_id`    CHAR(36)     NOT NULL,
                `subject`    VARCHAR(255) DEFAULT NULL,
                `expires_at` DATETIME     DEFAULT NULL,
                PRIMARY KEY (`token`),
                INDEX `idx_user_id` (`user_id`),
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `scopes` (
                `id`          INT          NOT NULL AUTO_INCREMENT,
                `name`        VARCHAR(128) NOT NULL,
                `description` VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `token_scopes` (
                `token`    VARCHAR(255) NOT NULL,
                `scope_id` INT          NOT NULL,
                PRIMARY KEY (`token`, `scope_id`),
                FOREIGN KEY (`token`)    REFERENCES `user_tokens`(`token`) ON DELETE CASCADE,
                FOREIGN KEY (`scope_id`) REFERENCES `scopes`(`id`)         ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scopes' AND COLUMN_NAME = 'description'"
        );
        $stmt->execute();
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `scopes` ADD COLUMN `description` VARCHAR(255) DEFAULT NULL");
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `user_scopes` (
                `user_id`  CHAR(36) NOT NULL,
                `scope_id` INT      NOT NULL,
                PRIMARY KEY (`user_id`, `scope_id`),
                FOREIGN KEY (`user_id`)  REFERENCES `users`(`id`)  ON DELETE CASCADE,
                FOREIGN KEY (`scope_id`) REFERENCES `scopes`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * Create tables for SQLite.
     *
     * @param PDO $pdo
     * @return void
     */
    private function createSqlite(PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = ON');

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_tokens (
                token      TEXT NOT NULL PRIMARY KEY,
                user_id    TEXT NOT NULL,
                subject    TEXT DEFAULT NULL,
                expires_at TEXT DEFAULT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS scopes (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT    NOT NULL,
                description TEXT    DEFAULT NULL,
                UNIQUE (name)
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS token_scopes (
                token    TEXT    NOT NULL,
                scope_id INTEGER NOT NULL,
                PRIMARY KEY (token, scope_id),
                FOREIGN KEY (token)    REFERENCES user_tokens(token) ON DELETE CASCADE,
                FOREIGN KEY (scope_id) REFERENCES scopes(id)         ON DELETE CASCADE
            )
        ");

        try {
            $pdo->exec("ALTER TABLE scopes ADD COLUMN description TEXT DEFAULT NULL");
        } catch (\Throwable) {
            // Column already exists — safe to ignore.
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_scopes (
                user_id  TEXT    NOT NULL,
                scope_id INTEGER NOT NULL,
                PRIMARY KEY (user_id, scope_id),
                FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
                FOREIGN KEY (scope_id) REFERENCES scopes(id) ON DELETE CASCADE
            )
        ");
    }
}
