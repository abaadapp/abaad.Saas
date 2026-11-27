@extends('portal.ribbon.layout')

@section('style')
    .bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; margin-bottom: 14px; } .bar .acts { display: flex; flex-wrap: wrap; gap: 8px; }
    .stage { background: #fff; border: 1px solid var(--line); border-radius: 16px; overflow: hidden; position: relative; min-height: 320px; } .stage iframe { border: 0; display: block; transform-origin: 0 0; position: absolute; top: 0; left: 0; background: #fff; }
@endsection

@section('start')
    <a class="btn sm text" href="{{ route('my.index', [], false) }}" data-testid="portal-back">{{ $t('back') }}</a>
@endsection

@section('main')
    <div class="bar">
        <h1 style="margin:0"><span class="num" data-testid="portal-paper-number">{{ $number }}</span></h1>
        <div class="acts">
            <button class="btn sm" type="button" id="print" data-testid="portal-print">{{ $t('print') }}</button>
            <a class="btn sm" href="{{ route('my.paper.pdf', [$kind, $id], false) }}" target="_blank" rel="noopener" data-testid="portal-open-pdf">{{ $t('open') }}</a>
            <a class="btn sm main" href="{{ route('my.paper.pdf', [$kind, $id, 'download' => 1], false) }}" data-testid="portal-download">{{ $t('pdf') }}</a>
        </div>
    </div>
    <div class="stage" id="stage"><iframe id="sheet" title="{{ $number }}" src="{{ route('my.paper.sheet', [$kind, $id], false) }}" width="794" height="1123" scrolling="no" data-testid="portal-sheet"></iframe></div>
@endsection

@section('script')
    <script nonce="{{ $nonce }}">
        (function () {
            // الورقةُ بعرضها الحقيقيّ (A4) تُصغَّر إلى عرض الشاشة، ويُقاس طولُها منها
            var stage = document.getElementById('stage'), sheet = document.getElementById('sheet'), W = 794;
            var fit = function () {
                var h = 1123; try { h = Math.max(h, sheet.contentDocument.documentElement.scrollHeight); } catch (e) {}
                var s = Math.min(1, stage.clientWidth / W), x = Math.max(0, (stage.clientWidth - W * s) / 2);
                sheet.height = h; sheet.style.transform = 'translateX(' + x + 'px) scale(' + s + ')'; stage.style.height = Math.ceil(h * s) + 'px';
            };
            sheet.addEventListener('load', fit); window.addEventListener('resize', fit); fit();
            document.getElementById('print').addEventListener('click', function () { try { sheet.contentWindow.focus(); sheet.contentWindow.print(); } catch (e) { window.open({{ \Illuminate\Support\Js::from(route('my.paper.pdf', [$kind, $id], false)) }}, '_blank', 'noopener'); } });
        })();
    </script>
@endsection
