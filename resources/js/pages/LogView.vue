<template>
  <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8 h-[calc(100vh-100px)] flex flex-col">
    <div class="flex justify-between items-center mb-4">
      
      <div class="flex items-center space-x-4">
        <h2 class="text-2xl font-bold text-gray-900">系统日志</h2>
        
        <div class="flex bg-gray-200/70 p-1 rounded-lg shadow-inner">
          <button 
            @click="switchLogSource('laravel')"
            :class="['px-4 py-1.5 text-sm font-medium rounded-md transition-all duration-200', 
                     currentLogType === 'laravel' ? 'bg-white text-gray-900 shadow' : 'text-gray-500 hover:text-gray-800']"
          >
            Laravel 业务
          </button>
          <button 
            @click="switchLogSource('supervisor')"
            :class="['px-4 py-1.5 text-sm font-medium rounded-md transition-all duration-200', 
                     currentLogType === 'supervisor' ? 'bg-white text-gray-900 shadow' : 'text-gray-500 hover:text-gray-800']"
          >
            守护进程
          </button>
        </div>
      </div>

      <button 
        @click="fetchLogs" 
        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg shadow-sm transition-colors text-sm font-medium"
      >
        手动刷新
      </button>
    </div>
    
    <div 
      ref="logContainer" 
      class="flex-1 bg-[#1e1e1e] text-green-400 font-mono p-4 rounded-xl overflow-y-auto whitespace-pre-wrap text-sm shadow-inner leading-relaxed"
    >
      {{ logs || 'Loading logs...' }}
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, nextTick } from 'vue'
import { getSystemLogs } from '../api/system'

const logs = ref('')
const logContainer = ref<HTMLElement | null>(null)
const currentLogType = ref('laravel')
let pollInterval: any = null
let isUserScrolling = false

const handleScroll = () => {
  if (!logContainer.value) return
  const { scrollTop, scrollHeight, clientHeight } = logContainer.value
  isUserScrolling = scrollHeight - scrollTop - clientHeight > 50
}

// 切换日志源：改为接收点击传参
const switchLogSource = (type: string) => {
  if (currentLogType.value === type) return // 点击当前正在看的，不执行任何操作

  currentLogType.value = type
  logs.value = 'Switching and loading ' + currentLogType.value + ' logs...'
  isUserScrolling = false // 切换时重置滚动锁，自动滚到底
  fetchLogs()
}

const fetchLogs = async () => {
  try {
    const res: any = await getSystemLogs(currentLogType.value)
    logs.value = res.data?.data || res.data || res
    scrollToBottom()
  } catch (error) {
    console.error('获取日志失败', error)
    logs.value = 'Failed to load logs. Please check network or server status.'
  }
}

const scrollToBottom = () => {
  if (isUserScrolling) return 
  
  nextTick(() => {
    if (logContainer.value) {
      logContainer.value.scrollTop = logContainer.value.scrollHeight
    }
  })
}

onMounted(() => {
  fetchLogs()
  logContainer.value?.addEventListener('scroll', handleScroll)
  pollInterval = setInterval(fetchLogs, 5000)
})

onUnmounted(() => {
  logContainer.value?.removeEventListener('scroll', handleScroll)
  if (pollInterval) {
    clearInterval(pollInterval)
  }
})
</script>