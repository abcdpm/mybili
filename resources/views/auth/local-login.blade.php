<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mybili · 登录</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: -apple-system, 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif;
            padding: 16px;
        }
        .card {
            width: 100%; max-width: 380px;
            background: rgba(255, 255, 255, 0.96);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
            padding: 36px 32px;
        }
        .logo {
            width: 52px; height: 52px; border-radius: 14px;
            background: linear-gradient(135deg, #a855f7, #ec4899);
            display: flex; align-items: center; justify-content: center;
            font-size: 26px; margin: 0 auto 14px;
        }
        h1 { font-size: 20px; text-align: center; color: #1f2937; margin-bottom: 6px; }
        .sub { text-align: center; color: #9ca3af; font-size: 12.5px; margin-bottom: 24px; }
        label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
        input[type="password"] {
            width: 100%; padding: 12px 14px;
            border: 1.5px solid #e5e7eb; border-radius: 12px;
            font-size: 15px; outline: none;
            transition: border-color .2s, box-shadow .2s;
        }
        input[type="password"]:focus {
            border-color: #a855f7;
            box-shadow: 0 0 0 3px rgba(168, 85, 247, 0.15);
        }
        button {
            width: 100%; margin-top: 18px; padding: 12px;
            border: none; border-radius: 12px;
            background: linear-gradient(135deg, #a855f7, #ec4899);
            color: #fff; font-size: 15px; font-weight: 600;
            cursor: pointer; transition: transform .15s, box-shadow .15s;
        }
        button:hover { transform: translateY(-1px); box-shadow: 0 10px 25px rgba(168, 85, 247, 0.35); }
        .error {
            margin-top: 14px; padding: 10px 12px;
            background: #fef2f2; border: 1px solid #fecaca;
            color: #b91c1c; border-radius: 10px; font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">🎬</div>
        <h1>Mybili</h1>
        <p class="sub">应急本地登录</p>

        <form method="POST" action="{{ $action ?? '/auth/local' }}">
            @csrf
            <label for="password">访问密码</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
            <button type="submit">登 录</button>
        </form>

        @if ($error ?? false)
            <div class="error">密码不正确，请重试。</div>
        @endif
    </div>
</body>
</html>