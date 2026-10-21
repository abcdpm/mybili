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
class SubscriptionClient extends BilibiliClient
{
    // 继承 BilibiliClient：getClient/getAppClient/appSign/getWbiKeys/encWbi、常量与构造函数


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
            Log::error("[合集管理] 获取合集列表失败", ['mid' => $mid, 'season_id' => $seasonId, 'page' => $page, 'result' => $result]);
            throw new \Exception("get seasons list failed");
        } catch (\Exception $e) {
            Log::error("[合集管理] API 请求失败" . $e->getMessage());
            if (strpos($e->getMessage(), '429') !== false || strpos($e->getMessage(), '412') !== false) {
                Log::warning("[合集管理] 检测到频率限制, 等待60秒后重试");
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
            Log::error("[系列管理] 获取系列元数据失败", ['series_id' => $seriesId, 'result' => $result]);
            throw new \Exception("get series meta failed");
        } catch (\Exception $e) {
            Log::error("[系列管理] API 请求失败" . $e->getMessage());
            if (strpos($e->getMessage(), '429') !== false || strpos($e->getMessage(), '412') !== false) {
                Log::warning("[系列管理] 检测到频率限制, 等待60秒后重试");
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
            Log::error("[系列管理] 获取系列列表失败", ['mid' => $mid, 'series_id' => $seriesId, 'page' => $page, 'result' => $result]);
            throw new \Exception("get series list failed");
        } catch (\Exception $e) {
            Log::error("[系列管理] API 请求失败" . $e->getMessage());
            if (strpos($e->getMessage(), '429') !== false || strpos($e->getMessage(), '412') !== false) {
                Log::warning("[系列管理] 检测到频率限制, 等待60秒后重试");
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
                throw new \Exception("[UP主管理] 获取UP主视频失败: " . $result['message']);
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
            Log::error("[UP主管理] API 请求失败" . $e->getMessage());
            if (strpos($e->getMessage(), '429') !== false || strpos($e->getMessage(), '412') !== false) {
                Log::warning("[UP主管理] 检测到频率限制, 等待60秒后重试");
                $this->bilibiliSuspendService->setSuspend();
            }
            throw $e;
        }
    }
}
