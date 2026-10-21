<?php
namespace App\Models;

use App\Casts\UnixTimestamp;
use App\Models\AudioPart;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\LocalImagePrioritizerTrait;

class Video extends Model
{
    use SoftDeletes, LocalImagePrioritizerTrait;

    protected $table      = 'videos';
    protected $fillable   = [
        'id', 'link', 'title', 'intro', 'cover', 'bvid', 'pubtime', 'duration',
        'attr', 'invalid', 'frozen', 'page', 'fav_time', 'danmaku_downloaded_at',
        'video_downloaded_at', 'upper_id', 'comments_updated_at', 'type',
        'tags', 'view'
    ];
    protected $primaryKey = 'id';

    protected $casts = [
        'tags' => 'array', // 【重要补漏】：必须加上，否则读取出来会是普通字符串
        // 时间字段统一约定：DB 存 'Y-m-d H:i:s'，对外读写为 Unix 秒级时间戳。
        // 取代旧版「strtotime 访问器」实现，读出值与旧版完全一致（前端无感），
        // 写入兼容时间戳整数 / 日期字符串 / Carbon。
        'pubtime'  => UnixTimestamp::class,
        'fav_time' => UnixTimestamp::class,
    ];

    protected $appends = [
        'cover_info',
    ];

    protected $attributes = [
        'cache_image' => '',
        'attr'        => 0,
        'invalid'     => false,
        'frozen'      => false,
        'page'        => 1,
        'fav_time'    => null,
        'upper_id'    => null,
        'type'        => 2,
        'view'        => 0,
        'intro'       => '',
    ];

    public function parts()
    {
        return $this->hasMany(VideoPart::class, 'video_id', 'id');
    }

    public function audioPart()
    {
        return $this->hasOne(AudioPart::class, 'video_id', 'id');
    }

    public function isAudio(): bool
    {
        return (int) $this->type === 12;
    }

    // 时间字段（pubtime/fav_time）已统一交由 App\Casts\UnixTimestamp 处理：
    // 读取返回 Unix 时间戳（与旧版访问器一致），写入兼容时间戳/字符串/Carbon。

    public function favorite()
    {
        return $this->belongsToMany(FavoriteList::class, 'favorite_list_videos', 'video_id', 'favorite_list_id');
    }

    public function subscriptions()
    {
        return $this->belongsToMany(Subscription::class, 'subscription_videos', 'video_id', 'subscription_id');
    }

    public function upper()
    {
        return $this->belongsTo(Upper::class, 'upper_id', 'mid');
    }

    /**
     * 获取视频封面（通过多态关联）
     * 返回 Cover 模型，可以访问封面的完整信息
     */
    public function coverImage()
    {
        return $this->morphToMany(Cover::class, 'coverable', 'coverables')
            ->withTimestamps();
    }

    /**
     * 获取单个封面（由于唯一约束，一个视频只有一个封面）
     * 使用示例: $video->coverImage()->first()
     * 或者使用访问器: $video->cover_info
     */
    public function getCoverInfoAttribute()
    {
        // 优先使用已预加载关系，避免大列表序列化时 N+1 查询
        if ($this->relationLoaded('coverImage')) {
            $coverImages = $this->getRelation('coverImage');
            return $coverImages ? $coverImages->first() : null;
        }

        return $this->coverImage()->first();
    }

    public function comments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Comment::class)->where('root', 0)->orderBy('like', 'desc');
    }
        
    // 是否还需触发新下载任务
    public function needsMoreDownloadTask(): bool
    {
        if($this->trashed()){
            return false;
        }

        $maxPage = intval($this->page);

        if (intval($this->audio_downloaded_num + $this->video_downloaded_num) < $maxPage) {
            return true;
        }

        return false;
    }
}
