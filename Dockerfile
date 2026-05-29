FROM php:8.2-apache

# ── System dependencies ───────────────────────────────────────
RUN apt-get update && apt-get install -y \
    curl \
    unzip \
    git \
    zip \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    # Chromium and all its shared library dependencies
    chromium \
    chromium-sandbox \
    fonts-liberation \
    fonts-noto \
    fonts-noto-color-emoji \
    libatk-bridge2.0-0 \
    libatk1.0-0 \
    libcups2 \
    libdbus-1-3 \
    libdrm2 \
    libgbm1 \
    libgtk-3-0 \
    libnspr4 \
    libnss3 \
    libx11-xcb1 \
    libxcomposite1 \
    libxdamage1 \
    libxfixes3 \
    libxrandr2 \
    libxss1 \
    libxtst6 \
    xdg-utils \
    && rm -rf /var/lib/apt/lists/*

# ── PHP extensions ────────────────────────────────────────────
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        mbstring \
        xml \
        zip \
        gd \
        opcache \
        bcmath

# ── Node.js 20.x ──────────────────────────────────────────────
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# ── Tell Puppeteer to use system Chromium, not download its own ──
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium

# ── Install Puppeteer globally ────────────────────────────────
# This gives the node bridge script access to the puppeteer module
RUN npm install -g puppeteer@21.0.0 \
    && npm cache clean --force

# ── Composer ──────────────────────────────────────────────────
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# ── Apache config ─────────────────────────────────────────────
RUN a2enmod rewrite

RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# ── PHP config ────────────────────────────────────────────────
RUN echo "upload_max_filesize = 32M"          >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "post_max_size = 32M"             >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "memory_limit = 512M"             >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "max_execution_time = 120"        >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "opcache.enable=1"                >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "opcache.memory_consumption=128"  >> /usr/local/etc/php/conf.d/custom.ini

# ── App files ─────────────────────────────────────────────────
WORKDIR /var/www/html

COPY . .

# ── Composer install ──────────────────────────────────────────
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-progress

# ── Make browsershot.js executable ───────────────────────────
# This is the Node bridge script Spatie Browsershot calls
RUN chmod +x /var/www/html/browsershot.js

# ── Symlink node_modules so browsershot.js can find puppeteer ─
# Global npm installs go to /usr/lib/node_modules
RUN ln -sf /usr/lib/node_modules /var/www/html/node_modules || true

# ── Storage directories ───────────────────────────────────────
RUN mkdir -p \
    storage/tickets \
    storage/qrcodes \
    storage/tickets \
    storage/banners \
    && chown -R www-data:www-data storage \
    && chmod -R 775 storage

# ── Final permissions ─────────────────────────────────────────
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# ── Environment ───────────────────────────────────────────────
ENV CHROMIUM_PATH=/usr/bin/chromium \
    NODE_PATH=/usr/bin/node \
    NPM_PATH=/usr/bin/npm \
    APACHE_DOCUMENT_ROOT=/var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
