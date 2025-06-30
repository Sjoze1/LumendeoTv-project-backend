# Use official PHP with Apache image
FROM php:8.2-apache

# Set working directory
WORKDIR /var/www/html

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    curl \
    libpq-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql mbstring zip exif pcntl

# Install Composer from official composer image
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy Laravel project files
COPY . .

# TEMP: Copy .env.example to .env for build-time artisan commands
COPY .env.example .env

# Install PHP dependencies without dev packages, optimize autoloader
RUN composer install --no-dev --optimize-autoloader

# Ensure Laravel required directories exist
RUN mkdir -p storage/framework/views storage/framework/cache/data bootstrap/cache

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html/storage

# Laravel caching
RUN php artisan key:generate && \
    php artisan config:cache && \
    php artisan route:cache && \
    php artisan view:cache

# Configure Apache to serve Laravel from /public folder
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|g' /etc/apache2/sites-available/000-default.conf

# Expose port 80
EXPOSE 80

# Start Apache in the foreground
CMD ["apache2-foreground"]
