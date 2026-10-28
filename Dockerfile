FROM php:8.2-apache

RUN a2enmod rewrite

COPY . /var/www/html/

EXPOSE 80

RUN docker-php-ext-install pdo pdo_mysql mysqli
