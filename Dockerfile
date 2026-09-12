# PHP & Nginx Application
# Frontend sudah statis (public/app/), tidak perlu build node/vite.
FROM php:8.3-fpm-alpine
WORKDIR /var/www/html

# Install package sistem & ekstensi PHP
RUN apk add --no-cache \
    nginx \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    curl \
    tzdata

ENV TZ=Asia/Jakarta

# Install driver MySQL, GD, Zip, dll
RUN docker-php-ext-install pdo pdo_mysql mysqli gd zip bcmath opcache

# Copy Composer binary
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy konfigurasi VirtualHost Nginx
COPY docker-nginx.conf /etc/nginx/http.d/default.conf

# Cache dependencies layer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Copy kode aplikasi (frontend statis ada di public/app/, ikut ke-copy)
COPY . .

# Generate autoloader
RUN composer dump-autoload --optimize --no-dev

# Set permissions storage dan bootstrap cache
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80

CMD ["sh", "-c", "php-fpm -D && nginx -g 'daemon off;'"]