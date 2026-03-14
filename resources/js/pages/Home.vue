<template>
    <div class="m-4">
        <div class="flex justify-between">
            <h1 class="my-8 text-2xl mt-4 md:mt-0">
                <RouterLink to="/">🌸</RouterLink> {{ t('home.favoriteList') }}
            </h1>
            <h1 class="my-8 text-2xl mt-4 md:mt-0">
                <RouterLink to="/progress">🌸</RouterLink> {{ t('navigation.progress') }}
            </h1>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-5 w-full gap-4" @dragover.prevent @drop="onDragEnd">
            
            <div class="flex flex-col relative bg-white p-2 rounded-lg transition-transform duration-200 cursor-move select-none" 
                 v-for="(item, index) in favList" :key="item.id"
                 draggable="true"
                 @dragstart="onDragStart(index, $event)" 
                 @dragenter.prevent="onDragEnter(index)"
                 @dragover.prevent
                 :class="{'opacity-40 scale-95 border-2 border-dashed border-blue-400': dragIndex === index}"
            >
                <RouterLink 
                    draggable="false"
                    :to="item.id > 0 ? `/fav/${item.id}` : `/subscription/${Math.abs(item.id)}`" 
                    :class="{'pointer-events-none': dragIndex !== null}"
                >
                    <div class="image-container rounded-lg overflow-hidden" :style="{ aspectRatio: '4/3' }">
                        <Image class="w-full h-full object-cover transition-all duration-300"
                            draggable="false"
                            :class="{'hover:scale-105': dragIndex === null}" 
                            :src="item.cover ? (item.cover.startsWith('http') ? item.cover : image(item.cover)) : '/assets/images/notfound.webp'" 
                            :title="item.title" 
                            referrerpolicy="no-referrer" />
                    </div>
                </RouterLink>
                
                <span class="mt-4 text-center font-sans" :title="item.title">{{ item.title }}</span>
                
                <div class="mt-2 flex justify-between text-xs text-gray-400 px-1">
                    <span>{{ t('home.created') }}: {{ formatTimestamp(item.ctime, "yyyy.mm.dd") }}</span>
                    <span>{{ t('home.updated') }}: {{ formatTimestamp(item.mtime, "yyyy.mm.dd") }}</span>
                </div>
                
                <span class="text-sm text-white bg-gray-600 rounded-lg w-10 text-center absolute top-4 right-4 pointer-events-none">
                    {{ item.media_count }}
                </span>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Favorite } from '@/api/fav';

const { t } = useI18n();

const favList = ref<Favorite[]>([]);

import { formatTimestamp, image } from "../lib/helper"
import Image from '@/components/Image.vue';
import { getFavList, reorderFavs } from '@/api/fav';


getFavList().then((result) => {
    favList.value = result
})

// === 【新增】拖拽排序逻辑 ===
const dragIndex = ref<number | null>(null);

// 【修改】接收 event，并强制写入拖拽数据（标准 HTML5 拖拽要求）
const onDragStart = (index: number, event: DragEvent) => {
    dragIndex.value = index;
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        // 写入一个空数据，这是激活完美拖拽的“钥匙”
        event.dataTransfer.setData('text/plain', index.toString());
    }
};

const onDragEnter = (index: number) => {
    // 移除 item.id < 0 的限制
    if (dragIndex.value === null || dragIndex.value === index) return;
    
    const draggedItem = favList.value[dragIndex.value];
    favList.value.splice(dragIndex.value, 1);
    favList.value.splice(index, 0, draggedItem);
    
    dragIndex.value = index; 
};

const onDragEnd = async () => {
    if (dragIndex.value === null) return;
    dragIndex.value = null;
    
    // 去掉 filter 拦截，直接把所有正负数混合的 ID 发给后端
    const newOrderIds = favList.value.map(item => item.id);
    
    try {
        await reorderFavs(newOrderIds);
    } catch (e) {
        console.error('排序保存失败！', e);
    }
};
</script>

<style scoped>
/* 样式代码 */
</style>