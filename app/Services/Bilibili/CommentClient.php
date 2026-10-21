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
class CommentClient extends BilibiliClient
{
    // 继承 BilibiliClient：getClient/getAppClient/appSign/getWbiKeys/encWbi、常量与构造函数


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
            Log::error("[视频管理] 缺少 WBI keys, 跳过评论下载");
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
                    Log::warning("[视频评论] 评论 API 错误: " . ($data['message'] ?? 'Unknown'), ['code' => $data['code']]);
                    // 如果遇到 412 或特定风控 code，直接终止本视频下载
                    if (in_array($data['code'], [-412, 412])) {
                        $this->bilibiliSuspendService->setSuspend(); // 触发全局熔断
                        throw new \Exception("[视频评论] 触发风险控制 (412)");
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
                                // 子评论不计入 fetchedRootCount
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
                Log::error("[视频评论] 获取评论失败", ['oid' => $oid, 'error' => $e->getMessage()]);
                break; 
            }

        } while ($pageCount < $maxPages);

        return array_values($allComments);
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
}
