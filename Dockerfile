# ---------- Stage 1: Install PHP dependencies with Composer ----------
FROM composer:2 AS vendor
WORKDIR /app

# Copy only Composer manifests first for better layer caching
COPY composer.json composer.lock ./

# Install production dependencies and optimize autoloader
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-progress \
    --no-interaction \
    --optimize-autoloader

# ---------- Stage 2: Runtime image ----------
FROM php:8.3-apache

# Tell Apache what hostname to use. NOTE: this is not needed to run, only to avoid warnings
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Optional: set working dir
WORKDIR /var/www/html

# System packages needed for PHP extensions
RUN apt-get update && apt-get install -y \
    default-mysql-client \
    git \
    unzip \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libzip-dev \
    zlib1g-dev \
    libonig-dev \
    libxml2-dev \
  --no-install-recommends && rm -rf /var/lib/apt/lists/*

# PHP extensions (pdo_mysql, mysqli already required; add mbstring, gd, zip, dom)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd mbstring zip dom pdo_mysql mysqli \
    && a2enmod rewrite

# Copy application code
COPY ./public/ /var/www/html/
COPY ./src/ /var/www/src/
# Copy all migration SQL files into the image (no host mounts needed on TrueNAS)
COPY ./database/migrations/ /usr/local/share/app-migrations/

# Copy Composer vendor from the builder stage
COPY --from=vendor /app/vendor /var/www/vendor

# Set recommended permissions (adjust as needed)
RUN chown -R www-data:www-data /var/www/html /var/www/src /var/www/vendor \
    && chmod -R 755 /var/www/html /var/www/src /var/www/vendor

# Entry script
WORKDIR /var/www
COPY ./docker/start.sh /usr/local/bin/start.sh
# Normalize Windows CRLF to LF to avoid "env: 'bash\r'" errors
RUN sed -i 's/\r$//' /usr/local/bin/start.sh && chmod +x /usr/local/bin/start.sh

EXPOSE 80
CMD ["start.sh"]
