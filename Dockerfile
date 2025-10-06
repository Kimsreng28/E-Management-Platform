# Laravel 12 Backend Dockerfile
FROM php:8.2-fpm

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    libpq-dev \
    postgresql-client \
    nginx \
    supervisor

# Install PHP extensions
RUN docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql && \
    docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    bcmath \
    ctype \
    fileinfo \
    gd \
    mbstring \
    tokenizer \
    xml \
    zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy application
COPY . .

# Set permissions
RUN chown -R www-data:www-data storage bootstrap/cache

# Install dependencies
RUN composer install --optimize-autoloader --no-dev

# Copy configuration files
COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Expose port 9000
EXPOSE 9000

# Start PHP-FPM
CMD ["php-fpm"]
