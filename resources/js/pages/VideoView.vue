<template>
    <div class="m-4">
        <Breadcrumbs :items="breadcrumbItems">
            <template #actions>
                <div class="text-sm text-gray-600 bg-gray-100 px-3 py-1 rounded-full hidden md:block">
                    {{ videoId }}
                </div>
            </template>
        </Breadcrumbs>

        <div class="space-y-4" v-if="videoInfo != null">
            <!-- 【B站样式】标题+UP主信息横跨全宽（延伸到分P清单上方）；ref 用于进入页面时标题与窗口顶部齐平 -->
            <div ref="titleHeaderRef" class="bg-white/90 backdrop-blur-sm rounded-xl shadow-lg border border-gray-200/50 p-4">
                        <div class="flex flex-col md:flex-row md:items-center gap-4">
                            <div class="flex-1 min-w-0">
                                <!-- 移动端（第16项）：标题/元信息自适应字号；标题即"在哔哩哔哩观看"入口（原按钮改为超链接） -->
                                <h2 class="text-xl md:text-2xl font-bold leading-tight break-words">
                                    <a :href="bilibiliUrl(videoInfo)" target="_blank" rel="noopener noreferrer"
                                        class="text-gray-800 hover:text-pink-600 hover:underline decoration-2 underline-offset-4 transition-colors duration-200">
                                        {{ videoInfo.title }}
                                        <span class="inline-block text-base text-gray-400 hover:text-pink-500 align-middle ml-1">↗</span>
                                    </a>
                                </h2>
                                <!-- 元信息行：播放量 / 弹幕数 / 视频发布时间 -->
                                <div class="mt-2 flex flex-wrap items-center gap-x-3 sm:gap-x-4 gap-y-1 text-xs sm:text-sm text-gray-600">
                                    <span class="inline-flex items-center">
                                        <span class="mr-1 text-blue-500">▶</span>
                                        {{ t('video.viewCount') }} {{ (videoInfo.view ?? 0).toLocaleString() }}
                                    </span>
                                    <span class="inline-flex items-center">
                                        <span class="mr-1 text-purple-500">💬</span>
                                        {{ t('video.danmakuCount') }} {{ (videoInfo.danmaku_count ?? 0).toLocaleString() }}
                                    </span>
                                    <span class="inline-flex items-center">
                                        <span class="mr-1 text-green-500">🕐</span>
                                        {{ t('video.publishTime') }} {{ formatTimestamp(videoInfo.pubtime, "yyyy-mm-dd hh:ii:ss") }}
                                    </span>
                                    <!-- 第21项：连播开关（苹果风格 iOS 开关）+ 连播范围胶囊标签 -->
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                        :class="autoPlayNext ? 'bg-green-100 text-green-700 border border-green-200' : 'bg-gray-100 text-gray-500 border border-gray-200'">
                                        {{ autoPlayScopeLabel }}
                                    </span>
                                    <label class="inline-flex items-center cursor-pointer select-none" :title="t('video.autoplay')">
                                        <span class="text-xs sm:text-sm text-gray-600 mr-2">{{ t('video.autoplay') }}</span>
                                        <!-- 苹果 iOS 风格开关：圆角轨道 + 白色圆形滑块平移 -->
                                        <span class="relative inline-block">
                                            <input type="checkbox" v-model="autoPlayNext" class="sr-only peer">
                                            <div class="w-[50px] h-[30px] rounded-full bg-gray-200 peer-checked:bg-green-500 transition-colors duration-300 ease-in-out after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:h-[26px] after:w-[26px] after:rounded-full after:bg-white after:shadow-[0_2px_5px_rgba(0,0,0,0.25)] after:transition-transform after:duration-300 after:ease-in-out peer-checked:after:translate-x-[20px]"></div>
                                        </span>
                                    </label>
                                </div>
                            </div>
                            <!-- 标题右侧：UP 主信息 -->
                            <div v-if="videoInfo.upper" class="flex items-center space-x-3 bg-gradient-to-r from-gray-50 to-gray-100 rounded-lg border border-gray-200/50 px-3 py-2 flex-shrink-0">
                                <div class="w-10 h-10 rounded-full overflow-hidden flex-shrink-0 bg-gradient-to-br from-pink-400 to-purple-500">
                                    <img :src="videoInfo.upper.cover_info?.image_url" alt="UP主头像" class="w-full h-full object-cover">
                                </div>
                                <div class="min-w-0 cursor-pointer" @click="openUpperSpace(videoInfo.upper.mid)">
                                    <h3 class="font-semibold text-gray-800 truncate">{{ videoInfo.upper.name }}</h3>
                                    <span class="text-xs text-gray-500">UID: {{ videoInfo.upper.mid }}</span>
                                </div>
                                <a :href="upperSpaceUrl(videoInfo.upper.mid)" target="_blank" rel="noopener noreferrer"
                                    class="hidden md:inline-flex items-center space-x-1 px-2.5 py-1.5 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 hover:border-gray-400 transition-all duration-200 text-xs font-medium text-gray-700 hover:text-gray-900 flex-shrink-0">
                                    <span>{{ t('video.visitSpace') }}</span>
                                    <span class="text-gray-400">↗</span>
                                </a>
                            </div>
                        </div>
                    </div>

            <!-- 播放器与侧栏（分P清单/播放清单） -->
            <div class="flex flex-col lg:flex-row gap-4">
                <div ref="leftColRef" class="flex-1 min-w-0">
                    <div ref="playerContainer" id="playerContainer" 
                        class="-mx-6 md:mx-0 bg-white shadow-lg overflow-hidden md:border border-gray-200/50 mb-4">
                        <Player ref="playerRef" @ready="onPlayerReady" @ended="onPlayerEnded" :danmaku="danmaku" 
                            :url="currentPart?.url ?? ''" />
                    </div>

                    <div class="bg-white/90 backdrop-blur-sm rounded-xl shadow-lg border border-gray-200/50 p-4 mb-4">
                        <div class="space-y-4">
                            <!-- 收藏时间（其余元信息保留在描述卡片中） -->
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-500">
                                <span class="inline-flex items-center">
                                    <span class="mr-1">⭐</span>
                                    {{ t('video.favoriteTime') }}：
                                    {{ videoInfo.fav_time ? formatTimestamp(videoInfo.fav_time, "yyyy-mm-dd hh:ii:ss") : '-' }}
                                </span>
                            </div>

                            <div class="bg-gray-50 rounded-lg p-4">
                                <h3 class="text-lg font-semibold text-gray-700 mb-2 flex items-center">
                                    <span class="w-1.5 h-1.5 bg-gray-400 rounded-full mr-2"></span>
                                    {{ t('video.videoDescription') }}
                                </h3>
                                <div class="mt-4 text-sm text-gray-700 dark:text-gray-300">
                                    <p class="whitespace-pre-wrap" v-if="videoInfo?.intro">{{ videoInfo.intro }}</p>
                                    <div class="video-tag-container" v-if="videoInfo?.tags && videoInfo.tags.length > 0">
                                        <div class="tag-panel">
                                            <div class="tag not-btn-tag" v-for="tag in videoInfo.tags" :key="tag.tag_id">
                                                <div v-if="tag.tag_type === 'bgm'" class="bgm-tag">
                                                    <a :href="tag.jump_url || `https://search.bilibili.com/all?keyword=${encodeURIComponent(tag.tag_name)}`" target="_blank" :title="tag.tag_name" class="tag-link bgm-link">
                                                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg" class="tag-icon bgm-tag-icon"><path fill-rule="evenodd" clip-rule="evenodd" d="M12.4934 0.804517C12.1107 0.492939 11.6138 0.372384 11.1549 0.468068L4.9118 1.47082C4.18689 1.62246 3.66112 2.27118 3.66112 3.0135V9.53936C3.44543 9.46268 3.2139 9.41881 2.97203 9.41881C1.83057 9.41881 0.904785 10.347 0.904785 11.4914C0.904785 12.6361 1.83057 13.564 2.97203 13.564C4.10902 13.564 5.03067 12.6437 5.03859 11.5059L5.03928 4.16828C5.03928 4.07708 5.1044 3.99694 5.17055 3.98278L11.4133 2.98038C11.5156 2.95758 11.589 3.00318 11.6242 3.0315C11.6586 3.05983 11.7168 3.12304 11.7168 3.22771V7.81225C11.5012 7.73556 11.2696 7.69169 11.0278 7.69169C9.88629 7.69169 8.96051 8.61986 8.96051 9.76427C8.96051 10.909 9.88629 11.8369 11.0278 11.8369C12.1647 11.8369 13.0864 10.9166 13.0943 9.77878L13.095 2.07259C13.095 1.57828 12.8755 1.11609 12.4934 0.804517Z" fill="currentColor"></path></svg>
                                                        <span class="tag-txt">{{ tag.tag_name }}</span>
                                                    </a>
                                                </div>
                                                <div v-else-if="tag.tag_type === 'topic'" class="topic-tag">
                                                    <a :href="tag.jump_url || `https://search.bilibili.com/all?keyword=${encodeURIComponent(tag.tag_name)}`" target="_blank" :title="tag.tag_name" class="tag-link topic-link">
                                                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" class="tag-icon topic-tag-icon"><path fill-rule="evenodd" clip-rule="evenodd" d="M5.15142 1.70791C5.36429 1.56008 5.6567 1.61281 5.80453 1.82568L6.92003 3.432C7.26888 3.42615 7.62969 3.42286 8.00007 3.42286C8.3704 3.42286 8.73116 3.42615 9.07998 3.432L10.1955 1.82568C10.3433 1.61281 10.6357 1.56008 10.8486 1.70791C11.0615 1.85574 11.1142 2.14814 10.9664 2.36102L10.203 3.46026C10.9526 3.48536 11.6215 3.52013 12.1791 3.5552C13.4364 3.63429 14.4433 4.60659 14.5376 5.87199C14.5966 6.66359 14.648 7.66253 14.648 8.7412C14.648 9.82395 14.5962 10.8185 14.5369 11.6027C14.4427 12.8488 13.4594 13.8117 12.2205 13.903C11.156 13.9815 9.67595 14.0596 8.00007 14.0596C6.32435 14.0596 4.8444 13.9815 3.77995 13.903C2.54085 13.8117 1.55749 12.8486 1.46327 11.6024C1.40396 10.8179 1.35214 9.82325 1.35214 8.7412C1.35214 7.66322 1.40358 6.66415 1.46258 5.87228C1.55688 4.60679 2.56384 3.63427 3.82138 3.55518C4.3788 3.52012 5.04762 3.48536 5.79702 3.46027L5.03365 2.36102C4.88582 2.14814 4.93855 1.85574 5.15142 1.70791ZM8.00007 4.36139C6.36941 4.36139 4.92598 4.4261 3.88029 4.49186C3.08157 4.5421 2.45732 5.15291 2.39852 5.94202C2.34078 6.71684 2.29067 7.69194 2.29067 8.7412C2.29067 9.79434 2.34115 10.7648 2.39913 11.5316C2.45778 12.3074 3.06585 12.9093 3.84894 12.967C4.89619 13.0442 6.35249 13.121 8.00007 13.121C9.6478 13.121 11.1042 13.0442 12.1515 12.967C12.9345 12.9093 13.5424 12.3075 13.6011 11.5319C13.659 10.7654 13.7095 9.79506 13.7095 8.7412C13.7095 7.69122 13.6594 6.71625 13.6017 5.94173C13.5429 5.15277 12.9188 4.54211 12.1201 4.49188C11.0744 4.42611 9.63088 4.36139 8.00007 4.36139ZM4.55172 7.40615C4.55172 7.16699 4.74559 6.97312 4.98475 6.97312H6.52431L6.69171 5.74556C6.72402 5.5086 6.94231 5.3427 7.17928 5.37501C7.41624 5.40732 7.58214 5.62562 7.54983 5.86258L7.39839 6.97312H8.92735L9.09475 5.74556C9.12706 5.5086 9.34535 5.3427 9.58231 5.37501C9.81928 5.40732 9.98518 5.62562 9.95287 5.86258L9.80143 6.97312H10.8589C11.0981 6.97312 11.2919 7.16699 11.2919 7.40615C11.2919 7.64531 11.0981 7.83918 10.8589 7.83918H9.68333L9.43736 9.643H10.8589C11.0981 9.643 11.2919 9.83688 11.2919 10.076C11.2919 10.3152 11.0981 10.5091 10.8589 10.5091H9.31926L9.15184 11.7367C9.11953 11.9737 8.90124 12.1396 8.66428 12.1073C8.42731 12.075 8.26141 11.8567 8.29372 11.6197L8.44518 10.5091H6.91622L6.74881 11.7367C6.71649 11.9737 6.4982 12.1396 6.26124 12.1073C6.02427 12.075 5.85837 11.8567 5.89068 11.6197L6.04214 10.5091H4.98475C4.74559 10.5091 4.55172 10.3152 4.55172 10.076C4.55172 9.83688 4.74559 9.643 4.98475 9.643H6.16024L6.40621 7.83918H4.98475C4.74559 7.83918 4.55172 7.64531 4.55172 7.40615ZM8.56328 9.643L8.80925 7.83918H7.28029L7.03432 9.643H8.56328Z" fill="currentColor"></path></svg>
                                                        <span class="tag-txt">{{ tag.tag_name }}</span>
                                                    </a>
                                                </div>
                                                <div v-else class="ordinary-tag">
                                                    <a :href="`https://search.bilibili.com/all?keyword=${encodeURIComponent(tag.tag_name)}`" target="_blank" class="tag-link">
                                                        {{ tag.tag_name }}
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-nowrap overflow-x-auto gap-1.5 pt-3 border-t border-gray-200/50">
                                <button @click="downloadVideo" class="flex flex-shrink-0 whitespace-nowrap items-center justify-center space-x-1 rounded-lg px-2 py-1.5 border bg-gradient-to-r from-blue-50 to-indigo-50 border-blue-200/50 hover:shadow hover:from-blue-100 hover:to-indigo-100 transition-all duration-300 group">
                                    <span class="text-blue-500 text-sm group-hover:scale-110 transition-transform duration-300">🎬</span>
                                    <span class="text-xs font-semibold text-gray-700 group-hover:text-blue-800">{{ t('video.downloadVideo') }}</span>
                                </button>

                                <button @click="downloadDanmaku" class="flex flex-shrink-0 whitespace-nowrap items-center justify-center space-x-1 rounded-lg px-2 py-1.5 border bg-gradient-to-r from-purple-50 to-pink-50 border-purple-200/50 hover:shadow hover:from-purple-100 hover:to-pink-100 transition-all duration-300 group">
                                    <span class="text-purple-500 text-sm group-hover:scale-110 transition-transform duration-300">💬</span>
                                    <span class="text-xs font-semibold text-gray-700 group-hover:text-purple-800">{{ t('video.downloadDanmaku') }}</span>
                                </button>

                                <button @click="downloadCover" class="flex flex-shrink-0 whitespace-nowrap items-center justify-center space-x-1 rounded-lg px-2 py-1.5 border bg-gradient-to-r from-pink-50 to-rose-50 border-pink-200/50 hover:shadow hover:from-pink-100 hover:to-rose-100 transition-all duration-300 group">
                                    <span class="text-pink-500 text-sm group-hover:scale-110 transition-transform duration-300">🖼️</span>
                                    <span class="text-xs font-semibold text-gray-700 group-hover:text-pink-800">{{ t('video.downloadCover') }}</span>
                                </button>

                                <button @click="openDeleteModal" class="flex flex-shrink-0 whitespace-nowrap items-center justify-center space-x-1 rounded-lg px-2 py-1.5 border bg-gradient-to-r from-red-50 to-rose-50 border-red-200/50 hover:shadow hover:from-red-100 hover:to-rose-100 transition-all duration-300 group">
                                    <span class="text-red-500 text-sm group-hover:scale-110 transition-transform duration-300">🗑️</span>
                                    <span class="text-xs font-semibold text-gray-700 group-hover:text-red-800">{{ t('video.deleteVideo') }}</span>
                                </button>
                            </div>

                            <div class="grid grid-cols-3 gap-3 pt-1">
                                <button @click="handleUpdateStats" class="flex flex-shrink-0 whitespace-nowrap items-center justify-center space-x-1 rounded-lg px-2 py-1.5 border bg-gradient-to-r from-amber-50 to-yellow-50 border-amber-200/50 hover:shadow hover:from-amber-100 hover:to-yellow-100 transition-all duration-300 group">
                                    <span class="text-amber-500 text-sm group-hover:scale-110 transition-transform duration-300">📊</span>
                                    <span class="text-xs font-semibold text-gray-700 group-hover:text-amber-800">{{ t('video.updateStat') }}</span>
                                </button>
                                
                                <button @click="handleUpdateDanmaku" class="flex flex-shrink-0 whitespace-nowrap items-center justify-center space-x-1 rounded-lg px-2 py-1.5 border bg-gradient-to-r from-orange-50 to-amber-50 border-orange-200/50 hover:shadow hover:from-orange-100 hover:to-amber-100 transition-all duration-300 group">
                                    <span class="text-orange-500 text-sm group-hover:scale-110 transition-transform duration-300">🔄</span>
                                    <span class="text-xs font-semibold text-gray-700 group-hover:text-orange-800">{{ t('video.updateDanmaku') }}</span>
                                </button>

                                <button @click="handleUpdateComments" class="flex flex-shrink-0 whitespace-nowrap items-center justify-center space-x-1 rounded-lg px-2 py-1.5 border bg-gradient-to-r from-teal-50 to-emerald-50 border-teal-200/50 hover:shadow hover:from-teal-100 hover:to-emerald-100 transition-all duration-300 group">
                                    <span class="text-teal-500 text-sm group-hover:scale-110 transition-transform duration-300">📝</span>
                                    <span class="text-xs font-semibold text-gray-700 group-hover:text-teal-800">{{ t('video.updateComment') }}</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <VideoComments v-if="videoInfo && videoInfo.id" :video-id="videoInfo.id" :upper-id="videoInfo.upper_id" />
                </div>

                <!-- 第21项：侧栏 = 分P列表（置顶固定高度内滚动） + 同收藏夹播放清单（平铺下延到评论区） -->
                <div ref="rightColRef" class="w-full lg:w-72 lg:shrink-0 flex flex-col gap-3"
                    v-if="videoInfo && ((videoInfo.video_parts && videoInfo.video_parts.length > 1) || playlist.length > 0)">
                    <div v-if="videoInfo.video_parts && videoInfo.video_parts.length > 1"
                        class="bg-white/90 backdrop-blur-sm rounded-xl shadow-lg border border-gray-200/50 p-3 flex flex-col"
                        :style="{ height: sidebarHeight }">
                        <h3 class="text-xl font-semibold mb-3 text-gray-800 flex items-center flex-shrink-0">
                            <span class="w-2 h-2 bg-gradient-to-r from-pink-500 to-purple-600 rounded-full mr-2"></span>
                            {{ t('video.videoParts') }}&nbsp;
                            <span class="text-gray-500 text-sm font-normal">
                                ({{ videoInfo.video_parts.findIndex(part => part.id === currentPart?.id) + 1 }}/{{ videoInfo.video_parts.length }})
                            </span>
                        </h3>
                        <div class="space-y-1 overflow-y-auto flex-1 min-h-0 pr-1 custom-scrollbar">
                            <button v-for="part in videoInfo?.video_parts" :key="part.id" @click="playPart(part.id)"
                                class="w-full px-3 py-2 text-left rounded-lg transition-all duration-300 group relative overflow-hidden"
                                :class="{
                                    'bg-gradient-to-r from-pink-500 to-purple-600 text-white shadow': currentPart?.id === part.id && part.downloaded,
                                    'bg-gray-50 hover:bg-gray-100 text-gray-700 hover:text-gray-900': currentPart?.id !== part.id && part.downloaded,
                                    'bg-gray-100 text-gray-500 cursor-not-allowed': !part.downloaded
                                }">
                                <div class="flex items-center justify-between">
                                    <span class="font-medium truncate">{{ part.title }}</span>
                                    <span v-if="currentPart?.id === part.id" class="text-white/80">▶</span>
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- 同收藏夹播放清单（平铺展示：不设内嵌滚轮，随页面滚动自动加载；
                         起点=当前视频前 2 条；封面+标题+UP主，播放量/弹幕以图标标识，不显示总数） -->
                    <div v-if="playlist.length > 0"
                        class="bg-white/90 backdrop-blur-sm rounded-xl shadow-lg border border-gray-200/50 p-3">
                        <h3 class="text-lg font-semibold mb-2 text-gray-800 flex items-center">
                            <span class="w-2 h-2 bg-gradient-to-r from-amber-400 to-orange-500 rounded-full mr-2"></span>
                            {{ t('video.playlist') }}
                        </h3>
                        <div class="space-y-2">
                            <button v-for="item in displayedPlaylist" :key="item.id"
                                :data-active="item.id === videoInfo?.id ? 'true' : 'false'"
                                @click="navigateToVideo(item.id)"
                                class="w-full flex gap-2.5 p-1.5 rounded-lg transition-all duration-200 text-left"
                                :class="item.id === videoInfo?.id
                                    ? 'bg-amber-50 ring-1 ring-amber-300 shadow-sm'
                                    : 'hover:bg-gray-50'">
                                <!-- 封面（右下角时长角标，照搬B站样式） -->
                                <div class="relative w-[112px] flex-shrink-0">
                                    <div class="w-full aspect-video rounded-md overflow-hidden bg-gray-100">
                                        <img v-if="item.cover_image_url || item.cover" :src="item.cover_image_url || item.cover"
                                            loading="lazy" class="w-full h-full object-cover" alt="">
                                        <div v-else class="w-full h-full flex items-center justify-center text-gray-300 text-xs">无封面</div>
                                    </div>
                                    <span class="absolute bottom-1 right-1 px-1 rounded bg-black/70 text-white text-[10px] leading-4 font-medium">
                                        {{ formatDuration(item.duration) }}
                                    </span>
                                </div>
                                <!-- 标题 + UP主 + 播放量·弹幕（图标标识） -->
                                <div class="flex-1 min-w-0 flex flex-col justify-between py-0.5">
                                    <h4 class="text-[13px] font-medium text-gray-800 leading-snug line-clamp-2"
                                        :class="item.id === videoInfo?.id ? 'text-amber-700' : ''">
                                        {{ item.title }}
                                    </h4>
                                    <div class="text-[11px] text-gray-500 space-y-0.5">
                                        <div class="flex items-center gap-1 truncate">
                                            <span class="text-gray-400">👤</span>
                                            <span class="truncate">{{ item.upper_name ?? '-' }}</span>
                                        </div>
                                        <div class="flex items-center gap-2.5">
                                            <!-- 播放量/弹幕数：图标标识（不显示中文标签） -->
                                            <span class="inline-flex items-center gap-0.5" :title="t('video.viewCount')">
                                                <span class="text-blue-400">▶</span>{{ formatCount(item.view) }}
                                            </span>
                                            <span class="inline-flex items-center gap-0.5" :title="t('video.danmakuCount')">
                                                <span class="text-purple-400">💬</span>{{ formatCount(item.danmaku_count) }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </button>
                            <!-- 随页面滚动自动加载（到底自动追加） -->
                            <div v-if="hasMorePlaylistItems"
                                class="text-center text-xs text-gray-400 py-2">
                                {{ t('video.playlistLoading') }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="notfound" class="text-center py-16">
            <div class="text-6xl mb-4">😢</div>
            <div class="text-3xl font-semibold text-gray-700 mb-2">{{ t('video.videoNotFound') }}</div>
            <div class="text-gray-500">{{ t('video.videoNotFoundDescription') }}</div>
            <RouterLink to="/"
                class="inline-block mt-6 px-6 py-3 bg-gradient-to-r from-pink-500 to-purple-600 text-white rounded-lg hover:from-pink-600 hover:to-purple-700 transition-all duration-300 shadow-lg hover:shadow-xl">
                {{ t('video.backToHome') }}
            </RouterLink>
        </div>

        <div class="m-4 relative"> <transition 
                enter-active-class="transition duration-300 ease-out"
                enter-from-class="translate-y-10 opacity-0"
                enter-to-class="translate-y-0 opacity-100"
                leave-active-class="transition duration-200 ease-in"
                leave-from-class="translate-y-0 opacity-100"
                leave-to-class="translate-y-10 opacity-0"
            >
                <button 
                    v-if="showBackToTop" 
                    @click="scrollToTop"
                    class="fixed bottom-20 right-6 z-50 p-3 bg-white border border-gray-200 rounded-full shadow-lg hover:shadow-xl hover:bg-gray-50 transition-all group"
                    aria-label="Back to Top"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-gray-500 group-hover:text-pink-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18" />
                    </svg>
                </button>
            </transition>
        </div>

        <transition
            enter-active-class="transition duration-300 ease-out transform"
            enter-from-class="translate-y-[-1rem] opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition duration-200 ease-in transform"
            leave-from-class="translate-y-0 opacity-100"
            leave-to-class="translate-y-[-1rem] opacity-0"
        >
            <div v-if="showToast" class="fixed top-6 right-6 z-[60] bg-white border border-green-200 rounded-lg shadow-xl p-4 w-80 overflow-hidden">
                <div class="flex items-start space-x-3 relative z-10">
                    <div class="flex-shrink-0 text-green-500 mt-0.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                    <div class="flex-1">
                        <h4 class="text-sm font-bold text-gray-800">信息提示</h4>
                        <p class="text-sm text-gray-600 mt-1">{{ toastMsg }}</p>
                    </div>
                </div>
                <div class="absolute bottom-0 left-0 h-1 bg-green-500 animate-shrink" style="width: 100%"></div>
            </div>
        </transition>

        <div v-if="showDeleteModal" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 px-4">
            <div class="w-full max-w-md rounded-xl bg-white shadow-2xl border border-gray-200 p-5">
                <h3 class="text-lg font-semibold text-gray-900">{{ t('video.deleteVideoTitle') }}</h3>
                <p class="text-sm text-gray-600 mt-2">
                    {{ t('video.deleteVideoDescription') }}
                </p>

                <div class="mt-4 space-y-3">
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" class="mt-0.5" v-model="deletePermanent" @change="onPermanentChange" />
                        <span class="text-sm text-gray-700">
                            {{ t('video.deletePermanentLabel') }}
                        </span>
                    </label>

                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" class="mt-0.5" v-model="deleteAndRequeue" :disabled="deletePermanent" />
                        <span class="text-sm text-gray-700" :class="{ 'opacity-50': deletePermanent }">
                            {{ t('video.deleteRequeueLabel') }}
                        </span>
                    </label>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button class="px-3 py-2 rounded border border-gray-300 text-gray-700 hover:bg-gray-50"
                        :disabled="deletingVideo" @click="closeDeleteModal">
                        {{ t('video.deleteCancel') }}
                    </button>
                    <button class="px-3 py-2 rounded bg-red-600 text-white hover:bg-red-700 disabled:opacity-60"
                        :disabled="deletingVideo" @click="confirmDeleteVideo">
                        {{ deletingVideo ? t('video.deleting') : t('video.deleteConfirm') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { computed, onMounted, ref, nextTick, onUnmounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useToast } from '@/composables/toast';
import { useI18n } from 'vue-i18n';
import { formatTimestamp } from '../lib/helper';
import Player from '../components/Player.vue';
import Breadcrumbs from '../components/Breadcrumbs.vue';
import type { Video, VideoPartType } from '@/api/fav';
import { getFavVideos } from '@/api/fav';
import { getVideoDanmaku, getVideoInfo, getVideoTags, triggerUpdateDanmaku, triggerUpdateComments, triggerUpdateStats, deleteVideo } from '@/api/video';
import VideoComments from '@/components/VideoComments.vue';

const { t } = useI18n();
const toast = useToast();
const playerRef = ref()
const playerContainer = ref<HTMLDivElement | null>(null)
const sidebarHeight = ref('auto')

const route = useRoute()

const videoId = ref(route.params.id)

if (route.name == "subscription-video-id" || route.name == "favlist-video-id") {
    videoId.value = route.params.video_id
}

const breadcrumbItems = computed(() => {
    if (route.name == "subscription-video-id") {
        let subscriptionId = route.params.id
        return [
            { text: t('navigation.home'), to: '/' },
            { text: videoInfo.value?.subscriptions?.[0]?.name ?? t('video.loading'), to: '/subscription/' + subscriptionId },
            { text: videoInfo.value?.title ?? t('video.loading') }
        ]
    } else if (route.name == "favlist-video-id") {
        let favId = route.params.id as string
        let title = t('video.favorite')
        let fav = videoInfo.value?.favorite?.find(fav => fav.id == parseInt(favId))
        if (fav) {
            title = fav.title
        } else {
            let subscription = videoInfo.value?.subscriptions?.find(sub => -sub.id == parseInt(favId))
            if (subscription) {
                title = subscription.name
            }
        }
        return [
            { text: t('navigation.home'), to: '/' },
            { text: title, to: '/fav/' + favId },
            { text: videoInfo.value?.title ?? t('video.loading') }
        ]
    } else {
        if (videoInfo.value?.favorite && videoInfo.value?.favorite?.length > 0) {
            return [
                { text: t('navigation.home'), to: '/' },
                { text: (videoInfo.value?.favorite?.[0]?.title ?? t('video.favorite')), to: '/fav/' + (videoInfo.value?.favorite?.[0]?.id ?? '') },
                { text: videoInfo.value?.title ?? t('video.loading') }
            ]
        } else if (videoInfo.value?.subscriptions && videoInfo.value?.subscriptions?.length > 0) {
            return [
                { text: t('navigation.home'), to: '/' },
                { text: (videoInfo.value?.subscriptions?.[0]?.name ?? t('video.favorite')), to: '/subscription/' + (videoInfo.value?.subscriptions?.[0]?.id ?? '') },
                { text: videoInfo.value?.title ?? t('video.loading') }
            ]
        } else {
            return [
                { text: t('navigation.home'), to: '/' },
                { text: videoInfo.value?.title ?? t('video.loading') }
            ]
        }

    }
})

const bilibiliUrl = (video: Video) => {
    if (video.type === 12) {
        return `https://www.bilibili.com/audio/au${video.id}`
    }
    return `https://www.bilibili.com/video/${video.bvid}`
}

const upperSpaceUrl = (mid: number) => {
    return `https://space.bilibili.com/${mid}`
}

const openUpperSpace = (mid: number) => {
    window.open(upperSpaceUrl(mid), '_blank')
}

const downloadFile = (url: string, name: string) => {
    const a = document.createElement('a')
    a.href = url
    a.download = name
    a.click()
}

const getMediaExtension = (url: string) => {
    const ext = url.split('.').pop()?.split('?')[0]
    return ext || 'mp4'
}

const downloadVideo = () => {
    const parts = videoInfo.value?.video_parts
    if (parts) {
        for (let i in parts) {
            const part = parts[i]
            const url = part.url
            if (url) {
                downloadFile(url, part.title + '.' + getMediaExtension(url))
            }
        }
    }
    console.log('Download video clicked for:', videoInfo.value?.bvid)
}

const downloadDanmaku = () => {
    const parts = videoInfo.value?.video_parts
    if (parts) {
        for (let i in parts) {
            const part = parts[i]
            const partId = part.id
            getVideoDanmaku(partId).then((danmaku: any[]) => {
                if (danmaku) {
                    danmaku.sort((a: any, b: any) => a.time - b.time)
                    const json = JSON.stringify(danmaku)
                    const file = new File([json], part.title + ".json", { type: "application/json" })
                    const url = URL.createObjectURL(file)
                    downloadFile(url, part.title + ".json")
                }
            })
        }
    }
    console.log('Download danmaku clicked for:', videoInfo.value?.bvid)
}

const downloadCover = () => {
    const coverURL = videoInfo.value?.cover_info?.image_url
    if (coverURL) {
        downloadFile(coverURL, videoInfo.value?.title + ".jpg")
    }
    console.log('Download cover clicked for:', videoInfo.value?.bvid)
}

// --- 新增：通知状态管理 ---
const toastMsg = ref('');
const showToast = ref(false);
let toastTimer: ReturnType<typeof setTimeout> | null = null;

const showNotification = (msg: string) => {
    toastMsg.value = msg;
    showToast.value = false;
    nextTick(() => {
        showToast.value = true;
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(() => {
            showToast.value = false;
        }, 5000);
    });
};

const handleUpdateDanmaku = async () => {
    if (!videoInfo.value?.id) return;
    try {
        const res = await triggerUpdateDanmaku(videoInfo.value.id);
        if (res && res.code === 0) {
            showNotification(res.message || '弹幕更新任务已插队执行，请稍后刷新页面。');
        } else {
            showNotification(res?.message || '弹幕更新任务投递失败。');
        }
    } catch (e) {
        showNotification('网络请求异常，请查看日志。');
    }
};

const handleUpdateComments = async () => {
    if (!videoInfo.value?.id) return;
    try {
        const res = await triggerUpdateComments(videoInfo.value.id);
        if (res && res.code === 0) {
            showNotification(res.message || '评论更新任务已插队执行，请稍后刷新页面。');
        } else {
            showNotification(res?.message || '评论更新任务投递失败。');
        }
    } catch (e) {
        showNotification('网络请求异常，请查看日志。');
    }
};

const handleUpdateStats = async () => {
    if (!videoInfo.value?.id) return;
    try {
        const res = await triggerUpdateStats(videoInfo.value.id);
        if (res && res.code === 0) {
            showNotification(res.message || '数据更新任务已插队执行，请稍后刷新页面。');
        } else {
            showNotification(res?.message || '数据更新任务投递失败。');
        }
    } catch (e) {
        showNotification('网络请求异常，请查看日志。');
    }
};

const videoInfo = ref<Video | null>()
const isAudioVideo = computed(() => Number(videoInfo.value?.type) === 12)
const notfound = ref(false)

const currentPart = ref<VideoPartType | null>(null)

const danmaku = ref<any[]>([])
const showDeleteModal = ref(false)
const deletePermanent = ref(false)
const deleteAndRequeue = ref(false)
const deletingVideo = ref(false)

const onPlayerReady = () => {
    playPart(videoInfo.value?.video_parts?.[0]?.id ?? 0)
}

const playPart = (partId: number) => {
    const part = videoInfo.value?.video_parts?.find(part => part.id === partId)
    if (part && playerRef.value) {
        getVideoDanmaku(part.id).then(danmaku => {
            playerRef.value?.switchVideo({
                url: part.url,
                mobileUrl: part.mobile_url,
                danmaku: danmaku,
            })
        })
        currentPart.value = part as VideoPartType
    }
}

// ============================================================================
// 第21项：同收藏夹连播 + 播放清单侧栏
// ============================================================================
// 设计红线（docs/optimization-round2.md 第三批）：
//   1. 分P连播优先：ended → ① 下一分P → ② 收藏夹清单下一项（需开启连播）→ ③ 停止；
//   2. 单一连播开关 + 胶囊标签显示当前连播范围（不出现两个开关）；
//   3. 播放清单：分P列表置顶固定，收藏夹清单在下方分组展示，当前项高亮并自动滚动到可视区；
//   4. 用户手动暂停/停止后不自动续播（仅响应播放器 ended 事件）。
const router = useRouter()
// 连播开关持久化到 localStorage：切换视频（组件可能被重建）后自动连播不再被错误关闭
const AUTOPLAY_STORAGE_KEY = 'mybili_autoplay_next'
const autoPlayNext = ref(localStorage.getItem(AUTOPLAY_STORAGE_KEY) === '1')
watch(autoPlayNext, (value) => {
    localStorage.setItem(AUTOPLAY_STORAGE_KEY, value ? '1' : '0')
})
const playlist = ref<Video[]>([])        // 同收藏夹播放清单（隐藏已失效）
const playlistFavId = ref<string>('')    // 清单所属收藏夹 ID
const titleHeaderRef = ref<HTMLElement | null>(null)
const leftColRef = ref<HTMLElement | null>(null)
const rightColRef = ref<HTMLElement | null>(null)
let alignObserver: ResizeObserver | null = null

/** 进入页面/切集后：滚动使带超链接的视频标题与浏览器窗口顶部齐平。
 *  必须在视频数据渲染完成后调用（标题卡 v-if="videoInfo != null"）。
 *  behavior=auto + 零偏移：精确对齐、不受动画时序影响。 */
const scrollTitleToTop = () => {
    nextTick(() => {
        const el = titleHeaderRef.value
        if (!el) return
        window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY, behavior: 'auto' })
    })
}

/** 收藏夹清单当前索引（-1 表示不在清单中） */
const playlistIndex = computed(() => {
    const id = parseInt(videoId.value as string)
    return playlist.value.findIndex(v => v.id === id)
})

/** 胶囊标签：显示当前连播范围（分P优先于收藏夹） */
const autoPlayScopeLabel = computed(() => {
    if (!autoPlayNext.value) return t('video.autoplayOff')
    const parts = videoInfo.value?.video_parts ?? []
    const idx = parts.findIndex(p => p.id === currentPart.value?.id)
    if (parts.length > 1 && idx >= 0 && idx < parts.length - 1) {
        return t('video.autoplayPart', { current: idx + 1, total: parts.length })
    }
    if (playlist.value.length > 1 && playlistIndex.value >= 0 && playlistIndex.value < playlist.value.length - 1) {
        return t('video.autoplayFav')
    }
    return t('video.autoplayIdle')
})

/** 加载同收藏夹播放清单（隐藏已失效视频）。
 *  收藏夹上下文用路由收藏夹 ID；其它上下文（进度页/直链/订阅打开）回退视频自身所属收藏夹，
 *  保证"连播"在任何入口都生效（修复：进度页打开视频时连播不工作）。 */
const fetchPlaylist = (favId: number) => {
    getFavVideos(favId).then((list) => {
        playlist.value = (list ?? []).filter((v: any) => v?.invalid !== 1)
        resetPlaylistWindow()
    })
}

const loadPlaylist = () => {
    if (route.name === 'favlist-video-id') {
        const favId = route.params.id as string
        playlistFavId.value = favId
        fetchPlaylist(parseInt(favId))
        return
    }
    const fallbackFavId = videoInfo.value?.favorite?.[0]?.id
    if (fallbackFavId) {
        playlistFavId.value = String(fallbackFavId)
        fetchPlaylist(fallbackFavId)
    }
}

/** 播放清单下一项（跳过已失效，已过滤；末尾返回 null） */
const nextPlaylistVideo = (): Video | null => {
    const idx = playlistIndex.value
    if (idx < 0 || idx + 1 >= playlist.value.length) return null
    return playlist.value[idx + 1]
}

/** 跳转到清单中的下一个视频（复用当前路由形态） */
const navigateToVideo = (targetId: number) => {
    if (route.name === 'favlist-video-id') {
        router.push(`/fav/${route.params.id}/video/${targetId}`)
    } else if (route.name === 'subscription-video-id') {
        router.push(`/subscription/${route.params.id}/video/${targetId}`)
    } else {
        router.push(`/video/${targetId}`)
    }
}

/**
 * 播放结束状态机：① 下一分P → ② 清单下一项（需开启连播）→ ③ 停止。
 * 仅由播放器 ended 事件触发；用户手动暂停/停止不进入此流程。
 */
const onPlayerEnded = () => {
    // ① 分P连播（优先级最高，属同一视频的自然续播）
    const parts = videoInfo.value?.video_parts ?? []
    const idx = parts.findIndex(p => p.id === currentPart.value?.id)
    if (idx >= 0 && idx + 1 < parts.length) {
        playPart(parts[idx + 1].id)
        return
    }
    // ② 收藏夹连播（需开启连播开关）
    if (!autoPlayNext.value) return
    const next = nextPlaylistVideo()
    if (next) {
        navigateToVideo(next.id)
        return
    }
    // ③ 停止：播放器停留在重播界面
}

// ===== 播放清单：平铺展示 + 与左侧评论区高度对齐自动填充 =====
// 起点 = 当前视频前 2 条；DOM 增量渲染（数据在内存，切片零开销）。
const PLAYLIST_PAGE_SIZE = 30
const playlistDisplayCount = ref(PLAYLIST_PAGE_SIZE)
const playlistStartIndex = computed(() => Math.max(0, playlistIndex.value - 2))
const displayedPlaylist = computed(() =>
    playlist.value.slice(playlistStartIndex.value, playlistStartIndex.value + playlistDisplayCount.value)
)
const hasMorePlaylistItems = computed(() =>
    playlistStartIndex.value + displayedPlaylist.value.length < playlist.value.length
)

/**
 * 清单"超前 + 全覆盖"填充（对齐修复 v2）：
 * 目标高度 = max(左列实际高度, 视口底部 + 超前量) —— 右列内容既与评论区等高，
 * 又在滚动方向上超前视口底部约 3-4 条，彻底杜绝"评论区有内容但清单没视频"。
 * 每次仅补齐差额（+2 条余量），避免一次渲染过多。
 */
const LEAD_PX = 320
const fillPlaylist = () => {
    if (!hasMorePlaylistItems.value) return
    const right = rightColRef.value
    if (!right) return
    const leftH = leftColRef.value?.scrollHeight ?? 0
    const rightTopAbs = right.getBoundingClientRect().top + window.scrollY
    const viewportTarget = window.scrollY + window.innerHeight + LEAD_PX - rightTopAbs
    const targetH = Math.max(leftH, viewportTarget)
    const rightH = right.scrollHeight
    if (rightH >= targetH) return
    const itemH = Math.max(60, rightH / Math.max(displayedPlaylist.value.length, 1))
    playlistDisplayCount.value += Math.ceil((targetH - rightH) / itemH) + 2
}

/** 滚动/尺寸变化时保持右列超前填充 */
const maybeLoadMorePlaylist = () => fillPlaylist()

/** 观察左列（评论懒加载）/右列高度变化，实时补齐清单保持两侧对齐 */
const setupAlignObserver = () => {
    alignObserver?.disconnect()
    if (typeof ResizeObserver === 'undefined') return
    alignObserver = new ResizeObserver(() => fillPlaylist())
    if (leftColRef.value) alignObserver.observe(leftColRef.value)
    if (rightColRef.value) alignObserver.observe(rightColRef.value)
}

/** 切集后重置清单窗口（起点=当前视频前 2 条）并立即按目标高度铺满 */
const resetPlaylistWindow = () => {
    playlistDisplayCount.value = 6
    nextTick(() => {
        setupAlignObserver()
        fillPlaylist()
    })
}

// 视频/清单异步数据（先后顺序任意）就绪后重建观察并铺满 ——
// 修复"清单先于模板渲染完成导致观察器挂在空 ref 上"的死角（此前会一直显示不足）
watch([videoInfo, playlist], () => {
    nextTick(() => {
        setupAlignObserver()
        fillPlaylist()
    })
})

/** 播放量格式化（B站风格：13.5万 / 1.2亿） */
const formatCount = (value: number | string | null | undefined): string => {
    const n = Number(value ?? 0)
    if (n >= 100000000) return (n / 100000000).toFixed(1).replace(/\.0$/, '') + '亿'
    if (n >= 10000) return (n / 10000).toFixed(1).replace(/\.0$/, '') + '万'
    return String(n)
}

/** 时长格式化（封面右下角角标：mm:ss 或 h:mm:ss） */
const formatDuration = (seconds: number | string | null | undefined): string => {
    const total = Math.max(0, Math.floor(Number(seconds ?? 0)))
    const h = Math.floor(total / 3600)
    const m = Math.floor((total % 3600) / 60)
    const s = total % 60
    const pad = (x: number) => String(x).padStart(2, '0')
    return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`
}

const openDeleteModal = () => {
    showDeleteModal.value = true
}

const closeDeleteModal = () => {
    if (deletingVideo.value) return
    showDeleteModal.value = false
}

const onPermanentChange = () => {
    if (deletePermanent.value) {
        deleteAndRequeue.value = false
    }
}

const confirmDeleteVideo = async () => {
    if (!videoInfo.value) return
    deletingVideo.value = true
    try {
        const res = await deleteVideo(Number(videoInfo.value.id), undefined, {
            permanent: deletePermanent.value,
            requeue: deleteAndRequeue.value,
        })
        if (res.code === 0) {
            toast.success(t('video.deleteSuccess'))
            showDeleteModal.value = false
        } else {
            toast.error(res.message ?? t('video.deleteFailed'))
        }
    } catch {
        toast.error(t('video.deleteFailed'))
    } finally {
        deletingVideo.value = false
    }
}

const updateSidebarHeight = () => {
    if (playerContainer.value) {
        const height = playerContainer.value.offsetHeight
        sidebarHeight.value = height > 0 ? `${height}px` : 'auto'
    }
}

let resizeObserver: ResizeObserver | null = null

const showBackToTop = ref(false);

const handleScroll = () => {
    const scrollTop = window.scrollY || document.documentElement.scrollTop;
    showBackToTop.value = scrollTop > 600; 
};

const scrollToTop = () => {
    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
};

const loadTags = async () => {
    if (!videoInfo.value?.id) return;
    try {
        const tagsData = await getVideoTags(videoInfo.value.id);
        videoInfo.value.tags = tagsData;
    } catch (e) {
        console.error('获取标签失败', e);
    }
};

watch(() => videoInfo.value?.id, (newId) => {
    if (newId) {
        loadTags();
    }
}, { immediate: true });

onMounted(() => {
    getVideoInfo(parseInt(videoId.value as string)).then(async (data) => {
        if (data) {
            videoInfo.value = data
            // 连播清单：非收藏夹上下文在此回退加载（进度页/直链等入口）
            loadPlaylist()
            // 数据渲染完成后：标题与窗口顶部齐平（此前在模板渲染前调用无效）
            scrollTitleToTop()
            updateSidebarHeight()
            nextTick(() => {
                updateSidebarHeight()
                if (playerContainer.value && window.ResizeObserver) {
                    resizeObserver = new ResizeObserver(() => {
                        updateSidebarHeight()
                    })
                    resizeObserver.observe(playerContainer.value)
                }
            })
        }
    })
    // 第21项：加载同收藏夹播放清单（仅收藏夹上下文）
    loadPlaylist()
    window.addEventListener('scroll', handleScroll);
    // 清单平铺随页面滚动自动加载（超前填充）
    window.addEventListener('scroll', maybeLoadMorePlaylist);
    window.addEventListener('resize', maybeLoadMorePlaylist);
})

// 第21项：收藏夹连播跳转时路由参数变化但组件被复用 —— 重新加载视频与首分P
watch(() => route.params.video_id, (newId) => {
    if ((route.name === 'favlist-video-id' || route.name === 'subscription-video-id') && newId) {
        videoId.value = newId as string
        getVideoInfo(parseInt(newId as string)).then((data) => {
            if (data) {
                videoInfo.value = data
                playPart(data.video_parts?.[0]?.id ?? 0)
                resetPlaylistWindow()
                scrollTitleToTop()
            }
        })
    }
})

onUnmounted(() => {
    if (resizeObserver) {
        resizeObserver.disconnect()
        resizeObserver = null
    }
    window.removeEventListener('scroll', handleScroll);
    window.removeEventListener('scroll', maybeLoadMorePlaylist);
    window.removeEventListener('resize', maybeLoadMorePlaylist);
    alignObserver?.disconnect();
    alignObserver = null;
})
</script>

<style scoped>
.custom-scrollbar {
    scrollbar-width: thin;
    scrollbar-color: #cbd5e1 #f1f5f9;
}

.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}

.custom-scrollbar::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 3px;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
    transition: background-color 0.2s ease;
}

.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* --- B站原生标签样式还原 --- */
.video-tag-container {
    margin: 16px 0 0px 0;
    padding-bottom: 0px;
}

.tag-panel {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.tag.not-btn-tag {
    display: inline-flex;
}

.tag-link {
    display: flex;
    align-items: center;
    text-decoration: none;
    transition: all 0.3s;
}

.tag-icon {
    margin-right: 4px;
}

.tag-arrow-icon {
    margin-left: 4px;
    opacity: 0.6;
}

.tag-txt {
    font-size: 13px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 140px;
}

/* --- BGM 音乐标签 (粉色) --- */
.bgm-tag {
    background-color: var(--brand_pink_thin, #FFECF1);
    border-radius: 14px;
    padding: 0 12px;
    height: 28px;
    transition: background-color 0.2s;
}
.dark .bgm-tag {
    background-color: var(--darkreader-bg--brand_pink_thin, #3e0010);
}
.bgm-tag:hover {
    background-color: var(--brand_pink, #FF6699);
}
.dark .bgm-tag:hover {
    background-color: var(--darkreader-bg--brand_pink, #8f0030);
}

.bgm-link {
    color: var(--brand_pink, #FF6699);
    height: 100%;
}
.dark .bgm-link {
    color: var(--darkreader-text--brand_pink, #ff6196);
}
.bgm-tag:hover .bgm-link {
    color: #fff;
}


/* --- Topic 话题标签 (蓝色) --- */
.topic-tag {
    background-color: var(--brand_blue_thin, #DFF6FD);
    border-radius: 14px;
    padding: 0 12px;
    height: 28px;
    transition: background-color 0.2s;
}
.dark .topic-tag {
    background-color: var(--darkreader-bg--brand_blue_thin, #043443);
}
.topic-tag:hover {
    background-color: var(--brand_blue, #00AEEC);
}
.dark .topic-tag:hover {
    background-color: var(--darkreader-bg--brand_blue, #008bbd);
}

.topic-link {
    color: var(--brand_blue, #00AEEC);
    height: 100%;
}
.dark .topic-link {
    color: var(--darkreader-text--brand_blue, #27c6ff);
}
.topic-tag:hover .topic-link {
    color: #fff;
}

/* --- 普通标签 (灰色) --- */
.ordinary-tag {
    background: var(--graph_bg_regular, #F1F2F3);
    border-radius: 14px;
    padding: 0 12px;
    height: 28px;
    line-height: 28px; 
    transition: background-color 0.2s, color 0.2s;
}
.dark .ordinary-tag {
    background: var(--darkreader-bg--graph_bg_regular, #272a2c);
}
.ordinary-tag:hover {
    background: var(--graph_bg_thick, #E3E5E7);
}
.dark .ordinary-tag:hover {
    background: var(--darkreader-bg--graph_bg_thick, #373c3e);
}

.ordinary-tag .tag-link {
    color: var(--text2, #61666D);
    font-size: 13px;
}
.dark .ordinary-tag .tag-link {
    color: var(--darkreader-text--text2, #a79f94);
}
.ordinary-tag:hover .tag-link {
    color: var(--brand_blue, #00AEEC);
}
</style>