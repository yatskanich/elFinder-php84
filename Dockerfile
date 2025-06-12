# Змінено на стабільну версію (якщо вона доступна, інакше поверніть -rc)
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli-alpine

# Встановлюємо системні залежності
RUN apk add --no-cache \
    $PHPIZE_DEPS \
    git \
    unzip \
    # Залежності для curl
    curl-dev \
    # Залежності для intl
    icu-dev \
    # Залежності для dom, xml
    libxml2-dev \
    # Залежності для mbstring
    oniguruma-dev \
    # Залежності для GD
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    zlib-dev \
    libwebp-dev

# Конфігуруємо та встановлюємо PHP розширення
RUN docker-php-ext-configure gd \
      --with-freetype \
      --with-jpeg \
      # --with-png  <-- Цю опцію прибрано, оскільки вона не розпізнається
      --with-webp && \
    docker-php-ext-install -j$(nproc) \
      gd \
      curl \
      intl \
      mbstring \
      dom \
      xml

# Перевірка, чи дійсно необхідні розширення завантажені
# Додано gd до перевірки
RUN php -m | grep -i -E '^json$|^curl$|^tokenizer$|^gd$' || \
    (echo "ПОМИЛКА: Одне з розширень (JSON, CURL, Tokenizer, GD) відсутнє!" && \
     echo "Завантажені розширення:" && php -m && exit 1) && \
    php -r "if (!function_exists('gd_info')) { echo 'ПОМИЛКА: Функція gd_info() не знайдена!\n'; exit(1); } \$gdInfo = gd_info(); if (empty(\$gdInfo['PNG Support'])) { echo 'ПОМИЛКА: GD зібрано без підтримки PNG!\n'; print_r(\$gdInfo); exit(1); } echo 'GD PNG Support: Enabled\n';"


COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock* ./

# composer install поки закоментований, як у вашому прикладі
# RUN composer install --no-interaction --no-scripts --prefer-dist --optimize-autoloader --no-dev

COPY . .

CMD ["sh"]