# Stage 1: Build Frontend Assets
FROM node:20-alpine AS node_builder
WORKDIR /app
COPY package*.json ./
RUN npm install
COPY . .
RUN npm run build

# Stage 2: PHP & Nginx Application
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

# Copy kode aplikasi & hasil build frontend
COPY . .
COPY --from=node_builder /app/public/build /var/www/html/public/build

# Generate autoloader
RUN composer dump-autoload --optimize --no-dev

# Set permissions storage dan bootstrap cache
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80

CMD ["sh", "-c", "php-fpm -D && nginx -g 'daemon off;'"]