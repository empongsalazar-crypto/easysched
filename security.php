<?php
declare(strict_types=1);

function easysched_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    if ((string) (getenv('EASYSCHED_TRUST_PROXY') ?: '') === '1') {
        $forwarded = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        return $forwarded === 'https';
    }
    return false;
}

function easysched_require_https(): void
{
    if ((string) (getenv('EASYSCHED_FORCE_HTTPS') ?: '') !== '1' || easysched_is_https()) {
        return;
    }
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    $hostName = strtolower((string) preg_replace('/:\\d+$/', '', $host));
    // Local XAMPP development normally runs on plain HTTP. Keep that workflow
    // usable even when a production HTTPS setting was copied into Apache.
    if (in_array($hostName, ['localhost', '127.0.0.1', '[::1]'], true)) {
        return;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    if ($host === '') {
        http_response_code(400);
        exit('A valid HTTPS host is required.');
    }
    header('Location: https://' . $host . $uri, true, 308);
    exit;
}

function easysched_start_session(): void
{
    easysched_require_https();
    $secure = easysched_is_https();
    $sessionPath = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'sessions';
    if (!is_dir($sessionPath)) {
        mkdir($sessionPath, 0770, true);
    }
    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_name('EASYSCHEDSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function easysched_send_security_headers(bool $noStore = true): void
{
    $secure = easysched_is_https();
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    if ($noStore) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
    if ($secure) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    $upgrade = $secure ? "; upgrade-insecure-requests" : '';
    header("Content-Security-Policy: default-src 'self'; connect-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'{$upgrade}");
}
