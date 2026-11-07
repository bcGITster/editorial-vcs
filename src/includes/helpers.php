<?php
/**
 * Helper functions
 */

require_once __DIR__ . '/config.php';

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void {
    header("Location: $url");
    exit;
}

function flash(string $msg, string $type = 'ok'): void {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

function get_flash(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function human_size(int $bytes): string {
    if ($bytes < 1024) return "$bytes B";
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

function time_ago(string $datetime): string {
    $tz = config('app.timezone', 'UTC');
    $local = new DateTimeImmutable($datetime, new DateTimeZone($tz));
    $diff = time() - $local->getTimestamp();
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 2592000) return floor($diff / 86400) . 'd ago';
    return $local->format('Y-m-d');
}

function detect_filetype(string $filename): string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $binary_exts = ['png','jpg','jpeg','gif','webp','svg','ico','bmp','docx','doc','pdf','pptx','xlsx','xls','zip','tar','gz'];
    return in_array($ext, $binary_exts) ? 'binary' : 'text';
}

function file_extension_icon(string $filepath): string {
    $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
    $map = [
        'html' => '&#9001;/&#9002;', 'htm' => '&#9001;/&#9002;',
        'md' => '&#9997;', 'txt' => '&#9776;',
        'eml' => '&#9993;',
        'png' => '&#9632;', 'jpg' => '&#9632;', 'jpeg' => '&#9632;',
        'gif' => '&#9632;', 'webp' => '&#9632;', 'svg' => '&#9632;',
        'css' => '&#10025;', 'js' => '&#9881;', 'json' => '&#123;&#125;',
        'php' => '&#9881;',
        'docx' => '&#9724;', 'pdf' => '&#9724;', 'xlsx' => '&#9724;',
    ];
    return $map[$ext] ?? '&#9724;';
}

function status_badge(string $status): string {
    $map = [
        'draft'  => '<span class="badge badge-draft">draft</span>',
        'review' => '<span class="badge badge-review">review</span>',
        'final'  => '<span class="badge badge-final">final</span>',
    ];
    return $map[$status] ?? '';
}

function app_title(): string {
    return config('app.title', 'Editorial VCS');
}
