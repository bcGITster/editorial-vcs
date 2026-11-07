<?php
/**
 * Database connection
 */

require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $cfg = config('db');
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['name'],
            $cfg['charset'] ?? 'utf8mb4'
        );
        $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function db_table_exists(string $table): bool {
    try {
        $stmt = db()->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return $stmt->fetch() !== false;
    } catch (PDOException $e) {
        return false;
    }
}

function db_is_initialized(): bool {
    return db_table_exists('users') && db_table_exists('projects');
}

function db_has_admin(): bool {
    if (!db_table_exists('users')) {
        return false;
    }
    $stmt = db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    return (int) $stmt->fetchColumn() > 0;
}

function db_has_projects(): bool {
    if (!db_table_exists('projects')) {
        return false;
    }
    $stmt = db()->query("SELECT COUNT(*) FROM projects");
    return (int) $stmt->fetchColumn() > 0;
}
