<?php

namespace App\Services;

use App\Models\Setting;

/**
 * 系统设置读写服务（settings 表，key/value JSON 编码存储）。
 *
 * 说明：get() 读不到时返回 null（调用方/控制器 presets() 提供默认值，保证 API 形状稳定）；
 * put() 走 updateOrCreate 幂等写入。定时任务配置等复合配置以 JSON 数组整体存取。
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
