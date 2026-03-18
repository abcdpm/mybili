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
                <RouterLink :to="{ name: 'video-id', params: { id: item.id } }">
                    <div class="rounded-lg overflow-hidden aspect-video w-full bg-gray-100 relative group">
                        <Image class="w-full h-full object-cover group-hover:scale-105 transition-all duration-300"
                            :src="item.cover ? (item.cover.startsWith('http') ? item.cover : image(item.cover)) : '/assets/images/notfound.webp'" 
                            :title="item.title"
                            referrerpolicy="no-referrer"
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
                    </div>
                </RouterLink>
                
                <span class="mt-4 text-center  h-12 line-clamp-2" :title="item.title">{{ item.title }}</span>
                <div class="mt-2 flex justify-between text-xs text-gray-400 px-1">
                    <span>{{ t('favorites.published') }}: {{ formatTimestamp(item.pubtime, "yyyy.mm.dd") }}</span>
                </div>
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
import { type Video } from '@/api/fav';
import { getSubscriptionDetail, type Subscription } from '@/api/subscription';

const { t } = useI18n();
const route = useRoute()
const id = route.params.id
const subscription = ref<Subscription | null>(null)

const isFilterDownloaded = ref(false)

const breadcrumbItems = computed(() => {
    return [
        { text: t('navigation.home'), to: '/' },
        { text: t('subscription.title') , to: '/subscription' },
        { text: subscription.value?.name ?? t('common.loading') }
    ]
})

const showVideoList = computed(() => {
    return (subscription.value?.videos ?? []).filter((value: Video) => {
        if (isFilterDownloaded.value) {
            console.log(value.video_downloaded_num)
            return value.video_downloaded_num > 0;
        }
        return true;
    })
})

// === 【修改】完全同步收藏夹的防卡顿懒加载逻辑 ===
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
            displayCount.value += 50;
        }
    }, { rootMargin: '400px' });

    if (sentinel.value) {
        observer.observe(sentinel.value);
    }
});

onUnmounted(() => {
    if (observer) observer.disconnect();
});

getSubscriptionDetail(Number(id)).then((result) => {
    subscription.value = result
})
</script>
<style scoped></style>