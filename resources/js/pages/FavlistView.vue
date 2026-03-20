<template>
    <div class="m-4">
        <Breadcrumbs :items="breadcrumbItems">
            <template #actions>
                <div class="flex items-center  gap-2">
                    <label class="text-slate-500">{{ t('favorites.downloaded') }}</label>
                    <div class="checkbox-wrapper-7">
                        <input class="tgl tgl-ios" id="cb2-7" type="checkbox" v-model="isFilterDownloaded" />
                        <label class="tgl-btn" for="cb2-7"></label>
                    </div>
                </div>
            </template>
        </Breadcrumbs>

        <div class="grid grid-cols-1 md:grid-cols-5 w-full gap-4">
            <div class="flex flex-col relative" v-for="item in displayedVideoList" :key="item.id">
                <RouterLink :to="{ name: 'favlist-video-id', params: { id: id, video_id: item.id } }" class="relative block rounded-lg overflow-hidden group">
                    <Image class="w-full h-auto aspect-video object-cover group-hover:scale-105 transition-all duration-300"
                        :src="item.cover_info?.image_url ?? '/assets/images/notfound.webp'" :title="item.title"
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
                <div class="absolute top-2 left-2" v-if="item.frozen == 1">💾</div>
                <span class="mt-4 text-center  h-12 line-clamp-2" :title="item.title">{{ item.title }}</span>
                <div class="mt-2 flex justify-between text-xs text-gray-400 px-1">
                    <span>{{ t('favorites.published') }}: {{ formatTimestamp(item.pubtime, "yyyy.mm.dd") }}</span>
                    <span v-if="item.fav_time">{{ t('favorites.favorited') }}: {{ formatTimestamp(item.fav_time,
                        "yyyy.mm.dd")
                        }}</span>
                </div>
                <span v-if="item.page > 1"
                    class="text-sm text-white bg-gray-600 rounded-lg w-10 text-center  absolute top-2 right-2">{{
                        item.page }}</span>
            </div>
        </div>
        <div ref="sentinel" class="w-full h-12 mt-6 flex justify-center items-center">
            <span v-if="displayCount < showVideoList.length" class="text-gray-400 text-sm animate-pulse">正在加载更多...</span>
            <span v-else-if="showVideoList.length > 0" class="text-gray-400 text-sm">- 到底了 -</span>
        </div>
    </div>
</template>
<script lang="ts" setup>
import { computed, ref, onMounted, onUnmounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import Image from '@/components/Image.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';

import { formatTimestamp, image, formatViewCount, formatDuration } from "../lib/helper"
import { getFavDetail, type Favorite, type Video } from '@/api/fav';

const { t } = useI18n();
const route = useRoute()
const id = route.params.id
const favorite = ref<Favorite | null>(null)

const isFilterDownloaded = ref(false)

const breadcrumbItems = computed(() => {
    return [
        { text: t('navigation.home'), to: '/' },
        { text: favorite.value?.title ?? t('common.loading') }
    ]
})

const showVideoList = computed(() => {
    return (favorite.value?.videos ?? []).filter((value: Video) => {
        if (isFilterDownloaded.value) {
            return value.video_downloaded_num > 0 || value.audio_downloaded_num > 0;
        }
        return true;
    })
})

// === 【新增】前端切片懒加载逻辑 ===
const displayCount = ref(50); // 默认首次只渲染 50 个
const displayedVideoList = computed(() => {
    return showVideoList.value.slice(0, displayCount.value);
});

const sentinel = ref<HTMLElement | null>(null);
let observer: IntersectionObserver | null = null;

// 【新增】当用户切换"仅看已下载"开关时，重置显示数量，防止渲染过多导致卡顿
watch(isFilterDownloaded, () => {
    displayCount.value = 50;
});

onMounted(() => {
    observer = new IntersectionObserver((entries) => {
        // 当触底且还有未渲染的数据时，追加 40 个
        if (entries[0].isIntersecting && displayCount.value < showVideoList.value.length) {
            displayCount.value += 40;
        }
    }, { rootMargin: '400px' }); // 提前 400px 触发，让用户无感加载

    if (sentinel.value) {
        observer.observe(sentinel.value);
    }
});

onUnmounted(() => {
    if (observer) observer.disconnect();
});

getFavDetail(Number(id)).then((result) => {
    favorite.value = result
})
</script>
<style scoped></style>
