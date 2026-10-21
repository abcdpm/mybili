<?php
namespace App\Services;

use App\Enums\SettingKey;
use App\Exceptions\ApiGetVideoStatusException;
use App\Services\Bilibili\CommentClient;
use App\Services\Bilibili\DanmakuClient;
use App\Services\Bilibili\FavClient;
use App\Services\Bilibili\SubscriptionClient;
use App\Services\Bilibili\VideoClient;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Utils;
use Log;
use Psr\Http\Message\ResponseInterface;
use App\Services\SettingsService;
use Arr;

/**
 * B 站 API 门面（兼容层）。
 *
 * 公有方法签名与历史版本完全一致，外部调用方（Job/Action/Command）
 * 无需任何改动；实现已按领域拆分至 App\Services\Bilibili\ 下的
 * WbiSigner / DanmakuClient / CommentClient / FavClient / VideoClient /
 * SubscriptionClient，便于维护与测试。
 */
class BilibiliService
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

    // ====== 领域客户端（懒加载）======

    private ?DanmakuClient $danmakuClient = null;
    private ?CommentClient $commentClient = null;
    private ?FavClient $favClient = null;
    private ?VideoClient $videoClient = null;
    private ?SubscriptionClient $subscriptionClient = null;

    private function danmakuClient(): DanmakuClient
    {
        return $this->danmakuClient ??= new DanmakuClient(settingsService: $this->settingsService, bilibiliSuspendService: $this->bilibiliSuspendService, cookieControlService: $this->cookieControlService);
    }

    private function commentClient(): CommentClient
    {
        return $this->commentClient ??= new CommentClient(settingsService: $this->settingsService, bilibiliSuspendService: $this->bilibiliSuspendService, cookieControlService: $this->cookieControlService);
    }

    private function favClient(): FavClient
    {
        return $this->favClient ??= new FavClient(settingsService: $this->settingsService, bilibiliSuspendService: $this->bilibiliSuspendService, cookieControlService: $this->cookieControlService);
    }

    private function videoClient(): VideoClient
    {
        return $this->videoClient ??= new VideoClient(settingsService: $this->settingsService, bilibiliSuspendService: $this->bilibiliSuspendService, cookieControlService: $this->cookieControlService);
    }

    private function subscriptionClient(): SubscriptionClient
    {
        return $this->subscriptionClient ??= new SubscriptionClient(settingsService: $this->settingsService, bilibiliSuspendService: $this->bilibiliSuspendService, cookieControlService: $this->cookieControlService);
    }

    // ====== 公有 API（签名与旧版一致，逐个委托）======

    public function getDanmaku(int $cid, int $duration)
    {
        return $this->danmakuClient()->getDanmaku($cid, $duration);
    }

    public function getVideoParts(string $strId)
    {
        return $this->videoClient()->getVideoParts($strId);
    }

    public function pullFav()
    {
        return $this->favClient()->pullFav();
    }

    public function pullFavVideoList(int $favId, ?int $page = null)
    {
        return $this->favClient()->pullFavVideoList($favId, $page);
    }

    public function getSeasonsList(int $mid, int $seasonId, int $page = 1)
    {
        return $this->subscriptionClient()->getSeasonsList($mid, $seasonId, $page);
    }

    public function getSeriesMeta(int $seriesId)
    {
        return $this->subscriptionClient()->getSeriesMeta($seriesId);
    }

    public function getSeriesList(int $mid, int $seriesId, int $page = 1)
    {
        return $this->subscriptionClient()->getSeriesList($mid, $seriesId, $page);
    }

    public function getUpVideos(int $mid, ?int $offsetAid)
    {
        return $this->subscriptionClient()->getUpVideos($mid, $offsetAid);
    }

    public function getVideoInfo(string $strId): array
    {
        return $this->videoClient()->getVideoInfo($strId);
    }

    public function getUperCard(int $mid): array
    {
        return $this->videoClient()->getUperCard($mid);
    }

    public function getVideoFestivalJumpUrl(string $strId): ?string
    {
        return $this->videoClient()->getVideoFestivalJumpUrl($strId);
    }

    public function getFavFolderResources(int $favId, int $page = 1)
    {
        return $this->favClient()->getFavFolderResources($favId, $page);
    }

    public function getAudioInfo(int $sid): array
    {
        return $this->videoClient()->getAudioInfo($sid);
    }

    public function checkCookieExpired(CookieJar $cookies): bool
    {
        return $this->videoClient()->checkCookieExpired($cookies);
    }

    public function getVideoComments(int $oid, int $minCount = 20): array
    {
        return $this->commentClient()->getVideoComments($oid, $minCount);
    }

    public function getFavoriteFolderInfo($mediaId)
    {
        return $this->favClient()->getFavoriteFolderInfo($mediaId);
    }

    public function getVideoTags(string $bvid): array
    {
        return $this->videoClient()->getVideoTags($bvid);
    }
}
