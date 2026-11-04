<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Models\User;

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

if (! isHttpsRequest()) {
    http_response_code(400);
    exit('HTTPS is required to use the deployment runner.');
}

session_set_cookie_params([
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
    'path' => '/',
]);
session_start();

$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
$applicationRoot = findApplicationRoot(__DIR__, $documentRoot ?: null, (string) (defined('BRADHA_APP_DIR_NAME') ? BRADHA_APP_DIR_NAME : 'bradha-matu-api'));

if ($applicationRoot === null) {
    http_response_code(503);
    exit('The private Laravel application was not found outside the document root.');
}

$envPath = $applicationRoot.DIRECTORY_SEPARATOR.'.env';
if (! is_file($envPath) || ! is_readable($envPath)) {
    http_response_code(503);
    exit('Configure the private Laravel .env file before running deployment.');
}

require_once $applicationRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

try {
    $environment = Dotenv::parse((string) file_get_contents($envPath));
} catch (Throwable) {
    http_response_code(503);
    exit('The private Laravel .env file could not be read.');
}

$deploymentToken = $environment['DEPLOYMENT_TOKEN'] ?? '';
if (! is_string($deploymentToken) || strlen($deploymentToken) < 32) {
    http_response_code(503);
    exit('Set a private random DEPLOYMENT_TOKEN of at least 32 characters in .env.');
}

$storagePath = $applicationRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app';
if (! is_dir($storagePath) && ! mkdir($storagePath, 0750, true) && ! is_dir($storagePath)) {
    http_response_code(503);
    exit('The private Laravel storage directory is not writable.');
}
$usedTokenPath = $storagePath.DIRECTORY_SEPARATOR.'.deployment-token-used';
$lockPath = $storagePath.DIRECTORY_SEPARATOR.'.deployment-runner.lock';

if (is_file($usedTokenPath) && hash_equals(trim((string) file_get_contents($usedTokenPath)), hash('sha256', $deploymentToken))) {
    http_response_code(410);
    exit('This deployment token has already been used. Remove this runner and use a new token for another release.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $_SESSION['deployment_csrf'] ??= bin2hex(random_bytes(32));
    renderPage($_SESSION['deployment_csrf'], null, isset($_SESSION['deployment_authenticated']));
}

if (!sameOriginRequest()) {
    http_response_code(403);
    exit('Rejected request origin.');
}

$csrf = (string) ($_POST['csrf'] ?? '');
if (! isset($_SESSION['deployment_csrf']) || ! hash_equals($_SESSION['deployment_csrf'], $csrf)) {
    http_response_code(403);
    exit('The form expired. Reload this page and try again.');
}

$action = (string) ($_POST['action'] ?? '');
if ($action === 'authenticate') {
    $attempts = (int) ($_SESSION['deployment_attempts'] ?? 0);
    if ($attempts >= 5) {
        http_response_code(429);
        exit('Too many attempts. Start a new browser session later.');
    }

    if (! hash_equals($deploymentToken, (string) ($_POST['deployment_token'] ?? ''))) {
        $_SESSION['deployment_attempts'] = $attempts + 1;
        $_SESSION['deployment_csrf'] = bin2hex(random_bytes(32));
        renderPage($_SESSION['deployment_csrf'], 'The deployment token is invalid.', false);
    }

    session_regenerate_id(true);
    $_SESSION['deployment_authenticated'] = true;
    $_SESSION['deployment_csrf'] = bin2hex(random_bytes(32));
    renderPage($_SESSION['deployment_csrf'], 'Access granted. Confirm the database backup before running deployment.', true);
}

if (! isset($_SESSION['deployment_authenticated'])) {
    http_response_code(401);
    renderPage($_SESSION['deployment_csrf'], 'Authenticate before running deployment.', false);
}

if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: '.strtok((string) $_SERVER['REQUEST_URI'], '?'), true, 303);
    exit;
}

if ($action !== 'deploy' || ($_POST['backup_confirmed'] ?? '') !== 'yes') {
    $_SESSION['deployment_csrf'] = bin2hex(random_bytes(32));
    renderPage($_SESSION['deployment_csrf'], 'Confirm that a current database backup exists before deployment.', true);
}

$lock = fopen($lockPath, 'c');
if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
    http_response_code(409);
    exit('Another deployment is already running.');
}

$message = null;
$success = false;
try {
    if (! is_writable($applicationRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache')) {
        throw new RuntimeException('Laravel bootstrap/cache is not writable by the PHP runtime.');
    }
    if (! is_writable($applicationRoot.DIRECTORY_SEPARATOR.'storage')) {
        throw new RuntimeException('Laravel storage is not writable by the PHP runtime.');
    }
    if (($environment['APP_ENV'] ?? '') !== 'production'
        || ($environment['APP_DEBUG'] ?? 'true') !== 'false'
        || ($environment['DB_CONNECTION'] ?? '') !== 'mysql'
        || ($environment['ROUTER_PROVIDER'] ?? '') !== 'rest') {
        throw new RuntimeException('Set production, debug=false, MySQL, and REST settings in .env before deployment.');
    }
    if (! is_string($environment['APP_KEY'] ?? null) || strlen((string) $environment['APP_KEY']) < 20) {
        $applicationKey = 'base64:'.base64_encode(random_bytes(32));
        $updatedEnv = preg_replace_callback(
            '/^APP_KEY=.*$/m',
            static fn (): string => 'APP_KEY="'.$applicationKey.'"',
            (string) file_get_contents($envPath),
            1,
            $replacements,
        );
        if (! is_string($updatedEnv)) {
            throw new RuntimeException('The application key could not be prepared.');
        }
        if ($replacements === 0) {
            $updatedEnv .= PHP_EOL.'APP_KEY="'.$applicationKey.'"'.PHP_EOL;
        }
        if (file_put_contents($envPath, $updatedEnv, LOCK_EX) === false) {
            throw new RuntimeException('The private .env file is not writable; set APP_KEY manually.');
        }
    }

    $cachedConfig = $applicationRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php';
    if (is_file($cachedConfig) && ! unlink($cachedConfig)) {
        throw new RuntimeException('The stale Laravel config cache could not be cleared.');
    }

    $app = require $applicationRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
    $app->make(ConsoleKernel::class)->bootstrap();

    DB::connection()->getPdo();
    Artisan::call('migrate', ['--force' => true]);

    if (Schema::hasTable('users') && ! User::query()->exists()) {
        if (! is_string($environment['SUPERADMIN_EMAIL'] ?? null)
            || ! filter_var($environment['SUPERADMIN_EMAIL'], FILTER_VALIDATE_EMAIL)
            || ! is_string($environment['SUPERADMIN_PASSWORD'] ?? null)
            || strlen($environment['SUPERADMIN_PASSWORD']) < 16) {
            throw new RuntimeException('Set a valid initial super-admin email and a password of at least 16 characters in .env.');
        }
        Artisan::call('db:seed', [
            '--class' => 'Database\\Seeders\\SuperAdminSeeder',
            '--force' => true,
        ]);
    }

    Artisan::call('config:cache');
    Artisan::call('route:cache');
    Artisan::call('view:cache');

    if (file_put_contents($usedTokenPath, hash('sha256', $deploymentToken), LOCK_EX) === false) {
        throw new RuntimeException('Deployment completed but its one-use token could not be locked.');
    }

    $success = true;
    $message = 'Deployment succeeded. Migrations and production caches are ready; the initial super-admin was created only if the users table was empty.';
    $wrapper = defined('BRADHA_RUNNER_WRAPPER') ? (string) BRADHA_RUNNER_WRAPPER : __FILE__;
    if (! unlink($wrapper) || ($wrapper !== __FILE__ && ! unlink(__FILE__))) {
        $message .= ' Remove web-migrate.php and deployment-runner.php from the public document root immediately.';
    }
} catch (Throwable $exception) {
    if (isset($app)) {
        try {
            Log::error('Browser deployment failed.', ['exception_class' => $exception::class]);
        } catch (Throwable) {
        }
    }
    $message = $exception instanceof RuntimeException
        ? $exception->getMessage()
        : 'Deployment failed. Check the private Laravel log for details.';
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

$_SESSION['deployment_csrf'] = bin2hex(random_bytes(32));
renderPage($_SESSION['deployment_csrf'], $message, true, $success);

function isHttpsRequest(): bool
{
    return (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function sameOriginRequest(): bool
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return true;
    }
    $originHost = parse_url($origin, PHP_URL_HOST);
    $originScheme = parse_url($origin, PHP_URL_SCHEME);

    return is_string($originHost)
        && strtolower($originHost) === strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''))
        && strtolower((string) $originScheme) === 'https';
}

function findApplicationRoot(string $start, ?string $documentRoot, string $appName = 'bradha-matu-api'): ?string
{
    $directory = realpath($start);
    $fallback = null;
    while (is_string($directory)) {
        $candidate = $directory.DIRECTORY_SEPARATOR.$appName;
        if (is_file($candidate.DIRECTORY_SEPARATOR.'artisan')) {
            $root = realpath($candidate);
            if ($root !== false
                && is_file($root.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php')
                && is_file($root.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php')) {
                if ($documentRoot === null || ! pathIsInside($root, $documentRoot)) {
                    return $root;
                }

                if (isSafeAccountRootApplication($root, $documentRoot)) {
                    $fallback = $root;
                }
            }
        }

        $parent = dirname($directory);
        if ($parent === $directory) {
            break;
        }
        $directory = $parent;
    }

    return $fallback;
}

function pathIsInside(string $path, string $directory): bool
{
    $normalizedDirectory = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

    return str_starts_with($path.DIRECTORY_SEPARATOR, $normalizedDirectory);
}

function isSafeAccountRootApplication(string $root, string $documentRoot): bool
{
    $documentRoot = realpath($documentRoot);
    $parent = realpath(dirname($root));
    if ($documentRoot === false || $parent === false || $parent !== $documentRoot) {
        return false;
    }

    $rootRulesPath = $documentRoot.DIRECTORY_SEPARATOR.'.htaccess';
    $applicationRulesPath = $root.DIRECTORY_SEPARATOR.'.htaccess';
    if (! is_file($rootRulesPath) || ! is_readable($rootRulesPath)
        || ! is_file($applicationRulesPath) || ! is_readable($applicationRulesPath)) {
        return false;
    }

    $rootRules = (string) file_get_contents($rootRulesPath);
    $applicationRules = (string) file_get_contents($applicationRulesPath);

    return str_contains($rootRules, '# BRADHA_HOME_ROOT_ROUTING_V1')
        && str_contains($applicationRules, '# BRADHA_PRIVATE_APP_V1')
        && str_contains($applicationRules, 'Require all denied');
}

function renderPage(string $csrf, ?string $message, bool $authenticated, bool $success = false): never
{
    http_response_code($success ? 200 : ($message === null || str_starts_with($message, 'Access granted') ? 200 : 400));
    $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Bratha Matu deployment</title><style>body{font:16px system-ui;background:#0f172a;color:#e2e8f0;margin:0;padding:2rem}.box{max-width:42rem;margin:4vh auto;background:#1e293b;padding:2rem;border-radius:1rem}input,button{font:inherit;padding:.8rem;border-radius:.5rem}input{width:95%;margin:.5rem 0 1rem;background:#0f172a;color:white;border:1px solid #475569}button{background:#0891b2;color:white;border:0;font-weight:700;cursor:pointer}.warn{color:#fbbf24}.ok{color:#86efac}.err{color:#fca5a5}form{margin:1.5rem 0}label{display:block;margin:1rem 0}code{overflow-wrap:anywhere}</style>';
    echo '<main class="box"><h1>Bratha Matu deployment</h1><p>One-use browser migration runner. It only runs safe pending migrations, conditionally creates the first super-admin, and caches production config. It cannot drop tables or roll back.</p>';
    if ($message !== null) {
        echo '<p class="'.($success || str_starts_with($message, 'Access granted') ? 'ok' : 'err').'">'.$escape($message).'</p>';
    }
    if ($authenticated && ! $success) {
        echo '<form method="post"><input type="hidden" name="csrf" value="'.$escape($csrf).'"><input type="hidden" name="action" value="deploy">';
        echo '<label><input type="checkbox" name="backup_confirmed" value="yes" required> I have created and verified a current database backup.</label>';
        echo '<button type="submit">Run safe deployment</button></form>';
        echo '<form method="post"><input type="hidden" name="csrf" value="'.$escape($csrf).'"><input type="hidden" name="action" value="logout"><button type="submit">Lock runner</button></form>';
    } elseif (! $authenticated) {
        echo '<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="'.$escape($csrf).'"><input type="hidden" name="action" value="authenticate">';
        echo '<label for="deployment-token">Private deployment token</label><input id="deployment-token" name="deployment_token" type="password" minlength="32" required autocomplete="new-password">';
        echo '<button type="submit">Unlock</button></form>';
    } else {
        echo '<p class="warn">This runner should be removed from the public document root. The token has been consumed.</p>';
    }
    echo '</main></html>';
    exit;
}
