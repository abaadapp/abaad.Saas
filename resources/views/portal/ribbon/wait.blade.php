{{-- جهازٌ كُتب فيه رقمٌ وينتظر الاعتماد — ولا شيءَ من حسابٍ هنا، ولو كان الرقمُ مسجَّلًا --}}
@extends('portal.ribbon.layout')

@section('style')
    .door { max-width: 440px; margin: 5vh auto 0; text-align: center; }
    .door .card { padding: 26px 22px; margin-top: 18px; }
    .door .number { display: block; margin: 4px 0 14px; font-size: 54px; line-height: 1.15; font-weight: 600; letter-spacing: .14em; text-indent: .14em; color: var(--olive-d); direction: ltr; font-variant-numeric: tabular-nums; }
    .door .state { display: flex; align-items: center; justify-content: center; gap: 10px; margin: 18px 0 0; padding-top: 16px; border-top: 1px solid var(--line); color: var(--olive); font-weight: 500; }
    .door .dot { width: 9px; height: 9px; border-radius: 50%; background: var(--olive); animation: beat 1.4s ease-in-out infinite; } @keyframes beat { 50% { opacity: .25; } }
    @media (prefers-reduced-motion: reduce) { .door .dot { animation: none; } }
@endsection

@section('main')
    <div class="door">
        <h1>{{ $t('waitTitle') }}</h1>
        <p class="muted" style="margin:0">{{ $t('waitWhy', ['shop' => $shop]) }}</p>

        <div class="card" data-testid="portal-wait">
            <span class="muted sm">{{ $t('waitNumber') }}</span>
            <strong class="number" data-testid="portal-number">{{ $number }}</strong>
            <p style="margin:0">{{ $t('waitHow') }}</p>
            <p class="state" role="status"><span class="dot" aria-hidden="true"></span>{{ $t('waiting') }}</p>
        </div>

        <p class="err" id="offline" role="status" hidden>{{ $t('networkError') }}</p>
        <p class="muted sm" style="margin:14px 0 6px">{{ $t('waitNote', ['shop' => $shop]) }}</p>
        <form method="post" action="{{ route('my.logout', [], false) }}">@csrf<button class="btn sm text" type="submit" data-testid="portal-change">{{ $t('changePhone') }}</button></form>
        <noscript><p class="err">{{ $t('needJs') }}</p></noscript>
    </div>
@endsection

@section('script')
    <script nonce="{{ $nonce }}">
        (function () {
            var busy = false, began = Date.now(), next = null, offline = document.getElementById('offline');
            var ask = function () {
                if (document.hidden || busy) return; busy = true;
                fetch({{ \Illuminate\Support\Js::from(route('my.wait', [], false)) }}, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } }).then(function (r) {
                    if (!r.ok) throw r.status;
                    return r.json();
                }).then(function (d) {
                    offline.hidden = true;
                    if (d.state !== 'wait') location.reload();
                }).catch(function (e) { offline.hidden = e === 429; }).then(function () { busy = false; });
            };
            // سريعًا وهو واقفٌ أمام الموظّف، ثمّ على مهل — ولا سؤالَ من صفحةٍ لا تُرى
            var tick = function () { ask(); next = setTimeout(tick, Date.now() - began < 600000 ? 4000 : 20000); };
            next = setTimeout(tick, 4000);
            document.addEventListener('visibilitychange', function () { if (!document.hidden) { began = Date.now(); clearTimeout(next); tick(); } });
            window.addEventListener('pageshow', function (e) { if (e.persisted) ask(); });
        })();
    </script>
@endsection
