FROM pimcore/pimcore:php8.4-latest

USER root

WORKDIR /var/www/html

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_MEMORY_LIMIT=-1

RUN apt-get update \
    && apt-get install -y nginx \
    && rm -rf /var/lib/apt/lists/*

COPY . .

RUN composer install \
    --no-interaction \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

RUN rm -f /etc/nginx/sites-enabled/default

COPY nginx.conf /etc/nginx/conf.d/pimcore.conf

RUN mkdir -p var/cache var/log public/var \
    && chown -R www-data:www-data /var/www/html \
    && chmod +x /var/www/html/entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/var/www/html/entrypoint.sh"]
