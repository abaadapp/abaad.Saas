{{--
    متجرُ التاجر — الصفحة التي يفتحها الزبون.

    وقالبٌ واحد لا خمسة: الثيمة ألوانٌ تُبدَّل في متغيّرات CSS، لا نسخةٌ من
    الصفحة لكلّ ذوق. خمسُ نسخٍ تعني أنّ إصلاح عطبٍ في واحدةٍ يُنسى في أربع.

    والصفحة تعمل بلا JavaScript إلّا لتصفية الأقسام: زبونٌ على شبكةٍ ضعيفة
    يرى المنتجات والأسعار وزرَّ الطلب كاملةً قبل أن يصل سطرُ سكربت.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    <title>{{ $headline }}</title>
    <meta name="description" content="{{ Str::limit($about !== '' ? $about : $headline, 155) }}">

    {{-- الأصل هو النطاق الفرعيّ: لا يقرأ محرّكُ البحث نسختين لصفحةٍ واحدة --}}
    @if ($url)
        <link rel="canonical" href="{{ $url }}">
    @endif

    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $headline }}">
    <meta property="og:description" content="{{ Str::limit($about !== '' ? $about : $headline, 155) }}">
    @if ($logo)
        <meta property="og:image" content="{{ $logo }}">
    @endif

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --accent: {{ $theme['accent'] }};
            --soft: {{ $theme['soft'] }};
            --ink: {{ $theme['ink'] }};
            --line: #e9e9e9;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'IBM Plex Sans Arabic', system-ui, sans-serif;
            color: #111;
            background: #fff;
            -webkit-text-size-adjust: 100%;
        }
        a { color: inherit; }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 0 16px; }

        /* الترويسة */
        header.hero { background: var(--soft); border-bottom: 1px solid var(--line); }
        .hero-in { display: flex; align-items: center; gap: 16px; padding: 28px 0; }
        .logo { width: 72px; height: 72px; border-radius: 18px; object-fit: contain;
                background: #fff; border: 1px solid var(--line); flex-shrink: 0; }
        .hero h1 { margin: 0; font-size: 24px; line-height: 1.3; color: var(--ink); }
        .hero p { margin: 6px 0 0; font-size: 14px; color: #555; max-width: 60ch; }
        .meta { margin-top: 10px; display: flex; flex-wrap: wrap; gap: 8px 16px; font-size: 13px; color: #666; }

        /* الأقسام */
        nav.cats { display: flex; gap: 8px; overflow-x: auto; padding: 14px 0; }
        nav.cats button {
            font: inherit; font-size: 14px; white-space: nowrap; cursor: pointer;
            border: 1px solid var(--line); background: #fff; color: #444;
            border-radius: 999px; padding: 9px 16px; min-height: 44px;
        }
        nav.cats button[aria-pressed="true"] { background: var(--accent); border-color: var(--accent); color: #fff; }

        /* الشبكة */
        .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; padding-bottom: 32px; }
        @media (min-width: 700px) { .grid { grid-template-columns: repeat(3, 1fr); } }
        @media (min-width: 1000px) { .grid { grid-template-columns: repeat(4, 1fr); } }

        .card { border: 1px solid var(--line); border-radius: 16px; overflow: hidden; background: #fff; display: flex; flex-direction: column; }
        .shot { aspect-ratio: 1 / 1; background: var(--soft); display: flex; align-items: center; justify-content: center; }
        .shot img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .shot span { font-size: 34px; }
        .body { padding: 12px; display: flex; flex-direction: column; gap: 6px; flex: 1; }
        .body h3 { margin: 0; font-size: 15px; font-weight: 600; }
        .body .desc { margin: 0; font-size: 12px; color: #777; line-height: 1.5; }
        .price { font-size: 16px; font-weight: 700; color: var(--accent); }
        .out { font-size: 12px; color: #b91c1c; font-weight: 600; }

        .order {
            margin-top: auto; display: block; text-align: center; text-decoration: none;
            background: var(--accent); color: #fff; font-weight: 600; font-size: 14px;
            border-radius: 999px; padding: 11px 12px; min-height: 44px; line-height: 22px;
        }
        .order[aria-disabled="true"] { background: #e5e5e5; color: #999; pointer-events: none; }

        /* الدفع والتواصل */
        section.pay { background: var(--soft); border-top: 1px solid var(--line); padding: 28px 0; }
        section.pay h2 { margin: 0 0 12px; font-size: 17px; color: var(--ink); }
        .pill { display: inline-block; background: #fff; border: 1px solid var(--line);
                border-radius: 999px; padding: 8px 14px; font-size: 13px; margin: 0 0 8px 8px; }
        .bank { margin: 10px 0 0; font-size: 13px; color: #444; white-space: pre-line; line-height: 1.7; }

        footer { padding: 22px 0 34px; font-size: 12px; color: #999; text-align: center; }
        footer a { color: #666; }
        .empty { padding: 60px 0; text-align: center; color: #888; }
    </style>
</head>
<body>

<header class="hero">
    <div class="wrap hero-in">
        @if ($logo)
            <img class="logo" src="{{ $logo }}" alt="{{ $business->name }}">
        @endif
        <div>
            <h1>{{ $headline }}</h1>
            @if ($about !== '')
                <p>{{ $about }}</p>
            @endif
            <div class="meta">
                @if ($address)<span>📍 {{ $address }}</span>@endif
                @if ($phone)<span dir="ltr">📞 {{ $phone }}</span>@endif
            </div>
        </div>
    </div>
</header>

<main class="wrap">
    @if (count($products) === 0)
        {{-- ولا صفحةٌ تدّعي متجرًا بلا بضاعة: الزائر يُصرَّح له بالحقيقة --}}
        <p class="empty">لا توجد منتجات معروضة حاليًا.</p>
    @else
        @if (count($categories) > 0)
            <nav class="cats" id="cats">
                <button type="button" data-cat="all" aria-pressed="true">الكل</button>
                @foreach ($categories as $c)
                    <button type="button" data-cat="{{ $c['id'] }}" aria-pressed="false">{{ $c['name'] }}</button>
                @endforeach
            </nav>
        @endif

        <div class="grid" id="grid">
            @foreach ($products as $p)
                <article class="card" data-cat="{{ $p['category_id'] ?? 'none' }}">
                    <div class="shot">
                        @if ($p['image'])
                            <img src="{{ $p['image'] }}" alt="{{ $p['name'] }}" loading="lazy">
                        @else
                            <span>🌷</span>
                        @endif
                    </div>
                    <div class="body">
                        <h3>{{ $p['name'] }}</h3>
                        @if ($p['description'])
                            <p class="desc">{{ Str::limit($p['description'], 80) }}</p>
                        @endif
                        @if ($showPrices && $p['price'] !== null)
                            <div class="price" dir="ltr">{{ number_format($p['price'], 3) }} ر.ع</div>
                        @endif
                        @unless ($p['available'])
                            <div class="out">غير متوفّر حاليًا</div>
                        @endunless

                        {{-- زرٌّ بلا رقمٍ يفتح محادثةً بلا مستقبِل، فلا يُرسم أصلًا --}}
                        @if ($p['order_url'])
                            <a class="order" href="{{ $p['order_url'] }}" target="_blank" rel="noopener"
                               @unless ($p['available']) aria-disabled="true" @endunless>
                                اطلب عبر واتساب
                            </a>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</main>

@if ($cod || $transfer)
    <section class="pay">
        <div class="wrap">
            <h2>طريقة الدفع</h2>
            @if ($cod)<span class="pill">💵 الدفع عند الاستلام</span>@endif
            @if ($transfer)<span class="pill">🏦 تحويل بنكي</span>@endif
            @if ($transfer && $bank !== '')
                <p class="bank">{{ $bank }}</p>
            @endif
        </div>
    </section>
@endif

<footer>
    <div class="wrap">
        {{ $business->name }}
        @if ($whatsapp)
            · <a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener">تواصل معنا</a>
        @endif
    </div>
</footer>

@if (count($categories) > 0)
<script>
    /* تصفيةٌ في المتصفّح: الصفحة وصلت كاملةً، فلا رحلةَ خادمٍ لتبديل قسم */
    document.getElementById('cats').addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        var pick = btn.dataset.cat;
        this.querySelectorAll('button').forEach(function (b) {
            b.setAttribute('aria-pressed', String(b === btn));
        });
        document.querySelectorAll('#grid .card').forEach(function (card) {
            card.style.display = (pick === 'all' || card.dataset.cat === pick) ? '' : 'none';
        });
    });
</script>
@endif

</body>
</html>
