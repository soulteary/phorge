# syntax=docker/dockerfile:1
#
# Phorge (phorge-fork) 自建镜像
# ------------------------------------------------------------------
# Phorge 官方没有提供 Docker 镜像，本 Dockerfile 基于当前源码目录自建。
# PHP 兼容运行库随源码交付，构建无需独立 Arcanist checkout。
#
ARG PHP_BASE_IMAGE=php:8.3-apache
FROM ${PHP_BASE_IMAGE}
ARG APCU_VERSION=5.1.28
ARG DEBIAN_SNAPSHOT=
# Release builds freeze package repositories as well as the base image.
RUN if [ -n "$DEBIAN_SNAPSHOT" ]; then \
      sed -i "s|http://deb.debian.org/debian-security|https://snapshot.debian.org/archive/debian-security/${DEBIAN_SNAPSHOT}|g; s|http://deb.debian.org/debian|https://snapshot.debian.org/archive/debian/${DEBIAN_SNAPSHOT}|g" /etc/apt/sources.list.d/debian.sources; \
      printf 'Acquire::Check-Valid-Until "false";\n' > /etc/apt/apt.conf.d/99snapshot; \
    fi

# ------------------------------------------------------------------
# 1. 系统依赖 & PHP 扩展
#    Phorge 需要: mysqli, gd, curl, mbstring, iconv, pcntl, posix, opcache
#    可选但会被 setup check 检查: zip (PhabricatorZipSetupCheck / Excel 导出)
#    运行时保留的命令行工具: git (维护工具)、mariadb-client (排障)、procps (phd)、
#    util-linux (flock 用于串行化共享 local.json 的配置任务)
# ------------------------------------------------------------------
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git \
        mariadb-client \
        procps \
        util-linux \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libcurl4-openssl-dev \
        libonig-dev \
        libzip-dev \
        default-libmysqlclient-dev \
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
        zip \
    ; \
# 回收 *-dev 构建依赖：先把包全部标记为「自动安装」，再把运行时确实需要的标回
# manual —— 原先就是 manual 的（含基础镜像的 PHPIZE_DEPS）、上面装的命令行工具、
# 以及扩展 .so 实际链接到的共享库，剩下的由 autoremove 清掉。
# 做法与 php / wordpress 官方镜像一致。
# 如需进一步瘦身，可额外 purge $PHPIZE_DEPS（gcc/g++ 等），代价是镜像内不能再
# 执行 docker-php-ext-install / pecl install。
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark > /dev/null; \
    apt-mark manual git mariadb-client procps util-linux > /dev/null; \
    ldd "$(php -r 'echo ini_get("extension_dir");')"/*.so \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*/%s\n", so }' \
        | sort -u \
        | xargs -r dpkg-query --search \
        | awk -F: '{ print $1 }' \
        | sort -u \
        | xargs -r apt-mark manual > /dev/null; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*

# APCu 缓存扩展
ARG APCU_SHA256=ca9c1820810a168786f8048a4c3f8c9e3fd941407ad1553259fb2e30b5f057bf
RUN curl -fsSL --retry 3 --max-time 60 "https://pecl.php.net/get/apcu-${APCU_VERSION}.tgz" -o /tmp/apcu.tgz \
    && echo "${APCU_SHA256}  /tmp/apcu.tgz" | sha256sum -c - \
    && pecl install /tmp/apcu.tgz \
    && docker-php-ext-enable apcu \
    && rm /tmp/apcu.tgz

# ------------------------------------------------------------------
# 2. PHP 运行参数（Phorge setup 检查项）
#    conf.d 下的 *.ini 按文件名字典序加载，phorge-* 排在基础镜像的
#    docker-php-ext-*.ini 之后，因此可以安全覆盖扩展默认值。
#    单项覆盖：挂载同名文件到 /usr/local/etc/php/conf.d/ 即可。
# ------------------------------------------------------------------
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/phorge-opcache.ini
COPY docker/php/memory.ini  /usr/local/etc/php/conf.d/phorge-memory.ini
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/phorge-uploads.ini

# MySQL 8 默认启用自签名证书的 TLS，而 MariaDB 客户端会校验证书链并报错。
# 让容器内手动执行 `mariadb ...` 时默认跳过校验。
RUN set -eux; \
    mkdir -p /etc/mysql/conf.d; \
    printf '[client]\nskip-ssl\n' > /etc/mysql/conf.d/skip-ssl.cnf

# ------------------------------------------------------------------
# 3. 使用当前源码内的 support/runtime PHP 兼容运行库
# ------------------------------------------------------------------
WORKDIR /opt/phorge

# ------------------------------------------------------------------
# 4. Apache 站点配置：DocumentRoot 指向 webroot，开启 PATH rewrite
# ------------------------------------------------------------------
RUN a2enmod rewrite
COPY docker/phorge-apache.conf /etc/apache2/sites-available/000-default.conf

# ------------------------------------------------------------------
# 5. 入口脚本
# ------------------------------------------------------------------
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY --chmod=0755 docker/phd-foreground.sh /usr/local/bin/phd-foreground

# ------------------------------------------------------------------
# 6. 拷贝 phorge 源码（当前构建上下文即 phorge-fork）
#    COPY --chown 直接落属主，避免再来一层与源码等大的 chown -R。
# ------------------------------------------------------------------
COPY --chown=www-data:www-data . /opt/phorge/phorge
# Build parser dependencies explicitly; web and CLI requests never download them.
RUN php /opt/phorge/phorge/support/runtime/support/xhpast/build-xhpast.php \
    && php /opt/phorge/phorge/support/runtime/support/php-parser/build-php-parser.php \
    && php /opt/phorge/phorge/scripts/runtime/verify.php

# ------------------------------------------------------------------
# 7. 预建运行时可写目录并设置属主
#    这些路径都会被命名卷覆盖；镜像内先建好并 chown，Docker 在初始化空卷时
#    会沿用镜像里的属主与权限，否则卷会被建成 root:root，Apache / phd
#    (均以 www-data 运行) 写不进去。
#      /var/repo          -> repository.default-local-path 默认值
#      /var/tmp/phd/log   -> phd.log-directory 默认值（phd 的控制目录也在其下）
#      /var/tmp/phd/pid   -> 旧版 phd.pid-directory；当前版本已不再写 PID 文件，
#                            这里一并建好，便于挂卷/降级时不出现属主问题
#      conf/local         -> entrypoint 生成 local.json 的位置
# ------------------------------------------------------------------
RUN set -eux; \
    mkdir -p \
        /var/repo \
        /var/tmp/phd/log \
        /var/tmp/phd/pid \
        /opt/phorge/phorge/conf/local \
    ; \
    chown -R www-data:www-data \
        /var/repo \
        /var/tmp/phd \
        /opt/phorge/phorge/conf

EXPOSE 80
# aphlict (实时通知) 与 ssh 如需可另行开放
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
