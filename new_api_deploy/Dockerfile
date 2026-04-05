# ---------- stage: vendor (use official composer image to install PHP deps) ----------
FROM composer:2 AS vendor

WORKDIR /app

# copy only composer files to leverage build cache
COPY composer.json composer.lock ./

# Install PHP dependencies into /app/vendor
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-suggest \
    && rm -rf /root/.composer/cache

# Ensure vendor/bin exists even if no binaries installed (prevents COPY failure)
RUN mkdir -p /app/vendor/bin

# ---------- stage: final (runtime) ----------
FROM php:8.2-apache

LABEL maintainer="you@example.com"

# install system dependencies (minimal + no-recommends)
RUN apt-get update \
  && apt-get install -y --no-install-recommends \
     git curl libpng-dev libonig-dev libxml2-dev libzip-dev zip unzip ca-certificates \
  && rm -rf /var/lib/apt/lists/*

# php extensions
RUN docker-php-ext-install pdo_mysql mysqli zip mbstring exif pcntl bcmath gd \
  && docker-php-ext-enable mysqli pdo_mysql

# enable apache modules
RUN a2enmod rewrite headers

WORKDIR /var/www/html

# copy app source
COPY . /var/www/html/

# copy vendor from the composer stage
COPY --from=vendor /app/vendor /var/www/html/vendor
COPY --from=vendor /app/vendor/bin /var/www/html/vendor/bin

# create required runtime directories & set perms
RUN mkdir -p /var/www/html/uploads /var/www/html/logs /var/www/html/excel_data \
  && chown -R www-data:www-data /var/www/html \
  && find /var/www/html -type d -exec chmod 755 {} \; \
  && chmod -R 775 /var/www/html/uploads /var/www/html/logs

# configure apache server name
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# php runtime configuration
RUN { \
      echo "upload_max_filesize = 50M"; \
      echo "post_max_size = 50M"; \
      echo "max_execution_time = 300"; \
      echo "memory_limit = 256M"; \
      echo "date.timezone = Asia/Kolkata"; \
    } > /usr/local/etc/php/conf.d/uploads.ini

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD curl -f http://localhost/health.php || exit 1

CMD ["apache2-foreground"]
