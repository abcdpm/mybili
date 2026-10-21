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
 * B 站 WBI 签名器（由 BilibiliService 拆分而来，方法体逐字保留）。
 */
class WbiSigner
{
    const API_HOST = 'https://api.bilibili.com';

    
    /**
     * [新增] 获取 WBI 签名所需的 img_key 和 sub_key
     */
    public static function getWbiKeys(Client $client, $cookies)
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
            Log::error("获取 WBI keys 失败: " . $e->getMessage());
            return null;
        }
    }


    public static function encWbi($params, $imgKey, $subKey)
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
        $filteredParams  = [];
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
}
