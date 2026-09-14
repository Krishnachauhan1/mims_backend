<?php

/**
 * MIMS Laravel web bootstrap (replaces Flutter on mims subdomain).
 * Upload to: ~/domains/powerforceindustries.com/public_html/mims/index.php
 */

define('LARAVEL_START', microtime(true));

require __DIR__.'/../laravel-bootstrap.php';

mims_bootstrap_laravel([
    __DIR__.'/../../mims-api',
    __DIR__.'/..',
]);
