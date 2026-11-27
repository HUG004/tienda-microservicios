FROM php:8.5-cli

# Dependencias del sistema.
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libonig-dev \
    libcurl4-openssl-dev \
    unzip \
    git \
    && docker-php-ext-install \
        pdo_pgsql \
        mbstring \
        curl \
    && rm -rf /var/lib/apt/lists/*

# Instalar Composer.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Instalar dependencias PHP.
COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --no-autoloader

# Copiar codigo del microservicio.
COPY . .

RUN composer dump-autoload \
    --optimize \
    --no-dev

# Configuracion PHP.
RUN printf "expose_php=Off\n\
display_errors=Off\n\
log_errors=On\n" \
    > /usr/local/etc/php/conf.d/99-api-pedidos.ini

# Permitir solicitudes concurrentes.
ENV PHP_CLI_SERVER_WORKERS=4

EXPOSE 8006

CMD ["php", "-S", "0.0.0.0:8006", "-t", "public", "public/index.php"]