<?php

/**
 * Resolve Laravel root from Hostinger bootstrap scripts (public_html or mims subfolder).
 *
 * @param  list<string>  $candidates  Relative paths from __DIR__
 */
function mims_resolve_laravel_root(array $candidates): string|false
{
    foreach ($candidates as $candidate) {
        $resolved = realpath($candidate);
        if ($resolved !== false && is_file($resolved.'/artisan')) {
            return $resolved;
        }
    }

    return false;
}

function mims_bootstrap_laravel(array $candidates, bool $jsonErrors = false): void
{
    $laravelRoot = mims_resolve_laravel_root($candidates);
    if ($laravelRoot === false) {
        http_response_code(500);
        if ($jsonErrors) {
            header('Content-Type: application/json');
            echo json_encode(['message' => 'Laravel root not found on server']);
        } else {
            echo 'Laravel root not found on server';
        }
        exit;
    }

    if (file_exists($maintenance = $laravelRoot.'/storage/framework/maintenance.php')) {
        require $maintenance;
    }

    require $laravelRoot.'/vendor/autoload.php';

    /** @var \Illuminate\Foundation\Application $app */
    $app = require_once $laravelRoot.'/bootstrap/app.php';

    $app->handleRequest(\Illuminate\Http\Request::capture());
}
