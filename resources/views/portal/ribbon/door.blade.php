{{-- بابُ البوابة: بلا رابطٍ لا حقلَ فيه — ومعه رقمُ الهاتف. والرابطُ في جزء `#` تقرؤه الصفحةُ ولا يبلغ الخادمَ في عنوان --}}
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

        <div class="card" id="how" data-testid="portal-public">
            <p style="margin:0">{{ $t('guestHow') }}</p>
            <p class="muted sm" style="margin:10px 0 0">{{ $t('guestAsk') }}</p>
            @if ($ask)
                <a class="btn" href="{{ $ask }}" target="_blank" rel="noopener noreferrer">{{ $t('askWhatsapp') }}</a>
            @endif
        </div>

        <form class="card" id="login" data-testid="portal-login" hidden autocomplete="on">
            <label for="phone">{{ $t('phoneLabel') }}</label>
            <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" maxlength="30" required>
            <p class="muted sm" style="margin:6px 0 0">{{ $t('phoneHint') }}</p>
            <p class="err" id="error" role="alert" hidden></p>
            <button class="btn main" id="enter" type="submit">{{ $t('enter') }}</button>
        </form>
    </div>
@endsection

@section('script')
    <script nonce="{{ $nonce }}">
        (function () {
            var texts = {{ \Illuminate\Support\Js::from(['enter' => $t('enter'), 'entering' => $t('entering'), 'network' => $t('networkError')]) }};
            var csrf = document.querySelector('meta[name=csrf-token]').content;
            var key = function () { return (location.hash || '').replace(/^#/, ''); };
            var form = document.getElementById('login'), how = document.getElementById('how'), error = document.getElementById('error'), enter = document.getElementById('enter');
            var show = function () { var has = /^[A-Za-z0-9_-]{43}$/.test(key()); form.hidden = !has; how.hidden = has; if (has) document.getElementById('phone').focus(); };
            var lang = document.querySelector('[data-keep-hash]');
            // تبديلُ اللغة لا يُسقط الرابط
            lang.addEventListener('click', function () { if (location.hash) lang.href = lang.href.split('#')[0] + location.hash; });
            window.addEventListener('hashchange', show); show();
            form.addEventListener('submit', function (e) {
                e.preventDefault(); error.hidden = true; enter.disabled = true; enter.textContent = texts.entering;
                fetch({{ \Illuminate\Support\Js::from(route('my.login', [], false)) }}, {
                    method: 'POST', credentials: 'same-origin', referrerPolicy: 'no-referrer',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ key: key(), phone: document.getElementById('phone').value }),
                }).then(function (r) {
                    if (r.status === 419) { location.reload(); return; }
                    return r.json().catch(function () { return {}; }).then(function (d) {
                        // والرابطُ يُنزع من العنوان وسجلّ المتصفّح متى أدّى غرضَه
                        if (r.ok && d.ok) { history.replaceState(null, '', location.pathname); location.reload(); return; }
                        throw d.message || texts.network;
                    });
                }).catch(function (m) {
                    error.textContent = typeof m === 'string' ? m : texts.network; error.hidden = false;
                    enter.disabled = false; enter.textContent = texts.enter;
                });
            });
        })();
    </script>
@endsection
