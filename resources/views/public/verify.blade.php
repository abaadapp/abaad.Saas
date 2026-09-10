{{--
    صفحةُ التحقّق من مستند — ما يفتحه من مسح الرمز.

    ═══ ولمَ ملخّصٌ لا الورقةُ كاملة ═══

    فاتورةُ العميل تُرسَل إلى الجهة كاملةً بالبريد أو على ورق. وما يحتاجه
    من يمسح الرمزَ سؤالٌ واحد: **أهذه الورقةُ صحيحة؟** — أي أنّ رقمَها
    وتاريخَها ومبلغَها وحالَها كما في دفتر التاجر، وأنّها لم تُعدَّل بعد أن
    خرجت منه.

    فيُعرض ما يجيب عن ذلك ولا يُعرض غيرُه: لا بنودَ ولا آيبانَ ولا مراكزَ
    تكلفةٍ ولا أرقامَ عقود. رابطٌ لا يحرسه إلّا كونُه غير مخمَّن لا يُحمَّل
    ما ليس ضروريًّا — ورقةٌ تُصوَّر بهاتفٍ في ممرّ، والرابطُ يُعاد إرساله.

    ═══ وصفحةٌ قائمةٌ بذاتها ═══

    لا Inertia ولا قائمةٌ جانبية ولا أصولُ البناء: تُفتح على هاتفٍ بشبكةٍ
    ضعيفة، ومن ينتظر حزمةَ جافاسكربت ليتحقّق من ورقةٍ يغلقها قبل أن تصل.

    و`noindex`: رابطُها لا يُخمَّن، لكنّ محرّك بحثٍ يزحف إليه من مشاركةٍ
    عابرة يجعله مفهرسًا للجميع.
--}}
@php
    $rtl = \App\Support\Paper::rtl();
@endphp
<!DOCTYPE html>
<html lang="{{ $rtl ? 'ar' : 'en' }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $doc['type'] }} {{ $doc['number'] }} — {{ $brand['name'] }}</title>
    <style>
        :root {
            --ink: #0f172a; --muted: #6b7280; --faint: #9ca3af;
            --rule: #e8e8e8; --wash: #fafafa; --brand: {{ $primary }};
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 16px 12px 40px;
            background: #f5f5f4; color: var(--ink);
            font: 15px/1.6 'IBM Plex Sans Arabic', -apple-system, 'Segoe UI', system-ui, Arial, sans-serif;
        }
        .sheet {
            max-width: 520px; margin: 0 auto; background: #fff;
            border: 1px solid var(--rule); border-radius: 16px; overflow: hidden;
        }
        .band { height: 6px; background: var(--brand); }
        .pad { padding: 18px; }
        .head { border-bottom: 1px solid var(--rule); text-align: center; }
        .logo { max-height: 52px; margin-bottom: 8px; }
        .shop { font-size: 19px; font-weight: 700; margin: 0 0 2px; }
        .muted { color: var(--muted); }
        .faint { color: var(--faint); }
        .sm { font-size: 13px; }
        .xs { font-size: 12px; }
        .ltr { direction: ltr; unicode-bidi: isolate; }

        .eyebrow {
            font-size: 11px; letter-spacing: .08em; color: var(--faint);
            margin-bottom: 10px;
        }
        .row { display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; }
        .row + .row { border-top: 1px solid #f5f5f4; }

        .grand {
            display: flex; justify-content: space-between; align-items: baseline;
            margin-top: 14px; padding: 12px 14px;
            background: var(--wash); border-radius: 10px;
        }
        .grand .v { font-size: 21px; font-weight: 700; direction: ltr; }

        /* حالُ المستند: لونٌ يقول ما يقوله النصّ، فلا يُقرأ بالتخمين */
        .pill {
            display: inline-block; padding: 3px 10px; border-radius: 999px;
            font-size: 12px; font-weight: 600;
        }
        .pill.ok    { background: #ecfdf5; color: #047857; }
        .pill.due   { background: #fffbeb; color: #b45309; }
        .pill.late  { background: #fef2f2; color: #b91c1c; }
        .pill.void  { background: #f5f5f4; color: #6b7280; }

        /* ————— ختمُ أبعاد ————— */
        .stamp {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 18px; border-top: 1px solid var(--rule); background: var(--wash);
        }
        .seal {
            flex: 0 0 auto; width: 54px; height: 54px; border-radius: 50%;
            border: 2px solid var(--ink); display: flex; align-items: center; justify-content: center;
            /* ميلٌ خفيف: الختم يُضرب باليد لا يُطبع مستويًا */
            transform: rotate(-8deg);
        }
        .seal svg { width: 26px; height: 26px; fill: var(--ink); }
        .stamp .t { font-weight: 700; }

        footer { max-width: 520px; margin: 14px auto 0; text-align: center; }
    </style>
</head>
<body>
<main class="sheet">
    <div class="band"></div>

    <div class="pad head">
        @if ($brand['logo'])
            <img class="logo" src="{{ $brand['logo'] }}" alt="">
        @endif
        <h1 class="shop">{{ $brand['name'] }}</h1>
        @if ($brand['sub'] !== '')
            <div class="muted sm">{{ $brand['sub'] }}</div>
        @endif
    </div>

    <div class="pad">
        <div class="eyebrow">{{ __('التحقق من المستند') }}</div>

        <div class="row">
            <span class="muted sm">{{ __('نوع المستند') }}</span>
            <strong>{{ $doc['type'] }}</strong>
        </div>
        <div class="row">
            <span class="muted sm">{{ __('رقم المستند') }}</span>
            <strong class="ltr">{{ $doc['number'] }}</strong>
        </div>
        <div class="row">
            <span class="muted sm">{{ __('التاريخ') }}</span>
            <span class="ltr">{{ $doc['date'] ?: '—' }}</span>
        </div>
        @if (filled($doc['due']))
            <div class="row">
                <span class="muted sm">{{ __('تاريخ الاستحقاق') }}</span>
                <span class="ltr">{{ $doc['due'] }}</span>
            </div>
        @endif
        <div class="row">
            <span class="muted sm">{{ __('الحالة') }}</span>
            <span class="pill {{ $doc['state'] }}">{{ $doc['status'] }}</span>
        </div>

        <div class="grand">
            <span class="muted sm">{{ __('الإجمالي') }}</span>
            <span class="v">{{ $doc['total'] }}</span>
        </div>
    </div>

    {{--
        والختمُ هنا وحده.

        الورقةُ المطبوعة تخرج من طابعة التاجر فلا تُثبت شيئًا عن نفسها،
        وهذه تُقرأ من خادم أبعاد — فالختمُ يقول إنّ ما تراه هو ما في
        الدفتر، لا صورةً عُدِّلت.
    --}}
    <div class="stamp">
        <div class="seal" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"/></svg>
        </div>
        <div>
            <div class="t sm">{{ __('موثّقة من أبعاد') }}</div>
            <div class="faint xs">
                {{ __('هذه نسخةٌ من سجلّ المتجر، عُرضت في') }}
                <span class="ltr">{{ $stampedAt }}</span>
            </div>
        </div>
    </div>
</main>

<footer class="faint xs">{{ __('أبعاد — نظام إدارة المتاجر') }}</footer>
</body>
</html>
