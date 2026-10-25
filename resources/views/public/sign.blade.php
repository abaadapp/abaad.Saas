{{--
    مستندٌ من أبعاد إلى عميلها — يُقرأ ويُكمَل ويُوقَّع من الهاتف.

    صفحةٌ واحدة بلا أصولٍ من خارج المنصّة ولا أدواتِ تحليل: تُفتح من رابطٍ في واتساب
    على شبكةٍ قد تكون ضعيفة، وفيها رقمٌ مدنيّ. والتوقيعُ لوحةٌ يُرسم عليها بالإصبع
    أو الماوس، تُرسَل صورتُها PNG — ومن غير جافاسكربت لا توقيع، والصفحةُ تقول ذلك.

    و`noindex`: الرابطُ لا يُخمَّن، لكنّ محرّك بحثٍ يزحف إليه من مشاركةٍ عابرة
    يجعل عقد عميلٍ مقروءًا للجميع.

    والإرسالُ طلبُ JSON لا انتقالُ صفحة: إن انقطعت الشبكة بقي الرسمُ والبيانُ في
    الصفحة وقيل ذلك، ولا يُقال «تم» إلّا بعد أن يحفظه الخادم.
--}}
@use('App\Models\PlatformDocument')
@use('App\Support\PlatformDocuments')
@php
    $state = $doc->state();
    $signed = $state === PlatformDocument::SIGNED;
    $open = $state === PlatformDocument::SENT;
    $client = $doc->client ?? [];
    $plan = $doc->plan;
    $val = fn (string $k) => old('client.'.$k, $client[$k] ?? '');
    // أقسامُ الصفحة مرقّمةٌ بترتيب ظهورها — ونموذجُ المستند يضيف أقسامَه بينها
    $num = 0;
    $next = function () use (&$num) { return str_pad((string) ++$num, 2, '0', STR_PAD_LEFT); };
    $form ??= null;
    // معاينةُ القالب لمدير المنصّة — الصفحةُ نفسُها بلا مستندٍ ولا إرسال (`DocumentTemplateController::preview`)
    $preview ??= false;
    // مقدّمُ الخدمة بما كُتب منه وحده: الاسمُ الذي يُخاطَب به العميل، وإن غاب قيلت الجملةُ بلا اسم
    $shown = PlatformDocuments::providerName($provider);
    $contact = $shown !== '' ? $shown : 'مُرسِل المستند';
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ $doc->title }}@if ($shown !== '') — {{ $shown }}@endif</title>
    <style>
        :root {
            --ink: #0f0f0f; --muted: #6b7280; --faint: #9ca3af;
            --rule: #e7e8ea; --card: #fff; --soft: #f6f6f5;
            --ok: #047857; --bad: #b91c1c;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 16px 12px 48px;
            background: #f2f2f1; color: var(--ink);
            font: 15px/1.7 -apple-system, "Segoe UI", system-ui, "Helvetica Neue", Arial, sans-serif;
        }
        .sheet { max-width: 760px; margin: 0 auto; background: var(--card); border: 1px solid var(--rule); border-radius: 18px; overflow: hidden; }
        .pad { padding: 22px 20px; }
        .rule { border-top: 1px solid var(--rule); }
        .muted { color: var(--muted); }
        .faint { color: var(--faint); }
        .xs { font-size: 12px; }
        .sm { font-size: 13.5px; }
        .ltr { direction: ltr; unicode-bidi: isolate; }
        .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
        .logo { height: 26px; width: auto; display: block; margin-bottom: 6px; }
        .brand { font-size: 15px; font-weight: 700; }
        h1 { font-size: 24px; margin: 0; line-height: 1.3; }
        h1 small { display: block; font-size: 13px; font-weight: 500; color: var(--muted); }
        .meta { text-align: end; font-size: 13px; color: var(--muted); }
        .meta b { color: var(--ink); font-weight: 600; }
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .party { background: var(--soft); border-radius: 12px; padding: 12px 14px; }
        .party .who { font-weight: 700; font-size: 16px; }
        h2 { font-size: 15px; margin: 0 0 12px; display: flex; gap: 8px; align-items: baseline; }
        h2 .n { color: var(--faint); font-variant-numeric: tabular-nums; }
        h2 .en { color: var(--faint); font-weight: 500; font-size: 12px; }
        .fields { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 12px; }
        label.f { display: block; font-size: 12.5px; color: var(--muted); }
        label.f .req { color: var(--bad); }
        label.f .en { color: var(--faint); font-size: 11px; margin-inline-start: 4px; }
        input.t {
            width: 100%; margin-top: 4px; padding: 10px 12px; font: inherit; font-size: 15px;
            border: 1px solid var(--rule); border-radius: 10px; background: #fff; color: var(--ink);
        }
        input.t:focus { outline: 2px solid #11111122; border-color: var(--ink); }
        .ro { margin-top: 4px; padding: 10px 12px; border-radius: 10px; background: var(--soft); min-height: 44px; }
        .plan { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; border: 1.5px solid var(--ink); border-radius: 14px; padding: 14px 16px; }
        .plan .name { font-weight: 700; font-size: 17px; }
        .price { font-size: 22px; font-weight: 800; white-space: nowrap; }
        .price small { font-size: 13px; font-weight: 500; color: var(--muted); }
        .term { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 8px; margin-top: 10px; }
        .term div { background: var(--soft); border-radius: 10px; padding: 8px 12px; }
        .term span { display: block; font-size: 12px; color: var(--muted); }
        .term b { font-weight: 700; }
        ol.terms { margin: 0; padding: 0 18px 0 0; }
        ol.terms li { margin-bottom: 8px; }
        ol.terms b { font-weight: 700; }
        .agree { display: flex; gap: 10px; align-items: flex-start; background: var(--soft); border-radius: 12px; padding: 12px 14px; cursor: pointer; }
        .agree input { width: 20px; height: 20px; margin-top: 3px; accent-color: #0f0f0f; flex-shrink: 0; }
        .pad-sig { position: relative; margin-top: 12px; border: 1.5px dashed #c9cbcf; border-radius: 14px; background: #fff; touch-action: none; }
        canvas#sig { display: block; width: 100%; height: 190px; border-radius: 14px; cursor: crosshair; }
        .sig-hint { position: absolute; inset-inline: 0; top: 50%; transform: translateY(-50%); text-align: center; color: var(--faint); pointer-events: none; font-size: 14px; }
        .row { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 8px; flex-wrap: wrap; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 12px 18px; border-radius: 12px; border: 1px solid var(--ink); background: var(--ink); color: #fff;
            font: inherit; font-weight: 700; font-size: 15px; cursor: pointer; text-decoration: none;
        }
        .btn.ghost { background: #fff; color: var(--ink); border-color: var(--rule); font-weight: 600; }
        .btn[disabled] { opacity: .4; cursor: not-allowed; }
        .wide { width: 100%; }
        .alert { border-radius: 12px; padding: 12px 14px; font-size: 14px; }
        .alert.bad { background: #fef2f2; color: #7f1d1d; }
        .alert.ok { background: #ecfdf5; color: #065f46; }
        .alert.info { background: var(--soft); color: var(--ink); }
        .stamp { display: flex; align-items: center; gap: 10px; color: var(--ok); font-weight: 700; }
        .stamp .tick { width: 30px; height: 30px; border-radius: 50%; background: var(--ok); color: #fff; display: inline-flex; align-items: center; justify-content: center; }
        .sig-img { max-width: 260px; max-height: 110px; display: block; margin-top: 8px; }
        .hash { word-break: break-all; }
        footer { max-width: 760px; margin: 14px auto 0; text-align: center; }
        .highlights { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px; }
        .highlights div { background: var(--soft); border-radius: 12px; padding: 10px 12px; font-size: 13.5px; }
        .highlights span { display: block; font-size: 12px; color: var(--muted); }
        .para { margin: 0 0 10px; }
        ul.items { margin: 0 0 10px; padding: 0 18px 0 0; }
        ul.items li { margin-bottom: 4px; }
        .note { background: var(--soft); border-radius: 10px; padding: 10px 12px; font-size: 13.5px; margin: 8px 0; }
        .after { font-size: 12.5px; color: var(--muted); margin: -6px 0 10px; }
        .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .col { border: 1px solid var(--rule); border-radius: 12px; padding: 10px 12px; }
        .col-head { font-weight: 700; font-size: 13.5px; margin-bottom: 6px; }
        .form-field { margin-bottom: 10px; }
        .checks { margin: 6px 0 10px; }
        label.check { display: flex; gap: 8px; align-items: flex-start; margin: 4px 0; font-size: 14px; cursor: pointer; }
        label.check input { width: 18px; height: 18px; margin-top: 3px; accent-color: #0f0f0f; flex-shrink: 0; }
        .box { width: 18px; height: 18px; border: 1px solid var(--muted); border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; flex-shrink: 0; margin-top: 3px; }
        .yesno { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; margin: 8px 0; }
        .grid-wrap { overflow-x: auto; margin: 8px 0 10px; }
        table.grid { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 460px; }
        table.grid th, table.grid td { border: 1px solid var(--rule); padding: 6px 8px; text-align: start; vertical-align: middle; }
        table.grid th { background: var(--soft); font-weight: 600; }
        table.grid .t { margin-top: 0; padding: 6px 8px; font-size: 13px; }
        table.grid input[type=checkbox] { width: 18px; height: 18px; accent-color: #0f0f0f; }
        .lead { margin: 0 0 10px; font-size: 14px; }
        @media (max-width: 560px) {
            .parties, .fields, .cols { grid-template-columns: 1fr; }
            .meta { text-align: start; }
            h1 { font-size: 21px; }
        }
        @media print {
            body { background: #fff; padding: 0; }
            .sheet { border: none; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
@if ($preview)
    <div class="alert info no-print" id="preview-banner" style="max-width:820px; margin:12px auto 0">معاينة من القالب (الإصدار {{ $doc->template_version }}) كما سيراها العميل — ليست مستندًا صادرًا، ولا يُرسل منها توقيع.</div>
@endif
<div class="sheet">
    <div class="pad top">
        <div>
            <img class="logo" src="{{ asset('brand/abaad/logo.svg') }}" alt="{{ $shown }}">
            @if ($shown !== '')<div class="brand">{{ $shown }}</div>@endif
            <div class="xs muted">
                @if ($provider['website'])<span class="ltr">{{ $provider['website'] }}</span>@endif
                @if ($provider['phone']) · <span class="ltr">{{ $provider['phone'] }}</span>@endif
            </div>
            @if ($provider['email'])<div class="xs muted ltr">{{ $provider['email'] }}</div>@endif
        </div>
        <div class="meta">
            <h1>{{ $doc->title }}@if ($doc->title_en)<small>{{ $doc->title_en }}</small>@endif</h1>
            <div>رقم المستند · No. <b class="ltr">{{ $doc->number }}</b></div>
            <div>التاريخ · Date <b class="ltr">{{ $doc->created_at->format('Y-m-d') }}</b></div>
        </div>
    </div>

    @if ($readable)
    <div class="pad rule">
        <div class="parties">
            <div class="party">
                <div class="xs muted">الطرف الأول · مزوّد الخدمة <span class="faint">Provider</span></div>
                {{-- بياناتُه القانونيّة كما نُسخت في المستند يومَ إصداره — ما كُتب منها وحده --}}
                @if (trim((string) $provider['name']) !== '')<div class="who" id="provider-name">{{ $provider['name'] }}</div>@endif
                @if ($provider['trade_name'])<div class="xs muted" id="provider-trade">الاسم التجاري للمنصة: {{ $provider['trade_name'] }}</div>@endif
                @if ($provider['cr'])<div class="xs muted" id="provider-cr">السجل التجاري رقم <span class="ltr">{{ $provider['cr'] }}</span></div>@endif
                @if ($provider['address'])<div class="xs muted" id="provider-address">{{ $provider['address'] }}</div>@endif
            </div>
            <div class="party">
                <div class="xs muted">الطرف الثاني · العميل <span class="faint">Customer</span></div>
                <div class="who">{{ $val('name') ?: '—' }}</div>
                <div class="xs muted">{{ $val('biz') ?: 'اسم النشاط' }}</div>
            </div>
        </div>
    </div>
    @endif

    {{-- الرابطُ المغلق يقول حالَه وحدها — لا بيانَ عميلٍ ولا باقةَ ولا توقيع --}}
    @if ($state === PlatformDocument::CANCELLED)
        <div class="pad rule"><div class="alert bad">أُلغي هذا المستند ولم يعد يقبل التوقيع. تواصل مع {{ $contact }} للحصول على رابط جديد.</div></div>
    @elseif ($state === 'expired')
        <div class="pad rule"><div class="alert bad">انتهت صلاحية هذا الرابط. تواصل مع {{ $contact }} للحصول على رابط جديد.</div></div>
    @elseif (! $readable && $doc->copyOpen())
        {{-- رابطٌ مُرِّر بعد التوقيع: النسخةُ على جهاز الموقِّع وحده --}}
        <div class="pad rule"><div class="alert info">وُقِّع هذا المستند. ولحماية بيانات العميل وتوقيعه لا تُعرض نسخته الموقَّعة إلا على الجهاز والمتصفح الذي وُقِّع منه. تواصل مع {{ $contact }} للحصول على نسختك.</div></div>
    @elseif (! $readable)
        <div class="pad rule"><div class="alert info">وُقِّع هذا المستند، وانتهت مدة عرض نسخته عبر هذا الرابط. تواصل مع {{ $contact }} للحصول على نسختك.</div></div>
    @endif

    @if (session('done'))
        <div class="pad rule no-print" id="done"><div class="alert ok">شكرًا لك — تم إرسال توقيعك وحفظ المستند موقّعًا، ووصلت نسخته إلى {{ $contact }}. نزّل نسختك PDF من أسفل الصفحة.</div></div>
    @endif

    @if ($readable)
    <form method="POST" action="{{ $preview ? '#' : route('documents.sign.submit', $token) }}" id="sign-form">
        @csrf

        @if ($form && (! empty($form['highlights']) || ! empty($form['intro'])))
            <div class="pad rule">
                @if (! empty($form['intro']))<p class="para">{{ $form['intro'] }}</p>@endif
                @if (! empty($form['highlights']))
                    <div class="highlights">@foreach ($form['highlights'] as $h)<div><span>{{ $h['label'] }}</span>{{ $h['text'] }}</div>@endforeach</div>
                @endif
            </div>
        @endif

        @unless ($asksClient ?? false)
        <div class="pad rule">
            <h2><span class="n">{{ $next() }} —</span> بيانات العميل <span class="en">Customer details</span></h2>
            <div class="fields">
                @foreach ($fields as $f)
                    <label class="f">
                        {{ $f['ar'] }}@if ($f['req'])<span class="req"> *</span>@endif<span class="en">{{ $f['en'] }}</span>
                        @if ($open && $f['k'] !== 'phone')
                            <input class="t" name="client[{{ $f['k'] }}]" value="{{ $val($f['k']) }}"
                                   type="{{ $f['type'] ?? 'text' }}" dir="{{ $f['dir'] ?? 'rtl' }}"
                                   @if ($f['req']) required @endif maxlength="190" autocomplete="off">
                        @else
                            <div class="ro" dir="{{ $f['dir'] ?? 'rtl' }}">{{ $client[$f['k']] ?? '—' }}</div>
                        @endif
                    </label>
                @endforeach
            </div>
        </div>
        @endunless

        @php $amountHere = $doc->amount !== null && ! ($amountInForm ?? false); @endphp
        @if ($plan || $amountHere)
            <div class="pad rule">
                <h2><span class="n">{{ $next() }} —</span> {{ $plan ? 'الباقة ومدة الاشتراك' : 'الخدمة والمبلغ' }} <span class="en">{{ $plan ? 'Plan & term' : 'Service & amount' }}</span></h2>
                @if ($plan)
                    <div class="plan">
                        <div>
                            <div class="name">{{ $plan['name'] }} · {{ $plan['cycle_label'] }}</div>
                            <div class="sm muted">{{ $plan['limits'] }}</div>
                        </div>
                        <div class="price"><span class="ltr">{{ $plan['price'] }}</span> <small>ر.ع / {{ $plan['unit'] }}</small></div>
                    </div>
                    @if ($doc->term_starts_on)
                        <div class="term" id="term">
                            <div><span>مدة الاشتراك</span><b>{{ $plan['duration'] ?? $plan['cycle_label'] }}</b></div>
                            <div><span>تبدأ في</span><b class="ltr">{{ PlatformDocuments::day($doc->term_starts_on) }}</b></div>
                            <div><span>تنتهي في</span><b class="ltr">{{ PlatformDocuments::day($doc->term_ends_on) }}</b></div>
                        </div>
                    @endif
                @endif
                @if ($amountHere)
                    <div class="plan" @if ($plan) style="margin-top:10px" @endif>
                        <div class="name">{{ $doc->amount_label }}</div>
                        <div class="price"><span class="ltr">{{ number_format((float) $doc->amount, 3) }}</span> <small>ر.ع</small></div>
                    </div>
                @endif
            </div>
        @endif

        @if ($form)
            @include('public.partials.document-form')
        @else
            <div class="pad rule">
                <h2><span class="n">{{ $next() }} —</span> الشروط والأحكام <span class="en">Terms &amp; conditions</span></h2>
                <ol class="terms">
                    @foreach ($doc->terms as $term)
                        <li>@if ($term['h'] ?? '')<b>{{ $term['h'] }}</b> @endif{{ $term['b'] }}</li>
                    @endforeach
                </ol>
            </div>
        @endif

        <div class="pad rule">
            <h2><span class="n">{{ $next() }} —</span> التوقيع <span class="en">Signature</span></h2>

            @if ($signed)
                <div class="stamp"><span class="tick">✓</span> تم توقيع المستند · Signed</div>
                <div class="sm muted" style="margin-top:4px">بتاريخ <span class="ltr">{{ PlatformDocuments::date($doc->signed_at) }}</span></div>
                @if ($signature)<img class="sig-img" src="{{ $signature }}" alt="التوقيع">@endif
                <div class="xs faint hash" style="margin-top:10px">بصمة المستند (SHA-256): <span class="ltr">{{ $doc->content_hash }}</span></div>
                <div class="row no-print" style="margin-top:16px">
                    <a class="btn wide" href="{{ route('documents.sign.pdf', $token) }}" id="pdf">تنزيل نسختك PDF</a>
                </div>
                <p class="xs muted no-print" style="margin:10px 0 0">تبقى نسختك على هذا الجهاز وهذا المتصفح وحدهما حتى <span class="ltr">{{ PlatformDocuments::day($doc->copyUntil()) }}</span>، فنزّلها الآن واحتفظ بها. من يفتح الرابط من جهاز آخر لا يرى بياناتك ولا توقيعك، وإن مسحت بيانات المتصفح فاطلب نسختك من {{ $contact }}.</p>
            @elseif ($open)
                @if ($errors->any())
                    <div class="alert bad" style="margin-bottom:12px">{{ $errors->first() }}</div>
                @endif
                @if ($form && ! empty($form['consent']))<p class="lead" id="consent-lead">{{ $form['consent'] }}</p>@endif
                <label class="agree">
                    <input type="checkbox" name="agree" value="1" id="agree" @checked(old('agree'))>
                    <span>{{ $form['agree'] ?? $consent }}</span>
                </label>
                <div class="pad-sig">
                    <canvas id="sig" aria-label="لوحة التوقيع"></canvas>
                    <div class="sig-hint" id="sig-hint">وقّع هنا بالإصبع أو الماوس · Sign here</div>
                </div>
                <input type="hidden" name="signature" id="signature">
                <div class="row">
                    <span class="xs muted" id="sig-state">التوقيع مطلوب لإرسال المستند</span>
                    <button type="button" class="btn ghost" id="clear">مسح التوقيع</button>
                </div>
                <noscript><div class="alert bad" style="margin-top:12px">التوقيع يحتاج تفعيل JavaScript في المتصفح.</div></noscript>
                <div class="alert bad" id="send-error" role="alert" style="margin-top:12px; display:none"></div>
                <div style="margin-top:16px">
                    <button type="submit" class="btn wide" id="submit" disabled>إرسال التوقيع</button>
                    <div class="xs muted" id="missing" style="margin-top:6px; text-align:center"></div>
                </div>
            @endif
            @if ($form && ! empty($form['footer']))<p class="xs muted" style="margin:12px 0 0" id="form-footer">{{ $form['footer'] }}</p>@endif
        </div>
    </form>
    @endif
</div>
<footer class="xs faint">@if ($shown !== '')© {{ $shown }}@endif @if ($shown !== '' && $provider['website'])· @endif @if ($provider['website'])<span class="ltr">{{ $provider['website'] }}</span>@endif</footer>

@if ($open && ! $preview)
<script>
(function () {
    var form = document.getElementById('sign-form');
    var canvas = document.getElementById('sig');
    var hint = document.getElementById('sig-hint');
    var out = document.getElementById('signature');
    var agree = document.getElementById('agree');
    var submit = document.getElementById('submit');
    var missing = document.getElementById('missing');
    var state = document.getElementById('sig-state');
    var error = document.getElementById('send-error');
    var drawn = false, drawing = false, sending = false, ctx;

    function setup(keep) {
        var prev = keep && drawn ? canvas.toDataURL('image/png') : null;
        var r = canvas.getBoundingClientRect(), dpr = window.devicePixelRatio || 1;
        canvas.width = Math.round(r.width * dpr); canvas.height = Math.round(r.height * dpr);
        ctx = canvas.getContext('2d');
        ctx.scale(dpr, dpr); ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0f0f0f';
        if (prev) { var im = new Image(); im.onload = function () { ctx.drawImage(im, 0, 0, r.width, r.height); }; im.src = prev; }
    }
    function pt(e) { var b = canvas.getBoundingClientRect(); return [e.clientX - b.left, e.clientY - b.top]; }

    canvas.addEventListener('pointerdown', function (e) {
        drawing = true; canvas.setPointerCapture(e.pointerId);
        var p = pt(e); ctx.beginPath(); ctx.moveTo(p[0], p[1]); ctx.lineTo(p[0] + .1, p[1] + .1); ctx.stroke();
    });
    canvas.addEventListener('pointermove', function (e) {
        if (!drawing) return;
        var p = pt(e); ctx.lineTo(p[0], p[1]); ctx.stroke();
        if (!drawn) { drawn = true; hint.style.display = 'none'; check(); }
    });
    function end() { drawing = false; }
    canvas.addEventListener('pointerup', end);
    canvas.addEventListener('pointercancel', end);

    document.getElementById('clear').addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height); drawn = false; hint.style.display = ''; check();
    });

    var required = Array.prototype.slice.call(form.querySelectorAll('input.t[required]'));
    function check() {
        var empty = required.filter(function (i) { return !i.value.trim(); })
            .map(function (i) { return i.closest('label').firstChild.textContent.trim(); });
        var msg = empty.length ? 'أكمل: ' + empty.join('، ') : !agree.checked ? 'وافق على الشروط' : !drawn ? 'أضف توقيعك' : '';
        missing.textContent = msg;
        state.textContent = drawn ? 'تم إضافة التوقيع' : 'التوقيع مطلوب لإرسال المستند';
        submit.disabled = sending || !!msg;
    }
    required.forEach(function (i) { i.addEventListener('input', check); });
    agree.addEventListener('change', check);

    // يُرسَل طلبًا لا انتقالًا: فشلُ الشبكة يُبقي الرسمَ والبيانَ ويقول ذلك، والنجاحُ يُقال بعد الحفظ وحده
    function fail(message) {
        sending = false;
        error.textContent = message;
        error.style.display = '';
        submit.textContent = 'إرسال التوقيع';
        check();
    }
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (sending || submit.disabled) return;
        sending = true;
        out.value = canvas.toDataURL('image/png');
        error.style.display = 'none';
        submit.disabled = true; submit.textContent = 'جارٍ الإرسال…';

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (res.ok && data.ok) { window.location.replace(data.redirect); return; }
                if (res.status === 409 && data.redirect) {
                    fail(data.message || 'هذا المستند موقّع بالفعل.');
                    setTimeout(function () { window.location.replace(data.redirect); }, 1500);
                    return;
                }
                if (res.status === 419) { fail('انتهت جلسة الصفحة — حدّث الصفحة ثم وقّع مرة أخرى.'); return; }
                if (res.status === 429) { fail('محاولات كثيرة — انتظر دقيقة ثم اضغط «إرسال التوقيع» مرة أخرى.'); return; }
                fail(data.message || 'تعذّر حفظ التوقيع — اضغط «إرسال التوقيع» مرة أخرى.');
            });
        }).catch(function () {
            fail('تعذّر الاتصال بالخادم — تحقّق من الإنترنت ثم اضغط «إرسال التوقيع» مرة أخرى. توقيعك ما زال في الصفحة ولم يُحفظ بعد.');
        });
    });

    setup(false);
    window.addEventListener('resize', function () { setup(true); });
    check();
})();
</script>
@endif
</body>
</html>
