<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Storage;

class VideoPart extends Model
{
    protected $table    = 'video_parts';
    protected $fillable = ['video_id', 'cid', 'page', 'from', 'part', 'duration', 'vid', 'weblink', 'width', 'height', 'rotate', 'first_frame', 'created_at', 'video_downloaded_at','danmaku_downloaded_at'];


    protected $appends = [
        'video_download_url', 'mobile_url'
    ];

    public function video()
    {
        return $this->belongsTo(Video::class, 'video_id', 'id');
    }

    public function danmakus()
    {
        return $this->hasMany(Danmaku::class, 'cid', 'cid');
    }

    public function getVideoDownloadUrlAttribute()
    {
        return $this->video_download_path ? Storage::url($this->video_download_path) : null;
    }

    // 新增访问器
    public function getMobileUrlAttribute()
    {
        if ($this->mobile_download_path && Storage::disk('public')->exists($this->mobile_download_path)) {
            return Storage::url($this->mobile_download_path);
        }
        // 如果没有兼容版，返回 null，前端会自动降级播放原版
        return null;
    }
}
