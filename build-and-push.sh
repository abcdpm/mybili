#!/bin/sh
# =====================================================================
# Mybili 镜像构建与发布脚本 (build-and-push.sh)
#
# 用法:
#   ./build-and-push.sh                 # 构建并推送 :latest 与 :版本号
#   ./build-and-push.sh --no-push       # 仅本地构建（单平台 --load）
#   ./build-and-push.sh --dry-run       # 只打印将执行的命令，不实际执行
#
# 选项:
#   --image NAME        镜像名（默认 abcdpm/mybili）
#   --platforms STR     目标平台，逗号分隔（默认 linux/amd64,linux/arm64）
#   --mirror NAME       国内镜像源预设: cn/tuna/aliyun/ustc（国内网络环境建议开启，
#                       等价于设置 APT_MIRROR/APK_MIRROR/NPM_REGISTRY/COMPOSER_MIRROR）
#   --cache             启用 Docker Hub registry 构建缓存 (:buildcache)
#   --no-push           只构建不推送（多平台自动降级为当前平台 --load）
#   --dry-run           打印命令后退出
#   -h, --help          显示帮助
#
# 多架构说明: 构建 linux/arm64 需要 QEMU/binfmt 支持（Docker Desktop 自带；
# Linux 服务器如报 exec format error，请执行:
#   docker run --privileged --rm tonistiigi/binfmt --install）
#
# 版本号来源: 项目根目录 .env 中的 APP_VERSION（例如 APP_VERSION=1.4.0）
#   - 作为 Dockerfile 的 --build-arg APP_VERSION（决定容器内 config('app.version')）
#   - 作为镜像标签 {image}:1.4.0，同时推送 {image}:latest
#
# 环境变量（可选，优先级高于 --mirror 预设）:
#   APT_MIRROR          Debian apt 源（如 mirrors.tuna.tsinghua.edu.cn）
#   APK_MIRROR          Alpine apk 源（如 mirrors.tuna.tsinghua.edu.cn）
#   NPM_REGISTRY        npm 源（如 https://registry.npmmirror.com）
#   COMPOSER_MIRROR     Composer 源（如 https://mirrors.aliyun.com/composer/）
#   PHP_IMAGE           PHP 基础镜像（默认 phpswoole/swoole:php8.5-alpine = PHP 8.5 系，
#                       可精确锁定如 php:8.5.12-cli-alpine）
#   HTTP_PROXY / HTTPS_PROXY / NO_PROXY   构建期代理，自动透传给 docker build
#
# 国内网络环境推荐:
#   ./build-and-push.sh --mirror cn
#   或者全程代理: HTTP_PROXY=http://192.168.0.2:1082 ./build-and-push.sh
#
# 前置条件:
#   1. 已安装 docker（含 buildx 插件）并 docker login 到 Docker Hub
#   2. 多架构构建需要 QEMU/binfmt（Docker Desktop 自带；Linux 服务器自行安装）
# =====================================================================
set -eu

# ---------- 默认值 ----------
IMAGE_NAME="llllalex/mybili"
PLATFORMS="linux/amd64"
MIRROR=""
PUSH=1
DRY_RUN=0
USE_CACHE=0

# ---------- 工具函数 ----------
log()  { printf '\033[1;36m[mybili-build]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[mybili-build][WARN]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[mybili-build][ERROR]\033[0m %s\n' "$*" >&2; exit 1; }

usage() { sed -n '2,32p' "$0" | sed 's/^# \{0,1\}//'; exit 0; }

run() {
    if [ "$DRY_RUN" -eq 1 ]; then
        printf '[dry-run] %s\n' "$*"
    else
        log "执行: $*"
        "$@"
    fi
}

# ---------- 参数解析 ----------
while [ $# -gt 0 ]; do
    case "$1" in
        --image)       IMAGE_NAME="$2"; shift 2 ;;
        --platforms)   PLATFORMS="$2"; shift 2 ;;
        --mirror)      MIRROR="$2"; shift 2 ;;
        --no-push)     PUSH=0; shift ;;
        --dry-run)     DRY_RUN=1; shift ;;
        --cache)       USE_CACHE=1; shift ;;
        -h|--help)     usage ;;
        *)             die "未知参数: $1（用 --help 查看用法）" ;;
    esac
done

# ---------- 读取 .env 中的版本号 ----------
ROOT_DIR=$(cd "$(dirname "$0")" && pwd)
ENV_FILE="$ROOT_DIR/.env"

[ -f "$ENV_FILE" ] || die "未找到 $ENV_FILE，请先创建 .env（可参考 .env.example）"

APP_VERSION=$(grep -E '^APP_VERSION=' "$ENV_FILE" | tail -n 1 | cut -d= -f2- | tr -d '"' | tr -d "'")
[ -n "$APP_VERSION" ] || die ".env 中缺少 APP_VERSION（例如 APP_VERSION=1.4.0）"

# 校验版本号只能包含合法 tag 字符（Docker tag 规则: [A-Za-z0-9_.-]）
echo "$APP_VERSION" | grep -Eq '^[A-Za-z0-9_.-]+$' || die "APP_VERSION 含非法字符: $APP_VERSION"

# 可选构建参数（优先取进程环境变量，其次 .env；未配置则不传）
WEBSITE_ID=${WEBSITE_ID:-$(grep -E '^WEBSITE_ID=' "$ENV_FILE" | tail -n 1 | cut -d= -f2- | tr -d '"' | tr -d "'")}

# ---------- 国内镜像源预设（显式环境变量优先于预设） ----------
if [ -n "$MIRROR" ]; then
    case "$MIRROR" in
        cn|tuna)  PRESET_APT="mirrors.tuna.tsinghua.edu.cn";  PRESET_APK="mirrors.tuna.tsinghua.edu.cn" ;;
        aliyun)   PRESET_APT="mirrors.aliyun.com";            PRESET_APK="mirrors.aliyun.com" ;;
        ustc)     PRESET_APT="mirrors.ustc.edu.cn";           PRESET_APK="mirrors.ustc.edu.cn" ;;
        *)        die "未知镜像源预设: $MIRROR（可选 cn/tuna/aliyun/ustc）" ;;
    esac
    APT_MIRROR=${APT_MIRROR:-$PRESET_APT}
    APK_MIRROR=${APK_MIRROR:-$PRESET_APK}
    NPM_REGISTRY=${NPM_REGISTRY:-https://registry.npmmirror.com}
    COMPOSER_MIRROR=${COMPOSER_MIRROR:-https://mirrors.aliyun.com/composer/}
fi

# ---------- 组装公共构建参数（代理 + 镜像源，业务镜像与基础镜像共用） ----------
PROXY_ARGS=""
for proxy_var in HTTP_PROXY HTTPS_PROXY NO_PROXY; do
    eval proxy_val=\${$proxy_var:-}
    if [ -n "$proxy_val" ]; then
        PROXY_ARGS="$PROXY_ARGS --build-arg $proxy_var=$proxy_val"
    fi
done

MIRROR_ARGS=""
for mirror_var in APT_MIRROR APK_MIRROR; do
    eval mirror_val=\${$mirror_var:-}
    if [ -n "$mirror_val" ]; then
        MIRROR_ARGS="$MIRROR_ARGS --build-arg $mirror_var=$mirror_val"
    fi
done

if [ -n "$MIRROR_ARGS" ] || [ -n "$PROXY_ARGS" ]; then
    log "国内源/代理参数将作用于构建（内联基础阶段与业务阶段共用）"
fi

log "镜像:      $IMAGE_NAME"
log "版本:      $APP_VERSION（来源 .env APP_VERSION）"
log "目标平台:  $PLATFORMS"
log "推送:      $([ "$PUSH" -eq 1 ] && echo '是（latest + '$APP_VERSION'）' || echo '否')"

# ---------- 环境检查 ----------
if [ "$DRY_RUN" -eq 0 ]; then
    command -v docker >/dev/null 2>&1 || die "未找到 docker 命令，请先安装 Docker"
    docker buildx version >/dev/null 2>&1 || die "未找到 docker buildx 插件，请升级 Docker 或安装 buildx"
fi

# ---------- 配置 buildx 构建器（跨架构构建的核心） ----------
# 旧版依赖本地单架构镜像 mybili-base:1：多平台构建时容器驱动无法解析该本地镜像
# （或 arm64 平台拿到 amd64 镜像导致 exec format error）。
# 现 Dockerfile 已内联基础阶段，buildx 为每个目标平台独立编译工具链与 PHP 扩展。
if [ "$DRY_RUN" -eq 0 ]; then
    if ! docker buildx inspect mybili-builder >/dev/null 2>&1; then
        log "创建 buildx 构建器 mybili-builder（docker-container 驱动，支持多平台）..."
        docker buildx create --name mybili-builder --driver docker-container --use >/dev/null
    fi
    docker buildx use mybili-builder >/dev/null 2>&1 || true

    # 多平台时预检 QEMU/binfmt 支持
    case "$PLATFORMS" in
        *,*)
            BUILDER_PLATFORMS=$(docker buildx inspect mybili-builder 2>/dev/null | grep -i '^ *Platforms' || true)
            case "$BUILDER_PLATFORMS" in
                *linux/arm64*) : ;;
                *) warn "构建器未报告 linux/arm64 支持；如构建报 exec format error，请先执行:
    docker run --privileged --rm tonistiigi/binfmt --install" ;;
            esac ;;
    esac
fi

# ---------- 组装 docker buildx build 命令 ----------
BUILD_ARGS="--build-arg APP_VERSION=$APP_VERSION"
if [ -n "${WEBSITE_ID:-}" ]; then
    BUILD_ARGS="$BUILD_ARGS --build-arg WEBSITE_ID=$WEBSITE_ID"
fi

# PHP 基础镜像覆盖（如需精确锁定 php:8.5.12-cli-alpine 等）
if [ -n "${PHP_IMAGE:-}" ]; then
    BUILD_ARGS="$BUILD_ARGS --build-arg PHP_IMAGE=$PHP_IMAGE"
fi

# npm / Composer 源（国内镜像）
for mirror_var in NPM_REGISTRY COMPOSER_MIRROR; do
    eval mirror_val=\${$mirror_var:-}
    if [ -n "$mirror_val" ]; then
        BUILD_ARGS="$BUILD_ARGS --build-arg $mirror_var=$mirror_val"
    fi
done

# 代理透传（Docker 构建原生支持 *_PROXY 预定义参数）
BUILD_ARGS="$BUILD_ARGS $PROXY_ARGS"

TAGS="-t $IMAGE_NAME:latest -t $IMAGE_NAME:$APP_VERSION"

CACHE_ARGS=""
if [ "$USE_CACHE" -eq 1 ]; then
    CACHE_ARGS="--cache-from type=registry,ref=$IMAGE_NAME:buildcache --cache-to type=registry,ref=$IMAGE_NAME:buildcache,mode=max"
fi

OUTPUT_ARGS="--push"
if [ "$PUSH" -eq 0 ]; then
    # --load 仅支持单平台；多平台时自动降级为第一个平台
    OUTPUT_ARGS="--load"
    case "$PLATFORMS" in
        *,*) warn "--no-push 模式不支持多平台 --load，已降级为单平台: ${PLATFORMS%%,*}"
             PLATFORMS="${PLATFORMS%%,*}" ;;
    esac
fi

# ---------- 执行构建 ----------
# 说明: --provenance=false --sbom=false 与 CI 保持一致，避免旧版 docker 拉取兼容问题
run docker buildx build \
    -f "$ROOT_DIR/Dockerfile" \
    --platform "$PLATFORMS" \
    $BUILD_ARGS \
    $TAGS \
    $CACHE_ARGS \
    $OUTPUT_ARGS \
    --provenance=false \
    --sbom=false \
    "$ROOT_DIR"

if [ "$DRY_RUN" -eq 0 ] && [ "$PUSH" -eq 1 ]; then
    log "推送完成:"
    log "  docker pull $IMAGE_NAME:$APP_VERSION"
    log "  docker pull $IMAGE_NAME:latest"
fi

log "全部完成 ✅"