<?php
declare(strict_types=1);

function clientAddress(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function enforceRateLimit(string $bucket, int $limit, int $windowSeconds): void {
    $key = hash('sha256', $bucket . '|' . clientAddress());
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'halky-rate-limits';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return;
    }

    $path = $dir . DIRECTORY_SEPARATOR . $key . '.json';
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return;
        }
        $contents = stream_get_contents($handle);
        $state = json_decode($contents ?: '', true);
        $now = time();
        if (!is_array($state) || ($now - (int)($state['started'] ?? 0)) >= $windowSeconds) {
            $state = ['started' => $now, 'count' => 0];
        }
        $state['count'] = (int)$state['count'] + 1;
        if ($state['count'] > $limit) {
            http_response_code(429);
            header('Retry-After: ' . max(1, $windowSeconds - ($now - (int)$state['started'])));
            exit('Too many requests.');
        }
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
        fflush($handle);
        flock($handle, LOCK_UN);
    } catch (Throwable) {
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}
