FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql pdo_sqlite \
    && a2enmod rewrite headers

COPY . /var/www/html/

RUN mkdir -p /var/www/html/storage \
    && chown -R www-data:www-data /var/www/html/storage \
    && sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html/public>\n    AllowOverride All\n</Directory>\n' >> /etc/apache2/apache2.conf

COPY render-entrypoint.sh /usr/local/bin/render-entrypoint.sh
RUN chmod +x /usr/local/bin/render-entrypoint.sh

EXPOSE 10000
CMD ["/usr/local/bin/render-entrypoint.sh"]
