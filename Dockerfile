FROM php:8.2-apache

# Install the missing MySQL database drivers
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Copy your project files to the web server directory
COPY . /var/www/html/

EXPOSE 80
