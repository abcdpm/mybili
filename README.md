## 🎥 Mybili

**bilibili 收藏夹下载工具** - 你的NAS中必不可少的程序

<img src="./mybili.png" alt="Mybili Logo" width="256" height="256" />

## 区别于 ellermister/mybili
1. 调整了 UI 界面
2. 新增了 **视频评论区** 的缓存功能
3. 新增了 **视频转码** 功能（主要用于 iOS 移动端）
4. 新增了 **视频标签** 的缓存功能
5. 新增了 日志页面
6. 优化了 系统信息页面
7. 调整了 定时任务
8. 新增了 **视频播放量和播放时长** 的缓存功能

## 📱 社区交流

加入我们的 Telegram 群组，与其他用户交流使用心得，获取最新更新和帮助：

🔗 **[加入 Telegram 群组](https://t.me/+SqAyFZfxF7dmNjk1)**

## ✨ 功能特性

- ⏰ 定时5分钟获取你的收藏夹所有视频，缓存标题、描述、封面等重要信息
- 🚀 自动通过队列，将你收藏的视频按照最高画质下载一份到本地
- 📺 提供友好的 web 页面展示你的收藏夹列表信息，以及进行在线播放预览
- 🎯 支持视频分P和弹幕全量缓存到本地，可以在线播放以及装载弹幕预览
- 📂 支持生成多媒体可读目录文件，适配 emby 等媒体库
- 📡 支持添加订阅功能，以对合集、UP主进行订阅，自动发现新视频下载

## 🖼️ 预览

🌐 **[在线预览](https://mybili.eller.top/)**

![preview](./preview.png)
![preview0](./preview0.png)
![preview1](./preview1.png)

## 📚 使用方法

该演示以最公共简单的方式创建一个服务，让你能够快速的体验到，你可以根据实际的需求和现实修改其中配置和部署方式。

- 💾 程序依赖 redis 来进行必要的缓存和分发处理异步任务
- 💿 默认将采用 sqlite 数据库存储收藏夹、视频、弹幕等文本型信息
- 📂 视频、图片将按照规则存储到你预设的本地文件系统中

### 🐳 1. 通过 docker 部署于你 nas

#### 📁 创建存储目录
建议为你将要缓存的视频资源创建目录，稍后将为其映射路径而不是通过 docker-compose 管理，目的是更方便的允许外部使用。

| 💡 如果你熟悉 Docker 配置，可以自由更改

```bash
mkdir /mnt/user/mybili/data -p
```

#### 📝 创建 docker 服务配置

创建文件 `/mnt/user/mybili/docker-compose.yml`

- 🔌 容器内部端口 80(http) 和 443(https) 都可以使用
- ⚙️ 下列 env 环境变量均可以参考 .env.example 文件内有的项进行覆盖，但默认只建议修改示例中内容

```yml
services: 
    mybili:
        image: abcdpm/mybili
        ports:
            - "5151:80"
        volumes:
            - "./data:/app/storage/app/public"
            - db-data:/data

        environment:
            REDIS_HOST: redis
            REDIS_DB: 3
            DB_CONNECTION: sqlite
            DB_DATABASE: /data/database.sqlite
    redis:
        image: redis
        volumes:
            - redis-data:/data
        command: redis-server --save 60 1 --loglevel warning

volumes:
  db-data:
  redis-data:
```

#### 🚀 一键启动
```bash
docker-compose up -d
```

#### 🛑 停止运行 (如果你需要)
```bash
docker-compose down
```

### 🍪 2. 获取 cookie

你有两个方案可选其一

#### 📌 方案1 - 手动

1. 🔌 在你的浏览器安装插件
   [Get cookies.txt LOCALLY](https://chrome.google.com/webstore/detail/cclelndahbckbenkjhflpdbgdldlbecc)

2. 📤 在你登录哔哩哔哩后，通过插件导出 cookie 文件。需要格式为：`Netscape`

3. 🌐 访问 `http://your-ip:5151/cookie`

4. ⬆️ 上传 cookie 文件，稍后将自动开始同步你的收藏夹了！🍡🍡🍡

#### 🤖 方案2 - 自动

由于方案1上传 cookie 会话之后，会在几天之后自动过期, 无法实现长期的自动同步。

原因是因为登录的网页版 bilibili 在同期使用时，重新获取了新的短期 token，而 mybili 并没有更新，也没有机制去自动获取新的 token，如果 mybili 自己去获取新的 token 也会导致你的网页版本掉线。无论是从实现复杂度还是使用体验来论都不好。

目前参考上述插件获取 cookie 内容，加以加工，制作了一个简单的自动同步 cookie chrome 扩展，只需要填写你的 mybili 网页地址。就能够实现自动无感知自动同步 cookie 到 mybili。

https://github.com/ellermister/mybili-cookie

1. 📥 打开项目地址，点击 "Code" -> "Download ZIP" 下载项目
2. 📦 将下载的项目解压到本地目录，长期使用请合理安排目录位置，如 `C:\mybili-cookie`
3. 🔧 打开 chrome 浏览器 `chrome://extensions/` 打开 "开发人员模式"
4. 📂 加载解压缩的扩展 选择目录 `C:\mybili-cookie` 以开启扩展
5. ⚙️ 点击新安装的扩展，在弹出的 popup.html 页面里填写你的 mybili 地址，截至到端口即可, 如 `http://192.168.1.200:5151`


### ⚙️ 3. 配置同步

在网页设置页面配置下载选项（默认关闭同步）。

访问：`http://your-ip:5151/settings`

**主要设置项：**
- **收藏夹同步**：总开关，每5分钟同步收藏夹信息
- **多P下载**：下载视频所有分段，否则仅下载第一个
- **弹幕下载**：下载视频弹幕到数据库
- **视频下载**：允许触发视频文件下载


## 🔄 更新说明

你可以在 github 或 docker hub 检视如果存在新版本，可以直接通过拉取最新镜像进行更新。

### ⚠️ 注意事项

1. 🔄 当前版本已经废除 redis 存储持久化数据，只用于写入临时缓存和异步队列用途
2. ⚙️ 当前版本指引中已经废除 .env 进行配置，采用环境变量进行覆盖配置，如果你有配置可以在 docker-compose.yml 中移除 .env 条目
3. 🍪 当前版本指引中已经废除 cookie.txt 文件映射，采用数据库进行存储，如果你有配置可以在 docker-compose.yml 中移除 cookie.txt 条目

### 常用控制台手动命令
- 同步「所有」收藏夹视频信息: `php artisan app:sync-media --fav-videos`
- 同步收藏夹信息 更新收藏夹本身的元数据（如标题、媒体数量等）: `php artisan app:sync-media --fav-list`
- 扫描并下载全部未下载的视频: `php artisan app:sync-media --download`
- 扫描本地全部缺失文件并下载: `php artisan app:scan-video-file --download`
- 「视频」封面图缺失: `php artisan app:scan-cover-image --target=video`
- 「收藏夹」封面图缺失: `php artisan app:scan-cover-image --target=favorite`
- 「订阅」封面图缺失: `php artisan app:scan-cover-image --target=subscription`
- 「UP主」头像图缺失: `php artisan app:scan-cover-image --target=upper`
- 全量视频转码: `php artisan app:transcode-all`
- 全量视频转码 状态查询: `php artisan app:transcode-all --status`
- 全量视频生成可读文件名: `php artisan app:make-human-readable-names`
- 全量视频评论缓存: `php artisan app:download-all-comment`
- 全量视频评论缓存 状态查询: `php artisan app:download-all-comment --status`
- 全量视频标签缓存: `php artisan app:download-tags`
- 全量视频标签缓存 状态查询: `php artisan app:download-tags --status`
- 全量视频弹幕缓存: `php artisan app:danmaku-download`
- 全量视频弹幕缓存 状态查询: `php artisan app:danmaku-download --status`
- 全量频播放量和播放时长信息缓存: `php artisan app:update-video-stats`
- 全量频播放量和播放时长信息缓存 状态查询: `php artisan app:update-video-stats --status`
- 扫描磁盘上已存在的手机版视频并同步到数据库（本地转码后同步到数据库）: `php artisan app:sync-mobile-videos`
- 重算系统信息页 - 全量计算系统数据库与媒体文件使用情况并存入缓存: `php artisan app:calculate-system-stats`

## 🚀 版本升级指南（老用户必读）

从旧版本升级到本版本**无需任何手动数据操作**，直接拉取新镜像重启即可平滑过渡：
所有数据库变更均为「加法」（新增索引/新表），读写路径对新旧数据双格式兼容，
**不会删除或改写你已有的视频、弹幕、评论数据**。

升级后建议按需执行以下可选优化（均为独立命令，可随时中断、反复执行）：

| 命令 | 作用 | 是否改动数据 |
|---|---|---|
| `php artisan app:danmaku-migrate-to-files` | 将数据库历史弹幕迁移到 JSON.gz 文件（DB 预计瘦身约 40-50%） | 迁移（先写文件校验后才清行，不丢数据） |
| `php artisan app:comments-backfill-users` | 把评论中的用户名/头像回填到 `comment_users` 归一化表 | 只加不删 |
| `php artisan app:comments-reclaim-space [--vacuum]` | 清空评论行内冗余 uname/avatar、压缩表情映射（canonical 数据已保留） | 单向瘦身（数据不丢失） |
| `php artisan app:cleanup-orphan-data` | 报告历史遗留的软删除视频与孤儿关联数据；加 `--force` 才清理 | 默认只报告 |
| `php artisan app:database-maintenance --init\|--analyze\|--vacuum` | SQLite WAL 初始化 / 统计信息 / 空间回收（已纳入定时任务） | 无 |

> 💡 弹幕文件默认存放于 `storage/app/public/danmaku`（官方 docker-compose 已映射
> `./data:/app/storage/app/public`，容器升级不丢数据）。如需自定义位置，
> 请设置环境变量 `DANMAKU_PATH` 并自行映射持久化目录。

### 📦 备份建议

收藏的视频属于不可再生资源（B 站稿件随时可能失效/被删），请务必定期备份：

1. **数据库**（含评论/收藏元数据）：停机复制 `database.sqlite`，或在线执行
   `sqlite3 /data/database.sqlite ".backup /backup/database-$(date +%F).sqlite"`
2. **弹幕文件**：备份 `storage/app/public/danmaku` 目录
3. **媒体文件**：视频/图片目录（`storage/app/public/videos|images|medias` 等）按容量规划备份

### 🔒 隐私声明

- **使用情况统计（默认关闭，严格 opt-in）**：仅当你在「设置 → 使用情况统计」显式开启后，
  程序才会每日上报一条匿名事件（随机匿名 ID、程序版本、运行环境、PHP 版本）到 umami
  统计服务，用于改进项目。**不包含** B 站账号、Cookie、视频、评论等任何业务数据。
  随时可在设置页关闭，或移除 `WEBSITE_ID` 环境变量彻底禁用。
- **Cookie 存储**：你的 B 站 Cookie 仅保存在本地数据库 `settings` 表中，
  用于访问 B 站 API，不会上传到任何第三方服务。
- **日志**：`storage/logs` 中的本地日志可能包含视频标题等调试信息，仅供排障使用，
  可在「日志页面」随时清空。

## 🏗️ 构建与发布镜像（维护者）

项目根目录提供 `build-and-push.sh` 一键构建并发布 Docker 镜像：

```bash
# 1. 先登录 Docker Hub
docker login

# 2. 构建并推送（自动打 :latest 与 :版本号 两个标签）
./build-and-push.sh
```

**版本号来源**：`.env` 中的 `APP_VERSION`（如 `APP_VERSION=1.4.0`），脚本会将其：

1. 作为 `--build-arg APP_VERSION` 传入镜像（决定系统信息页显示的版本号）；
2. 作为镜像标签与 `latest` **一并推送**，例如：
   `llllalex/mybili:1.4.0` 与 `llllalex/mybili:latest`。

发布新版本时只需修改 `.env` 中的 `APP_VERSION` 后重新执行脚本。

**常用选项：**

| 选项 | 说明 |
|---|---|
| `--mirror cn` | **国内网络一键加速**：切换 apt/apk/npm/Composer 为国内镜像源 |
| `--no-push` | 仅本地构建不推送（多平台自动降级为当前平台） |
| `--platforms STR` | 目标平台，默认 `linux/amd64,linux/arm64`（与 CI 一致） |
| `--image NAME` | 镜像名，默认 `llllalex/mybili` |
| `--rebuild-base` | 强制重建基础镜像 `mybili-base:1`（默认缺失时才自动构建） |
| `--cache` | 启用 Docker Hub registry 构建缓存（`:buildcache`） |
| `--dry-run` | 只打印将执行的命令，不实际执行 |

**其他说明：**

- 前置依赖：Docker（含 buildx 插件）；首次构建会自动构建基础镜像 `mybili-base:1`（耗时较长，之后有缓存）；
- `.env` 中配置 `WEBSITE_ID`（或以环境变量传入）会作为构建参数注入镜像，用于匿名使用统计（默认不开启，详见隐私声明）；
- 构建期如需代理，可设置 `HTTP_PROXY` / `HTTPS_PROXY` 环境变量，脚本会自动透传；
- CI（GitHub Actions）在推送 main / tag 时也会自动构建多架构镜像，本地脚本与其构建参数保持一致。

### 🌏 国内网络环境构建

构建时需访问 Debian/npm/Composer/GitHub 等境外源，国内网络直连容易超时
（典型报错：`Could not connect to deb.debian.org:80, connection timed out`）。
推荐以下任一方式：

```bash
# 方式一（推荐）：一键切换国内镜像源（TUNA apt/apk + npmmirror + 阿里云 Composer）
./build-and-push.sh --mirror cn

# 方式二：逐项自定义（优先级高于 --mirror 预设）
APT_MIRROR=mirrors.aliyun.com \
NPM_REGISTRY=https://registry.npmmirror.com \
./build-and-push.sh --mirror cn

# 方式三：构建全程走代理（可覆盖 GitHub 等下载）
HTTP_PROXY=http://192.168.0.2:1082 ./build-and-push.sh
```

说明：

- `--mirror` 可选 `cn/tuna`（清华）、`aliyun`（阿里云）、`ustc`（中科大）；
- 基础镜像 `Dockerfile.base` 中的 GitHub 资源下载（yt-dlp/frankenphp/supervisord/ffmpeg）
  无稳定国内镜像，如仍失败请使用代理方式（方式三）；
- 如拉取 `node:22-bookworm-slim`、`phpswoole/swoole` 等基础镜像失败，
  属于 Docker Hub 拉取问题，请为 Docker daemon 配置 `registry-mirrors` 或代理。

## 🧰 数据库性能优化（本版本新增）

- SQLite 自动启用 **WAL 模式 + busy_timeout(5s)**：多进程并发读写不再频繁
  `database is locked`（覆盖 Web / 队列 / 命令的每一条连接）。
- **每日 03:40** 自动 `PRAGMA optimize` 更新查询统计信息；
  **每周日 05:45** 自动 `VACUUM + ANALYZE` 回收数据库空间。
- 弹幕按 cid 存储为 `JSON.gz` 单文件后，播放弹幕无需再从数据库反序列化大结果集。