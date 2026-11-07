<?php
/**
 * Versioning functions — projects, files, revisions
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function get_project(string $slug): ?array {
    $stmt = db()->prepare("SELECT * FROM projects WHERE slug = ?");
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

function get_file(int $project_id, string $filepath): ?array {
    $stmt = db()->prepare("SELECT * FROM files WHERE project_id = ? AND filepath = ? AND is_deleted = 0");
    $stmt->execute([$project_id, $filepath]);
    return $stmt->fetch() ?: null;
}

function get_file_by_id(int $id): ?array {
    $stmt = db()->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function get_revision(int $id): ?array {
    $stmt = db()->prepare("SELECT * FROM revisions WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function get_head_revision(int $file_id): ?array {
    $stmt = db()->prepare("SELECT * FROM revisions WHERE file_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$file_id]);
    return $stmt->fetch() ?: null;
}

function create_revision(int $file_id, ?string $content, ?string $binary, string $message, string $author): int {
    if ($content !== null) {
        $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252, UTF-8');
    }
    $size = $content !== null ? strlen($content) : ($binary !== null ? strlen($binary) : 0);
    $hash = md5($content ?? $binary ?? '');

    $stmt = db()->prepare("
        INSERT INTO revisions (file_id, content, binary_content, message, author, size_bytes, content_hash)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$file_id, $content, $binary, $message, $author, $size, $hash]);

    db()->prepare("UPDATE files SET updated_at = NOW() WHERE id = ?")->execute([$file_id]);

    return (int) db()->lastInsertId();
}

function create_file(int $project_id, string $filepath, string $filetype = 'text', string $status = 'draft'): int {
    $stmt = db()->prepare("INSERT INTO files (project_id, filepath, filetype, status) VALUES (?, ?, ?, ?)");
    $stmt->execute([$project_id, $filepath, $filetype, $status]);
    return (int) db()->lastInsertId();
}

function resurrect_file(int $file_id, string $filetype, string $status): void {
    db()->prepare("UPDATE files SET is_deleted = 0, filetype = ?, status = ? WHERE id = ?")
        ->execute([$filetype, $status, $file_id]);
}

function soft_delete_file(int $file_id): void {
    db()->prepare("UPDATE files SET is_deleted = 1 WHERE id = ?")->execute([$file_id]);
}

function create_project(string $name, string $slug, ?string $description = null, ?string $deploy_path = null): int {
    $stmt = db()->prepare("INSERT INTO projects (name, slug, description, deploy_path) VALUES (?, ?, ?, ?)");
    $stmt->execute([$name, $slug, $description, $deploy_path]);
    return (int) db()->lastInsertId();
}

function get_all_projects(): array {
    return db()->query("SELECT * FROM projects ORDER BY name")->fetchAll();
}

function slugify(string $text): string {
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(str_replace(' ', '-', $text)));
    return $slug ?: 'project-' . time();
}

function project_slug_exists(string $slug): bool {
    $stmt = db()->prepare("SELECT id FROM projects WHERE slug = ?");
    $stmt->execute([$slug]);
    return $stmt->fetch() !== false;
}
