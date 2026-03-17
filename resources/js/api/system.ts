export const getSystemInfo = () => {
    return fetch('/api/system/info').then((res) => res.json());
};

// 获取系统日志
export const getSystemLogs = (type: string = 'laravel') => {
  return fetch(`/api/system/logs?type=${type}`).then((res) => res.json());
}