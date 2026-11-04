#!/usr/bin/env php
<?php
/**
 * Bratha Matu Console - Health Check Script
 * 
 * Comprehensive health check for cPanel deployment.
 * 
 * Usage:
 *   php health-check.php
 */

class HealthChecker
{
    private string $homeDir;
    private string $backendDir;
    private string $publicHtml;
    private array $results = [];

    public function __construct()
    {
        $this->homeDir = $_SERVER['HOME'] ?? getcwd();
        $this->backendDir = $this->homeDir . '/bradha-matu-api';
        $this->publicHtml = $this->homeDir . '/public_html';
    }

    public function run(): int
    {
        echo "\n";
        echo "========================================\n";
        echo "Bratha Matu Console - Health Check\n";
        echo "========================================\n\n";

        $this->checkPhp();
        $this->checkDirectories();
        $this->checkSymlinks();
        $this->checkPermissions();
        $this->checkEnvironment();
        $this->checkDatabase();
        $this->checkLaravel();
        $this->checkFrontend();

        $this->displaySummary();

        return $this->allPassed() ? 0 : 1;
    }

    private function checkPhp(): void
    {
        $this->section("PHP Environment");

        $version = PHP_VERSION;
        $required = '8.3.0';
        $this->check(
            "PHP Version (>= 8.3)",
            version_compare($version, $required, '>='),
            "Current: {$version}"
        );

        $extensions = ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'curl', 'json'];
        foreach ($extensions as $ext) {
            $this->check(
                "Extension: {$ext}",
                extension_loaded($ext),
                $ext
            );
        }
    }

    private function checkDirectories(): void
    {
        $this->section("Directory Structure");

        $dirs = [
            'Home Directory' => $this->homeDir,
            'Backend (Laravel)' => $this->backendDir,
            'Public HTML' => $this->publicHtml,
            'Storage' => $this->backendDir . '/storage',
            'Bootstrap Cache' => $this->backendDir . '/bootstrap/cache',
            'Logs' => $this->backendDir . '/storage/logs',
        ];

        foreach ($dirs as $label => $path) {
            $this->check($label, is_dir($path), $path);
        }
    }

    private function checkSymlinks(): void
    {
        $this->section("Symlinks");

        $apiLink = $this->publicHtml . '/api';
        $apiTarget = $this->backendDir . '/public';

        $exists = is_link($apiLink);
        $this->check("API Symlink Exists", $exists, $apiLink);

        if ($exists) {
            $target = readlink($apiLink);
            $valid = $target === $apiTarget;
            $this->check("API Symlink Target", $valid, "Points to: {$target}");
        }
    }

    private function checkPermissions(): void
    {
        $this->section("Permissions");

        $paths = [
            $this->backendDir . '/storage',
            $this->backendDir . '/bootstrap/cache',
        ];

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                $this->check(basename($path), false, "Directory not found");
                continue;
            }

            $perms = substr(sprintf('%o', fileperms($path)), -4);
            $writable = is_writable($path);
            
            $this->check(
                basename($path) . " Writable",
                $writable,
                "Permissions: {$perms}"
            );
        }
    }

    private function checkEnvironment(): void
    {
        $this->section("Environment Configuration");

        $envFile = $this->backendDir . '/.env';
        $this->check(".env File", file_exists($envFile), $envFile);

        if (file_exists($envFile)) {
            $env = $this->parseEnv($envFile);

            $this->check(
                "APP_KEY Set",
                !empty($env['APP_KEY']) && strlen($env['APP_KEY']) > 20,
                isset($env['APP_KEY']) ? "Length: " . strlen($env['APP_KEY']) : "Not set"
            );

            $this->check(
                "APP_ENV",
                isset($env['APP_ENV']) && $env['APP_ENV'] === 'production',
                $env['APP_ENV'] ?? 'Not set'
            );

            $this->check(
                "APP_DEBUG",
                isset($env['APP_DEBUG']) && $env['APP_DEBUG'] === 'false',
                $env['APP_DEBUG'] ?? 'Not set'
            );

            $this->check(
                "Database Configured",
                !empty($env['DB_DATABASE']) && !empty($env['DB_USERNAME']),
                "Database: " . ($env['DB_DATABASE'] ?? 'Not set')
            );
        }
    }

    private function checkDatabase(): void
    {
        $this->section("Database Connection");

        if (!is_dir($this->backendDir)) {
            $this->check("Backend Directory", false, "Not found");
            return;
        }

        $cmd = "cd {$this->backendDir} && php artisan db:show 2>&1";
        exec($cmd, $output, $returnCode);

        $this->check(
            "Database Connection",
            $returnCode === 0,
            $returnCode === 0 ? "Connected" : "Failed to connect"
        );

        if ($returnCode === 0) {
            $cmd = "cd {$this->backendDir} && php artisan migrate:status 2>&1";
            exec($cmd, $output, $returnCode);

            $this->check(
                "Migrations Table",
                $returnCode === 0,
                $returnCode === 0 ? "Migrations table exists" : "Run migrations first"
            );
        }
    }

    private function checkLaravel(): void
    {
        $this->section("Laravel Application");

        if (!is_dir($this->backendDir)) {
            return;
        }

        $files = [
            'artisan' => $this->backendDir . '/artisan',
            'composer.json' => $this->backendDir . '/composer.json',
            'composer.lock' => $this->backendDir . '/composer.lock',
            'vendor/' => $this->backendDir . '/vendor',
        ];

        foreach ($files as $label => $path) {
            $this->check($label, file_exists($path), $path);
        }

        // Check Laravel can boot
        $cmd = "cd {$this->backendDir} && php artisan --version 2>&1";
        exec($cmd, $output, $returnCode);

        $version = $returnCode === 0 ? trim($output[0] ?? '') : 'Failed';
        $this->check(
            "Laravel Boots",
            $returnCode === 0,
            $version
        );
    }

    private function checkFrontend(): void
    {
        $this->section("Frontend Deployment");

        $files = [
            'index.html' => $this->publicHtml . '/index.html',
            '.htaccess' => $this->publicHtml . '/.htaccess',
            'assets/' => $this->publicHtml . '/assets',
        ];

        foreach ($files as $label => $path) {
            $this->check($label, file_exists($path), $path);
        }
    }

    private function check(string $label, bool $passed, string $detail = ''): void
    {
        $status = $passed ? '✓' : '✗';
        $color = $passed ? "\033[0;32m" : "\033[0;31m";
        $reset = "\033[0m";

        echo sprintf(
            "  %s%s%s %-30s %s\n",
            $color,
            $status,
            $reset,
            $label,
            $detail
        );

        $this->results[] = $passed;
    }

    private function section(string $title): void
    {
        echo "\n{$title}\n";
        echo str_repeat("-", strlen($title)) . "\n";
    }

    private function displaySummary(): void
    {
        $total = count($this->results);
        $passed = count(array_filter($this->results));
        $failed = $total - $passed;

        $this->section("Summary");

        $color = $failed === 0 ? "\033[0;32m" : "\033[0;31m";
        $reset = "\033[0m";

        echo sprintf(
            "  Total Checks: %d\n",
            $total
        );
        echo sprintf(
            "  %sPassed: %d%s\n",
            "\033[0;32m",
            $passed,
            "\033[0m"
        );

        if ($failed > 0) {
            echo sprintf(
                "  %sFailed: %d%s\n",
                "\033[0;31m",
                $failed,
                "\033[0m"
            );
        }

        echo "\n";

        if ($failed === 0) {
            echo "{$color}All checks passed! ✓{$reset}\n";
        } else {
            echo "{$color}Some checks failed. Please review the issues above.{$reset}\n";
        }

        echo "\n";
    }

    private function allPassed(): bool
    {
        return count(array_filter($this->results)) === count($this->results);
    }

    private function parseEnv(string $file): array
    {
        $env = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $env[trim($key)] = trim($value);
            }
        }

        return $env;
    }
}

// Run health check
$checker = new HealthChecker();
exit($checker->run());
