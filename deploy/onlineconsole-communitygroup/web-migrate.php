<?php

declare(strict_types=1);

// One-use migration runner for the isolated communitygroup instance.
// The build packages deploy/cpanel/web-migrate.php alongside this file as
// deployment-runner.php (which this path excludes from public access via
// web-migrate.php's no-store rule pattern). It binds the shared runner to
// the private bradha-matu-api-communitygroup directory so it can never
// migrate the pandatechs database by accident.
define('BRADHA_APP_DIR_NAME', 'bradha-matu-api-communitygroup');
define('BRADHA_RUNNER_WRAPPER', __FILE__);

$sharedRunner = __DIR__.'/deployment-runner.php';
if (! is_file($sharedRunner)) {
    http_response_code(503);
    exit('The shared deployment runner is missing from this release.');
}

require $sharedRunner;
