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
            <div class="flex flex-col lg:flex-row gap-4">
                
                <div class="flex-1 min-w-0"> <div ref="playerContainer" id="playerContainer" class="-mx-6 md:mx-0 bg-white shadow-lg overflow-hidden md:border border-gray-200/50 mb-4">
                        <Player ref="playerRef" @ready="onPlayerReady" :danmaku="danmaku" :url="currentPart?.url ?? ''" />
                    </div>

                    <div class="bg-white/90 backdrop-blur-sm rounded-xl shadow-lg border border-gray-200/50 p-4 mb-4">
                        <div class="space-y-4">
                            <div class="flex justify-between items-start gap-4">
                                <div class="flex-1 min-w-0">
                                    <h2 class="text-2xl font-bold text-gray-800 mb-2 leading-tight break-words">{{ videoInfo.title }}</h2>
                                    <div class="w-16 h-1 bg-gradient-to-r from-pink-500 to-purple-600 rounded-full"></div>
                                </div>
                                <a class="hidden md:inline-flex items-center space-x-2 px-4 py-2 bg-gradient-to-r from-green-500 to-emerald-600 text-white text-sm rounded-lg hover:from-green-600 hover:to-emerald-700 transition-all duration-300 shadow hover:shadow-lg flex-shrink-0"
                                    :href="bilibiliUrl(videoInfo)" target="_blank" rel="noopener noreferrer">
                                    <span class="text-lg">📺</span>
                                    <span class="font-semibold">{{ t('video.watchOnBilibili') }}</span>
                                    <span class="text-white/80">↗</span>
                                </a>
                            </div>

                            <div v-if="videoInfo.upper" class="flex items-center justify-between p-3 bg-gradient-to-r from-gray-50 to-gray-100 rounded-lg border border-gray-200/50">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 bg-gradient-to-br from-pink-400 to-purple-500 rounded-full flex items-center justify-center flex-shrink-0">
                                        <img :src="videoInfo.upper.cover_info?.image_url" alt="UP主头像" class="w-full h-full object-cover rounded-full">
                                    </div>
                                    <div class="min-w-0 flex-1" @click="openUpperSpace(videoInfo.upper.mid)">
                                        <div class="flex items-center space-x-2">
                                            <h3 class="font-semibold text-gray-800 truncate">{{ videoInfo.upper.name }}</h3>
                                            <span class="text-xs text-gray-500 bg-gray-200 px-2 py-0.5 rounded-full">UID: {{ videoInfo.upper.mid }}</span>
                                        </div>
                                    </div>
                                </div>
                                <a :href="upperSpaceUrl(videoInfo.upper.mid)" target="_blank" rel="noopener noreferrer"
                                    class="hidden md:inline-flex items-center space-x-1 px-3 py-1.5 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 hover:border-gray-400 transition-all duration-200 text-sm font-medium text-gray-700 hover:text-gray-900 flex-shrink-0">
                                    <span>{{ t('video.visitSpace') }}</span>
                                    <span class="text-gray-400">↗</span>
                                </a>
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
                                                        <svg width="6" height="10" viewBox="0 0 6 10" xmlns="http://www.w3.org/2000/svg" class="tag-arrow-icon"><path fill-rule="evenodd" clip-rule="evenodd" d="M0.325639 0.575638C0.0913236 0.809953 0.0913236 1.18985 0.325639 1.42417L3.90137 4.9999L0.325639 8.57564C0.0913236 8.80995 0.0913236 9.18985 0.325639 9.42417C0.559953 9.65848 0.939852 9.65848 1.17417 9.42417L4.99739 5.60094C5.32934 5.269 5.32933 4.73081 4.99739 4.39886L1.17417 0.575638C0.939852 0.341324 0.559953 0.341324 0.325639 0.575638Z" fill="currentColor"></path></svg>
                                                    </a>
                                                </div>
                                                <div v-else-if="tag.tag_type === 'topic'" class="topic-tag">
                                                    <a :href="tag.jump_url || `https://search.bilibili.com/all?keyword=${encodeURIComponent(tag.tag_name)}`" target="_blank" :title="tag.tag_name" class="tag-link topic-link">
                                                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" class="tag-icon topic-tag-icon"><path fill-rule="evenodd" clip-rule="evenodd" d="M5.15142 1.70791C5.36429 1.56008 5.6567 1.61281 5.80453 1.82568L6.92003 3.432C7.26888 3.42615 7.62969 3.42286 8.00007 3.42286C8.3704 3.42286 8.73116 3.42615 9.07998 3.432L10.1955 1.82568C10.3433 1.61281 10.6357 1.56008 10.8486 1.70791C11.0615 1.85574 11.1142 2.14814 10.9664 2.36102L10.203 3.46026C10.9526 3.48536 11.6215 3.52013 12.1791 3.5552C13.4364 3.63429 14.4433 4.60659 14.5376 5.87199C14.5966 6.66359 14.648 7.66253 14.648 8.7412C14.648 9.82395 14.5962 10.8185 14.5369 11.6027C14.4427 12.8488 13.4594 13.8117 12.2205 13.903C11.156 13.9815 9.67595 14.0596 8.00007 14.0596C6.32435 14.0596 4.8444 13.9815 3.77995 13.903C2.54085 13.8117 1.55749 12.8486 1.46327 11.6024C1.40396 10.8179 1.35214 9.82325 1.35214 8.7412C1.35214 7.66322 1.40358 6.66415 1.46258 5.87228C1.55688 4.60679 2.56384 3.63427 3.82138 3.55518C4.3788 3.52012 5.04762 3.48536 5.79702 3.46027L5.03365 2.36102C4.88582 2.14814 4.93855 1.85574 5.15142 1.70791ZM8.00007 4.36139C6.36941 4.36139 4.92598 4.4261 3.88029 4.49186C3.08157 4.5421 2.45732 5.15291 2.39852 5.94202C2.34078 6.71684 2.29067 7.69194 2.29067 8.7412C2.29067 9.79434 2.34115 10.7648 2.39913 11.5316C2.45778 12.3074 3.06585 12.9093 3.84894 12.967C4.89619 13.0442 6.35249 13.121 8.00007 13.121C9.6478 13.121 11.1042 13.0442 12.1515 12.967C12.9345 12.9093 13.5424 12.3075 13.6011 11.5319C13.659 10.7654 13.7095 9.79506 13.7095 8.7412C13.7095 7.69122 13.6594 6.71625 13.6017 5.94173C13.5429 5.15277 12.9188 4.54211 12.1201 4.49188C11.0744 4.42611 9.63088 4.36139 8.00007 4.36139ZM4.55172 7.40615C4.55172 7.16699 4.74559 6.97312 4.98475 6.97312H6.52431L6.69171 5.74556C6.72402 5.5086 6.94231 5.3427 7.17928 5.37501C7.41624 5.40732 7.58214 5.62562 7.54983 5.86258L7.39839 6.97312H8.92735L9.09475 5.74556C9.12706 5.5086 9.34535 5.3427 9.58231 5.37501C9.81928 5.40732 9.98518 5.62562 9.95287 5.86258L9.80143 6.97312H10.8589C11.0981 6.97312 11.2919 7.16699 11.2919 7.40615C11.2919 7.64531 11.0981 7.83918 10.8589 7.83918H9.68333L9.43736 9.643H10.8589C11.0981 9.643 11.2919 9.83688 11.2919 10.076C11.2919 10.3152 11.0981 10.5091 10.8589 10.5091H9.31926L9.15184 11.7367C9.11953 11.9737 8.90124 12.1396 8.66428 12.1073C8.42731 12.075 8.26141 11.8567 8.29372 11.6197L8.44518 10.5091H6.91622L6.74881 11.7367C6.71649 11.9737 6.4982 12.1396 6.26124 12.1073C6.02427 12.075 5.85837 11.8567 5.89068 11.6197L6.04214 10.5091H4.98475C4.74559 10.5091 4.55172 10.3152 4.55172 10.076C4.55172 9.83688 4.74559 9.643 4.98475 9.643H6.16024L6.40621 7.83918H4.98475C4.74559 7.83918 4.55172 7.64531 4.55172 7.40615ZM8.56328 9.643L8.80925 7.83918H7.28029L7.03432 9.643H8.56328Z" fill="currentColor"></path></svg>
                                                        <span class="tag-txt">{{ tag.tag_name }}</span>
                                                        <svg width="6" height="10" viewBox="0 0 6 10" xmlns="http://www.w3.org/2000/svg" class="tag-arrow-icon"><path fill-rule="evenodd" clip-rule="evenodd" d="M0.325639 0.575638C0.0913236 0.809953 0.0913236 1.18985 0.325639 1.42417L3.90137 4.9999L0.325639 8.57564C0.0913236 8.80995 0.0913236 9.18985 0.325639 9.42417C0.559953 9.65848 0.939852 9.65848 1.17417 9.42417L4.99739 5.60094C5.32934 5.269 5.32933 4.73081 4.99739 4.39886L1.17417 0.575638C0.939852 0.341324 0.559953 0.341324 0.325639 0.575638Z" fill="currentColor"></path></svg>
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

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg p-3 border border-blue-200/50">
                                    <div class="flex items-center space-x-2">
                                        <span class="text-blue-500">📅</span>
                                        <span class="text-sm text-gray-600">{{ t('video.publishTime') }}</span>
                                    </div>
                                    <div class="text-base font-semibold text-gray-800 mt-1">
                                        {{ formatTimestamp(videoInfo.pubtime, "yyyy-mm-dd hh:ii") }}
                                    </div>
                                </div>

                                <div class="bg-gradient-to-r from-green-50 to-emerald-50 rounded-lg p-3 border border-green-200/50">
                                    <div class="flex items-center space-x-2">
                                        <span class="text-green-500">⭐</span>
                                        <span class="text-sm text-gray-600">{{ t('video.favoriteTime') }}</span>
                                    </div>
                                    <div class="text-base font-semibold text-gray-800 mt-1">
                                        {{ videoInfo.fav_time ? formatTimestamp(videoInfo.fav_time, "yyyy-mm-dd hh:ii") : '-' }}
                                    </div>
                                </div>

                                <div class="bg-gradient-to-r from-purple-50 to-pink-50 rounded-lg p-3 border border-purple-200/50">
                                    <div class="flex items-center space-x-2">
                                        <span class="text-purple-500">💬</span>
                                        <span class="text-sm text-gray-600">{{ t('video.danmakuCount') }}</span>
                                    </div>
                                    <div class="text-base font-semibold text-gray-800 mt-1">
                                        {{ videoInfo.danmaku_count.toLocaleString() }}
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex justify-center pt-2 md:hidden">
                                <a class="inline-flex items-center space-x-2 px-6 py-3 bg-gradient-to-r from-green-500 to-emerald-600 text-white rounded-lg hover:from-green-600 hover:to-emerald-700 transition-all duration-300 shadow hover:shadow-lg transform hover:-translate-y-0.5"
                                    :href="bilibiliUrl(videoInfo)" target="_blank" rel="noopener noreferrer">
                                    <span class="text-lg">📺</span>
                                    <span class="font-semibold">{{ t('video.watchOnBilibili') }}</span>
                                    <span class="text-white/80">↗</span>
                                </a>
                            </div>

                            <div class="grid grid-cols-2 md:grid-cols-3 gap-3 pt-4 border-t border-gray-200/50">
                                <button @click="downloadVideo" class="flex items-center justify-center space-x-2 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg p-3 border border-blue-200/50 hover:shadow hover:from-blue-100 hover:to-indigo-100 transition-all duration-300 group">
                                    <span class="text-blue-500 text-lg group-hover:scale-110 transition-transform duration-300">🎬</span>
                                    <span class="text-sm font-semibold text-gray-700 group-hover:text-blue-800">{{ t('video.downloadVideo') }}</span>
                                </button>

                                <button @click="downloadDanmaku" class="flex items-center justify-center space-x-2 bg-gradient-to-r from-purple-50 to-pink-50 rounded-lg p-3 border border-purple-200/50 hover:shadow hover:from-purple-100 hover:to-pink-100 transition-all duration-300 group">
                                    <span class="text-purple-500 text-lg group-hover:scale-110 transition-transform duration-300">💬</span>
                                    <span class="text-sm font-semibold text-gray-700 group-hover:text-purple-800">{{ t('video.downloadDanmaku') }}</span>
                                </button>

                                <button @click="downloadCover" class="flex items-center justify-center space-x-2 bg-gradient-to-r from-pink-50 to-rose-50 rounded-lg p-3 border border-pink-200/50 hover:shadow hover:from-pink-100 hover:to-rose-100 transition-all duration-300 group col-span-2 md:col-span-1">
                                    <span class="text-pink-500 text-lg group-hover:scale-110 transition-transform duration-300">🖼️</span>
                                    <span class="text-sm font-semibold text-gray-700 group-hover:text-pink-800">{{ t('video.downloadCover') }}</span>
                                </button>
                            </div>

                            <div class="grid grid-cols-2 md:grid-cols-3 gap-3 pt-1">
                                <button @click="handleUpdateStats" class="flex items-center justify-center space-x-2 bg-gradient-to-r from-amber-50 to-yellow-50 rounded-lg p-3 border border-amber-200/50 hover:shadow hover:from-amber-100 hover:to-yellow-100 transition-all duration-300 group">
                                    <span class="text-amber-500 text-lg group-hover:scale-110 transition-transform duration-300">📊</span>
                                    <span class="text-sm font-semibold text-gray-700 group-hover:text-amber-800">{{ t('video.updateStat') }}</span>
                                </button>
                                
                                <button @click="handleUpdateDanmaku" class="flex items-center justify-center space-x-2 bg-gradient-to-r from-orange-50 to-amber-50 rounded-lg p-3 border border-orange-200/50 hover:shadow hover:from-orange-100 hover:to-amber-100 transition-all duration-300 group">
                                    <span class="text-orange-500 text-lg group-hover:scale-110 transition-transform duration-300">🔄</span>
                                    <span class="text-sm font-semibold text-gray-700 group-hover:text-orange-800">{{ t('video.updateDanmaku') }}</span>
                                </button>

                                <button @click="handleUpdateComments" class="flex items-center justify-center space-x-2 bg-gradient-to-r from-teal-50 to-emerald-50 rounded-lg p-3 border border-teal-200/50 hover:shadow hover:from-teal-100 hover:to-emerald-100 transition-all duration-300 group col-span-2 md:col-span-1">
                                    <span class="text-teal-500 text-lg group-hover:scale-110 transition-transform duration-300">📝</span>
                                    <span class="text-sm font-semibold text-gray-700 group-hover:text-teal-800">{{ t('video.updateComment') }}</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <VideoComments v-if="videoInfo && videoInfo.id" :video-id="videoInfo.id" :upper-id="videoInfo.upper_id" />
                </div>

                <div class="w-full lg:w-72 lg:shrink-0" v-if="videoInfo && videoInfo.video_parts && videoInfo.video_parts.length > 1">
                    <div class="bg-white/90 backdrop-blur-sm rounded-xl shadow-lg border border-gray-200/50 p-3 flex flex-col"
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
    </div>
</template>
<script lang="ts" setup>
import { computed, onMounted, ref, nextTick, onUnmounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { formatTimestamp } from '../lib/helper';
import Player from '../components/Player.vue';
import Breadcrumbs from '../components/Breadcrumbs.vue';
import type { Video, VideoPartType } from '@/api/fav';
import { getVideoDanmaku, getVideoInfo, getVideoTags, triggerUpdateDanmaku, triggerUpdateComments, triggerUpdateStats } from '@/api/video';
import VideoComments from '@/components/VideoComments.vue';

const { t } = useI18n();
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
            fetch(`/api/danmaku/v3/?id=${partId}`).then(async (rsp) => {
                if (rsp.ok) {
                    const jsonData = await rsp.json()
                    const danmaku = jsonData.data
                    if (danmaku) {
                        // 按照第一个元素排序,时间
                        danmaku.sort((a: any, b: any) => a[0] - b[0])
                        const json = JSON.stringify(danmaku)
                        const file = new File([json], part.title + ".json", { type: "application/json" })
                        const url = URL.createObjectURL(file)
                        downloadFile(url, part.title + ".json")
                    }
                }
            })
        }
    }
    console.log('Download danmaku clicked for:', videoInfo.value?.bvid)
}

const downloadCover = () => {
    const coverURL = videoInfo.value?.cover_info?.image_url
    if (coverURL) {
        // 下载图片
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
    showToast.value = false; // 先重置状态，以重置进度条动画
    
    nextTick(() => {
        showToast.value = true;
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(() => {
            showToast.value = false;
        }, 5000); // 5秒后自动消失
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
const notfound = ref(false)

const currentPart = ref<VideoPartType | null>(null)

const danmaku = ref<any[]>([])

// Player 准备就绪时的回调
const onPlayerReady = () => {
    playPart(videoInfo.value?.video_parts?.[0]?.id ?? 0)
}


const playPart = (partId: number) => {
    console.log('playPart', partId)
    const part = videoInfo.value?.video_parts?.find(part => part.id === partId)
    if (part && playerRef.value) {
        console.log('playPart switchVideo', part.id)
        getVideoDanmaku(part.id).then(danmaku => {
            console.log('playPart switchVideo', {
                url: part.url,
                mobileUrl: part.mobile_url,
                danmaku: danmaku,
            })
            playerRef.value?.switchVideo({
                url: part.url,
                mobileUrl: part.mobile_url,
                danmaku: danmaku,
            })
        })
        currentPart.value = part as VideoPartType
    }
}

// 更新侧边栏高度
const updateSidebarHeight = () => {
    if (playerContainer.value) {
        const height = playerContainer.value.offsetHeight
        sidebarHeight.value = height > 0 ? `${height}px` : 'auto'
    }
}

// 监听窗口大小变化
let resizeObserver: ResizeObserver | null = null


// --- 回到顶部逻辑 ---
const showBackToTop = ref(false);
const commentSectionRef = ref<HTMLElement | null>(null); // 需要在 VideoComments 组件外层加个 ref 或者根据滚动距离判断

const handleScroll = () => {
    // 简单逻辑：滚动超过 500px 显示
    // 复杂逻辑(B站同款)：当滚动到评论区时显示
    // 假设评论区组件上有个 id="comment-section" 或者我们大致预估高度
    const scrollTop = window.scrollY || document.documentElement.scrollTop;
    showBackToTop.value = scrollTop > 600; 
};

const scrollToTop = () => {
    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
};

// 【新增】独立拉取标签数据
const loadTags = async () => {
    if (!videoInfo.value?.id) return;
    try {
        const tagsData = await getVideoTags(videoInfo.value.id);
        // 将请求到的标签合并到 videoInfo 中，触发响应式渲染
        videoInfo.value.tags = tagsData;
    } catch (e) {
        console.error('获取标签失败', e);
    }
};

// 【核心修复】监听 videoInfo，一旦视频 ID 确定，立刻拉取标签！
// 这样无论你是从列表点击进入（通过 history.state），还是 F5 刷新页面进入（通过 loadVideoInfo），都能保证 100% 拉取到标签。
watch(() => videoInfo.value?.id, (newId) => {
    if (newId) {
        loadTags();
    }
}, { immediate: true });

onMounted(() => {
    getVideoInfo(parseInt(videoId.value as string)).then(async (data) => {
        if (data) {
            videoInfo.value = data
            updateSidebarHeight()
            // // 等待DOM更新后设置高度监听
            nextTick(() => {
                updateSidebarHeight()

                // 使用ResizeObserver监听播放器容器大小变化
                if (playerContainer.value && window.ResizeObserver) {
                    resizeObserver = new ResizeObserver(() => {
                        updateSidebarHeight()
                    })
                    resizeObserver.observe(playerContainer.value)
                }
            })
        }
    })
    window.addEventListener('scroll', handleScroll);
})

// 组件卸载时清理
onUnmounted(() => {
    if (resizeObserver) {
        resizeObserver.disconnect()
        resizeObserver = null
    }
    window.removeEventListener('scroll', handleScroll);
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
    /* border-bottom: 1px solid var(--line_regular, #E3E5E7); */
}

/* .dark .video-tag-container {
    border-bottom: 1px solid var(--darkreader-border--line_regular, #373c3e);
} */

.tag-panel {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

/* --- 通用包裹器 --- */
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
    max-width: 140px; /* 防止过长的标签破坏布局 */
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
    line-height: 28px; /* 垂直居中对于没有 flex 的 a 标签更友好 */
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
