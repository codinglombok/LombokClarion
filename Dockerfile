# LombokClarion — production image (FPM). Pair with nginx (see docker-compose.yml).
FROM php:8.3-fpm-alpine AS base
RUN apk add --no-cache postgresql-dev \
 && docker-php-ext-install pdo pdo_pgsql pdo_mysql opcache \
 && rm -rf /var/cache/apk/*
# Opcache tuned for compiled, read-only code (§5 cold-start)
RUN { \
  echo 'opcache.enable=1'; \
  echo 'opcache.validate_timestamps=0'; \
  echo 'opcache.preload_user=www-data'; \
  echo 'opcache.memory_consumption=128'; \
 } > /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www/app
COPY . .
# In a Packagist-connected build, replace the autoload shim:
#   RUN composer install --no-dev --optimize-autoloader
# Build step: compile container+config+assets BEFORE the image ships (§5).
RUN php bin/lombokclarion optimize \
 && chown -R www-data:www-data storage public/assets

USER www-data
EXPOSE 9000
CMD ["php-fpm"]

# ---------- Worker target: docker build --target worker ----------
FROM base AS worker
CMD ["php", "bin/lombokclarion", "work", "--loop", "--sleep=2"]

# ---------- Single-container HTTP target (Cloud Run / DO App Platform / quick demos) ----------
FROM base AS cloudrun
ENV PORT=8080
EXPOSE 8080
CMD ["sh","-c","php -S 0.0.0.0:${PORT} -t public public/index.php"]
