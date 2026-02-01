#!/bin/sh

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