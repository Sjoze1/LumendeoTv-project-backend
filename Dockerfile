# Use official PHP 8.1 with FPM
FROM php:8.1-fpm

# Install system dependencies and PHP extensions needed by Laravel
RUN apt-get update && apt-get install -y \
    git \
    zip \
    unzip \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# Install Composer globally
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory inside container
WORKDIR /var/www/html

# Copy project files to container
COPY . .

# Install PHP dependencies without dev packages for production
RUN composer install --no-dev --optimize-autoloader

# Generate Laravel application key (optional, you can do this at runtime or use env var)
RUN php artisan key:generate

# Expose port 8000 to the outside of the container
EXPOSE 8000

# Start Laravel built-in server listening on all interfaces and port 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
