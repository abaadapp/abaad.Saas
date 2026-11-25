{{--
    جهازٌ كُتب فيه رقمٌ وينتظر الاعتماد — ولا شيءَ من حسابٍ هنا، ولو كان الرقمُ مسجَّلًا.

    ورقمُه عشرُ دقائق ثمّ يُطلب غيرُه من الصفحة نفسها، بلا كتابة الهاتف ثانية.
--}}
@extends('portal.ribbon.layout')

@section('style')
    .door { max-width: 440px; margin: 5vh auto 0; text-align: center; }
    .door .card { padding: 26px 22px; margin-top: 18px; }
    .door .number { display: block; margin: 4px 0 14px; font-size: clamp(36px, 12vw, 52px); line-height: 1.15; font-weight: 600; letter-spacing: .06em; text-indent: .06em; white-space: nowrap; color: var(--olive-d); direction: ltr; font-variant-numeric: tabular-nums; }
    .door .state { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 4px 10px; margin: 18px 0 0; padding-top: 16px; border-top: 1px solid var(--line); color: var(--olive); font-weight: 500; }
    .door .dot { width: 9px; height: 9px; border-radius: 50%; background: var(--olive); animation: beat 1.4s ease-in-out infinite; } @keyframes beat { 50% { opacity: .25; } }
    .door #again { width: 100%; margin-top: 16px; }
    @media (prefers-reduced-motion: reduce) { .door .dot { animation: none; } }
@endsection

@section('main')
    <div class="door">
        <h1>{{ $t('waitTitle') }}</h1>
        <p class="muted" style="margin:0">{{ $t('waitWhy', ['shop' => $shop]) }}</p>

        <div class="card" data-testid="portal-wait" data-left="{{ $left }}">
            @if ($number !== null)
                <span class="muted sm">{{ $t('waitNumber') }}</span>
                <strong class="number" data-testid="portal-number">{{ implode(' ', str_split($number, 3)) }}</strong>
                <p style="margin:0">{{ $t('waitHow') }}</p>
                <p class="muted sm" style="margin:8px 0 0">{{ $t('waitFar') }}</p>
                <p class="state" role="status"><span class="dot" aria-hidden="true"></span>{{ $t('waiting') }}</p>
                <p class="muted sm" style="margin:6px 0 0">{{ $t('waitLeft') }} <span class="num" id="left" data-testid="portal-left">{{ sprintf('%d:%02d', intdiv($left, 60), $left % 60) }}</span></p>
            @else
                <p style="margin:0" role="status" data-testid="portal-over">{{ $t('waitOver') }}</p>
                <button class="btn main" type="button" id="again" data-testid="portal-again">{{ $t('again') }}</button>
                <p class="err" id="error" role="alert" hidden></p>
            @endif
        </div>

        <p class="err" id="offline" role="status" hidden>{{ $t('networkError') }}</p>
        <p class="muted sm" style="margin:14px 0 6px">{{ $t('waitNote', ['shop' => $shop]) }}</p>
        <form method="post" action="{{ route('my.logout', [], false) }}" data-leave>@csrf<button class="btn sm text" type="submit" data-testid="portal-change">{{ $t('changePhone') }}</button></form>
        <noscript><p class="err">{{ $t('needJs') }}</p></noscript>
    </div>
@endsection

@section('script')
    <script nonce="{{ $nonce }}">
        (function () {
            var url = {{ \Illuminate\Support\Js::from(['wait' => route('my.wait', [], false), 'again' => route('my.again', [], false)]) }};
            var texts = {{ \Illuminate\Support\Js::from(['slow' => $t('slowDown'), 'network' => $t('networkError')]) }};
            var $ = function (id) { return document.getElementById(id); };
            var again = $('again'), state = again ? 'over' : 'wait', busy = false, until = Date.now() + {{ $left }} * 1000, next = null;

            var ask = function () {
                if (document.hidden || busy) return; busy = true;
                fetch(url.wait, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } }).then(function (r) {
                    if (!r.ok) throw r.status;
                    return r.json();
                }).then(function (d) {
                    $('offline').hidden = true;
                    // اعتُمد، أو انقضى رقمُه، أو طُلب له غيرُه من نافذةٍ أخرى
                    if (d.state !== state) location.reload();
                }).catch(function (e) { $('offline').hidden = e === 429; }).then(function () { busy = false; });
            };
            // سريعًا ورقمُه يصلح، وعلى مهلٍ بعده — ولا سؤالَ من صفحةٍ لا تُرى
            var tick = function () { ask(); next = setTimeout(tick, state === 'wait' ? 4000 : 20000); };
            next = setTimeout(tick, 4000);
            document.addEventListener('visibilitychange', function () { if (!document.hidden) { clearTimeout(next); tick(); } });
            window.addEventListener('pageshow', function (e) { if (e.persisted) ask(); });

            if (!again) {
                setInterval(function () {
                    var s = Math.max(0, Math.round((until - Date.now()) / 1000));
                    $('left').textContent = Math.floor(s / 60) + ':' + ('0' + s % 60).slice(-2);
                }, 1000);
                return;
            }
            again.addEventListener('click', function () {
                again.disabled = true; $('error').hidden = true;
                var say = function (m) { $('error').textContent = m; $('error').hidden = false; again.disabled = false; };
                fetch(url.again, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } }).then(function (r) {
                    // صفحةٌ بقيت مفتوحةً أو طلبٌ مضى يومُه: تُرسم من جديد
                    if (r.ok || r.status === 419 || r.status === 410) { location.reload(); return; }
                    return r.json().catch(function () { return {}; }).then(function (d) { say(r.status === 429 && !d.wait ? texts.slow : d.message || texts.network); });
                }).catch(function () { say(texts.network); });
            });
        })();
    </script>
@endsection
