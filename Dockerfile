FROM php:8.2-apache

# Tell Apache what hostname to use. NOTE: this is not needed to run, only to avoid warnings
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Optional: set working dir
WORKDIR /var/www/html

# Install mysqli and other useful extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql \
    && a2enmod rewrite

# Copy files (during local development we'll mount volumes; copying is still useful for built images)
COPY ./public/ /var/www/html/
COPY ./src/ /var/www/src/
COPY ./database/runtime.sql /usr/local/share/app-migrations/runtime.sql

# Set recommended permissions (adjust as needed)
RUN chown -R www-data:www-data /var/www/html /var/www/src \
    && chmod -R 755 /var/www/html /var/www/src

RUN apt-get update && apt-get install -y default-mysql-client --no-install-recommends && rm -rf /var/lib/apt/lists/*

RUN apt-get update && apt-get install -y default-mysql-client --no-install-recommends && rm -rf /var/lib/apt/lists/*

COPY ./docker/start.sh /usr/local/bin/start.sh
# Normalize Windows CRLF to LF to avoid "env: 'bash\r'" errors
RUN sed -i 's/\r$//' /usr/local/bin/start.sh && chmod +x /usr/local/bin/start.sh


EXPOSE 80
# CMD ["apache2-foreground"]
CMD ["start.sh"]
