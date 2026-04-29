<template>
  <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8 min-h-[calc(100vh-100px)] flex flex-col relative">
    
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
      <div class="flex items-center space-x-0.5">
        <button @click="handleClearLogs" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg shadow-sm transition-colors text-sm font-medium">清空日志</button>
        <button @click="fetchLogs" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg shadow-sm transition-colors text-sm font-medium">手动刷新</button>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-4 p-4 shrink-0">
      <div class="flex items-center mb-3">
        <svg class="w-5 h-5 mr-2 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
        <h3 class="text-base font-bold text-gray-800">Horizon 队列积压探针 <span class="text-xs font-normal text-gray-400 ml-2">(抽样前 100000 条)</span></h3>
      </div>
      <div class="inline-flex flex-wrap gap-1 bg-gray-200/70 p-1 rounded-lg shadow-inner mb-3">
        <button 
          v-for="q in ['default', 'fast', 'slow', 'comments', 'comments-batch', 'transcode']" 
          :key="q" 
          @click="checkQueue(q)" 
          :class="['px-4 py-1.5 text-sm font-medium rounded-md transition-all duration-200', activeQueue === q ? 'bg-white text-gray-900 shadow' : 'text-gray-500 hover:text-gray-800']"
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
      class="flex-1 min-h-[400px] max-h-[500px] bg-[#1e1e1e] text-green-400 font-mono p-4 rounded-xl overflow-y-auto whitespace-pre-wrap text-sm shadow-inner leading-relaxed"
    >
      {{ logs || 'Loading logs...' }}
    </div>

    <div class="mt-4 bg-white rounded-xl shadow-sm border border-gray-100 p-6 shrink-0">
      <h3 class="text-base font-bold text-gray-800 mb-5 border-b border-gray-100 pb-3 flex items-center">
        <svg class="w-5 h-5 mr-2 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
        高级运维工具箱
      </h3>
      
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
        
        <div class="space-y-3">
          <h4 class="text-sm font-semibold text-gray-600 tracking-wide mb-3 flex items-center">
            <span class="w-1.5 h-4 bg-blue-400 rounded-full mr-2"></span> 数据拉取与同步
          </h4>
          
          <button @click="execCmd('sync-fav-list')" class="w-full text-left px-4 py-2.5 text-sm font-medium text-gray-800 bg-blue-200 hover:bg-blue-300 hover:border-blue-300 border border-blue-200 rounded-lg shadow-sm transition-colors flex justify-between items-center">
            <span>同步基础收藏夹元数据</span>
          </button>
          
          <div class="bg-slate-50 p-3 rounded-xl border border-slate-200 text-sm space-y-3 shadow-sm">
            <div class="font-medium text-gray-700 flex items-center">
              同步指定目录视频信息
            </div>
            <div class="flex space-x-2">
              <select v-model="syncType" class="w-1/3 border border-slate-300 rounded-md py-1.5 px-2 text-sm text-gray-700 bg-white focus:ring focus:ring-blue-200">
                <option value="fav">收藏夹</option>
                <option value="sub">订阅</option>
              </select>
              <select v-if="syncType === 'fav'" v-model="syncFavId" class="w-2/3 border border-slate-300 rounded-md py-1.5 px-2 text-sm text-gray-700 bg-white focus:ring focus:ring-blue-200">
                <option v-for="fav in metaFavs" :key="fav.id" :value="fav.id">{{ fav.name }}</option>
              </select>
              <select v-else v-model="syncSubId" class="w-2/3 border border-slate-300 rounded-md py-1.5 px-2 text-sm text-gray-700 bg-white focus:ring focus:ring-blue-200">
                <option v-for="sub in metaSubs" :key="sub.id" :value="sub.id">{{ sub.name }}</option>
              </select>
            </div>
            <label class="flex items-center text-xs text-gray-600">
              <input type="checkbox" v-model="syncPage1" class="mr-1.5 rounded text-blue-500 focus:ring-blue-500" /> 追加仅更新最新一页
            </label>
            <button @click="execSyncTarget" class="w-full text-center px-4 py-2.5 text-sm font-medium text-gray-900 bg-blue-200 hover:bg-blue-300 hover:border-blue-300 border border-blue-200 font-bold rounded-lg shadow-sm transition-colors">
              执行单目录同步
            </button>
          </div>

          <button @click="execCmdConfirm('sync-all-favs', '同步所有收藏夹', '确定全量同步所有收藏夹下的视频？\n由于数据量庞大，此操作可能需要较长时间。')" class="w-full text-left px-4 py-2.5 text-sm font-medium text-gray-800 bg-blue-200 hover:bg-blue-300 hover:border-blue-300 border border-blue-200 rounded-lg shadow-sm transition-colors">
            同步所有收藏夹视频 (耗时操作)
          </button>
          <button @click="execCmdConfirm('sync-all-subs', '同步所有订阅', '确定全量同步所有订阅文件夹？\n由于数据量庞大，此操作可能需要较长时间。')" class="w-full text-left px-4 py-2.5 text-sm font-medium text-gray-800 bg-blue-200 hover:bg-blue-300 hover:border-blue-300 border border-blue-200 rounded-lg shadow-sm transition-colors">
            同步所有订阅视频 (耗时操作)
          </button>
        </div>

        <div class="space-y-3">
          <h4 class="text-sm font-semibold text-gray-600 tracking-wide mb-3 flex items-center">
            <span class="w-1.5 h-4 bg-emerald-400 rounded-full mr-2"></span> 图片缺失与详情更新
          </h4>
          
          <div class="grid grid-cols-2 gap-3">
            <button @click="execCmd('scan-cover', { target: 'video' })" class="w-full text-center px-1 py-2.5 text-sm font-medium text-gray-800 bg-emerald-200 hover:bg-emerald-300 hover:border-emerald-300 border border-emerald-200 rounded-lg shadow-sm transition-colors">缺失视频封面修复</button>
            <button @click="execCmd('scan-cover', { target: 'favorite' })" class="w-full text-center px-1 py-2.5 text-sm font-medium text-gray-800 bg-emerald-200 hover:bg-emerald-300 hover:border-emerald-300 border border-emerald-200 rounded-lg shadow-sm transition-colors">缺失收藏夹封面修复</button>
            <button @click="execCmd('scan-cover', { target: 'subscription' })" class="w-full text-center px-1 py-2.5 text-sm font-medium text-gray-800 bg-emerald-200 hover:bg-emerald-300 hover:border-emerald-300 border border-emerald-200 rounded-lg shadow-sm transition-colors">缺失订阅封面修复</button>
            <button @click="execCmd('scan-cover', { target: 'upper' })" class="w-full text-center px-1 py-2.5 text-sm font-medium text-gray-800 bg-emerald-200 hover:bg-emerald-300 hover:border-emerald-300 border border-emerald-200 rounded-lg shadow-sm transition-colors">缺失UP主头像修复</button>
          </div>

          <button @click="execCmd('download-comments')" class="w-full text-left px-4 py-2.5 text-sm font-medium text-gray-800 bg-emerald-200 hover:bg-emerald-300 hover:border-emerald-300 border border-emerald-200 rounded-lg shadow-sm transition-colors">
            全量视频评论下载
          </button>
          <button @click="execCmd('download-tags')" class="w-full text-left px-4 py-2.5 text-sm font-medium text-gray-800 bg-emerald-200 hover:bg-emerald-300 hover:border-emerald-300 border border-emerald-200 rounded-lg shadow-sm transition-colors">
            全量视频标签下载
          </button>
          <button @click="execCmd('download-danmaku')" class="w-full text-left px-4 py-2.5 text-sm font-medium text-gray-800 bg-emerald-200 hover:bg-emerald-300 hover:border-emerald-300 border border-emerald-200 rounded-lg shadow-sm transition-colors">
            全量视频弹幕下载
          </button>
          <button @click="execCmd('update-stats')" class="w-full text-left px-4 py-2.5 text-sm font-medium text-gray-800 bg-emerald-200 hover:bg-emerald-300 hover:border-emerald-300 border border-emerald-200 rounded-lg shadow-sm transition-colors">
            全量视频统计信息下载(播放量/时长)
          </button>
        </div>

        <div class="space-y-3">
          <h4 class="text-sm font-semibold text-gray-600 tracking-wide mb-3 flex items-center">
            <span class="w-1.5 h-4 bg-orange-400 rounded-full mr-2"></span> 系统维护与清理
          </h4>
          
          <button @click="execCmdConfirm('make-readable', '全量生成可读文件名', '确定为系统中所有视频重新生成可读文件名？\n此操作将对硬盘进行全量扫盘！')" class="w-full text-left px-4 py-2.5 text-sm font-medium text-gray-800 bg-orange-200 hover:bg-orange-300 hover:border-orange-300 border border-orange-200 rounded-lg shadow-sm transition-colors">
            全量视频可读文件名生成(扫盘)
          </button>
          
          <button @click="execCmdConfirm('transcode-all', '全量视频转码', '确定执行手动全量视频转码？\n高 CPU 消耗，可能会占用设备极多性能！')" class="w-full text-left px-4 py-2.5 text-sm font-medium text-gray-800 bg-orange-200 hover:bg-orange-300 hover:border-orange-300 border border-orange-200 rounded-lg shadow-sm transition-colors">
            全量视频转码
          </button>

          <button @click="execCmd('calc-stats')" class="w-full text-left px-4 py-2.5 text-sm font-medium text-gray-800 bg-orange-200 hover:bg-orange-300 hover:border-orange-300 border border-orange-200 rounded-lg shadow-sm transition-colors">
            重算系统信息页缓存数据
          </button>

          <div class="bg-slate-50 p-3 rounded-xl border border-slate-200 text-sm space-y-3 shadow-sm mt-4">
            <div class="font-medium text-gray-700 flex items-center">
              清空积压 Job 任务
            </div>
            <select v-model="clearQueueName" class="w-full border border-slate-300 rounded-md py-1.5 px-2 text-sm text-gray-700 bg-white focus:ring focus:ring-orange-200">
              <option v-for="q in metaQueues" :key="q" :value="q">清空 {{ q }} 队列</option>
              <option value="all">强制清空所有队列 (Flush)</option>
            </select>
            <button @click="execClearQueue" class="w-full text-center px-4 py-2.5 text-sm font-medium text-gray-900 font-bold bg-orange-300 hover:bg-orange-400 hover:border-orange-400 border border-orange-300 rounded-lg shadow-sm transition-colors">
              强制清理队列积压
            </button>
          </div>
          
        </div>
      </div>
    </div>

    <div v-if="isModalOpen" class="fixed inset-0 z-[100] flex items-center justify-center bg-gray-900/50 backdrop-blur-sm transition-opacity" @click.self="cancelModal">
      <div class="relative w-full max-w-md p-4 animate-fade-in-up">
        <div class="relative bg-white rounded-xl shadow-2xl overflow-hidden">
          <div class="p-6 text-center">
            <svg class="mx-auto mb-4 text-red-500 w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
            <h3 class="mb-2 text-xl font-bold text-gray-800">{{ modalTitle }}</h3>
            <p class="mb-6 text-sm text-gray-500 whitespace-pre-line leading-relaxed">{{ modalMessage }}</p>
            <div class="flex justify-center gap-3">
              <button @click="cancelModal" class="px-5 py-2.5 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 focus:outline-none transition-colors">
                取消操作
              </button>
              <button @click="confirmModal" class="px-5 py-2.5 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 focus:outline-none transition-colors shadow-sm">
                确认执行
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <transition
        enter-active-class="transition duration-300 ease-out transform"
        enter-from-class="translate-y-[-1rem] opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-200 ease-in transform"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="translate-y-[-1rem] opacity-0"
    >
        <div v-if="showToast" class="fixed top-6 right-6 z-[60] bg-white border border-green-200 rounded-lg shadow-xl p-4 w-80 overflow-hidden pointer-events-auto">
            <div class="flex items-start space-x-3 relative z-10">
                <div class="flex-shrink-0 text-green-500 mt-0.5">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
                <div class="flex-1">
                    <h4 class="text-sm font-bold text-gray-800">信息提示</h4>
                    <p class="text-sm text-gray-600 mt-1 whitespace-pre-line">{{ toastMsg }}</p>
                </div>
            </div>
            <div class="absolute bottom-0 left-0 h-1 bg-green-500 animate-shrink" style="width: 100%; animation-duration: 8s;"></div>
        </div>
    </transition>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, nextTick, watch } from 'vue'
import { getSystemLogs, getQueueStats, clearSystemLogs } from '../api/system'

const logs = ref('')
const logContainer = ref<HTMLElement | null>(null)
const currentLogType = ref('laravel')
let pollInterval: any = null
let isUserScrolling = false

// 【新增】队列统计的响应式状态
const queueStats = ref<any[] | null>(null)
const isQueryingQueue = ref(false)
const activeQueue = ref('default') // 将初始值设为 'default'

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

// 清空日志的处理函数
const handleClearLogs = async () => {
  try {
    await clearSystemLogs(currentLogType.value)
    logs.value = '日志已清空...'
    // 清空后稍微延迟一下再拉取，确保后端文件写入完成
    setTimeout(() => {
      fetchLogs()
    }, 500)
  } catch (error) {
    console.error('清空日志失败', error)
    alert('清空日志失败，请查看控制台')
  }
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

// =========== 高级工具箱状态 ===========
const syncType = ref('fav')
const syncFavId = ref('')
const syncSubId = ref('')
const syncPage1 = ref(false)
const clearQueueName = ref('slow')

const metaFavs = ref<any[]>([])
const metaSubs = ref<any[]>([])
const metaQueues = ref<string[]>([])

// =========== 统一规范的 Toast 状态 ===========
const showToast = ref(false)
const toastMsg = ref('')

// =========== 二次弹窗逻辑 ===========
const isModalOpen = ref(false)
const modalTitle = ref('')
const modalMessage = ref('')
const pendingAction = ref<(() => void) | null>(null)

const openConfirmModal = (title: string, msg: string, action: () => void) => {
  modalTitle.value = title
  modalMessage.value = msg
  pendingAction.value = action
  isModalOpen.value = true
}

const confirmModal = () => {
  if (pendingAction.value) pendingAction.value()
  isModalOpen.value = false
}

const cancelModal = () => {
  isModalOpen.value = false
  pendingAction.value = null
}

// =========== API 通信 ===========
const fetchMetadata = async () => {
  try {
    const res = await fetch('/api/system/maintenance/metadata')
    const json = await res.json()
    if (json.success) {
      metaFavs.value = json.data.favs
      metaSubs.value = json.data.subs
      metaQueues.value = json.data.queues
    }
  } catch (e) {
    console.error('Failed to load maintenance metadata', e)
  }
}

watch(metaFavs, (val) => { if(val.length > 0 && !syncFavId.value) syncFavId.value = val[0].id })
watch(metaSubs, (val) => { if(val.length > 0 && !syncSubId.value) syncSubId.value = val[0].id })

const execCmd = async (command: string, params: any = {}) => {
  // 1. 触发统一样式的右上角悬浮通知栏
  toastMsg.value = `任务 [${command}] 已下发...\n若是全量转码/同步等耗时任务，可能会遇到请求超时，请直接观察上方日志进展！`;
  showToast.value = true;

  // 5秒后自动隐藏通知栏
  setTimeout(() => { showToast.value = false; }, 5000);

  try {
    const res = await fetch('/api/system/maintenance/run-command', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({ command, ...params })
    });
    
    const data = await res.json();
    if (data.success) {
       console.log('命令派发成功', data.message);
    } else {
       alert('执行失败: ' + data.message);
    }
    setTimeout(() => { fetchLogs() }, 1000);
  } catch (e) {
    console.error('Fetch caught an error:', e);
    console.warn('请求已发出，如果数据量庞大将在后台持续执行，请查看日志进展。');
  }
}

// 封装后的确认执行
const execCmdConfirm = (command: string, title: string, msg: string, params: any = {}) => {
  openConfirmModal(title, msg, () => {
    execCmd(command, params)
  })
}

const execSyncTarget = () => {
  const targetId = syncType.value === 'fav' ? syncFavId.value : syncSubId.value;
  if (!targetId) return alert('请先选择目标目录！');
  
  execCmd('sync-target', { 
    type: syncType.value, 
    favId: targetId, 
    page1: syncPage1.value 
  });
}

const execClearQueue = () => {
  const qName = clearQueueName.value === 'all' ? '所有' : clearQueueName.value;
  openConfirmModal('强制清理队列', `⚠️ 危险操作：\n确认清空 ${qName} 队列中积压的任务吗？\n清空后未执行的任务将永久丢失！`, () => {
    execCmd('clear-queue', { queue: clearQueueName.value });
  })
}

// =========== 原有的生命周期钩子 ===========
onMounted(() => {
  fetchLogs()
  fetchMetadata()
  
  // 【新增】进入页面时自动执行一次 default 队列的扫描
  checkQueue('default')

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

<style scoped>
/* 简单的淡入淡出动画，让弹窗更顺滑优雅 */
.animate-fade-in-up {
  animation: fadeInUp 0.2s ease-out forwards;
}

@keyframes fadeInUp {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}

/* Toast 底部进度条随时间缩短的动画 */
.animate-shrink {
  animation-name: shrink;
  animation-timing-function: linear;
  animation-fill-mode: forwards;
}

@keyframes shrink {
  from { width: 100%; }
  to { width: 0%; }
}
</style>