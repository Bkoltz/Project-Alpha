# ---------- Stage 1: Install PHP dependencies with Composer ----------
FROM php:8.5-cli AS vendor
WORKDIR /app

COPY composer.json ./

RUN apt-get update && apt-get install -y --no-install-recommends \
        git curl unzip zip zlib1g-dev libzip-dev \
    && rm -rf /var/lib/apt/lists/* \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && docker-php-ext-install -j"$(nproc)" zip

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-progress \
    --no-interaction \
    --optimize-autoloader

# ---------- Stage 2: Runtime image ----------
FROM php:8.5-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        default-mysql-client \
        cron \
        curl \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions required by the cron scripts
RUN docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli

WORKDIR /var/www

# NOTE: Application source is provided via volume mount from docker-compose.yml:
#   - ./src:/var/www/src
# This eliminates cron/src/ duplication — the cron container shares the same
# src/ tree as the web container. Do NOT COPY source files here.

# Copy Composer vendor from the builder stage
COPY --from=vendor /app/vendor /var/www/vendor

# Set proper permissions
RUN chown -R root:root /var/www && chmod -R 755 /var/www

# Create log directory and files for each cron job
RUN mkdir -p /var/log/cron && \
    touch /var/log/cron/generate_recurring_invoices.log \
          /var/log/cron/auto_charge_recurring.log \
          /var/log/cron/send_invoice_reminders.log \
          /var/log/cron/auto_terminate_contracts.log \
          /var/log/cron/link_expiration_checker.log \
          /var/log/cron/stripe_reconciliation.log \
          /var/log/cron/sync_merchant_rate.log && \
    chmod 666 /var/log/cron/*.log

# Install crontab into /etc/cron.d/ (requires user field in each entry)
COPY crontab /etc/cron.d/project-alpha
RUN sed -i 's/\r$//' /etc/cron.d/project-alpha && chmod 0644 /etc/cron.d/project-alpha

# Entrypoint script (waits for DB, exports env vars, starts cron)
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

CMD ["entrypoint.sh"]
