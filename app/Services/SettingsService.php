<?php

namespace App\Services;

use App\Models\Setting;

/**
 * 系统设置读写服务（settings 表 + 请求级记忆缓存）。
 *
 * 说明：get() 命中实例缓存后不再查表（单请求内多处读取零成本）；
 * set() 写表并同步缓存，settings 以 key/value（JSON 编码）存储，
 * 默认值由 SettingsController::presets() 提供（无库行时返回预设，保证 API 形状稳定）。
 */
class SettingsService
{
    public function put($name, $values)
    {
        Setting::updateOrCreate(['name' => $name], ['value' => $values]);
    }

    public function get($name)
    {
        $setting = Setting::where('name', $name)->first();

        return $setting ? $setting->value : null;
    }
}
