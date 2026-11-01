{{--
    صفحةٌ قانونيّة لمتجرٍ بلا واجهةٍ خاصّة — البسيطةُ والمبنيّة في البانِي معًا.

    صفحةٌ مستقلّةٌ خفيفة: اسمُ المتجر يعود إلى رئيسيّته، وعنوانُ الصفحة، ثمّ
    النصُّ الذي نشره صاحبُه (`Store\StoreLegal`) مُهرَّبًا، وروابطُ الصفحتين
    الأخريين واللغةُ الأخرى. وتُقرأ على الهاتف واللوحيّ والحاسوب: عمودٌ واحد
    بعرضٍ مقروء، ولا تمريرَ أفقيّ.
--}}
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $lang === 'en' ? 'ltr' : 'rtl' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — {{ $business->name }}</title>
    @if ($canonical)
        <link rel="canonical" href="{{ $canonical }}">
    @endif
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #fafafa; color: #1f2933; font: 16px/1.85 system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif; }
        .wrap { max-width: 760px; margin: 0 auto; padding: 0 20px; }
        header { background: #fff; border-bottom: 1px solid #e5e7eb; }
        header .wrap { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 60px; flex-wrap: wrap; }
        header a { color: inherit; text-decoration: none; font-weight: 600; min-height: 44px; display: flex; align-items: center; }
        .lang { font-weight: 400; font-size: 14px; color: #4b5563; }
        main { padding: 32px 0 48px; }
        h1 { font-size: clamp(24px, 4vw, 32px); line-height: 1.35; margin: 0 0 6px; }
        .updated { color: #6b7280; font-size: 13px; margin: 0 0 24px; }
        h2 { font-size: 19px; margin: 28px 0 8px; }
        p, ul { margin: 0 0 14px; } ul { padding-inline-start: 22px; } li { margin: 4px 0; }
        article { overflow-wrap: anywhere; }
        article a { color: #1d4ed8; }
        footer { border-top: 1px solid #e5e7eb; padding: 20px 0 32px; font-size: 14px; color: #6b7280; }
        footer nav { display: flex; flex-wrap: wrap; gap: 0 18px; }
        footer a { color: #374151; min-height: 44px; display: inline-flex; align-items: center; }
    </style>
</head>
<body>
<header>
    <div class="wrap">
        <a href="{{ $home }}" data-testid="legal-home">{{ $business->name }}</a>
        <a class="lang" href="?lang={{ $lang === 'en' ? 'ar' : 'en' }}" data-testid="legal-lang">{{ $lang === 'en' ? 'العربية' : 'English' }}</a>
    </div>
</header>
<main class="wrap" data-testid="legal-page">
    <h1 data-testid="legal-title">{{ $title }}</h1>
    @if ($updated)
        <p class="updated" data-testid="legal-updated">{{ $lang === 'en' ? 'Last updated' : 'آخر تحديث' }}: <bdi dir="ltr">{{ $updated }}</bdi></p>
    @endif
    <article data-testid="legal-body">
        @foreach ($blocks as $block)
            @if ($block['type'] === 'h')
                <h2>{{ $block['text'] }}</h2>
            @elseif ($block['type'] === 'ul')
                <ul>
                    @foreach ($block['items'] as $item)
                        <li>{{ \App\Support\Store\StoreLegal::inline($item) }}</li>
                    @endforeach
                </ul>
            @else
                <p>{{ \App\Support\Store\StoreLegal::inline($block['text']) }}</p>
            @endif
        @endforeach
    </article>
</main>
<footer>
    <div class="wrap">
        <nav aria-label="{{ $lang === 'en' ? 'Legal' : 'الصفحات القانونية' }}" data-testid="legal-links">
            @foreach ($links as $link)
                <a href="{{ $link['href'] }}{{ $lang === 'en' ? '?lang=en' : '' }}" data-testid="legal-link-{{ $link['key'] }}">{{ $link['label'] }}</a>
            @endforeach
        </nav>
    </div>
</footer>
</body>
</html>
