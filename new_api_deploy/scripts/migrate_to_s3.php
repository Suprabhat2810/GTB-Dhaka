<?php
/**
 * Migration Script: Local Files to S3
 * 
 * This script migrates existing local files to S3 bucket
 * Run this ONCE when moving from development to production
 * 
 * Usage: php scripts/migrate_to_s3.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../services/StorageService.php';
require_once __DIR__ . '/../services/LocalStorage.php';
require_once __DIR__ . '/../services/S3Storage.php';

use Services\LocalStorage;
use Services\S3Storage;

echo "=== S3 Migration Script ===\n\n";

// Check environment
$appEnv = $_ENV['APP_ENV'] ?? 'development';
if ($appEnv !== 'production') {
    echo "⚠️  Warning: APP_ENV is set to '{$appEnv}'\n";
    echo "This script is intended for production migration.\n";
    echo "Continue anyway? (yes/no): ";
    $confirm = trim(fgets(STDIN));
    if (strtolower($confirm) !== 'yes') {
        echo "Migration cancelled.\n";
        exit(0);
    }
}

// Verify S3 credentials
try {
    $s3Storage = new S3Storage();
    echo "✓ S3 connection verified\n\n";
} catch (Exception $e) {
    echo "✗ S3 connection failed: " . $e->getMessage() . "\n";
    echo "Please check your AWS credentials in .env file\n";
    exit(1);
}

$localStorage = new LocalStorage();
$pdo = getPDO();
$logger = getLogger('migration');

// Get all documents from database
try {
    $stmt = $pdo->query("SELECT id, student_id, document_path, file_size FROM documents ORDER BY id");
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total = count($documents);
    echo "Found {$total} documents to migrate\n\n";
    
    if ($total === 0) {
        echo "No documents to migrate.\n";
        exit(0);
    }
    
    echo "Start migration? (yes/no): ";
    $confirm = trim(fgets(STDIN));
    if (strtolower($confirm) !== 'yes') {
        echo "Migration cancelled.\n";
        exit(0);
    }
    
    echo "\nStarting migration...\n";
    echo str_repeat("-", 50) . "\n";
    
    $migrated = 0;
    $skipped = 0;
    $failed = 0;
    
    foreach ($documents as $index => $doc) {
        $docId = $doc['id'];
        $relativePath = $doc['document_path'];
        $progress = $index + 1;
        
        echo "[{$progress}/{$total}] Migrating: {$relativePath}... ";
        
        // Check if file exists locally
        $localPath = $localStorage->getFilePath($relativePath);
        if (!$localPath || !file_exists($localPath)) {
            echo "SKIP (file not found locally)\n";
            $logger->warning('Migration: local file not found', ['doc_id' => $docId, 'path' => $relativePath]);
            $skipped++;
            continue;
        }
        
        // Check if already exists in S3
        if ($s3Storage->exists($relativePath)) {
            echo "SKIP (already in S3)\n";
            $skipped++;
            continue;
        }
        
        // Upload to S3
        try {
            if ($s3Storage->upload($localPath, $relativePath)) {
                echo "SUCCESS\n";
                $logger->info('Migration: file uploaded to S3', [
                    'doc_id' => $docId,
                    'path' => $relativePath,
                    'size' => $doc['file_size']
                ]);
                $migrated++;
            } else {
                echo "FAILED (upload error)\n";
                $logger->error('Migration: S3 upload failed', ['doc_id' => $docId, 'path' => $relativePath]);
                $failed++;
            }
        } catch (Exception $e) {
            echo "FAILED (" . $e->getMessage() . ")\n";
            $logger->error('Migration: exception during upload', [
                'doc_id' => $docId,
                'path' => $relativePath,
                'error' => $e->getMessage()
            ]);
            $failed++;
        }
        
        // Small delay to avoid rate limiting
        usleep(100000); // 100ms
    }
    
    echo str_repeat("-", 50) . "\n";
    echo "\nMigration Summary:\n";
    echo "  Total documents: {$total}\n";
    echo "  ✓ Migrated:      {$migrated}\n";
    echo "  ⊘ Skipped:       {$skipped}\n";
    echo "  ✗ Failed:        {$failed}\n\n";
    
    if ($failed > 0) {
        echo "⚠️  Some files failed to migrate. Check logs for details.\n";
        echo "You can re-run this script to retry failed uploads.\n\n";
    }
    
    if ($migrated > 0) {
        echo "✓ Migration completed successfully!\n\n";
        echo "Next steps:\n";
        echo "1. Update .env: Set APP_ENV=production and STORAGE_DRIVER=s3\n";
        echo "2. Test file downloads to ensure S3 access works\n";
        echo "3. Optionally run cleanup script to remove local files\n\n";
    }
    
} catch (PDOException $e) {
    echo "✗ Database error: " . $e->getMessage() . "\n";
    $logger->error('Migration: database error', ['error' => $e->getMessage()]);
    exit(1);
}
