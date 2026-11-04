<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Isolated instance for onlineconsole.communitygroup.com. The directory name
// is deliberate: it stops this front controller from ever resolving to the
// pandatechs bradha-matu-api instance on the same cPanel account.
const BRADHA_COMMUNITYGROUP_APP_DIR = 'bradha-matu-api-communitygroup';

$directory = realpath(__DIR__);
$applicationRoot = null;
$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
while (is_string($directory)) {
    $candidate = $directory.DIRECTORY_SEPARATOR.BRADHA_COMMUNITYGROUP_APP_DIR;
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
    }
    $parent = dirname($directory);
    if ($parent === $directory) {
        break;
    }
    $directory = $parent;
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
