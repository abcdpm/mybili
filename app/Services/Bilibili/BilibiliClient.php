<?php

namespace App\Services\Bilibili;

use App\Enums\SettingKey;
use App\Exceptions\ApiGetVideoStatusException;
use App\Services\BilibiliSuspendService;
use App\Services\CookieControlService;
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
abstract class BilibiliClient
{
    const API_HOST     = 'https://api.bilibili.com';
    const APP_API_HOST = 'https://app.biliapi.com';
    const WEB_HOST     = 'https://www.bilibili.com';
    const APP_KEY      = '1d8b6e7d45233436';
    const APP_SECRET   = '560c52ccd288fed045859ed18bffd973';

    const SUCCESS_CODE             = 0;//成功
    const ERROR_CODE_REQUEST       = -400;//请求错误
    const ERROR_CODE_PERMISSION    = -403;//权限不足
    const ERROR_CODE_NOT_FOUND     = -404;//无视频
    const ERROR_CODE_NOT_VISIBLE   = 62002;//稿件不可见
    const ERROR_CODE_REVIEWING     = 62004;//稿件审核中
    const ERROR_CODE_ONLY_UP_OWNER = 62012;//仅UP主自己可见

    protected $favVideosPageSize;

    public function __construct(
        public SettingsService $settingsService,
        public BilibiliSuspendService $bilibiliSuspendService,
        public CookieControlService $cookieControlService
    ) {
        $this->favVideosPageSize = intval(config('services.bilibili.fav_videos_page_size'));
    }


    protected function getClient()
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
                    Log::error("[Cookie] Cookie 已过期" . ($data['message'] ?? ''));
                    // 通过 CookieControlService 检查并发送通知（内部会检查 Redis 避免重复）
                    $cookieControlService->checkAndNotifyCookieExpired();
                }

                // 重新创建响应对象，因为响应体已被读取
                return $response->withBody(Utils::streamFor($bodyContent));
            } catch (\Exception $e) {
                Log::error("[Cookie] 检查 Cookie 过期时出错:" . $e->getMessage());
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
            'timeout'         => 30.0,  // 整个请求生命周期的最大超时时间（秒）
            'connect_timeout' => 10.0,  // 连接阶段（含 DNS 解析）的超时时间（秒）
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3',
                'Referer'    => 'https://www.bilibili.com/',
            ],
        ]);
    }


    protected function getAppClient()
    {
        return new Client([
            'timeout'         => 30.0,  // 整个请求生命周期的最大超时时间（秒）
            'connect_timeout' => 10.0,  // 连接阶段（含 DNS 解析）的超时时间（秒）
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

    protected function encWbi($params, $imgKey, $subKey)
    {
        return WbiSigner::encWbi($params, $imgKey, $subKey);
    }


    /**
     * APP签名方法
     */
    protected function appSign(array $params, string $appkey, string $appsec): array
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

    protected function getWbiKeys(Client $client, $cookies)
    {
        return WbiSigner::getWbiKeys($client, $cookies);
    }
}
