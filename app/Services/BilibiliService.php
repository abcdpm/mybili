<?php
namespace App\Services;

use App\Enums\SettingKey;
use App\Services\SettingsService;
use Arr;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Utils;
use Log;
use Psr\Http\Message\ResponseInterface;

class BilibiliService
{

    const API_HOST     = 'https://api.bilibili.com';
    const APP_API_HOST = 'https://app.biliapi.com';
    const APP_KEY      = '1d8b6e7d45233436';
    const APP_SECRET   = '560c52ccd288fed045859ed18bffd973';

    protected $favVideosPageSize;

    public function __construct(
        public SettingsService $settingsService,
        public BilibiliSuspendService $bilibiliSuspendService,
        public CookieControlService $cookieControlService
    ) {
        $this->favVideosPageSize = intval(config('services.bilibili.fav_videos_page_size'));
    }

    private function getClient()
    {
        $cookies = parse_netscape_cookie_content($this->settingsService->get(SettingKey::COOKIES_CONTENT));

        // 创建 HandlerStack 并添加响应状态码检查中间件
        $stack                = HandlerStack::create();
        $cookieControlService = $this->cookieControlService;
        $stack->push(Middleware::mapResponse(function (ResponseInterface $response) use ($cookieControlService) {
            try {
                // 读取响应体内容
                $bodyContent = $response->getBody()->getContents();
                $data        = json_decode($bodyContent, true);

                // 检查是否为 Cookie 过期错误码
                if (isset($data['code']) && intval($data['code']) === -101) {
                    Log::error("Cookie expired: " . ($data['message'] ?? ''));
                    // 通过 CookieControlService 检查并发送通知（内部会检查 Redis 避免重复）
                    $cookieControlService->checkAndNotifyCookieExpired();
                }

                // 重新创建响应对象，因为响应体已被读取
                return $response->withBody(Utils::streamFor($bodyContent));
            } catch (\Exception $e) {
                Log::error("Error checking cookie expired: " . $e->getMessage());
                // 如果出错，尝试恢复响应体（如果可能的话）
                try {
                    $response->getBody()->rewind();
                } catch (\Exception $rewindException) {
                    // 如果 rewind 失败，说明响应体已被读取，需要重新创建
                    // 这种情况下返回原响应，让调用方处理
                }
                return $response;
            }
        }), 'check_cookie_expired');

        return new Client([
            'handler' => $stack,
            'cookies' => $cookies,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3',
                'Referer'    => 'https://www.bilibili.com/',
            ],
        ]);
    }

    private function getAppClient()
    {
        return new Client([
            'headers' => [
                'User-Agent' => 'bili-universal/77100100 CFNetwork/1404.0.5 Darwin/22.3.0',
                'Buvid'      => 'XY' . bin2hex(random_bytes(16)),
                'Display-ID' => 'MAC-' . strtoupper(bin2hex(random_bytes(6))),
                'Device-ID'  => bin2hex(random_bytes(20)),
                'Channel'    => 'AppStore',
                'App-Key'    => 'iphone',
                'Env'        => 'prod',
            ],
        ]);
    }

    public function getDanmaku(int $cid, int $duration)
    {
        $cookies = parse_netscape_cookie_content($this->settingsService->get(SettingKey::COOKIES_CONTENT));
        $client  = $this->getClient();

        // 获取 WBI keys
        $navResponse = $client->request('GET', self::API_HOST . '/x/web-interface/nav', [
            'cookies' => $cookies,
        ]);
        $navData = json_decode($navResponse->getBody()->getContents(), true);

        if (! isset($navData['data']['wbi_img'])) {
            throw new \Exception('无法获取 WBI keys');
        }

        $imgUrl = $navData['data']['wbi_img']['img_url'];
        $subUrl = $navData['data']['wbi_img']['sub_url'];
        $imgKey = substr($imgUrl, strrpos($imgUrl, '/') + 1, -4);
        $subKey = substr($subUrl, strrpos($subUrl, '/') + 1, -4);

        $segmentCount = ceil($duration / 360);
        $danmakus     = [];
        for ($i = 1; $i <= $segmentCount; $i++) {
            // 准备参数
            $params = [
                'type'          => 1,
                'oid'           => $cid,
                'segment_index' => $i,
                'web_location'  => 1315873,
                'wts'           => time(),
            ];

            // 生成 WBI 签名
            $query = $this->encWbi($params, $imgKey, $subKey);

            // 请求弹幕数据
            $url      = self::API_HOST . "/x/v2/dm/wbi/web/seg.so?" . $query;
            $response = $client->request('GET', $url, [
                'cookies' => $cookies,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3',
                    'Referer'    => 'https://www.bilibili.com/',
                ],
            ]);
            $content         = $response->getBody()->getContents();
            $currentDanmakus = $this->parseDanmakuProtobuf($content);
            $danmakus        = array_merge($danmakus, $currentDanmakus);
        }
        return array_map(function ($danmaku) {
            // [
            //     "id" => 1620000000000000000000000000
            //     "progress" => 390379
            //     "mode" => 1
            //     "fontsize" => 25
            //     "color" => 16777215
            //     "midHash" => b"\x010È\x01\x01Ð\x01Æ¡´“\x03Ø\x01\x01"
            //     "content" => "是我"
            //     "ctime" => 1720936142
            //     "weight" => 10
            //     "action" => "1620000000000000000000000000"
            // ]
            return Arr::only($danmaku, ['content', 'mode', 'color', 'progress', 'id']);
        }, $danmakus);
        // 手动解析二进制数据
    }

    private function encWbi($params, $imgKey, $subKey)
    {
        // WBI 签名算法
        $mixinKeyEncTab = [
            46, 47, 18, 2, 53, 8, 23, 32, 15, 50, 10, 31, 58, 3, 45, 35, 27, 43, 5, 49,
            33, 9, 42, 19, 29, 28, 14, 39, 12, 38, 41, 13, 37, 48, 7, 16, 24, 55, 40,
            61, 26, 17, 0, 1, 60, 51, 30, 4, 22, 25, 54, 21, 56, 59, 6, 63, 57, 62, 11,
            36, 20, 34, 44, 52,
        ];

        // 获取混合密钥
        $orig     = $imgKey . $subKey;
        $mixinKey = '';
        foreach ($mixinKeyEncTab as $n) {
            if (isset($orig[$n])) {
                $mixinKey .= $orig[$n];
            }
        }
        $mixinKey = substr($mixinKey, 0, 32);

        // 过滤参数
        $filteredParams = [];
        foreach ($params as $key => $value) {
            $filteredParams[$key] = preg_replace("/[!'()*]/", '', (string) $value);
        }

        // 按键排序
        ksort($filteredParams);

        // 构建查询字符串
        $query = http_build_query($filteredParams);

        // 计算 MD5
        $wbiSign = md5($query . $mixinKey);

        return $query . '&w_rid=' . $wbiSign;
    }

    /**
     * APP签名方法
     */
    private function appSign(array $params, string $appkey, string $appsec): array
    {
        // 添加appkey参数
        $params['appkey'] = $appkey;

        // 按照key重排参数
        ksort($params);

        // 序列化参数
        $query = http_build_query($params);

        // 计算api签名
        $sign = md5($query . $appsec);

        // 添加签名参数
        $params['sign'] = $sign;

        return $params;
    }

    /**
     * 解析弹幕 protobuf 数据
     */
    private function parseDanmakuProtobuf($binary)
    {
        $danmakus = [];
        $pos      = 0;
        $length   = strlen($binary);

        while ($pos < $length) {
            // 添加边界检查
            if ($pos >= $length) {
                break;
            }

            // 读取字段标识和类型
            $byte        = ord($binary[$pos]);
            $fieldNumber = $byte >> 3;
            $wireType    = $byte & 0x07;
            $pos++;

            // 如果是弹幕数组字段 (field number = 1)
            if ($fieldNumber === 1) {
                // 读取长度
                $msgLen = 0;
                $shift  = 0;
                do {
                    // 添加边界检查
                    if ($pos >= $length) {
                        break 2; // 跳出外层循环
                    }
                    $byte = ord($binary[$pos]);
                    $msgLen |= ($byte & 0x7F) << $shift;
                    $shift += 7;
                    $pos++;
                } while ($byte & 0x80);

                // 添加长度检查
                if ($pos + $msgLen > $length) {
                    break;
                }

                // 解析单条弹幕
                $danmaku = $this->parseSingleDanmaku(substr($binary, $pos, $msgLen));
                if ($danmaku) {
                    $danmakus[] = $danmaku;
                }
                $pos += $msgLen;
            } else {
                // 跳过其他字段
                switch ($wireType) {
                    case 0: // Varint
                        while ($pos < $length && (ord($binary[$pos++]) & 0x80));
                        break;
                    case 1: // 64-bit
                        $pos += 8;
                        if ($pos > $length) {
                            break 2;
                        }

                        break;
                    case 2: // Length-delimited
                        $len   = 0;
                        $shift = 0;
                        do {
                            if ($pos >= $length) {
                                break 3;
                            }
                            // 跳出所有循环
                            $byte = ord($binary[$pos]);
                            $len |= ($byte & 0x7F) << $shift;
                            $shift += 7;
                            $pos++;
                        } while ($byte & 0x80);
                        $pos += $len;
                        if ($pos > $length) {
                            break 2;
                        }

                        break;
                    case 5: // 32-bit
                        $pos += 4;
                        if ($pos > $length) {
                            break 2;
                        }

                        break;
                }
            }
        }

        return $danmakus;
    }

    /**
     * 解析单条弹幕数据
     */
    private function parseSingleDanmaku($binary)
    {
        $result = [];
        $pos    = 0;
        $length = strlen($binary);

        while ($pos < $length) {
            // 读取字段标识和类型
            $byte        = ord($binary[$pos]);
            $fieldNumber = $byte >> 3;
            $wireType    = $byte & 0x07;
            $pos++;

            // 根据字段编号解析对应的值
            switch ($fieldNumber) {
                case 1:  // id
                case 2:  // progress
                case 3:  // mode
                case 4:  // fontsize
                case 5:  // color
                case 8:  // ctime
                case 9:  // weight
                case 11: // attr
                    $value = 0;
                    $shift = 0;
                    do {
                        $byte = ord($binary[$pos]);
                        $value |= ($byte & 0x7F) << $shift;
                        $shift += 7;
                        $pos++;
                    } while ($byte & 0x80);

                    switch ($fieldNumber) {
                        case 1:$result['id'] = $value;
                            break;
                        case 2:$result['progress'] = $value;
                            break;
                        case 3:$result['mode'] = $value;
                            break;
                        case 4:$result['fontsize'] = $value;
                            break;
                        case 5:$result['color'] = $value;
                            break;
                        case 8:$result['ctime'] = $value;
                            break;
                        case 9:$result['weight'] = $value;
                            break;
                        case 11:$result['attr'] = $value;
                            break;
                    }
                    break;

                case 6:  // midHash
                case 7:  // content
                case 10: // idStr
                case 12: // action
                    $len   = 0;
                    $shift = 0;
                    do {
                        $byte = ord($binary[$pos]);
                        $len |= ($byte & 0x7F) << $shift;
                        $shift += 7;
                        $pos++;
                    } while ($byte & 0x80);

                    $value = substr($binary, $pos, $len);
                    $pos += $len;

                    switch ($fieldNumber) {
                        case 6:$result['midHash'] = $value;
                            break;
                        case 7:$result['content'] = $value;
                            break;
                        case 10:$result['idStr'] = $value;
                            break;
                        case 12:$result['action'] = $value;
                            break;
                    }
                    break;

                default:
                    // 跳过未知字段
                    if ($wireType === 0) {
                        // 添加边界检查
                        while ($pos < $length && (ord($binary[$pos++]) & 0x80));
                    } elseif ($wireType === 2) {
                        $len   = 0;
                        $shift = 0;
                        do {
                            // 添加边界检查
                            if ($pos >= $length) {
                                break 2; // 跳出外层循环
                            }
                            $byte = ord($binary[$pos]);
                            $len |= ($byte & 0x7F) << $shift;
                            $shift += 7;
                            $pos++;
                        } while ($byte & 0x80);

                        // 添加长度检查
                        if ($pos + $len > $length) {
                            break 2; // 跳出外层循环
                        }
                        $pos += $len;
                    }
                    break;
            }
        }

        return $result;
    }

    private function getVideoPartFromWebpage(string $bvid)
    {
        $cookies  = parse_netscape_cookie_content($this->settingsService->get(SettingKey::COOKIES_CONTENT));
        $client   = $this->getClient();
        $url      = "https://www.bilibili.com/video/{$bvid}";
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
                $parsedParts = $this->getVideoPartFromWebpage($bvid);
            } else {
                $parsedParts = $this->getVideoPartFromWebpage('av' . $strId);
            }
        } catch (\Exception $e) {
            Log::error("通过网页获取视频分P信息失败: " . $e->getMessage());
            try {
                $parsedParts = $this->getVideoPartFromApi($strId);
                Log::info("Successfully fetched video parts via API", [
                    'str_id' => $strId, 
                    'parts_count' => count($parsedParts ?? [])
                ]);
            } catch (\Exception $e) {
                Log::error("通过API获取视频分P信息失败: " . $e->getMessage());
                throw new \Exception("获取视频分P信息失败: " . $e->getMessage());
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

    public function pullFav()
    {
        $cookies    = parse_netscape_cookie_content($this->settingsService->get(SettingKey::COOKIES_CONTENT));
        $dedeUserID = $cookies->getCookieByName('DedeUserID');
        if (! $dedeUserID) {
            throw new \Exception("DedeUserID 不存在");
        }
        $mid = $dedeUserID->getValue();

        $pn        = 1;
        $ps        = 20;
        $client    = $this->getClient();
        $favorites = [];
        while (true) {
            $response = $client->request('GET', self::API_HOST . "/x/v3/fav/folder/created/list?pn={$pn}&ps={$ps}&up_mid={$mid}");

            $result = json_decode($response->getBody()->getContents(), true);

            if ($result && $result['code'] == 0) {

                if ($result['data'] == null) {
                    Log::error(sprintf("Account cookie is invalid when accessing the get fav folder api."));
                    return [];
                }
                foreach ($result['data']['list'] as $value) {
                    $favorites[] = [
                        'title'       => $value['title'],
                        'cover'       => $value['cover'],
                        'ctime'       => $value['ctime'],
                        'mtime'       => $value['mtime'],
                        'media_count' => $value['media_count'],
                        'id'          => $value['id'],
                    ];
                }
            }
            if (isset($result['data']['has_more']) && $result['data']['has_more']) {
                $pn++;
            } else {
                break;
            }
        }

        return $favorites;
    }

    public function pullFavVideoList(int $favId, ?int $page = null)
    {
        $client = $this->getClient();
        $pn     = $page ?? 1;
        $videos = [];

        while (true) {
            $url = self::API_HOST . "/x/v3/fav/resource/list?media_id=$favId&pn=$pn&ps={$this->favVideosPageSize}&keyword=&order=mtime&type=0&tid=0&platform=web";
            Log::info("Fetch fav video list", ['url' => $url, 'fav_id' => $favId, 'page' => $pn]);

            try {
                $response = $client->request('GET', $url);
                $result   = json_decode($response->getBody()->getContents(), true);

                if (isset($result['data']) && is_array($result['data']['medias'])) {
                    foreach ($result['data']['medias'] as $value) {
                        $videos[] = $value;
                    }
                }

                // 如果指定了页码,只获取该页数据
                if ($page !== null) {
                    break;
                }

                if (isset($result['data']['has_more']) && $result['data']['has_more']) {
                    $pn++;
                } else {
                    break;
                }
            } catch (\Exception $e) {
                Log::error("API request failed: " . $e->getMessage());
                // 如果是频率限制错误，等待更长时间
                // 检查接口响应是否包含429 或者412，如果包含则通过redis记录2个小时。
                if (strpos($e->getMessage(), '429') !== false || strpos($e->getMessage(), '412') !== false) {
                    Log::warning("Rate limit detected, waiting 60 seconds before retry");
                    $this->bilibiliSuspendService->setSuspend();
                    continue;
                }
                throw $e;
            }
        }
        return $videos;
    }

    public function getSeasonsList(int $mid, int $seasonId, int $page = 1)
    {
        try {
            $pageSize = 30;
            $client   = $this->getClient();
            $url      = self::API_HOST . "/x/polymer/web-space/seasons_archives_list?mid={$mid}&season_id={$seasonId}&sort_reverse=false&page_size={$pageSize}&page_num={$page}";
            $response = $client->request('GET', $url);
            $result   = json_decode($response->getBody()->getContents(), true);
            if (is_array($result) && isset($result['data']) && is_array($result['data'])) {
                return $result['data'];
            }
            Log::error("get seasons list failed", ['mid' => $mid, 'season_id' => $seasonId, 'page' => $page, 'result' => $result]);
            throw new \Exception("get seasons list failed");
        } catch (\Exception $e) {
            Log::error("API request failed: " . $e->getMessage());
            if (strpos($e->getMessage(), '429') !== false || strpos($e->getMessage(), '412') !== false) {
                Log::warning("Rate limit detected, waiting 60 seconds before retry");
                $this->bilibiliSuspendService->setSuspend();
            }
            throw $e;
        }
    }

    public function getSeriesMeta(int $seriesId)
    {
        $client = $this->getClient();
        $url    = self::API_HOST . "/x/series/series?series_id={$seriesId}";
        try {
            $response = $client->request('GET', $url);
            $result   = json_decode($response->getBody()->getContents(), true);
            if (is_array($result) && isset($result['data']) && is_array($result['data'])) {
                return $result['data'];
            }
            Log::error("get series meta failed", ['series_id' => $seriesId, 'result' => $result]);
            throw new \Exception("get series meta failed");
        } catch (\Exception $e) {
            Log::error("API request failed: " . $e->getMessage());
            if (strpos($e->getMessage(), '429') !== false || strpos($e->getMessage(), '412') !== false) {
                Log::warning("Rate limit detected, waiting 60 seconds before retry");
                $this->bilibiliSuspendService->setSuspend();
            }
            throw $e;
        }
    }

    public function getSeriesList(int $mid, int $seriesId, int $page = 1)
    {
        try {
            $pageSize = 30;
            $client   = $this->getClient();
            $url      = self::API_HOST . "/x/series/archives?mid={$mid}&series_id={$seriesId}&only_normal=true&sort=desc&ps={$pageSize}&pn={$page}";
            $response = $client->request('GET', $url);
            $result   = json_decode($response->getBody()->getContents(), true);
            if (is_array($result) && isset($result['data']) && is_array($result['data'])) {
                return $result['data'];
            }
            Log::error("get series list failed", ['mid' => $mid, 'series_id' => $seriesId, 'page' => $page, 'result' => $result]);
            throw new \Exception("get series list failed");
        } catch (\Exception $e) {
            Log::error("API request failed: " . $e->getMessage());
            if (strpos($e->getMessage(), '429') !== false || strpos($e->getMessage(), '412') !== false) {
                Log::warning("Rate limit detected, waiting 60 seconds before retry");
                $this->bilibiliSuspendService->setSuspend();
            }
            throw $e;
        }

    }
    public function getUpVideos(int $mid, ?int $offsetAid)
    {
        $client = $this->getAppClient();

        // 准备基础参数
        $params = [
            'vmid'  => $mid,
            'order' => 'pubdate',
            'ps'    => 20,
            'ts'    => time(),
        ];

        // 如果有偏移aid，添加到参数中
        if ($offsetAid !== null) {
            $params['aid'] = $offsetAid;
        }

        // 进行APP签名
        $signedParams = $this->appSign($params, self::APP_KEY, self::APP_SECRET);

        // 构建查询字符串
        $query = http_build_query($signedParams);
        $url   = self::APP_API_HOST . "/x/v2/space/archive/cursor?" . $query;

        try {
            $response = $client->request('GET', $url);
            $result   = json_decode($response->getBody()->getContents(), true);
            if ($result['code'] !== 0) {
                throw new \Exception("get up videos failed: " . $result['message']);
            }
            if (is_array($result['data']['item']) && count($result['data']['item']) > 0) {
                $lastAid = intval(end($result['data']['item'])['param']);
            } else {
                $lastAid = null;
            }

            return [
                'has_next' => $result['data']['has_next'],
                'list'     => $result['data']['item'] ?? [],
                'last_aid' => $lastAid,
            ];
        } catch (\Exception $e) {
            Log::error("API request failed: " . $e->getMessage());
            if (strpos($e->getMessage(), '429') !== false || strpos($e->getMessage(), '412') !== false) {
                Log::warning("Rate limit detected, waiting 60 seconds before retry");
                $this->bilibiliSuspendService->setSuspend();
            }
            throw $e;
        }
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
                throw new \Exception("get video info failed: " . $result['message'], $result['code']);
            }
            return $result['data'];
        } catch (\Exception $e) {
            Log::error("API request failed: " . $e->getMessage());
            if (strpos($e->getMessage(), '429') !== false || strpos($e->getMessage(), '412') !== false) {
                Log::warning("Rate limit detected, waiting 60 seconds before retry");
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
            throw new \Exception("get uper card failed: " . $result['message'], $result['code']);
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

    public function getFavFolderResources(int $favId, int $page = 1)
    {
        $client = $this->getClient();
        $url    = self::API_HOST . "/x/v3/fav/folder/resources?media_id={$favId}&pn={$page}&ps={$this->favVideosPageSize}&build=85900200&c_locale=en&device=phone&disable_rcmd=0&mobi_app=iphone&platform=ios&s_locale=en";

        $response = $client->request('GET', $url);
        $result   = json_decode($response->getBody()->getContents(), true);

        if (is_array($result) && $result['code'] == 0) {
            return $result['data']['list'] ? (array) $result['data']['list'] : [];
        }
        return [];
    }

    public function checkCookieExpired(CookieJar $cookies):bool
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
            Log::error("Cookie expired: " . $data['message'] ?? '', ['response' => $response]);
        } catch (\Exception $e) {
            Log::error("Error checking cookie expired: " . $e->getMessage());
            throw $e;
        }
        return false;
    }

    /**
     * 获取视频热评及子评论
     * @param int $oid 稿件ID (av号)
     * @param int $minCount 最少获取多少条主评论 (不包含子评论)
     */
    public function getVideoComments(int $oid, int $minCount = 20): array
    {
        $allComments = [];
        $next = 0;      
        $pageCount = 0; 
        $maxPages = 20; 
        $fetchedRootCount = 0;

        $client = $this->getClient();
        $cookies = parse_netscape_cookie_content($this->settingsService->get(SettingKey::COOKIES_CONTENT));

        // 1. 获取 WBI Keys
        $keys = $this->getWbiKeys($client, $cookies);
        if (!$keys) {
            Log::error("Skipping comments download due to missing WBI keys");
            return [];
        }
        list($imgKey, $subKey) = $keys;

        do {
            // 2. 增加随机延迟 (关键防风控手段)
            // 第一页不睡，后续页数随机休眠 1.5秒 - 3.5秒
            if ($pageCount > 0) {
                $sleepTime = rand(1500000, 3500000); 
                usleep($sleepTime);
            }

            // 3. 构建参数并签名
            $params = [
                'oid' => $oid,
                'type' => 1,
                'mode' => 3, // 3=热度排序, 2=时间排序 (建议用3)
                'ps' => 20,
                'next' => $next,
                'wts' => time(), // 必须包含时间戳
            ];

            // 使用 encWbi 生成 query 字符串 (包含 w_rid)
            $query = $this->encWbi($params, $imgKey, $subKey);

            // 4. 使用 WBI 接口
            $url = self::API_HOST . "/x/v2/reply/wbi/main?" . $query;
            
            try {
                $response = $client->get($url, ['cookies' => $cookies]); // 显式传递 cookies
                $data = json_decode($response->getBody()->getContents(), true);
                
                // 检查 API 错误
                if (($data['code'] ?? 0) !== 0) {
                    Log::warning("Comment API Error: " . ($data['message'] ?? 'Unknown'), ['code' => $data['code']]);
                    // 如果遇到 412 或特定风控 code，直接终止本视频下载
                    if (in_array($data['code'], [-412, 412])) {
                        $this->bilibiliSuspendService->setSuspend(); // 触发全局熔断
                        throw new \Exception("Triggered Risk Control (412)");
                    }
                    break;
                }

                if (!isset($data['data'])) {
                    break;
                }

                $responseData = $data['data'];

                // --- 处理置顶评论 (仅第一页) ---
                if ($next === 0) {
                    $upperTop = $responseData['top']['upper'] ?? null;
                    if ($upperTop && isset($upperTop['rpid'])) {
                        $topComment = $this->formatCommentData($upperTop, $oid, 0);
                        $topComment['is_top'] = true;
                        $allComments[$topComment['rpid']] = $topComment;
                        $fetchedRootCount++;

                        if (($upperTop['rcount'] ?? 0) > 0) {
                            // 子评论也需要传递 keys 以便后续优化（目前子评论接口暂无WBI，但传递client保持会话）
                            $subComments = $this->getAllSubComments($oid, $upperTop['rpid'], $client);
                            foreach ($subComments as $sub) {
                                $allComments[$sub['rpid']] = $sub;
                            }
                        }
                    }
                }
                
                // --- 处理普通评论 ---
                if (isset($responseData['replies']) && is_array($responseData['replies'])) {
                    foreach ($responseData['replies'] as $reply) {
                        $formatted = $this->formatCommentData($reply, $oid, 0);

                        if (!isset($allComments[$formatted['rpid']])) {
                            $allComments[$formatted['rpid']] = $formatted;
                            $fetchedRootCount++; 
                        }

                        if (($reply['rcount'] ?? 0) > 0) {
                            $subComments = $this->getAllSubComments($oid, $reply['rpid'], $client);
                            foreach ($subComments as $sub) {
                                $allComments[$sub['rpid']] = $sub;
                            }
                        }
                    }
                } else {
                    break;
                }

                // --- 循环控制 ---
                if ($fetchedRootCount >= $minCount) {
                    break;
                }

                $cursor = $responseData['cursor'] ?? null;
                if ($cursor && isset($cursor['is_end']) && !$cursor['is_end'] && isset($cursor['next'])) {
                    $next = $cursor['next'];
                    $pageCount++;
                } else {
                    break;
                }

            } catch (\Exception $e) {
                Log::error("Failed to fetch comments", ['oid' => $oid, 'error' => $e->getMessage()]);
                break; 
            }

        } while ($pageCount < $maxPages);

        return array_values($allComments);
    }

    private function processReply($reply, $oid, $rootId = 0) {
        return $this->formatCommentData($reply, $oid, $rootId);
    }

    // [修改] 增加 Client 参数复用连接
    private function getAllSubComments(int $oid, int $rootId, Client $client = null): array
    {
        $subComments = [];
        $page = 1;
        $pageSize = 20; 
        $maxPages = 3; // [建议] 降低子评论页数，子评论翻页由于没有 wbi 很容易触发风控，取前3页通常够了

        if (!$client) {
            $client = $this->getClient();
        }

        do {
            $url = "https://api.bilibili.com/x/v2/reply/reply?oid={$oid}&type=1&root={$rootId}&ps={$pageSize}&pn={$page}";
            try {
                // [修改] 大幅增加子评论休眠时间 (1s - 2s)
                usleep(rand(1000000, 2000000)); 
                
                $response = $client->get($url);
                $data = json_decode($response->getBody()->getContents(), true);
                
                if (empty($data['data']['replies'])) {
                    break;
                }

                foreach ($data['data']['replies'] as $reply) {
                    $subComments[] = $this->formatCommentData($reply, $oid, $rootId);
                }
                
                $total = $data['data']['page']['count'] ?? 0;
                if ($page * $pageSize >= $total) {
                    break;
                }
                $page++;

            } catch (\Exception $e) {
                // 子评论失败不影响主流程，静默跳出
                break;
            }
        } while ($page <= $maxPages);

        return $subComments;
    }

    // 复用之前的 getAllSubComments，但内部调用 processReply
    // 注意：需要确保 getAllSubComments 里使用的是 $this->getClient()
    private function formatCommentData(array $reply, int $oid, int $rootId): array
    {
        // 提取图片
        $pictures = [];
        if (isset($reply['content']['pictures'])) {
            foreach ($reply['content']['pictures'] as $pic) {
                $pictures[] = $pic['img_src'];
            }
        }

        // 【核心修复】优先获取动图链接 webp_url > gif_url > url
        $emotes = [];
        if (isset($reply['content']['emote'])) {
            foreach ($reply['content']['emote'] as $key => $value) {
                if (isset($value['webp_url']) && !empty($value['webp_url'])) {
                    $emotes[$key] = $value['webp_url'];
                } elseif (isset($value['gif_url']) && !empty($value['gif_url'])) {
                    $emotes[$key] = $value['gif_url'];
                } else {
                    $emotes[$key] = $value['url'];
                }
            }
        }

        return [
            'rpid' => $reply['rpid'],
            'oid' => $oid,
            'mid' => $reply['mid'],
            'uname' => $reply['member']['uname'],
            'avatar' => $reply['member']['avatar'],
            'content' => $reply['content']['message'],
            'like' => $reply['like'],
            'root' => $rootId,
            'parent' => $reply['parent'],
            'ctime' => date('Y-m-d H:i:s', $reply['ctime']),
            'pictures' => $pictures, // 新增
            'emotes' => $emotes,     // 新增
            'is_top' => false,       // 默认为 false
        ];
    }

    /**
     * [新增] 获取收藏夹元数据 (用于添加订阅时获取标题和封面)
     */
    public function getFavoriteFolderInfo($mediaId)
    {
        // 这里的 media_id 对应收藏夹 URL 中的 fid
        $url = self::API_HOST . "/x/v3/fav/folder/info?media_id={$mediaId}";
        
        try {
            $response = $this->getClient()->get($url);
            $data = json_decode($response->getBody()->getContents(), true);

            if (($data['code'] ?? -1) !== 0) {
                Log::error("获取收藏夹信息失败: " . ($data['message'] ?? 'Unknown error'));
                return null;
            }

            return $data['data']; // 包含 title, cover, upper 等信息
        } catch (\Exception $e) {
            Log::error("Bilibili API Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * [新增] 获取 WBI 签名所需的 img_key 和 sub_key
     */
    private function getWbiKeys(Client $client, $cookies)
    {
        // 尝试从缓存获取（可选优化），这里先保持实时获取以确保准确性
        // 获取 WBI keys
        try {
            $navResponse = $client->request('GET', self::API_HOST . '/x/web-interface/nav', [
                'cookies' => $cookies,
            ]);
            $navData = json_decode($navResponse->getBody()->getContents(), true);
    
            if (! isset($navData['data']['wbi_img'])) {
                Log::warning('无法获取 WBI keys');
                return null;
            }
    
            $imgUrl = $navData['data']['wbi_img']['img_url'];
            $subUrl = $navData['data']['wbi_img']['sub_url'];
            $imgKey = substr($imgUrl, strrpos($imgUrl, '/') + 1, -4);
            $subKey = substr($subUrl, strrpos($subUrl, '/') + 1, -4);
            
            return [$imgKey, $subKey];
        } catch (\Exception $e) {
            Log::error("Get WBI Keys failed: " . $e->getMessage());
            return null;
        }
    }
}
