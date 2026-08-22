FROM php:8.2-fpm-alpine

ARG HOST_UID=1000

COPY ./scripts/* /usr/local/bin/

# GD is required, not optional: FR-062 and FR-071 resize uploaded photos and logos and generate
# thumbnails, and ImageResizer degrades to storing the original unresized without it. The -dev
# packages are build-only and go with .build-deps; the runtime libs stay.
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS shadow icu-dev \
      libpng-dev \
      libjpeg-turbo-dev \
      freetype-dev \
    && apk add --no-cache bash \
      curl \
      wget \
      linux-headers \
      libpq-dev \
      icu-libs \
      libpng \
      libjpeg-turbo \
      freetype \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql intl gd \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && usermod -u "${HOST_UID}" www-data \
    && groupmod -g "${HOST_UID}" www-data  \
    && docker-install-composer \
    && /usr/bin/install -m 0775 -o www-data -g www-data -d /app \
    && apk del .build-deps