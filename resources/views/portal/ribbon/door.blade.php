{{--
    بابُ البوابة — رابطٌ واحدٌ لجميع العملاء: رقمُ الهاتف أوّلًا، ثمّ رمزُ الفاتورة مرّةً على الجهاز.

    والرقمُ لا يُرسَل وحدَه: الخطوتان في الصفحة والطلبُ واحد، فلا يُعرف منها أرقمٌ مسجَّلٌ أم لا.
    ورابطٌ شخصيٌّ صدر قبل الرابط الواحد (في جزء `#`) يكفي معه الرقم.
--}}
@extends('portal.ribbon.layout')

@section('style')
    .door { max-width: 440px; margin: 5vh auto 0; text-align: center; }
    .door .card { padding: 26px 22px; margin-top: 18px; text-align: start; }
    .door label { display: block; font-weight: 500; margin-bottom: 6px; } .door .btn { width: 100%; margin-top: 14px; }
    .door #code { text-align: center; font-size: 22px; letter-spacing: .12em; font-variant-numeric: tabular-nums; min-height: 54px; }
    .door .who { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 16px; padding-bottom: 14px; border-bottom: 1px solid var(--line); }
    .door .who button { border: 0; background: none; padding: 8px; color: var(--olive); text-decoration: underline; cursor: pointer; font-size: 13px; }
    .door .where { display: flex; align-items: center; gap: 12px; margin: 12px 0 0; } .door .where svg { flex: 0 0 auto; width: 52px; height: auto; color: var(--olive); }
@endsection

@section('main')
    <div class="door">
        <h1>{{ $shop }}</h1>
        <p class="muted" style="margin:0">{{ $t('welcomeGuest') }}</p>

        <noscript><p class="err">{{ $t('needJs') }}</p></noscript>

        <form class="card" id="login" data-testid="portal-login" autocomplete="on" novalidate>
            <div id="step-phone" data-testid="portal-step-phone">
                <label for="phone">{{ $t('phoneLabel') }}</label>
                <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" maxlength="30">
                <p class="muted sm" style="margin:6px 0 0">{{ $t('phoneHint') }}</p>
            </div>

            <div id="step-code" data-testid="portal-step-code" hidden>
                <div class="who"><span class="num" id="who"></span><button type="button" id="change" data-testid="portal-change">{{ $t('changePhone') }}</button></div>
                <label for="code">{{ $t('codeLabel') }}</label>
                <input id="code" name="code" type="text" inputmode="numeric" autocomplete="off" dir="ltr" maxlength="15" placeholder="000 000 000">
                <div class="where">
                    {{-- أين هو: أسفلَ الفاتورة --}}
                    <svg viewBox="0 0 52 60" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 2h40v56l-6.7-4-6.6 4-6.7-4-6.7 4-6.6-4L6 58z"/><path d="M14 13h24M14 21h24M14 29h15" opacity=".35"/><rect x="12" y="37" width="28" height="11" rx="2.5" fill="currentColor" stroke="none"/></svg>
                    <p class="muted sm" style="margin:0">{{ $t('codeHint') }}</p>
                </div>
            </div>

            <p class="err" id="error" role="alert" hidden></p>
            <button class="btn main" id="enter" type="submit">{{ $t('next') }}</button>
            <p class="muted sm" id="help" style="margin:14px 0 0" hidden>{{ $t('codeHelp') }}</p>
        </form>
    </div>
@endsection

@section('script')
    <script nonce="{{ $nonce }}">
        (function () {
            var texts = {{ \Illuminate\Support\Js::from(['next' => $t('next'), 'enter' => $t('enter'), 'entering' => $t('entering'), 'network' => $t('networkError'), 'phone' => $t('phoneShort'), 'code' => $t('codeShort'), 'slow' => $t('slowDown')]) }};
            var csrf = document.querySelector('meta[name=csrf-token]').content;
            var $ = function (id) { return document.getElementById(id); };
            var key = function () { var k = (location.hash || '').replace(/^#/, ''); return /^[A-Za-z0-9_-]{43}$/.test(k) ? k : ''; };
            // أرقامٌ عربيّةٌ وفارسيّة تُقرأ أرقامًا
            var digits = function (v) { return v.replace(/[٠-٩]/g, function (d) { return d.charCodeAt(0) - 1632; }).replace(/[۰-۹]/g, function (d) { return d.charCodeAt(0) - 1776; }).replace(/\D+/g, ''); };
            var second = false;
            var say = function (m) { $('error').textContent = m; $('error').hidden = false; };
            var show = function (to) {
                second = to; $('error').hidden = true;
                $('step-phone').hidden = to; $('step-code').hidden = !to; $('help').hidden = !to;
                $('enter').textContent = to || key() ? texts.enter : texts.next; $('who').textContent = $('phone').value.trim();
                (to ? $('code') : $('phone')).focus();
            };
            show(false);
            $('change').addEventListener('click', function () { show(false); });
            // ثلاثاتٌ كما طُبع
            $('code').addEventListener('input', function () { var d = digits(this.value).slice(0, {{ \App\Support\RibbonPortal\Code::DIGITS }}); this.value = d.replace(/(\d{3})(?=\d)/g, '$1 '); });
            // تبديلُ اللغة لا يُسقط رابطًا قديمًا
            var lang = document.querySelector('[data-keep-hash]');
            lang.addEventListener('click', function () { if (location.hash) lang.href = lang.href.split('#')[0] + location.hash; });

            $('login').addEventListener('submit', function (e) {
                e.preventDefault(); $('error').hidden = true;
                if (digits($('phone').value).length < 8) { show(false); say(texts.phone); return; }
                // الرقمُ لا يُرسَل وحده: الخطوةُ الثانية في الصفحة، ولو لم يكن مسجَّلًا
                if (!second && !key()) { show(true); return; }
                if (!key() && digits($('code').value).length !== {{ \App\Support\RibbonPortal\Code::DIGITS }}) { say(texts.code); return; }
                var enter = $('enter'), was = enter.textContent; enter.disabled = true; enter.textContent = texts.entering;
                fetch({{ \Illuminate\Support\Js::from(route('my.login', [], false)) }}, {
                    method: 'POST', credentials: 'same-origin', referrerPolicy: 'no-referrer',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ phone: $('phone').value, code: key() ? '' : $('code').value, key: key() }),
                }).then(function (r) {
                    if (r.status === 419) { location.reload(); return; }
                    return r.json().catch(function () { return {}; }).then(function (d) {
                        if (r.ok && d.ok) { history.replaceState(null, '', location.pathname); location.reload(); return; }
                        throw r.status === 429 && !d.wait ? texts.slow : d.message || texts.network;
                    });
                }).catch(function (m) { say(typeof m === 'string' ? m : texts.network); enter.disabled = false; enter.textContent = was; });
            });
        })();
    </script>
@endsection
