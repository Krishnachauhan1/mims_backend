<?php

/**
 * MIMS subdomain API bootstrap (same-origin /api on mims.powerforceindustries.com).
 * Supports Hostinger layouts:
 *   ~/domains/mims.powerforceindustries.com/public_html
 *   ~/mims/web
 *   ~/domains/powerforceindustries.com/public_html/mims/web
 */

define('LARAVEL_START', microtime(true));

function mims_find_laravel_root_for_proxy(): string|false
{
    $candidates = [
        __DIR__.'/../../powerforceindustries.com/public_html',
        __DIR__.'/../../domains/powerforceindustries.com/public_html',
        __DIR__.'/../..',
    ];

    foreach ($candidates as $candidate) {
        $resolved = realpath($candidate);
        if ($resolved !== false && is_file($resolved.'/artisan')) {
            return $resolved;
        }
    }

    return false;
}

try {
    $laravelRoot = mims_find_laravel_root_for_proxy();
    if ($laravelRoot === false) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'message' => 'Laravel root not found',
            'proxy_dir' => __DIR__,
        ]);
        exit;
    }

    $bootstrap = $laravelRoot.'/laravel-bootstrap.php';
    if (! is_file($bootstrap)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'message' => 'laravel-bootstrap.php not found',
            'laravel_root' => $laravelRoot,
        ]);
        exit;
    }

    require $bootstrap;

    mims_bootstrap_laravel([$laravelRoot], true);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}
