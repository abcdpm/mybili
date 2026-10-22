# =====================================================================
# Mybili 业务镜像（多架构自包含构建）
#
# 一键构建: ./build-and-push.sh（支持 linux/amd64 + linux/arm64）
# 手动构建: docker buildx build --platform linux/amd64,linux/arm64 -t mybili:1 .
#
# 本文件已内联基础镜像的全部阶段（工具链下载 + PHP 运行时，源自 Dockerfile.base），
# 因此 buildx 会为每个目标平台独立编译各自的工具链与 PHP 扩展，
# 从根本上解决跨架构构建问题（旧版 FROM mybili-base:1 依赖宿主机单架构本地镜像，
# 多平台构建时无法解析或架构不匹配）。
#
# 常用构建参数:
#   APP_VERSION       程序版本号（系统信息页显示 + 镜像标签）
#   PHP_IMAGE         PHP 基础镜像（默认 phpswoole/swoole:php8.5-alpine = PHP 8.5 系，
#                     可覆盖为 php:8.5.12-cli-alpine 等精确锁定版本）
#   APT_MIRROR / APK_MIRROR / NPM_REGISTRY / COMPOSER_MIRROR   国内镜像源
#   HTTP_PROXY / HTTPS_PROXY / NO_PROXY                          构建代理
# =====================================================================

# === 基础阶段 1/2: 工具链下载（按目标架构下载对应二进制） ===
ARG NODE_VERSION=22
ARG PHP_IMAGE=phpswoole/swoole:php8.5-alpine
FROM node:${NODE_VERSION}-bookworm-slim AS downloader

WORKDIR /downloads

# 定义参数
ARG TARGETPLATFORM
ARG TARGETARCH

# 本地离线包（./fetch-packages.sh 预取；目录为空时以下步骤自动回退在线下载）
COPY docker-packages/tools/ /tmp/mybili-pkgs/tools/
COPY docker-packages/debian/${TARGETARCH}/ /tmp/mybili-pkgs/debs/

# 安装下载工具（优先本地离线包，缺失/失败自动回退在线安装）
# 可选 --build-arg APT_MIRROR=mirrors.tuna.tsinghua.edu.cn 切换国内 apt 源（国内网络必开）
ARG APT_MIRROR=
RUN if [ -n "$APT_MIRROR" ]; then \
        sed -i "s|deb.debian.org|${APT_MIRROR}|g" /etc/apt/sources.list.d/debian.sources; \
    fi; \
    if ls /tmp/mybili-pkgs/debs/*.deb >/dev/null 2>&1; then \
        echo "==> [offline] 使用本地 Debian 离线包安装"; \
        apt-get install -y --no-download /tmp/mybili-pkgs/debs/*.deb || { \
            echo "==> [fallback] 离线安装失败，回退在线安装"; \
            apt-get -o Acquire::http::Timeout=20 -o Acquire::Retries=2 update && \
            apt-get -o Acquire::http::Timeout=20 -o Acquire::Retries=2 install -y xz-utils curl wget tar; \
        }; \
    else \
        echo "==> [online] 本地离线包缺失，走在线安装（可运行 ./fetch-packages.sh 预取加速）"; \
        apt-get -o Acquire::http::Timeout=20 -o Acquire::Retries=2 update && \
        apt-get -o Acquire::http::Timeout=20 -o Acquire::Retries=2 install -y xz-utils curl wget tar; \
    fi

# 1. 下载 FFMPEG（优先本地离线包）
RUN PLATFORM="${TARGETPLATFORM:-linux/$(uname -m)}" && \
    case "${PLATFORM}" in \
        "linux/amd64"|"linux/x86_64") FFMPEG_URL="https://www.johnvansickle.com/ffmpeg/old-releases/ffmpeg-6.0.1-amd64-static.tar.xz" ;; \
        "linux/arm64"|"linux/aarch64") FFMPEG_URL="https://www.johnvansickle.com/ffmpeg/old-releases/ffmpeg-6.0.1-arm64-static.tar.xz" ;; \
        *) echo "Unsupported platform: ${PLATFORM}" && exit 1 ;; \
    esac && \
    if [ -f "/tmp/mybili-pkgs/tools/${FFMPEG_URL##*/}" ]; then \
        echo "==> [offline] ${FFMPEG_URL##*/}"; cp "/tmp/mybili-pkgs/tools/${FFMPEG_URL##*/}" ffmpeg.tar.xz; \
    else \
        echo "==> [online] 下载 ${FFMPEG_URL##*/}"; curl -L ${FFMPEG_URL} -o ffmpeg.tar.xz; \
    fi && \
    xz -d ffmpeg.tar.xz && \
    tar -xf ffmpeg.tar --strip-components=1 && \
    chmod +x ffmpeg ffprobe

# 2. 下载 yt-dlp
RUN PLATFORM="${TARGETPLATFORM:-linux/$(uname -m)}" && \
    case "${PLATFORM}" in \
        "linux/amd64"|"linux/x86_64") YT_DLP_ARCH="yt-dlp_musllinux" ;; \
        "linux/arm64"|"linux/aarch64") YT_DLP_ARCH="yt-dlp_musllinux_aarch64" ;; \
        *) echo "Unsupported platform: ${PLATFORM}" && exit 1 ;; \
    esac && \
    if [ -f "/tmp/mybili-pkgs/tools/${YT_DLP_ARCH}" ]; then \
        echo "==> [offline] ${YT_DLP_ARCH}"; cp "/tmp/mybili-pkgs/tools/${YT_DLP_ARCH}" yt-dlp_linux; \
    else \
        echo "==> [online] 下载 ${YT_DLP_ARCH}"; wget -O yt-dlp_linux "https://github.com/yt-dlp/yt-dlp/releases/latest/download/${YT_DLP_ARCH}"; \
    fi && \
    chmod 775 yt-dlp_linux

# 3. 下载 FrankenPHP
RUN PLATFORM="${TARGETPLATFORM:-linux/$(uname -m)}" && \
    case "${PLATFORM}" in \
        "linux/amd64"|"linux/x86_64") FRANKENPHP_ARCH="frankenphp-linux-x86_64" ;; \
        "linux/arm64"|"linux/aarch64") FRANKENPHP_ARCH="frankenphp-linux-aarch64" ;; \
        *) echo "Unsupported platform: ${PLATFORM}" && exit 1 ;; \
    esac && \
    if [ -f "/tmp/mybili-pkgs/tools/${FRANKENPHP_ARCH}" ]; then \
        echo "==> [offline] ${FRANKENPHP_ARCH}"; cp "/tmp/mybili-pkgs/tools/${FRANKENPHP_ARCH}" frankenphp; \
    else \
        echo "==> [online] 下载 ${FRANKENPHP_ARCH}"; wget -O frankenphp "https://github.com/dunglas/frankenphp/releases/download/v1.2.5/${FRANKENPHP_ARCH}"; \
    fi && \
    chmod 775 frankenphp

# 4. 下载 Supervisord
RUN PLATFORM="${TARGETPLATFORM:-linux/$(uname -m)}" && \
    case "${PLATFORM}" in \
        "linux/amd64"|"linux/x86_64") SUPERVISORD_URL="https://github.com/ochinchina/supervisord/releases/download/v0.7.3/supervisord_0.7.3_Linux_64-bit.tar.gz" ;; \
        "linux/arm64"|"linux/aarch64") SUPERVISORD_URL="https://github.com/ochinchina/supervisord/releases/download/v0.7.3/supervisord_0.7.3_Linux_ARM64.tar.gz" ;; \
        *) echo "Unsupported platform: ${PLATFORM}" && exit 1 ;; \
    esac && \
    if [ -f "/tmp/mybili-pkgs/tools/${SUPERVISORD_URL##*/}" ]; then \
        echo "==> [offline] ${SUPERVISORD_URL##*/}"; cp "/tmp/mybili-pkgs/tools/${SUPERVISORD_URL##*/}" supervisord.tar.gz; \
    else \
        echo "==> [online] 下载 ${SUPERVISORD_URL##*/}"; curl -L ${SUPERVISORD_URL} -o supervisord.tar.gz; \
    fi && \
    tar -xzf supervisord.tar.gz --strip-components=1 && \
    chmod +x supervisord

# === 基础阶段 2/2: PHP 8.5 运行时（Alpine） ===
FROM ${PHP_IMAGE} AS runtime

ENV PHPRC=/etc/php.ini
ENV COMPOSER_ALLOW_SUPERUSER=1

# 1. 安装系统依赖库 (Alpine，优先本地离线包)
# libpng, libjpeg-turbo, libwebp, freetype 是 GD 库的底层依赖
# 可选 --build-arg APK_MIRROR=mirrors.tuna.tsinghua.edu.cn 切换国内 apk 源
ARG APK_MIRROR=
ARG TARGETARCH
COPY docker-packages/alpine/${TARGETARCH}/ /tmp/mybili-pkgs/apks/
RUN if [ -n "$APK_MIRROR" ]; then \
        sed -i "s|dl-cdn.alpinelinux.org|${APK_MIRROR}|g" /etc/apk/repositories; \
    fi; \
    if ls /tmp/mybili-pkgs/apks/*.apk >/dev/null 2>&1; then \
        echo "==> [offline] 使用本地 Alpine 离线包"; \
        apk add --no-cache --allow-untrusted /tmp/mybili-pkgs/apks/*.apk || { \
            echo "==> [fallback] 离线安装失败，回退在线安装"; \
            apk add --no-cache \
            libpng libpng-dev \
            libjpeg-turbo libjpeg-turbo-dev \
            libwebp libwebp-dev \
            freetype freetype-dev \
            libavif libavif-dev; \
        }; \
    else \
        echo "==> [online] 本地离线包缺失，走在线安装（可运行 ./fetch-packages.sh 预取加速）"; \
        apk add --no-cache \
        libpng libpng-dev \
        libjpeg-turbo libjpeg-turbo-dev \
        libwebp libwebp-dev \
        freetype freetype-dev \
        libavif libavif-dev; \
    fi

# 安装 Composer
COPY --from=composer/composer:2-bin /composer /usr/bin/composer

# 2. 配置并安装 PHP 扩展
# 优先复用 fetch-packages.sh 预编译的 gd/pcntl（秒级），并做三重校验（架构/PHP 版本/扩展API目录）
# 与冒烟测试；任何一项不通过自动回退源码编译，绝不因此构建失败。
COPY docker-packages/php-ext/${TARGETARCH}/ /tmp/mybili-pkgs/php-ext/
RUN set -e; \
    EXT_DIR=$(php -r 'echo ini_get("extension_dir");'); \
    if [ -f /tmp/mybili-pkgs/php-ext/gd.so ] && [ -f /tmp/mybili-pkgs/php-ext/pcntl.so ] \
       && [ "$EXT_DIR" = "$(cat /tmp/mybili-pkgs/php-ext/.ext-dir 2>/dev/null)" ] \
       && php -r 'exit(version_compare(PHP_VERSION, trim(@file_get_contents("/tmp/mybili-pkgs/php-ext/.php-version")), "=") ? 0 : 1);' \
       && cp /tmp/mybili-pkgs/php-ext/gd.so /tmp/mybili-pkgs/php-ext/pcntl.so "$EXT_DIR/" \
       && docker-php-ext-enable gd pcntl >/dev/null 2>&1 \
       && php -m | grep -qx 'gd' && php -m | grep -qx 'pcntl'; then \
        echo "==> [offline] 复用预编译 PHP 扩展（gd/pcntl），跳过源码编译"; \
    else \
        echo "==> [compile] 预编译扩展缺失/校验失败，回退源码编译（可运行 ./fetch-packages.sh --target php-ext 预编译加速）"; \
        rm -f "$EXT_DIR/gd.so" "$EXT_DIR/pcntl.so" /usr/local/etc/php/conf.d/docker-php-ext-gd.ini /usr/local/etc/php/conf.d/docker-php-ext-pcntl.ini; \
        docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-avif \
        && docker-php-ext-install -j$(nproc) gd pcntl; \
    fi

# 从下载阶段复制二进制文件到系统路径（各平台自动使用对应架构的产物）
COPY --from=downloader /downloads/ffmpeg /usr/local/bin/ffmpeg
COPY --from=downloader /downloads/ffprobe /usr/local/bin/ffprobe
COPY --from=downloader /downloads/yt-dlp_linux /usr/local/bin/yt-dlp_linux
COPY --from=downloader /downloads/frankenphp /usr/local/bin/frankenphp
COPY --from=downloader /downloads/supervisord /usr/local/bin/supervisord

# 赋予正确的权限
RUN chown root:root /usr/local/bin/yt-dlp_linux /usr/local/bin/frankenphp

# === 阶段: 前端构建 ===
FROM node:${NODE_VERSION}-bookworm-slim AS build

WORKDIR /app

# 可选 --build-arg NPM_REGISTRY=https://registry.npmmirror.com 切换国内 npm 源
ARG NPM_REGISTRY=

# 本地 pnpm 依赖 store（./fetch-packages.sh 预热；为空时 --prefer-offline 自动在线补全）
COPY docker-packages/npm-store/ /pnpm-store/

# 安装 pnpm
RUN if [ -n "$NPM_REGISTRY" ]; then \
        npm config set registry "$NPM_REGISTRY"; \
    fi \
    && npm install -g pnpm@latest-11

# [优化核心] 先只复制依赖描述文件，利用缓存
# 注意必须包含 pnpm-workspace.yaml：其中的 allowBuilds 白名单（esbuild 等构建脚本）
# 缺失时 pnpm 11 严格模式会报 ERR_PNPM_IGNORED_BUILDS 导致 install 失败
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./

# 安装依赖 (如果 package.json 没变，这一步会直接使用缓存)
# --prefer-offline: 本地 store（预热于 /pnpm-store）命中即离线，缺失增量在线补全
RUN pnpm install --frozen-lockfile --prefer-offline --store-dir /pnpm-store

# 再复制其余源代码
COPY . .

# 编译前端
RUN pnpm build

# === 阶段: 业务镜像 ===
# 基于上面内联的 runtime 基础阶段（多架构构建时各平台独立生成）
FROM runtime

WORKDIR /app

# 重新声明 ARG
ARG APP_VERSION=1.0.0
ARG WEBSITE_ID
# 可选 --build-arg COMPOSER_MIRROR=https://mirrors.aliyun.com/composer/ 切换国内 Composer 源
ARG COMPOSER_MIRROR=

# [优化核心] 后端依赖缓存优化
COPY composer.json composer.lock ./

# 本地 Composer 包缓存（./fetch-packages.sh 预热；为空时自动在线补全，完整性由 composer.lock 校验）
COPY docker-packages/composer-cache/ /composer-cache/
ENV COMPOSER_CACHE_DIR=/composer-cache

# 可选切换 Composer 源（国内网络环境使用）
RUN if [ -n "$COMPOSER_MIRROR" ]; then \
        composer config -g repos.packagist composer "$COMPOSER_MIRROR"; \
    fi

# [修改点 1] 安装依赖，但跳过自动加载器生成 (--no-autoloader) 和 脚本运行 (--no-scripts)
RUN composer install \
    --ignore-platform-reqs \
    --no-interaction \
    --no-ansi \
    --no-dev \
    --no-autoloader \
    --no-scripts

# 复制其余后端代码
COPY . .

# [修改点 2] 现在源代码都在了，补全自动加载映射
RUN composer dump-autoload --optimize --classmap-authoritative

# 复制部署文件
COPY ./deploy/files/ /

# 从构建阶段复制前端产物
COPY --from=build /app/public/ /app/public/

# 执行项目初始化命令
RUN cp .env.example .env \
    && php artisan key:generate \
    && rm -f public/storage && php artisan storage:link \
    && php artisan octane:install --server=frankenphp

# 设置环境变量
ENV APP_VERSION=${APP_VERSION}
ENV WEBSITE_ID=${WEBSITE_ID}
ENV DB_DATABASE=/data/database.sqlite

# [新增] 1. 复制启动脚本到容器根目录
COPY entrypoint.sh /entrypoint.sh

# [新增] 2. 赋予脚本执行权限
RUN chmod +x /entrypoint.sh

# [修改] 3. 将 CMD 改为执行我们的启动脚本
CMD ["/entrypoint.sh"]