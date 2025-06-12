ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli-alpine

RUN apk add --no-cache \
    $PHPIZE_DEPS \
    git \
    unzip \
    curl-dev \
    icu-dev \
    libxml2-dev \
    oniguruma-dev

RUN docker-php-ext-install -j$(nproc) \
    curl \
    intl \
    mbstring \
    dom \
    xml \

RUN php -m | grep -i -E '^json$|^curl$|^tokenizer$' || \
    (echo "ПОМИЛКА: Розширення JSON, CURL або TOKENIZER відсутнє після спроби встановлення!" && \
     echo "Завантажені розширення:" && php -m && exit 1)

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock* ./

RUN #composer install --no-interaction --no-scripts --prefer-dist --optimize-autoloader --no-dev

COPY . .

CMD ["sh"]