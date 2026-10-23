<?php
declare(strict_types=1);

require_once __DIR__ . '/app-config.php';

function recaptchaSiteKey(): string {
    return (string)(getAppConfig()['recaptcha']['site_key'] ?? '');
}

function verifyRecaptcha(string $response, ?string $remoteIp = null): bool {
    $config = getAppConfig()['recaptcha'] ?? [];
    $secret = trim((string)($config['secret'] ?? ''));
    if ($secret === '' || trim($response) === '' || !function_exists('curl_init')) {
        return false;
    }

    $payload = http_build_query([
        'secret' => $secret,
        'response' => $response,
        'remoteip' => $remoteIp ?? ($_SERVER['REMOTE_ADDR'] ?? ''),
    ], '', '&', PHP_QUERY_RFC3986);

    $curl = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if (!is_string($raw) || $status !== 200) {
        return false;
    }

    try {
        $result = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return false;
    }

    if (($result['success'] ?? false) !== true) {
        return false;
    }

    $hostname = trim((string)($config['hostname'] ?? ''));
    return $hostname === '' || hash_equals($hostname, (string)($result['hostname'] ?? ''));
}
