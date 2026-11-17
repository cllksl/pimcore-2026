FROM pimcore/pimcore:php8.4-latest

USER root

WORKDIR /var/www/html

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_MEMORY_LIMIT=-1

COPY . .

RUN composer install \
    --no-interaction \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

RUN mkdir -p var/cache var/log public/var \
    && chown -R www-data:www-data /var/www/html

EXPOSE 9000

CMD ["php-fpm", "-F"]
