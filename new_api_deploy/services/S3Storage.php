<?php
declare(strict_types=1);

namespace Services;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class S3Storage implements StorageInterface
{
    private S3Client $s3Client;
    private string $bucket;
    private string $region;
    private string $baseUrl;

    public function __construct()
    {
        $this->bucket = $_ENV['AWS_BUCKET_NAME'] ?? '';
        $this->region = $_ENV['AWS_DEFAULT_REGION'] ?? 'ap-south-1';
        $this->baseUrl = $_ENV['AWS_S3_URL'] ?? '';

        if (empty($this->bucket)) {
            throw new \Exception('AWS_BUCKET_NAME not configured in .env');
        }

        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region' => $this->region,
            'credentials' => [
                'key' => $_ENV['AWS_ACCESS_KEY_ID'] ?? '',
                'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'] ?? '',
            ],
        ]);
    }

    /**
     * Upload a file to S3
     */
    public function upload(string $tmpPath, string $relativePath): bool
    {
        try {
            $result = $this->s3Client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $relativePath,
                'SourceFile' => $tmpPath,
                'ContentType' => $this->getMimeType($tmpPath),
                'ServerSideEncryption' => 'AES256',
            ]);

            return isset($result['ObjectURL']);
        } catch (AwsException $e) {
            error_log('S3 Upload Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a file from S3
     */
    public function delete(string $relativePath): bool
    {
        try {
            $this->s3Client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $relativePath,
            ]);

            return true;
        } catch (AwsException $e) {
            error_log('S3 Delete Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if file exists in S3
     */
    public function exists(string $relativePath): bool
    {
        try {
            $result = $this->s3Client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $relativePath,
            ]);

            return isset($result['ContentLength']);
        } catch (AwsException $e) {
            return false;
        }
    }

    /**
     * Get signed URL for S3 file
     */
    public function getUrl(string $relativePath, int $expirationMinutes = 5): ?string
    {
        if (!$this->exists($relativePath)) {
            return null;
        }

        try {
            $cmd = $this->s3Client->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key' => $relativePath,
            ]);

            $request = $this->s3Client->createPresignedRequest(
                $cmd,
                '+' . $expirationMinutes . ' minutes'
            );

            return (string) $request->getUri();
        } catch (AwsException $e) {
            error_log('S3 GetUrl Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get file size from S3
     */
    public function getSize(string $relativePath): ?int
    {
        try {
            $result = $this->s3Client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $relativePath,
            ]);

            return (int) ($result['ContentLength'] ?? null);
        } catch (AwsException $e) {
            return null;
        }
    }

    /**
     * Get MIME type of file
     */
    private function getMimeType(string $filePath): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath);
        
        return $mimeType ?: 'application/octet-stream';
    }

    /**
     * Download file from S3 to temporary location (for migration or processing)
     */
    public function downloadToTemp(string $relativePath): ?string
    {
        try {
            $tempFile = tempnam(sys_get_temp_dir(), 's3_download_');
            
            $this->s3Client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $relativePath,
                'SaveAs' => $tempFile,
            ]);

            return $tempFile;
        } catch (AwsException $e) {
            error_log('S3 Download Error: ' . $e->getMessage());
            return null;
        }
    }
}
