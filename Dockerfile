
FROM php:8.3-apache

# Install PHP extensions required by Localitea
RUN docker-php-ext-install pdo pdo_mysql

# Ensure Apache uses only one MPM
RUN a2dismod mpm_event mpm_worker || true
RUN a2enmod mpm_prefork rewrite

# Copy the project files
COPY . /var/www/html/

# Set application directory permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
