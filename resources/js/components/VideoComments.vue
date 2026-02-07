<template>
    <div class="bg-white/90 backdrop-blur-sm rounded-xl shadow-lg border border-gray-200/50 p-6 mt-6">
        <h3 class="text-xl font-bold mb-6 flex items-center gap-2 text-gray-800">
            <span>评论</span>
            <span v-if="totalCount > 0" class="text-xs font-normal text-gray-500 bg-gray-100 px-3 py-1 rounded-full border border-gray-200">
                {{ totalCount }}
            </span>
        </h3>
        
        <div v-if="loading && comments.length === 0" class="py-12 text-center">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-pink-500"></div>
            <p class="mt-2 text-gray-400 text-sm">正在加载评论...</p>
        </div>
        
        <div v-else-if="comments.length === 0" class="py-12 text-center flex flex-col items-center">
            <span class="text-4xl mb-3">😶</span>
            <p class="text-gray-400">暂无评论</p>
        </div>
        
        <div v-else class="space-y-6">
            <div v-for="comment in visibleComments" :key="comment.id" class="group">
                <VideoCommentItem :comment="comment" :upper-id="upperId" />
                </div>
            
            <div ref="sentinel" class="py-4 text-center text-xs text-gray-400">
                <span v-if="hasMore">正在加载更多...</span>
                <span v-else>- 到底了 (共 {{ totalCount }} 条) -</span>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, watch, computed, onUnmounted, nextTick } from 'vue';
import axios from 'axios';
import VideoCommentItem from './VideoCommentItem.vue';

const props = defineProps<{
    videoId: number;
    upperId?: number;
}>();

const comments = ref([]);
const loading = ref(true);
const sentinel = ref<HTMLElement | null>(null);
let observer: IntersectionObserver | null = null;

// 分页控制
const pageSize = 10;
const page = ref(1);

const totalCount = computed(() => comments.value.length);
const visibleComments = computed(() => comments.value.slice(0, page.value * pageSize));
const hasMore = computed(() => visibleComments.value.length < totalCount.value);

const fetchComments = async () => {
    if (!props.videoId) return;
    
    loading.value = true;
    try {
        const response = await axios.get(`/api/videos/${props.videoId}/comments`);
        comments.value = response.data;
        page.value = 1; 
        
        // 数据加载后，重新挂载观察者
        nextTick(() => setupObserver());
    } catch (e) {
        console.error("Failed to load comments", e);
    } finally {
        loading.value = false;
    }
};

// 设置 IntersectionObserver
const setupObserver = () => {
    if (observer) observer.disconnect();
    
    if (!sentinel.value) return;

    observer = new IntersectionObserver((entries) => {
        if (entries[0].isIntersecting && hasMore.value) {
            // 模拟加载延迟，体验更好
            setTimeout(() => {
                page.value++;
            }, 200);
        }
    }, { rootMargin: '100px' }); // 提前100px触发

    observer.observe(sentinel.value);
};

onMounted(() => {
    fetchComments();
});

onUnmounted(() => {
    if (observer) observer.disconnect();
});

watch(() => props.videoId, (newId) => {
    if(newId) fetchComments();
});
</script>