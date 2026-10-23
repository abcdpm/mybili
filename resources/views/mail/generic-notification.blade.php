<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>{{ $subject }}</title>
</head>
<body style="font-family: -apple-system, 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif; background: #f6f7f9; padding: 24px;">
    <div style="max-width: 640px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #e5e7eb; overflow: hidden;">
        <div style="background: linear-gradient(90deg, #a855f7, #ec4899); padding: 16px 24px;">
            <h1 style="color: #ffffff; font-size: 18px; margin: 0;">{{ $subject }}</h1>
        </div>
        <div style="padding: 24px; color: #374151; font-size: 14px; line-height: 1.8;">
            {!! nl2br(e($body)) !!}
        </div>
        <div style="padding: 12px 24px; background: #f9fafb; color: #9ca3af; font-size: 12px;">
            本邮件由 mybili 自动发送
        </div>
    </div>
</body>
</html>