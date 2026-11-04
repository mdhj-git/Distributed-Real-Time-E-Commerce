FROM php:8.3-fpm
RUN apt-get update \
 && apt-get install -y --no-install-recommends $PHPIZE_DEPS zlib1g-dev libssl-dev \
 && rm -rf /var/lib/apt/lists/*
ENV MAKEFLAGS="-j2" \
    CFLAGS="-O2 -g0" \
    CXXFLAGS="-O2 -g0"
RUN pecl install grpc-1.85.0 && docker-php-ext-enable grpc
