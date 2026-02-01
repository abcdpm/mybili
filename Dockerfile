# 构建业务镜像命令:
# docker build -t mybili:1 .

# === 阶段 1: 前端构建 ===
ARG NODE_VERSION=22
FROM node:${NODE_VERSION}-bullseye-slim AS build

WORKDIR /app

# 安装 pnpm
RUN npm install -g pnpm

# [优化核心] 先只复制依赖描述文件，利用缓存
COPY package.json pnpm-lock.yaml ./

# 安装依赖 (如果 package.json 没变，这一步会直接使用缓存)
RUN pnpm install

# 再复制其余源代码
COPY . .

# 编译前端
RUN pnpm build

# === 阶段 2: 最终业务镜像 ===
# 基于我们刚才构建的基础镜像
FROM mybili-base:1

WORKDIR /app

# 重新声明 ARG
ARG APP_VERSION=1.0.0
ARG WEBSITE_ID

# [优化核心] 后端依赖缓存优化
COPY composer.json composer.lock ./

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