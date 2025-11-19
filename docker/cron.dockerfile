#!/usr/bin/env bash
# Cron service Dockerfile for Project Alpha
# Runs scheduled tasks independently from the web service

FROM php:8.3-cli

# System packages needed
RUN apt-get update && apt-get install -y \
    default-mysql-client \
    cron \
    --no-install-recommends && rm -rf /var/lib/apt/lists/*

# PHP extensions required for cron scripts
RUN docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli

# Set working directory
WORKDIR /var/www

# Copy only the necessary files
COPY ./src/ /var/www/src/
COPY ./vendor/ /var/www/vendor/
COPY ./config/ /var/www/config/

# Set proper permissions
RUN chown -R nobody:nogroup /var/www && chmod -R 755 /var/www

# Create cron log directory
RUN mkdir -p /var/log/cron && touch /var/log/cron/cron.log && chmod 666 /var/log/cron/cron.log

# Create crontab file
RUN cat > /etc/cron.d/project-alpha <<'EOF'
# Project Alpha cron jobs
# Generate recurring invoices daily at 2 AM UTC
0 2 * * * nobody php /var/www/src/cron/generate_recurring_invoices.php >> /var/log/cron/generate_recurring_invoices.log 2>&1
# Send invoice reminders daily at 8 AM UTC
0 8 * * * nobody php /var/www/src/cron/send_invoice_reminders.php >> /var/log/cron/send_invoice_reminders.log 2>&1
# Auto-terminate contracts daily at 3 AM UTC
0 3 * * * nobody php /var/www/src/cron/auto_terminate_contracts.php >> /var/log/cron/auto_terminate_contracts.log 2>&1
EOF

# Fix permissions on crontab
RUN chmod 0644 /etc/cron.d/project-alpha

# Create log files
RUN touch /var/log/cron/generate_recurring_invoices.log \
    /var/log/cron/send_invoice_reminders.log \
    /var/log/cron/auto_terminate_contracts.log && \
    chmod 666 /var/log/cron/*.log

# Entry point - start cron in foreground
CMD ["cron", "-f"]
