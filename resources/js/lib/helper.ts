export const image = (path: string) => {
    return `/storage/images/${path}`
}

export const formatTimestamp = (timestamp: number, format: string) => {
    const date = new Date(timestamp * 1000);

    const map = {
        'yyyy': date.getFullYear(),
        'mm': String(date.getMonth() + 1).padStart(2, '0'),
        'dd': String(date.getDate()).padStart(2, '0'),
        'hh': String(date.getHours()).padStart(2, '0'),
        'ii': String(date.getMinutes()).padStart(2, '0'),
        'ss': String(date.getSeconds()).padStart(2, '0'),
    };

    return format.replace(/yyyy|mm|dd|hh|ii|ss/g, matched => map[matched]);
}


export const getLocale = () => {
    const locale = localStorage.getItem('locale');
    if (locale && ['zh-CN', 'en-US'].includes(locale)) {
        return locale;
    }
    return navigator.language;
}


export const formatViewCount = (count: number): string => {
    if (!count) return '0';
    if (count >= 10000) {
        return (count / 10000).toFixed(1).replace(/\.0$/, '') + '万';
    }
    return count.toString();
}

export const formatDuration = (seconds: number): string => {
    if (!seconds) return '00:00';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.floor(seconds % 60);

    const pad = (num: number) => num.toString().padStart(2, '0');

    if (h > 0) {
        return `${h}:${pad(m)}:${pad(s)}`;
    }
    return `${pad(m)}:${pad(s)}`;
}