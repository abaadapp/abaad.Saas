@php
    /**
     * فاتورة اشتراكٍ في أبعاد — ورقةُ المنصّة لا ورقةُ المحلّ.
     *
     * كانت هذه الفاتورة تُطبع بقالب فاتورة المبيعات نفسه: قالبٌ يقرأ `$order`
     * — أصنافًا وفرعًا وزبونًا وطريقة دفع — والمتحكّم يمرّر `$invoice`. فكلّ
     * ضغطةٍ على «عرض» أو «تحميل» في شاشة الفواتير كانت ٥٠٠، لا رسالةَ تقول
     * لماذا. وبابٌ معروضٌ لا يُفتح أسوأ من بابٍ لا يُعرض.
     *
     * والقالب الجديد يقول ما تقوله هذه الورقة فعلًا: أبعاد بائعة، والمحلّ
     * مشترٍ، وسطرٌ واحد هو الباقة. ولا يُسمّى «فاتورة ضريبية» ولا يُحسب فيها
     * ضريبةٌ لم تُخزَّن: الرقم في العمود هو الرقم على الورقة.
     */
    $money = fn ($v) => number_format((float) $v, 3) . ' ' . __('ر.ع');
    $paid = ($invoice->status ?? '') === 'مدفوعة';
@endphp
<style>
    * { font-family: sans-serif; }
    body { direction: rtl; text-align: right; font-size: 12px; color: #111; }
    .muted { color: #666; }
    .small { font-size: 10px; }
    h1 { font-size: 20px; margin: 0 0 2px; }
    .doc-title { font-size: 15px; font-weight: bold; }

    .head { width: 100%; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 14px; }
    .head td { vertical-align: top; }

    .parties { width: 100%; margin-bottom: 14px; }
    .parties td { width: 50%; vertical-align: top; padding: 8px 10px; border: 1px solid #ddd; }
    .parties .cap { font-size: 10px; color: #888; margin-bottom: 3px; }

    table.items { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    table.items th { background: #f4f4f2; border: 1px solid #ddd; padding: 7px 8px; font-size: 11px; text-align: right; }
    table.items td { border: 1px solid #eee; padding: 7px 8px; }
    table.items td.amt, table.items th.amt { text-align: left; }

    .totals { width: 100%; border-collapse: collapse; }
    .totals td { padding: 5px 8px; }
    /* nowrap: «10.500 ر.ع» تنكسر سطرين في عمودٍ ضيّق فتُقرأ رقمين */
    .totals .amt { text-align: left; white-space: nowrap; }
    .totals .grand td { border-top: 2px solid #111; font-size: 15px; font-weight: bold; padding-top: 8px; }

    /* حالة السداد تُقرأ من بعيد: ورقةٌ غير مدفوعة تُرسَل للمطالبة */
    .stamp { display: inline-block; padding: 3px 10px; border: 1px solid; border-radius: 3px; font-size: 11px; font-weight: bold; }
    .stamp.paid { color: #047857; border-color: #047857; }
    .stamp.due { color: #b91c1c; border-color: #b91c1c; }

    .foot { margin-top: 26px; border-top: 1px solid #ddd; padding-top: 10px; }
</style>

<table class="head">
    <tr>
        <td style="width:62%">
            <h1>{{ $platform['company'] ?: $platform['app_name'] }}</h1>
            @if ($platform['website'])
                <div class="muted small" dir="ltr">{{ $platform['website'] }}</div>
            @endif
            @if ($platform['email'])
                <div class="muted small" dir="ltr">{{ $platform['email'] }}</div>
            @endif
            @if ($platform['phone'])
                <div class="muted small">{{ __('هاتف') }}: <span dir="ltr">{{ $platform['phone'] }}</span></div>
            @endif
        </td>
        <td style="text-align:left">
            <div class="doc-title">{{ __('فاتورة اشتراك') }}</div>
            <div class="small" style="margin-top:6px">
                <div>{{ __('رقم الفاتورة') }}: <strong dir="ltr">{{ $invoice->number }}</strong></div>
                <div class="muted">{{ __('التاريخ') }}: <span dir="ltr">{{ optional($invoice->issued_at)->format('Y-m-d') ?: '—' }}</span></div>
            </div>
            <div style="margin-top:8px">
                <span class="stamp {{ $paid ? 'paid' : 'due' }}">{{ __($invoice->status ?: 'غير مدفوعة') }}</span>
            </div>
        </td>
    </tr>
</table>

<table class="parties">
    <tr>
        <td>
            <div class="cap">{{ __('المُصدِر') }}</div>
            <div><strong>{{ $platform['company'] ?: $platform['app_name'] }}</strong></div>
            <div class="muted small">{{ __('منصة أبعاد لإدارة المحلات') }}</div>
        </td>
        <td>
            <div class="cap">{{ __('المشترك') }}</div>
            <div><strong>{{ $invoice->business->name ?? '—' }}</strong></div>
            @if ($invoice->business?->type)
                <div class="muted small">{{ __($invoice->business->type) }}</div>
            @endif
            @if ($invoice->business?->phone)
                <div class="muted small">{{ __('هاتف') }}: <span dir="ltr">{{ $invoice->business->phone }}</span></div>
            @endif
        </td>
    </tr>
</table>

<table class="items">
    <tr>
        <th>{{ __('البيان') }}</th>
        <th class="amt" style="width:28%">{{ __('المبلغ') }}</th>
    </tr>
    <tr>
        <td>
            {{ __('اشتراك') }}@if ($invoice->plan?->name) — {{ __($invoice->plan->name) }}@endif
        </td>
        <td class="amt">{{ $money($invoice->amount) }}</td>
    </tr>
</table>

<table class="totals">
    <tr class="grand">
        <td>{{ __('الإجمالي') }}</td>
        <td class="amt">{{ $money($invoice->amount) }}</td>
    </tr>
</table>

<div class="foot muted small">
    {{ __('هذه الفاتورة صادرة عن منصة أبعاد مقابل اشتراك البرنامج.') }}
</div>
