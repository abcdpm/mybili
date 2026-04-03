export const getSystemInfo = () => {
    return fetch('/api/system/info').then((res) => res.json());
};

// 获取系统日志
export const getSystemLogs = (type: string = 'laravel') => {
  return fetch(`/api/system/logs?type=${type}`).then((res) => res.json());
}

// 清空系统日志
export const clearSystemLogs = (type: string = 'laravel') => {
  return fetch(`/api/system/logs/clear`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    },
    body: JSON.stringify({ type })
  }).then((res) => res.json());
}

// 获取队列积压统计
export const getQueueStats = (queue: string = 'default') => {
  return fetch(`/api/system/queue-stats?queue=${queue}`).then((res) => res.json());
}

export const getMediaUsage = () => {
    return fetch('/api/system/media-usage').then((res) => res.json());
};
