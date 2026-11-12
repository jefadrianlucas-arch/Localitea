
FROM php:8.3-apache

# Install PHP extensions for MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Remove conflicting Apache MPM modules
RUN a2dismod mpm_event || true
RUN a2dismod mpm_worker || true

# Remove all enabled MPM configuration links
RUN rm -f /etc/apache2/mods-enabled/mpm_*.load \
          /etc/apache2/mods-enabled/mpm_*.conf

# Enable only prefork and URL rewriting
RUN a2enmod mpm_prefork rewrite

# Copy Local Milktea House source code
COPY . /var/www/html/

# Set application permissions
RUN chown -R www-data:www-data /var/www/html

# Verify that exactly one MPM is loaded
RUN set -eux; \
    apache2ctl -M 2>&1; \
    test "$(apache2ctl -M 2>&1 | grep -Ec 'mpm_(event|worker|prefork)_module')" -eq 1; \
    apache2ctl -t

EXPOSE 80
