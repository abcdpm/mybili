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
#   --rebuild-base      强制重建本地基础镜像 mybili-base:1
#   --cache             启用 Docker Hub registry 构建缓存 (:buildcache)
#   --no-push           只构建不推送（多平台自动降级为当前平台 --load）
#   --dry-run           打印命令后退出
#   -h, --help          显示帮助
#
# 版本号来源: 项目根目录 .env 中的 APP_VERSION（例如 APP_VERSION=1.4.0）
#   - 作为 Dockerfile 的 --build-arg APP_VERSION（决定容器内 config('app.version')）
#   - 作为镜像标签 {image}:1.4.0，同时推送 {image}:latest
#
# 环境变量（可选）:
#   HTTP_PROXY / HTTPS_PROXY / NO_PROXY   构建期代理，自动透传给 docker build
#
# 前置条件:
#   1. 已安装 docker（含 buildx 插件）并 docker login 到 Docker Hub
#   2. 本地存在基础镜像 mybili-base:1（不存在时本脚本会自动构建，
#      也可用 --rebuild-base 强制重建，见 Dockerfile.base 顶部注释）
# =====================================================================
set -eu

# ---------- 默认值 ----------
IMAGE_NAME="llllalex/mybili"
PLATFORMS="linux/amd64,linux/arm64"
PUSH=1
DRY_RUN=0
REBUILD_BASE=0
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
        --no-push)     PUSH=0; shift ;;
        --dry-run)     DRY_RUN=1; shift ;;
        --rebuild-base) REBUILD_BASE=1; shift ;;
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

log "镜像:      $IMAGE_NAME"
log "版本:      $APP_VERSION（来源 .env APP_VERSION）"
log "目标平台:  $PLATFORMS"
log "推送:      $([ "$PUSH" -eq 1 ] && echo '是（latest + '$APP_VERSION'）' || echo '否')"

# ---------- 环境检查 ----------
if [ "$DRY_RUN" -eq 0 ]; then
    command -v docker >/dev/null 2>&1 || die "未找到 docker 命令，请先安装 Docker"
    docker buildx version >/dev/null 2>&1 || die "未找到 docker buildx 插件，请升级 Docker 或安装 buildx"
fi

# ---------- 基础镜像检查/构建 ----------
# 业务 Dockerfile 依赖本地基础镜像 mybili-base:1（见 Dockerfile.base）
BASE_EXISTS=$(docker images -q mybili-base:1 2>/dev/null | head -n 1 || true)
if [ "$REBUILD_BASE" -eq 1 ] || [ -z "$BASE_EXISTS" ]; then
    if [ "$REBUILD_BASE" -eq 1 ]; then
        log "强制重建基础镜像 mybili-base:1 ..."
    else
        warn "本地不存在基础镜像 mybili-base:1，自动构建中（首次构建耗时较长）..."
    fi
    run docker build -f "$ROOT_DIR/Dockerfile.base" -t mybili-base:1 "$ROOT_DIR"
fi

# ---------- 组装 docker buildx build 命令 ----------
BUILD_ARGS="--build-arg APP_VERSION=$APP_VERSION"
if [ -n "${WEBSITE_ID:-}" ]; then
    BUILD_ARGS="$BUILD_ARGS --build-arg WEBSITE_ID=$WEBSITE_ID"
fi

# 代理透传（Docker 构建原生支持 *_PROXY 预定义参数）
for proxy_var in HTTP_PROXY HTTPS_PROXY NO_PROXY; do
    eval proxy_val=\${$proxy_var:-}
    if [ -n "$proxy_val" ]; then
        BUILD_ARGS="$BUILD_ARGS --build-arg $proxy_var=$proxy_val"
    fi
done

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