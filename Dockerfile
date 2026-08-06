FROM php:8.4-cli
RUN docker-php-ext-install -j"$(nproc)" pdo_mysql pcntl opcache
