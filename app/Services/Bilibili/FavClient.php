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
class FavClient extends BilibiliClient
{
    // 继承 BilibiliClient：getClient/getAppClient/appSign/getWbiKeys/encWbi、常量与构造函数


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
                    Log::error(sprintf("[Cookie] 访问收藏夹列表 API 时 Cookie 无效"));
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
            Log::info("[收藏夹管理] 获取收藏夹视频列表", ['url' => $url, 'fav_id' => $favId, 'page' => $pn]);

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
                Log::error("[收藏夹管理] API 请求失败" . $e->getMessage());
                // 如果是频率限制错误，等待更长时间
                // 检查接口响应是否包含429 或者412，如果包含则通过redis记录2个小时。
                if (strpos($e->getMessage(), '429') !== false || strpos($e->getMessage(), '412') !== false) {
                    Log::warning("[收藏夹管理] 检测到频率限制, 等待60秒后重试");
                    $this->bilibiliSuspendService->setSuspend();
                    continue;
                }
                throw $e;
            }
        }
        return $videos;
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
                Log::error("[收藏夹管理] 获取收藏夹信息失败" . ($data['message'] ?? 'Unknown error'));
                return null;
            }

            return $data['data']; // 包含 title, cover, upper 等信息
        } catch (\Exception $e) {
            Log::error("[收藏夹管理] Bilibili API 错误" . $e->getMessage());
            return null;
        }
    }
}
