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
            <div v-for="comment in comments" :key="comment.id" class="group">
                <VideoCommentItem :comment="comment" :upper-id="upperId" />
            </div>
            
            <div ref="sentinel" class="py-4 text-center text-xs text-gray-400">
                <span v-if="loadingMore">正在加载更多...</span>
                <span v-else-if="nextPageUrl">下拉加载更多</span>
                <span v-else>- 到底了 (共 {{ totalCount }} 条) -</span>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, watch, onUnmounted, nextTick } from 'vue';
import axios from 'axios';
import VideoCommentItem from './VideoCommentItem.vue';

const props = defineProps<{
    videoId: number;
    upperId?: number;
}>();

const comments = ref<any[]>([]);
// 将 totalCount 变成一个普通的 ref，初始值为 0 (删掉原来的 computed)
const totalCount = ref(0);
const loading = ref(true);
const loadingMore = ref(false);
const nextPageUrl = ref<string | null>(null); // 保存后端返回的下一页 AJAX 链接
const sentinel = ref<HTMLElement | null>(null);
let observer: IntersectionObserver | null = null;

const fetchComments = async (isLoadMore = false) => {
    if (!props.videoId) return;
    
    // 如果正在加载中，或者没有下一页了，就不再重复请求
    if (isLoadMore) {
        if (!nextPageUrl.value || loadingMore.value) return;
        loadingMore.value = true;
    } else {
        loading.value = true;
        comments.value = []; // 首次加载清空数据
    }
    
    try {
        // 判断是请求第一页，还是请求下一页的完整 URL
        const url = isLoadMore ? nextPageUrl.value : `/api/videos/${props.videoId}/comments`;
        const response = await axios.get(url);
        
        // simplePaginate 返回的数据结构是 { data: [...], next_page_url: "..." }
        if (isLoadMore) {
            comments.value.push(...response.data.data); // 将新评论追加到现有数组中
        } else {
            comments.value = response.data.data;
        }
        
        // 从后端的 paginate 响应中读取真实的总评论数
        totalCount.value = response.data.total; 
        nextPageUrl.value = response.data.next_page_url;
        
        if (!isLoadMore) {
            nextTick(() => setupObserver());
        }
    } catch (e) {
        console.error("Failed to load comments", e);
    } finally {
        loading.value = false;
        loadingMore.value = false;
    }
};

const setupObserver = () => {
    if (observer) observer.disconnect();
    if (!sentinel.value) return;

    observer = new IntersectionObserver((entries) => {
        // 当触底，且有下一页，且当前没有在加载时，触发真正的 AJAX 请求
        if (entries[0].isIntersecting && nextPageUrl.value && !loadingMore.value) {
            fetchComments(true); 
        }
    }, { rootMargin: '200px' }); // 提前 200px 触发，让用户感觉不到卡顿

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