# 1. Use the official PHP 8.2 image with Apache pre-installed
FROM php:8.2-apache

# 2. Change Apache's Document Root to point to Laravel's public folder
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# 3. Enable Apache mod_rewrite for Laravel pretty URLs
RUN a2enmod rewrite

# 4. Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev

# 5. Clear package manager cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# 6. Install required PHP extensions for Laravel
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# 7. Get the latest Composer binary
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 8. Set the working directory inside the container
WORKDIR /var/www/html

# 9. Copy your Laravel files
COPY . /var/www/html

# 10. Grant permissions to Apache's user (www-data)
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
