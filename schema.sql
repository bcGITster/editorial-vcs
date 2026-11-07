-- Editorial VCS Schema
-- Run this to initialize a new database:
--   mysql -u editorial_user -p editorial_vcs < schema.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Users table for application-level authentication
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(64) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) DEFAULT NULL,
    `role` ENUM('admin', 'editor', 'viewer') NOT NULL DEFAULT 'editor',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_login` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `username` (`username`),
    UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Projects (silos)
CREATE TABLE IF NOT EXISTS `projects` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(128) NOT NULL,
    `slug` VARCHAR(64) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `deploy_path` VARCHAR(512) DEFAULT NULL COMMENT 'Absolute server path for import/deploy operations',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Files within projects
CREATE TABLE IF NOT EXISTS `files` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `project_id` INT UNSIGNED NOT NULL,
    `filepath` VARCHAR(512) NOT NULL COMMENT 'Relative path within project',
    `filetype` ENUM('text', 'binary') NOT NULL DEFAULT 'text',
    `status` ENUM('draft', 'review', 'final') NOT NULL DEFAULT 'draft',
    `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Soft delete flag',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `project_filepath` (`project_id`, `filepath`),
    KEY `project_id` (`project_id`),
    KEY `status` (`status`),
    CONSTRAINT `files_project_fk` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Revisions (full content stored, not diffs)
CREATE TABLE IF NOT EXISTS `revisions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `file_id` INT UNSIGNED NOT NULL,
    `content` LONGTEXT DEFAULT NULL COMMENT 'Text content (NULL for binary files)',
    `binary_content` LONGBLOB DEFAULT NULL COMMENT 'Binary content (NULL for text files)',
    `message` VARCHAR(512) NOT NULL DEFAULT '',
    `author` VARCHAR(64) NOT NULL,
    `size_bytes` INT UNSIGNED NOT NULL DEFAULT 0,
    `content_hash` VARCHAR(32) DEFAULT NULL COMMENT 'MD5 hash for change detection',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `file_id` (`file_id`),
    KEY `created_at` (`created_at`),
    CONSTRAINT `revisions_file_fk` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Deployment log
CREATE TABLE IF NOT EXISTS `deployments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `file_id` INT UNSIGNED NOT NULL,
    `revision_id` INT UNSIGNED NOT NULL,
    `deployed_by` VARCHAR(64) NOT NULL,
    `deployed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deploy_path` VARCHAR(512) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `file_id` (`file_id`),
    KEY `revision_id` (`revision_id`),
    CONSTRAINT `deployments_file_fk` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
    CONSTRAINT `deployments_revision_fk` FOREIGN KEY (`revision_id`) REFERENCES `revisions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lore entries (optional feature for creative projects)
CREATE TABLE IF NOT EXISTS `lore_entries` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(128) NOT NULL,
    `slug` VARCHAR(128) NOT NULL,
    `title` VARCHAR(256) DEFAULT NULL COMMENT 'Role or subtitle',
    `category` VARCHAR(64) NOT NULL DEFAULT 'character',
    `faction` VARCHAR(64) DEFAULT NULL COMMENT 'Group/faction for graph coloring',
    `aliases` TEXT DEFAULT NULL COMMENT 'Comma-separated alternate names',
    `content` LONGTEXT DEFAULT NULL,
    `created_by` VARCHAR(64) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`),
    KEY `category` (`category`),
    KEY `faction` (`faction`),
    FULLTEXT KEY `content_search` (`name`, `aliases`, `content`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- App state for setup wizard and migrations
CREATE TABLE IF NOT EXISTS `app_state` (
    `key` VARCHAR(64) NOT NULL,
    `value` TEXT DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
