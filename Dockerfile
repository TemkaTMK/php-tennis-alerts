FROM composer:2 AS deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

FROM php:8.2-cli

RUN apt-get update && apt-get install -y supervisor && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --from=deps /app/vendor /app/vendor
COPY . /app

RUN mkdir -p /app/storage /app/storage/rate_limit \
    && chmod -R 775 /app/storage

COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

EXPOSE 8080

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
