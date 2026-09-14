<?php

/**
 * Main domain API bootstrap.
 * Upload to: ~/domains/powerforceindustries.com/public_html/laravel-api-index.php
 */

define('LARAVEL_START', microtime(true));

require __DIR__.'/laravel-bootstrap.php';

mims_bootstrap_laravel([
    __DIR__,
], jsonErrors: true);
