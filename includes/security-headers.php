<?php
declare(strict_types=1);

function sendSecurityHeaders(bool $allowRecaptcha = false): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    $scriptSources = ["'self'"];
    $frameSources = ["'none'"];
    $connectSources = ["'self'"];
    if ($allowRecaptcha) {
        $scriptSources[] = 'https://www.google.com';
        $scriptSources[] = 'https://www.gstatic.com';
        $frameSources[] = 'https://www.google.com';
        $frameSources[] = 'https://recaptcha.google.com';
        $connectSources[] = 'https://www.google.com';
    }

    header(sprintf(
        "Content-Security-Policy: default-src 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src %s; frame-src %s; connect-src %s; form-action 'self'",
        implode(' ', $scriptSources),
        implode(' ', $frameSources),
        implode(' ', $connectSources)
    ));
}
