<?php

namespace App\Services\Bilibili;

use App\Enums\SettingKey;
use App\Exceptions\ApiGetVideoStatusException;
use App\Services\SettingsService;
use Arr;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Utils;
use Log;
use Psr\Http\Message\ResponseInterface;

/**
 * 由 BilibiliService 按领域拆分而来，方法体逐字保留，行为与拆分前完全一致。
 */
class VideoClient extends BilibiliClient
{
    // 继承 BilibiliClient：getClient/getAppClient/appSign/getWbiKeys/encWbi、常量与构造函数

    
    // 已弃用该方法，使用getVideoPartFromWbiApi替代，因为直接爬取网页的方式太过于脆弱，而通过WBI接口获取更稳定可靠
    private function getVideoPartFromWebpage(string $bvid)
    {
        $cookies  = parse_netscape_cookie_content($this->settingsService->get(SettingKey::COOKIES_CONTENT));
        $client   = $this->getClient();
        $url      = self::WEB_HOST . "/video/{$bvid}";
        $response = $client->request('GET', $url, [
            'cookies' => $cookies,
        ]);
        $content = $response->getBody()->getContents();

        if (preg_match('/"pages":\s*(\[.*?\])/', $content, $matches)) {
            $pages = json_decode($matches[1], true);
            if ($pages === null) {
                throw new \Exception("JSON 解析失败：" . json_last_error_msg());
            }
            return $pages;
        }
        throw new \Exception("未找到视频分P信息");
    }


    private function getVideoPartFromWbiApi(string $bvid)
    {
        $cookies = parse_netscape_cookie_content($this->settingsService->get(SettingKey::COOKIES_CONTENT));
        $client  = $this->getClient();

        // 1. 获取 WBI Keys (复用现有的方法)
        $keys = $this->getWbiKeys($client, $cookies);
        if (!$keys) {
            throw new \Exception("获取 WBI keys 失败，无法请求分P数据");
        }
        list($imgKey, $subKey) = $keys;

        // 2. 构造参数
        $params = [
            'wts' => time()
        ];
        
        // 兼容传入的是 BV 还是 AV 格式
        if (str_starts_with(strtolower($bvid), 'bv')) {
            $params['bvid'] = $bvid;
        } else {
            $params['aid'] = str_ireplace('av', '', strtolower($bvid));
        }

        // 3. 生成 WBI 签名
        $query = $this->encWbi($params, $imgKey, $subKey);

        // 4. 请求带有 WBI 签名的接口 (替代直接爬取网页HTML)
        $url = self::API_HOST . "/x/web-interface/wbi/view?" . $query;
        
        $response = $client->request('GET', $url, [
            'cookies' => $cookies,
        ]);
        
        $result = json_decode($response->getBody()->getContents(), true);

        if (($result['code'] ?? -1) !== 0) {
            throw new \Exception("WBI 接口请求失败：" . ($result['message'] ?? '未知错误') . " (Code: " . ($result['code'] ?? 'null') . ")");
        }

        if (isset($result['data']['pages']) && is_array($result['data']['pages'])) {
            return $result['data']['pages'];
        }
        
        throw new \Exception("未找到视频分P信息");
    }


    private function getVideoPartFromApi(string $id)
    {
        $info = $this->getVideoInfo($id);
        if ($info && isset($info['pages']) && is_array($info['pages'])) {
            return $info['pages'];
        }
        return null;
    }


    /**
     *  获取视频分P信息
     * 兼容av,bv
     */
    public function getVideoParts(string $strId)
    {
        $parsedParts = null;
        try {
            // api 接口太敏感，优先从网页获取
            if (str_starts_with(strtolower($strId), 'bv')) {
                $bvid        = $strId;
                // $parsedParts = $this->getVideoPartFromWebpage($bvid);
                $parsedParts = $this->getVideoPartFromWbiApi($bvid);
            } else {
                // $parsedParts = $this->getVideoPartFromWebpage('av' . $strId);
                $parsedParts = $this->getVideoPartFromWbiApi('av' . $strId);
            }
        } catch (\Exception $e) {
            // Log::error("[视频分片] 通过网页获取视频分片信息失败" . $e->getMessage());
            Log::error("[视频分片] 通过 WBI API 获取视频分片信息失败: " . $e->getMessage());
            try {
                $parsedParts = $this->getVideoPartFromApi($strId);
                Log::info("[视频分片] 通过 API 获取视频分片信息成功", [
                    'str_id' => $strId, 
                    'parts_count' => count($parsedParts ?? [])
                ]);
            } catch (\Exception $e) {
                Log::error("[视频分片] 通过 API 获取视频分片信息失败" . $e->getMessage());
                throw new \Exception("[视频分片] 获取视频分片信息失败" . $e->getMessage());
            }
        }

        return array_map(function ($item) {
            return [
                'cid'         => $item['cid'] ?? 0,
                'page'        => $item['page'] ?? 0,
                'from'        => $item['from'] ?? '',
                'part'        => $item['part'] ?? '',
                'duration'    => $item['duration'] ?? 0,
                'vid'         => $item['vid'] ?? '',
                'weblink'     => $item['weblink'] ?? '',
                'dimension'   => $item['dimension'] ?? null,
                'first_frame' => $item['first_frame'] ?? null,
            ];
        }, $parsedParts ?? []);
    }


    public function getVideoInfo(string $strId): array
    {
        try {
            $client = $this->getClient();
            if (str_starts_with(strtolower($strId), 'bv')) {
                $url = self::API_HOST . "/x/web-interface/view?bvid={$strId}";
            } else {
                $url = self::API_HOST . "/x/web-interface/view?aid={$strId}";
            }
            $response = $client->request('GET', $url);
            $result   = json_decode($response->getBody()->getContents(), true);
            if ($result['code'] !== 0) {
                // 判断稿件状态异常
                if (in_array($result['code'], [self::ERROR_CODE_NOT_VISIBLE, self::ERROR_CODE_REVIEWING, self::ERROR_CODE_ONLY_UP_OWNER])) {
                    throw new ApiGetVideoStatusException($result['message'], $result['code']);
                }
                throw new \Exception("[视频管理] 获取视频信息失败" . $result['message'], $result['code']);
            }
            return $result['data'];
        } catch (\Exception $e) {
            Log::error("[视频管理] API 请求失败" . $e->getMessage());
            if (strpos($e->getMessage(), '429') !== false || strpos($e->getMessage(), '412') !== false) {
                Log::warning("[视频管理] 检测到频率限制, 等待60秒后重试");
                $this->bilibiliSuspendService->setSuspend();
            }
            throw $e;
        }
    }


    public function getUperCard(int $mid): array
    {
        $client   = $this->getClient();
        $url      = self::API_HOST . "/x/web-interface/card?mid={$mid}";
        $response = $client->request('GET', $url);
        $result   = json_decode($response->getBody()->getContents(), true);
        if ($result['code'] !== 0) {
            throw new \Exception("[UP主管理] 获取UP主卡片失败" . $result['message'], $result['code']);
        }
        if (isset($result['data']) && isset($result['data']['card'])) {
            return $result['data']['card'];
        }
        return [];
    }


    public function getVideoFestivalJumpUrl(string $strId): ?string
    {
        $data = $this->getVideoInfo($strId);
        if (isset($data['festival_jump_url'])) {
            return $data['festival_jump_url'];
        }
        return null;
    }


    public function getAudioInfo(int $sid): array
    {
        try {
            $client   = $this->getClient();
            $url      = self::WEB_HOST . "/audio/music-service-c/web/song/info?sid={$sid}";
            $response = $client->request('GET', $url);
            $result   = json_decode($response->getBody()->getContents(), true);
            if ($result['code'] !== 0) {
                throw new \Exception("[音频管理] 获取音频信息失败" . $result['message'], $result['code']);
            }
            return $result['data'];
        } catch (\Exception $e) {
            Log::error("[音频管理] API 请求失败" . $e->getMessage());
            if (strpos($e->getMessage(), '429') !== false || strpos($e->getMessage(), '412') !== false) {
                Log::warning("[音频管理] 检测到频率限制, 等待60秒后重试");
                $this->bilibiliSuspendService->setSuspend();
            }
            throw $e;
        }
    }


    public function checkCookieExpired(CookieJar $cookies): bool
    {
        try {
            $client   = new Client();
            $response = $client->request('GET', 'https://api.bilibili.com/x/web-interface/nav', [
                'headers' => [
                    'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                    'referer'    => 'https://space.bilibili.com/',
                ],
                'cookies' => $cookies,
            ]);

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if ($data['data']['isLogin'] === true) {
                return true;
            }
            Log::error("[Cookie] Cookie 过期" . $data['message'] ?? '', ['response' => $response]);
        } catch (\Exception $e) {
            Log::error("[Cookie] 检查 Cookie 过期失败" . $e->getMessage());
            throw $e;
        }
        return false;
    }


    /**
     * 获取视频TAG信息（新接口）
     *
     * @param string $bvid
     * @return array
     */
    public function getVideoTags(string $bvid): array
    {
        try {
            // 1. 获取带有认证信息（SESSDATA）的 Cookie
            $cookies = parse_netscape_cookie_content($this->settingsService->get(SettingKey::COOKIES_CONTENT));
            
            // 2. 获取内置的 Guzzle Client
            $client = $this->getClient();

            // 3. 请求官方 TAG 接口
            $response = $client->get(self::API_HOST . '/x/web-interface/view/detail/tag', [
                'query' => [
                    'bvid' => $bvid
                ],
                'cookies' => $cookies,
                'headers' => [
                    'Referer' => self::WEB_HOST . '/video/' . $bvid
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            // 4. 判断返回结果
            if (isset($data['code']) && $data['code'] === 0) {
                // 如果没有标签，接口可能会返回 null 或空数组，做一下默认值处理
                return is_array($data['data']) ? $data['data'] : [];
            }

            Log::warning('[视频标签] 获取视频标签失败', ['bvid' => $bvid, 'response' => $data]);
        } catch (\Exception $e) {
            Log::error('[视频标签] 请求视频标签异常', ['bvid' => $bvid, 'error' => $e->getMessage()]);
        }

        return [];
    }
}
