FROM php:8.3-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libwebp-dev \
        libfreetype6-dev \
        default-mysql-client \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql zip gd bcmath opcache \
    && curl -fsSL https://github.com/phpredis/phpredis/archive/refs/tags/6.3.0.tar.gz -o /tmp/redis.tar.gz \
    && mkdir -p /usr/src/php/ext/redis \
    && tar -xzf /tmp/redis.tar.gz -C /usr/src/php/ext/redis --strip-components=1 \
    && rm /tmp/redis.tar.gz \
    && docker-php-ext-install -j"$(nproc)" redis \
    && apt-get purge -y libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/*

# gRPC extension — طبقة مستقلة، تعاد فقط عند تعديل هذا السطر بالذات
RUN apt-get update && apt-get install -y --no-install-recommends zlib1g-dev \
    && curl -fsSL https://pecl.php.net/get/grpc-1.62.0.tgz -o /tmp/grpc.tar.gz \
    && mkdir -p /usr/src/php/ext/grpc \
    && tar -xzf /tmp/grpc.tar.gz -C /usr/src/php/ext/grpc --strip-components=1 \
    && rm /tmp/grpc.tar.gz \
    && docker-php-ext-install -j"$(nproc)" grpc \
    && apt-get purge -y zlib1g-dev \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY php.ini /usr/local/etc/php/conf.d/app.ini
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]