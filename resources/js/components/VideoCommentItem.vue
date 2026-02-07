<template>
    <div class="flex gap-4 py-4" :class="{'border-b border-gray-100': !isReply}">
        <div class="flex-shrink-0 cursor-pointer">
            <img :src="comment.avatar" class="w-10 h-10 rounded-full border border-gray-100 shadow-sm hover:opacity-80 transition-opacity"
                loading="lazy" referrerpolicy="no-referrer" />
        </div>

        <div class="flex-grow min-w-0">
            <div class="flex items-center gap-2 mb-1">
                <span class="text-sm font-bold" :class="isUpper ? 'text-pink-500' : 'text-gray-600'">
                    {{ comment.uname }}
                </span>
                <span v-if="isUpper" class="px-1 py-0.5 text-[10px] leading-none text-pink-500 border border-pink-200 bg-pink-50 rounded">UP</span>
                <span v-if="comment.is_top" class="px-1 py-0.5 text-[10px] leading-none text-white bg-pink-500 rounded">置顶</span>
            </div>

            <div class="text-[15px] text-gray-800 leading-7 break-words whitespace-pre-wrap select-text video-comment-content" v-html="parsedContent"></div>

            <div v-if="comment.pictures && comment.pictures.length > 0" class="mt-3 pswp-gallery" :id="'gallery-' + comment.rpid">
                <div class="flex gap-2 flex-wrap">
                    <a v-for="(pic, idx) in comment.pictures" 
                    :key="idx" 
                    :href="pic" 
                    :data-pswp-width="getImageWidth(idx)" 
                    :data-pswp-height="getImageHeight(idx)"
                    target="_blank"
                    class="relative group overflow-hidden rounded-lg cursor-zoom-in block bg-gray-100"
                    :style="getLongImageStyle(idx)" 
                    >
                        <img :src="pic" 
                            class="h-full w-full object-cover object-top hover:brightness-95 transition-all" 
                            loading="lazy"
                            @load="onImageLoad($event, idx)"
                        >
                        <div v-if="isLongImage(idx)" class="absolute bottom-1 right-1 bg-black/50 text-white text-[10px] px-1.5 py-0.5 rounded">
                            长图
                        </div>
                    </a>
                </div>
            </div>

            <div class="flex items-center gap-4 text-xs text-gray-400 mt-2 select-none">
                <span>{{ formatCustomTime(comment.ctime) }}</span>
                <div class="flex items-center gap-1 cursor-pointer hover:text-gray-600 transition-colors">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" class="text-gray-400 hover:text-gray-600"><path d="M18.77,11h-4.23l1.52-4.94C16.38,5.03,15.54,4,14.38,4c-0.58,0-1.14,0.24-1.52,0.65L7,11H3v10h4h1h9.43 c1.06,0,1.98-0.67,2.19-1.61l1.34-6C21.23,12.15,20.18,11,18.77,11z M7,20H4v-8h3V20z M19.98,13.17l-1.34,6 C18.54,19.65,18.03,20,17.43,20H8v-8.61l5.6-6.06C13.79,5.12,14.08,5,14.38,5c0.26,0,0.5,0.11,0.63,0.3 c0.07,0.1,0.15,0.26,0.09,0.47l-1.52,4.94L13.18,12h1.35h4.23c0.41,0,0.8,0.17,1.03,0.46C19.92,12.61,20.05,12.86,19.98,13.17z"></path></svg>
                    <span>{{ comment.like }}</span>
                </div>
            </div>

            <div v-if="comment.replies && comment.replies.length > 0" class="mt-3 bg-gray-50/80 rounded-lg p-3">
                <div class="space-y-4">
                    <div v-for="reply in displayedReplies" :key="reply.id" class="flex gap-2">
                        <img :src="reply.avatar" class="w-6 h-6 rounded-full flex-shrink-0 mt-0.5 cursor-pointer" referrerpolicy="no-referrer">
                        <div class="flex-grow min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-bold" :class="isReplyUpper(reply.mid) ? 'text-pink-500' : 'text-gray-600'">{{ reply.uname }}</span>
                                <span v-if="isReplyUpper(reply.mid)" class="px-1 text-[10px] leading-none text-pink-500 border border-pink-200 rounded bg-pink-50">UP</span>
                                <span v-if="reply.parent !== comment.rpid" class="text-xs text-gray-500"> 回复 @{{reply.member?.uname ?? '用户'}} :</span>
                            </div>
                            
                            <div class="text-sm text-gray-800 leading-6 break-words whitespace-pre-wrap mt-0.5 video-comment-content" v-html="parseReplyContent(reply)"></div>
                             
                             <div v-if="reply.pictures && reply.pictures.length" class="mt-2 pswp-gallery" :id="'gallery-' + reply.rpid">
                                <div class="flex gap-2 flex-wrap">
                                    <a v-for="(pic, idx) in reply.pictures" 
                                       :key="idx" 
                                       :href="pic"
                                       target="_blank"
                                       class="relative group overflow-hidden rounded cursor-zoom-in block"
                                       :style="{ height: '80px', width: 'auto' }"
                                       :data-pswp-width="getReplyImageWidth(reply.rpid, idx)" 
                                       :data-pswp-height="getReplyImageHeight(reply.rpid, idx)"
                                    >
                                        <img :src="pic" 
                                             class="h-full w-auto object-cover border border-gray-200" 
                                             loading="lazy"
                                             @load="onReplyImageLoad($event, reply.rpid, idx)"
                                        >
                                    </a>
                                </div>
                             </div>

                             <div class="flex items-center gap-4 text-xs text-gray-400 mt-1 select-none">
                                <span>{{ formatCustomTime(reply.ctime) }}</span>
                                <div class="flex items-center gap-1 hover:text-gray-600">
                                    <svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor" class="text-gray-400"><path d="M18.77,11h-4.23l1.52-4.94C16.38,5.03,15.54,4,14.38,4c-0.58,0-1.14,0.24-1.52,0.65L7,11H3v10h4h1h9.43 c1.06,0,1.98-0.67,2.19-1.61l1.34-6C21.23,12.15,20.18,11,18.77,11z M7,20H4v-8h3V20z M19.98,13.17l-1.34,6 C18.54,19.65,18.03,20,17.43,20H8v-8.61l5.6-6.06C13.79,5.12,14.08,5,14.38,5c0.26,0,0.5,0.11,0.63,0.3 c0.07,0.1,0.15,0.26,0.09,0.47l-1.52,4.94L13.18,12h1.35h4.23c0.41,0,0.8,0.17,1.03,0.46C19.92,12.61,20.05,12.86,19.98,13.17z"></path></svg>
                                    <span>{{ reply.like }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="comment.replies.length > 3" class="mt-3 text-xs text-gray-500 select-none">
                    <div v-if="!isExpanded">
                        <span class="cursor-pointer hover:text-blue-500" @click="expandReplies">
                            共{{ comment.replies.length }}条回复，点击查看
                        </span>
                    </div>
                    <div v-else class="flex flex-wrap items-center gap-2">
                        <span>共{{ totalPages }}页</span>
                        <div class="flex gap-1">
                            <span v-for="p in paginationDisplay" :key="p" 
                                  class="px-2 py-0.5 rounded cursor-pointer transition-colors"
                                  :class="p === currentPage ? 'bg-blue-500 text-white' : 'bg-gray-100 hover:bg-gray-200'"
                                  @click="changePage(p)">
                                {{ p }}
                            </span>
                        </div>
                        <span v-if="currentPage < totalPages" class="cursor-pointer hover:text-blue-500 ml-1" @click="changePage(currentPage + 1)">下一页</span>
                        <span class="cursor-pointer hover:text-blue-500 ml-2" @click="collapseReplies">收起</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, ref, onMounted, onUnmounted } from 'vue';
// 引入 PhotoSwipe
import PhotoSwipeLightbox from 'photoswipe/lightbox';
import 'photoswipe/style.css';

// 判断是否为长图 (高宽比 > 2.5)
const isLongImage = (index: number) => {
    const size = imageSizes.value[index];
    if (!size) return false;
    return size.h / size.w > 2.5;
};

// 动态样式：如果是长图，限制显示区域
// 这里简化处理：我们已经统一设置了 height: 120px, object-fit: cover
// 加上 object-position: top 就能自动展示顶部
const getLongImageStyle = (index: number) => {
    // 保持统一高度，让 object-cover 发挥作用
    return { height: '120px', width: '120px' }; 
};

const props = defineProps<{
    comment: any;
    upperId?: number;
    isReply?: boolean;
}>();

const isUpper = computed(() => {
    return props.upperId && Number(props.comment.mid) === Number(props.upperId);
});

const isReplyUpper = (mid: number) => {
    return props.upperId && Number(mid) === Number(props.upperId);
}

// ---------------- 图片处理逻辑 ----------------
// 存储图片尺寸信息，以便 PhotoSwipe 打开时有平滑动画
const imageSizes = ref<Record<number, { w: number, h: number }>>({});
const replyImageSizes = ref<Record<string, { w: number, h: number }>>({});

// 图片加载后获取真实尺寸
const onImageLoad = (event: Event, index: number) => {
    const img = event.target as HTMLImageElement;
    imageSizes.value[index] = { w: img.naturalWidth, h: img.naturalHeight };
};

const onReplyImageLoad = (event: Event, rpid: number, index: number) => {
    const img = event.target as HTMLImageElement;
    const key = `${rpid}-${index}`;
    replyImageSizes.value[key] = { w: img.naturalWidth, h: img.naturalHeight };
};

const getImageWidth = (index: number) => imageSizes.value[index]?.w || 0;
const getImageHeight = (index: number) => imageSizes.value[index]?.h || 0;

const getReplyImageWidth = (rpid: number, index: number) => replyImageSizes.value[`${rpid}-${index}`]?.w || 0;
const getReplyImageHeight = (rpid: number, index: number) => replyImageSizes.value[`${rpid}-${index}`]?.h || 0;

let lightbox: PhotoSwipeLightbox | null = null;
let replyLightboxes: PhotoSwipeLightbox[] = [];

onMounted(() => {
    // 初始化主评论画廊
    if (props.comment.pictures && props.comment.pictures.length > 0) {
        lightbox = new PhotoSwipeLightbox({
            gallery: '#gallery-' + props.comment.rpid,
            children: 'a',
            pswpModule: () => import('photoswipe'),
            bgOpacity: 0.8, // B站风格半透明背景
            showHideOpacity: true, // 淡入淡出
        });
        lightbox.init();
    }
    
    // 初始化子评论画廊 (如果有)
    // 注意：如果是无感滚动加载的，这里只初始化当前已存在的。
    // 如果展开更多子评论，可能需要重新初始化，但这里简化处理。
    if (props.comment.replies) {
         props.comment.replies.forEach((reply: any) => {
             if (reply.pictures && reply.pictures.length > 0) {
                 const lb = new PhotoSwipeLightbox({
                    gallery: '#gallery-' + reply.rpid,
                    children: 'a',
                    pswpModule: () => import('photoswipe')
                });
                lb.init();
                replyLightboxes.push(lb);
             }
         });
    }
});

onUnmounted(() => {
    if (lightbox) {
        lightbox.destroy();
        lightbox = null;
    }
    replyLightboxes.forEach(lb => lb.destroy());
    replyLightboxes = [];
});
// -------------------------------------------

const parseContent = (content: string, emotes: any) => {
    if (!content) return '';
    let parsed = content;
    // 1. 基础HTML转义
    parsed = parsed.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");

    // 超链接解析
    // 匹配 http/https 开头的链接
    const urlRegex = /(https?:\/\/[^\s<]+)/g;
    parsed = parsed.replace(urlRegex, (url) => {
        return `<a href="${url}" target="_blank" class="text-blue-500 hover:underline" rel="noopener noreferrer">${url}</a>`;
    });

    // 2. 表情包解析
    parsed = parsed.replace(/\[(.*?)\]/g, (match, name) => {
        if (emotes && emotes['['+name+']']) {
            const url = emotes['['+name+']'];
            return `<img src="${url}" alt="${name}" class="bili-emoji" loading="lazy">`;
        }
        return match;
    });
    
    return parsed;
};

const parsedContent = computed(() => {
    return parseContent(props.comment.content, props.comment.emotes);
});

const parseReplyContent = (reply: any) => {
    return parseContent(reply.content, reply.emotes);
}

const formatCustomTime = (timeStr: string) => {
    if (!timeStr) return '';
    try {
        const date = new Date(timeStr);
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        return `${year}-${month}-${day} ${hours}:${minutes}`;
    } catch (e) {
        return timeStr.substring(0, 16).replace('T', ' ');
    }
};

// ... 分页逻辑 ...
const isExpanded = ref(false);
const currentPage = ref(1);
const pageSize = 10;
const totalPages = computed(() => Math.ceil(props.comment.replies.length / pageSize));
const displayedReplies = computed(() => {
    if (!isExpanded.value) return props.comment.replies.slice(0, 3);
    const start = (currentPage.value - 1) * pageSize;
    return props.comment.replies.slice(start, start + pageSize);
});
const paginationDisplay = computed(() => {
    let pages = [];
    const total = totalPages.value;
    const current = currentPage.value;
    if (total <= 5) {
        for(let i=1; i<=total; i++) pages.push(i);
    } else {
        if (current <= 3) {
             for(let i=1; i<=4; i++) pages.push(i); pages.push('...'); pages.push(total);
        } else if (current >= total - 2) {
             pages.push(1); pages.push('...'); for(let i=total-3; i<=total; i++) pages.push(i);
        } else {
            pages.push(1); pages.push('...'); pages.push(current-1); pages.push(current); pages.push(current+1); pages.push('...'); pages.push(total);
        }
    }
    return pages.filter(p => typeof p === 'number');
});
const expandReplies = () => { isExpanded.value = true; };
const collapseReplies = () => { isExpanded.value = false; currentPage.value = 1; };
const changePage = (p: any) => { if (typeof p === 'number') currentPage.value = p; };
</script>

<style scoped>
/* 使用 :deep() 穿透 v-html */
:deep(.video-comment-content .bili-emoji) {
    display: inline-block;
    height: 1.25em;       
    width: auto;         
    vertical-align: text-bottom;
    margin: 0 2px;       
    max-width: none !important;
    max-height: none !important;
    border: none;
    border-radius: 0;
}
</style>