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

# Keep a copy of the uploads committed to git. The Railway volume mounted at
# assets/uploads hides them, so the entrypoint copies them into the volume once.
RUN mkdir -p /var/www/html/assets/uploads \
    && cp -a /var/www/html/assets/uploads /usr/local/share/uploads-seed

# Start-up script (kept outside the web root).
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN rm -f /var/www/html/docker-entrypoint.sh \
    && sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

# Block direct URL access to PWD / Senior ID photos (admin/view-discount-id.php
# still reads them from disk). Does not rely on .htaccess being honoured.
RUN printf '<Directory "/var/www/html/assets/uploads/discount-ids">\n    Require all denied\n</Directory>\n' \
        > /etc/apache2/conf-available/localitea-private.conf \
    && a2enconf localitea-private

# Set application permissions
RUN chown -R www-data:www-data /var/www/html

# Verify that exactly one MPM is loaded
RUN set -eux; \
    apache2ctl -M 2>&1; \
    test "$(apache2ctl -M 2>&1 | grep -Ec 'mpm_(event|worker|prefork)_module')" -eq 1; \
    apache2ctl -t

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]