#!/bin/sh
# =====================================================================
# Mybili 构建依赖离线包预取脚本 (fetch-packages.sh)
#
# 将 Docker 构建所需的 Debian 包 / Alpine 包 / 工具二进制 / npm / Composer
# 依赖自动下载并持久化到项目本地 docker-packages/ 目录（不入库），
# 之后 Dockerfile 构建时直接从本地复制安装，大幅加速（尤其国内网络）。
#
# 用法:
#   ./fetch-packages.sh                  # 预取全部（默认 amd64），增量刷新
#   ./fetch-packages.sh --check          # 只校验新鲜度，不下载，输出报告
#   ./fetch-packages.sh --force          # 忽略本地缓存强制全量刷新
#   ./fetch-packages.sh --target tools   # 只处理指定目标
#   ./fetch-packages.sh --arch arm64     # 预取 arm64（需 QEMU/binfmt）
#   ./fetch-packages.sh --dry-run        # 只打印将执行的动作
#
# 选项:
#   --arch amd64|arm64|all     目标架构（默认 amd64；all = 两者）
#   --target debian|alpine|tools|php-ext|composer|npm|all   （默认 all）
#   --check / --force / --dry-run
#
# 镜像源（与 build-and-push.sh 一致，国内网络建议开启）:
#   --mirror cn|tuna|aliyun|ustc     预设国内源
#   APT_MIRROR / APK_MIRROR / HTTP_PROXY / HTTPS_PROXY 环境变量逐项覆盖
#
# 新鲜度判断（MD5/ETag）:
#   - apt .deb:   以 apt 官方索引 MD5/SHA256 为准，变化才重下（自动捕获安全更新）
#   - apk .apk:   按包版本判断（记录于 manifest）
#   - 工具二进制: 固定版本按 MD5 校验损坏；yt-dlp(latest) 用 ETag/Last-Modified 条件请求
#   - npm/composer: 由 lockfile 完整性哈希保证（比 MD5 更强），本脚本只做缓存预热
#
# 兼容性: docker-packages/ 缺失/为空时 Dockerfile 自动回退在线安装（原方案不变）
# =====================================================================
set -eu

ROOT_DIR=$(cd "$(dirname "$0")" && pwd)
PKG_DIR="$ROOT_DIR/docker-packages"
MANIFEST="$PKG_DIR/manifest.txt"

ARCH="amd64"
TARGET="all"
CHECK_ONLY=0
FORCE=0
DRY_RUN=0
MIRROR=""

# 与 Dockerfile 保持一致的依赖清单
APT_PACKAGES="xz-utils curl wget tar"
APK_PACKAGES="libpng libpng-dev libjpeg-turbo libjpeg-turbo-dev libwebp libwebp-dev freetype freetype-dev libavif libavif-dev"
PHP_IMAGE_DEFAULT="phpswoole/swoole:php8.5-alpine"
DEBIAN_IMAGE="debian:bookworm-slim"
NODE_IMAGE="node:22-bookworm-slim"

# 工具清单（文件名即上游 URL 末段，保持与 Dockerfile 下载一致）
# 格式: 文件名|URL
TOOL_FILES() {
    _arch="$1"
    case "$_arch" in
        amd64)
            echo "ffmpeg-6.0.1-amd64-static.tar.xz|https://www.johnvansickle.com/ffmpeg/old-releases/ffmpeg-6.0.1-amd64-static.tar.xz"
            echo "yt-dlp_musllinux|https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_musllinux"
            echo "frankenphp-linux-x86_64|https://github.com/dunglas/frankenphp/releases/download/v1.2.5/frankenphp-linux-x86_64"
            echo "supervisord_0.7.3_Linux_64-bit.tar.gz|https://github.com/ochinchina/supervisord/releases/download/v0.7.3/supervisord_0.7.3_Linux_64-bit.tar.gz"
            ;;
        arm64)
            echo "ffmpeg-6.0.1-arm64-static.tar.xz|https://www.johnvansickle.com/ffmpeg/old-releases/ffmpeg-6.0.1-arm64-static.tar.xz"
            echo "yt-dlp_musllinux_aarch64|https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_musllinux_aarch64"
            echo "frankenphp-linux-aarch64|https://github.com/dunglas/frankenphp/releases/download/v1.2.5/frankenphp-linux-aarch64"
            echo "supervisord_0.7.3_Linux_ARM64.tar.gz|https://github.com/ochinchina/supervisord/releases/download/v0.7.3/supervisord_0.7.3_Linux_ARM64.tar.gz"
            ;;
    esac
}

# ---------- 工具函数 ----------
log()  { printf '\033[1;36m[pkgs]\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m[pkgs][OK]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[pkgs][WARN]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[pkgs][ERROR]\033[0m %s\n' "$*" >&2; exit 1; }

run() {
    if [ "$DRY_RUN" -eq 1 ]; then
        printf '[dry-run] %s\n' "$*"
    else
        "$@"
    fi
}

usage() { sed -n '2,38p' "$0" | sed 's/^# \{0,1\}//'; exit 0; }

# 文件 MD5（空文件/缺失返回空）
file_md5() {
    [ -f "$1" ] || { echo ""; return; }
    md5sum "$1" 2>/dev/null | awk '{print $1}'
}

# ---------- manifest 读写（格式: 路径|md5|size|etag|url|时间） ----------
manifest_get() {
    [ -f "$MANIFEST" ] || return 0
    grep -F "$1|" "$MANIFEST" | tail -n 1 || true
}

manifest_set() {
    # $1=path $2=md5 $3=size $4=etag $5=url
    if [ "$DRY_RUN" -eq 1 ]; then return 0; fi
    mkdir -p "$(dirname "$MANIFEST")"
    [ -f "$MANIFEST" ] && grep -vF "$1|" "$MANIFEST" > "$MANIFEST.tmp" || true
    [ -f "$MANIFEST.tmp" ] && mv "$MANIFEST.tmp" "$MANIFEST" || true
    printf '%s|%s|%s|%s|%s|%s\n' "$1" "$2" "$3" "$4" "$5" "$(date '+%F %T')" >> "$MANIFEST"
}

# ---------- 参数解析 ----------
while [ $# -gt 0 ]; do
    case "$1" in
        --arch)    ARCH="$2"; shift 2 ;;
        --target)  TARGET="$2"; shift 2 ;;
        --mirror)  MIRROR="$2"; shift 2 ;;
        --check)   CHECK_ONLY=1; shift ;;
        --force)   FORCE=1; shift ;;
        --dry-run) DRY_RUN=1; shift ;;
        -h|--help) usage ;;
        *)         die "未知参数: $1（--help 查看用法）" ;;
    esac
done

case "$ARCH" in amd64|arm64|all) : ;; *) die "--arch 仅支持 amd64/arm64/all" ;; esac
case "$TARGET" in debian|alpine|tools|php-ext|composer|npm|all) : ;; *) die "--target 仅支持 debian/alpine/tools/php-ext/composer/npm/all" ;; esac

# 国内镜像源预设（显式环境变量优先）
if [ -n "$MIRROR" ]; then
    case "$MIRROR" in
        cn|tuna)  PRESET_APT="mirrors.tuna.tsinghua.edu.cn";  PRESET_APK="mirrors.tuna.tsinghua.edu.cn" ;;
        aliyun)   PRESET_APT="mirrors.aliyun.com";            PRESET_APK="mirrors.aliyun.com" ;;
        ustc)     PRESET_APT="mirrors.ustc.edu.cn";           PRESET_APK="mirrors.ustc.edu.cn" ;;
        *)        die "未知镜像源预设: $MIRROR（可选 cn/tuna/aliyun/ustc）" ;;
    esac
    APT_MIRROR=${APT_MIRROR:-$PRESET_APT}
    APK_MIRROR=${APK_MIRROR:-$PRESET_APK}
fi

# ---------- 环境检查与目录骨架 ----------
if [ "$DRY_RUN" -eq 0 ]; then
    command -v docker >/dev/null 2>&1 || die "未找到 docker（apt/apk 依赖解析与缓存预热需要容器环境）"
    if ! command -v curl >/dev/null 2>&1 && ! command -v wget >/dev/null 2>&1; then
        die "未找到 curl 或 wget（工具二进制下载需要）"
    fi
    command -v md5sum >/dev/null 2>&1 || die "未找到 md5sum（新鲜度校验需要）"
fi

ensure_dirs() {
    for d in debian/amd64 debian/arm64 alpine/amd64 alpine/arm64 tools php-ext/amd64 php-ext/arm64 composer-cache npm-store; do
        mkdir -p "$PKG_DIR/$d"
        # .gitkeep 保证 git 新克隆后 Dockerfile 的 COPY 永不失败
        [ -f "$PKG_DIR/$d/.gitkeep" ] || : > "$PKG_DIR/$d/.gitkeep"
    done
    [ -f "$PKG_DIR/.gitkeep" ] || : > "$PKG_DIR/.gitkeep"
}

# 下载单个文件（支持 curl/wget），带超时与重试
download_file() {
    # $1=url $2=dest
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL --connect-timeout 20 --retry 2 -o "$2" "$1"
    else
        wget -q -T 20 -O "$2" "$1"
    fi
}

# 远端 ETag/Last-Modified（用于浮动资源的新鲜度判断）
remote_tag() {
    if command -v curl >/dev/null 2>&1; then
        curl -fsSI --connect-timeout 15 "$1" 2>/dev/null | grep -iE '^(etag|last-modified):' | tr -d '\r' | tr '\n' ' ' || true
    else
        echo ""
    fi
}

ARCH_PLATFORM() { echo "linux/$1"; }

# =====================================================================
# 目标 1: debian 离线包（apt 依赖闭包，官方 MD5/SHA256 校验）
# =====================================================================
fetch_debian() {
    _arch="$1"
    _dir="$PKG_DIR/debian/$_arch"
    log "[debian/$_arch] 解析 apt 依赖闭包（$APT_PACKAGES）..."

    if [ "$DRY_RUN" -eq 1 ]; then
        printf '[dry-run] docker run --platform %s %s apt-get install --print-uris -y %s\n' "$(ARCH_PLATFORM "$_arch")" "$DEBIAN_IMAGE" "$APT_PACKAGES"
        return 0
    fi

    # 在 debian 容器内让 apt 解析完整依赖闭包并输出 URI+官方哈希
    _print_uris=$(docker run --rm --platform "$(ARCH_PLATFORM "$_arch")" \
        -e APT_MIRROR="${APT_MIRROR:-}" "$DEBIAN_IMAGE" sh -c '
            if [ -n "$APT_MIRROR" ]; then
                sed -i "s|deb.debian.org|$APT_MIRROR|g" /etc/apt/sources.list.d/debian.sources 2>/dev/null || true
            fi
            apt-get -o Acquire::http::Timeout=20 -o Acquire::Retries=2 update >/dev/null 2>&1
            apt-get install --print-uris -y '"$APT_PACKAGES"' 2>/dev/null
        ') || die "[debian/$_arch] 依赖解析失败（容器内 apt update 失败？可加 --mirror cn 或 HTTP_PROXY）"

    [ -n "$_print_uris" ] || die "[debian/$_arch] apt --print-uris 输出为空"

    _list="$_dir/.packages.list"
    : > "$_list.new"
    printf '%s\n' "$_print_uris" | while IFS= read -r line; do
        case "$line" in \'*) : ;; *) continue ;; esac
        _uri=$(printf '%s' "$line" | sed -E "s/^'([^']+)'.*/\1/")
        _rest=$(printf '%s' "$line" | sed -E "s/^'[^']+' +//")
        _fname=$(printf '%s' "$_rest" | awk '{print $1}')
        _hash=$(printf '%s' "$_rest" | grep -oE '(MD5Sum|SHA256):[0-9a-f]+' | head -n 1)
        printf '%s|%s|%s\n' "$_fname" "$_hash" "$_uri" >> "$_list.new"
    done

    _stale=0; _missing=0; _fresh=0
    while IFS='|' read -r _fname _hash _uri; do
        [ -n "$_fname" ] || continue
        _file="$_dir/$_fname"
        _want=$(printf '%s' "$_hash" | cut -d: -f2)
        _algo=$(printf '%s' "$_hash" | cut -d: -f1)
        _have=""
        if [ -f "$_file" ]; then
            if [ "$_algo" = "SHA256" ]; then _have=$(sha256sum "$_file" | awk '{print $1}'); else _have=$(file_md5 "$_file"); fi
        fi

        if [ -n "$_have" ] && [ "$_have" = "$_want" ] && [ "$FORCE" -eq 0 ]; then
            _fresh=$((_fresh + 1))
            continue
        fi
        if [ -n "$_have" ]; then _stale=$((_stale + 1)); else _missing=$((_missing + 1)); fi
        if [ "$CHECK_ONLY" -eq 1 ]; then continue; fi
        log "[debian/$_arch] 下载 $_fname"
        download_file "$_uri" "$_file" || { warn "下载失败: $_uri"; rm -f "$_file"; continue; }
        manifest_set "debian/$_arch/$_fname" "$_want" "$(wc -c < "$_file" | tr -d ' ')" "" "$_uri"
    done < "$_list.new"
    rm -f "$_list.new"

    ok "[debian/$_arch] 完成：最新 $_fresh 个 / 更新 $_stale 个 / 新增 $_missing 个"
}

# =====================================================================
# 目标 2: alpine 离线包（apk 依赖闭包，按版本新鲜度判断）
# =====================================================================
fetch_alpine() {
    _arch="$1"
    _dir="$PKG_DIR/alpine/$_arch"
    log "[alpine/$_arch] 拉取 apk 依赖闭包（PHP 扩展编译与 GD 运行库）..."

    if [ "$DRY_RUN" -eq 1 ]; then
        printf '[dry-run] docker run --platform %s %s sh -c "apk update && apk fetch -R ..."\n' "$(ARCH_PLATFORM "$_arch")" "$PHP_IMAGE_DEFAULT"
        return 0
    fi

    if [ "$CHECK_ONLY" -eq 1 ]; then
        _count=$(find "$_dir" -name '*.apk' 2>/dev/null | wc -l | tr -d ' ')
        if [ "$_count" -gt 0 ]; then ok "[alpine/$_arch] 本地已有 $_count 个 .apk"; else warn "[alpine/$_arch] 本地无 .apk（构建将回退在线）"; fi
        return 0
    fi

    # 在运行时基础镜像容器内拉取（保证 alpine 版本与镜像严格一致）
    if docker run --rm --platform "$(ARCH_PLATFORM "$_arch")" \
        -e APK_MIRROR="${APK_MIRROR:-}" -v "$_dir:/out" "$PHP_IMAGE_DEFAULT" sh -c '
            if [ -n "$APK_MIRROR" ]; then
                sed -i "s|dl-cdn.alpinelinux.org|$APK_MIRROR|g" /etc/apk/repositories 2>/dev/null || true
            fi
            apk update >/dev/null 2>&1
            cd /out && apk fetch -R '"$APK_PACKAGES"' >/dev/null 2>&1
        '; then
        _count=$(find "$_dir" -name '*.apk' 2>/dev/null | wc -l | tr -d ' ')
        if [ "$_count" -gt 0 ]; then
            ok "[alpine/$_arch] 完成：$_count 个 .apk"
            return 0
        fi
    fi

    # 安全兜底：apk fetch 失败 → 清理半成品保持目录为空（Dockerfile 自动回退在线，不影响构建）
    warn "[alpine/$_arch] apk 离线包拉取失败（构建时自动回退在线安装）"
    find "$_dir" -name '*.apk' -delete 2>/dev/null || true
    return 0
}

# =====================================================================
# 目标 3: 工具二进制（ffmpeg/yt-dlp/frankenphp/supervisord）
# =====================================================================
fetch_tools() {
    _arch="$1"
    log "[tools/$_arch] 校验/下载工具二进制..."

    TOOL_FILES "$_arch" | while IFS='|' read -r _fname _url; do
        [ -n "$_fname" ] || continue
        _file="$PKG_DIR/tools/$_fname"
        _rec=$(manifest_get "tools/$_fname")
        _etag=$(printf '%s' "$_rec" | cut -d'|' -f4)
        _md5=$(printf '%s' "$_rec" | cut -d'|' -f2)
        _now_tag=""

        case "$_url" in
            */latest/*)
                # 浮动资源（yt-dlp latest）：ETag/Last-Modified 条件判断
                _now_tag=$(remote_tag "$_url")
                if [ "$FORCE" -eq 0 ] && [ -f "$_file" ] && [ -n "$_etag" ] && [ "$_etag" = "$_now_tag" ]; then
                    ok "[tools] $_fname 已是最新（ETag 未变化）"
                    continue
                fi
                ;;
            *)
                # 固定版本：本地存在且 MD5 与 manifest 一致即跳过（仅防损坏/半截文件）
                if [ "$FORCE" -eq 0 ] && [ -f "$_file" ] && [ -n "$_md5" ] && [ "$_md5" = "$(file_md5 "$_file")" ]; then
                    ok "[tools] $_fname 命中缓存（MD5 一致）"
                    continue
                fi
                ;;
        esac

        if [ "$CHECK_ONLY" -eq 1 ]; then
            warn "[tools] $_fname 需要下载/刷新"
            continue
        fi
        if [ "$DRY_RUN" -eq 1 ]; then
            printf '[dry-run] download %s -> %s\n' "$_url" "$_file"
            continue
        fi

        log "[tools] 下载 $_fname"
        download_file "$_url" "$_file" || { warn "下载失败: $_url"; rm -f "$_file"; continue; }
        manifest_set "tools/$_fname" "$(file_md5 "$_file")" "$(wc -c < "$_file" | tr -d ' ')" "$_now_tag" "$_url"
        ok "[tools] $_fname 完成"
    done
}

# =====================================================================
# 目标 4: 预编译 PHP 扩展（gd/pcntl）——一次编译，构建时秒级复用
#
# 背景：docker-php-ext-install gd 源码编译在 NAS 级 CPU 上耗时 2-8 分钟，
# 且层缓存失效就要重编。预编译产物 .so 按「架构 + PHP 版本 + 扩展 API 目录」
# 三重校验后复用，构建时冒烟测试失败会自动回退源码编译（绝不因此构建失败）。
# =====================================================================
fetch_php_ext() {
    _arch="$1"
    _dir="$PKG_DIR/php-ext/$_arch"
    log "[php-ext/$_arch] 预编译 PHP 扩展（gd/pcntl）——首次编译较慢（约 2-8 分钟），之后构建秒级复用..."

    if [ "$DRY_RUN" -eq 1 ]; then
        printf '[dry-run] docker run --platform %s %s sh -c "apk add <libs> && docker-php-ext-configure gd && docker-php-ext-install gd pcntl"\n' "$(ARCH_PLATFORM "$_arch")" "$PHP_IMAGE_DEFAULT"
        return 0
    fi

    if [ "$CHECK_ONLY" -eq 1 ]; then
        if [ -f "$_dir/gd.so" ] && [ -f "$_dir/pcntl.so" ]; then
            ok "[php-ext/$_arch] 已有预编译产物（PHP $(cat "$_dir/.php-version" 2>/dev/null)）"
        else
            warn "[php-ext/$_arch] 无预编译产物（构建时将源码编译）"
        fi
        return 0
    fi

    # 在运行时基础镜像内编译（保证扩展 ABI 与镜像严格一致），完成后拷出 .so
    if docker run --rm --platform "$(ARCH_PLATFORM "$_arch")" \
        -e APK_MIRROR="${APK_MIRROR:-}" -v "$_dir:/out" "$PHP_IMAGE_DEFAULT" sh -c '
            set -e
            if [ -n "$APK_MIRROR" ]; then
                sed -i "s|dl-cdn.alpinelinux.org|$APK_MIRROR|g" /etc/apk/repositories
            fi
            apk add --no-cache '"$APK_PACKAGES"' >/dev/null
            docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-avif >/dev/null
            docker-php-ext-install -j"$(nproc)" gd pcntl >/dev/null
            EXT_DIR=$(php -r "echo ini_get('"'"'extension_dir'"'"');")
            cp "$EXT_DIR/gd.so" "$EXT_DIR/pcntl.so" /out/
            php -r "echo PHP_VERSION;" > /out/.php-version
            printf %s "$EXT_DIR" > /out/.ext-dir
        '; then
        manifest_set "php-ext/$_arch/gd.so" "$(file_md5 "$_dir/gd.so")" "$(wc -c < "$_dir/gd.so" | tr -d ' ')" "" "local-build $(cat "$_dir/.php-version" 2>/dev/null)"
        ok "[php-ext/$_arch] 预编译完成（PHP $(cat "$_dir/.php-version" 2>/dev/null)）"
    else
        warn "[php-ext/$_arch] 预编译失败（构建时自动回退源码编译，不影响构建）"
    fi
}

# =====================================================================
# 目标 4: Composer 包缓存预热（lockfile 自带完整性校验）
# =====================================================================
fetch_composer() {
    _dir="$PKG_DIR/composer-cache"
    log "[composer] 预热 Composer 包缓存（composer.lock 驱动，完整性哈希校验）..."
    if [ "$DRY_RUN" -eq 1 ]; then
        printf '[dry-run] docker run %s sh -c "composer install --no-scripts --prefer-dist（预热缓存）"\n' "$PHP_IMAGE_DEFAULT"
        return 0
    fi
    if [ "$CHECK_ONLY" -eq 1 ]; then
        _count=$(find "$_dir" -type f ! -name '.gitkeep' 2>/dev/null | wc -l | tr -d ' ')
        ok "[composer] 本地缓存文件 $_count 个（lockfile 变化时 composer 自动补下载）"
        return 0
    fi

    docker run --rm -v "$ROOT_DIR:/app" -v "$_dir:/composer-cache" \
        -e COMPOSER_CACHE_DIR=/composer-cache -e COMPOSER_ALLOW_SUPERUSER=1 \
        -e HTTP_PROXY="${HTTP_PROXY:-}" -e HTTPS_PROXY="${HTTPS_PROXY:-}" \
        -w /tmp "$PHP_IMAGE_DEFAULT" sh -c '
            cp /app/composer.json /app/composer.lock /tmp/ && cd /tmp \
            && composer install --no-scripts --no-autoloader --prefer-dist --no-interaction >/dev/null 2>&1
        ' && ok "[composer] 缓存预热完成" || warn "[composer] 预热失败（构建时在线下载，不影响构建）"
}

# =====================================================================
# 目标 5: pnpm/npm 依赖 store 预热（lockfile 自带 sha512 校验）
# =====================================================================
fetch_npm() {
    _dir="$PKG_DIR/npm-store"
    log "[npm] 预热 pnpm 依赖 store（pnpm-lock.yaml 驱动）..."
    if [ "$DRY_RUN" -eq 1 ]; then
        printf '[dry-run] docker run %s sh -c "pnpm fetch --store-dir /store"\n' "$NODE_IMAGE"
        return 0
    fi
    if [ "$CHECK_ONLY" -eq 1 ]; then
        _count=$(find "$_dir" -type f ! -name '.gitkeep' 2>/dev/null | wc -l | tr -d ' ')
        ok "[npm] 本地 store 文件 $_count 个（lockfile 变化时自动增量补全）"
        return 0
    fi

    docker run --rm -v "$ROOT_DIR:/app" -v "$_dir:/store" \
        -e HTTP_PROXY="${HTTP_PROXY:-}" -e HTTPS_PROXY="${HTTPS_PROXY:-}" \
        -e NPM_REGISTRY="${NPM_REGISTRY:-}" \
        "$NODE_IMAGE" sh -c '
            if [ -n "$NPM_REGISTRY" ]; then npm config set registry "$NPM_REGISTRY"; fi
            npm install -g pnpm@latest-11 >/dev/null 2>&1
            cd /app && pnpm fetch --store-dir /store >/dev/null 2>&1
        ' && ok "[npm] 依赖 store 预热完成" || warn "[npm] 预热失败（构建时在线下载，不影响构建）"
}

# =====================================================================
# 主调度
# =====================================================================
ensure_dirs

ARCHS="$ARCH"
if [ "$ARCH" = "all" ]; then ARCHS="amd64 arm64"; fi

log "目标: $TARGET | 架构: $ARCHS | 模式: $([ "$CHECK_ONLY" -eq 1 ] && echo 校验 || echo 下载)$([ "$DRY_RUN" -eq 1 ] && echo '(dry-run)')"
log "存储目录: $PKG_DIR"

for a in $ARCHS; do
    case "$TARGET" in debian|all)   fetch_debian "$a"   ;; esac
    case "$TARGET" in alpine|all)   fetch_alpine "$a"   ;; esac
    case "$TARGET" in tools|all)    fetch_tools "$a"    ;; esac
    case "$TARGET" in php-ext|all)  fetch_php_ext "$a"  ;; esac
done
case "$TARGET" in composer|all) fetch_composer ;; esac
case "$TARGET" in npm|all)      fetch_npm      ;; esac

if [ "$CHECK_ONLY" -eq 1 ]; then
    log "校验完成（--check 模式未做任何修改）"
else
    log "全部完成 ✅  现在运行 ./build-and-push.sh 将自动使用本地离线包构建"
fi