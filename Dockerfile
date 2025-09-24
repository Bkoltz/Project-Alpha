FROM php:8.2-apache
COPY ./public/ /var/www/html/
RUN docker-php-ext-install mysqli