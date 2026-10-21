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
class DanmakuClient extends BilibiliClient
{
    // 继承 BilibiliClient：getClient/getAppClient/appSign/getWbiKeys/encWbi、常量与构造函数


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
                    $byte    = ord($binary[$pos]);
                    $msgLen |= ($byte & 0x7F) << $shift;
                    $shift  += 7;
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
                            $byte   = ord($binary[$pos]);
                            $len   |= ($byte & 0x7F) << $shift;
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
                        $byte   = ord($binary[$pos]);
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
                        $byte   = ord($binary[$pos]);
                        $len   |= ($byte & 0x7F) << $shift;
                        $shift += 7;
                        $pos++;
                    } while ($byte & 0x80);

                    $value  = substr($binary, $pos, $len);
                    $pos   += $len;

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
                            $byte   = ord($binary[$pos]);
                            $len   |= ($byte & 0x7F) << $shift;
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
}
