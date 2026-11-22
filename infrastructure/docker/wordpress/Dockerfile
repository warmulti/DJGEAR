ARG WP_IMAGE_TAG=7.1.5-php8.3-fpm
FROM wordpress:${WP_IMAGE_TAG}

# Install system dependencies for WooCommerce extensions, WP-CLI DB tools, and PHP-FPM healthchecks
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libxml2-dev \
        libfcgi-bin \
        default-mysql-client \
        less \
        unzip \
    ; \
    docker-php-ext-install -j"$(nproc)" soap pdo_mysql; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false libxml2-dev; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

# Install WP-CLI for reproducible WordPress & WooCommerce management
ENV WP_CLI_CACHE_DIR=/tmp/wp-cli-cache
RUN set -eux; \
    curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp; \
    chmod +x /usr/local/bin/wp; \
    mkdir -p /etc/mysql/conf.d /tmp/wp-cli-cache; \
    chmod 1777 /tmp/wp-cli-cache; \
    printf '[client]\nssl-verify-server-cert = 0\n' > /etc/mysql/conf.d/zz-djgear-client.cnf; \
    wp --info --allow-root

# Copy custom PHP & PHP-FPM configuration
COPY php.ini /usr/local/etc/php/conf.d/99-djgear.ini
COPY zz-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-djgear.conf

WORKDIR /var/www/html
