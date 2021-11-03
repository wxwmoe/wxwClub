FROM php:7.3.32-cli-alpine

RUN apk add --no-cache --virtual .build-deps \
       autoconf \
       g++ \
       libtool \
       make \
       pcre-dev \
    && apk add --no-cache\
       freetype-dev \
       tzdata \
       unzip \
       git \
       libintl \
       icu \
       icu-dev \
       libxml2-dev \
    && docker-php-ext-configure opcache --enable-opcache \
    && docker-php-ext-install pdo_mysql opcache pcntl \
    && apk del .build-deps
