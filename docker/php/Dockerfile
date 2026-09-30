FROM php:8.3-fpm

# System libraries: GD needs freetype/jpeg/png/webp; intl needs ICU (required by Filament).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        curl \
        unzip \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev \
        libicu-dev \
        libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring zip exif gd intl opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Stable temp dir for PHP/Livewire uploads (see docs/reference-3dprint/09-troubleshooting.md).
RUN mkdir -p /var/www/html/storage/tmp \
    && chown -R www-data:www-data /var/www/html/storage

# Upload/processing limits sized for full-resolution photos (≈ 64 MB, 24+ Mpx resize in GD).
RUN { \
    echo 'log_errors=On'; \
    echo 'display_errors=Off'; \
    echo 'sys_temp_dir=/var/www/html/storage/tmp'; \
    echo 'upload_tmp_dir=/var/www/html/storage/tmp'; \
    echo 'upload_max_filesize=64M'; \
    echo 'post_max_size=64M'; \
    echo 'memory_limit=512M'; \
    echo 'max_execution_time=300'; \
    echo 'max_input_time=300'; \
} > /usr/local/etc/php/conf.d/99-photo.ini
