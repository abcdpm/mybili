#!/bin/sh

# === 1. 预创建必要的存储目录 ===
# 确保视频下载目录存在 (对应 DownloadVideoService.php)
if [ ! -d "/app/storage/app/public/videos" ]; then
    echo "📁 Creating video storage directory..."
    mkdir -p /app/storage/app/public/videos
fi

# 确保可读文件名目录存在 (medias) HumanReadableNameService
if [ ! -d "/app/storage/app/public/medias" ]; then
    echo "📁 Creating medias storage directory..."
    mkdir -p /app/storage/app/public/medias
fi

# 确保图片/封面目录存在 (对应 CoverService/DownloadImageService)
if [ ! -d "/app/storage/app/public/images" ]; then
    echo "📁 Creating image storage directory..."
    mkdir -p /app/storage/app/public/images
fi

# ⚠️ 权限修正：确保所有 storage 目录不仅存在，而且可写
# 如果容器以 root 运行，这步可能不是强制的，但为了健壮性建议加上
chmod -R 777 /app/storage/app/public

# === 2. 数据库初始化逻辑 ===
# 读取环境变量中的数据库路径，默认为 /data/database.sqlite
DB_FILE=${DB_DATABASE:-/data/database.sqlite}

# === 1. 判断数据库文件是否存在 ===
if [ ! -f "$DB_FILE" ]; then
    echo "Checking database file... Not found."
    echo "Creating empty database file at $DB_FILE"
    # 创建空文件，SQLite 驱动需要文件存在才能连接
    touch "$DB_FILE"
    
    # 可选：如果你在 Linux 上运行，可能需要确属主是 www-data (视具体基础镜像而定)
    # chown www-data:www-data "$DB_FILE"
else
    echo "Checking database file... Found at $DB_FILE"
fi

# === 2. 自动执行数据库迁移 ===
# --force 参数用于在生产模式下强制执行，不弹出确认提示
echo "Running database migrations..."
php artisan migrate --force

# === 3. 启动主进程 ===
# 执行 Dockerfile 原本的启动命令 (supervisord)
echo "Starting Supervisord..."
exec /usr/local/bin/supervisord -c /etc/supervisord.conf