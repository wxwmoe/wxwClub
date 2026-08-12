FROM php:8.4-cli-alpine
RUN docker-php-ext-install -j"$(nproc)" pcntl pdo_mysql
