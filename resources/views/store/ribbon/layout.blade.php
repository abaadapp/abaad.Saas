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
    {{--
        ═══ وما يُعرض حين يُلصَق الرابط ═══

        زبونُ محلّ وردٍ في عُمان يصل من رسالةِ واتساب لا من بحثٍ غالبًا. وكانت
        البطاقةُ تخرج بصورةٍ وحدَها (إن وُجد شعار) بلا عنوانٍ ولا وصف — فتبدو
        رابطًا مكسورًا في محادثة، ولا يُضغط.

        ومتجرُ البانِي يعلنها كاملةً منذ كُتب (`site/show.blade.php`)،
        وانفردت هذه الواجهةُ بالنقص.
    --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $business->name }}">
    <meta property="og:title" content="@yield('title', $seo['title'])">
    <meta property="og:description" content="{{ $seo['description'] }}">
    @if ($seo['canonical'])
        <meta property="og:url" content="{{ $seo['canonical'] }}">
    @endif
    {{-- وصورةُ الصفحة إن كان لها واحدة — صنفٌ بصورته، وإلّا واجهةُ المتجر --}}
    @php $rbShare = ($ogImage ?? null) ?: $seo['image']; @endphp
    @if ($rbShare)
        <meta property="og:image" content="{{ $rbShare }}">
        <meta name="twitter:card" content="summary_large_image">
    @else
        <meta name="twitter:card" content="summary">
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
         * و`IBM Plex Sans Arabic` أوّلُ السلسلة — للعربيّة والإنجليزيّة معًا.
         *
         * كان قبله `GE Hili` اسمًا بلا ملفّ، وبعده `Noto Kufi Arabic`: جهازٌ
         * فيه أحدُهما يرسم به بعضَ الصفحة قبل أن يصل خطُّنا أو مكانه، فتختلط
         * الحروف. وما بعد IBM خطوطُ النظام احتياطًا وحدها.
         */
        html { font-family: 'IBM Plex Sans Arabic', system-ui, -apple-system, 'Segoe UI', Tahoma, Arial, sans-serif; }
        body { margin: 0; background: var(--rb-bg); color: #000; font-family: 'IBM Plex Sans Arabic', system-ui, -apple-system, 'Segoe UI', Tahoma, Arial, sans-serif; -webkit-font-smoothing: antialiased; min-height: 100vh; display: flex; flex-direction: column; }
        /*
         * ═══ وتباعدُ الحروف للإنجليزيّة وحدها ═══
         *
         * الحروفُ العربيّة متّصلة: مسافةٌ بينها (`letter-spacing`) تقطع
         * الكلمةَ حروفًا منفصلة — «الرئيسية» تُقرأ «ا ل ر ئ ي س ي ة». فالتباعدُ
         * يُكتب على `html[lang="en"]` وحدها، والعربيّةُ بلا تباعد. و`.rb-track`
         * لعنصرٍ يريد تباعدًا بالإنجليزيّة، ومقدارُه في `--rb-track`.
         */
        html[lang="en"] .rb-track { letter-spacing: var(--rb-track, .18em); }
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
        .rb-nav-a { color: rgba(239,234,219,.82); font-size: 13px; min-height: 46px; display: inline-flex; align-items: center; border-bottom: 1px solid transparent; }
        html[lang="en"] .rb-nav-a { letter-spacing: .14em; }
        .rb-nav-a:hover { color: var(--rb-cream); }
        .rb-nav-a.is-on { color: var(--rb-cream); border-bottom-color: var(--rb-cream); }
        @media (max-width: 1023px) { .rb-nav-in { padding: 0 16px; gap: 2px 18px; } .rb-nav-a { font-size: 12px; min-height: 42px; } }

        /*
         * ═══ شريطُ الإعلان — أوّلُ صفوف الترويسة ═══
         *
         * بلا خلفيّةٍ له: لونُه لونُ الترويسة التي هو فيها (`--rb-olive`)،
         * فلا يفترقان يومًا بدرجةٍ كُتبت تقريبًا. والمحاذاةُ صنفٌ من ثلاثةٍ
         * مكانًا لا اتّجاهًا — اليسارُ يسارٌ في الصفحتين، ولا نصَّ حرٌّ في `style`.
         */
        .rb-ann { border-bottom: 1px solid rgba(239,234,219,.18); }
        .rb-ann-in { max-width: 1280px; margin: 0 auto; padding: 8px 24px; color: var(--rb-cream); font-size: 13px; line-height: 1.5; }
        html[lang="en"] .rb-ann-in { letter-spacing: .04em; }
        .rb-ann-left { text-align: left; } .rb-ann-center { text-align: center; } .rb-ann-right { text-align: right; }
        @media (max-width: 1023px) { .rb-ann-in { padding: 7px 16px; font-size: 12px; } }

        /*
         * ═══ وصفُّ خيارات المتجر — آخرُ صفوف الترويسة، في «المتجر» وحدها ═══
         *
         * سطرٌ واحدٌ يُمرَّر بالإصبع على الهاتف لا أسطرٌ تلتفّ: الترويسةُ هناك
         * طويلةٌ أصلًا. والهامشُ التلقائيّ على الطرفين يوسّطه حين يتّسع ولا
         * يقصّ أوّلَه حين يضيق (`justify-content:center` تقصّه)، والفاصلان
         * قبله وبعده يُبقيان آخرَ زرٍّ كاملًا لا ملتصقًا بحافّة الشاشة.
         */
        .rb-opts { border-top: 1px solid rgba(239,234,219,.18); }
        .rb-opts-in { max-width: 1280px; margin: 0 auto; padding: 6px 0; display: flex; gap: 8px; overflow-x: auto; overscroll-behavior-x: contain; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
        .rb-opts-in::-webkit-scrollbar { display: none; }
        .rb-opts-in::before, .rb-opts-in::after { content: ''; flex: none; width: 16px; }
        .rb-opts-in > :first-child { margin-inline-start: auto; } .rb-opts-in > :last-child { margin-inline-end: auto; }
        .rb-opt { flex: none; white-space: nowrap; min-height: 44px; padding: 0 16px; display: inline-flex; align-items: center; border: 1px solid rgba(239,234,219,.45); border-radius: 999px; color: var(--rb-cream); font-size: 13px; }
        .rb-opt:hover { background: rgba(239,234,219,.12); }
        .rb-opt.is-on { background: var(--rb-cream); border-color: var(--rb-cream); color: var(--rb-olive); }
        @media (max-width: 1023px) { .rb-opts-in::before, .rb-opts-in::after { width: 8px; } .rb-opt { padding: 0 14px; font-size: 12px; } }

        /*
         * ═══ والقفزُ إلى قسمٍ لا يقع تحت الترويسة ═══
         *
         * «استكشف مجموعاتنا» يقفز إلى قسم الفئات، والترويسةُ لاصقةٌ وطولُها
         * يتبدّل: شريطٌ أو لا شريط، وصفٌّ للبحث على الهاتف. فلا رقمَ يُكتب
         * لجهازٍ بعينه — طولُها يُقاس ويُوضع في `--rb-head-h` (آخرَ الصفحة)،
         * وبلا قياسٍ يبقى القفزُ كما كان.
         */
        html { scroll-padding-top: var(--rb-head-h, 0px); }

        /* بطاقاتُ «تواصل معنا» — بإطار الصفحة ولونها، لا بلونٍ جديد */
        .rb-cbox { border: 1px solid var(--rb-border); border-radius: var(--rb-r); padding: 18px 20px; display: flex; flex-direction: column; gap: 6px; background: #fff; }
        .rb-clabel { font-size: 12px; color: #6b6a55; }
        html[lang="en"] .rb-clabel { letter-spacing: .18em; }
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
        @if ($showFloatingWhatsapp ?? false)
        /* زرُّ واتساب العائم — يمينَ الشاشة في الاتّجاهين، فوق المحتوى وتحت التنبيه */
        .rb-wa-float { position: fixed; right: 20px; bottom: calc(20px + env(safe-area-inset-bottom, 0px)); z-index: 40; width: 58px; height: 58px; border-radius: 50%; background: #25d366; color: #fff; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 14px rgba(0, 0, 0, .22); transition: transform .15s; }
        .rb-wa-float:hover { transform: scale(1.05); }
        .rb-wa-float:focus-visible { outline: 3px solid var(--rb-olive); outline-offset: 3px; }
        .rb-wa-float svg { width: 30px; height: 30px; display: block; }
        /* ولا يغطّي آخرَ سطرٍ في التذييل حين تبلغه الصفحة */
        .rb-foot-bottom { padding-bottom: 76px; }
        @media (max-width: 640px) { .rb-wa-float { right: 16px; bottom: calc(16px + env(safe-area-inset-bottom, 0px)); width: 56px; height: 56px; } }
        @media print { .rb-wa-float { display: none; } }
        @endif
    </style>
    {!! $analytics ?? '' !!}
</head>
<body>
<header class="rb-head">
    {{--
        ═══ والترويسةُ عنصرٌ لاصقٌ واحد، وصفوفُها في جريانه ═══

        إعلانٌ ← شعارٌ وبحثٌ وسلّة ← صفحاتُ المتجر ← خياراتُ «المتجر».
        ولا يُثبَّت صفٌّ منها وحدَه بإزاحةٍ محسوبة: أربعةُ شرائطَ ثابتةٍ
        بأرقامٍ تتراكب على الهاتف متى التفّ سطرُ الإعلان.

        وشريطُ الإعلان بلغة الزائر وحدها، ولا يُرسم إن فرغ نصُّها — فلا
        يأخذ ارتفاعًا. انظر `Store\StoreHeader::announcement`.
    --}}
    @if ($announcement ?? null)
        <div class="rb-ann" data-testid="rb-announcement" data-align="{{ $announcement['align'] }}">
            <p class="rb-ann-in rb-ann-{{ $announcement['align'] }}" style="margin:0">{{ $announcement['text'] }}</p>
        </div>
    @endif
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
    {{--
        ═══ وخياراتُ «المتجر» — في صفحته وحدها، وداخلَ الترويسة ═══

        «كل المنتجات» و«الأكثر مبيعًا» ثمّ الفئاتُ التي اختارها صاحبُ المتجر
        بترتيبه، كلٌّ بمعرّفه. وهي الترشيحُ الوحيد في الصفحة — لا صفَّ ثانيًا
        في جسمها. انظر `Store\StoreHeader::shopOptions`.
    --}}
    @if (! empty($shopOptions ?? []))
        <nav class="rb-opts" data-testid="rb-shop-options" aria-label="{{ $t['shopOptions'] }}">
            <div class="rb-opts-in" data-rb-opts>
                @foreach ($shopOptions as $rbOpt)
                    <a href="{{ $rbOpt['href'] }}"
                       class="rb-opt @if ($rbOpt['current']) is-on @endif"
                       data-testid="rb-opt-{{ $rbOpt['key'] }}"
                       @if ($rbOpt['current']) aria-current="page" @endif>{{ $rbOpt['label'] }}</a>
                @endforeach
            </div>
        </nav>
    @endif
</header>

<main>
    @yield('content')
</main>

{{--
    ═══ والتذييلُ يُضغط — لا يُنسخ ═══

    الهاتفُ يتّصل (`tel:`)، والبريدُ يفتح البريد (`mailto:`)، وواتساب يفتح
    محادثةَ المحلّ مباشرةً (`https://wa.me/…` — التطبيقُ على الهاتف وWhatsApp
    Web على الحاسوب)، بالطريقة نفسِها في صفحة «تواصل معنا». والأرقامُ والبريدُ
    من اليسار (`bdi dir="ltr"`) في الصفحتين، والسطرُ يتبع اتّجاهَ صفحته.
--}}
@php
    $rbTel = preg_replace('/[^\d+]/', '', $identity['phone']);
    $rbWa = preg_replace('/\D/', '', $identity['whatsapp']);
    $rbIg = $identity['instagram'] !== '' ? 'https://instagram.com/'.rawurlencode($identity['instagram']) : '';
@endphp
<footer class="rb-foot">
    <div class="rb-foot-in">
        <div class="rb-foot-grid">
            <div style="display:flex;flex-direction:column;gap:16px">
                <img src="{{ \App\Support\Store\StoreAsset::url('/brand/ribbon/logo-cream.svg') }}" alt="{{ $business->name }}" style="width:180px;height:auto;display:block">
                @if ($identity['about'] !== '')
                    <p style="margin:0;line-height:1.8;max-width:300px">{{ $identity['about'] }}</p>
                @endif
                <div class="rb-social" style="display:flex;gap:10px;flex-wrap:wrap">
                    @if ($rbIg !== '')
                        <a href="{{ $rbIg }}" target="_blank" rel="noopener" data-testid="rb-foot-instagram">Instagram</a>
                    @endif
                    @if ($rbWa !== '')
                        <a href="https://wa.me/{{ $rbWa }}" target="_blank" rel="noopener" data-testid="rb-foot-whatsapp">WhatsApp</a>
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
                    @if ($rbTel !== '')<a href="tel:{{ $rbTel }}" data-testid="rb-foot-phone"><bdi dir="ltr">{{ $identity['phone'] }}</bdi></a>@endif
                    @if ($rbWa !== '')<a href="https://wa.me/{{ $rbWa }}" target="_blank" rel="noopener" data-testid="rb-foot-whatsapp-line">{{ $t['cWhatsapp'] }}&nbsp;·&nbsp;<bdi dir="ltr">{{ $identity['whatsapp'] }}</bdi></a>@endif
                    @if ($identity['email'] !== '')<a href="mailto:{{ $identity['email'] }}" data-testid="rb-foot-email"><bdi dir="ltr">{{ $identity['email'] }}</bdi></a>@endif
                    @if ($identity['address'] !== '')<span>{{ $identity['address'] }}</span>@endif
                    @if ($hours !== '')<span>{{ $hours }}</span>@endif
                </div>
            </div>
        </div>
        <div class="rb-foot-bottom">
            <span>© {{ now()->year }} {{ $business->name }}</span>
            {{--
                وسطرُ التذييل يكتبه صاحبُ المحلّ — أو لا سطر.

                كان الفراغُ يقع على وصفِ محلٍّ بعينه مكتوبٍ في هذا القالب، فكلُّ
                محلٍّ يلبس الواجهةَ ولم يكتب سطرَه يُذيَّل بوصفِ غيره. فما لم
                يُكتب لا يُرسم، ولا يُخترع له بديل.
            --}}
            @if (filled($tagline))
                <span class="rb-track" style="--rb-track:.18em" data-testid="rb-tagline">{{ $tagline }}</span>
            @endif
        </div>
    </div>
</footer>

<div class="rb-toast" data-rb-toast></div>

{{--
    ═══ زرُّ واتساب العائم — في القالب العامّ، فهو في كلّ صفحة ═══

    لمن في `storefront.ribbon_floating_whatsapp_businesses` وبرقم «بيانات
    المتجر» نفسِه (`RibbonController::floatingWhatsapp`)، ومعه رسالةٌ جاهزةٌ
    بلغة الصفحة. و`wa.me` يفتح التطبيقَ على الهاتف وWhatsApp Web على الحاسوب.
--}}
@if ($showFloatingWhatsapp ?? false)
    <a class="rb-wa-float" href="{{ $floatingWhatsappUrl }}" target="_blank" rel="noopener" aria-label="{{ $t['waFloat'] }}" title="{{ $t['waFloat'] }}" data-testid="rb-wa-float">
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/></svg>
    </a>
@endif

<script>{!! file_get_contents(resource_path('js/store/ribbon-cart-lines.js')) !!}</script>
<script>
/*
 * سلّةُ RIBBON — في المتصفّح، وتُسعَّر من الخادم.
 * لا سعرَ يُحسب هنا: الخادمُ يقرأ الأسعارَ من القاعدة ويردّ الأرقامَ مكتوبة.
 */
window.RB = (function () {
    var KEY = 'ribbon-cart:{{ (int) $business->id }}';
    var BASE = @json($base);
    var CSRF = document.querySelector('meta[name=csrf-token]').content;
    // وما يُقال للزبون حين يُردّ بابٌ — بلغته، وخريطتُه من الخادم لتُحرَس
    var SAYS = @json(\App\Support\Store\RibbonTexts::doorSays($lang));
    function read() { try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } }
    function write(items) { try { localStorage.setItem(KEY, JSON.stringify(items)); } catch (e) {} paint(); }
    function count() { return read().reduce(function (a, b) { return a + (b.qty || 0); }, 0); }
    function paint() { document.querySelectorAll('[data-rb-count]').forEach(function (el) { el.textContent = count(); }); }
    function toast(msg) { var el = document.querySelector('[data-rb-toast]'); el.textContent = msg; el.classList.add('show'); clearTimeout(el._t); el._t = setTimeout(function () { el.classList.remove('show'); }, 1600); }
    // والبندُ صنفٌ ومقاسٌ ونصُّ كرتٍ إن كان — انظر `ribbon-cart-lines.js`
    function add(id, variantId, qty, note) { write(RBLines.add(read(), id, variantId, qty, note)); }
    function set(id, variantId, qty, note) { write(RBLines.set(read(), id, variantId, qty, note)); }
    function clear() { write([]); }
    /*
        وكلُّ جوابٍ يُقال للزبون — ولو لم يكن خطأَ حقلٍ في نموذجه.

        ═══ والعطبُ الذي وُضع لأجله ═══

        كانت الصفحتان تقرآن `errors` وحدَها: إن غابت كُتب `''` في موضع
        الخطأ. فكلُّ جوابٍ ليس ٤٢٢ يخرج **صامتًا** — يضغط الزبون «تأكيد
        الطلب»، يعود الزرُّ قابلًا للضغط، ولا تظهر كلمة.

        وأكثرُه وقوعًا ٤١٩: يفتح الزبون الإتمام، يذهب يسأل من يُهدي، يعود
        بعد ساعةٍ وقد انتهت جلستُه، فيملأ ويضغط ويضغط ولا شيء. ومن ملأ
        نموذجًا كاملًا ثمّ لم يُجَب يترك السلّة ولا يعود.

        ثمّ ٤٢٩ بعد أن صار للبابِ عدّاد، و٥٠٠، و٥٠٢ من الوسيط — وهذا
        الأخيرُ ليس JSON أصلًا، فكان `r.json()` يرمي: السلّةُ بلا
        `catch` فتصمت، والإتمامُ يقول «أكمل الحقول» وكلُّها مكتملة.

        فيُقرأ النصُّ ثمّ يُحاوَل تحليلُه، ويُصنَع `errors` لمن لا `errors`
        له — فلا يبقى بابٌ يُغلق بلا كلمة.
    */
    function post(path, body) {
        return fetch(BASE + path, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF }, body: JSON.stringify(body) })
            .then(function (r) {
                return r.text().then(function (text) {
                    var j;
                    try { j = JSON.parse(text); } catch (e) { j = null; }
                    if (!j || typeof j !== 'object') { j = { ok: false }; }
                    j._status = r.status;
                    if (!j.ok && !j.errors) { j.errors = { _: [said(r.status)] }; }
                    return j;
                });
            })
            .catch(function () { return { ok: false, _status: 0, errors: { _: [SAYS._] } }; });
    }
    function said(status) { return SAYS[String(status)] || SAYS._; }
    function quote(extra) { return post('/quote', Object.assign({ items: read() }, extra || {})); }
    document.addEventListener('DOMContentLoaded', paint);
    return { read: read, write: write, add: add, set: set, clear: clear, count: count, quote: quote, post: post, toast: toast, base: BASE };
})();
</script>
<script>
/*
 * ما لا يقدر عليه CSS وحده — سطران، وبلاهما تبقى الصفحةُ كما كانت.
 *
 * ١) طولُ الترويسة اللاصقة يتبدّل (شريطٌ يلتفّ، صفُّ بحثٍ على الهاتف)،
 *    فيُقاس ويُوضع في `--rb-head-h` ليقع القفزُ إلى قسمٍ تحتها لا خلفها.
 * ٢) والخيارُ المختار في صفّ المتجر قد يقع خارجَ الشاشة على الهاتف، فيُمرَّر
 *    الصفُّ إليه وحدَه — أفقيًّا، ولا تتحرّك الصفحة.
 */
(function () {
    var head = document.querySelector('header.rb-head');
    if (head) {
        var put = function () { document.documentElement.style.setProperty('--rb-head-h', head.offsetHeight + 'px'); };
        put();
        if (window.ResizeObserver) { new ResizeObserver(put).observe(head); }
    }
    var row = document.querySelector('[data-rb-opts]');
    var on = row && row.querySelector('.is-on');
    var center = function () {
        if (row.scrollWidth > row.clientWidth) {
            row.scrollLeft += (on.getBoundingClientRect().left + on.offsetWidth / 2) - (row.getBoundingClientRect().left + row.clientWidth / 2);
        }
    };
    if (on) {
        center();
        // والخطُّ يصل بعد الرسم فتتّسع الأزرارُ ويزيح المختار — فيُعاد حين يجهز
        if (document.fonts && document.fonts.ready) { document.fonts.ready.then(center); }
    }
})();
</script>
@yield('scripts')
</body>
</html>
