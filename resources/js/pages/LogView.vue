<template>
  <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8 h-[calc(100vh-100px)] flex flex-col">
    <div class="flex justify-between items-center mb-4">
      
      <div class="flex items-center space-x-4">
        <h2 class="text-2xl font-bold text-gray-900">系统日志</h2>
        <div class="flex bg-gray-200/70 p-1 rounded-lg shadow-inner">
          <button 
            @click="switchLogSource('laravel')"
            :class="['px-4 py-1.5 text-sm font-medium rounded-md transition-all duration-200', currentLogType === 'laravel' ? 'bg-white text-gray-900 shadow' : 'text-gray-500 hover:text-gray-800']"
          >Laravel 业务</button>
          <button 
            @click="switchLogSource('supervisor')"
            :class="['px-4 py-1.5 text-sm font-medium rounded-md transition-all duration-200', currentLogType === 'supervisor' ? 'bg-white text-gray-900 shadow' : 'text-gray-500 hover:text-gray-800']"
          >守护进程</button>
        </div>
      </div>

      <button @click="fetchLogs" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg shadow-sm transition-colors text-sm font-medium">手动刷新</button>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-4 p-4 shrink-0">
      <div class="flex items-center mb-3">
        <svg class="w-5 h-5 mr-2 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
        <h3 class="text-base font-bold text-gray-800">Horizon 队列积压探针 <span class="text-xs font-normal text-gray-400 ml-2">(抽样前 100000 条)</span></h3>
      </div>
      
      <div class="flex flex-wrap gap-2 mb-3">
        <button 
          v-for="q in ['default', 'fast', 'slow', 'comments', 'transcode', 'bilibili-rate-limit']" 
          :key="q"
          @click="checkQueue(q)"
          :class="['px-3 py-1 text-sm font-medium rounded-lg transition-colors border', activeQueue === q ? 'bg-blue-50 border-blue-200 text-blue-700' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50']"
        >
          {{ q }}
        </button>
      </div>

      <div v-if="isQueryingQueue" class="text-sm text-gray-500 flex items-center p-2">
        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-blue-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        正在扫描队列...
      </div>
      <div v-else-if="queueStats !== null" class="bg-gray-50 rounded-lg p-3 border border-gray-100">
        <div v-if="queueStats.length === 0" class="text-green-600 font-medium text-sm flex items-center">
          <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
          当前队列极其健康，无任何积压任务！🎉
        </div>
        <div v-else class="flex flex-wrap gap-3">
          <div v-for="stat in queueStats" :key="stat.name" class="inline-flex items-center bg-white border border-gray-200 rounded-lg px-3 py-1 shadow-sm">
            <span class="text-sm font-semibold text-gray-700 mr-2">{{ stat.name }}</span>
            <span class="bg-blue-100 text-blue-700 py-0.5 px-2 rounded-md text-xs font-bold">{{ stat.count }}</span>
          </div>
        </div>
      </div>
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
import { getSystemLogs, getQueueStats } from '../api/system' // 引入探针API

const logs = ref('')
const logContainer = ref<HTMLElement | null>(null)
const currentLogType = ref('laravel')
let pollInterval: any = null
let isUserScrolling = false

// 【新增】队列统计的响应式状态
const queueStats = ref<any[] | null>(null)
const isQueryingQueue = ref(false)
const activeQueue = ref('')

const handleScroll = () => {
  if (!logContainer.value) return
  const { scrollTop, scrollHeight, clientHeight } = logContainer.value
  isUserScrolling = scrollHeight - scrollTop - clientHeight > 50
}

const switchLogSource = (type: string) => {
  if (currentLogType.value === type) return
  currentLogType.value = type
  logs.value = 'Switching and loading ' + currentLogType.value + ' logs...'
  isUserScrolling = false
  fetchLogs()
}

// 【新增】执行队列探针查询
const checkQueue = async (queue: string) => {
  activeQueue.value = queue
  isQueryingQueue.value = true
  try {
    const res: any = await getQueueStats(queue)
    queueStats.value = res.data?.data || res.data || res
  } catch (error) {
    console.error('查询队列失败', error)
    queueStats.value = []
  } finally {
    isQueryingQueue.value = false
  }
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