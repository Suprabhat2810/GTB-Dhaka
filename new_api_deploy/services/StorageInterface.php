<?php
declare(strict_types=1);

namespace Services;

interface StorageInterface
{
    /**
     * Upload a file to storage
     * 
     * @param string $tmpPath Temporary file path from $_FILES
     * @param string $relativePath Relative path where file should be stored (e.g., 'uploads/documents/file.pdf')
     * @return bool True on success, false on failure
     */
    public function upload(string $tmpPath, string $relativePath): bool;

    /**
     * Delete a file from storage
     * 
     * @param string $relativePath Relative path of the file to delete
     * @return bool True on success, false on failure
     */
    public function delete(string $relativePath): bool;

    /**
     * Check if a file exists in storage
     * 
     * @param string $relativePath Relative path of the file
     * @return bool True if exists, false otherwise
     */
    public function exists(string $relativePath): bool;

    /**
     * Get the full URL to access a file
     * For local: returns relative URL
     * For S3: returns signed URL with expiration
     * 
     * @param string $relativePath Relative path of the file
     * @param int $expirationMinutes Expiration time for signed URLs (S3 only)
     * @return string|null Full URL or null if file doesn't exist
     */
    public function getUrl(string $relativePath, int $expirationMinutes = 5): ?string;

    /**
     * Get file size in bytes
     * 
     * @param string $relativePath Relative path of the file
     * @return int|null File size in bytes or null if file doesn't exist
     */
    public function getSize(string $relativePath): ?int;
}
