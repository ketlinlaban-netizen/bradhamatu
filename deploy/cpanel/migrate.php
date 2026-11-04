#!/usr/bin/env php
<?php
/**
 * Bratha Matu Console - Database Migration Script
 * 
 * Usage:
 *   php migrate.php           - Run pending migrations
 *   php migrate.php fresh     - Fresh migration (WARNING: Drops all tables!)
 *   php migrate.php rollback  - Rollback last migration
 *   php migrate.php seed      - Run database seeders
 *   php migrate.php status    - Show migration status
 * 
 * This script safely runs Laravel migrations in cPanel environment.
 */

class MigrationRunner
{
    private string $homeDir;
    private string $backendDir;
    private string $logFile;

    public function __construct()
    {
        $this->homeDir = $_SERVER['HOME'] ?? getcwd();
        $this->backendDir = $this->homeDir . '/bradha-matu-api';
        $this->logFile = $this->homeDir . '/migration.log';
    }

    public function run(array $args): int
    {
        $command = $args[1] ?? 'migrate';

        $this->log("========================================");
        $this->log("Migration Runner - Command: {$command}");
        $this->log("Timestamp: " . date('Y-m-d H:i:s'));
        $this->log("========================================");

        try {
            switch ($command) {
                case 'migrate':
                    return $this->migrate();
                case 'fresh':
                    return $this->migrateFresh();
                case 'rollback':
                    return $this->rollback();
                case 'seed':
                    return $this->seed();
                case 'status':
                    return $this->status();
                case 'help':
                default:
                    $this->showHelp();
                    return 0;
            }
        } catch (Exception $e) {
            $this->error("Migration failed: " . $e->getMessage());
            return 1;
        }
    }

    private function migrate(): int
    {
        $this->info("Running database migrations...");

        // Check database connection first
        if (!$this->checkDatabaseConnection()) {
            $this->error("Database connection failed. Check .env configuration.");
            return 1;
        }

        // Run migrations
        $result = $this->runArtisan('migrate --force');
        
        if ($result['success']) {
            $this->success("Migrations completed successfully!");
            $this->info("\nMigration output:");
            foreach ($result['output'] as $line) {
                $this->info("  " . $line);
            }
            return 0;
        } else {
            $this->error("Migration failed!");
            foreach ($result['output'] as $line) {
                $this->error("  " . $line);
            }
            return 1;
        }
    }

    private function migrateFresh(): int
    {
        $this->warning("========================================");
        $this->warning("WARNING: DESTRUCTIVE OPERATION!");
        $this->warning("This will DROP ALL TABLES and re-run migrations.");
        $this->warning("========================================");
        
        if (php_sapi_name() === 'cli') {
            echo "\nType 'yes' to confirm: ";
            $handle = fopen("php://stdin", "r");
            $line = fgets($handle);
            fclose($handle);
            
            if (trim($line) !== 'yes') {
                $this->info("Operation cancelled.");
                return 0;
            }
        } else {
            $this->error("This command requires interactive confirmation.");
            $this->error("Run: php migrate.php fresh");
            return 1;
        }

        $this->info("Running fresh migrations...");
        
        $result = $this->runArtisan('migrate:fresh --force');
        
        if ($result['success']) {
            $this->success("Fresh migrations completed!");
            
            // Ask about seeding
            if (php_sapi_name() === 'cli') {
                echo "\nRun database seeders? (yes/no): ";
                $handle = fopen("php://stdin", "r");
                $line = fgets($handle);
                fclose($handle);
                
                if (trim($line) === 'yes') {
                    $this->seed();
                }
            }
            
            return 0;
        } else {
            $this->error("Fresh migration failed!");
            return 1;
        }
    }

    private function rollback(): int
    {
        $this->info("Rolling back last migration...");
        
        $result = $this->runArtisan('migrate:rollback --force');
        
        if ($result['success']) {
            $this->success("Rollback completed!");
            return 0;
        } else {
            $this->error("Rollback failed!");
            return 1;
        }
    }

    private function seed(): int
    {
        $this->info("Running database seeders...");
        
        $result = $this->runArtisan('db:seed --force');
        
        if ($result['success']) {
            $this->success("Seeding completed!");
            $this->info("\nSeeder output:");
            foreach ($result['output'] as $line) {
                $this->info("  " . $line);
            }
            
            $this->info("\n" . str_repeat("=", 60));
            $this->info("IMPORTANT: Save these credentials securely!");
            $this->info(str_repeat("=", 60));
            
            return 0;
        } else {
            $this->error("Seeding failed!");
            return 1;
        }
    }

    private function status(): int
    {
        $this->info("Migration Status");
        $this->info("================\n");

        // Check database connection
        $this->info("Database Connection:");
        if ($this->checkDatabaseConnection()) {
            $this->success("  ✓ Connected");
        } else {
            $this->error("  ✗ Failed");
            return 1;
        }

        // Show migration status
        $this->info("\nMigration Status:");
        $result = $this->runArtisan('migrate:status');
        
        if ($result['success']) {
            foreach ($result['output'] as $line) {
                echo "  {$line}\n";
            }
        }

        return 0;
    }

    private function checkDatabaseConnection(): bool
    {
        $result = $this->runArtisan('db:show');
        return $result['success'];
    }

    private function runArtisan(string $command): array
    {
        if (!is_dir($this->backendDir)) {
            throw new Exception("Backend directory not found: {$this->backendDir}");
        }

        if (!file_exists($this->backendDir . '/.env')) {
            throw new Exception(".env file not found in {$this->backendDir}");
        }

        $cmd = "cd {$this->backendDir} && php artisan {$command} 2>&1";
        exec($cmd, $output, $returnCode);

        $this->log("Command: artisan {$command}");
        $this->log("Return code: {$returnCode}");
        $this->log("Output:\n" . implode("\n", $output));

        return [
            'success' => $returnCode === 0,
            'output' => $output,
            'returnCode' => $returnCode,
        ];
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

Bratha Matu Console - Migration Runner
======================================

Usage:
  php migrate.php <command>

Commands:
  migrate    Run pending migrations (safe, default)
  fresh      Drop all tables and re-run migrations (DESTRUCTIVE!)
  rollback   Rollback last migration batch
  seed       Run database seeders
  status     Show migration status
  help       Show this help message

Examples:
  php migrate.php                 # Run pending migrations
  php migrate.php status          # Check status
  php migrate.php seed            # Run seeders
  php migrate.php fresh           # Fresh install (requires confirmation)

Logs: {$this->logFile}

HELP;
    }
}

// Run the migration runner
$runner = new MigrationRunner();
exit($runner->run($argv));
