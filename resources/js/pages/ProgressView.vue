<template>
    <div class="w-full flex justify-center">
        <div class="container mx-auto justify-center" id="main">
            <div class="m-4">

                <div class="flex justify-between">
                    <h1 class="my-8 text-2xl mt-0">
                        <RouterLink to="/">🌸</RouterLink> {{ t('progress.title') }} {{ $route.params.id }}
                    </h1>
                    <div class="flex gap-4 my-8 text-2xl mt-0 items-center">
                        <button @click="showMobileSearch = true"
                            class="md:hidden text-2xl hover:text-blue-600 transition-colors"
                            :title="t('progress.searchButtonTitle')">
                            🔍
                        </button>
                        <button @click="openDesktopSearch"
                            class="hidden md:block text-2xl hover:text-blue-600 transition-colors"
                            :title="t('progress.searchButtonTitlePC')">
                            🔍
                        </button>
                        <RouterLink to="/videos" class="hover:text-blue-600 transition-colors">
                            🎬<span class="hidden md:inline"> {{ t('navigation.videoManagement') }}</span>
                        </RouterLink>
                        <RouterLink to="/download-queue" class="hover:text-blue-600 transition-colors">
                            📥<span class="hidden md:inline"> 下载队列</span>
                        </RouterLink>
                        <RouterLink to="/horizon" target="_blank" class="hover:text-blue-600 transition-colors">
                            🔭<span class="hidden md:inline"> {{ t('progress.viewTasks') }}</span>
                        </RouterLink>
                    </div>
                </div>

                <SearchBar
                    v-if="showDesktopSearch"
                    ref="desktopSearchBarRef"
                    class="hidden md:block mb-4"
                    :model-value="searchQuery"
                    :placeholder="t('progress.searchPlaceholder')"
                    :result-count="searchResults.length"
                    :current-index="currentSearchIndex"
                    :result-found-text="t('progress.searchResultsFound', { count: searchResults.length })"
                    :navigate-hint-text="t('progress.searchNavigateHint')"
                    :no-result-text="t('progress.searchNoResults')"
                    @update:model-value="searchQuery = $event"
                    @enter="navigateToNextResult"
                    @esc="closeSearch"
                    @clear="clearSearch"
                />

                <SearchBar
                    v-if="showMobileSearch"
                    ref="mobileSearchBarRef"
                    class="md:hidden mb-4"
                    :model-value="searchQuery"
                    :placeholder="t('progress.searchPlaceholderMobile')"
                    :result-count="searchResults.length"
                    :current-index="currentSearchIndex"
                    :result-found-text="t('progress.searchResultsFound', { count: searchResults.length })"
                    :no-result-text="t('progress.searchNoResults')"
                    @update:model-value="searchQuery = $event"
                    @enter="navigateToNextResult"
                    @esc="closeSearch"
                    @clear="closeSearch"
                />

                <div class="flex flex-col md:flex-row md:justify-between gap-4 md:gap-0">
                    <h2 class="text-xl" :title="t('progress.cacheRateDescription')">{{ t('progress.cacheRate') }} {{
                        progress }}% ({{ stat.downloaded
                        }}/{{ stat.count }})</h2>

                    <div class="flex items-center gap-2">
                        <button @click="showCachedOnly = !showCachedOnly" :class="[
                            'relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2',
                            showCachedOnly ? 'bg-blue-600' : 'bg-gray-200'
                        ]" role="switch" :aria-checked="showCachedOnly">
                            <span :class="[
                                'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
                                showCachedOnly ? 'translate-x-6' : 'translate-x-1'
                            ]" />
                        </button>
                        <label class="text-sm text-gray-700 cursor-pointer" @click="showCachedOnly = !showCachedOnly">
                            {{ t('progress.showCachedOnly') }}
                        </label>
                    </div>
                </div>

                <div class="my-8 w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
                    <div class="bg-blue-600 h-2.5 rounded-full" :style="{ width: progress + '%' }"></div>
                </div>

                <div class="hidden md:grid grid-cols-4 w-full my-4">
                    <div class="flex flex-col text-center text-white bg-blue-400 hover:bg-gradient-to-r from-purple-500 to-pink-500  py-4 rounded-l-lg"
                        :class="{ 'bg-gradient-to-r': filter.class == null }" @click="setFilter(null)">
                        <span class="text-2xl" :title="t('progress.allVideosDescription')">{{ t('progress.allVideos')
                        }}</span>
                        <span class="text-xl font-semibold">{{ stat.count }}</span>
                    </div>
                    <div class="flex flex-col text-center text-white bg-blue-400 hover:bg-gradient-to-r from-purple-500 to-pink-500 py-4"
                        :class="{ 'bg-gradient-to-r': filter.class == 'valid' }" @click="setFilter('valid')">
                        <span class="text-2xl" :title="t('progress.validVideosDescription')">{{
                            t('progress.validVideos') }}</span>
                        <span class="text-xl font-semibold">{{ stat.valid }}</span>
                    </div>
                    <div class="flex flex-col text-center text-white bg-blue-400 hover:bg-gradient-to-r from-purple-500 to-pink-500 py-4"
                        :class="{ 'bg-gradient-to-r': filter.class == 'invalid' }" @click="setFilter('invalid')">
                        <span class="text-2xl" :title="t('progress.invalidVideosDescription')">{{
                            t('progress.invalidVideos') }}</span>
                        <span class="text-xl font-semibold">{{ stat.invalid }}</span>
                    </div>
                    <div class="flex flex-col text-center text-white bg-blue-400 hover:bg-gradient-to-r from-purple-500 to-pink-500 py-4 rounded-r-lg"
                        :class="{ 'bg-gradient-to-r': filter.class == 'frozen' }" @click="setFilter('frozen')">
                        <span class="text-2xl" :title="t('progress.frozenVideosDescription')">{{
                            t('progress.frozenVideos') }}</span>
                        <span class="text-xl font-semibold">{{ stat.frozen }}</span>
                    </div>
                </div>

                <div ref="filterRef" class="md:hidden w-full my-4">
                    <div class="mb-4 p-4 bg-gradient-to-r from-blue-500 to-purple-600 rounded-lg text-white shadow-lg">
                        <div class="flex items-center space-x-3">
                            <div class="w-3 h-3 bg-white rounded-full"></div>
                            <div>
                                <div class="text-lg font-semibold">
                                    {{ getCurrentFilterLabel() }}
                                </div>
                                <div class="text-sm opacity-90">
                                    {{ getCurrentFilterCount() }} 个视频
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-white rounded-lg p-4 shadow-sm border-2 transition-all"
                            :class="filter.class == null ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:border-gray-300'"
                            @click="setFilter(null)">
                            <div class="text-center">
                                <div class="text-2xl mb-1">📺</div>
                                <div class="text-sm font-medium text-gray-700">{{ t('progress.allVideos') }}</div>
                                <div class="text-lg font-bold text-gray-900">{{ stat.count }}</div>
                            </div>
                        </div>
                        <div class="bg-white rounded-lg p-4 shadow-sm border-2 transition-all"
                            :class="filter.class == 'valid' ? 'border-green-500 bg-green-50' : 'border-gray-200 hover:border-gray-300'"
                            @click="setFilter('valid')">
                            <div class="text-center">
                                <div class="text-2xl mb-1">✅</div>
                                <div class="text-sm font-medium text-gray-700">{{ t('progress.validVideos') }}</div>
                                <div class="text-lg font-bold text-gray-900">{{ stat.valid }}</div>
                            </div>
                        </div>
                        <div class="bg-white rounded-lg p-4 shadow-sm border-2 transition-all"
                            :class="filter.class == 'invalid' ? 'border-red-500 bg-red-50' : 'border-gray-200 hover:border-gray-300'"
                            @click="setFilter('invalid')">
                            <div class="text-center">
                                <div class="text-2xl mb-1">❌</div>
                                <div class="text-sm font-medium text-gray-700">{{ t('progress.invalidVideos') }}</div>
                                <div class="text-lg font-bold text-gray-900">{{ stat.invalid }}</div>
                            </div>
                        </div>
                        <div class="bg-white rounded-lg p-4 shadow-sm border-2 transition-all"
                            :class="filter.class == 'frozen' ? 'border-orange-500 bg-orange-50' : 'border-gray-200 hover:border-gray-300'"
                            @click="setFilter('frozen')">
                            <div class="text-center">
                                <div class="text-2xl mb-1">🧊</div>
                                <div class="text-sm font-medium text-gray-700">{{ t('progress.frozenVideos') }}</div>
                                <div class="text-lg font-bold text-gray-900">{{ stat.frozen }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="isScrolled"
                    class="md:hidden bg-white border-b border-gray-300 shadow-md fixed top-0 left-0 right-0 z-40 transition-all duration-300 ease-in-out"
                    :style="{ opacity: showMiniFilter ? 1 : 0, transform: showMiniFilter ? 'translateY(0)' : 'translateY(-10px)' }">
                    <div class="grid grid-cols-4 divide-x divide-gray-300">
                        <button
                            class="flex flex-col items-center justify-center py-3 px-1 transition-all duration-200 relative"
                            :class="filter.class == null ? 'bg-blue-50 text-blue-600' : 'text-gray-600 hover:text-gray-900 active:bg-gray-50'"
                            @click="setFilter(null)">
                            <span class="text-xl leading-none">📺</span>
                            <span v-if="filter.class == null"
                                class="absolute bottom-0 left-0 right-0 h-0.5 bg-blue-600"></span>
                        </button>
                        <button
                            class="flex flex-col items-center justify-center py-3 px-1 transition-all duration-200 relative"
                            :class="filter.class == 'valid' ? 'bg-green-50 text-green-600' : 'text-gray-600 hover:text-gray-900 active:bg-gray-50'"
                            @click="setFilter('valid')">
                            <span class="text-xl leading-none">✅</span>
                            <span v-if="filter.class == 'valid'"
                                class="absolute bottom-0 left-0 right-0 h-0.5 bg-green-600"></span>
                        </button>
                        <button
                            class="flex flex-col items-center justify-center py-3 px-1 transition-all duration-200 relative"
                            :class="filter.class == 'invalid' ? 'bg-red-50 text-red-600' : 'text-gray-600 hover:text-gray-900 active:bg-gray-50'"
                            @click="setFilter('invalid')">
                            <span class="text-xl leading-none">❌</span>
                            <span v-if="filter.class == 'invalid'"
                                class="absolute bottom-0 left-0 right-0 h-0.5 bg-red-600"></span>
                        </button>
                        <button
                            class="flex flex-col items-center justify-center py-3 px-1 transition-all duration-200 relative"
                            :class="filter.class == 'frozen' ? 'bg-orange-50 text-orange-600' : 'text-gray-600 hover:text-gray-900 active:bg-gray-50'"
                            @click="setFilter('frozen')">
                            <span class="text-xl leading-none">🧊</span>
                            <span v-if="filter.class == 'frozen'"
                                class="absolute bottom-0 left-0 right-0 h-0.5 bg-orange-600"></span>
                        </button>
                    </div>
                </div>

                <div class="w-full mt-4">
                    <div class="grid grid-cols-1 md:grid-cols-5 w-full gap-4 pb-4">
                        <div class="flex flex-col relative" v-for="video in displayedVideos" :key="video.id" :data-video-id="video.id">
                            <RouterLink :to="{ name: 'video-id', params: { id: video.id } }" class="relative block rounded-lg overflow-hidden group">
                                <div class="image-container w-full h-full" :style="{ aspectRatio: '4/3' }">
                                    <Image class="w-full h-full object-cover group-hover:scale-105 transition-all duration-300"
                                        :src="video.cover_image_url ?? video.cover_info?.image_url ?? '/assets/images/notfound.webp'"
                                        :class="{ 'grayscale-image': video.video_downloaded_num == 0 && video.audio_downloaded_num == 0 }" :title="video.title" />
                                </div>
                                <div class="absolute bottom-0 left-0 right-0 w-full px-2 py-1.5 flex justify-between items-end text-white text-[13px] z-10 pointer-events-none" style="background-image: linear-gradient(180deg, transparent 0%, rgba(0,0,0,0.8) 100%);">
                                    <div class="flex items-center tracking-wide font-medium drop-shadow">
                                        <svg class="w-[18px] h-[18px] mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2.5" y="4" width="19" height="16" rx="3" /><path d="M10 9L15 12L10 15V9Z" fill="currentColor" stroke="none" /></svg>
                                        <span>{{ formatViewCount(video.view || video.stat?.view || 0) }}</span>
                                    </div>
                                    <div class="flex items-center tracking-wide font-medium drop-shadow">
                                        <span>{{ formatDuration(video.duration || 0) }}</span>
                                    </div>
                                </div>
                            </RouterLink>
                            <span class="mt-4 text-center h-12 line-clamp-2" :title="video.title">{{ video.title }}</span>
                            <div class="mt-2 flex justify-between text-xs text-gray-400 px-1">
                                <span>{{ t('progress.published') }}: {{ formatTimestamp(video.pubtime, "yyyy.mm.dd") }}</span>
                                <span v-if="video.fav_time > 0">{{ t('progress.favorited') }}: {{ formatTimestamp(video.fav_time, "yyyy.mm.dd") }}</span>
                            </div>
                            <span v-if="video.page > 1" class="text-sm text-white bg-gray-600 rounded-lg w-10 text-center absolute top-2 right-2">{{ video.page }}</span>
                        </div>
                    </div>

                    <div ref="sentinel" class="w-full h-12 mt-2 flex justify-center items-center">
                        <span v-if="displayCount < dataList.length" class="text-gray-400 text-sm animate-pulse">正在加载更多...</span>
                        <span v-else-if="dataList.length > 0" class="text-gray-400 text-sm">- 到底了 -</span>
                    </div>
                </div>

            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { computed, ref, onMounted, onUnmounted, watch, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import Image from '@/components/Image.vue'; 
import { formatTimestamp, formatViewCount, formatDuration } from '../lib/helper';
import type { ProgressVideo } from '../api/fav';
import SearchBar from '../components/SearchBar.vue';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const videoList = ref<ProgressVideo[]>([])
const progress = ref(0)
const showCachedOnly = ref(false)
const isScrolled = ref(false) 
const filterRef = ref<HTMLElement>() 
const isRestoringScroll = ref(false) 
const showMiniFilter = computed(() => !isRestoringScroll.value && isScrolled.value)
const searchQuery = ref('') 
const showMobileSearch = ref(false) 
const showDesktopSearch = ref(false) 
const desktopSearchBarRef = ref<any>(null) 
const mobileSearchBarRef = ref<any>(null) 
const currentSearchIndex = ref(-1) 
const scrollMemory = ref<Record<string, number>>({})
const currentScrollMemoryKey = ref('')

const stat = ref({
    count: 0,
    downloaded: 0,
    invalid: 0,
    valid: 0,
    frozen: 0,
})

const filter = ref<{
    class: null | string
}>({
    class: null
})

const initFilterFromUrl = () => {
    const filterParam = route.query.filter as string;
    if (filterParam && ['valid', 'invalid', 'frozen'].includes(filterParam)) {
        filter.value.class = filterParam;
    } else {
        filter.value.class = null;
    }
}

const setFilter = (filterValue: string | null) => {
    saveCurrentScrollPosition();
    filter.value.class = filterValue;

    const query = { ...route.query };
    if (filterValue) {
        query.filter = filterValue;
    } else {
        delete query.filter;
    }

    router.replace({ query });
    nextTick(() => {
        restoreScrollPositionByCurrentState();
    });
}

const searchResults = computed(() => {
    if (!searchQuery.value.trim()) {
        return []
    }
    const query = searchQuery.value.trim().toLowerCase()
    return videoList.value.filter(video =>
        video.title.toLowerCase().includes(query)
    )
})

const dataList = computed(() => {
    let list = videoList.value.filter(i => {
        if (showCachedOnly.value && i.video_downloaded_num === 0 && i.audio_downloaded_num === 0) {
            return false
        }

        if (filter.value.class == null) {
            return true
        }

        if (filter.value.class == 'invalid' && i.invalid) {
            return true
        } else if (filter.value.class == 'valid' && !i.invalid) {
            return true
        } else if (filter.value.class == 'frozen' && i.frozen) {
            return true
        }

        return false;
    })

    if (searchQuery.value.trim()) {
        const query = searchQuery.value.trim().toLowerCase()
        list = list.filter(video =>
            video.title.toLowerCase().includes(query)
        )
    }

    return list
})

// 前端切片懒加载逻辑
const displayCount = ref(50);
const displayedVideos = computed(() => {
    return dataList.value.slice(0, displayCount.value);
});

const sentinel = ref<HTMLElement | null>(null);
let scrollObserver: IntersectionObserver | null = null;

const setupScrollObserver = () => {
    if (scrollObserver) scrollObserver.disconnect();
    scrollObserver = new IntersectionObserver((entries) => {
        if (entries[0].isIntersecting && displayCount.value < dataList.value.length) {
            displayCount.value += 50; 
        }
    }, { rootMargin: '600px' });

    if (sentinel.value) {
        scrollObserver.observe(sentinel.value);
    }
};

watch(() => filter.value.class, () => { displayCount.value = 50; });
watch(searchQuery, () => { 
    currentSearchIndex.value = -1;
    displayCount.value = 50; 
});

const buildScrollMemoryKey = (): string => {
    const filterKey = filter.value.class ?? 'all'
    const cachedKey = showCachedOnly.value ? 'cached=1' : 'cached=0'
    return `${filterKey}|${cachedKey}`
}

// 采用全窗口原生滚动记忆
const saveCurrentScrollPosition = () => {
    const key = currentScrollMemoryKey.value || buildScrollMemoryKey()
    scrollMemory.value[key] = window.scrollY || document.documentElement.scrollTop
}

const restoreScrollPositionByCurrentState = () => {
    const key = buildScrollMemoryKey()
    currentScrollMemoryKey.value = key
    const savedTop = scrollMemory.value[key] ?? 0
    window.scrollTo(0, savedTop)
}

watch(() => route.query.filter, () => {
    initFilterFromUrl();
    nextTick(() => {
        restoreScrollPositionByCurrentState();
    });
}, { immediate: true });

watch(showCachedOnly, () => {
    saveCurrentScrollPosition();
    nextTick(() => {
        restoreScrollPositionByCurrentState();
    });
});

let filterObserver: IntersectionObserver | null = null;
const setupFilterObserver = () => {
    if (filterObserver) {
        filterObserver.disconnect();
        filterObserver = null;
    }

    if (window.innerWidth >= 768) {
        isScrolled.value = false;
        return;
    }

    if (!filterRef.value) return;

    filterObserver = new IntersectionObserver((entries) => {
        if (isRestoringScroll.value) return;
        const entry = entries[0];
        const newIsScrolled = !entry.isIntersecting;
        if (isScrolled.value !== newIsScrolled) {
            isScrolled.value = newIsScrolled;
        }
    }, {
        root: null,
        threshold: 0,
        rootMargin: '-1px 0px 0px 0px' 
    });

    filterObserver.observe(filterRef.value);
};

onMounted(() => {
    initFilterFromUrl();
    setupFilterObserver();
    setupScrollObserver(); 
    
    window.addEventListener('resize', setupFilterObserver, { passive: true });
    document.addEventListener('keydown', handleKeyDown);
    nextTick(setupFilterObserver);
    nextTick(() => {
        currentScrollMemoryKey.value = buildScrollMemoryKey();
        restoreScrollPositionByCurrentState();
    });
});

onUnmounted(() => {
    saveCurrentScrollPosition();
    if (filterObserver) {
        filterObserver.disconnect();
        filterObserver = null;
    }
    if (scrollObserver) { 
        scrollObserver.disconnect(); 
        scrollObserver = null; 
    }
    window.removeEventListener('resize', setupFilterObserver);
    document.removeEventListener('keydown', handleKeyDown);
});

fetch(`/api/progress`).then(async (rsp) => {
    if (rsp.ok) {
        const jsonData = await rsp.json()
        videoList.value = jsonData.data
        stat.value = jsonData.stat
        progress.value = parseInt((stat.value.downloaded / stat.value.count * 100).toFixed(2))
    }
})

const getCurrentFilterLabel = () => {
    switch (filter.value.class) {
        case 'valid': return t('progress.validVideos')
        case 'invalid': return t('progress.invalidVideos')
        case 'frozen': return t('progress.frozenVideos')
        default: return t('progress.allVideos')
    }
}

const getCurrentFilterCount = () => {
    switch (filter.value.class) {
        case 'valid': return stat.value.valid
        case 'invalid': return stat.value.invalid
        case 'frozen': return stat.value.frozen
        default: return stat.value.count
    }
}

const openDesktopSearch = () => {
    if (showDesktopSearch.value) {
        showDesktopSearch.value = false
    } else {
        showDesktopSearch.value = true
        nextTick(() => {
            desktopSearchBarRef.value?.focusInput?.()
        })
    }
}

const closeSearch = () => {
    searchQuery.value = ''
    showMobileSearch.value = false
    showDesktopSearch.value = false
    currentSearchIndex.value = -1
}

const clearSearch = () => {
    searchQuery.value = ''
    currentSearchIndex.value = -1
}

const handleKeyDown = (e: KeyboardEvent) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') {
        e.preventDefault()
        if (window.innerWidth >= 768) {
            openDesktopSearch()
        } else {
            showMobileSearch.value = true
            nextTick(() => {
                mobileSearchBarRef.value?.focusInput?.()
            })
        }
        return false
    }

    if (e.key === 'F3' && !e.shiftKey) {
        if (searchQuery.value.trim() && searchResults.value.length > 0) {
            e.preventDefault()
            navigateToNextResult()
        }
    }

    if (e.shiftKey && e.key === 'F3') {
        if (searchQuery.value.trim() && searchResults.value.length > 0) {
            e.preventDefault()
            navigateToPrevResult()
        }
    }
}

const navigateToNextResult = () => {
    if (searchResults.value.length === 0) return
    if (currentSearchIndex.value < 0) {
        currentSearchIndex.value = 0
    } else {
        currentSearchIndex.value = (currentSearchIndex.value + 1) % searchResults.value.length
    }
    scrollToSearchResult()
}

const navigateToPrevResult = () => {
    if (searchResults.value.length === 0) return
    currentSearchIndex.value = currentSearchIndex.value <= 0
        ? searchResults.value.length - 1
        : currentSearchIndex.value - 1
    scrollToSearchResult()
}

const scrollToSearchResult = () => {
    if (currentSearchIndex.value < 0 || currentSearchIndex.value >= searchResults.value.length) return;

    const targetVideo = searchResults.value[currentSearchIndex.value];
    if (!targetVideo) return;

    const videoIndex = dataList.value.findIndex(v => v.id === targetVideo.id);
    if (videoIndex === -1) return;

    if (videoIndex >= displayCount.value) {
        displayCount.value = videoIndex + 20;
    }

    nextTick(() => {
        setTimeout(() => {
            const elements = document.querySelectorAll(`[data-video-id="${targetVideo.id}"]`);
            if (elements.length > 0) {
                const el = elements[0] as HTMLElement;
                const y = el.getBoundingClientRect().top + window.scrollY - 100;
                window.scrollTo({ top: y, behavior: 'smooth' });
                
                el.classList.add('search-highlight');
                setTimeout(() => { el.classList.remove('search-highlight'); }, 2000);
            }
        }, 150); 
    });
}

watch(searchQuery, () => {
    currentSearchIndex.value = -1
})

watch(showMobileSearch, (show) => {
    if (show) {
        nextTick(() => {
            mobileSearchBarRef.value?.focusInput?.()
        })
    }
})

watch(showDesktopSearch, (show) => {
    if (show) {
        nextTick(() => {
            desktopSearchBarRef.value?.focusInput?.()
        })
    }
})
</script>

<style>
/* 仅保留搜索高亮的全局动画，彻底删除了旧版 scroller-container 相关的 CSS 限制 */
.search-highlight {
    position: relative;
    border-radius: 8px;
    background-color: rgba(59, 130, 246, 0.3);
    animation: searchHighlight 2s ease-out;
}

@keyframes searchHighlight {
    0% {
        background-color: rgba(59, 130, 246, 0.3);
        box-shadow: inset 0 0 0 3px #3b82f6;
    }

    50% {
        background-color: rgba(59, 130, 246, 0.5);
        box-shadow: inset 0 0 0 3px #2563eb;
    }

    100% {
        background-color: transparent;
        box-shadow: inset 0 0 0 3px rgba(0, 0, 0, 0);
    }
}
</style>