{{--
    بوابةُ عملاء RIBBON — صفحةٌ قائمةٌ بذاتها.

    لا ترويسةَ متجرٍ ولا سلّةَ ولا أصولَ لوحة: ورقةُ أنماطٍ ونصٌّ صغيرٌ برمزه (`nonce`)
    وخطُّ الهويّة من المضيف نفسه. ولا أداةَ قياسٍ ولا رابطَ خارجيًّا يحمل ما في العنوان.
--}}
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <meta name="referrer" content="no-referrer">
    <meta name="format-detection" content="telephone=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#f7f2ec">
    <title>{{ $t('title') }} — {{ $shop }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ \App\Support\Store\StoreAsset::url('/brand/ribbon/favicon.svg') }}">
    <link rel="apple-touch-icon" href="{{ \App\Support\Store\StoreAsset::url('/brand/ribbon/apple-touch-icon.png') }}">
    <link rel="stylesheet" href="{{ \App\Support\Store\StoreAsset::url('/fonts/ibm-plex-arabic.css') }}">
    <style>
        /* ألوانُ دليل هويّة RIBBON كما في متجره: الأخضرُ #58563C والكريميّ #F7F2EC */
        :root { --olive: #58563c; --olive-d: #474530; --cream: #f7f2ec; --soft: #efe8de; --line: #e4dcd1; --ink: #2b2a20; --muted: #7b776a; --wine: #5f2832; --card: #fffdfa; }
        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body { margin: 0; min-height: 100vh; min-height: 100dvh; display: flex; flex-direction: column; background: var(--cream); color: var(--ink); font: 400 15px/1.65 'IBM Plex Sans Arabic', system-ui, -apple-system, 'Segoe UI', Tahoma, Arial, sans-serif; -webkit-font-smoothing: antialiased; }
        a { color: inherit; } button, input { font: inherit; color: inherit; }
        .wrap { width: 100%; max-width: 980px; margin: 0 auto; padding: 0 18px; }
        .top { padding: 14px 0 10px; border-bottom: 1px solid var(--line); background: var(--cream); }
        .top .wrap { display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 10px; }
        .top .end { display: flex; justify-content: flex-end; gap: 8px; }
        .logo { display: block; height: 58px; width: auto; max-width: 210px; object-fit: contain; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 42px; padding: 0 16px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--olive); text-decoration: none; cursor: pointer; font-size: 14px; font-weight: 500; transition: background .15s, border-color .15s; white-space: nowrap; }
        .btn:hover { background: var(--soft); } .btn:disabled { opacity: .55; cursor: default; }
        .btn.main { background: var(--olive); border-color: var(--olive); color: var(--cream); } .btn.main:hover { background: var(--olive-d); }
        .btn.text { border-color: transparent; background: transparent; padding: 0 8px; }
        .btn.sm { min-height: 38px; padding: 0 12px; font-size: 13px; }
        @media (pointer: coarse) { .btn, .btn.sm { min-height: 44px; } }
        :focus-visible { outline: 2px solid var(--olive); outline-offset: 2px; }
        main { flex: 1; padding: 22px 0 34px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 20px; padding: 20px; }
        .muted { color: var(--muted); } .sm { font-size: 13px; } .num { direction: ltr; unicode-bidi: isolate; display: inline-block; font-variant-numeric: tabular-nums; }
        h1 { font-size: 24px; line-height: 1.35; margin: 0 0 4px; font-weight: 600; color: var(--olive-d); } h2 { font-size: 17px; margin: 0; font-weight: 600; color: var(--olive-d); }
        input[type=text], input[type=tel], input[type=search], input[type=date] { width: 100%; min-height: 44px; padding: 0 14px; border: 1px solid var(--line); border-radius: 12px; background: #fff; font-size: 16px; }
        input:focus { outline: 2px solid var(--olive); outline-offset: 0; border-color: var(--olive); }
        .err { color: var(--wine); font-size: 14px; margin: 10px 0 0; }
        footer { padding: 18px 0 26px; text-align: center; color: var(--muted); font-size: 11px; letter-spacing: .04em; }
        [hidden] { display: none !important; }
        .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
        @media (max-width: 560px) { .logo { height: 46px; max-width: 150px; } .top .btn { padding: 0 10px; } h1 { font-size: 21px; } .card { padding: 16px; border-radius: 18px; } }
        @yield('style')
    </style>
</head>
<body>
    <header class="top">
        <div class="wrap">
            <div>@yield('start')</div>
            <img class="logo" src="{{ route('my.logo', [], false) }}" alt="{{ $shop }}" data-testid="portal-logo">
            <div class="end">
                <a class="btn sm text" href="{{ route('my.index', ['lang' => $lang === 'en' ? 'ar' : 'en'], false) }}" data-testid="portal-lang">{{ $t('langBtn') }}</a>
                @yield('end')
            </div>
        </div>
    </header>
    <main><div class="wrap">@yield('main')</div></main>
    <footer>{{ $t('poweredBy') }}</footer>
    <script nonce="{{ $nonce }}">
        // خروجٌ من صفحةٍ بقيت مفتوحةً ساعات: رمزُها قدُم، فتُجدَّد ولا تُعرض صفحةُ خطأ
        document.querySelectorAll('form[data-leave]').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                fetch(form.action, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } })
                    .then(function (r) { if (r.ok) location.replace(location.pathname); else location.reload(); })
                    .catch(function () { form.submit(); });
            });
        });
    </script>
    @yield('script')
</body>
</html>
