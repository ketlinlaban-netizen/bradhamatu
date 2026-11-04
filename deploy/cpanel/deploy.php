#!/usr/bin/env php
<?php
/**
 * Bratha Matu Console - cPanel Deployment Script
 * 
 * Usage:
 *   php deploy.php setup    - Initial setup (first time only)
 *   php deploy.php deploy   - Deploy application
 *   php deploy.php rollback - Rollback to previous version
 *   php deploy.php status   - Check deployment status
 * 
 * This script handles:
 * - Directory structure setup
 * - Frontend build deployment
 * - Laravel backend configuration
 * - Symlink management
 * - Permissions and ownership
 * - Backup and rollback
 */

class CpanelDeployer
{
    private string $homeDir;
    private string $publicHtml;
    private string $backendDir;
    private string $backupDir;
    private string $logFile;
    private array $config;

    public function __construct()
    {
        $this->homeDir = $_SERVER['HOME'] ?? getcwd();
        $this->publicHtml = $this->homeDir . '/public_html';
        $this->backendDir = $this->homeDir . '/bradha-matu-api';
        $this->backupDir = $this->homeDir . '/backups';
        $this->logFile = $this->homeDir . '/deploy.log';
        
        $this->config = [
            'app_name' => 'Bratha Matu Console',
            'api_symlink' => $this->publicHtml . '/api',
            'frontend_dist' => getcwd() . '/dist',
            'laravel_public' => $this->backendDir . '/public',
        ];
    }

    public function run(array $args): int
    {
        $command = $args[1] ?? 'help';
        
        $this->log("========================================");
        $this->log("Bratha Matu Deployment - Command: {$command}");
        $this->log("Timestamp: " . date('Y-m-d H:i:s'));
        $this->log("========================================");

        try {
            switch ($command) {
                case 'setup':
                    return $this->setup();
                case 'deploy':
                    return $this->deploy();
                case 'rollback':
                    return $this->rollback();
                case 'status':
                    return $this->status();
                case 'help':
                default:
                    $this->showHelp();
                    return 0;
            }
        } catch (Exception $e) {
            $this->error("Deployment failed: " . $e->getMessage());
            return 1;
        }
    }

    private function setup(): int
    {
        $this->info("Starting initial setup...");

        // Create required directories
        $this->info("Creating directory structure...");
        $this->createDirectory($this->backupDir);
        $this->createDirectory($this->backendDir . '/storage/logs');
        $this->createDirectory($this->backendDir . '/storage/framework/cache');
        $this->createDirectory($this->backendDir . '/storage/framework/sessions');
        $this->createDirectory($this->backendDir . '/storage/framework/views');
        $this->createDirectory($this->backendDir . '/bootstrap/cache');

        // Set permissions
        $this->info("Setting permissions...");
        $this->setPermissions($this->backendDir . '/storage', 0755, true);
        $this->setPermissions($this->backendDir . '/bootstrap/cache', 0755, true);

        // Check for .env file
        if (!file_exists($this->backendDir . '/.env')) {
            $this->warning(".env file not found. Creating from example...");
            if (file_exists($this->backendDir . '/.env.cpanel.example')) {
                copy($this->backendDir . '/.env.cpanel.example', $this->backendDir . '/.env');
                $this->warning("IMPORTANT: Edit {$this->backendDir}/.env and configure:");
                $this->warning("  - APP_KEY (run: php artisan key:generate)");
                $this->warning("  - Database credentials");
                $this->warning("  - Superadmin credentials");
                $this->warning("  - APP_URL");
            } else {
                $this->error(".env.cpanel.example not found!");
                return 1;
            }
        }

        // Create API symlink
        $this->info("Creating API symlink...");
        $this->createSymlink($this->config['laravel_public'], $this->config['api_symlink']);

        $this->success("Initial setup completed!");
        $this->info("\nNext steps:");
        $this->info("1. Edit .env file: nano {$this->backendDir}/.env");
        $this->info("2. Generate app key: cd {$this->backendDir} && php artisan key:generate");
        $this->info("3. Run migrations: php migrate.php");
        $this->info("4. Deploy application: php deploy.php deploy");

        return 0;
    }

    private function deploy(): int
    {
        $this->info("Starting deployment...");

        // Create backup
        $backupName = 'backup_' . date('Y-m-d_His');
        $this->info("Creating backup: {$backupName}");
        $this->createBackup($backupName);

        // Deploy frontend
        $this->info("Deploying frontend...");
        $this->deployFrontend();

        // Deploy backend
        $this->info("Deploying backend...");
        $this->deployBackend();

        // Clear caches
        $this->info("Clearing caches...");
        $this->clearCaches();

        // Verify deployment
        $this->info("Verifying deployment...");
        if (!$this->verifyDeployment()) {
            $this->error("Deployment verification failed!");
            $this->warning("Consider running: php deploy.php rollback");
            return 1;
        }

        $this->success("Deployment completed successfully!");
        $this->info("Frontend: {$this->publicHtml}");
        $this->info("Backend API: {$this->config['api_symlink']}");

        return 0;
    }

    private function deployFrontend(): void
    {
        $distDir = $this->config['frontend_dist'];
        
        if (!is_dir($distDir)) {
            throw new Exception("Frontend dist directory not found: {$distDir}");
        }

        // Copy frontend files to public_html
        $this->info("Copying frontend files...");
        
        $files = ['index.html', 'assets', 'vite.svg'];
        foreach ($files as $file) {
            $source = $distDir . '/' . $file;
            $dest = $this->publicHtml . '/' . $file;
            
            if (file_exists($source)) {
                if (is_dir($source)) {
                    $this->copyDirectory($source, $dest);
                } else {
                    copy($source, $dest);
                }
                $this->log("Copied: {$file}");
            }
        }

        // Copy .htaccess for frontend routing
        $htaccessSource = __DIR__ . '/.htaccess';
        $htaccessDest = $this->publicHtml . '/.htaccess';
        
        if (file_exists($htaccessSource)) {
            copy($htaccessSource, $htaccessDest);
            $this->log("Deployed .htaccess");
        }
    }

    private function deployBackend(): void
    {
        // Verify backend directory exists
        if (!is_dir($this->backendDir)) {
            throw new Exception("Backend directory not found: {$this->backendDir}");
        }

        // Optimize Laravel
        $this->info("Optimizing Laravel...");
        $this->runLaravelCommand('config:cache');
        $this->runLaravelCommand('route:cache');
        $this->runLaravelCommand('view:cache');

        // Verify symlink
        if (!is_link($this->config['api_symlink'])) {
            $this->createSymlink($this->config['laravel_public'], $this->config['api_symlink']);
        }
    }

    private function clearCaches(): void
    {
        $this->runLaravelCommand('config:clear');
        $this->runLaravelCommand('cache:clear');
        $this->runLaravelCommand('view:clear');
    }

    private function rollback(): int
    {
        $this->info("Starting rollback...");

        // Find latest backup
        $backups = glob($this->backupDir . '/backup_*');
        if (empty($backups)) {
            $this->error("No backups found!");
            return 1;
        }

        rsort($backups);
        $latestBackup = $backups[0];
        $backupName = basename($latestBackup);

        $this->info("Rolling back to: {$backupName}");

        // Restore frontend
        if (is_dir($latestBackup . '/frontend')) {
            $this->info("Restoring frontend...");
            $this->copyDirectory($latestBackup . '/frontend', $this->publicHtml);
        }

        $this->clearCaches();

        $this->success("Rollback completed!");
        return 0;
    }

    private function status(): int
    {
        $this->info("Deployment Status Check");
        $this->info("======================\n");

        $checks = [
            'Home Directory' => $this->homeDir,
            'Public HTML' => $this->publicHtml,
            'Backend Directory' => $this->backendDir,
            'API Symlink' => $this->config['api_symlink'],
            'Frontend Dist' => $this->config['frontend_dist'],
        ];

        foreach ($checks as $label => $path) {
            $exists = file_exists($path);
            $status = $exists ? '✓' : '✗';
            $this->info("{$status} {$label}: {$path}");
        }

        $this->info("\nBackups:");
        $backups = glob($this->backupDir . '/backup_*');
        if (empty($backups)) {
            $this->info("  No backups found");
        } else {
            rsort($backups);
            foreach (array_slice($backups, 0, 5) as $backup) {
                $this->info("  - " . basename($backup));
            }
        }

        return 0;
    }

    private function createBackup(string $name): void
    {
        $backupPath = $this->backupDir . '/' . $name;
        $this->createDirectory($backupPath);

        // Backup frontend
        $frontendBackup = $backupPath . '/frontend';
        $this->createDirectory($frontendBackup);
        
        if (file_exists($this->publicHtml . '/index.html')) {
            $this->copyDirectory($this->publicHtml . '/assets', $frontendBackup . '/assets');
            copy($this->publicHtml . '/index.html', $frontendBackup . '/index.html');
        }

        $this->log("Backup created: {$name}");
    }

    private function verifyDeployment(): bool
    {
        $checks = [
            $this->publicHtml . '/index.html',
            $this->config['api_symlink'] . '/index.php',
            $this->backendDir . '/.env',
        ];

        foreach ($checks as $file) {
            if (!file_exists($file)) {
                $this->error("Missing: {$file}");
                return false;
            }
        }

        return true;
    }

    private function runLaravelCommand(string $command): void
    {
        $cmd = "cd {$this->backendDir} && php artisan {$command} 2>&1";
        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0) {
            $this->warning("Command failed: artisan {$command}");
            $this->log(implode("\n", $output));
        }
    }

    private function createDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
            $this->log("Created directory: {$path}");
        }
    }

    private function setPermissions(string $path, int $mode, bool $recursive = false): void
    {
        if ($recursive && is_dir($path)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                chmod($item->getPathname(), $mode);
            }
        }
        
        chmod($path, $mode);
    }

    private function createSymlink(string $target, string $link): void
    {
        if (is_link($link)) {
            unlink($link);
        } elseif (is_dir($link)) {
            rmdir($link);
        }

        symlink($target, $link);
        $this->log("Created symlink: {$link} -> {$target}");
    }

    private function copyDirectory(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $destPath = $dest . '/' . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0755, true);
                }
            } else {
                copy($item->getPathname(), $destPath);
            }
        }
    }

    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }

    private function info(string $message): void
    {
        echo "\033[0;34m[INFO]\033[0m {$message}\n";
        $this->log("[INFO] {$message}");
    }

    private function success(string $message): void
    {
        echo "\033[0;32m[SUCCESS]\033[0m {$message}\n";
        $this->log("[SUCCESS] {$message}");
    }

    private function warning(string $message): void
    {
        echo "\033[0;33m[WARNING]\033[0m {$message}\n";
        $this->log("[WARNING] {$message}");
    }

    private function error(string $message): void
    {
        echo "\033[0;31m[ERROR]\033[0m {$message}\n";
        $this->log("[ERROR] {$message}");
    }

    private function showHelp(): void
    {
        echo <<<HELP

Bratha Matu Console - cPanel Deployment Script
===============================================

Usage:
  php deploy.php <command>

Commands:
  setup      Initial setup (first time only)
  deploy     Deploy application
  rollback   Rollback to previous version
  status     Check deployment status
  help       Show this help message

Examples:
  php deploy.php setup
  php deploy.php deploy
  php deploy.php rollback

Logs: {$this->logFile}

HELP;
    }
}

// Run the deployer
$deployer = new CpanelDeployer();
exit($deployer->run($argv));
