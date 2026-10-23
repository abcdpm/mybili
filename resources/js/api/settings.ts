export const getSettings = () => {
    return fetch('/api/settings').then((res) => res.json());
};

/** 定时任务注册表元数据（任务名/中文说明/默认频率/当前配置） */
export const getScheduledTasks = () => {
    return fetch('/api/system/scheduled-tasks').then((res) => res.json());
};

export const saveSettings = async (settings: any) => {
    const res = await fetch('/api/settings', {
        method: 'POST',
        headers: {
            // 必须声明 JSON 类型，否则 Laravel 端无法解析请求体（会导致校验字段全部缺失）
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(settings),
    })
    if (!res.ok) {
        const data = await res.json();
        console.error(data);
        // 422 校验错误：把字段级错误明细挂到 Error 上，便于界面展示可读信息
        const err: any = new Error(data.message ?? 'Failed to save settings');
        err.errors = data.errors;
        throw err;
    }
    return await res.json();
};

export const testTelegramConnection = (botToken: string, chatId: string, botUrl?: string) => {
    return fetch('/api/settings/test-telegram', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            telegram_bot_token: botToken,
            telegram_chat_id: chatId,
            telegram_bot_api_url: botUrl,
        }),
    }).then((res) => res.json());
};

/** 邮件推送测试发送（第22项）：使用已保存的 SMTP 配置发送测试邮件 */
export const testMailConnection = () => {
    return fetch('/api/settings/test-mail', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({}),
    }).then((res) => res.json());
};
