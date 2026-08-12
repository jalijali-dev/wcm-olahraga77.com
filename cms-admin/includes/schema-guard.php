<?php
declare(strict_types=1);

/**
 * Lightweight, idempotent "add column if missing" helper shared by any
 * admin page that needs to self-heal its own schema on load (Gallery,
 * Media Library, etc.) instead of showing a Fatal Error or a raw PDO
 * exception to the admin.
 *
 * Safe to call on every page load: it checks INFORMATION_SCHEMA.COLUMNS
 * first and only runs ALTER TABLE when the column truly doesn't exist yet,
 * so repeat calls never duplicate a column and never fail on a re-run.
 */
if (!function_exists('cms_ensure_column')) {
    function cms_ensure_column(PDO $pdo, string $table, string $column, string $definition): bool
    {
        $check = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = :table
               AND COLUMN_NAME  = :column'
        );
        $check->execute(['table' => $table, 'column' => $column]);
        if ((int) $check->fetchColumn() > 0) {
            return false; // already existed — nothing to do
        }

        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");

        return true; // column just added
    }
}

/**
 * Widens an existing column's type (e.g. VARCHAR(50) -> VARCHAR(255)) —
 * cms_ensure_column() only adds a column when it's missing entirely, it
 * can't change an already-existing column's definition. Checks the live
 * COLUMN_TYPE first and only runs ALTER TABLE ... MODIFY COLUMN when it
 * differs from the target, so repeat calls are safe no-ops once applied.
 */
if (!function_exists('cms_widen_column')) {
    function cms_widen_column(PDO $pdo, string $table, string $column, string $newDefinition): bool
    {
        $check = $pdo->prepare(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = :table
               AND COLUMN_NAME  = :column'
        );
        $check->execute(['table' => $table, 'column' => $column]);
        $currentType = (string) $check->fetchColumn();
        if ($currentType === '') {
            return false; // column doesn't exist — nothing to widen
        }

        $desiredType = strtolower((string) strtok($newDefinition, ' '));
        if (strtolower($currentType) === $desiredType) {
            return false; // already at target width
        }

        $pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$newDefinition}");

        return true;
    }
}

/**
 * Companion helper: create a whole table if it doesn't exist yet.
 * `$definitionSql` is the full column/constraint list that goes between
 * the parens of CREATE TABLE — safe to call on every page load.
 */
if (!function_exists('cms_ensure_table')) {
    function cms_ensure_table(PDO $pdo, string $table, string $definitionSql): bool
    {
        $check = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = :table'
        );
        $check->execute(['table' => $table]);
        if ((int) $check->fetchColumn() > 0) {
            return false; // already existed
        }

        $pdo->exec("CREATE TABLE `{$table}` ({$definitionSql}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        return true; // table just created
    }
}
