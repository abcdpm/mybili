<template>
    <div class="m-4">
        <Breadcrumbs :items="breadcrumbItems">
            <template #actions>
                <div class="flex items-center gap-2">
                    <label class="text-slate-500">{{ t('favorites.downloaded') }}</label>
                    <div class="checkbox-wrapper-7">
                        <input class="tgl tgl-ios" id="cb2-7" type="checkbox" v-model="isFilterDownloaded" />
                        <label class="tgl-btn" for="cb2-7"></label>
                    </div>
                </div>
            </template>
        </Breadcrumbs>

        <SearchBar
            v-if="showSearchPanel"
            ref="searchBarRef"
            class="mt-4 mb-7"
            :model-value="searchQuery"
            :placeholder="'搜索标题 / BV号 / ID（Ctrl+F）'"
            :result-count="searchMatchIndexes.length"
            :current-index="currentSearchIndex"
            :result-found-text="`找到 ${searchMatchIndexes.length} 个结果`"
            :navigate-hint-text="'按 Enter 或 F3 跳转到下一个结果'"
            :no-result-text="'未找到匹配结果'"
            :idle-hint-text="'快捷键：Ctrl+F 打开/关闭，Enter/F3 下一个，Shift+F3 上一个，Esc 关闭'"
            @update:model-value="searchQuery = $event"
            @enter="navigateToNextResult"
            @esc="closeSearchPanel"
            @clear="clearSearchKeyword"
        />
        <div v-if="showSearchPanel" class="search-panel-divider" aria-hidden="true"></div>

        <div v-if="loading" class="flex items-center justify-center min-h-[400px]">
            <div class="text-center">
                <div class="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
                <p class="mt-4 text-gray-600">{{ t('common.loading') }}</p>
            </div>
        </div>

        <div v-else class="grid grid-cols-1 md:grid-cols-5 w-full gap-4 mt-4">
            <div 
                class="flex flex-col relative transition-all duration-300" 
                v-for="item in displayedVideoList" 
                :key="item.id"
                :data-video-id="item.id"
                :class="{
                    'search-current': isCurrentMatch(item.id),
                    'search-highlight': isPulseMatch(item.id),
                }"
            >
                <RouterLink :to="{ name: 'favlist-video-id', params: { id: id, video_id: item.id } }" class="relative block rounded-lg overflow-hidden group">
                    <Image class="w-full h-auto aspect-video object-cover group-hover:scale-105 transition-all duration-300"
                        :src="item.cover_image_url ?? item.cover ?? '/assets/images/notfound.webp'" :title="item.title"
                        :class="{ 'grayscale-image': item.video_downloaded_num == 0 && item.audio_downloaded_num == 0 }" />
                    
                    <div class="absolute bottom-0 left-0 right-0 w-full px-2 py-1.5 flex justify-between items-end text-white text-[13px] z-10 pointer-events-none" style="background-image: linear-gradient(180deg, transparent 0%, rgba(0,0,0,0.8) 100%);">
                        <div class="flex items-center tracking-wide font-medium drop-shadow">
                            <svg class="w-[18px] h-[18px] mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2.5" y="4" width="19" height="16" rx="3" /><path d="M10 9L15 12L10 15V9Z" fill="currentColor" stroke="none" /></svg>
                            <span>{{ formatViewCount(item.view || item.stat?.view || 0) }}</span>
                        </div>
                        <div class="flex items-center tracking-wide font-medium drop-shadow">
                            <span>{{ formatDuration(item.duration || 0) }}</span>
                        </div>
                    </div>
                </RouterLink>

                <div class="absolute top-2 left-2 z-10 pointer-events-none" v-if="item.frozen == 1">💾</div>
                
                <span 
                    class="mt-4 text-center h-12 line-clamp-2" 
                    :title="item.title" 
                    v-html="renderTitleWithHighlight(item)"
                ></span>
                
                <div class="mt-2 flex justify-between text-xs text-gray-400 px-1">
                    <span>{{ t('favorites.published') }}: {{ formatTimestamp(item.pubtime, "yyyy.mm.dd") }}</span>
                    <span v-if="item.fav_time">{{ t('favorites.favorited') }}: {{ formatTimestamp(item.fav_time, "yyyy.mm.dd") }}</span>
                </div>
                
                <span v-if="item.page > 1"
                    class="text-sm text-white bg-gray-600 rounded-lg min-w-10 px-1.5 text-center absolute top-2 right-2 z-10 pointer-events-none">
                    {{ item.page }}
                </span>
            </div>
        </div>

        <div ref="sentinel" class="w-full h-12 mt-6 flex justify-center items-center">
            <span v-if="displayCount < showVideoList.length" class="text-gray-400 text-sm animate-pulse">正在加载更多...</span>
            <span v-else-if="showVideoList.length > 0" class="text-gray-400 text-sm">- 到底了 -</span>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import Image from '@/components/Image.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import SearchBar from '@/components/SearchBar.vue';

// 修复导入冲突
import { formatTimestamp as _formatTimestamp, image, formatViewCount, formatDuration } from "../lib/helper"
import { getFavDetail, getFavVideos, type Favorite, type FavVideo } from '@/api/fav';

const formatTimestamp = (value: number | string | null, format: string) => {
    if (!value) return '';
    const ts = typeof value === 'number' ? value : Math.floor(new Date(value).getTime() / 1000);
    return _formatTimestamp(ts, format);
};

const { t } = useI18n();
const route = useRoute();
const id = Number(route.params.id);
const favorite = ref<Favorite | null>(null);
const videoList = ref<FavVideo[]>([]);
const loading = ref(true);
const isFilterDownloaded = ref(false);

// 搜索相关状态
const showSearchPanel = ref(false);
const searchQuery = ref('');
const searchBarRef = ref<any>(null);
const currentSearchIndex = ref(-1);
const highlightedVideoId = ref<number | null>(null);
const pulseVideoId = ref<number | null>(null);
const pulseTimer = ref<number | null>(null);

const breadcrumbItems = computed(() => {
    return [
        { text: t('navigation.home'), to: '/' },
        { text: favorite.value?.title ?? t('common.loading') }
    ];
});

// === 【恢复】您的核心过滤逻辑 (将原可见列表重命名回 showVideoList 修复报错) ===
const showVideoList = computed(() => {
    return videoList.value.filter((value: FavVideo) => {
        if (isFilterDownloaded.value) {
            return value.video_downloaded_num > 0 || value.audio_downloaded_num > 0;
        }
        return true;
    });
});

// === 【恢复】您的前端切片懒加载逻辑 ===
const displayCount = ref(50); 
const displayedVideoList = computed(() => {
    return showVideoList.value.slice(0, displayCount.value);
});

const sentinel = ref<HTMLElement | null>(null);
let observer: IntersectionObserver | null = null;

watch(isFilterDownloaded, () => {
    displayCount.value = 50;
});

onMounted(() => {
    observer = new IntersectionObserver((entries) => {
        if (entries[0].isIntersecting && displayCount.value < showVideoList.value.length) {
            displayCount.value += 40;
        }
    }, { rootMargin: '400px' }); 

    if (sentinel.value) {
        observer.observe(sentinel.value);
    }
});

onUnmounted(() => {
    if (observer) observer.disconnect();
    if (pulseTimer.value !== null) {
        window.clearTimeout(pulseTimer.value);
    }
    document.removeEventListener('keydown', handleKeyDown);
});

// ==========================================
// 适配后的搜索逻辑 (合并进来的功能，做兼容处理)
// ==========================================
const searchMatchIndexes = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();
    if (!query) return [];

    const matches: number[] = [];
    showVideoList.value.forEach((video, index) => {
        const videoId = String(video.id ?? '');
        const bvid = (video.bvid ?? '').toLowerCase();
        const title = (video.title ?? '').toLowerCase();

        if (title.includes(query) || bvid.includes(query) || videoId.includes(query)) {
            matches.push(index);
        }
    });

    return matches;
});

const openSearchPanel = () => {
    showSearchPanel.value = true;
    nextTick(() => {
        searchBarRef.value?.focusInput?.();
        searchBarRef.value?.selectInput?.();
    });
};

const closeSearchPanel = () => {
    showSearchPanel.value = false;
    searchQuery.value = '';
    currentSearchIndex.value = -1;
    highlightedVideoId.value = null;
    pulseVideoId.value = null;
};

const clearSearchKeyword = () => {
    searchQuery.value = '';
    currentSearchIndex.value = -1;
    highlightedVideoId.value = null;
    pulseVideoId.value = null;
};

const isCurrentMatch = (videoId: number | string) => {
    return highlightedVideoId.value === Number(videoId);
};

const isPulseMatch = (videoId: number | string) => {
    return pulseVideoId.value === Number(videoId);
};

const triggerPulseHighlight = (videoId: number) => {
    pulseVideoId.value = null;
    nextTick(() => {
        pulseVideoId.value = videoId;
    });

    if (pulseTimer.value !== null) {
        window.clearTimeout(pulseTimer.value);
    }
    pulseTimer.value = window.setTimeout(() => {
        if (pulseVideoId.value === videoId) {
            pulseVideoId.value = null;
        }
    }, 2200);
};

const escapeHtml = (value: string) => {
    return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
};

const renderTitleWithHighlight = (video: FavVideo) => {
    const title = video.title ?? '';
    const safeTitle = escapeHtml(title);
    const keyword = searchQuery.value.trim();

    if (!keyword || !isCurrentMatch(video.id)) return safeTitle;

    const lowerTitle = title.toLowerCase();
    const lowerKeyword = keyword.toLowerCase();
    const start = lowerTitle.indexOf(lowerKeyword);
    if (start === -1) return safeTitle;

    const end = start + keyword.length;
    return `${escapeHtml(title.slice(0, start))}<mark class="fav-search-mark">${escapeHtml(title.slice(start, end))}</mark>${escapeHtml(title.slice(end))}`;
};

const scrollToCurrentSearchResult = () => {
    if (currentSearchIndex.value < 0 || currentSearchIndex.value >= searchMatchIndexes.value.length) return;

    const targetVideoIndex = searchMatchIndexes.value[currentSearchIndex.value];
    const targetVideo = showVideoList.value[targetVideoIndex];
    if (!targetVideo) return;
    
    highlightedVideoId.value = Number(targetVideo.id);

    // 【关键兼容】：如果目标视频被折叠在懒加载之外，自动扩展加载量，确保页面能渲染并跳过去
    if (targetVideoIndex >= displayCount.value) {
        displayCount.value = targetVideoIndex + 10;
    }

    nextTick(() => {
        setTimeout(() => {
            const element = document.querySelector(`[data-video-id="${targetVideo.id}"]`) as HTMLElement | null;
            if (!element) return;
            // 减掉顶部偏移，防止被遮挡
            const y = element.getBoundingClientRect().top + window.scrollY - 100;
            window.scrollTo({ top: y, behavior: 'smooth' });
            triggerPulseHighlight(Number(targetVideo.id));
        }, 100); // 留给 Vue 扩充 DOM 的时间
    });
};

const navigateToNextResult = () => {
    if (searchMatchIndexes.value.length === 0) return;
    currentSearchIndex.value = currentSearchIndex.value < 0 ? 0 : (currentSearchIndex.value + 1) % searchMatchIndexes.value.length;
    scrollToCurrentSearchResult();
};

const navigateToPrevResult = () => {
    if (searchMatchIndexes.value.length === 0) return;
    currentSearchIndex.value = currentSearchIndex.value <= 0 ? searchMatchIndexes.value.length - 1 : currentSearchIndex.value - 1;
    scrollToCurrentSearchResult();
};

const handleKeyDown = (e: KeyboardEvent) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') {
        e.preventDefault();
        showSearchPanel.value ? closeSearchPanel() : openSearchPanel();
        return;
    }
    if (e.key === 'F3' && searchQuery.value.trim()) {
        e.preventDefault();
        e.shiftKey ? navigateToPrevResult() : navigateToNextResult();
    }
};

watch([searchQuery, isFilterDownloaded], () => {
    currentSearchIndex.value = -1;
});

onMounted(() => {
    document.addEventListener('keydown', handleKeyDown);
});

// 数据获取
getFavDetail(id).then((result) => { favorite.value = result; });
getFavVideos(id).then((result) => { videoList.value = result; }).finally(() => { loading.value = false; });
</script>

<style scoped>
.search-panel-divider {
    height: 14px;
    margin-top: -20px;
    margin-bottom: 8px;
    pointer-events: none;
    background: linear-gradient(to bottom, rgba(15, 23, 42, 0.14), rgba(15, 23, 42, 0));
}
</style>
<style>
/* 搜索高亮动画 (保留上游特性) */
.search-highlight {
    border-radius: 12px;
    animation: search-focus-ring 2200ms cubic-bezier(0.22, 1, 0.36, 1) 1;
}

.search-current {
    border-radius: 12px;
    background: linear-gradient(180deg, rgba(59, 130, 246, 0.14), rgba(59, 130, 246, 0.06));
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.52);
    padding: 6px;
    margin: -6px;
}

.fav-search-mark {
    background: #ff9632;
    color: #111827;
    padding: 0 2px;
    border-radius: 3px;
}

@keyframes search-focus-ring {
    0% {
        box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.7), 0 0 0 0 rgba(59, 130, 246, 0.25);
        background: linear-gradient(180deg, rgba(59, 130, 246, 0.24), rgba(59, 130, 246, 0.12));
        transform: translateY(0) scale(1);
    }
    35% {
        box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.82), 0 0 0 10px rgba(59, 130, 246, 0.2);
        background: linear-gradient(180deg, rgba(59, 130, 246, 0.2), rgba(59, 130, 246, 0.08));
        transform: translateY(-2px) scale(1.005);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(59, 130, 246, 0), 0 0 0 0 rgba(59, 130, 246, 0);
        background: linear-gradient(180deg, rgba(59, 130, 246, 0.14), rgba(59, 130, 246, 0.06));
        transform: translateY(0) scale(1);
    }
}
</style>