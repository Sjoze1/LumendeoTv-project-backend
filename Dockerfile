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

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy Laravel files to container
COPY . .

# 🔥 TEMPORARY: Copy .env.example as .env for build-time artisan commands
COPY .env.example .env

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Generate app key and cache config, routes, views
RUN php artisan key:generate && \
    php artisan config:cache && \
    php artisan route:cache && \
    php artisan view:cache

# Set permissions for Laravel
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage

# Configure Apache to serve Laravel from /public
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|g' /etc/apache2/sites-available/000-default.conf

# Expose port 80 to Render
EXPOSE 80

# Start Apache in the foreground
CMD ["apache2-foreground"]
