FROM php:8.4-apache

# Habilitar mod_rewrite para que funcionen las rutas de Laravel
RUN a2enmod rewrite

# Cambiar el DocumentRoot de Apache a la carpeta /public de Laravel
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Instalar dependencias del sistema
RUN apt-get update && apt-get install -y \
    libpng-dev libonig-dev libxml2-dev zip curl unzip git libzip-dev \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiamos el código
COPY . .

# Instalamos dependencias
RUN composer install --no-interaction --no-plugins --no-scripts --prefer-dist

# Asignamos permisos
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache

# Apache ya se encarga de iniciar y escuchar en el puerto 80 nativamente por defecto.