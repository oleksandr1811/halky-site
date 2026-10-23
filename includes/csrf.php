<?php
declare(strict_types=1);

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

function csrfToken(): string {
    startSecureSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function requireCsrfToken(?string $token): void {
    startSecureSession();
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    if ($expected === '' || !is_string($token) || !hash_equals($expected, $token)) {
        http_response_code(403);
        exit('Request validation failed.');
    }
}
