/**
 * 子路径部署（nginx 剥前缀，如 https://paccu.cn/mybili/ → 容器根路径）下的
 * 浏览器侧 URL 前缀适配（第 SSO 项·资源 URL 修复）。
 *
 * BASE 由 welcome.blade.php 注入的 window.__MYBILI_BASE__ 提供
 * （服务端 SsoUrl::base()，根部署/未开 SSO 时为空字符串 → 本模块全部
 *  原样透传，行为与旧版完全一致）。
 */
import axios from 'axios';

declare global {
    interface Window {
        __MYBILI_BASE__?: string;
    }
}

const raw = typeof window !== 'undefined' ? (window.__MYBILI_BASE__ ?? '') : '';

/** 子路径前缀：''（根部署）或 '/mybili'（保证不带末尾斜杠） */
export const BASE: string = String(raw).replace(/\/+$/, '');

/**
 * 为站内根相对路径补子路径前缀（幂等）。
 * 跳过：空值、绝对/协议相对 URL（//host、https://）、data: 等非站内地址。
 */
export function withBase(path: string): string {
    if (!path || !BASE) return path;
    // 协议相对 //host 或带 scheme 的绝对地址：外部资源，原样返回
    if (/^(?:[a-z][a-z\d+\-.]*:)?\/\//i.test(path)) return path;
    // data: / blob: 等特殊 scheme
    if (/^[a-z][a-z\d+\-.]*:/i.test(path)) return path;
    // 幂等：已带前缀不重复添加
    if (path === BASE || path.startsWith(BASE + '/')) return path;
    return BASE + (path.startsWith('/') ? path : '/' + path);
}

/**
 * API 地址归一化：绝对地址提取 pathname+search 后再补前缀，
 * 用于后端 paginate 返回的 next_page_url（绝对 URL 在剥前缀部署下会丢前缀）。
 */
export function apiUrl(url: string): string {
    if (!url || !BASE) return url;
    if (/^https?:\/\//i.test(url)) {
        try {
            const u = new URL(url, window.location.origin);
            return withBase(u.pathname + u.search + u.hash);
        } catch {
            // 解析失败按普通地址处理
        }
    }
    return withBase(url);
}

let installed = false;

/**
 * 安装全局 fetch/axios 前缀拦截（main.ts 最先调用，早于一切 API 调用）。
 * 用幂等的 apiUrl 归一化：站内相对/绝对地址统一补前缀，外部 URL 一律不碰；
 * 根部署（BASE 为空）不打任何补丁，零行为变化。
 */
export function installBasePath(): void {
    if (installed) return;
    installed = true;
    if (!BASE) return; // 根部署：零行为变化

    // fetch：string 入参补前缀（项目内所有 fetch 调用均为 string 字面量/模板串）
    const origFetch = window.fetch.bind(window);
    window.fetch = ((input: RequestInfo | URL, init?: RequestInit) => {
        if (typeof input === 'string') {
            input = apiUrl(input);
        }
        return origFetch(input, init);
    }) as typeof window.fetch;

    // axios：拦截器用幂等 apiUrl 归一化。
    // 不用 baseURL——next_page_url 等已补前缀地址会与 baseURL 二次拼接。
    axios.interceptors.request.use((config) => {
        if (config.url) {
            config.url = apiUrl(config.url);
        }
        return config;
    });
}

