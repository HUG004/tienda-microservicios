FROM php:8.4-cli-alpine

RUN apk add --no-cache postgresql-libs \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install pdo_pgsql \
    && apk del .build-deps

WORKDIR /app

COPY public ./public

EXPOSE 8002

CMD ["php", "-S", "0.0.0.0:8002", "-t", "public", "public/index.php"]