import type { Video } from "./fav";

export interface VideoListResponse {
    stat: {
        count: number;
        downloaded: number;
        invalid: number;
        valid: number;
        frozen: number;
    };
    list: Video[];
}

export interface VideoListParams {
    query?: string;
    page?: number;
    status?: string;
    downloaded?: string;
    multi_part?: string;
    fav_id?: string;
}

export async function getVideoList(data: VideoListParams): Promise<VideoListResponse> {
    // 过滤掉空值，然后转换为 URL 查询字符串
    const filteredData = Object.fromEntries(
        Object.entries(data).filter(([_, v]) => v != null && v !== '')
    );
    const params = new URLSearchParams(filteredData as Record<string, string>);
    const url = `/api/videos${params.toString() ? '?' + params.toString() : ''}`;

    const response = await fetch(url, {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
        },
    });
    return response.json();
}

export async function deleteVideo(id: number, extend_ids?: number[]): Promise<boolean> {
    const response = await fetch(`/api/videos/${id}`, {
        method: 'DELETE',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ extend_ids: extend_ids }),
    });
    return response.json();
}

export async function getVideoDanmaku(id: number): Promise<any[]> {
    const response = await fetch(`/api/danmaku?id=${id}`, {
        method: 'GET',
    });
    const data = await response.json()
    return data.data;
}

export async function getVideoInfo(id: number): Promise<Video> {
    const response = await fetch(`/api/videos/${id}`, {
        method: 'GET',
    });
    return response.json();
}

// 请求视频标签的接口
export async function getVideoTags(id: number): Promise<any[]> {
    const response = await fetch(`/api/videos/${id}/tags`, {
        method: 'GET',
    });
    const data = await response.json();
    return data.data || [];
}

// 手动触发更新弹幕
export async function triggerUpdateDanmaku(id: number): Promise<any> {
    const response = await fetch(`/api/videos/${id}/update-danmaku`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
    });
    return response.json();
}

// 手动触发更新评论
export async function triggerUpdateComments(id: number): Promise<any> {
    const response = await fetch(`/api/videos/${id}/update-comments`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
    });
    return response.json();
}

// 手动触发更新播放量等数据
export async function triggerUpdateStats(id: number): Promise<any> {
    const response = await fetch(`/api/videos/${id}/update-stats`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
    });
    return response.json();
}