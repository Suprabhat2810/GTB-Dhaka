<?php
declare(strict_types=1);

namespace Services;

require_once __DIR__ . '/StorageInterface.php';
require_once __DIR__ . '/LocalStorage.php';
require_once __DIR__ . '/S3Storage.php';

class StorageService
{
    private static ?StorageInterface $instance = null;

    /**
     * Get storage instance based on environment configuration
     */
    public static function getInstance(): StorageInterface
    {
        if (self::$instance === null) {
            $driver = $_ENV['STORAGE_DRIVER'] ?? 'local';
            $appEnv = $_ENV['APP_ENV'] ?? 'development';

            // Auto-detect: development = local, production = s3
            if ($appEnv === 'production' && $driver === 'local') {
                $driver = 's3';
            }

            self::$instance = self::createDriver($driver);
        }

        return self::$instance;
    }

    /**
     * Create storage driver instance
     */
    private static function createDriver(string $driver): StorageInterface
    {
        switch (strtolower($driver)) {
            case 's3':
                return new S3Storage();
            
            case 'local':
            default:
                return new LocalStorage();
        }
    }

    /**
     * Reset instance (useful for testing)
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Get current driver name
     */
    public static function getDriverName(): string
    {
        $driver = $_ENV['STORAGE_DRIVER'] ?? 'local';
        $appEnv = $_ENV['APP_ENV'] ?? 'development';

        if ($appEnv === 'production' && $driver === 'local') {
            return 's3';
        }

        return $driver;
    }
}
