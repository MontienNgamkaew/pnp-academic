<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']) && !empty($_SESSION['role']);
}

function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function current_user_role(): ?string
{
    return $_SESSION['role'] ?? null;
}

function current_user_fullname(): string
{
    return $_SESSION['fullname'] ?? 'ผู้ใช้งาน';
}

function redirect_to(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function require_login(): void
{
    if (!is_logged_in()) {
        $baseUrl = '';
        $docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
        $authDir = str_replace('\\', '/', __DIR__);
        $docRootLower = strtolower($docRoot);
        $authDirLower = strtolower($authDir);
        
        if ($docRoot !== '' && strpos($authDirLower, $docRootLower) === 0) {
            $basePath = substr($authDir, strlen($docRoot));
            $basePath = '/' . trim(str_replace('\\', '/', $basePath), '/');
            $baseUrl = $basePath === '/' ? '' : $basePath;
        } else {
            // Simple fallback based on current URL depth
            $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
            if (preg_match('#/(admin|teacher)/#i', $script)) {
                $baseUrl = '..';
            } else {
                $baseUrl = '.';
            }
        }
        
        $redirectPath = 'login.php';
        if ($baseUrl !== '') {
            $redirectPath = rtrim($baseUrl, '/') . '/login.php';
        }
        
        redirect_to($redirectPath);
    }
}

function require_role(string $role): void
{
    require_login();

    if (current_user_role() !== $role) {
        http_response_code(403);
        exit('ไม่มีสิทธิ์เข้าถึงหน้านี้');
    }
}

function require_admin(): void
{
    require_role('admin');
}

function require_teacher(): void
{
    require_role('teacher');
}

function create_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool
{
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

function login_user(array $user): void
{
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['fullname'] = $user['fullname'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['login_at'] = time();

    unset($_SESSION['csrf_token']);
}

function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax'
        ]);
    }

    session_destroy();
}
