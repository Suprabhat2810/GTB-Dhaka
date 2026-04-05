# 🐳 Docker Commands Guide - Complete Reference

## ✅ Your Docker Files Are Ready!

All Dockerfiles have been updated with:
- ✅ Proper PHP extensions
- ✅ File upload support (50MB)
- ✅ Health checks
- ✅ Optimized builds
- ✅ Security configurations
- ✅ Volume mounts for persistence

---

## 📋 Pre-Flight Checklist

Before running Docker commands:

```bash
# 1. Create required directories
mkdir -p uploads/{documents,receipts,profiles,temp}
mkdir -p excel_data
mkdir -p backups
mkdir -p logs

# 2. Set permissions (Linux/Mac)
chmod -R 775 uploads/
chmod -R 775 logs/

# 3. Verify Docker is installed
docker --version
docker-compose --version

# 4. Verify you're in the project directory
pwd
# Should show: /path/to/Final_Enhancements
```

---

## 🚀 Initial Setup & Deployment

### Step 1: Build All Containers (First Time)

```bash
# Build all services from scratch
docker-compose build

# Or build with no cache (if you made changes)
docker-compose build --no-cache

# Build specific service only
docker-compose build backend
docker-compose build frontend
docker-compose build db
```

**Expected Output:**
```
[+] Building 45.2s (23/23) FINISHED
 => [backend internal] load build definition
 => [frontend internal] load build definition
 => [db] pulling image mysql:8.0
✓ All services built successfully
```

---

### Step 2: Start All Services

```bash
# Start all containers in detached mode (background)
docker-compose up -d

# Or start with build (if you made changes)
docker-compose up -d --build

# Start and view logs (foreground)
docker-compose up

# Start specific service only
docker-compose up -d backend
```

**Expected Output:**
```
[+] Running 4/4
 ✔ Network gtb-network       Created
 ✔ Container gtb-mysql        Started
 ✔ Container gtb-backend      Started
 ✔ Container gtb-frontend     Started
```

---

### Step 3: Verify All Containers Are Running

```bash
# Check container status
docker-compose ps

# Or use docker ps
docker ps
```

**Expected Output:**
```
NAME            STATUS          PORTS
gtb-mysql       Up (healthy)    0.0.0.0:3307->3306/tcp
gtb-backend     Up              0.0.0.0:8080->80/tcp
gtb-frontend    Up              0.0.0.0:3000->80/tcp
```

**Status Meanings:**
- `Up` = Running normally
- `Up (healthy)` = Running and health check passed
- `Restarting` = Container crashed, trying to restart
- `Exited` = Container stopped (ERROR!)

---

## 📊 Monitoring & Logs

### View Logs

```bash
# View all logs (live)
docker-compose logs -f

# View logs for specific service
docker-compose logs -f backend
docker-compose logs -f frontend
docker-compose logs -f db

# View last 50 lines
docker-compose logs --tail=50

# View logs without following
docker-compose logs

# View logs with timestamps
docker-compose logs -f -t

# Search logs for errors
docker-compose logs | grep -i error
docker-compose logs | grep -i warning
```

**Press `Ctrl+C` to exit log view**

---

### Check Container Health

```bash
# Check health status
docker inspect gtb-mysql --format='{{.State.Health.Status}}'
# Should show: healthy

# View detailed health check
docker inspect gtb-mysql | grep -A 10 Health

# Check all container stats (CPU, Memory)
docker stats

# Check specific container stats
docker stats gtb-backend
```

---

### Check Container Details

```bash
# View container details
docker inspect gtb-backend

# View container IP address
docker inspect gtb-backend --format='{{.NetworkSettings.Networks.gtb-network.IPAddress}}'

# View environment variables
docker exec gtb-backend env

# View running processes inside container
docker top gtb-backend
```

---

## 🔧 Database Operations

### Import Database

```bash
# Method 1: Direct import
docker exec -i gtb-mysql mysql -uroot -psup2005 gtb_database < gtb_database.sql

# Method 2: Copy file then import
docker cp gtb_database.sql gtb-mysql:/tmp/
docker exec gtb-mysql mysql -uroot -psup2005 gtb_database -e "source /tmp/gtb_database.sql"

# Method 3: Import from backup directory
docker exec -i gtb-mysql mysql -uroot -psup2005 gtb_database < backups/gtb_database.sql
```

**Expected Output:**
```
(No output = success)
```

---

### Backup Database

```bash
# Backup to local file
docker exec gtb-mysql mysqldump -uroot -psup2005 gtb_database > backup_$(date +%Y%m%d).sql

# Backup to backups directory
docker exec gtb-mysql mysqldump -uroot -psup2005 gtb_database > backups/backup_$(date +%Y%m%d).sql

# Backup with compression
docker exec gtb-mysql mysqldump -uroot -psup2005 gtb_database | gzip > backup_$(date +%Y%m%d).sql.gz

# Backup specific tables
docker exec gtb-mysql mysqldump -uroot -psup2005 gtb_database students payments > students_payments_backup.sql
```

---

### Connect to MySQL

```bash
# Connect to MySQL shell
docker exec -it gtb-mysql mysql -uroot -psup2005

# Connect to specific database
docker exec -it gtb-mysql mysql -uroot -psup2005 gtb_database

# Run SQL query directly
docker exec gtb-mysql mysql -uroot -psup2005 gtb_database -e "SELECT COUNT(*) FROM students;"

# Run SQL file
docker exec gtb-mysql mysql -uroot -psup2005 gtb_database -e "source /var/www/html/migration.sql"
```

**Inside MySQL:**
```sql
-- Show databases
SHOW DATABASES;

-- Use database
USE gtb_database;

-- Show tables
SHOW TABLES;

-- Check table structure
DESCRIBE students;

-- Count records
SELECT COUNT(*) FROM students;

-- Exit
EXIT;
```

---

### Run Migrations

```bash
# Run migration script
docker exec gtb-backend php run_migration_proper.php

# Run specific migration
docker exec gtb-backend php run_specific_migration.php

# Check migration status
docker exec gtb-backend php -r "echo 'Migrations check';"
```

---

## 🔍 Debugging & Troubleshooting

### Access Container Shell

```bash
# Access backend container
docker exec -it gtb-backend bash

# Access frontend container
docker exec -it gtb-frontend sh

# Access database container
docker exec -it gtb-mysql bash
```

**Inside Container:**
```bash
# Check PHP version
php -v

# Check PHP extensions
php -m

# Check PHP configuration
php -i | grep upload

# List files
ls -la /var/www/html

# Check permissions
ls -la /var/www/html/uploads

# Test database connection
php -r "new PDO('mysql:host=db;dbname=gtb_database', 'root', 'sup2005');"

# Exit container
exit
```

---

### Check Container Logs for Errors

```bash
# Backend errors
docker-compose logs backend | grep -i "error\|warning\|fatal"

# Frontend errors
docker-compose logs frontend | grep -i "error\|failed"

# Database errors
docker-compose logs db | grep -i "error\|warning"

# All errors
docker-compose logs | grep -i "error"
```

---

### Test Connectivity

```bash
# Test backend from frontend
docker exec gtb-frontend ping gtb-backend

# Test database from backend
docker exec gtb-backend ping db

# Test database connection
docker exec gtb-backend php -r "new PDO('mysql:host=db;dbname=gtb_database', 'root', 'sup2005'); echo 'Connected!';"

# Check if port is listening
docker exec gtb-backend netstat -tuln | grep 80
```

---

### Check File Permissions

```bash
# Check uploads directory
docker exec gtb-backend ls -la /var/www/html/uploads

# Fix permissions if needed
docker exec gtb-backend chown -R www-data:www-data /var/www/html/uploads
docker exec gtb-backend chmod -R 775 /var/www/html/uploads

# Check if files are accessible
docker exec gtb-backend test -w /var/www/html/uploads && echo "Writable" || echo "Not writable"
```

---

### View PHP Configuration

```bash
# View PHP info
docker exec gtb-backend php -i

# Check upload settings
docker exec gtb-backend php -i | grep upload

# Check memory limit
docker exec gtb-backend php -i | grep memory_limit

# Check timezone
docker exec gtb-backend php -i | grep timezone
```

---

## 🔄 Container Management

### Restart Containers

```bash
# Restart all containers
docker-compose restart

# Restart specific container
docker-compose restart backend
docker-compose restart frontend
docker-compose restart db

# Force restart (stop then start)
docker-compose stop backend
docker-compose start backend
```

---

### Stop Containers

```bash
# Stop all containers
docker-compose stop

# Stop specific container
docker-compose stop backend

# Stop and remove containers
docker-compose down

# Stop and remove containers + volumes (⚠️ DELETES DATA!)
docker-compose down -v

# Stop and remove containers + images
docker-compose down --rmi all
```

---

### Rebuild Containers

```bash
# Rebuild all containers
docker-compose up -d --build

# Rebuild specific container
docker-compose up -d --build backend

# Force rebuild (no cache)
docker-compose build --no-cache backend
docker-compose up -d backend

# Rebuild and recreate
docker-compose up -d --force-recreate --build
```

---

## 📦 Volume Management

### List Volumes

```bash
# List all volumes
docker volume ls

# Inspect volume
docker volume inspect final_enhancements_db_data

# Check volume size
docker system df -v
```

---

### Backup Volumes

```bash
# Backup database volume
docker run --rm \
  -v final_enhancements_db_data:/data \
  -v $(pwd)/backups:/backup \
  alpine tar czf /backup/db_volume_backup.tar.gz /data

# Restore database volume
docker run --rm \
  -v final_enhancements_db_data:/data \
  -v $(pwd)/backups:/backup \
  alpine tar xzf /backup/db_volume_backup.tar.gz -C /
```

---

### Clean Up Volumes

```bash
# Remove unused volumes
docker volume prune

# Remove specific volume (⚠️ DELETES DATA!)
docker volume rm final_enhancements_db_data

# Remove all volumes (⚠️ DELETES ALL DATA!)
docker volume prune -a
```

---

## 🧹 Cleanup Commands

### Remove Stopped Containers

```bash
# Remove all stopped containers
docker container prune

# Remove specific container
docker rm gtb-backend

# Force remove running container
docker rm -f gtb-backend
```

---

### Remove Images

```bash
# Remove unused images
docker image prune

# Remove all unused images
docker image prune -a

# Remove specific image
docker rmi final_enhancements_backend

# Force remove image
docker rmi -f final_enhancements_backend
```

---

### Complete Cleanup

```bash
# Remove everything (containers, images, volumes, networks)
docker system prune -a --volumes

# Check disk space
docker system df

# Remove only unused data
docker system prune
```

---

## 🔧 Advanced Operations

### Copy Files To/From Container

```bash
# Copy file TO container
docker cp local_file.txt gtb-backend:/var/www/html/

# Copy file FROM container
docker cp gtb-backend:/var/www/html/logs/error.log ./

# Copy directory TO container
docker cp ./uploads gtb-backend:/var/www/html/

# Copy directory FROM container
docker cp gtb-backend:/var/www/html/uploads ./backup_uploads
```

---

### Execute Commands in Container

```bash
# Run PHP script
docker exec gtb-backend php script.php

# Run composer
docker exec gtb-backend composer install

# Run npm (if needed)
docker exec gtb-frontend npm install

# Run multiple commands
docker exec gtb-backend bash -c "cd /var/www/html && php script.php"
```

---

### Network Operations

```bash
# List networks
docker network ls

# Inspect network
docker network inspect gtb-network

# Check container IPs
docker network inspect gtb-network | grep -A 3 "IPv4Address"

# Connect container to network
docker network connect gtb-network container_name

# Disconnect container from network
docker network disconnect gtb-network container_name
```

---

## 🎯 Common Workflows

### Complete Restart (Fresh Start)

```bash
# Stop everything
docker-compose down

# Remove volumes (⚠️ DELETES DATA - Backup first!)
docker volume rm final_enhancements_db_data

# Rebuild and start
docker-compose up -d --build

# Import database
docker exec -i gtb-mysql mysql -uroot -psup2005 gtb_database < gtb_database.sql

# Check logs
docker-compose logs -f
```

---

### Update Code and Redeploy

```bash
# Pull latest code (if using git)
git pull

# Rebuild containers
docker-compose up -d --build

# Check logs for errors
docker-compose logs -f --tail=50

# Test application
curl http://localhost:3000
curl http://localhost:8080/api/test.php
```

---

### Daily Maintenance

```bash
# Check container status
docker-compose ps

# Check logs for errors
docker-compose logs --tail=100 | grep -i error

# Check disk space
docker system df

# Backup database
docker exec gtb-mysql mysqldump -uroot -psup2005 gtb_database > daily_backup_$(date +%Y%m%d).sql

# Clean up old images
docker image prune -a
```

---

## 🚨 Emergency Procedures

### Container Won't Start

```bash
# Check logs
docker-compose logs backend

# Check if port is already in use
netstat -tuln | grep 8080

# Remove and recreate
docker-compose down
docker-compose up -d --force-recreate backend

# Check for errors
docker-compose logs -f backend
```

---

### Database Connection Failed

```bash
# Check if MySQL is running
docker-compose ps db

# Check MySQL logs
docker-compose logs db

# Test connection
docker exec gtb-backend ping db

# Restart database
docker-compose restart db

# Wait for health check
docker-compose ps db
# Should show: Up (healthy)
```

---

### Out of Disk Space

```bash
# Check disk usage
docker system df

# Remove unused data
docker system prune -a

# Remove old images
docker image prune -a

# Remove old volumes
docker volume prune

# Check again
docker system df
```

---

### Container Keeps Restarting

```bash
# Check why it's restarting
docker-compose logs backend --tail=100

# Check exit code
docker inspect gtb-backend --format='{{.State.ExitCode}}'

# Stop auto-restart temporarily
docker update --restart=no gtb-backend

# Fix the issue, then re-enable
docker update --restart=always gtb-backend
```

---

## 📝 Testing Checklist

### After Deployment

```bash
# 1. Check all containers are running
docker-compose ps
# All should show "Up" or "Up (healthy)"

# 2. Test frontend
curl http://localhost:3000
# Should return HTML

# 3. Test backend
curl http://localhost:8080
# Should return response

# 4. Test database
docker exec gtb-mysql mysql -uroot -psup2005 -e "SELECT 1;"
# Should return: 1

# 5. Test file uploads
docker exec gtb-backend test -w /var/www/html/uploads && echo "OK" || echo "FAIL"
# Should return: OK

# 6. Check logs for errors
docker-compose logs | grep -i error
# Should be empty or only minor warnings

# 7. Check disk space
docker system df
# Should have enough space

# 8. Test API endpoint
curl http://localhost:8080/api/test.php
# Should return JSON response
```

---

## 🎓 Quick Reference Card

```bash
# START
docker-compose up -d

# STOP
docker-compose down

# RESTART
docker-compose restart

# LOGS
docker-compose logs -f

# STATUS
docker-compose ps

# REBUILD
docker-compose up -d --build

# SHELL
docker exec -it gtb-backend bash

# DATABASE
docker exec -it gtb-mysql mysql -uroot -psup2005

# BACKUP DB
docker exec gtb-mysql mysqldump -uroot -psup2005 gtb_database > backup.sql

# IMPORT DB
docker exec -i gtb-mysql mysql -uroot -psup2005 gtb_database < backup.sql

# CLEAN UP
docker system prune -a
```

---

## 🔗 Access URLs

After starting containers:

- **Frontend:** http://localhost:3000
- **Backend API:** http://localhost:8080/api/
- **Database:** localhost:3307 (from host machine)
- **Uploads:** http://localhost:8080/uploads/

---

## 💡 Pro Tips

1. **Always check logs first** when something goes wrong
2. **Use health checks** to ensure services are ready
3. **Backup before major changes** (database, volumes)
4. **Use `docker-compose ps`** to quickly check status
5. **Keep containers updated** with `docker-compose pull`
6. **Monitor disk space** with `docker system df`
7. **Use `.dockerignore`** to exclude unnecessary files
8. **Tag your images** for version control
9. **Use networks** to isolate services
10. **Document your changes** in docker-compose.yml

---

## 📚 Additional Resources

- Docker Documentation: https://docs.docker.com/
- Docker Compose: https://docs.docker.com/compose/
- Docker Hub: https://hub.docker.com/
- Best Practices: https://docs.docker.com/develop/dev-best-practices/

---

**Your Docker setup is ready! Start with `docker-compose up -d` and check logs with `docker-compose logs -f`** 🚀
