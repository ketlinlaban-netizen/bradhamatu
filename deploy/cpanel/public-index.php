<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$directory = realpath(__DIR__);
$applicationRoot = null;
$accountRootApplication = null;
$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
while (is_string($directory)) {
    $candidate = $directory.DIRECTORY_SEPARATOR.'bradha-matu-api';
    if (is_file($candidate.DIRECTORY_SEPARATOR.'artisan')
        && is_file($candidate.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php')
        && is_file($candidate.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php')) {
        $resolvedCandidate = realpath($candidate);
        if ($resolvedCandidate !== false && (
            $documentRoot === false
            || ! str_starts_with($resolvedCandidate.DIRECTORY_SEPARATOR, rtrim($documentRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
        )) {
            $applicationRoot = $resolvedCandidate;
            break;
        }

        if ($resolvedCandidate !== false
            && realpath(dirname($resolvedCandidate)) === $documentRoot
            && is_file($resolvedCandidate.DIRECTORY_SEPARATOR.'.htaccess')
            && is_file($documentRoot.DIRECTORY_SEPARATOR.'.htaccess')
            && str_contains((string) file_get_contents($resolvedCandidate.DIRECTORY_SEPARATOR.'.htaccess'), '# BRADHA_PRIVATE_APP_V1')
            && str_contains((string) file_get_contents($documentRoot.DIRECTORY_SEPARATOR.'.htaccess'), '# BRADHA_HOME_ROOT_ROUTING_V1')) {
            $accountRootApplication = $resolvedCandidate;
        }
    }
    $parent = dirname($directory);
    if ($parent === $directory) {
        break;
    }
    $directory = $parent;
}

if ($applicationRoot === null) {
    $applicationRoot = $accountRootApplication;
}

if (! is_string($applicationRoot)) {
    http_response_code(503);
    exit('Application is not configured.');
}

if (is_file($maintenance = $applicationRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'maintenance.php')) {
    require $maintenance;
}

require $applicationRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

/** @var Application $app */
$app = require $applicationRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
$app->handleRequest(Request::capture());
