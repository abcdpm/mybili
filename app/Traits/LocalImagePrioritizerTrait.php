<?php

namespace App\Traits;

use App\Models\Cover;
use Illuminate\Support\Facades\Storage;

trait LocalImagePrioritizerTrait
{
    /**
     * 启动 Trait 时自动预加载关联，彻底防止 N+1 数据库查询性能问题
     */
    public static function bootLocalImagePrioritizerTrait()
    {
        // 仅在非命令行（即前端 HTTP 请求 API）时自动触发关联预加载，保护 CLI 任务的内存
        if (!app()->runningInConsole()) {
            static::addGlobalScope('withLocalCover', function ($builder) {
                $builder->with('coverImage');
            });
        }
    }

    /**
     * 定义多态关联：根据 coverable_id 和 coverable_type 找到对应的本地封面
     */
    public function coverImage()
    {
        return $this->morphToMany(Cover::class, 'coverable', 'coverables');
    }

    /**
     * 拦截 API JSON 序列化：在发给前端前，自动把 B 站外链换成本地高压缩图片
     */
    public function toArray()
    {
        $array = parent::toArray();

        // 如果数据库里已经下载并关联了本地图
        if ($this->relationLoaded('coverImage') && $this->coverImage->isNotEmpty()) {
            $localCover = $this->coverImage->first();
            // 生成带 http:// 的完整绝对地址，骗过前端的自动拼接
            $localUrl = asset(Storage::url($localCover->path));

            // 自动嗅探模型中可能代表图片的字段，进行“狸猫换太子”
            $imageFields = ['cover', 'face', 'avatar', 'pic'];
            foreach ($imageFields as $field) {
                if (isset($array[$field]) && !empty($array[$field])) {
                    $array[$field] = $localUrl;
                }
            }
            
            // 为了让前端拿到干净的 JSON，剔除内部的 coverImage 对象结构
            unset($array['coverImage']); 
        }

        return $array;
    }
}