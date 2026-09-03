{{--
    ورقةُ مستندٍ عامّة — يرسمها هذا الملفّ وحده لكلّ الأنواع.

    أمرُ الشراء وسندُ الاستلام وسندُ النقل وسندُ التسليم أوراقٌ واحدةُ
    الهيكل: ترويسةٌ فيها هويّة المتجر، وبطاقاتُ أطراف، وجدولُ أصناف،
    وتوقيع. وأربعةُ ملفّاتٍ لأربعتها تفترق عند أوّل تعديل — يُصلَح سطرٌ في
    واحدةٍ ويبقى معطوبًا في ثلاث.

    وترويستُها من `pdf.layout` كبقيّة الورق: كانت ترسمها بيدها فتفترق عن
    ترويسة الفاتورة في الخطّ واللون وموضع الشعار — لطلبٍ واحد يخرج منه
    سندُ تسليمٍ وفاتورة.
--}}
@extends('pdf.layout')

@php
    $show = fn (string $k, bool $default = false) => (bool) ($tpl[$k] ?? $default);
    $scale = $scale ?? 1.0;
    /* الرقم الضريبي في الترويسة بمقبضه: انظر invoice.blade.php */
    $vatNumber = $show('show_vat_no') ? ($vatNumber ?? '') : '';
    $headerNote = trim((string) ($tpl['header'] ?? ''));
@endphp

@section('title', $doc['title'])

@section('meta')
    <div>{{ __('الرقم') }}: <strong dir="ltr">{{ $doc['number'] }}</strong></div>
    @if ($show('show_datetime') && $doc['date'])
    <div><span class="k">{{ __('التاريخ') }}:</span> <span dir="ltr">{{ $doc['date'] }}</span></div>
    @endif
    @if ($show('show_branch') && $doc['branch'])
    <div>{{ $doc['branch'] }}</div>
    @endif
    @if ($show('show_employee') && $doc['employee'])
    <div>{{ __('الموظف') }}: {{ $doc['employee'] }}</div>
    @endif
@endsection

@section('body')
    @if ($showParties && count($doc['parties']) > 0)
        <table class="grid" style="margin-bottom:12pt">
            <tr>
                @foreach ($doc['parties'] as $party)
                    <td style="width:{{ (int) (100 / count($doc['parties'])) }}%; border-bottom:none; vertical-align:top">
                        <div class="faint small">{{ $party['cap'] }}</div>
                        @foreach ($party['lines'] as $l)
                            <div>{{ $l }}</div>
                        @endforeach
                    </td>
                @endforeach
            </tr>
        </table>
    @endif

    <table class="grid">
        <thead>
            <tr>
                <th style="width:6%" class="num">#</th>
                <th>{{ __('الصنف') }}</th>
                <th style="width:14%" class="num">{{ __('الكمية') }}</th>
                @if ($showPrices)
                    <th style="width:18%" class="amt">{{ __('السعر') }}</th>
                    <th style="width:20%" class="amt">{{ __('الإجمالي') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($doc['items'] as $i => $item)
                <tr>
                    <td class="num faint">{{ $i + 1 }}</td>
                    <td>{{ $item['name'] }}</td>
                    <td class="num">{{ $item['qty'] }}</td>
                    @if ($showPrices)
                        <td class="amt">{{ $item['unit'] ?? '—' }}</td>
                        <td class="amt">{{ $item['total'] ?? '—' }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($show('show_items_count'))
        <div class="muted small" style="margin-bottom:8pt">
            {{ __('عدد الأصناف') }}: <span dir="ltr">{{ count($doc['items']) }}</span>
        </div>
    @endif

    @if ($showPrices && count($doc['totals']) > 0)
        <table style="width:100%"><tr>
            <td style="width:56%; border:none"></td>
            <td style="width:44%; border:none">
                <table class="grid" style="margin:0">
                    @foreach ($doc['totals'] as $row)
                        @if ($row['grand'] ?? false)
                            <tfoot><tr><td>{{ $row['label'] }}</td><td class="amt">{{ $row['value'] }}</td></tr></tfoot>
                        @else
                            <tr><td>{{ $row['label'] }}</td><td class="amt">{{ $row['value'] }}</td></tr>
                        @endif
                    @endforeach
                </table>
            </td>
        </tr></table>
    @endif

    @if ($show('show_notes') && trim($doc['notes']) !== '')
        <div class="note small">
            <span class="faint">{{ __('ملاحظات') }}:</span> {{ $doc['notes'] }}
        </div>
    @endif

    {{--
        والرمزُ على أوراق الزبون وحدها.

        سندُ التسليم يمشي مع الشحنة إلى يد المستلِم، فيحمل طريقَه إلى نسخته
        الحيّة. وأمرُ الشراء وسندُ الاستلام يمضيان إلى المورّد وفيهما تكلفةُ
        البضاعة — ورابطٌ عامٌّ لا يحرسه إلا كونُه غير مخمَّن يضع هامشَ ربح
        التاجر خلف قصاصةٍ تُصوَّر بهاتف. فلا رمزَ لهما، وأصلًا لا يُبنى
        (انظر App\Support\PublicDocument).
    --}}
    @include('pdf.partials.qr', ['eInvoice' => '', 'paperUrl' => $paperUrl ?? '', 'googleReview' => '', 'size' => 1.0])

    @if ($show('show_signature'))
        {{--
            خانتان لا واحدة: ورقةٌ يوقّعها المستلم وحده لا تُثبت من سلّم،
            وورقةٌ يوقّعها المسلِّم وحده لا تُثبت أنّها وصلت.
        --}}
        <table class="sign small">
            <tr>
                <td><div class="rule faint">{{ __('توقيع المسلِّم') }}</div></td>
                <td><div class="rule faint">{{ __('توقيع المستلِم') }}</div></td>
            </tr>
        </table>
    @endif
@endsection

@if (trim((string) ($tpl['footer'] ?? '')) !== '')
    @section('foot')
        @foreach (preg_split('/\r\n|\r|\n/', $tpl['footer']) as $l)
            <div>{{ $l }}</div>
        @endforeach
    @endsection
@endif
