<?php
declare(strict_types=1);

namespace Services;

class LocalStorage implements StorageInterface
{
    private string $baseDir;
    private string $baseUrl;

    public function __construct()
    {
        $this->baseDir = dirname(__DIR__);
        $this->baseUrl = $this->getBaseUrl();
    }

    /**
     * Upload a file to local filesystem
     */
    public function upload(string $tmpPath, string $relativePath): bool
    {
        $fullPath = $this->getFullPath($relativePath);
        $directory = dirname($fullPath);

        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                return false;
            }
        }

        if (!is_writable($directory)) {
            return false;
        }

        return move_uploaded_file($tmpPath, $fullPath);
    }

    /**
     * Delete a file from local filesystem
     */
    public function delete(string $relativePath): bool
    {
        $fullPath = $this->getFullPath($relativePath);
        
        if (!file_exists($fullPath)) {
            return false;
        }

        if (!is_writable($fullPath)) {
            return false;
        }

        return @unlink($fullPath);
    }

    /**
     * Check if file exists
     */
    public function exists(string $relativePath): bool
    {
        $fullPath = $this->getFullPath($relativePath);
        return file_exists($fullPath);
    }

    /**
     * Get URL for local file
     */
    public function getUrl(string $relativePath, int $expirationMinutes = 5): ?string
    {
        if (!$this->exists($relativePath)) {
            return null;
        }

        // For local storage, return relative URL path
        // The download endpoint will handle serving the file
        return $this->baseUrl . '/v1/download_document.php?path=' . urlencode($relativePath);
    }

    /**
     * Get file size
     */
    public function getSize(string $relativePath): ?int
    {
        $fullPath = $this->getFullPath($relativePath);
        
        if (!file_exists($fullPath)) {
            return null;
        }

        $size = filesize($fullPath);
        return $size !== false ? $size : null;
    }

    /**
     * Get full filesystem path from relative path
     */
    private function getFullPath(string $relativePath): string
    {
        return $this->baseDir . '/' . ltrim($relativePath, '/\\');
    }

    /**
     * Determine base URL for the application
     */
    private function getBaseUrl(): string
    {
        // Try to get from environment first
        if (!empty($_ENV['API_BASE_URL'])) {
            return rtrim($_ENV['API_BASE_URL'], '/');
        }

        // Fallback to auto-detection
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        
        // Remove the script filename to get the base path
        $basePath = dirname(dirname($scriptName));
        
        return $protocol . '://' . $host . $basePath;
    }

    /**
     * Get the actual file path for streaming (used by download endpoint)
     */
    public function getFilePath(string $relativePath): ?string
    {
        $fullPath = $this->getFullPath($relativePath);
        return file_exists($fullPath) ? $fullPath : null;
    }
}
