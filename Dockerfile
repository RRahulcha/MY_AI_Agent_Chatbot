FROM php:8.2-apache

# Install Linux packages & PHP extensions required for SETTribe AMS
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libcurl4-openssl-dev \
    zip \
    unzip \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) mysqli pdo_mysql gd zip curl bcmath \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache modules (rewrite for routing/.htaccess, headers for CORS/security)
RUN a2enmod rewrite headers

# PHP runtime configurations
RUN { \
    echo 'upload_max_filesize = 64M'; \
    echo 'post_max_size = 64M'; \
    echo 'memory_limit = 256M'; \
    echo 'max_execution_time = 120'; \
    echo 'date.timezone = Asia/Kolkata'; \
    echo 'session.cookie_httponly = 1'; \
} > /usr/local/etc/php/conf.d/ams-custom.ini

# Enable AllowOverride All in Apache
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html

# Copy codebase
COPY . /var/www/html/

# Permissions for uploads and photos
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/profile-photos /var/www/html/uploads 2>/dev/null || true

EXPOSE 80

CMD ["apache2-foreground"]
