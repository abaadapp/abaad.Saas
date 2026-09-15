{{--
    ما يكتب فيه الزبونُ رأيَه — صفحةٌ واحدة بلا أصولٍ خارجية.

    تُفتح من رابطٍ في رسالةِ واتساب، على هاتفٍ في الشارع بشبكةٍ ضعيفة. ومن
    ينتظر تحميلَ حزمةِ جافاسكربت ليضع خمسَ نجومٍ يُغلقها قبل أن تصل.

    ═══ والنجومُ CSS خالص ═══

    خمسةُ أزرارٍ راديو مخفيّة، وتلوينُها بـ`:checked ~ label`. فلا جافاسكربت
    في الصفحة أصلًا: من أطفأه — أو من لم تصله الحزمة — يبقى قادرًا على
    الاختيار والإرسال. ومقياسُ نجاح هذه الصفحة أنّ الرأي يصل، لا أنّها أنيقة.

    وترتيبُ الراديو من الخمسة إلى الواحد، والصفُّ معكوسٌ بـ`row-reverse`:
    فتُقرأ من اليمين ١…٥ كما تُقرأ الصفحةُ كلُّها، ويُلوَّن ما قبل المختارة
    بـ`~` — وهو ما بعدها في المستند.

    و`noindex`: رابطُها لا يُخمَّن، لكنّ محرّك بحثٍ يزحف إليه من مشاركةٍ
    عابرة يجعله مفهرسًا للجميع.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('قيّم تجربتك') }} — {{ $brand['name'] }}</title>
    <style>
        :root {
            --ink: #111; --muted: #6b7280; --faint: #9ca3af;
            --rule: #e8e8e8; --card: #fff; --gold: #f59e0b; --dim: #d8d8d8;
            --ok: #047857; --bad: #b91c1c;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 16px 12px 40px;
            background: #f5f5f4; color: var(--ink);
            font: 16px/1.6 -apple-system, "Segoe UI", system-ui, "Helvetica Neue", Arial, sans-serif;
        }
        .sheet {
            max-width: 520px; margin: 0 auto; background: var(--card);
            border: 1px solid var(--rule); border-radius: 16px; overflow: hidden;
        }
        .pad { padding: 20px 18px; }
        .head { border-bottom: 1px solid var(--rule); text-align: center; }
        .logo { max-height: 56px; margin-bottom: 8px; }
        .shop { font-size: 20px; font-weight: 700; margin: 0 0 2px; }
        .muted { color: var(--muted); }
        .sm { font-size: 14px; }
        .xs { font-size: 12px; }
        .ltr { direction: ltr; unicode-bidi: isolate; }
        h2 { font-size: 17px; font-weight: 600; margin: 0 0 4px; }

        /* ---------------------------- النجوم ---------------------------- */
        .stars {
            position: relative;
            display: flex; flex-direction: row-reverse; justify-content: center;
            gap: 4px; margin: 14px 0 6px;
        }
        .stars input {
            position: absolute; width: 1px; height: 1px;
            opacity: 0; margin: 0; pointer-events: none;
        }
        .stars label {
            cursor: pointer; font-size: 40px; line-height: 1;
            color: var(--dim); padding: 2px 3px; user-select: none;
            transition: color .12s;
        }
        .stars input:checked ~ label { color: var(--gold); }
        .stars:hover label { color: var(--dim); }
        .stars label:hover, .stars label:hover ~ label { color: var(--gold); }
        .stars input:focus-visible + label { outline: 2px solid #2563eb; border-radius: 6px; }

        textarea {
            width: 100%; min-height: 110px; resize: vertical;
            padding: 11px 12px; border: 1px solid var(--rule); border-radius: 12px;
            font: inherit; color: inherit; background: #fff;
        }
        textarea:focus { outline: 2px solid #2563eb; outline-offset: 1px; border-color: transparent; }

        button {
            width: 100%; margin-top: 14px; padding: 13px 16px;
            border: 0; border-radius: 12px; background: var(--ink); color: #fff;
            font: 600 16px/1 inherit; cursor: pointer;
        }
        button:active { opacity: .85; }

        .err { color: var(--bad); font-size: 14px; margin-top: 6px; }
        .done { text-align: center; }
        .tick { font-size: 44px; line-height: 1; color: var(--ok); }
        .foot { text-align: center; padding: 14px 18px 18px; }
    </style>
</head>
<body>
<main class="sheet">
    <div class="pad head">
        @if ($brand['logo'])
            <img class="logo" src="{{ $brand['logo'] }}" alt="">
        @endif
        <h1 class="shop">{{ $brand['name'] }}</h1>
        @if ($brand['sub'] !== '')
            <div class="muted sm">{{ $brand['sub'] }}</div>
        @endif
    </div>

    @if ($done)
        {{--
            وصل الرأي — ولا يُعرض النموذجُ ثانيةً.

            ولا يُقال «نُشر»: لا يظهر على الموقع حتى يقرأه صاحبُ المحلّ ويأذن،
            وقولُ «نُشر» عن كلامٍ محجوب طمأنينةٌ كاذبة.
        --}}
        <div class="pad done">
            <div class="tick">✓</div>
            <h2>{{ __('وصلنا رأيك — شكرًا لك') }}</h2>
            <p class="muted sm">{{ __('يقرؤه صاحبُ المحلّ، ويختار ما يُعرض منه على موقعه.') }}</p>
        </div>
    @else
        <form class="pad" method="POST" action="{{ route('review.submit', $token) }}">
            @csrf

            <h2>{{ __('كيف كانت تجربتك؟') }}</h2>
            <p class="muted sm" style="margin:0">
                {{ __('عن طلبك رقم') }} <span class="ltr">{{ $number }}</span>
            </p>

            <div class="stars">
                @foreach ([5, 4, 3, 2, 1] as $n)
                    <input type="radio" id="star-{{ $n }}" name="rating" value="{{ $n }}"
                           @checked(old('rating') == $n) required>
                    <label for="star-{{ $n }}" title="{{ $n }}"
                           aria-label="{{ __('التقييم :n من 5', ['n' => $n]) }}">★</label>
                @endforeach
            </div>

            @error('rating')
                <div class="err">{{ $message }}</div>
            @enderror

            <p class="muted sm" style="margin:14px 0 6px">{{ __('واكتب كلمةً إن أحببت — وهي ما قد يُعرض على الموقع') }}</p>
            <textarea name="comment" maxlength="2000"
                      placeholder="{{ __('ما الذي أعجبك؟ وما الذي كان يمكن أن يكون أفضل؟') }}">{{ old('comment') }}</textarea>

            @error('comment')
                <div class="err">{{ $message }}</div>
            @enderror

            <button type="submit">{{ __('أرسِل رأيي') }}</button>
        </form>
    @endif

    <div class="foot muted xs">{{ __('لا يظهر رأيك على الموقع إلا بإذن صاحب المحلّ.') }}</div>
</main>
</body>
</html>
