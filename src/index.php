<?php
/**
 * Editorial VCS — Main Application
 * Self-hosted editorial version control for creative projects.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/versioning.php';

// Set timezone
date_default_timezone_set(config('app.timezone', 'UTC'));

// Start session
session_start();

// Check if setup is needed
try {
    if (!db_is_initialized() || !db_has_admin() || !db_has_projects()) {
        header('Location: setup.php');
        exit;
    }
} catch (PDOException $e) {
    header('Location: setup.php');
    exit;
}

// Routing
$page = $_GET['page'] ?? 'dashboard';
$project_slug = $_GET['p'] ?? null;
$filepath = $_GET['f'] ?? null;

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Login ---
    if ($action === 'login') {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        if (auth_login($username, $password)) {
            redirect('?');
        }
        flash('Invalid username or password.', 'err');
        redirect('?page=login');
    }

    // --- Logout ---
    if ($action === 'logout') {
        auth_logout();
        redirect('?page=login');
    }

    // Require auth for all other actions
    if (!auth_current_user()) {
        redirect('?page=login');
    }

    // --- Save edit (new revision) ---
    if ($action === 'save' && $project_slug && $filepath) {
        $proj = get_project($project_slug);
        $file = $proj ? get_file($proj['id'], $filepath) : null;
        if ($file && $file['filetype'] === 'text') {
            $content = $_POST['content'] ?? '';
            $message = trim($_POST['message'] ?? '') ?: 'Edit via web UI';
            $content = str_replace("\r\n", "\n", $content);
            create_revision($file['id'], $content, null, $message, auth_current_username());
            flash("Revision saved for " . basename($filepath));
            redirect("?p=" . e($project_slug) . "&f=" . urlencode($filepath));
        }
        flash("Save failed — file not found or not a text file.", 'err');
        redirect("?p=" . e($project_slug));
    }

    // --- Create new file ---
    if ($action === 'create' && $project_slug) {
        $proj = get_project($project_slug);
        if ($proj) {
            $new_path = trim($_POST['filepath'] ?? '');
            $content = $_POST['content'] ?? '';
            $message = trim($_POST['message'] ?? '') ?: 'Initial creation';
            $status = $_POST['status'] ?? 'draft';
            $content = str_replace("\r\n", "\n", $content);
            if ($new_path === '') {
                flash("Filepath is required.", 'err');
                redirect("?p=" . e($project_slug) . "&page=create");
            }
            $new_path = ltrim($new_path, '/');
            $ftype = detect_filetype($new_path);

            // Check for soft-deleted file and resurrect
            $stmt = db()->prepare("SELECT * FROM files WHERE project_id = ? AND filepath = ?");
            $stmt->execute([$proj['id'], $new_path]);
            $existing = $stmt->fetch();

            if ($existing && !$existing['is_deleted']) {
                flash("File already exists: $new_path", 'err');
                redirect("?p=" . e($project_slug) . "&page=create");
            }
            if ($existing && $existing['is_deleted']) {
                resurrect_file($existing['id'], $ftype, $status);
                $file_id = $existing['id'];
            } else {
                $file_id = create_file($proj['id'], $new_path, $ftype, $status);
            }
            create_revision($file_id, $content, null, $message, auth_current_username());
            flash("Created $new_path");
            redirect("?p=" . e($project_slug) . "&f=" . urlencode($new_path));
        }
    }

    // --- Upload files ---
    if ($action === 'upload' && $project_slug) {
        $proj = get_project($project_slug);
        if ($proj && !empty($_FILES['files'])) {
            $subdir = trim($_POST['subdir'] ?? '', " \t\n\r/");
            $message = trim($_POST['message'] ?? '') ?: 'Upload via web UI';
            $status = $_POST['status'] ?? 'draft';
            $count = 0;
            $files_arr = $_FILES['files'];
            for ($i = 0; $i < count($files_arr['name']); $i++) {
                if ($files_arr['error'][$i] !== UPLOAD_ERR_OK) continue;
                $name = basename($files_arr['name'][$i]);
                $rel_path = $subdir ? "$subdir/$name" : $name;
                $ftype = detect_filetype($name);
                $tmp = $files_arr['tmp_name'][$i];

                // Check for soft-deleted file and resurrect
                $stmt = db()->prepare("SELECT * FROM files WHERE project_id = ? AND filepath = ?");
                $stmt->execute([$proj['id'], $rel_path]);
                $existing = $stmt->fetch();
                if ($existing && $existing['is_deleted']) {
                    resurrect_file($existing['id'], $ftype, $status);
                    $file_id = $existing['id'];
                } elseif ($existing) {
                    $file_id = $existing['id'];
                } else {
                    $file_id = create_file($proj['id'], $rel_path, $ftype, $status);
                }

                if ($ftype === 'text') {
                    $content = file_get_contents($tmp);
                    $content = str_replace("\r\n", "\n", $content);
                    create_revision($file_id, $content, null, $message, auth_current_username());
                } else {
                    $binary = file_get_contents($tmp);
                    create_revision($file_id, null, $binary, $message, auth_current_username());
                }
                $count++;
            }
            flash("Uploaded $count file(s).");
            redirect("?p=" . e($project_slug));
        }
    }

    // --- Change file status ---
    if ($action === 'set_status' && $project_slug && $filepath) {
        $proj = get_project($project_slug);
        $file = $proj ? get_file($proj['id'], $filepath) : null;
        $new_status = $_POST['new_status'] ?? '';
        if ($file && in_array($new_status, ['draft','review','final'])) {
            db()->prepare("UPDATE files SET status = ? WHERE id = ?")->execute([$new_status, $file['id']]);
            flash("Status changed to $new_status.");
            redirect("?p=" . e($project_slug) . "&f=" . urlencode($filepath));
        }
        flash("Status change failed.", 'err');
        redirect("?p=" . e($project_slug));
    }

    // --- Promote file to another project ---
    if ($action === 'promote' && $project_slug && $filepath) {
        $src_proj = get_project($project_slug);
        $src_file = $src_proj ? get_file($src_proj['id'], $filepath) : null;
        $dest_slug = $_POST['dest_project'] ?? '';
        $dest_path = trim($_POST['dest_path'] ?? '') ?: $filepath;
        $dest_path = ltrim($dest_path, '/');
        $dest_proj = $dest_slug ? get_project($dest_slug) : null;

        if ($src_file && $dest_proj && $dest_proj['id'] !== $src_proj['id']) {
            $head = get_head_revision($src_file['id']);
            if ($head) {
                // Check for existing file in destination
                $stmt = db()->prepare("SELECT * FROM files WHERE project_id = ? AND filepath = ?");
                $stmt->execute([$dest_proj['id'], $dest_path]);
                $dest_file = $stmt->fetch();

                if ($dest_file && $dest_file['is_deleted']) {
                    resurrect_file($dest_file['id'], $src_file['filetype'], 'final');
                    $dest_file_id = $dest_file['id'];
                } elseif ($dest_file) {
                    $dest_file_id = $dest_file['id'];
                } else {
                    $dest_file_id = create_file($dest_proj['id'], $dest_path, $src_file['filetype'], 'final');
                }

                $msg = "Promoted from {$src_proj['name']}/" . basename($filepath);
                if ($src_file['filetype'] === 'text') {
                    create_revision($dest_file_id, $head['content'], null, $msg, auth_current_username());
                } else {
                    create_revision($dest_file_id, null, $head['binary_content'], $msg, auth_current_username());
                }

                flash("Promoted to {$dest_proj['name']}/$dest_path");
                redirect("?p=" . e($project_slug) . "&f=" . urlencode($filepath));
            }
        }
        flash("Promote failed.", 'err');
        redirect("?p=" . e($project_slug) . "&f=" . urlencode($filepath));
    }

    // --- Delete file (soft) ---
    if ($action === 'delete' && $project_slug && $filepath) {
        $proj = get_project($project_slug);
        $file = $proj ? get_file($proj['id'], $filepath) : null;
        if ($file) {
            soft_delete_file($file['id']);
            flash("Deleted " . basename($filepath) . " (revisions preserved).");
        }
        redirect("?p=" . e($project_slug));
    }

    // --- Import existing files from production ---
    if ($action === 'import' && $project_slug) {
        $proj = get_project($project_slug);
        if ($proj && $proj['deploy_path'] && is_dir($proj['deploy_path'])) {
            $base = rtrim($proj['deploy_path'], '/');
            $extensions = ['html','htm','txt','md','css','js','json','php','png','jpg','jpeg','gif','webp','svg','eml'];
            $count = 0;
            $skipped = 0;
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iter as $item) {
                if ($item->isDir()) continue;
                $ext = strtolower($item->getExtension());
                if (!in_array($ext, $extensions)) continue;
                $rel = substr($item->getPathname(), strlen($base) + 1);

                $ftype = detect_filetype($rel);
                $existing = get_file($proj['id'], $rel);

                if ($existing) {
                    $head = get_head_revision($existing['id']);
                    if ($head) {
                        $prod_content = file_get_contents($item->getPathname());
                        $prod_hash = md5($prod_content);
                        $head_hash = $head['content_hash'] ?? '';

                        if ($prod_hash === $head_hash) {
                            $skipped++;
                            continue;
                        }
                        if ($ftype === 'text') {
                            create_revision($existing['id'], $prod_content, null, 'Updated from production', auth_current_username());
                        } else {
                            create_revision($existing['id'], null, $prod_content, 'Updated from production', auth_current_username());
                        }
                        $count++;
                        continue;
                    }
                }

                // Check for soft-deleted file and resurrect
                $stmt = db()->prepare("SELECT * FROM files WHERE project_id = ? AND filepath = ?");
                $stmt->execute([$proj['id'], $rel]);
                $any_existing = $stmt->fetch();
                if ($any_existing && $any_existing['is_deleted']) {
                    resurrect_file($any_existing['id'], $ftype, 'final');
                    $file_id = $any_existing['id'];
                } elseif (!$existing) {
                    $file_id = create_file($proj['id'], $rel, $ftype, 'final');
                } else {
                    continue;
                }

                if ($ftype === 'text') {
                    $content = file_get_contents($item->getPathname());
                    create_revision($file_id, $content, null, 'Imported from production', auth_current_username());
                } else {
                    $binary = file_get_contents($item->getPathname());
                    create_revision($file_id, null, $binary, 'Imported from production', auth_current_username());
                }
                $count++;
            }
            $msg = "Imported/updated $count file(s) from production.";
            if ($skipped) $msg .= " $skipped unchanged file(s) skipped.";
            flash($msg);
            redirect("?p=" . e($project_slug));
        }
        flash("Import failed — import source path not set or not accessible.", 'err');
        redirect("?p=" . e($project_slug));
    }

    // --- Create new project ---
    if ($action === 'create_project') {
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $deploy_path = trim($_POST['deploy_path'] ?? '') ?: null;

        if ($name === '' || $slug === '') {
            flash("Project name and slug are required.", 'err');
            redirect("?page=create_project");
        }
        $slug = slugify($slug);
        if ($slug === '') {
            flash("Slug must contain at least one alphanumeric character.", 'err');
            redirect("?page=create_project");
        }
        if (project_slug_exists($slug)) {
            flash("A project with slug '$slug' already exists.", 'err');
            redirect("?page=create_project");
        }
        if ($deploy_path && !is_dir($deploy_path)) {
            flash("Import source path does not exist on the server: $deploy_path", 'err');
            redirect("?page=create_project");
        }
        create_project($name, $slug, $description, $deploy_path);
        flash("Project '$name' created.");
        redirect("?p=" . e($slug));
    }

    // --- Lore entry actions (if feature enabled) ---
    if (feature_enabled('lore_bible')) {
        if ($action === 'create_lore') {
            $name = trim($_POST['name'] ?? '');
            $category = trim($_POST['category'] ?? 'character');
            $content = $_POST['content'] ?? '';
            $content = str_replace("\r\n", "\n", $content);
            $lore_title = trim($_POST['title'] ?? '') ?: null;
            $faction = trim($_POST['faction'] ?? '') ?: null;
            $aliases = trim($_POST['aliases'] ?? '') ?: null;
            if ($name === '') {
                flash("Entry name is required.", 'err');
                redirect("?page=lore_create");
            }
            $slug = slugify($name);
            if ($slug === '') $slug = 'entry-' . time();
            // Ensure unique slug
            $base_slug = $slug;
            $n = 1;
            while (true) {
                $stmt = db()->prepare("SELECT id FROM lore_entries WHERE slug = ?");
                $stmt->execute([$slug]);
                if (!$stmt->fetch()) break;
                $slug = $base_slug . '-' . (++$n);
            }
            $stmt = db()->prepare("INSERT INTO lore_entries (name, slug, title, category, faction, aliases, content, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $slug, $lore_title, $category, $faction, $aliases, $content, auth_current_username()]);
            flash("Lore entry '$name' created.");
            redirect("?page=lore_view&entry=" . urlencode($slug));
        }

        if ($action === 'edit_lore') {
            $entry_slug = $_POST['entry_slug'] ?? '';
            $name = trim($_POST['name'] ?? '');
            $category = trim($_POST['category'] ?? 'character');
            $content = $_POST['content'] ?? '';
            $content = str_replace("\r\n", "\n", $content);
            $lore_title = trim($_POST['title'] ?? '') ?: null;
            $faction = trim($_POST['faction'] ?? '') ?: null;
            $aliases = trim($_POST['aliases'] ?? '') ?: null;
            if ($name === '' || $entry_slug === '') {
                flash("Entry name is required.", 'err');
                redirect("?page=lore");
            }
            $stmt = db()->prepare("UPDATE lore_entries SET name = ?, title = ?, category = ?, faction = ?, aliases = ?, content = ? WHERE slug = ?");
            $stmt->execute([$name, $lore_title, $category, $faction, $aliases, $content, $entry_slug]);
            flash("Lore entry '$name' updated.");
            redirect("?page=lore_view&entry=" . urlencode($entry_slug));
        }

        if ($action === 'delete_lore') {
            $entry_slug = $_POST['entry_slug'] ?? '';
            if ($entry_slug) {
                $stmt = db()->prepare("SELECT name FROM lore_entries WHERE slug = ?");
                $stmt->execute([$entry_slug]);
                $entry = $stmt->fetch();
                if ($entry) {
                    db()->prepare("DELETE FROM lore_entries WHERE slug = ?")->execute([$entry_slug]);
                    flash("Deleted lore entry: " . $entry['name']);
                }
            }
            redirect("?page=lore");
        }
    }

    exit;
}

// Download handlers (GET-based, exit early)
if ($page === 'download' && $project_slug && $filepath) {
    $proj = get_project($project_slug);
    $file = $proj ? get_file($proj['id'], $filepath) : null;
    if ($file) {
        $rev_id = isset($_GET['rev']) ? (int)$_GET['rev'] : null;
        $rev = $rev_id ? get_revision($rev_id) : get_head_revision($file['id']);
        if ($rev) {
            $filename = basename($filepath);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $mime_map = [
                'html' => 'text/html', 'htm' => 'text/html', 'css' => 'text/css',
                'js' => 'application/javascript', 'json' => 'application/json',
                'md' => 'text/markdown', 'txt' => 'text/plain', 'php' => 'text/plain',
                'eml' => 'message/rfc822',
                'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
                'ico' => 'image/x-icon',
                'pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'zip' => 'application/zip',
            ];
            $mime = $mime_map[$ext] ?? 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            if ($file['filetype'] === 'text') {
                header('Content-Length: ' . strlen($rev['content'] ?? ''));
                echo $rev['content'];
            } else {
                header('Content-Length: ' . strlen($rev['binary_content'] ?? ''));
                echo $rev['binary_content'];
            }
            exit;
        }
    }
    http_response_code(404);
    echo "File not found.";
    exit;
}

if ($page === 'download_zip' && $project_slug) {
    $proj = get_project($project_slug);
    if ($proj) {
        $stmt = db()->prepare("SELECT f.* FROM files f WHERE f.project_id = ? AND f.is_deleted = 0 ORDER BY f.filepath");
        $stmt->execute([$proj['id']]);
        $files = $stmt->fetchAll();

        $tmp = tempnam(sys_get_temp_dir(), 'evcs_');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) === true) {
            foreach ($files as $f) {
                $head = get_head_revision($f['id']);
                if (!$head) continue;
                $content = $f['filetype'] === 'text' ? ($head['content'] ?? '') : ($head['binary_content'] ?? '');
                $zip->addFromString($f['filepath'], $content);
            }
            $zip->close();

            $zip_name = $proj['slug'] . '_' . date('Y-m-d') . '.zip';
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zip_name . '"');
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp);
            unlink($tmp);
            exit;
        }
    }
    http_response_code(404);
    echo "Project not found.";
    exit;
}

// Lore export (if feature enabled)
if ($page === 'lore_export' && feature_enabled('lore_bible')) {
    $cat_filter = $_GET['cat'] ?? '';
    $where = '';
    $params = [];
    if ($cat_filter) {
        $where = ' WHERE category = ?';
        $params = [$cat_filter];
    }
    $entries = db()->prepare("SELECT * FROM lore_entries{$where} ORDER BY category, name");
    $entries->execute($params);
    $entries = $entries->fetchAll();

    $app_title = app_title();
    $md = "# $app_title — Lore Bible\n";
    $md .= "Exported " . date('Y-m-d H:i:s') . " by " . auth_current_username() . "\n\n";
    if ($cat_filter) {
        $md .= "**Filtered:** {$cat_filter}\n\n";
    }
    $md .= "---\n\n";

    $current_cat = '';
    foreach ($entries as $entry) {
        if ($entry['category'] !== $current_cat) {
            $current_cat = $entry['category'];
            $md .= "## " . ucfirst($current_cat) . "\n\n";
        }
        $md .= "### " . $entry['name'] . "\n\n";
        if ($entry['title']) $md .= "**Role:** " . $entry['title'] . "\n";
        if ($entry['faction']) $md .= "**Faction:** " . $entry['faction'] . "\n";
        if ($entry['aliases']) $md .= "**Aliases:** " . $entry['aliases'] . "\n";
        if ($entry['title'] || $entry['faction'] || $entry['aliases']) $md .= "\n";
        $md .= $entry['content'] . "\n\n";
        $md .= "---\n\n";
    }

    $filename = 'lore-bible' . ($cat_filter ? '-' . $cat_filter : '') . '_' . date('Y-m-d') . '.md';
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($md));
    echo $md;
    exit;
}

// View data loading
$project = $project_slug ? get_project($project_slug) : null;
$flash = get_flash();
$current_user = auth_current_user();
$app_title = app_title();

// Require login for all pages except login
if (!$current_user && $page !== 'login') {
    redirect('?page=login');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($app_title) ?><?= $project ? ' — ' . e($project['name']) : '' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/monokai.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/diff2html/bundles/css/diff2html.min.css">
<style>
:root {
    --bg: #0a0a0c;
    --bg-card: #111115;
    --bg-hover: #1a1a20;
    --bg-input: #0d0d10;
    --border: #2a2a32;
    --border-light: #3a3a44;
    --text: #c8c8d0;
    --text-dim: #6a6a78;
    --text-bright: #e8e8f0;
    --accent: #4488cc;
    --accent-dim: rgba(68, 136, 204, 0.15);
    --green: #3a9a5a;
    --green-dim: rgba(58, 154, 90, 0.15);
    --yellow: #c8a832;
    --yellow-dim: rgba(200, 168, 50, 0.15);
    --blue: #4488cc;
    --blue-dim: rgba(68, 136, 204, 0.15);
    --orange: #d48a3c;
    --orange-dim: rgba(212, 138, 60, 0.15);
    --red: #d4443c;
    --red-dim: rgba(212, 68, 60, 0.15);
    --mono: 'IBM Plex Mono', 'Consolas', monospace;
    --sans: 'IBM Plex Sans', 'Segoe UI', sans-serif;
    --radius: 4px;
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: var(--sans);
    background: var(--bg);
    color: var(--text);
    font-size: 14px;
    line-height: 1.6;
    min-height: 100vh;
}
a { color: var(--blue); text-decoration: none; }
a:hover { text-decoration: underline; }

.topbar {
    background: var(--bg-card);
    border-bottom: 1px solid var(--border);
    padding: 12px 24px;
    display: flex;
    align-items: center;
    gap: 16px;
    position: sticky;
    top: 0;
    z-index: 100;
}
.topbar-title {
    font-family: var(--mono);
    font-weight: 600;
    font-size: 15px;
    color: var(--accent);
    letter-spacing: 2px;
    text-transform: uppercase;
}
.topbar-sep { color: var(--border); }
.topbar-crumb { color: var(--text-dim); font-size: 13px; }
.topbar-crumb a { color: var(--text); }
.topbar-user {
    margin-left: auto;
    font-family: var(--mono);
    font-size: 12px;
    color: var(--text-dim);
    display: flex;
    align-items: center;
    gap: 12px;
}

.container { max-width: 1200px; margin: 0 auto; padding: 24px; }

.flash {
    padding: 10px 16px;
    border-radius: var(--radius);
    margin-bottom: 20px;
    font-size: 13px;
    font-family: var(--mono);
}
.flash-ok { background: var(--green-dim); border: 1px solid var(--green); color: var(--green); }
.flash-err { background: var(--red-dim); border: 1px solid var(--red); color: var(--red); }

.card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 16px;
}
.card:hover { border-color: var(--border-light); }
.card-title {
    font-family: var(--mono);
    font-size: 15px;
    font-weight: 600;
    color: var(--text-bright);
    margin-bottom: 6px;
}
.card-meta {
    font-size: 12px;
    color: var(--text-dim);
    font-family: var(--mono);
}

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.grid-4 { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }

.data-table { width: 100%; border-collapse: collapse; }
.data-table th {
    text-align: left;
    font-family: var(--mono);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--text-dim);
    padding: 8px 12px;
    border-bottom: 1px solid var(--border);
}
.data-table td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
    vertical-align: middle;
}
.data-table tr:hover td { background: var(--bg-hover); }
.data-table .filepath { font-family: var(--mono); font-size: 13px; }
.data-table .icon { width: 28px; text-align: center; font-size: 14px; color: var(--text-dim); }

.btn {
    display: inline-block;
    font-family: var(--mono);
    font-size: 12px;
    font-weight: 500;
    padding: 6px 14px;
    border-radius: var(--radius);
    border: 1px solid var(--border);
    background: var(--bg-card);
    color: var(--text);
    cursor: pointer;
    transition: all 0.15s;
    text-decoration: none;
}
.btn:hover { border-color: var(--text-dim); background: var(--bg-hover); text-decoration: none; }
.btn-accent { border-color: var(--accent); color: var(--accent); }
.btn-accent:hover { background: var(--accent-dim); }
.btn-green { border-color: var(--green); color: var(--green); }
.btn-green:hover { background: var(--green-dim); }
.btn-sm { padding: 3px 10px; font-size: 11px; }
.btn-group { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

.form-group { margin-bottom: 16px; }
.form-label {
    display: block;
    font-family: var(--mono);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--text-dim);
    margin-bottom: 6px;
}
.form-input, .form-textarea, .form-select {
    width: 100%;
    font-family: var(--mono);
    font-size: 13px;
    padding: 8px 12px;
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text);
    outline: none;
}
.form-input:focus, .form-textarea:focus, .form-select:focus { border-color: var(--accent); }
.form-textarea { min-height: 200px; resize: vertical; }
.form-select { appearance: auto; }
.form-inline { display: inline-block; width: auto; }

.rev-timeline { position: relative; padding-left: 24px; }
.rev-timeline::before {
    content: '';
    position: absolute;
    left: 8px;
    top: 0;
    bottom: 0;
    width: 1px;
    background: var(--border);
}
.rev-item {
    position: relative;
    padding: 12px 16px;
    margin-bottom: 4px;
    border-radius: var(--radius);
    cursor: default;
}
.rev-item:hover { background: var(--bg-hover); }
.rev-item::before {
    content: '';
    position: absolute;
    left: -20px;
    top: 18px;
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: var(--border-light);
    border: 2px solid var(--bg);
}
.rev-item.head::before { background: var(--green); }
.rev-item.selected { background: var(--blue-dim); border: 1px solid var(--blue); }
.rev-msg { font-size: 13px; color: var(--text-bright); margin-bottom: 4px; }
.rev-meta { font-family: var(--mono); font-size: 11px; color: var(--text-dim); }
.rev-actions { float: right; display: flex; gap: 6px; }

.diff-container {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
}
.d2h-wrapper { background: var(--bg-card) !important; }
.d2h-file-header { background: var(--bg-hover) !important; border-color: var(--border) !important; }
.d2h-file-name { color: var(--text-bright) !important; }
.d2h-code-line { font-family: var(--mono) !important; font-size: 12px !important; }
.d2h-code-linenumber { background: var(--bg) !important; color: var(--text-dim) !important; border-color: var(--border) !important; }
.d2h-del { background: rgba(212, 68, 60, 0.1) !important; }
.d2h-ins { background: rgba(58, 154, 90, 0.1) !important; }
.d2h-info { background: var(--bg-hover) !important; color: var(--text-dim) !important; }
.d2h-emptyplaceholder { background: var(--bg) !important; border-color: var(--border) !important; }
del { background: rgba(212, 68, 60, 0.25) !important; text-decoration: none !important; }
ins { background: rgba(58, 154, 90, 0.25) !important; text-decoration: none !important; }

.diff-selector {
    padding: 12px 16px;
    background: var(--bg-hover);
    border-bottom: 1px solid var(--border);
    font-family: var(--mono);
    font-size: 12px;
    color: var(--text-dim);
    display: flex;
    align-items: center;
    gap: 12px;
}

.badge {
    display: inline-block;
    font-family: var(--mono);
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 2px 8px;
    border-radius: 2px;
}
.badge-text { background: var(--blue-dim); color: var(--blue); }
.badge-binary { background: var(--yellow-dim); color: var(--yellow); }
.badge-deploy { background: var(--green-dim); color: var(--green); }
.badge-no-deploy { background: var(--bg-hover); color: var(--text-dim); }
.badge-draft { background: var(--orange-dim); color: var(--orange); }
.badge-review { background: var(--yellow-dim); color: var(--yellow); }
.badge-final { background: var(--green-dim); color: var(--green); }

.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border);
}
.section-title {
    font-family: var(--mono);
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 2px;
    color: var(--text-dim);
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-dim);
}
.empty-state p { margin-bottom: 16px; }

.CodeMirror {
    height: 70vh;
    font-size: 13px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
}

.rev-check {
    appearance: none;
    width: 14px;
    height: 14px;
    border: 1px solid var(--border-light);
    border-radius: 2px;
    background: var(--bg);
    cursor: pointer;
    vertical-align: middle;
    margin-right: 4px;
}
.rev-check:checked { background: var(--blue); border-color: var(--blue); }

.filter-bar {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-bottom: 16px;
    font-family: var(--mono);
    font-size: 12px;
    color: var(--text-dim);
}
.filter-bar a {
    padding: 4px 10px;
    border-radius: var(--radius);
    color: var(--text-dim);
    text-decoration: none;
    border: 1px solid transparent;
}
.filter-bar a:hover { border-color: var(--border); color: var(--text); }
.filter-bar a.active { border-color: var(--accent); color: var(--accent); background: var(--accent-dim); }

.login-container {
    max-width: 360px;
    margin: 100px auto;
}
.login-title {
    text-align: center;
    font-family: var(--mono);
    font-size: 18px;
    font-weight: 600;
    color: var(--accent);
    letter-spacing: 2px;
    text-transform: uppercase;
    margin-bottom: 24px;
}

@media (max-width: 768px) {
    .grid-2 { grid-template-columns: 1fr; }
    .container { padding: 16px; }
}
</style>
</head>
<body>

<?php if ($page === 'login'): ?>
<!-- Login page -->
<div class="container">
    <div class="login-container">
        <div class="login-title"><?= e($app_title) ?></div>
        <?php if ($flash): ?>
            <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
        <?php endif; ?>
        <div class="card">
            <form method="post">
                <input type="hidden" name="action" value="login">
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-input" required autocomplete="username">
                </div>
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-input" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-accent" style="width:100%">Sign In</button>
            </form>
        </div>
    </div>
</div>
<?php else: ?>

<!-- Top Bar -->
<div class="topbar">
    <a href="?" class="topbar-title" style="text-decoration:none"><?= e($app_title) ?></a>
    <?php if ($project): ?>
        <span class="topbar-sep">&#9656;</span>
        <span class="topbar-crumb">
            <a href="?p=<?= e($project['slug']) ?>"><?= e($project['name']) ?></a>
            <?php if ($filepath): ?>
                <span class="topbar-sep">&#9656;</span>
                <?= e($filepath) ?>
            <?php endif; ?>
        </span>
    <?php endif; ?>
    <span class="topbar-user">
        <a href="?page=sitemap" style="color:var(--text-dim); text-decoration:none">Sitemap</a>
        <?php if (feature_enabled('lore_bible')): ?>
            <a href="?page=lore" style="color:var(--text-dim); text-decoration:none">Lore Bible</a>
        <?php endif; ?>
        <?php if (feature_enabled('relationship_graph')): ?>
            <a href="graph.php" style="color:var(--text-dim); text-decoration:none">Entity Graph</a>
        <?php endif; ?>
        <span><?= e($current_user['username'] ?? 'anonymous') ?></span>
        <form method="post" style="display:inline">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="btn btn-sm" style="padding:2px 8px">Logout</button>
        </form>
    </span>
</div>

<div class="container">

<?php if ($flash): ?>
    <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php
// ============================================================
// DASHBOARD
// ============================================================
if (!$project && $page === 'dashboard'):
    $projects = db()->query("
        SELECT p.*,
            (SELECT COUNT(*) FROM files f WHERE f.project_id = p.id AND f.is_deleted = 0) AS file_count,
            (SELECT COUNT(*) FROM files f WHERE f.project_id = p.id AND f.is_deleted = 0 AND f.status = 'draft') AS draft_count,
            (SELECT COUNT(*) FROM files f WHERE f.project_id = p.id AND f.is_deleted = 0 AND f.status = 'review') AS review_count,
            (SELECT COUNT(*) FROM revisions r JOIN files f ON r.file_id = f.id WHERE f.project_id = p.id) AS rev_count,
            (SELECT MAX(r.created_at) FROM revisions r JOIN files f ON r.file_id = f.id WHERE f.project_id = p.id) AS last_activity
        FROM projects p ORDER BY p.name
    ")->fetchAll();
?>

<div class="section-header">
    <span class="section-title">Projects</span>
    <a href="?page=create_project" class="btn btn-sm btn-accent">New Project</a>
</div>

<div class="grid-4">
<?php foreach ($projects as $p): ?>
    <a href="?p=<?= e($p['slug']) ?>" class="card" style="text-decoration:none; display:block">
        <div class="card-title"><?= e($p['name']) ?></div>
        <div class="card-meta" style="margin-bottom:8px"><?= e($p['description'] ?? '') ?></div>
        <div style="display:flex; gap:12px; margin-top:12px">
            <span class="card-meta"><?= $p['file_count'] ?> files</span>
            <span class="card-meta"><?= $p['rev_count'] ?> revisions</span>
        </div>
        <?php if ($p['draft_count'] || $p['review_count']): ?>
        <div style="display:flex; gap:8px; margin-top:8px">
            <?php if ($p['draft_count']): ?><span class="badge badge-draft"><?= $p['draft_count'] ?> draft</span><?php endif; ?>
            <?php if ($p['review_count']): ?><span class="badge badge-review"><?= $p['review_count'] ?> review</span><?php endif; ?>
        </div>
        <?php endif; ?>
        <div style="margin-top:8px">
            <?php if ($p['deploy_path']): ?>
                <span class="badge badge-deploy">has deploy path</span>
            <?php else: ?>
                <span class="badge badge-no-deploy">no deploy</span>
            <?php endif; ?>
        </div>
        <?php if ($p['last_activity']): ?>
            <div class="card-meta" style="margin-top:8px">Last activity: <?= time_ago($p['last_activity']) ?></div>
        <?php endif; ?>
    </a>
<?php endforeach; ?>
</div>

<?php if (empty($projects)): ?>
<div class="empty-state">
    <p>No projects yet.</p>
    <a href="?page=create_project" class="btn btn-accent">Create First Project</a>
</div>
<?php endif; ?>

<?php
// ============================================================
// CREATE PROJECT
// ============================================================
elseif (!$project && $page === 'create_project'):
?>
<div class="section-header">
    <span class="section-title">Create New Project</span>
    <a href="?" class="btn btn-sm">Back</a>
</div>

<div class="card">
    <form method="post">
        <input type="hidden" name="action" value="create_project">
        <div class="form-group">
            <label class="form-label">Project Name</label>
            <input type="text" name="name" class="form-input" required placeholder="My Project" id="proj-name">
        </div>
        <div class="form-group">
            <label class="form-label">Slug (URL identifier)</label>
            <input type="text" name="slug" class="form-input" required placeholder="my-project" id="proj-slug" pattern="[a-z0-9\-]+">
        </div>
        <div class="form-group">
            <label class="form-label">Description</label>
            <input type="text" name="description" class="form-input" placeholder="Brief description">
        </div>
        <div class="form-group">
            <label class="form-label">Deploy Path (optional)</label>
            <input type="text" name="deploy_path" class="form-input" placeholder="/var/www/mysite">
        </div>
        <button type="submit" class="btn btn-accent">Create Project</button>
    </form>
</div>
<script>
(function() {
    var name = document.getElementById('proj-name');
    var slug = document.getElementById('proj-slug');
    var edited = false;
    slug.addEventListener('input', function() { edited = true; });
    name.addEventListener('input', function() {
        if (!edited) {
            slug.value = name.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        }
    });
})();
</script>

<?php
// ============================================================
// SITEMAP
// ============================================================
elseif (!$project && $page === 'sitemap'):
    $all_projects = get_all_projects();
?>

<div class="section-header">
    <span class="section-title">Sitemap</span>
    <a href="?" class="btn btn-sm">Dashboard</a>
</div>

<?php foreach ($all_projects as $sp): ?>
    <?php
        $sm_files = db()->prepare("
            SELECT f.filepath, f.filetype, f.status,
                (SELECT COUNT(*) FROM revisions r WHERE r.file_id = f.id) AS rev_count,
                (SELECT r.created_at FROM revisions r WHERE r.file_id = f.id ORDER BY r.created_at DESC LIMIT 1) AS last_rev_at
            FROM files f WHERE f.project_id = ? AND f.is_deleted = 0 ORDER BY f.filepath
        ");
        $sm_files->execute([$sp['id']]);
        $sm_files = $sm_files->fetchAll();

        // Build tree structure
        $tree = [];
        foreach ($sm_files as $sf) {
            $parts = explode('/', $sf['filepath']);
            $ref = &$tree;
            foreach ($parts as $i => $part) {
                if ($i === count($parts) - 1) {
                    $ref[] = $sf;
                } else {
                    if (!isset($ref[$part])) $ref[$part] = [];
                    $ref = &$ref[$part];
                }
            }
            unset($ref);
        }
    ?>
    <div class="card" style="margin-bottom:12px">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px">
            <a href="?p=<?= e($sp['slug']) ?>" class="card-title" style="text-decoration:none"><?= e($sp['name']) ?></a>
            <span class="card-meta"><?= count($sm_files) ?> files</span>
        </div>
        <?php if (empty($sm_files)): ?>
            <div class="card-meta" style="font-style:italic">No files</div>
        <?php else: ?>
            <div style="font-family:var(--mono); font-size:12px; line-height:1.8">
            <?php
                function render_tree(array $node, string $project_slug, int $depth = 0): void {
                    $dirs = [];
                    $files = [];
                    foreach ($node as $key => $val) {
                        if (is_string($key)) {
                            $dirs[$key] = $val;
                        } else {
                            $files[] = $val;
                        }
                    }
                    ksort($dirs);
                    foreach ($dirs as $dirname => $children) {
                        $indent = str_repeat('&nbsp;&nbsp;&nbsp;', $depth);
                        echo "<div>{$indent}<span style=\"color:var(--yellow)\">&#9662;</span> <span style=\"color:var(--text-bright)\">{$dirname}/</span></div>";
                        render_tree($children, $project_slug, $depth + 1);
                    }
                    foreach ($files as $f) {
                        $indent = str_repeat('&nbsp;&nbsp;&nbsp;', $depth);
                        $icon = file_extension_icon($f['filepath']);
                        $badge = status_badge($f['status']);
                        $ago = $f['last_rev_at'] ? time_ago($f['last_rev_at']) : '';
                        $link = '?p=' . e($project_slug) . '&f=' . urlencode($f['filepath']);
                        echo "<div>{$indent}{$icon} <a href=\"{$link}\" style=\"color:var(--text)\">" . e(basename($f['filepath'])) . "</a> {$badge} <span style=\"color:var(--text-dim); margin-left:8px\">{$f['rev_count']} rev" . ($f['rev_count'] != 1 ? 's' : '') . "</span> <span style=\"color:var(--text-dim)\">{$ago}</span></div>";
                    }
                }
                render_tree($tree, $sp['slug']);
            ?>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php
// ============================================================
// PROJECT FILE BROWSER
// ============================================================
elseif ($project && !$filepath && !in_array($page, ['create','upload'])):
    $status_filter = $_GET['status'] ?? 'all';
    $where_status = '';
    if ($status_filter !== 'all' && in_array($status_filter, ['draft','review','final'])) {
        $where_status = " AND f.status = " . db()->quote($status_filter);
    }
    $files = db()->prepare("
        SELECT f.*,
            (SELECT COUNT(*) FROM revisions r WHERE r.file_id = f.id) AS rev_count,
            (SELECT r.created_at FROM revisions r WHERE r.file_id = f.id ORDER BY r.created_at DESC LIMIT 1) AS last_rev_at,
            (SELECT r.author FROM revisions r WHERE r.file_id = f.id ORDER BY r.created_at DESC LIMIT 1) AS last_author,
            (SELECT r.message FROM revisions r WHERE r.file_id = f.id ORDER BY r.created_at DESC LIMIT 1) AS last_message,
            (SELECT r.size_bytes FROM revisions r WHERE r.file_id = f.id ORDER BY r.created_at DESC LIMIT 1) AS last_size
        FROM files f
        WHERE f.project_id = ? AND f.is_deleted = 0 $where_status
        ORDER BY f.status = 'draft' DESC, f.status = 'review' DESC, f.filepath
    ");
    $files->execute([$project['id']]);
    $files = $files->fetchAll();

    $counts_stmt = db()->prepare("SELECT status, COUNT(*) AS cnt FROM files WHERE project_id = ? AND is_deleted = 0 GROUP BY status");
    $counts_stmt->execute([$project['id']]);
    $status_counts = ['all' => 0, 'draft' => 0, 'review' => 0, 'final' => 0];
    foreach ($counts_stmt->fetchAll() as $row) {
        $status_counts[$row['status']] = $row['cnt'];
        $status_counts['all'] += $row['cnt'];
    }
?>

<div class="section-header">
    <span class="section-title">Files (<?= count($files) ?>)</span>
    <div class="btn-group">
        <?php if ($project['deploy_path']): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Import all eligible files from production?')">
                <input type="hidden" name="action" value="import">
                <button type="submit" class="btn btn-sm">Import from Prod</button>
            </form>
        <?php endif; ?>
        <a href="?p=<?= e($project['slug']) ?>&page=download_zip" class="btn btn-sm">Download Zip</a>
        <a href="?p=<?= e($project['slug']) ?>&page=upload" class="btn btn-sm">Upload</a>
        <a href="?p=<?= e($project['slug']) ?>&page=create" class="btn btn-sm btn-accent">New File</a>
    </div>
</div>

<div class="filter-bar">
    <span>Show:</span>
    <?php foreach (['all','draft','review','final'] as $sf): ?>
        <a href="?p=<?= e($project['slug']) ?>&status=<?= $sf ?>" class="<?= $status_filter === $sf ? 'active' : '' ?>">
            <?= $sf ?> (<?= $status_counts[$sf] ?>)
        </a>
    <?php endforeach; ?>
</div>

<?php if (empty($files)): ?>
    <div class="empty-state">
        <p>No files<?= $status_filter !== 'all' ? " with status '$status_filter'" : '' ?> found.</p>
        <div class="btn-group" style="justify-content:center">
            <?php if ($status_filter !== 'all'): ?>
                <a href="?p=<?= e($project['slug']) ?>" class="btn">Show All</a>
            <?php endif; ?>
            <?php if ($project['deploy_path']): ?>
                <form method="post"><input type="hidden" name="action" value="import"><button type="submit" class="btn">Import from Production</button></form>
            <?php endif; ?>
            <a href="?p=<?= e($project['slug']) ?>&page=create" class="btn btn-accent">Create First File</a>
        </div>
    </div>
<?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th class="icon"></th>
                <th>File</th>
                <th>Status</th>
                <th>Last Change</th>
                <th>Author</th>
                <th>Revs</th>
                <th>Size</th>
                <th>Updated</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($files as $f): ?>
            <tr>
                <td class="icon"><?= file_extension_icon($f['filepath']) ?></td>
                <td>
                    <a href="?p=<?= e($project['slug']) ?>&f=<?= urlencode($f['filepath']) ?>" class="filepath">
                        <?= e($f['filepath']) ?>
                    </a>
                    <span class="badge <?= $f['filetype'] === 'text' ? 'badge-text' : 'badge-binary' ?>" style="margin-left:6px"><?= $f['filetype'] ?></span>
                </td>
                <td><?= status_badge($f['status']) ?></td>
                <td style="font-size:12px; color:var(--text-dim); max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap"><?= e($f['last_message'] ?? '') ?></td>
                <td class="card-meta"><?= e($f['last_author'] ?? '') ?></td>
                <td class="card-meta"><?= $f['rev_count'] ?></td>
                <td class="card-meta"><?= $f['last_size'] ? human_size($f['last_size']) : '--' ?></td>
                <td class="card-meta"><?= $f['last_rev_at'] ? time_ago($f['last_rev_at']) : '--' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php
// ============================================================
// CREATE FILE
// ============================================================
elseif ($project && $page === 'create'):
?>
<div class="section-header">
    <span class="section-title">Create New File</span>
    <a href="?p=<?= e($project['slug']) ?>" class="btn btn-sm">Back</a>
</div>

<div class="card">
    <form method="post">
        <input type="hidden" name="action" value="create">
        <div class="form-group">
            <label class="form-label">File Path (relative)</label>
            <input type="text" name="filepath" class="form-input" required placeholder="path/to/file.html">
        </div>
        <div class="grid-2">
            <div class="form-group">
                <label class="form-label">Commit Message</label>
                <input type="text" name="message" class="form-input" placeholder="Initial creation">
            </div>
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="draft" selected>Draft</option>
                    <option value="review">Review</option>
                    <option value="final">Final</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Content</label>
            <textarea name="content" class="form-textarea" id="create-editor"></textarea>
        </div>
        <button type="submit" class="btn btn-accent">Create File</button>
    </form>
</div>

<?php
// ============================================================
// UPLOAD FILES
// ============================================================
elseif ($project && $page === 'upload'):
?>
<div class="section-header">
    <span class="section-title">Upload Files</span>
    <a href="?p=<?= e($project['slug']) ?>" class="btn btn-sm">Back</a>
</div>

<div class="card">
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload">
        <div class="form-group">
            <label class="form-label">Subdirectory (optional)</label>
            <input type="text" name="subdir" class="form-input" placeholder="Leave blank for project root">
        </div>
        <div class="grid-2">
            <div class="form-group">
                <label class="form-label">Commit Message</label>
                <input type="text" name="message" class="form-input" placeholder="Upload via web UI">
            </div>
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="draft" selected>Draft</option>
                    <option value="review">Review</option>
                    <option value="final">Final</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Files</label>
            <input type="file" name="files[]" multiple class="form-input" style="padding:12px">
        </div>
        <button type="submit" class="btn btn-accent">Upload</button>
    </form>
</div>

<?php
// ============================================================
// FILE HISTORY + DIFF + EDIT
// ============================================================
elseif ($project && $filepath):
    $file = get_file($project['id'], $filepath);
    if (!$file) {
        echo '<div class="flash flash-err">File not found.</div>';
        echo '<a href="?p=' . e($project['slug']) . '" class="btn">Back to project</a>';
    } else {
        $revisions = db()->prepare("SELECT * FROM revisions WHERE file_id = ? ORDER BY created_at DESC");
        $revisions->execute([$file['id']]);
        $revisions = $revisions->fetchAll();
        $head = $revisions[0] ?? null;
        $is_editing = ($page === 'edit');
        $is_diffing = ($page === 'diff');
        $view_rev = isset($_GET['rev']) ? (int)$_GET['rev'] : null;
        $diff_a = isset($_GET['a']) ? (int)$_GET['a'] : null;
        $diff_b = isset($_GET['b']) ? (int)$_GET['b'] : null;

        $all_projects = get_all_projects();
?>

<div class="section-header">
    <span class="section-title">
        <?= file_extension_icon($filepath) ?> <?= e($filepath) ?>
        <span class="badge <?= $file['filetype'] === 'text' ? 'badge-text' : 'badge-binary' ?>" style="margin-left:6px"><?= $file['filetype'] ?></span>
        <?= status_badge($file['status']) ?>
    </span>
    <div class="btn-group">
        <a href="?p=<?= e($project['slug']) ?>&f=<?= urlencode($filepath) ?>&page=download" class="btn btn-sm">Download</a>
        <?php if ($file['filetype'] === 'text'): ?>
            <a href="?p=<?= e($project['slug']) ?>&f=<?= urlencode($filepath) ?>&page=edit" class="btn btn-sm">Edit</a>
        <?php endif; ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Soft-delete this file?')">
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn btn-sm" style="color:var(--text-dim)">Delete</button>
        </form>
        <a href="?p=<?= e($project['slug']) ?>" class="btn btn-sm">Back</a>
    </div>
</div>

<!-- Status + Promote controls -->
<div style="display:flex; gap:16px; align-items:flex-start; margin-bottom:16px; flex-wrap:wrap">
    <div style="display:flex; align-items:center; gap:8px">
        <span class="form-label" style="margin:0; white-space:nowrap">Status:</span>
        <form method="post" style="display:flex; gap:4px; align-items:center">
            <input type="hidden" name="action" value="set_status">
            <?php foreach (['draft','review','final'] as $st): ?>
                <button type="submit" name="new_status" value="<?= $st ?>"
                    class="btn btn-sm<?= $file['status'] === $st ? ' btn-accent' : '' ?>"
                    <?= $file['status'] === $st ? 'disabled' : '' ?>><?= $st ?></button>
            <?php endforeach; ?>
        </form>
    </div>

    <?php if (count($all_projects) > 1): ?>
    <div style="display:flex; align-items:center; gap:8px">
        <span class="form-label" style="margin:0; white-space:nowrap">Promote to:</span>
        <form method="post" style="display:flex; gap:4px; align-items:center" onsubmit="return confirm('Copy head revision to selected project?')">
            <input type="hidden" name="action" value="promote">
            <input type="hidden" name="dest_path" value="<?= e($filepath) ?>">
            <select name="dest_project" class="form-select form-inline" style="width:180px; padding:3px 8px; font-size:11px">
                <?php foreach ($all_projects as $ap): ?>
                    <?php if ($ap['id'] !== $project['id']): ?>
                        <option value="<?= e($ap['slug']) ?>"><?= e($ap['name']) ?></option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm">Promote</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php
// ---- EDIT MODE ----
if ($is_editing && $file['filetype'] === 'text' && $head):
?>
<div class="card">
    <form method="post">
        <input type="hidden" name="action" value="save">
        <div class="form-group">
            <label class="form-label">Commit Message</label>
            <input type="text" name="message" class="form-input" placeholder="Describe your changes">
        </div>
        <div class="form-group">
            <label class="form-label">Content</label>
            <textarea name="content" id="edit-content" style="display:none"><?= e($head['content'] ?? '') ?></textarea>
            <div id="editor-mount"></div>
        </div>
        <div class="btn-group">
            <button type="submit" class="btn btn-accent">Save Revision</button>
            <a href="?p=<?= e($project['slug']) ?>&f=<?= urlencode($filepath) ?>" class="btn">Cancel</a>
        </div>
    </form>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/css/css.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/markdown/markdown.min.js"></script>
<script>
(function() {
    var ta = document.getElementById('edit-content');
    var mount = document.getElementById('editor-mount');
    var ext = <?= json_encode(pathinfo($filepath, PATHINFO_EXTENSION)) ?>;
    var modeMap = {html:'htmlmixed',htm:'htmlmixed',md:'markdown',css:'css',js:'javascript',json:'javascript'};
    var cm = CodeMirror(mount, {
        value: ta.value,
        mode: modeMap[ext] || 'htmlmixed',
        theme: 'monokai',
        lineNumbers: true,
        lineWrapping: true,
        indentUnit: 2,
        tabSize: 2,
        indentWithTabs: false
    });
    cm.on('change', function() { ta.value = cm.getValue(); });
    cm.getWrapperElement().closest('form').addEventListener('submit', function() {
        ta.value = cm.getValue();
    });
})();
</script>

<?php
// ---- VIEW SPECIFIC REVISION ----
elseif ($view_rev):
    $rev = get_revision($view_rev);
    if ($rev && $file['filetype'] === 'text'):
?>
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px">
        <div>
            <span class="card-title">Revision #<?= $rev['id'] ?></span>
            <span class="card-meta" style="margin-left:12px"><?= e($rev['message']) ?></span>
        </div>
        <a href="?p=<?= e($project['slug']) ?>&f=<?= urlencode($filepath) ?>" class="btn btn-sm">Back to History</a>
    </div>
    <pre style="background:var(--bg); border:1px solid var(--border); border-radius:var(--radius); padding:16px; overflow-x:auto; font-family:var(--mono); font-size:12px; line-height:1.5; max-height:70vh; overflow-y:auto; white-space:pre-wrap"><?= e($rev['content']) ?></pre>
</div>
<?php
    endif;

// ---- DIFF MODE ----
elseif ($diff_a && $diff_b):
    $rev_a = get_revision($diff_a);
    $rev_b = get_revision($diff_b);
    if ($rev_a && $rev_b):
?>
<div class="diff-container">
    <div class="diff-selector">
        <span>Comparing rev #<?= $rev_a['id'] ?> (<?= time_ago($rev_a['created_at']) ?>)</span>
        <span style="color:var(--accent)">&#8594;</span>
        <span>rev #<?= $rev_b['id'] ?> (<?= time_ago($rev_b['created_at']) ?>)</span>
        <a href="?p=<?= e($project['slug']) ?>&f=<?= urlencode($filepath) ?>" class="btn btn-sm" style="margin-left:auto">Back to History</a>
    </div>
    <div id="diff-output"></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/diff@5.1.0/dist/diff.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/diff2html/bundles/js/diff2html.min.js"></script>
<script>
(function() {
    var oldText = <?= json_encode($rev_a['content'] ?? '') ?>;
    var newText = <?= json_encode($rev_b['content'] ?? '') ?>;
    var filename = <?= json_encode($filepath) ?>;
    var diff = Diff.createTwoFilesPatch(
        filename + ' (rev #<?= $rev_a['id'] ?>)',
        filename + ' (rev #<?= $rev_b['id'] ?>)',
        oldText, newText, '', '', {context: 5}
    );
    var html = Diff2Html.html(diff, {
        drawFileList: false,
        matching: 'lines',
        outputFormat: 'side-by-side'
    });
    document.getElementById('diff-output').innerHTML = html;
})();
</script>
<?php
    endif;

// ---- DEFAULT: REVISION HISTORY ----
else:
?>

<?php if (count($revisions) >= 2): ?>
<div class="card" style="padding:12px 16px; margin-bottom:16px">
    <form id="diff-form" style="display:flex; align-items:center; gap:12px; font-family:var(--mono); font-size:12px; color:var(--text-dim)">
        <span>Select two revisions below to compare, then:</span>
        <button type="submit" class="btn btn-sm" id="diff-btn" disabled>View Diff</button>
    </form>
</div>
<?php endif; ?>

<div class="rev-timeline">
<?php foreach ($revisions as $i => $rev): ?>
    <div class="rev-item <?= $i === 0 ? 'head' : '' ?>" data-rev-id="<?= $rev['id'] ?>">
        <div class="rev-actions">
            <?php if (count($revisions) >= 2): ?>
                <input type="checkbox" class="rev-check" value="<?= $rev['id'] ?>">
            <?php endif; ?>
            <a href="?p=<?= e($project['slug']) ?>&f=<?= urlencode($filepath) ?>&rev=<?= $rev['id'] ?>" class="btn btn-sm">View</a>
        </div>
        <div class="rev-msg"><?= e($rev['message'] ?: '(no message)') ?> <?= $i === 0 ? '<span class="badge badge-deploy">HEAD</span>' : '' ?></div>
        <div class="rev-meta">
            Rev #<?= $rev['id'] ?>
            &middot; <?= e($rev['author']) ?>
            &middot; <?= human_size($rev['size_bytes']) ?>
            &middot; <?= e($rev['created_at']) ?> (<?= time_ago($rev['created_at']) ?>)
        </div>
    </div>
<?php endforeach; ?>
</div>

<?php if (count($revisions) >= 2): ?>
<script>
(function() {
    var checks = document.querySelectorAll('.rev-check');
    var btn = document.getElementById('diff-btn');
    var form = document.getElementById('diff-form');
    function getSelected() {
        return Array.from(checks).filter(function(c) { return c.checked; }).map(function(c) { return c.value; });
    }
    checks.forEach(function(c) {
        c.addEventListener('change', function() {
            var sel = getSelected();
            if (sel.length > 2) {
                checks.forEach(function(cb) {
                    if (cb !== c && cb.checked && sel.indexOf(cb.value) < 2) {
                        cb.checked = false;
                    }
                });
            }
            sel = getSelected();
            btn.disabled = sel.length !== 2;
        });
    });
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var sel = getSelected().sort(function(a,b) { return a - b; });
        if (sel.length === 2) {
            window.location = '?p=<?= e($project['slug']) ?>&f=<?= urlencode($filepath) ?>&page=diff&a=' + sel[0] + '&b=' + sel[1];
        }
    });
})();
</script>
<?php endif; ?>

<?php
    endif;
    }

// ============================================================
// LORE BIBLE PAGES (if feature enabled)
// ============================================================
elseif (!$project && $page === 'lore' && feature_enabled('lore_bible')):
    $cat_filter = $_GET['cat'] ?? '';
    $where = '';
    $params = [];
    if ($cat_filter) {
        $where = ' WHERE category = ?';
        $params = [$cat_filter];
    }
    $entries = db()->prepare("SELECT * FROM lore_entries{$where} ORDER BY category, name");
    $entries->execute($params);
    $entries = $entries->fetchAll();

    $categories = db()->query("SELECT category, COUNT(*) AS cnt FROM lore_entries GROUP BY category ORDER BY category")->fetchAll();
    $total = array_sum(array_column($categories, 'cnt'));
?>

<div class="section-header">
    <span class="section-title">Lore Bible (<?= count($entries) ?> entries)</span>
    <div class="btn-group">
        <a href="?page=lore_export<?= $cat_filter ? '&cat=' . urlencode($cat_filter) : '' ?>" class="btn btn-sm">Export Markdown</a>
        <a href="?page=lore_create" class="btn btn-sm btn-accent">New Entry</a>
    </div>
</div>

<div class="filter-bar">
    <span>Category:</span>
    <a href="?page=lore" class="<?= !$cat_filter ? 'active' : '' ?>">all (<?= $total ?>)</a>
    <?php foreach ($categories as $cat): ?>
        <a href="?page=lore&cat=<?= urlencode($cat['category']) ?>" class="<?= $cat_filter === $cat['category'] ? 'active' : '' ?>">
            <?= e($cat['category']) ?> (<?= $cat['cnt'] ?>)
        </a>
    <?php endforeach; ?>
</div>

<?php if (empty($entries)): ?>
    <div class="empty-state">
        <p>No lore entries<?= $cat_filter ? " in category '$cat_filter'" : '' ?> found.</p>
        <a href="?page=lore_create" class="btn btn-accent">Create First Entry</a>
    </div>
<?php else: ?>
    <?php
        $grouped = [];
        foreach ($entries as $entry) {
            $grouped[$entry['category']][] = $entry;
        }
    ?>
    <?php foreach ($grouped as $cat_name => $cat_entries): ?>
        <div style="margin-bottom:24px">
            <div style="font-family:var(--mono); font-size:11px; text-transform:uppercase; letter-spacing:2px; color:var(--accent); margin-bottom:10px; padding-bottom:6px; border-bottom:1px solid var(--border)">
                <?= e($cat_name) ?> (<?= count($cat_entries) ?>)
            </div>
            <?php foreach ($cat_entries as $entry): ?>
                <a href="?page=lore_view&entry=<?= urlencode($entry['slug']) ?>" class="card" style="display:block; text-decoration:none; padding:14px 18px; margin-bottom:6px">
                    <div style="display:flex; justify-content:space-between; align-items:center">
                        <div>
                            <span class="card-title" style="margin-bottom:0"><?= e($entry['name']) ?></span>
                            <?php if ($entry['faction']): ?>
                                <span class="badge badge-deploy" style="margin-left:8px"><?= e($entry['faction']) ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="card-meta"><?= time_ago($entry['updated_at']) ?></span>
                    </div>
                    <?php if ($entry['title']): ?>
                        <div style="font-size:12px; color:var(--text); margin-top:4px"><?= e($entry['title']) ?></div>
                    <?php endif; ?>
                    <?php
                        $preview = trim($entry['content'] ?? '');
                        if (strlen($preview) > 200) $preview = substr($preview, 0, 200) . '...';
                    ?>
                    <?php if ($preview): ?>
                        <div style="font-size:12px; color:var(--text-dim); margin-top:6px; line-height:1.5"><?= e($preview) ?></div>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php
elseif (!$project && $page === 'lore_create' && feature_enabled('lore_bible')):
    $default_categories = ['character','entity','term','canon','location','organization','technology','event'];
    $existing_cats = db()->query("SELECT DISTINCT category FROM lore_entries ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
    $all_cats = array_unique(array_merge($default_categories, $existing_cats));
    sort($all_cats);
?>

<div class="section-header">
    <span class="section-title">New Lore Entry</span>
    <a href="?page=lore" class="btn btn-sm">Back</a>
</div>

<div class="card">
    <form method="post">
        <input type="hidden" name="action" value="create_lore">
        <div class="grid-2">
            <div class="form-group">
                <label class="form-label">Name</label>
                <input type="text" name="name" class="form-input" required placeholder="Entry name">
            </div>
            <div class="form-group">
                <label class="form-label">Category</label>
                <select name="category" class="form-select" id="lore-cat-select">
                    <?php foreach ($all_cats as $cat): ?>
                        <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="grid-2">
            <div class="form-group">
                <label class="form-label">Title / Role (optional)</label>
                <input type="text" name="title" class="form-input" placeholder="e.g. Lead Developer">
            </div>
            <div class="form-group">
                <label class="form-label">Faction (optional)</label>
                <input type="text" name="faction" class="form-input" placeholder="Group or faction">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Aliases (comma-separated)</label>
            <input type="text" name="aliases" class="form-input" placeholder="Alternate names for cross-referencing">
        </div>
        <div class="form-group">
            <label class="form-label">Content (Markdown)</label>
            <textarea name="content" class="form-textarea" style="min-height:300px; font-family:var(--mono); font-size:13px"></textarea>
        </div>
        <button type="submit" class="btn btn-accent">Create Entry</button>
    </form>
</div>

<?php
elseif (!$project && $page === 'lore_view' && feature_enabled('lore_bible')):
    $entry_slug = $_GET['entry'] ?? '';
    $stmt = db()->prepare("SELECT * FROM lore_entries WHERE slug = ?");
    $stmt->execute([$entry_slug]);
    $lore_entry = $stmt->fetch();
    $is_lore_editing = isset($_GET['edit']);

    if (!$lore_entry):
?>
    <div class="flash flash-err">Lore entry not found.</div>
    <a href="?page=lore" class="btn">Back to Lore Bible</a>
<?php else: ?>

<div class="section-header">
    <span class="section-title">
        <?= e($lore_entry['name']) ?>
        <span class="badge badge-draft" style="margin-left:8px"><?= e($lore_entry['category']) ?></span>
    </span>
    <div class="btn-group">
        <?php if (!$is_lore_editing): ?>
            <a href="?page=lore_view&entry=<?= urlencode($entry_slug) ?>&edit=1" class="btn btn-sm">Edit</a>
        <?php endif; ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this entry permanently?')">
            <input type="hidden" name="action" value="delete_lore">
            <input type="hidden" name="entry_slug" value="<?= e($entry_slug) ?>">
            <button type="submit" class="btn btn-sm" style="color:var(--text-dim)">Delete</button>
        </form>
        <a href="?page=lore" class="btn btn-sm">Back</a>
    </div>
</div>

<div class="card-meta" style="margin-bottom:16px">
    Created by <?= e($lore_entry['created_by']) ?> &middot; Updated <?= time_ago($lore_entry['updated_at']) ?>
</div>

<?php if ($is_lore_editing): ?>
    <?php
        $default_categories = ['character','entity','term','canon','location','organization','technology','event'];
        $existing_cats = db()->query("SELECT DISTINCT category FROM lore_entries ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
        $all_cats = array_unique(array_merge($default_categories, $existing_cats));
        sort($all_cats);
    ?>
    <div class="card">
        <form method="post">
            <input type="hidden" name="action" value="edit_lore">
            <input type="hidden" name="entry_slug" value="<?= e($entry_slug) ?>">
            <div class="grid-2">
                <div class="form-group">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" class="form-input" required value="<?= e($lore_entry['name']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-select">
                        <?php foreach ($all_cats as $cat): ?>
                            <option value="<?= e($cat) ?>" <?= $lore_entry['category'] === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label class="form-label">Title / Role</label>
                    <input type="text" name="title" class="form-input" value="<?= e($lore_entry['title'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Faction</label>
                    <input type="text" name="faction" class="form-input" value="<?= e($lore_entry['faction'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Aliases</label>
                <input type="text" name="aliases" class="form-input" value="<?= e($lore_entry['aliases'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Content (Markdown)</label>
                <textarea name="content" class="form-textarea" style="min-height:400px; font-family:var(--mono); font-size:13px"><?= e($lore_entry['content'] ?? '') ?></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-accent">Save Changes</button>
                <a href="?page=lore_view&entry=<?= urlencode($entry_slug) ?>" class="btn">Cancel</a>
            </div>
        </form>
    </div>
<?php else: ?>
    <?php if ($lore_entry['title'] || $lore_entry['faction'] || $lore_entry['aliases']): ?>
        <div style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:16px">
            <?php if ($lore_entry['title']): ?>
                <div style="font-size:13px; color:var(--text)"><?= e($lore_entry['title']) ?></div>
            <?php endif; ?>
            <?php if ($lore_entry['faction']): ?>
                <span class="badge badge-deploy"><?= e($lore_entry['faction']) ?></span>
            <?php endif; ?>
            <?php if ($lore_entry['aliases']): ?>
                <div style="font-family:var(--mono); font-size:11px; color:var(--text-dim)">AKA: <?= e($lore_entry['aliases']) ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (trim($lore_entry['content'] ?? '')): ?>
    <div class="card">
        <div style="font-family:var(--sans); font-size:14px; line-height:1.8; white-space:pre-wrap"><?= e($lore_entry['content']) ?></div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php endif; ?>

<?php
// ============================================================
// FALLBACK
// ============================================================
else:
?>
<div class="flash flash-err">Page not found.</div>
<a href="?" class="btn">Dashboard</a>
<?php endif; ?>

</div><!-- /.container -->
<?php endif; // end login check ?>
</body>
</html>
