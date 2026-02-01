<template>
    <div id="artplayer" ref="$container" class="w-full md:flex-1 artplayer-app"></div>
</template>
<script lang="ts" setup>
import { onMounted, ref, shallowRef, onBeforeUnmount } from 'vue';
import Artplayer from 'artplayer'
import artplayerPluginDanmuku, { type Option } from 'artplayer-plugin-danmuku';
import { type Option as DanmakuOption } from  'artplayer-plugin-danmuku';

const DANMAKU_CONFIG_KEY = 'mybili_danmaku_config'

const art = shallowRef<Artplayer | null>(null)
const $container = ref<HTMLDivElement | null>(null)

// 定义事件
const emit = defineEmits<{
    ready: []
}>()

const props = defineProps<{
    danmaku: any[]
    url: string
    mobileUrl?: string // 【新增】接收移动端地址
}>()

/**
 * 从 localStorage 读取弹幕配置
 */
const loadDanmakuConfig = (): Partial<Option> | null => {
    try {
        const saved = localStorage.getItem(DANMAKU_CONFIG_KEY)
        if (saved) {
            return JSON.parse(saved)
        }
    } catch (error) {
        console.error('读取弹幕配置失败:', error)
    }
    return null
}

/**
 * 保存弹幕配置到 localStorage
 * 只保存预设中的 key
 */
const saveDanmakuConfig = (config: DanmakuOption) => {
    try {
        const configToSave: Partial<Option> = {
            speed: config.speed,
            antiOverlap: config.antiOverlap,
            synchronousPlayback: config.synchronousPlayback,
            fontSize: config.fontSize,
            theme: config.theme,
            margin: config.margin,
            modes: config.modes,
        }
        localStorage.setItem(DANMAKU_CONFIG_KEY, JSON.stringify(configToSave))
        console.log('弹幕配置已保存:', configToSave)
    } catch (error) {
        console.error('保存弹幕配置失败:', error)
    }
}

const switchVideo = (param: { url: string, mobileUrl?: string, danmaku: any[] }) => {
    if (art.value) {
        (art.value.plugins as any).artplayerPluginDanmuku.config({
            danmuku: param.danmaku
        });
        (art.value.plugins as any).artplayerPluginDanmuku.load()

        // 【修改】使用同样的通用判断
        const isSmallScreen = document.documentElement.clientWidth < 768
        const isMobileDevice = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) 
                               || (navigator.userAgent.includes('Mac') && navigator.maxTouchPoints > 0);
        
        const useCompatibleSource = isSmallScreen || isMobileDevice
        
        let targetUrl = param.url
        if (useCompatibleSource && param.mobileUrl) {
            targetUrl = param.mobileUrl
        }
        
        art.value.url = targetUrl
        art.value.play()
    }
}

onMounted(async () => {
    // 1. 布局判断：仅用于 UI (是否显示大字体等)
    const isSmallScreen = document.documentElement.clientWidth < 768

    // 2. 兼容性判断：核心逻辑
    // 检测是否为移动端设备 (包含 Android, iPhone, iPad, Mac触屏版)
    const isMobileDevice = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) 
                           || (navigator.userAgent.includes('Mac') && navigator.maxTouchPoints > 0); // iPadOS 13+ 伪装成 Mac
    
    // 只要是小屏，或者被识别为移动设备(Android/iOS)，就强制使用兼容源
    const useCompatibleSource = isSmallScreen || isMobileDevice

    // 3. 自动播放策略：移动端声音为1 PC端声音为0.5
    const volume = useCompatibleSource ? 1 : 0.5 

    // 平板(iPad/Android Tablet)保留网页全屏功能，只有手机禁用
    const fullscreenWeb = isSmallScreen ? false : true

    // 默认配置
    const defaultDanmakuOption = {
        speed: isSmallScreen ? 4 : 7.5,
        antiOverlap: true,
        synchronousPlayback: false,
        fontSize: isSmallScreen ? 14 : 25,
        theme: "light",
        margin: isSmallScreen ? [10, '75%'] as [number | `${number}%`, number | `${number}%`] : [10, 10] as [number | `${number}%`, number | `${number}%`],
        modes: [0, 1, 2],
    } as Option

    // 读取已保存的配置
    const savedConfig = loadDanmakuConfig()
    
    // 合并配置：优先使用已保存的配置，其次使用默认配置
    const presetDanmakuOption = {
        ...defaultDanmakuOption,
        ...savedConfig,
        danmuku: props.danmaku, // 弹幕数据始终使用 props
    } as Option

    console.log('使用的弹幕配置:', presetDanmakuOption)

    const plugins: any[] = [
        artplayerPluginDanmuku(presetDanmakuOption),
    ]

    // 【新增】智能选源逻辑
    let playUrl = props.url
    if (useCompatibleSource && props.mobileUrl) {
        console.log('Mobile/Tablet device detected, using compatible video source.');
        playUrl = props.mobileUrl
    }

    art.value = new Artplayer({
        container: $container.value as HTMLDivElement,
        fullscreen: true,
        fullscreenWeb: fullscreenWeb, // 大屏平板可以使用网页全屏
        autoOrientation: true,
        url: playUrl,
        setting: true,
        volume: volume,
        flip: true,
        playbackRate: true,
        theme: "#e749a0",
        miniProgressBar: true,
        plugins: plugins
    })
    // 监听弹幕配置变化并保存
    art.value?.on('artplayerPluginDanmuku:config', (...args: unknown[]) => {
        const option = args[0] as DanmakuOption
        console.info('弹幕配置变化:', option);
        saveDanmakuConfig(option)
    });
    
    emit('ready')
})
onBeforeUnmount(() => {
    art.value?.destroy(false)
})
defineExpose({
    switchVideo,
})
</script>
<style scoped>
.artplayer-app {
    width: 100%;
    height: 600px;
    position: relative;
    overflow: hidden;
}

@media (max-width: 768px) {
    .artplayer-app {
        height: 300px;
    }
}
</style>