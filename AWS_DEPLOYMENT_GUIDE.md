# 🚀 AWS Deployment Guide - GTB College Management System

## Table of Contents
1. [Docker vs Non-Docker Comparison](#docker-vs-non-docker-comparison)
2. [Prerequisites](#prerequisites)
3. [Deployment Option 1: Docker (Recommended)](#deployment-option-1-docker-recommended)
4. [Deployment Option 2: Traditional EC2](#deployment-option-2-traditional-ec2)
5. [Domain & SSL Setup](#domain--ssl-setup)
6. [Database Migration](#database-migration)
7. [Professional Tips & Tricks](#professional-tips--tricks)
8. [Monitoring & Maintenance](#monitoring--maintenance)
9. [Cost Optimization](#cost-optimization)
10. [Troubleshooting](#troubleshooting)

---

## Docker vs Non-Docker Comparison

### 🐳 **Docker Deployment**

#### ✅ **Advantages:**
1. **Consistency**: Same environment in development, staging, and production
2. **Easy Scaling**: Spin up multiple containers instantly
3. **Isolation**: Each service runs independently
4. **Version Control**: Easy rollback to previous versions
5. **Portability**: Move between AWS, Azure, Google Cloud easily
6. **Resource Efficiency**: Better resource utilization
7. **CI/CD Integration**: Easier automation
8. **Faster Deployment**: Deploy in minutes, not hours

#### ❌ **Disadvantages:**
1. **Learning Curve**: Need to understand Docker concepts
2. **Initial Setup**: More complex initial configuration
3. **Overhead**: Small performance overhead (negligible)
4. **Debugging**: Slightly harder to debug issues

#### 💰 **Cost**: ~$30-50/month (t3.medium instance)

---

### 🖥️ **Traditional EC2 Deployment**

#### ✅ **Advantages:**
1. **Simplicity**: Straightforward setup like XAMPP
2. **Direct Access**: Easy to SSH and modify files
3. **Familiar**: Similar to local development
4. **No Docker Knowledge**: Use existing PHP/MySQL skills

#### ❌ **Disadvantages:**
1. **Manual Setup**: Install and configure everything manually
2. **Inconsistency**: Different environments can cause issues
3. **Scaling Difficulty**: Hard to scale horizontally
4. **Maintenance**: More manual updates and patches
5. **Dependency Hell**: Version conflicts possible

#### 💰 **Cost**: ~$25-40/month (t3.small instance)

---

## **🎯 Recommendation: Use Docker!**

**Why?**
- Professional standard in industry
- Easier to maintain long-term
- Better for team collaboration
- Scalable for future growth
- Worth the initial learning curve

---

## Prerequisites

### What You Need:
- ✅ AWS Account (Free tier eligible)
- ✅ Domain name (e.g., gtbcollege.com)
- ✅ Credit/Debit card for AWS verification
- ✅ Basic terminal/command line knowledge
- ✅ SSH client (PuTTY for Windows, built-in for Mac/Linux)

### Costs Breakdown:
| Service | Cost/Month | Purpose |
|---------|-----------|---------|
| EC2 Instance (t3.medium) | $30-35 | Server |
| RDS MySQL (db.t3.micro) | $15-20 | Database |
| Elastic IP | $0 (if attached) | Static IP |
| Route 53 | $0.50 | DNS |
| SSL Certificate | $0 (Let's Encrypt) | HTTPS |
| S3 Storage | $1-5 | File storage |
| **Total** | **$46-61/month** | |

---

## Deployment Option 1: Docker (Recommended)

### Step 1: Launch EC2 Instance

#### 1.1 Login to AWS Console
```
1. Go to https://aws.amazon.com
2. Click "Sign In to Console"
3. Enter your credentials
```

#### 1.2 Launch EC2 Instance
```
1. Go to EC2 Dashboard
2. Click "Launch Instance"
3. Configure:
   - Name: GTB-College-Server
   - AMI: Ubuntu Server 22.04 LTS (Free tier eligible)
   - Instance Type: t3.medium (2 vCPU, 4GB RAM)
   - Key Pair: Create new → Download .pem file (SAVE THIS!)
   - Network Settings:
     ✅ Allow SSH (Port 22) from My IP
     ✅ Allow HTTP (Port 80) from Anywhere
     ✅ Allow HTTPS (Port 443) from Anywhere
     ✅ Allow Custom TCP (Port 3307) from My IP (for MySQL)
   - Storage: 30 GB gp3
4. Click "Launch Instance"
5. Wait 2-3 minutes for instance to start
```

#### 1.3 Allocate Elastic IP
```
1. Go to EC2 → Elastic IPs
2. Click "Allocate Elastic IP address"
3. Click "Allocate"
4. Select the IP → Actions → Associate Elastic IP address
5. Select your instance → Associate
6. Note down this IP (e.g., 54.123.45.67)
```

---

### Step 2: Connect to Server

#### For Windows (Using PuTTY):
```bash
# Convert .pem to .ppk using PuTTYgen
1. Open PuTTYgen
2. Load your .pem file
3. Save private key as .ppk

# Connect using PuTTY
1. Host: ubuntu@YOUR_ELASTIC_IP
2. Connection → SSH → Auth → Browse for .ppk file
3. Click Open
```

#### For Mac/Linux:
```bash
# Set permissions
chmod 400 your-key.pem

# Connect
ssh -i your-key.pem ubuntu@YOUR_ELASTIC_IP
```

---

### Step 3: Install Docker & Docker Compose

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install Docker
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh

# Add user to docker group
sudo usermod -aG docker ubuntu

# Install Docker Compose
sudo curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose

# Verify installation
docker --version
docker-compose --version

# Logout and login again for group changes
exit
# Then reconnect via SSH
```

---

### Step 4: Setup Project Files

```bash
# Create project directory
mkdir -p /home/ubuntu/gtb-college
cd /home/ubuntu/gtb-college

# Install git
sudo apt install git -y

# Clone your repository (if using Git)
# git clone YOUR_REPO_URL .

# OR upload files using SCP/SFTP
# For Windows: Use WinSCP
# For Mac/Linux: Use scp command
```

#### Upload Files from Local Machine:

**Windows (WinSCP):**
```
1. Download WinSCP
2. Host: YOUR_ELASTIC_IP
3. Username: ubuntu
4. Private key: your-key.ppk
5. Connect and drag-drop files
```

**Mac/Linux (SCP):**
```bash
# From your local machine
scp -i your-key.pem -r /path/to/Final_Enhancements/* ubuntu@YOUR_ELASTIC_IP:/home/ubuntu/gtb-college/
```

---

### Step 5: Configure Docker Files

#### 5.1 Create Dockerfile for Backend

```bash
nano /home/ubuntu/gtb-college/new_api_deploy/Dockerfile
```

```dockerfile
FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mysqli zip

# Enable Apache modules
RUN a2enmod rewrite headers

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Configure Apache
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Copy custom Apache config
COPY apache-config.conf /etc/apache2/sites-available/000-default.conf

EXPOSE 80

CMD ["apache2-foreground"]
```

#### 5.2 Create Apache Config

```bash
nano /home/ubuntu/gtb-college/new_api_deploy/apache-config.conf
```

```apache
<VirtualHost *:80>
    ServerAdmin admin@gtbcollege.com
    DocumentRoot /var/www/html/v1
    
    <Directory /var/www/html/v1>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
```

#### 5.3 Create Dockerfile for Frontend

```bash
nano /home/ubuntu/gtb-college/frontend/Dockerfile
```

```dockerfile
# Build stage
FROM node:18-alpine AS build

WORKDIR /app

# Copy package files
COPY package*.json ./

# Install dependencies
RUN npm ci

# Copy source files
COPY . .

# Build application
RUN npm run build

# Production stage
FROM nginx:alpine

# Copy built files
COPY --from=build /app/dist /usr/share/nginx/html

# Copy nginx config
COPY nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
```

#### 5.4 Create Nginx Config for Frontend

```bash
nano /home/ubuntu/gtb-college/frontend/nginx.conf
```

```nginx
server {
    listen 80;
    server_name _;
    root /usr/share/nginx/html;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location /api {
        proxy_pass http://backend:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # Gzip compression
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript;
}
```

#### 5.5 Create Docker Compose File

```bash
nano /home/ubuntu/gtb-college/docker-compose.yml
```

```yaml
version: '3.8'

services:
  # MySQL Database
  db:
    image: mysql:8.0
    container_name: gtb-mysql
    restart: always
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MYSQL_DATABASE: ${DB_NAME}
      MYSQL_USER: ${DB_USER}
      MYSQL_PASSWORD: ${DB_PASSWORD}
    ports:
      - "3307:3306"
    volumes:
      - mysql_data:/var/lib/mysql
      - ./backups:/backups
    command: --default-authentication-plugin=mysql_native_password --character-set-server=utf8mb4 --collation-server=utf8mb4_general_ci
    networks:
      - gtb-network

  # PHP Backend
  backend:
    build:
      context: ./new_api_deploy
      dockerfile: Dockerfile
    container_name: gtb-backend
    restart: always
    depends_on:
      - db
    environment:
      DB_HOST: db
      DB_PORT: 3306
      DB_NAME: ${DB_NAME}
      DB_USER: ${DB_USER}
      DB_PASSWORD: ${DB_PASSWORD}
    volumes:
      - ./new_api_deploy:/var/www/html
      - ./uploads:/var/www/html/uploads
    networks:
      - gtb-network

  # React Frontend
  frontend:
    build:
      context: ./frontend
      dockerfile: Dockerfile
    container_name: gtb-frontend
    restart: always
    depends_on:
      - backend
    networks:
      - gtb-network

  # Nginx Reverse Proxy
  nginx:
    image: nginx:alpine
    container_name: gtb-nginx
    restart: always
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./nginx/nginx.conf:/etc/nginx/nginx.conf
      - ./nginx/conf.d:/etc/nginx/conf.d
      - ./certbot/conf:/etc/letsencrypt
      - ./certbot/www:/var/www/certbot
    depends_on:
      - frontend
      - backend
    networks:
      - gtb-network

  # Certbot for SSL
  certbot:
    image: certbot/certbot
    container_name: gtb-certbot
    volumes:
      - ./certbot/conf:/etc/letsencrypt
      - ./certbot/www:/var/www/certbot
    entrypoint: "/bin/sh -c 'trap exit TERM; while :; do certbot renew; sleep 12h & wait $${!}; done;'"

volumes:
  mysql_data:

networks:
  gtb-network:
    driver: bridge
```

#### 5.6 Create Environment File

```bash
nano /home/ubuntu/gtb-college/.env
```

```env
# Database Configuration
DB_ROOT_PASSWORD=YourSecureRootPassword123!
DB_NAME=gtb_database
DB_USER=gtb_user
DB_PASSWORD=YourSecurePassword123!

# Application Configuration
APP_ENV=production
APP_DEBUG=false

# Razorpay Configuration
RAZORPAY_KEY_ID=your_razorpay_key
RAZORPAY_KEY_SECRET=your_razorpay_secret

# JWT Configuration
JWT_SECRET=your_jwt_secret_key_here_make_it_long_and_random

# Email Configuration (if using)
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=your-email@gmail.com
SMTP_PASSWORD=your-app-password
```

---

### Step 6: Create Nginx Reverse Proxy Config

```bash
mkdir -p /home/ubuntu/gtb-college/nginx/conf.d
nano /home/ubuntu/gtb-college/nginx/conf.d/default.conf
```

```nginx
# Redirect HTTP to HTTPS
server {
    listen 80;
    server_name gtbcollege.com www.gtbcollege.com;
    
    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }
    
    location / {
        return 301 https://$host$request_uri;
    }
}

# HTTPS Server
server {
    listen 443 ssl http2;
    server_name gtbcollege.com www.gtbcollege.com;
    
    # SSL Configuration
    ssl_certificate /etc/letsencrypt/live/gtbcollege.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/gtbcollege.com/privkey.pem;
    
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    
    # Security Headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    
    # Frontend
    location / {
        proxy_pass http://frontend:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
    
    # Backend API
    location /api/ {
        proxy_pass http://backend:80/;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        
        # Increase timeouts for long-running requests
        proxy_connect_timeout 600;
        proxy_send_timeout 600;
        proxy_read_timeout 600;
        send_timeout 600;
    }
    
    # File uploads
    client_max_body_size 50M;
}
```

---

### Step 7: Deploy Application

```bash
cd /home/ubuntu/gtb-college

# Build and start containers
docker-compose up -d --build

# Check if containers are running
docker-compose ps

# View logs
docker-compose logs -f

# If everything looks good, press Ctrl+C to exit logs
```

---

### Step 8: Import Database

```bash
# Copy your SQL dump to server (from local machine)
scp -i your-key.pem gtb_database.sql ubuntu@YOUR_ELASTIC_IP:/home/ubuntu/gtb-college/

# On server, import database
docker exec -i gtb-mysql mysql -uroot -p${DB_ROOT_PASSWORD} gtb_database < /home/ubuntu/gtb-college/gtb_database.sql

# Or connect to MySQL container
docker exec -it gtb-mysql mysql -uroot -p${DB_ROOT_PASSWORD}

# Then run migrations
docker exec -it gtb-backend php run_migration_proper.php
```

---

## Domain & SSL Setup

### Step 1: Configure Domain DNS

#### Using Route 53 (AWS):
```
1. Go to Route 53 → Hosted Zones
2. Click "Create Hosted Zone"
3. Domain name: gtbcollege.com
4. Click "Create"
5. Create A Record:
   - Record name: (leave blank for root domain)
   - Type: A
   - Value: YOUR_ELASTIC_IP
   - TTL: 300
6. Create A Record for www:
   - Record name: www
   - Type: A
   - Value: YOUR_ELASTIC_IP
   - TTL: 300
7. Copy the 4 NS records
8. Go to your domain registrar (GoDaddy, Namecheap, etc.)
9. Update nameservers with Route 53 NS records
10. Wait 24-48 hours for DNS propagation
```

#### Using Domain Registrar DNS:
```
1. Login to your domain registrar
2. Go to DNS Management
3. Add A Record:
   - Host: @
   - Points to: YOUR_ELASTIC_IP
   - TTL: 300
4. Add A Record for www:
   - Host: www
   - Points to: YOUR_ELASTIC_IP
   - TTL: 300
5. Save changes
6. Wait 1-24 hours for propagation
```

---

### Step 2: Install SSL Certificate (Let's Encrypt)

```bash
# Stop nginx temporarily
docker-compose stop nginx

# Get SSL certificate
docker-compose run --rm certbot certonly --standalone \
  --email your-email@example.com \
  --agree-tos \
  --no-eff-email \
  -d gtbcollege.com \
  -d www.gtbcollege.com

# Start nginx again
docker-compose up -d nginx

# Test SSL renewal (dry run)
docker-compose run --rm certbot renew --dry-run
```

---

### Step 3: Update Frontend API URL

```bash
# Edit frontend environment
nano /home/ubuntu/gtb-college/frontend/.env.production
```

```env
VITE_API_BASE_URL=https://gtbcollege.com/api
```

```bash
# Rebuild frontend
docker-compose up -d --build frontend
```

---

## Deployment Option 2: Traditional EC2

### Step 1: Launch EC2 Instance
(Same as Docker Option - Step 1)

### Step 2: Connect to Server
(Same as Docker Option - Step 2)

### Step 3: Install LAMP Stack

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install Apache
sudo apt install apache2 -y
sudo systemctl start apache2
sudo systemctl enable apache2

# Install MySQL
sudo apt install mysql-server -y
sudo mysql_secure_installation

# Install PHP 8.2
sudo apt install software-properties-common -y
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install php8.2 php8.2-cli php8.2-fpm php8.2-mysql php8.2-xml php8.2-mbstring php8.2-curl php8.2-zip php8.2-gd -y

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Install Node.js & npm
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install nodejs -y
```

---

### Step 4: Configure Apache

```bash
# Create virtual host
sudo nano /etc/apache2/sites-available/gtbcollege.conf
```

```apache
<VirtualHost *:80>
    ServerName gtbcollege.com
    ServerAlias www.gtbcollege.com
    DocumentRoot /var/www/gtbcollege/frontend/dist
    
    # Frontend
    <Directory /var/www/gtbcollege/frontend/dist>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        # React Router support
        RewriteEngine On
        RewriteBase /
        RewriteRule ^index\.html$ - [L]
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule . /index.html [L]
    </Directory>
    
    # Backend API
    Alias /api /var/www/gtbcollege/new_api_deploy/v1
    <Directory /var/www/gtbcollege/new_api_deploy/v1>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/gtbcollege-error.log
    CustomLog ${APACHE_LOG_DIR}/gtbcollege-access.log combined
</VirtualHost>
```

```bash
# Enable site and modules
sudo a2ensite gtbcollege
sudo a2enmod rewrite headers
sudo systemctl restart apache2
```

---

### Step 5: Deploy Application Files

```bash
# Create directory
sudo mkdir -p /var/www/gtbcollege
sudo chown -R ubuntu:ubuntu /var/www/gtbcollege

# Upload files (from local machine)
scp -i your-key.pem -r /path/to/Final_Enhancements/* ubuntu@YOUR_ELASTIC_IP:/var/www/gtbcollege/

# On server, set permissions
sudo chown -R www-data:www-data /var/www/gtbcollege
sudo chmod -R 755 /var/www/gtbcollege
sudo chmod -R 775 /var/www/gtbcollege/uploads
```

---

### Step 6: Setup Database

```bash
# Login to MySQL
sudo mysql -u root -p

# Create database and user
CREATE DATABASE gtb_database CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER 'gtb_user'@'localhost' IDENTIFIED BY 'YourSecurePassword123!';
GRANT ALL PRIVILEGES ON gtb_database.* TO 'gtb_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;

# Import database
mysql -u gtb_user -p gtb_database < /var/www/gtbcollege/gtb_database.sql

# Run migrations
cd /var/www/gtbcollege
php run_migration_proper.php
```

---

### Step 7: Build Frontend

```bash
cd /var/www/gtbcollege/frontend

# Install dependencies
npm install

# Update API URL
nano .env.production
# Set: VITE_API_BASE_URL=https://gtbcollege.com/api

# Build
npm run build

# Verify build
ls -la dist/
```

---

### Step 8: Install SSL (Certbot)

```bash
# Install Certbot
sudo apt install certbot python3-certbot-apache -y

# Get certificate
sudo certbot --apache -d gtbcollege.com -d www.gtbcollege.com

# Test auto-renewal
sudo certbot renew --dry-run
```

---

## Professional Tips & Tricks

### 🔒 Security Best Practices

#### 1. Firewall Configuration
```bash
# Install UFW
sudo apt install ufw -y

# Configure rules
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow ssh
sudo ufw allow http
sudo ufw allow https
sudo ufw enable

# Check status
sudo ufw status
```

#### 2. Fail2Ban (Prevent Brute Force)
```bash
# Install
sudo apt install fail2ban -y

# Configure
sudo nano /etc/fail2ban/jail.local
```

```ini
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 5

[sshd]
enabled = true
port = ssh
logpath = /var/log/auth.log

[apache-auth]
enabled = true
port = http,https
logpath = /var/log/apache2/*error.log
```

```bash
# Start service
sudo systemctl start fail2ban
sudo systemctl enable fail2ban
```

#### 3. Disable Root Login
```bash
sudo nano /etc/ssh/sshd_config
```

```
PermitRootLogin no
PasswordAuthentication no
```

```bash
sudo systemctl restart sshd
```

#### 4. Setup Automatic Security Updates
```bash
sudo apt install unattended-upgrades -y
sudo dpkg-reconfigure --priority=low unattended-upgrades
```

---

### 📊 Monitoring & Logging

#### 1. Install Monitoring Tools
```bash
# Install htop
sudo apt install htop -y

# Install netdata (Real-time monitoring)
bash <(curl -Ss https://my-netdata.io/kickstart.sh)

# Access at: http://YOUR_IP:19999
```

#### 2. Setup Log Rotation
```bash
sudo nano /etc/logrotate.d/gtbcollege
```

```
/var/log/apache2/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data adm
    sharedscripts
    postrotate
        systemctl reload apache2 > /dev/null
    endscript
}
```

#### 3. Application Logging
```bash
# View Docker logs
docker-compose logs -f --tail=100

# View Apache logs
sudo tail -f /var/log/apache2/error.log
sudo tail -f /var/log/apache2/access.log

# View MySQL logs
docker exec -it gtb-mysql tail -f /var/log/mysql/error.log
```

---

### 🚀 Performance Optimization

#### 1. Enable Caching (Apache)
```bash
sudo a2enmod expires
sudo a2enmod headers
sudo nano /etc/apache2/sites-available/gtbcollege.conf
```

```apache
# Add inside <VirtualHost>
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg "access plus 1 year"
    ExpiresByType image/jpeg "access plus 1 year"
    ExpiresByType image/gif "access plus 1 year"
    ExpiresByType image/png "access plus 1 year"
    ExpiresByType text/css "access plus 1 month"
    ExpiresByType application/javascript "access plus 1 month"
</IfModule>
```

#### 2. Enable Gzip Compression
```bash
sudo a2enmod deflate
sudo systemctl restart apache2
```

#### 3. Optimize MySQL
```bash
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
```

```ini
[mysqld]
# Performance tuning
innodb_buffer_pool_size = 1G
innodb_log_file_size = 256M
max_connections = 200
query_cache_size = 64M
query_cache_type = 1
```

```bash
sudo systemctl restart mysql
```

#### 4. Setup Redis Cache (Optional)
```bash
# Add to docker-compose.yml
redis:
  image: redis:alpine
  container_name: gtb-redis
  restart: always
  ports:
    - "6379:6379"
  networks:
    - gtb-network
```

---

### 💾 Backup Strategy

#### 1. Automated Database Backup
```bash
# Create backup script
nano /home/ubuntu/backup.sh
```

```bash
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/home/ubuntu/backups"
DB_NAME="gtb_database"

# Create backup directory
mkdir -p $BACKUP_DIR

# Backup database
docker exec gtb-mysql mysqldump -uroot -p${DB_ROOT_PASSWORD} $DB_NAME > $BACKUP_DIR/db_backup_$DATE.sql

# Compress backup
gzip $BACKUP_DIR/db_backup_$DATE.sql

# Delete backups older than 7 days
find $BACKUP_DIR -name "*.sql.gz" -mtime +7 -delete

# Upload to S3 (optional)
# aws s3 cp $BACKUP_DIR/db_backup_$DATE.sql.gz s3://your-bucket/backups/

echo "Backup completed: db_backup_$DATE.sql.gz"
```

```bash
# Make executable
chmod +x /home/ubuntu/backup.sh

# Add to crontab (daily at 2 AM)
crontab -e
```

```cron
0 2 * * * /home/ubuntu/backup.sh >> /home/ubuntu/backup.log 2>&1
```

#### 2. Backup Files to S3
```bash
# Install AWS CLI
sudo apt install awscli -y

# Configure AWS
aws configure
# Enter: Access Key, Secret Key, Region, Output format

# Sync uploads to S3
aws s3 sync /var/www/gtbcollege/uploads s3://gtb-college-uploads/
```

---

### 🔄 CI/CD Setup (GitHub Actions)

Create `.github/workflows/deploy.yml`:

```yaml
name: Deploy to AWS

on:
  push:
    branches: [ main ]

jobs:
  deploy:
    runs-on: ubuntu-latest
    
    steps:
    - uses: actions/checkout@v2
    
    - name: Deploy to EC2
      uses: appleboy/ssh-action@master
      with:
        host: ${{ secrets.EC2_HOST }}
        username: ubuntu
        key: ${{ secrets.EC2_SSH_KEY }}
        script: |
          cd /home/ubuntu/gtb-college
          git pull origin main
          docker-compose down
          docker-compose up -d --build
          docker-compose exec -T gtb-backend php run_migration_proper.php
```

---

## Cost Optimization

### 1. Use Reserved Instances
- Save up to 72% by committing to 1-3 years
- Good for production servers

### 2. Use Spot Instances (Dev/Test)
- Save up to 90% for non-critical workloads
- Not recommended for production

### 3. Auto Scaling
```yaml
# Add to docker-compose.yml
deploy:
  replicas: 2
  update_config:
    parallelism: 1
    delay: 10s
  restart_policy:
    condition: on-failure
```

### 4. CloudFront CDN
- Cache static assets
- Reduce bandwidth costs
- Improve global performance

### 5. S3 Lifecycle Policies
```json
{
  "Rules": [
    {
      "Id": "Archive old backups",
      "Status": "Enabled",
      "Transitions": [
        {
          "Days": 30,
          "StorageClass": "GLACIER"
        }
      ],
      "Expiration": {
        "Days": 90
      }
    }
  ]
}
```

---

## Monitoring & Maintenance

### Daily Tasks
```bash
# Check system health
docker-compose ps
htop

# Check disk space
df -h

# Check logs for errors
docker-compose logs --tail=50 | grep -i error
```

### Weekly Tasks
```bash
# Update system packages
sudo apt update && sudo apt upgrade -y

# Check SSL certificate expiry
sudo certbot certificates

# Review backup logs
cat /home/ubuntu/backup.log
```

### Monthly Tasks
```bash
# Review AWS costs
# AWS Console → Billing Dashboard

# Analyze logs for issues
# Check access patterns
# Optimize database queries
```

---

## Troubleshooting

### Issue 1: Container Won't Start
```bash
# Check logs
docker-compose logs backend

# Check if port is in use
sudo netstat -tulpn | grep :80

# Restart container
docker-compose restart backend
```

### Issue 2: Database Connection Failed
```bash
# Check if MySQL is running
docker-compose ps db

# Check connection from backend
docker exec -it gtb-backend ping db

# Check credentials
docker exec -it gtb-mysql mysql -ugtb_user -p
```

### Issue 3: SSL Certificate Issues
```bash
# Check certificate
sudo certbot certificates

# Renew manually
sudo certbot renew --force-renewal

# Check nginx config
docker exec -it gtb-nginx nginx -t
```

### Issue 4: High Memory Usage
```bash
# Check memory
free -h

# Check container stats
docker stats

# Restart containers
docker-compose restart
```

### Issue 5: Slow Performance
```bash
# Check CPU usage
htop

# Optimize MySQL
docker exec -it gtb-mysql mysql -uroot -p
SHOW PROCESSLIST;

# Enable query cache
# Clear old logs
docker-compose logs --tail=0 -f
```

---

## Quick Reference Commands

### Docker Commands
```bash
# Start all services
docker-compose up -d

# Stop all services
docker-compose down

# Restart a service
docker-compose restart backend

# View logs
docker-compose logs -f backend

# Execute command in container
docker exec -it gtb-backend bash

# Remove all containers and volumes
docker-compose down -v

# Rebuild containers
docker-compose up -d --build
```

### Database Commands
```bash
# Backup database
docker exec gtb-mysql mysqldump -uroot -p${DB_ROOT_PASSWORD} gtb_database > backup.sql

# Restore database
docker exec -i gtb-mysql mysql -uroot -p${DB_ROOT_PASSWORD} gtb_database < backup.sql

# Connect to MySQL
docker exec -it gtb-mysql mysql -uroot -p${DB_ROOT_PASSWORD}
```

### System Commands
```bash
# Check disk space
df -h

# Check memory
free -h

# Check running processes
ps aux | grep php

# Check open ports
sudo netstat -tulpn

# Check system logs
sudo journalctl -xe
```

---

## Post-Deployment Checklist

- [ ] EC2 instance running
- [ ] Elastic IP associated
- [ ] Security groups configured
- [ ] Domain DNS configured
- [ ] SSL certificate installed
- [ ] Application accessible via domain
- [ ] Database imported successfully
- [ ] All features working (login, payment, etc.)
- [ ] Backups configured
- [ ] Monitoring setup
- [ ] Firewall configured
- [ ] Fail2Ban installed
- [ ] Log rotation configured
- [ ] Documentation updated

---

## Support & Resources

### AWS Documentation
- EC2: https://docs.aws.amazon.com/ec2/
- RDS: https://docs.aws.amazon.com/rds/
- Route 53: https://docs.aws.amazon.com/route53/

### Docker Documentation
- Docker: https://docs.docker.com/
- Docker Compose: https://docs.docker.com/compose/

### Community Support
- AWS Forums: https://forums.aws.amazon.com/
- Stack Overflow: https://stackoverflow.com/
- Docker Community: https://forums.docker.com/

---

## Conclusion

You now have a complete guide to deploy your GTB College Management System on AWS!

**Recommended Approach**: Start with Docker deployment for better scalability and maintainability.

**Timeline**:
- Docker Setup: 2-3 hours
- Traditional Setup: 3-4 hours
- DNS Propagation: 1-24 hours
- SSL Setup: 30 minutes
- Testing: 1-2 hours

**Total Time**: 1-2 days for complete deployment

Good luck with your deployment! 🚀

---

**Last Updated**: January 9, 2025
**Version**: 1.0
