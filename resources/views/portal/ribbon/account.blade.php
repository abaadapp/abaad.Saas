@extends('portal.ribbon.layout')

@section('style')
    .hi { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; justify-content: space-between; margin-bottom: 18px; }
    .hi .side { display: flex; align-items: center; gap: 10px; }
    .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
    .stat { background: var(--card); border: 1px solid var(--line); border-radius: 18px; padding: 16px; display: flex; flex-direction: column; align-items: flex-start; gap: 4px; min-width: 0; }
    .stat > span:first-child { color: var(--muted); font-size: 13px; } .stat strong { font-size: 19px; font-weight: 600; color: var(--olive-d); overflow-wrap: anywhere; } .stat small { font-size: 13px; font-weight: 400; }
    .stat.hero { background: var(--olive); border-color: var(--olive); } .stat.hero > span:first-child, .stat.hero strong { color: var(--cream); } .stat.hero strong { font-size: 24px; }
    .tabs { display: flex; gap: 6px; margin: 22px 0 14px; border-bottom: 1px solid var(--line); }
    .tab { border: 0; background: none; padding: 10px 14px; min-height: 44px; cursor: pointer; color: var(--muted); border-bottom: 2px solid transparent; margin-bottom: -1px; font-weight: 500; }
    .tab[aria-selected=true] { color: var(--olive-d); border-color: var(--olive); }
    .filter { display: grid; grid-template-columns: 1.6fr 1fr 1fr auto auto; gap: 10px; align-items: end; margin-bottom: 14px; } .filter > *, .filter input { min-width: 0; } .filter input[type=date] { max-width: 100%; } .filter label span { display: block; font-size: 12px; color: var(--muted); margin-bottom: 3px; }
    .list { list-style: none; margin: 0; padding: 0; }
    .row { display: grid; grid-template-columns: minmax(0, 1.5fr) 100px 124px 112px minmax(0, 1.4fr); gap: 12px; align-items: center; padding: 14px 4px; border-top: 1px solid var(--line); } .row:first-child { border-top: 0; }
    .row .who, .row .sum, .row .when { display: flex; flex-direction: column; align-items: flex-start; min-width: 0; } .row .who .num { overflow-wrap: anywhere; } .row .state { justify-self: start; } .row .acts { display: flex; flex-wrap: wrap; gap: 4px; justify-content: flex-end; }
    .state { font-size: 12px; font-weight: 500; padding: 4px 10px; border-radius: 999px; white-space: nowrap; background: var(--soft); color: var(--olive-d); }
    .state.paid { background: #e6ede0; color: #3f5a2c; } .state.partial { background: #f6ecd2; color: #7a5a12; } .state.unpaid { background: #f5e1df; color: #8a2f2a; } .state.void { background: #ece8e2; color: var(--muted); }
    .more { display: flex; justify-content: center; margin-top: 12px; } .empty { text-align: center; color: var(--muted); padding: 26px 0; margin: 0; }
    .trio { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; } .trio > div { background: var(--soft); border-radius: 14px; padding: 12px; display: flex; flex-direction: column; align-items: flex-start; min-width: 0; } .trio strong { font-size: 20px; color: var(--olive-d); }
    .rules { margin: 16px 0; padding: 14px 16px; border: 1px dashed var(--line); border-radius: 14px; } .rules ul { margin: 6px 0 0; padding-inline-start: 18px; } h3 { font-size: 15px; margin: 0 0 4px; color: var(--olive-d); }
    .pt { display: flex; justify-content: space-between; gap: 12px; padding: 12px 2px; border-top: 1px solid var(--line); } .pt:first-child { border-top: 0; } .pt > div { display: flex; flex-direction: column; align-items: flex-start; } .pt .amt { align-items: flex-end; } .pt strong { font-weight: 500; } .plus { color: #3f5a2c; } .minus { color: #8a2f2a; }
    dialog { max-width: 400px; width: calc(100% - 40px); color: var(--ink); } dialog::backdrop { background: rgb(43 42 32 / .45); } dialog form { display: flex; flex-wrap: wrap; gap: 8px; } dialog .btn { flex: 1 1 130px; }
    .flash { animation: flash 1.6s ease-out; } @keyframes flash { from { background: #f1ead9; } to { background: transparent; } }
    @media (prefers-reduced-motion: reduce) { .flash { animation: none; } }
    @media (max-width: 820px) { .stats { grid-template-columns: 1fr 1fr; } .filter { grid-template-columns: 1fr 1fr; } .filter label:first-child { grid-column: 1 / -1; } }
    @media (max-width: 680px) { .row { grid-template-columns: minmax(0, 1fr) auto; gap: 6px 12px; } .row .when { flex-direction: row; gap: 8px; grid-column: 1; } .row .sum { grid-row: 1; grid-column: 2; align-items: flex-end; } .row .state { justify-self: end; grid-row: 2; grid-column: 2; } .row .acts { grid-column: 1 / -1; justify-content: flex-start; } .stat strong { font-size: 17px; } }
@endsection

@section('start')
    <button class="btn sm text" type="button" id="leave-ask" data-testid="portal-logout">{{ $t('logout') }}</button>
@endsection

@section('main')
    <section class="hi">
        <div><h1 data-testid="portal-hello">{{ $t('hello', ['name' => $summary['name']]) }}</h1><p class="muted" style="margin:0">{{ $t('helloSub') }}</p></div>
        <div class="side">
            <span class="muted sm">{{ $t('updated') }} <span class="num" id="updated" data-testid="portal-updated">{{ $updated }}</span></span>
            <button class="btn sm" type="button" id="refresh" data-testid="portal-refresh">{{ $t('refresh') }}</button>
        </div>
    </section>
    <p class="err" id="offline" role="status" hidden>{{ $t('networkError') }}</p>

    <div id="summary">@include('portal.ribbon._summary')</div>

    <div class="tabs" role="tablist">
        <button class="tab" role="tab" type="button" aria-selected="true" aria-controls="p-buy" id="t-buy">{{ $t('purchases') }}</button>
        <button class="tab" role="tab" type="button" aria-selected="false" aria-controls="p-pts" id="t-pts">{{ $t('pointsTitle') }}</button>
    </div>

    <section class="card" id="p-buy" role="tabpanel" aria-labelledby="t-buy">
        <form class="filter" id="filter">
            <label><span>{{ $t('searchNumber') }}</span><input type="search" name="q" maxlength="60" autocomplete="off"></label>
            <label><span>{{ $t('from') }}</span><input type="date" name="from"></label>
            <label><span>{{ $t('to') }}</span><input type="date" name="to"></label>
            <button class="btn main" type="submit">{{ $t('apply') }}</button>
            <button class="btn" type="reset">{{ $t('clear') }}</button>
        </form>
        <ul class="list" id="rows" data-testid="portal-rows">@foreach ($purchases['rows'] as $row)@include('portal.ribbon._row')@endforeach</ul>
        <p class="empty" id="empty" data-testid="portal-empty" @if ($purchases['rows'] !== []) hidden @endif>{{ $t('empty') }}</p>
        <div class="more"><button class="btn" type="button" id="more" data-testid="portal-more" @if (! $purchases['more']) hidden @endif>{{ $t('more') }}</button></div>
    </section>

    <section class="card" id="p-pts" role="tabpanel" aria-labelledby="t-pts" hidden data-testid="portal-points-panel">@include('portal.ribbon._points')</section>

    {{-- الخروجُ ينسى الجهاز: يُقال قبل أن يقع --}}
    <dialog id="leave" class="card" data-testid="portal-leave" aria-labelledby="leave-text">
        <p id="leave-text" style="margin:0 0 16px">{{ $t('logoutAsk') }}</p>
        <form method="post" action="{{ route('my.logout', [], false) }}" data-leave>@csrf
            <button class="btn main" type="submit" data-testid="portal-leave-yes">{{ $t('logout') }}</button>
            <button class="btn" type="button" id="leave-no">{{ $t('logoutNo') }}</button>
        </form>
    </dialog>
@endsection

@section('script')
    <script nonce="{{ $nonce }}">
        (function () {
            var url = {{ \Illuminate\Support\Js::from(['data' => route('my.data', [], false), 'points' => route('my.points', [], false)]) }};
            var texts = {{ \Illuminate\Support\Js::from(['empty' => $t('empty'), 'emptyFiltered' => $t('emptyFiltered')]) }};
            var PAGE = {{ \App\Support\RibbonPortal\Account::PAGE }}, MAX = {{ \App\Support\RibbonPortal\Account::MAX }};
            var $ = function (id) { return document.getElementById(id); };
            var rows = $('rows'), filter = $('filter'), query = {}, busy = false;

            var node = function (html) { var b = document.createElement('template'); b.innerHTML = html.trim(); return b.content.firstElementChild; };
            var find = function (list, k) { return list.querySelector('[data-key="' + k + '"]'); };
            var get = function (to, params) {
                var q = new URLSearchParams(); Object.keys(params).forEach(function (k) { if (params[k] !== '' && params[k] != null) q.set(k, params[k]); });
                return fetch(to + '?' + q, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } }).then(function (r) {
                    if (r.status === 401) { location.reload(); throw 0; }
                    if (!r.ok) throw r.status;
                    $('offline').hidden = true; return r.json();
                });
            };
            var empty = function () { $('empty').hidden = rows.children.length > 0; $('empty').textContent = Object.keys(query).length ? texts.emptyFiltered : texts.empty; };

            /*
             * يُدمج بالمفتاح لا بالموضع: ما تغيّر حالُه يُبدَّل في مكانه، والجديدُ يُدرَج أوّلًا،
             * وما حُمّل من القديم يبقى. فبيعةٌ جديدة لا تُسقط صفًّا ولا تكرّره.
             */
            var merge = function (list) {
                var before = null;
                for (var i = list.length - 1; i >= 0; i--) {
                    var fresh = node(list[i].html), old = find(rows, list[i].key);
                    if (old) { if (old.outerHTML !== fresh.outerHTML) { rows.replaceChild(fresh, old); fresh.classList.add('flash'); before = fresh; } else before = old; }
                    else { rows.insertBefore(fresh, before || rows.firstChild); fresh.classList.add('flash'); before = fresh; }
                }
            };
            var load = function (mode) {
                if (busy) return; busy = true; $('refresh').disabled = mode === 'reset';
                var poll = mode === 'poll', offset = mode === 'more' ? rows.children.length : 0;
                var limit = poll ? Math.min(MAX, Math.max(PAGE, rows.children.length)) : PAGE;
                get(url.data, Object.assign({ offset: offset, limit: limit, all: mode === 'more' ? '' : 1 }, query)).then(function (d) {
                    if (mode === 'reset') rows.replaceChildren();
                    if (poll) merge(d.rows); else d.rows.forEach(function (r) { if (!find(rows, r.key)) rows.appendChild(node(r.html)); });
                    if (!poll || rows.children.length <= limit) $('more').hidden = !d.more;
                    if (d.summary) $('summary').innerHTML = d.summary;
                    if (d.points && !(poll && $('pts') && $('pts').children.length > PAGE)) $('p-pts').innerHTML = d.points;
                    if (d.summary) $('updated').textContent = d.updated;
                    empty();
                }).catch(function (e) { if (e !== 0) $('offline').hidden = false; }).then(function () { busy = false; $('refresh').disabled = false; });
            };

            $('leave-ask').addEventListener('click', function () { $('leave').showModal(); });
            $('leave-no').addEventListener('click', function () { $('leave').close(); });
            $('refresh').addEventListener('click', function () { load('reset'); });
            $('more').addEventListener('click', function () { load('more'); });
            filter.addEventListener('submit', function (e) {
                e.preventDefault(); query = {}; new FormData(filter).forEach(function (v, k) { if (String(v).trim() !== '') query[k] = String(v).trim(); }); load('reset');
            });
            filter.addEventListener('reset', function () { query = {}; setTimeout(function () { load('reset'); }, 0); });
            $('p-pts').addEventListener('click', function (e) {
                if (!e.target || e.target.id !== 'pts-more') return;
                var list = $('pts');
                get(url.points, { offset: list.children.length }).then(function (d) { d.rows.forEach(function (r) { if (!find(list, r.key)) list.appendChild(node(r.html)); }); e.target.hidden = !d.more; }).catch(function (x) { if (x !== 0) $('offline').hidden = false; });
            });
            ['buy', 'pts'].forEach(function (name) {
                $('t-' + name).addEventListener('click', function () {
                    ['buy', 'pts'].forEach(function (n) { $('t-' + n).setAttribute('aria-selected', String(n === name)); $('p-' + n).hidden = n !== name; });
                });
            });

            // كلَّ ثلاثين ثانية والصفحةُ أمامه، وعند العودة إليها — ولا نبضةَ لصفحةٍ لا تُرى
            var last = Date.now(), tick = function () { if (document.hidden) return; last = Date.now(); load('poll'); };
            setInterval(tick, 30000);
            document.addEventListener('visibilitychange', function () { if (!document.hidden && Date.now() - last > 3000) tick(); });
            window.addEventListener('pageshow', function (e) { if (e.persisted) tick(); });
        })();
    </script>
@endsection
