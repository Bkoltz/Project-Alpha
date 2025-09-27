FROM php:8.2-apache

# Optional: set working dir
WORKDIR /var/www/html

# Install mysqli and other useful extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql \
    && a2enmod rewrite

# Copy files (during local development we'll mount volumes; copying is still useful for built images)
COPY ./public/ /var/www/html/

# Set recommended permissions (adjust as needed)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

RUN apt-get update && apt-get install -y default-mysql-client --no-install-recommends && rm -rf /var/lib/apt/lists/*

RUN apt-get update && apt-get install -y default-mysql-client --no-install-recommends && rm -rf /var/lib/apt/lists/*

COPY ./docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh


EXPOSE 80
# CMD ["apache2-foreground"]
CMD ["start.sh"]
