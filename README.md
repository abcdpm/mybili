
同步收藏夹信息 更新收藏夹本身的元数据（如标题、媒体数量等）：
php artisan app:update-fav --update-fav
php artisan app:update-fav --update-fav=1
更新收藏夹封面图：
php artisan app:scan-cover-image --target=favorite

同步收藏夹视频信息：
php artisan app:update-fav --update-fav-videos=1
php artisan app:update-fav --update-fav-videos
查看收藏夹视频信息数据量：
php artisan tinker --execute="echo App\Models\VideoPart::count();"

扫描数据库中已有的记录去下载文件：
php artisan app:update-fav --download-video-part=1

手动全量视频转码：
php artisan app:transcode-all
php artisan app:transcode-all --force
php artisan app:transcode-all --force --hwaccel=qsv
php artisan app:transcode-all --force --hwaccel=nvenc
php artisan app:transcode-all --status
php artisan app:transcode-all --hwaccel=nvenc

手动全量视频可读文件名：
php artisan app:make-human-readable-names

删除Redis脏数据：
php artisan tinker
\Illuminate\Support\Facades\Redis::del('video_list');

触发全量视频评论备份：
php artisan app:download-all-comment
php artisan app:download-all-comment --limit=60 --force
php artisan app:download-all-comment --limit=80 --force --sleep=10
php artisan app:download-all-comment --limit=60 --force 115936803686117
php artisan app:download-all-comment --sleep=10
php artisan app:download-all-comment --status

清空积压的 Job：
php artisan horizon:clear
php artisan queue:flush
redis-cli flushall

docker build --build-arg APP_VERSION=1.0.3 -t llllalex/mybili:1.0.3 . --no-cache
docker push llllalex/mybili:1.0.3
docker tag llllalex/mybili:1.0.3 llllalex/mybili:latest
docker push llllalex/mybili:latest

扫描磁盘上已存在的手机版视频并同步到数据库
php artisan app:sync-mobile-videos


查看堆积的任务类型
php artisan tinker
执行统计脚本

// --- 复制开始 ---
$queueName = 'default'; // 如果想查默认队列，改为 'default' 'slow' 'fast'
$connection = 'redis';

// 获取 Redis 实例
$redis = app('queue')->connection($connection)->getRedis()->connection();

// 获取队列全名 (Laravel 自动处理前缀)
$prefix = config('database.redis.options.prefix', '');
$queueKey = 'queues:' . $queueName;

// 抽样取出前 10000 条任务 (不会删除任务)
$jobs = $redis->lrange($queueKey, 0, 10000);

$stats = [];
foreach ($jobs as $jobJson) {
    $job = json_decode($jobJson, true);
    $commandName = $job['displayName'] ?? 'Unknown';

    // 简化类名显示
    $parts = explode('\\', $commandName);
    $shortName = end($parts);

    if (!isset($stats[$shortName])) {
        $stats[$shortName] = 0;
    }
    $stats[$shortName]++;
}

// 按数量降序排列
arsort($stats);
print_r($stats);
// --- 复制结束 ---


## 所有控制台 PHP 命令 (php artisan ...) 及其参数汇总
### 1. 视频与评论管理
#### 下载/更新所有评论
app:download-all-comments

批量下载或更新视频的评论区数据。

参数:

video_id (可选): 指定单个视频的 ID (数据库 ID)。

选项:

--force: 强制重新下载（即使评论已存在）。

--limit=: 自定义下载的评论数量。

--sleep=3: 每次 API 请求后的休眠时间（秒），默认为 3 秒。

--status: 查看当前评论下载进度的统计信息（不执行下载任务）。

#### 视频转码 (移动端兼容)
app:transcode-all

将已下载的视频转码为移动端（手机/Web）兼容的格式 (通常是 H.264/AAC)。

参数:

video_id (可选): 指定单个视频的 ID。

选项:

--force: 强制重新转码，即使目标文件已存在。

--hwaccel=cpu: 指定硬件加速模式。支持值：

cpu (默认): 使用 CPU 软解。

qsv: Intel 核显加速 (需设备映射 /dev/dri)。

nvenc: Nvidia 显卡加速 (需配置 NVIDIA Runtime)。

--status: 查看当前转码进度的统计信息。

#### 同步手机版视频
app:sync-mobile-videos

扫描磁盘上已存在的手机版转码视频，并将信息同步到数据库中。

无参数

#### 更新无分P信息的视频
app:update-no-parts-valid-video

扫描并修复数据库中状态正常但缺少分P（Video Parts）信息的视频。

选项:

--id=: 指定单个视频 ID。

--force: 强制更新。

### 2. 收藏夹与订阅管理
#### 更新收藏夹 (核心同步命令)
app:update-fav

处理收藏夹的元数据同步、视频列表更新及分P信息拉取。

选项 (通常组合使用):

--update-fav=true: 仅更新收藏夹本身的元数据（标题、封面、数量）。

--update-fav-videos=true: 扫描收藏夹内的视频列表，发现新视频。

--update-fav-videos-page=: 仅更新指定页码的视频列表。

--update-video-parts=true: 拉取视频的分P（子视频）详情。

--update-video-parts-video-id=: 仅拉取指定视频 ID 的分P。

--download-video-part=true: 触发分P视频文件的下载任务。

--fix-invalid-fav-videos=true: 检查并修复收藏夹中的失效视频。

--fix-invalid-fav-videos-page=: 修复指定页码的失效视频。

### 3. 文件与系统维护
#### 生成人类可读文件名
app:make-human-readable-names

基于视频标题创建硬链接或符号链接，生成 Emby/Plex 友好的目录结构。

无参数

#### 扫描视频文件
app:scan-video-file

扫描本地磁盘，检查数据库记录的视频文件是否存在。

选项:

--video-id=: 指定视频 ID。

--force: 强制扫描。

--download: 如果文件缺失，触发重新下载。

#### 扫描/下载封面图
app:scan-cover-image

扫描并下载缺失的封面图片。

选项:

--target=: 扫描目标，支持 favorite (收藏夹) 或 subscription (订阅)。

--id=: 指定特定的收藏夹或订阅 ID。

#### Redis 数据迁移
app:upgrade-redis-to-sqlite

将旧版本存储在 Redis 中的数据迁移到 SQLite/MySQL 数据库。

选项:

--all: 迁移所有数据（推荐）。

--favorite-list: 仅迁移收藏夹列表。

--video: 仅迁移视频信息。

--video-part: 仅迁移分P信息。

--danmaku: 仅迁移弹幕。

--settings: 仅迁移设置。

#### 频率限制状态
scheduled-rate-limit:status

查看或重置任务调度器的频率限制状态。

选项:

--list: 列出所有限制键。

--schedule=KEY: 查看指定键的调度情况。

--reset=KEY: 重置指定键的限制。

发送统计信息
stats:send

手动触发发送匿名使用统计数据（程序版本、环境信息等）。

无参数

### 4. 其他工具
#### 下载弹幕
app:danmaku-download

下载指定视频的弹幕到文件。

参数:

id: 视频 ID (通常是数据库 ID 或 Bilibili ID)。

filename: 保存的文件名。

## 🎥 Mybili

**bilibili 收藏夹下载工具** - 你的NAS中必不可少的程序

<img src="./mybili.png" alt="Mybili Logo" width="256" height="256" />

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
        image: ellermister/mybili
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


### 📝 4. 日志排查

在容器内部，存储了多份日志，来源于不同的服务产生的文件。
```bash
/app # ls /var/log
queue.log.0        schedule.log.0     supervisord.log.0  web.log.0
```

#### 🌐 Web 服务日志
网页不通或者异常报错，可以查看 laravel 的日志
```bash
tail -f /app/storage/logs/laravel.log
```

## 🔄 更新说明

你可以在 github 或 docker hub 检视如果存在新版本，可以直接通过拉取最新镜像进行更新。

### ⚠️ 注意事项

1. 🔄 当前版本已经废除 redis 存储持久化数据，只用于写入临时缓存和异步队列用途
2. ⚙️ 当前版本指引中已经废除 .env 进行配置，采用环境变量进行覆盖配置，如果你有配置可以在 docker-compose.yml 中移除 .env 条目
3. 🍪 当前版本指引中已经废除 cookie.txt 文件映射，采用数据库进行存储，如果你有配置可以在 docker-compose.yml 中移除 cookie.txt 条目

## 💓 支持本项目

如果你喜欢这个项目，或者它对你有帮助，请考虑支持我！

### 💰 赞助方式

你可以通过以下方式支持这个项目：

- **Buy me a coffee**: [买一杯咖啡](https://buymeacoffee.com/ellermister)
- **USDT**: TRC20 `TRjWTbPfQBhHawCD8DrfLGa8ECbhPP6F3b`
- **LTC**: Litecoin `LdH6SxbAq3No9P4zaNR2aGgH9Kr9yfuGHi`
- **爱发电**  [去支持一下](https://afdian.com/a/eller)

任何形式的支持都将帮助我继续改进和维护这个项目，非常感谢！

## Star History

[![Star History Chart](https://api.star-history.com/svg?repos=ellermister/mybili&type=Date)](https://www.star-history.com/#ellermister/mybili&Date)