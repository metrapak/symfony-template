FROM php:8.2-fpm-alpine

ARG HOST_UID=1000

COPY ./scripts/* /usr/local/bin/

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS shadow icu-dev \
    && apk add --no-cache bash \
      curl \
      wget \
      linux-headers \
      libpq-dev \
      icu-libs \
    && docker-php-ext-install pdo pdo_pgsql intl \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && usermod -u "${HOST_UID}" www-data \
    && groupmod -g "${HOST_UID}" www-data  \
    && docker-install-composer \
    && /usr/bin/install -m 0775 -o www-data -g www-data -d /app \
    && apk del .build-deps