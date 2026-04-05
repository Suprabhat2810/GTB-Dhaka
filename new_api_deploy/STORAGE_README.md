# Storage Service Documentation

## Overview

The application now supports **environment-based file storage** with automatic switching between local filesystem (development) and AWS S3 (production).

## Features

- ✅ **Automatic Environment Detection**: Development uses local storage, Production uses S3
- ✅ **Unified API**: Same code works for both storage types
- ✅ **Secure Downloads**: Authentication required, signed URLs for S3
- ✅ **Zero Downtime**: Switch between storage types without code changes
- ✅ **Backward Compatible**: Existing local files continue to work

---

## Configuration

### Environment Variables (.env)

```env
# Storage Configuration
APP_ENV=development              # 'development' or 'production'
STORAGE_DRIVER=local             # 'local' or 's3'

# AWS S3 Configuration (Required for production)
AWS_ACCESS_KEY_ID=your_access_key_here
AWS_SECRET_ACCESS_KEY=your_secret_key_here
AWS_DEFAULT_REGION=ap-south-1
AWS_BUCKET_NAME=gtb-student-documents
AWS_S3_URL=https://gtb-student-documents.s3.ap-south-1.amazonaws.com
```

### Automatic Switching

- **Development** (`APP_ENV=development`): Uses local filesystem
- **Production** (`APP_ENV=production`): Automatically uses S3 (even if `STORAGE_DRIVER=local`)

---

## AWS S3 Setup

### 1. Create S3 Bucket

```bash
# Using AWS CLI
aws s3 mb s3://gtb-student-documents --region ap-south-1
```

Or via AWS Console:
1. Go to S3 → Create bucket
2. Name: `gtb-student-documents`
3. Region: `ap-south-1` (Mumbai)
4. **Block all public access**: ✅ Enabled (keep private)
5. Create bucket

### 2. Configure Bucket CORS

Add CORS policy to allow your domain:

```json
[
    {
        "AllowedHeaders": ["*"],
        "AllowedMethods": ["GET", "PUT", "POST", "DELETE"],
        "AllowedOrigins": ["https://gtbnc.co.in"],
        "ExposeHeaders": ["ETag"],
        "MaxAgeSeconds": 3000
    }
]
```

### 3. Create IAM User

1. Go to IAM → Users → Create user
2. Name: `gtb-storage-service`
3. Attach policy:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "s3:PutObject",
                "s3:GetObject",
                "s3:DeleteObject",
                "s3:ListBucket"
            ],
            "Resource": [
                "arn:aws:s3:::gtb-student-documents",
                "arn:aws:s3:::gtb-student-documents/*"
            ]
        }
    ]
}
```

4. Create access key → Copy `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY`

### 4. Enable Server-Side Encryption (Optional)

- Go to bucket → Properties → Default encryption
- Enable: AES-256 (SSE-S3)

---

## Installation

### 1. Install AWS SDK

```bash
cd new_api_deploy
composer require aws/aws-sdk-php
```

### 2. Update .env File

```bash
# Copy example and edit
cp .env .env.backup
nano .env
```

Add AWS credentials from IAM user creation.

### 3. Test Configuration

```bash
# Test local storage (development)
APP_ENV=development php -r "require 'config.php'; require 'services/StorageService.php'; echo Services\StorageService::getDriverName();"
# Output: local

# Test S3 storage (production)
APP_ENV=production php -r "require 'config.php'; require 'services/StorageService.php'; echo Services\StorageService::getDriverName();"
# Output: s3
```

---

## Migration from Local to S3

When moving to production, migrate existing files:

### 1. Run Migration Script

```bash
cd new_api_deploy
php scripts/migrate_to_s3.php
```

The script will:
- ✅ Check S3 connection
- ✅ List all documents in database
- ✅ Upload local files to S3
- ✅ Skip files already in S3
- ✅ Log all operations

### 2. Update Environment

After successful migration:

```env
APP_ENV=production
STORAGE_DRIVER=s3
```

### 3. Test Downloads

Test that downloads work from S3:
- Login as admin
- Download a document
- Verify signed URL is generated
- Check file downloads correctly

---

## Usage in Code

### Upload File

```php
use Services\StorageService;

$storage = StorageService::getInstance();
$relativePath = 'uploads/documents/file.pdf';

if ($storage->upload($tmpFilePath, $relativePath)) {
    // Save to database
    echo "Upload successful!";
}
```

### Delete File

```php
$storage = StorageService::getInstance();

if ($storage->delete($relativePath)) {
    echo "File deleted!";
}
```

### Get Download URL

```php
$storage = StorageService::getInstance();

// For S3: Returns signed URL (expires in 5 minutes)
// For Local: Returns download endpoint URL
$url = $storage->getUrl($relativePath, 5);
```

### Check if File Exists

```php
$storage = StorageService::getInstance();

if ($storage->exists($relativePath)) {
    echo "File exists!";
}
```

---

## Download Endpoint

### Endpoint: `/v1/download_document.php`

**Authentication**: Required (admin or document owner)

**Parameters**:
- `path` (required): Relative path of document

**Example**:
```
GET /v1/download_document.php?path=uploads/documents/doc_123.pdf
```

**Behavior**:
- **Local Storage**: Streams file directly to browser
- **S3 Storage**: Redirects to signed URL (5-minute expiration)

**Security**:
- ✅ Authentication required
- ✅ Permission check (admin or owner only)
- ✅ Path sanitization (prevents directory traversal)
- ✅ Database verification

---

## Troubleshooting

### Issue: "S3 connection failed"

**Solution**:
1. Verify AWS credentials in `.env`
2. Check IAM user has correct permissions
3. Verify bucket name and region are correct

### Issue: "Failed to upload file"

**Solution**:
1. Check bucket permissions
2. Verify IAM policy allows `s3:PutObject`
3. Check file size (S3 has 5GB single upload limit)

### Issue: "Download returns 404"

**Solution**:
1. Verify file exists in database
2. Check file path in database matches actual file
3. For S3: Verify file was uploaded successfully
4. Check authentication token is valid

### Issue: "CORS error in browser"

**Solution**:
1. Add CORS policy to S3 bucket
2. Include your domain in `AllowedOrigins`
3. Clear browser cache

---

## Cost Estimation (AWS S3)

### Storage Costs (ap-south-1 Mumbai)
- **Standard Storage**: $0.025 per GB/month
- **Example**: 10GB documents = $0.25/month

### Request Costs
- **PUT/POST**: $0.0055 per 1,000 requests
- **GET**: $0.00044 per 1,000 requests
- **Example**: 10,000 downloads/month = $0.004

### Data Transfer
- **Upload to S3**: Free
- **Download from S3**: First 1GB free, then $0.109/GB

**Total Estimated Cost**: ~$1-5/month for typical usage

---

## Security Best Practices

1. ✅ **Private Bucket**: Never make bucket public
2. ✅ **IAM Permissions**: Use least-privilege principle
3. ✅ **Signed URLs**: Short expiration (5 minutes)
4. ✅ **Encryption**: Enable server-side encryption
5. ✅ **Access Logs**: Enable S3 access logging
6. ✅ **Versioning**: Enable for backup/recovery
7. ✅ **Lifecycle Policies**: Archive old documents to Glacier

---

## Files Modified/Created

### New Files
- `services/StorageInterface.php` - Storage interface
- `services/StorageService.php` - Factory and singleton
- `services/LocalStorage.php` - Local filesystem driver
- `services/S3Storage.php` - AWS S3 driver
- `v1/download_document.php` - Secure download endpoint
- `scripts/migrate_to_s3.php` - Migration script

### Modified Files
- `composer.json` - Added AWS SDK
- `.env` - Added storage configuration
- `v1/documents.php` - Uses StorageService
- `v1/student_documents.php` - Uses StorageService

---

## Support

For issues or questions:
1. Check logs: `new_api_deploy/v1/logs/`
2. Review AWS CloudWatch logs
3. Verify environment configuration
4. Test with migration script

---

**Last Updated**: December 2025
