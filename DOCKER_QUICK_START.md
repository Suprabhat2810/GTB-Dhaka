# 🚀 Docker Quick Start - Get Running in 5 Minutes!

## ✅ What's Been Updated

Your Docker files are now production-ready with:
- ✅ **Backend Dockerfile** - PHP 8.2, all extensions, file uploads (50MB)
- ✅ **Frontend Dockerfile** - Optimized build, Nginx, health checks
- ✅ **docker-compose.yml** - MySQL 8.0, networks, volumes, health checks
- ✅ **nginx.conf** - Compression, caching, security, API proxy

---

## 🎯 Quick Start (3 Steps)

### Step 1: Start Docker Desktop
Make sure Docker Desktop is running on your computer.

### Step 2: Run Startup Script

**Windows:**
```bash
docker-start.bat
```

**Mac/Linux:**
```bash
chmod +x docker-start.sh
./docker-start.sh
```

### Step 3: Access Your Application

- **Frontend:** http://localhost:3000
- **Backend:** http://localhost:8080
- **Database:** localhost:3307

**That's it! Your application is running!** 🎉

---

## 📝 Manual Start (If Script Fails)

```bash
# 1. Create directories
mkdir -p uploads/{documents,receipts,profiles,temp}
mkdir -p excel_data backups logs

# 2. Build containers
docker-compose build

# 3. Start services
docker-compose up -d

# 4. Check status
docker-compose ps

# 5. View logs
docker-compose logs -f
```

---

## 🔍 Check Everything is Working

Run the health check script:

**Windows:**
```bash
docker-check.bat
```

**Or manually:**
```bash
# Check container status
docker-compose ps

# Check logs for errors
docker-compose logs | grep -i error

# Test frontend
curl http://localhost:3000

# Test backend
curl http://localhost:8080

# Test database
docker exec gtb-backend php -r "new PDO('mysql:host=db;dbname=gtb_database', 'root', 'sup2005'); echo 'Connected!';"
```

---

## 📊 Import Your Database

```bash
# Import database
docker exec -i gtb-mysql mysql -uroot -psup2005 gtb_database < gtb_database.sql

# Verify import
docker exec gtb-mysql mysql -uroot -psup2005 gtb_database -e "SHOW TABLES;"

# Check record count
docker exec gtb-mysql mysql -uroot -psup2005 gtb_database -e "SELECT COUNT(*) FROM students;"
```

---

## 🔧 Essential Commands

### View Logs
```bash
# All logs (live)
docker-compose logs -f

# Specific service
docker-compose logs -f backend
docker-compose logs -f frontend
docker-compose logs -f db

# Last 50 lines
docker-compose logs --tail=50

# Search for errors
docker-compose logs | grep -i error
```

### Restart Services
```bash
# Restart all
docker-compose restart

# Restart specific service
docker-compose restart backend
```

### Stop Services
```bash
# Stop all
docker-compose down

# Stop and remove volumes (⚠️ DELETES DATA!)
docker-compose down -v
```

### Rebuild After Changes
```bash
# Rebuild and restart
docker-compose up -d --build

# Force rebuild (no cache)
docker-compose build --no-cache
docker-compose up -d
```

---

## 🐛 Troubleshooting

### Container Won't Start

```bash
# Check logs
docker-compose logs backend

# Check if port is in use
netstat -ano | findstr :8080

# Remove and recreate
docker-compose down
docker-compose up -d --force-recreate
```

### Database Connection Failed

```bash
# Check MySQL is running
docker-compose ps db

# Should show: Up (healthy)

# Check MySQL logs
docker-compose logs db

# Restart database
docker-compose restart db
```

### "Port already in use" Error

```bash
# Windows - Kill process on port 8080
netstat -ano | findstr :8080
taskkill /PID <PID_NUMBER> /F

# Or change port in docker-compose.yml
ports:
  - "8081:80"  # Change 8080 to 8081
```

### Out of Disk Space

```bash
# Check disk usage
docker system df

# Clean up
docker system prune -a

# Remove old volumes
docker volume prune
```

### Frontend Shows "Cannot connect to backend"

```bash
# Check if backend is running
docker-compose ps backend

# Check backend logs
docker-compose logs backend

# Test backend directly
curl http://localhost:8080/api/test.php

# Restart backend
docker-compose restart backend
```

---

## 📁 File Upload Testing

```bash
# Check upload directory exists
docker exec gtb-backend ls -la /var/www/html/uploads

# Check permissions
docker exec gtb-backend test -w /var/www/html/uploads && echo "Writable" || echo "Not writable"

# Fix permissions if needed
docker exec gtb-backend chown -R www-data:www-data /var/www/html/uploads
docker exec gtb-backend chmod -R 775 /var/www/html/uploads

# Test upload
curl -X POST -F "file=@test.pdf" http://localhost:8080/api/upload_document.php
```

---

## 🔐 Database Access

### From Host Machine

```bash
# Using MySQL client
mysql -h 127.0.0.1 -P 3307 -uroot -psup2005 gtb_database

# Using MySQL Workbench
Host: 127.0.0.1
Port: 3307
Username: root
Password: sup2005
Database: gtb_database
```

### From Container

```bash
# Access MySQL shell
docker exec -it gtb-mysql mysql -uroot -psup2005 gtb_database

# Run query
docker exec gtb-mysql mysql -uroot -psup2005 gtb_database -e "SELECT COUNT(*) FROM students;"
```

---

## 📦 Backup & Restore

### Backup Database

```bash
# Create backup
docker exec gtb-mysql mysqldump -uroot -psup2005 gtb_database > backup_$(date +%Y%m%d).sql

# Compressed backup
docker exec gtb-mysql mysqldump -uroot -psup2005 gtb_database | gzip > backup_$(date +%Y%m%d).sql.gz
```

### Restore Database

```bash
# Restore from backup
docker exec -i gtb-mysql mysql -uroot -psup2005 gtb_database < backup_20250109.sql

# Restore from compressed
gunzip < backup_20250109.sql.gz | docker exec -i gtb-mysql mysql -uroot -psup2005 gtb_database
```

### Backup Uploads

```bash
# Backup uploads directory
tar -czf uploads_backup_$(date +%Y%m%d).tar.gz uploads/

# Restore uploads
tar -xzf uploads_backup_20250109.tar.gz
```

---

## 🎓 Common Workflows

### Daily Development

```bash
# Start
docker-compose up -d

# Check logs
docker-compose logs -f --tail=50

# Make code changes (auto-reloads)

# Restart if needed
docker-compose restart backend

# Stop when done
docker-compose down
```

### After Code Changes

```bash
# Backend changes
docker-compose restart backend

# Frontend changes
docker-compose up -d --build frontend

# Both changed
docker-compose up -d --build
```

### Fresh Start

```bash
# Stop everything
docker-compose down

# Remove volumes (⚠️ DELETES DATA!)
docker volume rm final_enhancements_db_data

# Rebuild and start
docker-compose up -d --build

# Import database
docker exec -i gtb-mysql mysql -uroot -psup2005 gtb_database < gtb_database.sql
```

---

## 📊 Monitoring

### Check Resource Usage

```bash
# Real-time stats
docker stats

# Disk usage
docker system df

# Container details
docker-compose ps
```

### View Container Details

```bash
# Backend details
docker inspect gtb-backend

# View environment variables
docker exec gtb-backend env

# View running processes
docker top gtb-backend
```

---

## 🚨 Emergency Commands

```bash
# Stop all containers immediately
docker stop $(docker ps -aq)

# Remove all containers
docker rm $(docker ps -aq)

# Remove all images
docker rmi $(docker images -q)

# Complete cleanup (⚠️ NUCLEAR OPTION!)
docker system prune -a --volumes
```

---

## ✅ Pre-Deployment Checklist

Before deploying to production:

- [ ] All containers start successfully
- [ ] No errors in logs
- [ ] Frontend loads at http://localhost:3000
- [ ] Backend responds at http://localhost:8080
- [ ] Database connection works
- [ ] File uploads work
- [ ] Login works (admin & student)
- [ ] All features tested
- [ ] Database backup created
- [ ] Environment variables configured
- [ ] SSL certificate ready (for production)

---

## 📚 Full Documentation

For complete details, see:
- **DOCKER_COMMANDS_GUIDE.md** - All Docker commands
- **STEP_BY_STEP_DEPLOYMENT.md** - Complete deployment guide
- **FILE_UPLOAD_DEPLOYMENT.md** - File upload configuration
- **AWS_DEPLOYMENT_GUIDE.md** - AWS production deployment

---

## 🆘 Need Help?

### Check Logs First
```bash
docker-compose logs -f
```

### Common Issues
1. **Port already in use** → Change port in docker-compose.yml
2. **Database won't start** → Check logs: `docker-compose logs db`
3. **Backend errors** → Check logs: `docker-compose logs backend`
4. **Frontend won't build** → Clear npm cache: `docker-compose build --no-cache frontend`

### Still Stuck?
Run the health check:
```bash
docker-check.bat  # Windows
```

---

## 🎉 Success!

If you see:
```
NAME            STATUS          PORTS
gtb-mysql       Up (healthy)    0.0.0.0:3307->3306/tcp
gtb-backend     Up              0.0.0.0:8080->80/tcp
gtb-frontend    Up              0.0.0.0:3000->80/tcp
```

**Congratulations! Your application is running!** 🚀

Visit: http://localhost:3000

---

**Quick Reference:**
- Start: `docker-compose up -d`
- Stop: `docker-compose down`
- Logs: `docker-compose logs -f`
- Status: `docker-compose ps`
- Rebuild: `docker-compose up -d --build`
