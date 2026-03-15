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

                            <div v-if="videoInfo.intro" class="bg-gray-50 rounded-lg p-4">
                                <h3 class="text-lg font-semibold text-gray-700 mb-2 flex items-center">
                                    <span class="w-1.5 h-1.5 bg-gray-400 rounded-full mr-2"></span>
                                    {{ t('video.videoDescription') }}
                                </h3>
                                <div class="mt-4 text-sm text-gray-700 dark:text-gray-300">
                                    <p class="whitespace-pre-wrap" v-if="videoInfo?.intro">{{ videoInfo.intro }}</p>
                                    <div class="video-tag-container mt-6" v-if="videoInfo?.tags && videoInfo.tags.length > 0">
                                        <div class="tag-panel">
                                            <div class="tag not-btn-tag" v-for="tag in videoInfo.tags" :key="tag.tag_id">
                                                <div v-if="tag.tag_type === 'topic' || tag.tag_type === 'bgm'" class="topic-tag">
                                                    <a :href="tag.jump_url || `https://search.bilibili.com/all?keyword=${encodeURIComponent(tag.tag_name)}`" 
                                                       target="_blank" class="tag-link topic-link flex items-center">
                                                        <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" class="mr-1">
                                                            <path fill-rule="evenodd" clip-rule="evenodd" d="M5.15142 1.70791C5.36429 1.56008 5.6567 1.61281 5.80453 1.82568L6.92003 3.432C7.26888 3.42615 7.62969 3.42286 8.00007 3.42286C8.3704 3.42286 8.73116 3.42615 9.07998 3.432L10.1955 1.82568C10.3433 1.61281 10.6357 1.56008 10.8486 1.70791C11.0615 1.85574 11.1142 2.14814 10.9664 2.36102L10.203 3.46026C10.9526 3.48536 11.6215 3.52013 12.1791 3.5552C13.4364 3.63429 14.4433 4.60659 14.5376 5.87199C14.5966 6.66359 14.648 7.66253 14.648 8.7412C14.648 9.82395 14.5962 10.8185 14.5369 11.6027C14.4427 12.8488 13.4594 13.8117 12.2205 13.903C11.156 13.9815 9.67595 14.0596 8.00007 14.0596C6.32435 14.0596 4.8444 13.9815 3.77995 13.903C2.54085 13.8117 1.55749 12.8486 1.46327 11.6024C1.40396 10.8179 1.35214 9.82325 1.35214 8.7412C1.35214 7.66322 1.40358 6.66415 1.46258 5.87228C1.55688 4.60679 2.56384 3.63427 3.82138 3.55518C4.3788 3.52012 5.04762 3.48536 5.79702 3.46027L5.03365 2.36102C4.88582 2.14814 4.93855 1.85574 5.15142 1.70791ZM8.00007 4.36139C6.36941 4.36139 4.92598 4.4261 3.88029 4.49186C3.08157 4.5421 2.45732 5.15291 2.39852 5.94202C2.34078 6.71684 2.29067 7.69194 2.29067 8.7412C2.29067 9.79434 2.34115 10.7648 2.39913 11.5316C2.45778 12.3074 3.06585 12.9093 3.84894 12.967C4.89619 13.0442 6.35249 13.121 8.00007 13.121C9.6478 13.121 11.1042 13.0442 12.1515 12.967C12.9345 12.9093 13.5424 12.3075 13.6011 11.5319C13.659 10.7654 13.7095 9.79506 13.7095 8.7412C13.7095 7.69122 13.6594 6.71625 13.6017 5.94173C13.5429 5.15277 12.9188 4.54211 12.1201 4.49188C11.0744 4.42611 9.63088 4.36139 8.00007 4.36139ZM4.55172 7.40615C4.55172 7.16699 4.74559 6.97312 4.98475 6.97312H6.52431L6.69171 5.74556C6.72402 5.5086 6.94231 5.3427 7.17928 5.37501C7.41624 5.40732 7.58214 5.62562 7.54983 5.86258L7.39839 6.97312H8.92735L9.09475 5.74556C9.12706 5.5086 9.34535 5.3427 9.58231 5.37501C9.81928 5.40732 9.98518 5.62562 9.95287 5.86258L9.80143 6.97312H10.8589C11.0981 6.97312 11.2919 7.16699 11.2919 7.40615C11.2919 7.64531 11.0981 7.83918 10.8589 7.83918H9.68333L9.43736 9.643H10.8589C11.0981 9.643 11.2919 9.83688 11.2919 10.076C11.2919 10.3152 11.0981 10.5091 10.8589 10.5091H9.31926L9.15184 11.7367C9.11953 11.9737 8.90124 12.1396 8.66428 12.1073C8.42731 12.075 8.26141 11.8567 8.29372 11.6197L8.44518 10.5091H6.91622L6.74881 11.7367C6.71649 11.9737 6.4982 12.1396 6.26124 12.1073C6.02427 12.075 5.85837 11.8567 5.89068 11.6197L6.04214 10.5091H4.98475C4.74559 10.5091 4.55172 10.3152 4.55172 10.076C4.55172 9.83688 4.74559 9.643 4.98475 9.643H6.16024L6.40621 7.83918H4.98475C4.74559 7.83918 4.55172 7.64531 4.55172 7.40615ZM8.56328 9.643L8.80925 7.83918H7.28029L7.03432 9.643H8.56328Z"></path>
                                                        </svg>
                                                        <span class="tag-txt">{{ tag.tag_name }}</span>
                                                    </a>
                                                </div>
                                                <div v-else class="ordinary-tag">
                                                    <a :href="`https://search.bilibili.com/all?keyword=${encodeURIComponent(tag.tag_name)}`" 
                                                       target="_blank" class="tag-link">
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

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 pt-4 border-t border-gray-200/50">
                                <button @click="downloadVideo" class="flex flex-col items-center p-4 bg-gradient-to-br from-blue-50 to-blue-100 border border-blue-200/50 rounded-xl hover:from-blue-100 hover:to-blue-150 hover:border-blue-300/50 transition-all duration-300 group hover:shadow-md">
                                    <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center mb-2 group-hover:scale-110 transition-transform duration-300">
                                        <span class="text-xl text-white">🎬</span>
                                    </div>
                                    <span class="text-sm font-medium text-blue-700 group-hover:text-blue-800">{{ t('video.downloadVideo') }}</span>
                                </button>

                                <button @click="downloadDanmaku" class="flex flex-col items-center p-4 bg-gradient-to-br from-purple-50 to-purple-100 border border-purple-200/50 rounded-xl hover:from-purple-100 hover:to-purple-150 hover:border-purple-300/50 transition-all duration-300 group hover:shadow-md">
                                    <div class="w-12 h-12 bg-gradient-to-br from-purple-500 to-purple-600 rounded-full flex items-center justify-center mb-2 group-hover:scale-110 transition-transform duration-300">
                                        <span class="text-xl text-white">💬</span>
                                    </div>
                                    <span class="text-sm font-medium text-purple-700 group-hover:text-purple-800">{{ t('video.downloadDanmaku') }}</span>
                                </button>

                                <button @click="downloadCover" class="flex flex-col items-center p-4 bg-gradient-to-br from-pink-50 to-pink-100 border border-pink-200/50 rounded-xl hover:from-pink-100 hover:to-pink-150 hover:border-pink-300/50 transition-all duration-300 group hover:shadow-md">
                                    <div class="w-12 h-12 bg-gradient-to-br from-pink-500 to-pink-600 rounded-full flex items-center justify-center mb-2 group-hover:scale-110 transition-transform duration-300">
                                        <span class="text-xl text-white">🖼️</span>
                                    </div>
                                    <span class="text-sm font-medium text-pink-700 group-hover:text-pink-800">{{ t('video.downloadCover') }}</span>
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
    </div>
</template>
<script lang="ts" setup>
import { computed, onMounted, ref, nextTick, onUnmounted } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { formatTimestamp } from '../lib/helper';
import Player from '../components/Player.vue';
import Breadcrumbs from '../components/Breadcrumbs.vue';
import type { Video, VideoPartType } from '@/api/fav';
import { getVideoDanmaku, getVideoInfo } from '@/api/video';
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

/* 【新增】标签高仿 B站 样式 */
.topic-tag {
    background: var(--brand_blue_thin, #DFF6FD);
    border-radius: 14px;
    padding: 0 12px;
    height: 28px;
    display: flex;
    align-items: center;
    transition: background-color 0.2s;
}
.dark .topic-tag {
    background: var(--darkreader-bg--brand_blue_thin, #043443);
}
.topic-tag:hover {
    background: var(--brand_blue, #00AEEC);
}
.dark .topic-tag:hover {
    background: var(--darkreader-bg--brand_blue, #008bbd);
}
.topic-link {
    color: var(--brand_blue, #00AEEC);
    font-size: 13px;
    text-decoration: none;
    transition: color 0.2s;
}
.dark .topic-link {
    color: var(--darkreader-text--brand_blue, #27c6ff);
}
.topic-tag:hover .topic-link {
    color: #fff;
}

</style>
