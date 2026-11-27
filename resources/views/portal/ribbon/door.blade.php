{{--
    بابُ البوابة — رابطٌ واحدٌ لجميع العملاء وخانةٌ واحدة: رقمُ الهاتف.

    تفتح به صفحةُ صاحبه ويُذكر الجهاز (`RibbonPortal\Access::enter`).
--}}
@extends('portal.ribbon.layout')

@section('style')
    .door { max-width: 440px; margin: 5vh auto 0; text-align: center; }
    .door .card { padding: 26px 22px; margin-top: 18px; text-align: start; }
    .door label { display: block; font-weight: 500; margin-bottom: 6px; } .door .btn { width: 100%; margin-top: 14px; }
@endsection

@section('main')
    <div class="door">
        <h1>{{ $shop }}</h1>
        <p class="muted" style="margin:0">{{ $t('welcomeGuest') }}</p>

        <noscript><p class="err">{{ $t('needJs') }}</p></noscript>

        <form class="card" id="login" data-testid="portal-login" autocomplete="on" novalidate>
            <label for="phone">{{ $t('phoneLabel') }}</label>
            <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" maxlength="30" autofocus>
            <p class="muted sm" style="margin:6px 0 0">{{ $t('phoneHint') }}</p>
            <p class="err" id="error" role="alert" hidden></p>
            <button class="btn main" id="enter" type="submit">{{ $t('enter') }}</button>
        </form>
    </div>
@endsection

@section('script')
    <script nonce="{{ $nonce }}">
        (function () {
            var texts = {{ \Illuminate\Support\Js::from(['enter' => $t('enter'), 'entering' => $t('entering'), 'network' => $t('networkError'), 'phone' => $t('phoneShort'), 'slow' => $t('slowDown')]) }};
            var $ = function (id) { return document.getElementById(id); };
            // أرقامٌ عربيّةٌ وفارسيّة تُعدّ أرقامًا
            var count = function (v) { return (v.match(/[0-9٠-٩۰-۹]/g) || []).length; };
            var say = function (m) { $('error').textContent = m; $('error').hidden = false; };

            $('login').addEventListener('submit', function (e) {
                e.preventDefault(); $('error').hidden = true;
                if (count($('phone').value) < 8) { say(texts.phone); $('phone').focus(); return; }
                var enter = $('enter'); enter.disabled = true; enter.textContent = texts.entering;
                fetch({{ \Illuminate\Support\Js::from(route('my.login', [], false)) }}, {
                    method: 'POST', credentials: 'same-origin', referrerPolicy: 'no-referrer',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ phone: $('phone').value }),
                }).then(function (r) {
                    if (r.status === 419) { location.reload(); return; }
                    return r.json().catch(function () { return {}; }).then(function (d) {
                        if (r.ok && d.ok) { location.reload(); return; }
                        throw r.status === 429 && !d.wait ? texts.slow : d.message || texts.network;
                    });
                }).catch(function (m) { say(typeof m === 'string' ? m : texts.network); enter.disabled = false; enter.textContent = texts.enter; });
            });
        })();
    </script>
@endsection
