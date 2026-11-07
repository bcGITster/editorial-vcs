<?php
/**
 * Editorial VCS — First-Run Setup Wizard
 *
 * Guides new installations through:
 * 1. Database initialization
 * 2. Admin account creation
 * 3. First project setup
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/helpers.php';

session_start();

$step = $_GET['step'] ?? 'check';
$error = null;
$success = null;

// Check database connection
function check_db_connection(): ?string {
    try {
        require_once __DIR__ . '/includes/db.php';
        db();
        return null;
    } catch (PDOException $e) {
        return $e->getMessage();
    }
}

// Run schema
function run_schema(): ?string {
    try {
        $schema_file = dirname(__DIR__) . '/schema.sql';
        if (!file_exists($schema_file)) {
            return 'schema.sql not found in project root';
        }
        $sql = file_get_contents($schema_file);
        require_once __DIR__ . '/includes/db.php';
        db()->exec($sql);
        return null;
    } catch (PDOException $e) {
        return $e->getMessage();
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'init_db') {
        $err = run_schema();
        if ($err) {
            $error = "Failed to initialize database: $err";
        } else {
            $success = 'Database initialized successfully.';
            $step = 'admin';
        }
    }

    if ($action === 'create_admin') {
        require_once __DIR__ . '/includes/db.php';
        require_once __DIR__ . '/includes/auth.php';

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';
        $email = trim($_POST['email'] ?? '') ?: null;

        if ($username === '' || strlen($username) < 3) {
            $error = 'Username must be at least 3 characters.';
        } elseif ($password === '' || strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $password2) {
            $error = 'Passwords do not match.';
        } else {
            try {
                auth_create_user($username, $password, 'admin', $email);
                $success = "Admin account '$username' created.";
                $step = 'project';
            } catch (PDOException $e) {
                $error = 'Failed to create admin: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'create_project') {
        require_once __DIR__ . '/includes/db.php';
        require_once __DIR__ . '/includes/versioning.php';

        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $description = trim($_POST['description'] ?? '') ?: null;
        $deploy_path = trim($_POST['deploy_path'] ?? '') ?: null;

        if ($name === '' || $slug === '') {
            $error = 'Project name and slug are required.';
        } else {
            $slug = slugify($slug);
            if (project_slug_exists($slug)) {
                $error = "A project with slug '$slug' already exists.";
            } elseif ($deploy_path && !is_dir($deploy_path)) {
                $error = "Deploy path does not exist: $deploy_path";
            } else {
                create_project($name, $slug, $description, $deploy_path);
                $success = "Project '$name' created. Setup complete!";
                $step = 'done';
            }
        }
    }
}

// Determine current step based on state
if ($step === 'check') {
    $db_error = check_db_connection();
    if ($db_error) {
        $step = 'db_error';
        $error = $db_error;
    } else {
        require_once __DIR__ . '/includes/db.php';
        if (!db_is_initialized()) {
            $step = 'init';
        } elseif (!db_has_admin()) {
            $step = 'admin';
        } elseif (!db_has_projects()) {
            $step = 'project';
        } else {
            $step = 'done';
        }
    }
}

$title = app_title();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — Setup</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
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
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.setup-container {
    max-width: 480px;
    width: 100%;
}
.setup-header {
    text-align: center;
    margin-bottom: 32px;
}
.setup-title {
    font-family: var(--mono);
    font-weight: 600;
    font-size: 20px;
    color: var(--accent);
    letter-spacing: 2px;
    text-transform: uppercase;
    margin-bottom: 8px;
}
.setup-subtitle {
    color: var(--text-dim);
    font-size: 13px;
}
.card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 24px;
}
.step-indicator {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-bottom: 24px;
}
.step-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--border);
}
.step-dot.active { background: var(--accent); }
.step-dot.done { background: var(--green); }
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
.form-input {
    width: 100%;
    font-family: var(--mono);
    font-size: 13px;
    padding: 10px 12px;
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text);
    outline: none;
}
.form-input:focus { border-color: var(--accent); }
.form-hint {
    font-size: 11px;
    color: var(--text-dim);
    margin-top: 4px;
}
.btn {
    display: inline-block;
    font-family: var(--mono);
    font-size: 12px;
    font-weight: 500;
    padding: 10px 20px;
    border-radius: var(--radius);
    border: 1px solid var(--accent);
    background: var(--accent-dim);
    color: var(--accent);
    cursor: pointer;
    transition: all 0.15s;
    text-decoration: none;
    width: 100%;
    text-align: center;
}
.btn:hover { background: var(--accent); color: var(--bg); }
.flash {
    padding: 10px 14px;
    border-radius: var(--radius);
    margin-bottom: 16px;
    font-size: 13px;
    font-family: var(--mono);
}
.flash-ok { background: var(--green-dim); border: 1px solid var(--green); color: var(--green); }
.flash-err { background: var(--red-dim); border: 1px solid var(--red); color: var(--red); }
.step-title {
    font-family: var(--mono);
    font-size: 14px;
    font-weight: 600;
    color: var(--text-bright);
    margin-bottom: 16px;
    text-align: center;
}
.done-message {
    text-align: center;
    padding: 20px 0;
}
.done-message p { margin-bottom: 16px; color: var(--text-dim); }
</style>
</head>
<body>

<div class="setup-container">
    <div class="setup-header">
        <div class="setup-title"><?= e($title) ?></div>
        <div class="setup-subtitle">First-Time Setup</div>
    </div>

    <div class="step-indicator">
        <div class="step-dot <?= in_array($step, ['init']) ? 'active' : (in_array($step, ['admin','project','done']) ? 'done' : '') ?>"></div>
        <div class="step-dot <?= $step === 'admin' ? 'active' : (in_array($step, ['project','done']) ? 'done' : '') ?>"></div>
        <div class="step-dot <?= $step === 'project' ? 'active' : ($step === 'done' ? 'done' : '') ?>"></div>
    </div>

    <?php if ($error): ?>
        <div class="flash flash-err"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="flash flash-ok"><?= e($success) ?></div>
    <?php endif; ?>

    <div class="card">
    <?php if ($step === 'db_error'): ?>
        <div class="step-title">Database Connection Failed</div>
        <p style="color:var(--text-dim); margin-bottom:16px">
            Could not connect to the database. Check your <code>config.php</code> settings.
        </p>
        <pre style="background:var(--bg); padding:12px; border-radius:var(--radius); font-size:11px; overflow-x:auto; margin-bottom:16px"><?= e($error ?? 'Unknown error') ?></pre>
        <a href="?step=check" class="btn">Retry</a>

    <?php elseif ($step === 'init'): ?>
        <div class="step-title">Initialize Database</div>
        <p style="color:var(--text-dim); margin-bottom:16px">
            The database exists but has not been initialized. Click below to create the required tables.
        </p>
        <form method="post">
            <input type="hidden" name="action" value="init_db">
            <button type="submit" class="btn">Initialize Database</button>
        </form>

    <?php elseif ($step === 'admin'): ?>
        <div class="step-title">Create Admin Account</div>
        <form method="post">
            <input type="hidden" name="action" value="create_admin">
            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-input" required minlength="3" autocomplete="username">
            </div>
            <div class="form-group">
                <label class="form-label">Email (optional)</label>
                <input type="email" name="email" class="form-input" autocomplete="email">
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-input" required minlength="8" autocomplete="new-password">
                <div class="form-hint">At least 8 characters</div>
            </div>
            <div class="form-group">
                <label class="form-label">Confirm Password</label>
                <input type="password" name="password2" class="form-input" required autocomplete="new-password">
            </div>
            <button type="submit" class="btn">Create Admin</button>
        </form>

    <?php elseif ($step === 'project'): ?>
        <div class="step-title">Create First Project</div>
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
                <label class="form-label">Description (optional)</label>
                <input type="text" name="description" class="form-input" placeholder="Brief description">
            </div>
            <div class="form-group">
                <label class="form-label">Deploy Path (optional)</label>
                <input type="text" name="deploy_path" class="form-input" placeholder="/var/www/mysite">
                <div class="form-hint">Absolute server path for import/deploy operations</div>
            </div>
            <button type="submit" class="btn">Create Project</button>
        </form>
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

    <?php elseif ($step === 'done'): ?>
        <div class="done-message">
            <div style="font-size:32px; margin-bottom:12px">&#10003;</div>
            <div class="step-title">Setup Complete</div>
            <p>Your Editorial VCS installation is ready to use.</p>
            <a href="index.php" class="btn">Go to Dashboard</a>
        </div>
    <?php endif; ?>
    </div>
</div>

</body>
</html>
