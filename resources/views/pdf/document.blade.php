{{--
    ورقةُ مستندٍ عامّة — يرسمها هذا الملفّ وحده لكلّ الأنواع.

    أمرُ الشراء وسندُ الاستلام وسندُ النقل وسندُ التسليم أوراقٌ واحدةُ
    الهيكل: ترويسةٌ فيها هويّة المتجر، وبطاقاتُ أطراف، وجدولُ أصناف،
    وتوقيع. وأربعةُ ملفّاتٍ لأربعتها تفترق عند أوّل تعديل — يُصلَح سطرٌ في
    واحدةٍ ويبقى معطوبًا في ثلاث.

    ولا كتلةَ @php هنا: بلاد تقرأ أوّل سطرٍ فيه قوس على أنّه @php(...)
    فتبتلع ما بعده وتُخرج خطأ تحليلٍ لا علاقة له بموضعه. وكلُّ ما يُحسب
    محسوبٌ في DocumentPaper وDocumentTemplates قبل الوصول إلى هنا.
--}}
<style>
    * { font-family: sans-serif; }
    body { direction: rtl; text-align: right; font-size: {{ $base }}px; color: #111; }
    .muted { color: #666; }
    .small { font-size: {{ $base - 2 }}px; }

    h1 { font-size: {{ $base + 8 }}px; margin: 0 0 2px; }
    .doc-title { font-size: {{ $base + 3 }}px; font-weight: bold; }

    .head { width: 100%; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 14px; }
    .head td { vertical-align: top; }

    .parties { width: 100%; margin-bottom: 14px; }
    .parties td { vertical-align: top; padding: 8px 10px; border: 1px solid #ddd; }
    .parties .cap { font-size: {{ $base - 2 }}px; color: #888; margin-bottom: 3px; }

    table.items { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    table.items th { background: #f4f4f2; border: 1px solid #ddd; padding: 7px 8px; font-size: {{ $base - 1 }}px; text-align: right; }
    table.items td { border: 1px solid #eee; padding: 7px 8px; }
    table.items td.num, table.items th.num { text-align: center; }
    {{-- nowrap: «10.500 ر.ع» كانت تنكسر سطرين في عمودٍ ضيّق فتُقرأ رقمين --}}
    table.items td.amt, table.items th.amt { text-align: left; white-space: nowrap; }

    .totals { width: 100%; border-collapse: collapse; }
    .totals td { padding: 5px 8px; border-bottom: 1px solid #f0f0f0; }
    .totals .amt { text-align: left; white-space: nowrap; }
    .totals .grand td { border-top: 2px solid #111; border-bottom: none; font-size: {{ $base + 3 }}px; font-weight: bold; padding-top: 8px; }

    .notes { margin-top: 14px; border: 1px dashed #ddd; padding: 8px 10px; }
    .sign { width: 100%; margin-top: 34px; }
    .sign td { width: 50%; padding-top: 26px; }
    .sign .rule { border-top: 1px solid #999; padding-top: 4px; }
    .foot { margin-top: 26px; border-top: 1px solid #ddd; padding-top: 10px; }
</style>

<table class="head">
    <tr>
        <td style="width:62%">
            @if (($tpl['show_logo'] ?? false) && $logo)
                <img src="{{ $logo }}" style="max-height:52px; margin-bottom:4px;" alt="">
            @endif
            <h1>{{ $business->name ?? __('نظام Abad POS') }}</h1>
            @if (trim((string) ($tpl['header'] ?? '')) !== '')
                <div class="muted small">{{ $tpl['header'] }}</div>
            @endif
            @if ($business && $business->address)
                <div class="muted small">{{ $business->address }}@if ($business->city) — {{ $business->city }}@endif</div>
            @endif
            @if ($business && $business->phone)
                <div class="muted small">{{ __('هاتف') }}: <span dir="ltr">{{ $business->phone }}</span></div>
            @endif
            @if (($tpl['show_vat_no'] ?? false) && $vatNumber !== '')
                <div class="muted small">{{ __('الرقم الضريبي') }}: <span dir="ltr">{{ $vatNumber }}</span></div>
            @endif
        </td>
        <td style="text-align:left">
            <div class="doc-title">{{ $doc['title'] }}</div>
            <div class="small" style="margin-top:6px">
                <div>{{ __('الرقم') }}: <strong dir="ltr">{{ $doc['number'] }}</strong></div>
                @if (($tpl['show_datetime'] ?? false) && $doc['date'])
                    <div class="muted">{{ __('التاريخ') }}: <span dir="ltr">{{ $doc['date'] }}</span></div>
                @endif
                @if (($tpl['show_branch'] ?? false) && $doc['branch'])
                    <div class="muted">{{ $doc['branch'] }}</div>
                @endif
                @if (($tpl['show_employee'] ?? false) && $doc['employee'])
                    <div class="muted">{{ __('الموظف') }}: {{ $doc['employee'] }}</div>
                @endif
            </div>
        </td>
    </tr>
</table>

@if ($showParties && count($doc['parties']) > 0)
    <table class="parties">
        <tr>
            @foreach ($doc['parties'] as $party)
                <td style="width:{{ (int) (100 / count($doc['parties'])) }}%">
                    <div class="cap">{{ $party['cap'] }}</div>
                    @foreach ($party['lines'] as $l)
                        <div>{{ $l }}</div>
                    @endforeach
                </td>
            @endforeach
        </tr>
    </table>
@endif

<table class="items">
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
                <td class="num">{{ $i + 1 }}</td>
                <td>{{ $item['name'] }}</td>
                <td class="num" dir="ltr">{{ $item['qty'] }}</td>
                @if ($showPrices)
                    <td class="amt" dir="ltr">{{ $item['unit'] ?? '—' }}</td>
                    <td class="amt" dir="ltr">{{ $item['total'] ?? '—' }}</td>
                @endif
            </tr>
        @endforeach
    </tbody>
</table>

@if ($tpl['show_items_count'] ?? false)
    <div class="muted small" style="margin-bottom:10px">
        {{ __('عدد الأصناف') }}: <span dir="ltr">{{ count($doc['items']) }}</span>
    </div>
@endif

@if ($showPrices && count($doc['totals']) > 0)
    <table class="totals">
        @foreach ($doc['totals'] as $row)
            <tr @if ($row['grand'] ?? false) class="grand" @endif>
                <td>{{ $row['label'] }}</td>
                <td class="amt" dir="ltr">{{ $row['value'] }}</td>
            </tr>
        @endforeach
    </table>
@endif

@if (($tpl['show_notes'] ?? false) && trim($doc['notes']) !== '')
    <div class="notes small">
        <span class="muted">{{ __('ملاحظات') }}:</span> {{ $doc['notes'] }}
    </div>
@endif

@if ($tpl['show_signature'] ?? false)
    {{--
        خانتان لا واحدة: ورقةٌ يوقّعها المستلم وحده لا تُثبت من سلّم، وورقةٌ
        يوقّعها المسلِّم وحده لا تُثبت أنّها وصلت.
    --}}
    <table class="sign small">
        <tr>
            <td><div class="rule muted">{{ __('توقيع المسلِّم') }}</div></td>
            <td><div class="rule muted">{{ __('توقيع المستلِم') }}</div></td>
        </tr>
    </table>
@endif

@if (trim((string) ($tpl['footer'] ?? '')) !== '')
    <div class="foot muted small">
        @foreach (preg_split('/\r\n|\r|\n/', $tpl['footer']) as $l)
            <div>{{ $l }}</div>
        @endforeach
    </div>
@endif
