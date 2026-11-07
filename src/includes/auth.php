<?php
/**
 * Authentication
 * Supports: application login, HTTP Basic Auth (.htpasswd), proxy auth headers
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function auth_current_user(): ?array {
    // Check session first
    if (isset($_SESSION['user_id'])) {
        $stmt = db()->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if ($user) {
            return $user;
        }
        // Session invalid, clear it
        unset($_SESSION['user_id']);
    }

    // Check proxy auth header
    if (config('security.trust_proxy_auth')) {
        $header = config('security.proxy_auth_header', 'X-Forwarded-User');
        $username = $_SERVER['HTTP_' . str_replace('-', '_', strtoupper($header))] ?? null;
        if ($username) {
            return auth_get_or_create_user($username);
        }
    }

    // Check HTTP Basic Auth (from .htpasswd)
    $username = $_SERVER['PHP_AUTH_USER'] ?? $_SERVER['REMOTE_USER'] ?? null;
    if ($username) {
        return auth_get_or_create_user($username);
    }

    return null;
}

function auth_get_or_create_user(string $username): array {
    $stmt = db()->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user) {
        return $user;
    }

    // Auto-create user from external auth
    // First user becomes admin, others become editors
    $role = db_has_admin() ? 'editor' : 'admin';
    $stmt = db()->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, '', ?)");
    $stmt->execute([$username, $role]);

    return [
        'id'       => (int) db()->lastInsertId(),
        'username' => $username,
        'role'     => $role,
    ];
}

function auth_current_username(): string {
    $user = auth_current_user();
    return $user['username'] ?? 'anonymous';
}

function auth_login(string $username, string $password): bool {
    $stmt = db()->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    $_SESSION['user_id'] = $user['id'];

    // Update last login
    db()->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

    return true;
}

function auth_logout(): void {
    unset($_SESSION['user_id']);
    session_destroy();
}

function auth_create_user(string $username, string $password, string $role = 'editor', ?string $email = null): int {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare("INSERT INTO users (username, password_hash, email, role) VALUES (?, ?, ?, ?)");
    $stmt->execute([$username, $hash, $email, $role]);
    return (int) db()->lastInsertId();
}

function auth_require_login(): void {
    if (!auth_current_user()) {
        header('Location: ?page=login');
        exit;
    }
}

function auth_is_admin(): bool {
    $user = auth_current_user();
    return $user && $user['role'] === 'admin';
}
