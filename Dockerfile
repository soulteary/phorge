# syntax=docker/dockerfile:1
#
# Phorge (phorge-fork) 自建镜像
# ------------------------------------------------------------------
# Phorge 官方没有提供 Docker 镜像，本 Dockerfile 基于当前源码目录自建。
# 运行时依赖 arcanist（构建阶段从 GitHub 拉取）。
#
FROM php:8.3-apache

# ------------------------------------------------------------------
# 1. 系统依赖 & PHP 扩展
#    Phorge 需要: mysqli, gd, curl, mbstring, iconv, pcntl, posix, opcache
# ------------------------------------------------------------------
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git \
        mariadb-client \
        procps \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libcurl4-openssl-dev \
        libonig-dev \
        libzip-dev \
        default-libmysqlclient-dev \
        ncat \
    ; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        mysqli \
        gd \
        curl \
        mbstring \
        pcntl \
        posix \
        opcache \
        iconv \
    ; \
    rm -rf /var/lib/apt/lists/*

# ------------------------------------------------------------------
# 2. PHP 运行参数（Phorge setup 检查项）
# ------------------------------------------------------------------
RUN { \
        echo "opcache.enable=1"; \
        echo "opcache.validate_timestamps=0"; \
        echo "post_max_size=32M"; \
        echo "memory_limit=512M"; \
        echo "max_execution_time=0"; \
        echo "date.timezone=UTC"; \
    } > /usr/local/etc/php/conf.d/phorge.ini

# MySQL 8 默认启用自签名证书的 TLS，而 MariaDB 客户端会校验证书链并报错。
# 让容器内手动执行 `mariadb ...` 时默认跳过校验。
RUN printf '[client]\nskip-ssl\n' > /etc/mysql/conf.d/skip-ssl.cnf 2>/dev/null \
    || { mkdir -p /etc/mysql/conf.d && printf '[client]\nskip-ssl\n' > /etc/mysql/conf.d/skip-ssl.cnf; }

# ------------------------------------------------------------------
# 3. 拉取 arcanist（Phorge 运行时必需的外部依赖）
# ------------------------------------------------------------------
WORKDIR /opt/phorge
RUN git clone --depth 1 https://we.phorge.it/source/arcanist.git arcanist \
    || git clone --depth 1 https://github.com/phorgeit/arcanist.git arcanist

# ------------------------------------------------------------------
# 4. 拷贝 phorge 源码（当前构建上下文即 phorge-fork）
# ------------------------------------------------------------------
COPY . /opt/phorge/phorge
RUN chown -R www-data:www-data /opt/phorge

# ------------------------------------------------------------------
# 5. Apache 站点配置：DocumentRoot 指向 webroot，开启 PATH rewrite
# ------------------------------------------------------------------
RUN a2enmod rewrite
COPY docker/phorge-apache.conf /etc/apache2/sites-available/000-default.conf

# ------------------------------------------------------------------
# 6. 入口脚本
# ------------------------------------------------------------------
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
# aphlict (实时通知) 与 ssh 如需可另行开放
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
