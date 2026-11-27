FROM php:8.4-cli-alpine
WORKDIR /app
COPY public ./public
EXPOSE 8002
CMD ["php", "-S", "0.0.0.0:8002", "-t", "public", "public/index.php"]