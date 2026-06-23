# ---------- Stage 1: Install PHP dependencies with Composer ----------
FROM php:8.3-cli AS vendor
WORKDIR /app

# Copy only Composer manifests first for better layer caching
COPY composer.json composer.lock ./

# Install system utilities and Composer (use a matching PHP version so lockfile checks pass)
RUN apt-get update && apt-get install -y --no-install-recommends \
        git curl unzip zip zlib1g-dev libzip-dev \
    && rm -rf /var/lib/apt/lists/* \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer --version \
    # Install ext-zip so Composer can make use of zip archives where available
    && docker-php-ext-install -j"$(nproc)" zip

# Install production dependencies and optimize autoloader
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-progress \
    --no-interaction \
    --optimize-autoloader

# ---------- Stage 2: Runtime image ----------
FROM php:8.3-apache AS web

# Tell Apache what hostname to use. NOTE: this is not needed to run, only to avoid warnings
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Always-on hardening (ZAP findings 10036/10037): hide Apache version and
# PHP X-Powered-By header regardless of APP_HOST.
RUN { \
      echo "ServerTokens Prod"; \
      echo "ServerSignature Off"; \
    } >> /etc/apache2/conf-available/security.conf \
    && a2enconf security \
    && echo "expose_php = Off" > /usr/local/etc/php/conf.d/zz-hardening.ini

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

# Create php ini file for error logging and future php customization
COPY php.ini /usr/local/etc/php/conf.d/php.ini

# Copy application code
COPY ./public/ /var/www/html/
COPY ./src/ /var/www/src/

# Copy database initialization and migration files into the image
# The init.sql is the single source of truth with all modules concatenated
COPY ./database/init.sql /usr/local/share/app-migrations/init.sql
COPY ./database/init.sql /docker-entrypoint-initdb.d/01-init.sql

# Copy Composer vendor from the builder stage
COPY --from=vendor /app/vendor /var/www/vendor

# Set recommended permissions (adjust as needed)
RUN chown -R www-data:www-data /var/www/html /var/www/src /var/www/vendor \
    && chmod -R 755 /var/www/html /var/www/src /var/www/vendor

# RUN chown -R www-data:www-data /var/www/config && chmod -R 755 /var/www/config

# Create log file
RUN mkdir -p /var/log && \
    touch /var/log/error_log.txt && \
    chmod 666 /var/log/error_log.txt

# Entry script
WORKDIR /var/www
COPY ./docker/start.sh /usr/local/bin/start.sh
# Normalize Windows CRLF to LF to avoid "env: 'bash\r'" errors
RUN sed -i 's/\r$//' /usr/local/bin/start.sh && chmod +x /usr/local/bin/start.sh

EXPOSE 80
CMD ["start.sh"]

# ---------- Stage 3: Cron service ----------
# Uses the same vendor stage as web. Source code is volume-mounted at runtime.
FROM php:8.3-cli AS cron

RUN apt-get update && apt-get install -y --no-install-recommends \
        default-mysql-client cron curl \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli

WORKDIR /var/www

COPY --from=vendor /app/vendor /var/www/vendor
COPY ./src/ /var/www/src/
RUN mkdir -p /var/www/logs /var/www/backups \
    && chown -R root:root /var/www \
    && chmod -R 755 /var/www

RUN mkdir -p /var/log/cron && \
    touch /var/log/cron/generate_recurring_invoices.log \
          /var/log/cron/auto_charge_recurring.log \
          /var/log/cron/send_invoice_reminders.log \
          /var/log/cron/auto_terminate_contracts.log \
          /var/log/cron/link_expiration_checker.log \
          /var/log/cron/stripe_reconciliation.log && \
    chmod 666 /var/log/cron/*.log

COPY cron/crontab /etc/cron.d/project-alpha
RUN sed -i 's/\r$//' /etc/cron.d/project-alpha && chmod 0644 /etc/cron.d/project-alpha

COPY cron/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

CMD ["entrypoint.sh"]
