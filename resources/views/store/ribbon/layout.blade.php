{{--
    واجهةُ RIBBON — الهيكلُ المشترك: الترويسةُ والتذييلُ والسلّةُ في المتصفّح.

    التصميمُ تصميمُ صاحب المتجر (ملفّ «متجر RIBBON الإلكتروني») بألوانه:
    زيتونيٌّ داكن للترويسة والأزرار، وكريميٌّ للصفحة. والسلّةُ في `localStorage`
    باسم المتجر، وتُسعَّر دائمًا من الخادم (`/quote`) — لا رقمَ يُحسب هنا.
--}}
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', $seo['title'])</title>
    <meta name="description" content="{{ $seo['description'] }}">
    {{--
        وإذنُ الفهرسة يكتبه صاحبُ المتجر من «الظهور في البحث».

        ومن يجرّب متجره على عنوانٍ حقيقيّ لا يريده في نتائج غوغل بعد،
        وإخفاؤه بإطفاء النشر يُغلقه على زبائنه أيضًا — وهذان سؤالان لا سؤال.
    --}}
    @unless ($seo['index'])
        <meta name="robots" content="noindex, nofollow" data-testid="rb-noindex">
    @endunless
    {{--
        والعنوانُ الأصليُّ يُحسب في الخادم — انظر `Store\StoreSeo::canonical`.

        وكان يُركَّب هنا بشرطٍ على `$base`، فكانت كلُّ صفحةٍ على الطريق
        البديل تقول لغوغل إنّها نسخةٌ من الرئيسية.
    --}}
    @if ($seo['canonical'])
        <link rel="canonical" href="{{ $seo['canonical'] }}">
        {{--
            والعربيّةُ والإنجليزيّةُ ترجمتان لا نسختان.

            الصفحتان على العنوان نفسِه ويفرّق بينهما `‎?lang‎`، وأصلُهما
            واحدٌ في `canonical`. فبلا هذين السطرين يقرأ غوغل الإنجليزيّةَ
            نسخةً مكرَّرةً ويُسقطها — ولا يجدها من يبحث بالإنجليزيّة.
        --}}
        <link rel="alternate" hreflang="ar" href="{{ $seo['canonical'] }}">
        <link rel="alternate" hreflang="en" href="{{ $seo['canonical'] }}?lang=en">
        <link rel="alternate" hreflang="x-default" href="{{ $seo['canonical'] }}">
    @endif
    @if ($logo)
        <meta property="og:image" content="{{ $logo }}">
    @endif
    {{-- أيقونةُ المتصفّح من دليل الهوية — لسانُ الزبون يحمل علامةَ المتجر --}}
    <link rel="icon" type="image/svg+xml" href="{{ \App\Support\Store\StoreAsset::url('/brand/ribbon/favicon.svg') }}">
    <link rel="apple-touch-icon" href="{{ \App\Support\Store\StoreAsset::url('/brand/ribbon/apple-touch-icon.png') }}">
    <meta name="theme-color" content="#58563C">
    <link rel="stylesheet" href="{{ \App\Support\Store\StoreAsset::url('/fonts/ibm-plex-arabic.css') }}">
    <style>
        :root {
            /*
             * الألوانُ من دليل هوية Ribbon بالحرف — لا مقرَّبةً ولا مُستخرَجةً
             * من صورة: الأخضر `#58563C` والكريميّ `#F7F2EC` كما في ملفّ الهوية.
             * وما عداهما مشتقٌّ منهما: الأخضرُ الداكن للضغط، وكريميٌّ أعمق
             * قليلًا لمواضع الصور، وخطوطٌ دافئةٌ تتبع الكريميّ لا الرماديّ.
             */
            --rb-olive: #58563c; --rb-olive-dark: #474530; --rb-cream: #f7f2ec; --rb-bg: #f7f2ec; --rb-soft: #efe8de; --rb-line: #e4dcd1; --rb-border: #d8cfc2; --rb-err: #5f2832;

            /*
             * الحواف — ثلاثةُ مقاديرَ لا رقمٌ في كلّ موضع.
             *
             * كانت الأرقامُ مبعثرةً بين ٤ و٦ و٨ وصفرٍ في أزرارٍ بعينها، فيقف
             * الزرُّ المربّع إلى جانب الحقل المستدير في الشاشة نفسِها. وثلاثةُ
             * رموزٍ تجعل الاستدارةَ صفةً للواجهة كلِّها: ما صغر (بحثٌ وأزرارُ
             * ترويسةٍ ومصغَّرات) و«ما يُلمس» (أزرارٌ وحقول) و«ما يحمل» (صورٌ
             * وبطاقاتٌ وألواح). وتبديلُ الاستدارة كلِّها بعدها ثلاثةُ أسطر.
             *
             * والحبّةُ والدائرةُ تبقيان على حالهما: `999px` شكلٌ لا مقدار.
             */
            --rb-r-sm: 10px; --rb-r: 14px; --rb-r-lg: 20px;
        }
        * { box-sizing: border-box; }
        /*
         * والخطُّ يُوضع على الجذر لا على `body` وحده.
         *
         * خطُّ التصميم `IBM Plex Sans Arabic`، ويُخدم من ملفّات المتجر نفسِه
         * (`/fonts/ibm-plex-arabic.css`) لا من شبكةٍ خارجيّة: صفحةُ التاجر لا
         * تنتظر خادمَ غيرِنا لتُقرأ، ولا تُسرّب زائرَه إليه. ووضعُه على `html`
         * يجعل كلَّ ما يُرسم داخلَه — حتى ما يضيفه المتصفّح من حقولٍ أصليّة —
         * يرثه، فلا يبقى في الصفحة موضعٌ يسقط إلى Times.
         */
        /*
         * وأوّلُ السلسلة خطُّ الهوية `GE Hili` — واسمًا لا ملفًّا.
         *
         * حزمةُ الهوية لا تحمل ملفّاته، ورخصتُه لا تُجيز استخراجَه من ملفّ
         * الدليل. فيُذكر أوّلًا ليتولّى يومَ تُضاف ملفّاتُه المرخَّصة، ويقع
         * اليومَ على `IBM Plex Sans Arabic` المخزَّن عندنا.
         */
        html { font-family: 'GE Hili', 'IBM Plex Sans Arabic', 'Noto Kufi Arabic', system-ui, Arial, sans-serif; }
        body { margin: 0; background: var(--rb-bg); color: #000; font-family: 'GE Hili', 'IBM Plex Sans Arabic', 'Noto Kufi Arabic', system-ui, Arial, sans-serif; -webkit-font-smoothing: antialiased; min-height: 100vh; display: flex; flex-direction: column; }
        a { color: #000; text-decoration: none; }
        input, select, textarea, button { font-family: inherit; }
        input:focus, select:focus, textarea:focus { outline: 2px solid var(--rb-olive); outline-offset: 0; }
        main { flex: 1; overflow-x: hidden; }
        .rb-wrap { max-width: 1280px; margin: 0 auto; padding: 0 24px; box-sizing: border-box; }
        @keyframes rb-fade { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
        .rb-screen { animation: rb-fade .3s ease; }

        /* الترويسة — بحثٌ يسارًا وشعارٌ وسطًا وأزرارٌ يمينًا؛ وعلى الهاتف صفٌّ للأزرار وصفٌّ للبحث */
        header.rb-head { background: var(--rb-olive); position: sticky; top: 0; z-index: 20; }
        .rb-head-in { max-width: 1280px; margin: 0 auto; padding: 18px 24px; display: grid; grid-template-columns: minmax(0,1fr) auto minmax(0,1fr); grid-template-areas: "search logo right"; align-items: center; gap: 24px; min-height: 90px; }
        .rb-logo { grid-area: logo; display: block; } .rb-logo img { width: clamp(150px, 18vw, 240px); height: auto; display: block; margin: 0 auto; }
        .rb-search { grid-area: search; display: flex; align-items: center; gap: 8px; border: 1px solid rgba(239,234,219,.45); padding: 0 14px; height: 46px; max-width: 340px; border-radius: var(--rb-r); margin: 0; }
        .rb-search span { width: 14px; height: 14px; border: 1.5px solid var(--rb-cream); border-radius: 50%; flex: none; }
        .rb-search input { border: 0; background: transparent; width: 100%; font-size: 14px; color: var(--rb-cream); }
        .rb-search input::placeholder { color: rgba(239,234,219,.7); } .rb-search input:focus { outline: none; }
        .rb-right { grid-area: right; display: flex; justify-content: flex-end; align-items: center; gap: 12px; font-size: 14px; }
        .rb-hbtn { border: 1px solid rgba(239,234,219,.45); background: transparent; height: 44px; padding: 0 14px; font-size: 13px; cursor: pointer; color: var(--rb-cream); border-radius: var(--rb-r-sm); display: inline-flex; align-items: center; gap: 8px; }
        .rb-hbtn:hover { background: rgba(239,234,219,.12); }
        .rb-count { background: var(--rb-cream); color: var(--rb-olive); border-radius: 999px; min-width: 22px; height: 22px; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; padding: 0 6px; }
        @media (max-width: 1023px) {
            .rb-head-in { grid-template-columns: minmax(0,1fr) auto minmax(0,1fr); grid-template-areas: "lang logo cart" "search search search"; padding: 12px 16px 14px; gap: 12px 8px; min-height: 0; }
            .rb-right { display: contents; }
            .rb-lang { grid-area: lang; justify-self: start; } .rb-cartbtn { grid-area: cart; justify-self: end; }
            /* وفراغٌ حول الشعار على الهاتف: بين زرّ السلّة وزرّ اللغة مساحةٌ ضيّقة */
            .rb-logo img { width: clamp(118px, 29vw, 190px); }
            .rb-search { max-width: none; }
        }

        /* قائمةُ الصفحات — شريطٌ تحت الترويسة، بحروف الواجهة ومسافاتها */
        .rb-nav { border-top: 1px solid rgba(239,234,219,.18); }
        .rb-nav-in { max-width: 1280px; margin: 0 auto; padding: 0 24px; display: flex; flex-wrap: wrap; justify-content: center; gap: 4px 28px; }
        .rb-nav-a { color: rgba(239,234,219,.82); font-size: 13px; letter-spacing: .14em; min-height: 46px; display: inline-flex; align-items: center; border-bottom: 1px solid transparent; }
        .rb-nav-a:hover { color: var(--rb-cream); }
        .rb-nav-a.is-on { color: var(--rb-cream); border-bottom-color: var(--rb-cream); }
        @media (max-width: 1023px) { .rb-nav-in { padding: 0 16px; gap: 2px 18px; } .rb-nav-a { font-size: 12px; min-height: 42px; } }

        /* بطاقاتُ «تواصل معنا» — بإطار الصفحة ولونها، لا بلونٍ جديد */
        .rb-cbox { border: 1px solid var(--rb-border); border-radius: var(--rb-r); padding: 18px 20px; display: flex; flex-direction: column; gap: 6px; background: #fff; }
        .rb-clabel { font-size: 12px; letter-spacing: .18em; color: #6b6a55; }
        .rb-cbox a { color: #000; font-size: 16px; min-height: 44px; display: inline-flex; align-items: center; }
        .rb-cbox a:hover { text-decoration: underline; }
        .rb-cbox span:not(.rb-clabel) { font-size: 16px; line-height: 1.7; }

        /* أزرار */
        .rb-btn { height: 50px; padding: 0 28px; border: 0; background: var(--rb-olive); color: var(--rb-cream); font-size: 15px; cursor: pointer; border-radius: var(--rb-r); display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
        .rb-btn:hover { background: var(--rb-olive-dark); } .rb-btn[disabled] { opacity: .5; cursor: not-allowed; }
        .rb-btn-ghost { height: 50px; padding: 0 28px; border: 1px solid var(--rb-olive); background: transparent; color: #000; font-size: 15px; cursor: pointer; border-radius: var(--rb-r); display: inline-flex; align-items: center; justify-content: center; }
        .rb-btn-ghost:hover { background: #e8e4d6; }
        .rb-pill { border: 1px solid var(--rb-border); background: #fff; color: #000; padding: 10px 18px; font-size: 13px; cursor: pointer; border-radius: 999px; }
        .rb-pill.on { border-color: var(--rb-olive); background: var(--rb-olive); color: #fff; }
        .rb-input { height: 46px; border: 1px solid var(--rb-border); background: #fff; padding: 0 14px; font-size: 14px; width: 100%; color: #000; border-radius: var(--rb-r); }
        .rb-input.err { border-color: var(--rb-err); }
        textarea.rb-input { height: auto; padding: 12px 14px; resize: vertical; border-radius: var(--rb-r); }
        .rb-error { color: var(--rb-err); font-size: 13px; margin-top: 6px; }
        /*
            تنبيهُ الصورة — تعليقٌ لا تحذير.

            بلا إطارٍ ولا أيقونةٍ ولا خلفيّةٍ صفراء: صندوقُ إنذارٍ فوق باقةٍ
            يجعلها تبدو معيبةً قبل أن تُشترى. ومكتوبٌ مرّةً هنا لأنّه يظهر في
            ثلاث صفحات — ولو كُتب في كلٍّ منها لَافترق شكلُه يوم يُبدَّل.
        */
        .rb-note { margin: 12px 0 0; font-size: 13px; line-height: 1.8; color: #6b655c; text-wrap: pretty; }

        /* شبكة الأصناف */
        .rb-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 230px), 1fr)); gap: 28px 24px; }
        .rb-card { display: block; color: inherit; }
        .rb-card .rb-img { aspect-ratio: 1/1; border-radius: var(--rb-r-lg); overflow: hidden; background: var(--rb-soft); display: flex; align-items: center; justify-content: center; }
        .rb-card .rb-img img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .3s ease; }
        .rb-card:hover .rb-img img { transform: scale(1.03); }
        .rb-card .rb-name { margin-top: 12px; font-size: 15px; text-align: center; }
        .rb-card .rb-price { margin-top: 4px; font-size: 17px; font-weight: 600; text-align: center; }
        .rb-stripes { background: repeating-linear-gradient(135deg, var(--tint, #e6dcc8) 0 12px, #f3efe9 12px 24px); width: 100%; height: 100%; }
        .rb-h2 { margin: 0; font-size: clamp(24px, 2.6vw, 32px); font-weight: 500; }
        .rb-h1 { margin: 0; font-size: 30px; font-weight: 500; }
        .rb-section { max-width: 1280px; margin: 0 auto; padding: clamp(48px, 6vw, 80px) 24px 0; box-sizing: border-box; }
        .rb-section-head { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; margin-bottom: 28px; }
        .rb-link { font-size: 14px; text-decoration: underline; text-underline-offset: 4px; text-decoration-color: #c4bfa6; min-height: 44px; display: inline-flex; align-items: center; }
        .rb-box { background: #fff; border: 1px solid var(--rb-line); border-radius: var(--rb-r-lg); padding: 24px; }
        .rb-qty { display: inline-flex; align-items: center; border: 1px solid var(--rb-border); background: #fff; border-radius: var(--rb-r); overflow: hidden; }
        .rb-qty button { border: 0; background: transparent; width: 44px; height: 48px; font-size: 18px; cursor: pointer; color: #000; }
        .rb-qty span { min-width: 32px; text-align: center; font-size: 15px; }

        /* التذييل */
        footer.rb-foot { background: var(--rb-olive); color: var(--rb-cream); margin-top: clamp(48px, 6vw, 80px); }
        .rb-foot-in { max-width: 1280px; margin: 0 auto; padding: clamp(40px, 5vw, 64px) 24px 32px; }
        .rb-foot-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr)); gap: 36px 32px; }
        .rb-foot h3 { margin: 0 0 14px; font-size: 15px; font-weight: 500; }
        .rb-foot a, .rb-foot p, .rb-foot span { color: #d9d4c0; font-size: 14px; }
        .rb-foot a { min-height: 44px; display: flex; align-items: center; } .rb-foot a:hover { color: #fff; }
        .rb-social a { height: 44px; min-width: 44px; padding: 0 14px; border: 1px solid #8b8968; border-radius: var(--rb-r-sm); display: inline-flex; align-items: center; justify-content: center; color: var(--rb-cream); font-size: 13px; }
        .rb-social a:hover { background: #6b694c; }
        .rb-foot-bottom { border-top: 1px solid #77754f; margin-top: 40px; padding-top: 20px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 12px; font-size: 12px; color: #c4bfa6; }
        .rb-toast { position: fixed; bottom: 24px; inset-inline-start: 50%; transform: translateX(-50%); background: #111; color: #fff; padding: 12px 20px; border-radius: 999px; font-size: 14px; opacity: 0; pointer-events: none; transition: opacity .2s; z-index: 50; }
        .rb-toast.show { opacity: 1; }
    </style>
    {!! $analytics ?? '' !!}
</head>
<body>
<header class="rb-head">
    <div class="rb-head-in">
        <form class="rb-search" action="{{ $base }}/shop" method="get" role="search">
            <span></span>
            <input name="q" placeholder="{{ $t['search'] }}" value="{{ request()->query('q', '') }}" aria-label="{{ $t['search'] }}">
        </form>
        {{-- الشعارُ ملفٌّ متّجه من دليل الهوية — حادٌّ في كلّ مقاسٍ وشاشة،
             وبنسخته الكريمية لأنّ الترويسة زيتونيّةٌ داكنة --}}
        <a href="{{ $base }}/" class="rb-logo" aria-label="{{ $business->name }}"><img src="{{ \App\Support\Store\StoreAsset::url('/brand/ribbon/logo-cream.svg') }}" alt="{{ $business->name }}"></a>
        <div class="rb-right">
            @php
                $rbPath = request()->getPathInfo();
                $rbRel = $base !== '' && str_starts_with($rbPath, $base) ? substr($rbPath, strlen($base)) : $rbPath;
                $rbQuery = array_filter(request()->query(), fn ($v, $k) => $k !== 'lang', ARRAY_FILTER_USE_BOTH) + ['lang' => $lang === 'en' ? 'ar' : 'en'];
            @endphp
            <a class="rb-hbtn rb-lang" href="{{ $base }}{{ $rbRel ?: '/' }}?{{ http_build_query($rbQuery) }}" data-testid="rb-lang">{{ $t['langBtn'] }}</a>
            <a class="rb-hbtn rb-cartbtn" href="{{ $base }}/cart" data-testid="rb-cart-btn"><span>{{ $t['cart'] }}</span><span class="rb-count" data-rb-count>0</span></a>
        </div>
    </div>
    {{--
        ═══ وقائمةُ الصفحات — شريطٌ تحت الترويسة ═══

        ومتجرُ الواجهة الخاصّة كان صفحةً ورفًّا وسلّةً بلا قائمةٍ أصلًا:
        الزبون الذي يريد «من نحن» أو «تواصل معنا» لا يجد إليهما بابًا،
        وهما ما يسأل عنه قبل أن يدفع لمن لا يعرفه.

        وموضعُه صفٌّ ثانٍ لا عمودٌ رابعٌ في الصفّ الأوّل: ذاك مبنيٌّ على
        ثلاث مناطق (بحثٌ وشعارٌ ويمين) بمقاساتٍ محسوبة، وحشرُ القائمة فيه
        يُضيّق البحثَ ويزحزح الشعارَ عن الوسط. والألوانُ والخطُّ كما هي.

        ولا يُرسم الشريطُ على قائمةٍ فارغة — انظر `StoreNav::links`.
    --}}
    @if (count($nav))
        <nav class="rb-nav" data-testid="rb-nav" aria-label="{{ $t['footPages'] }}">
            <div class="rb-nav-in">
                @foreach ($nav as $rbLink)
                    <a href="{{ $rbLink['href'] }}"
                       class="rb-nav-a @if ($rbLink['current']) is-on @endif"
                       data-testid="rb-nav-{{ $rbLink['key'] }}"
                       @if ($rbLink['current']) aria-current="page" @endif>{{ $rbLink['label'] }}</a>
                @endforeach
            </div>
        </nav>
    @endif
</header>

<main>
    @yield('content')
</main>

<footer class="rb-foot">
    <div class="rb-foot-in">
        <div class="rb-foot-grid">
            <div style="display:flex;flex-direction:column;gap:16px">
                <img src="{{ \App\Support\Store\StoreAsset::url('/brand/ribbon/logo-cream.svg') }}" alt="{{ $business->name }}" style="width:180px;height:auto;display:block">
                @if ($identity['about'] !== '')
                    <p style="margin:0;line-height:1.8;max-width:300px">{{ $identity['about'] }}</p>
                @endif
                <div class="rb-social" style="display:flex;gap:10px;flex-wrap:wrap">
                    @if ($identity['instagram'] !== '')
                        <a href="https://instagram.com/{{ $identity['instagram'] }}" target="_blank" rel="noopener">Instagram</a>
                    @endif
                    @if ($identity['whatsapp'] !== '')
                        <a href="https://wa.me/{{ preg_replace('/\D/', '', $identity['whatsapp']) }}" target="_blank" rel="noopener">WhatsApp</a>
                    @endif
                </div>
            </div>
            @if (count($nav))
                {{-- وعمودُ الصفحات في التذييل من مصدر القائمة نفسِه --}}
                <div>
                    <h3>{{ $t['footPages'] }}</h3>
                    <div style="display:flex;flex-direction:column">
                        @foreach ($nav as $rbLink)
                            <a href="{{ $rbLink['href'] }}" data-testid="rb-foot-{{ $rbLink['key'] }}">{{ $rbLink['label'] }}</a>
                        @endforeach
                    </div>
                </div>
            @endif
            <div>
                <h3>{{ $t['footShop'] }}</h3>
                <div style="display:flex;flex-direction:column">
                    <a href="{{ $base }}/shop">{{ $t['all'] }}</a>
                    @foreach ($catsNav as $c)
                        <a href="{{ $base }}/shop?cat={{ $c['id'] }}">{{ $c['name'] }}</a>
                    @endforeach
                </div>
            </div>
            <div>
                <h3>{{ $t['footContact'] }}</h3>
                <div style="display:flex;flex-direction:column;gap:10px;line-height:1.6">
                    @if ($identity['phone'] !== '')<span dir="ltr" style="display:block;text-align:start">{{ $identity['phone'] }}</span>@endif
                    @if ($identity['email'] !== '')<span>{{ $identity['email'] }}</span>@endif
                    @if ($identity['address'] !== '')<span>{{ $identity['address'] }}</span>@endif
                    @if ($hours !== '')<span>{{ $hours }}</span>@endif
                </div>
            </div>
        </div>
        <div class="rb-foot-bottom">
            <span>© {{ date('Y') }} {{ $business->name }}</span>
            {{--
                وسطرُ التذييل يكتبه صاحبُ المحلّ.

                «FLOWERS · LOUNGE · AND MORE» وصفُ محلٍّ بعينه، وكان مكتوبًا
                بحروفه في هذا القالب. ومحلٌّ آخر يلبس الواجهةَ نفسَها كان
                يُذيّل صفحتَه بوصفِ غيره.
            --}}
            <span style="letter-spacing:.18em" data-testid="rb-tagline">{{ $tagline ?? 'FLOWERS · LOUNGE · AND MORE' }}</span>
        </div>
    </div>
</footer>

<div class="rb-toast" data-rb-toast></div>

<script>
/*
 * سلّةُ RIBBON — في المتصفّح، وتُسعَّر من الخادم.
 * لا سعرَ يُحسب هنا: الخادمُ يقرأ الأسعارَ من القاعدة ويردّ الأرقامَ مكتوبة.
 */
window.RB = (function () {
    var KEY = 'ribbon-cart:{{ (int) $business->id }}';
    var BASE = @json($base);
    var CSRF = document.querySelector('meta[name=csrf-token]').content;
    function read() { try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } }
    function write(items) { try { localStorage.setItem(KEY, JSON.stringify(items)); } catch (e) {} paint(); }
    function count() { return read().reduce(function (a, b) { return a + (b.qty || 0); }, 0); }
    function paint() { document.querySelectorAll('[data-rb-count]').forEach(function (el) { el.textContent = count(); }); }
    function toast(msg) { var el = document.querySelector('[data-rb-toast]'); el.textContent = msg; el.classList.add('show'); clearTimeout(el._t); el._t = setTimeout(function () { el.classList.remove('show'); }, 1600); }
    function add(id, variantId, qty) {
        var items = read(); var hit = null;
        items.forEach(function (it) { if (it.id === id && (it.variant_id || null) === (variantId || null)) hit = it; });
        if (hit) hit.qty += qty; else items.push({ id: id, variant_id: variantId || null, qty: qty });
        write(items);
    }
    function set(id, variantId, qty) {
        var items = read().map(function (it) { if (it.id === id && (it.variant_id || null) === (variantId || null)) it.qty = qty; return it; }).filter(function (it) { return it.qty > 0; });
        write(items);
    }
    function clear() { write([]); }
    function post(path, body) {
        return fetch(BASE + path, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF }, body: JSON.stringify(body) })
            .then(function (r) { return r.json().then(function (j) { j._status = r.status; return j; }); });
    }
    function quote(extra) { return post('/quote', Object.assign({ items: read() }, extra || {})); }
    document.addEventListener('DOMContentLoaded', paint);
    return { read: read, write: write, add: add, set: set, clear: clear, count: count, quote: quote, post: post, toast: toast, base: BASE };
})();
</script>
@yield('scripts')
</body>
</html>
