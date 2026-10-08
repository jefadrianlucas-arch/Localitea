FROM php:8.3-apache

# Install MySQL PDO extension
RUN docker-php-ext-install pdo pdo_mysql

# Make sure only Apache's prefork MPM is enabled
RUN a2dismod mpm_event mpm_worker mpm_prefork || true \
    && a2enmod mpm_prefork rewrite

# Copy Localitea files into Apache
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80