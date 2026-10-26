<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" href="{{ asset('assets/images/favicon.svg') }}">

        <title>Mybili</title>
        {{-- 子路径部署（如 /mybili）前缀：前端 withBase/拦截器据此补前缀；
             根部署时为空串，前端全部原样透传（零行为变化）。@json 防注入 --}}
        <script>window.__MYBILI_BASE__ = @json(\App\Services\SsoUrl::base(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);</script>
        @vite(['resources/css/app.css', 'resources/js/main.ts'])
        @if(config('app.website_id') && usage_analytics_enabled())
            <script defer src="https://cloud.umami.is/script.js" data-website-id="{{ config('app.website_id') }}"></script>
        @endif
    </head>
    <body>
        <div id="app">
        </div>
    </body>
</html>
