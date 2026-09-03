{{--
    فاتورة اشتراكٍ في أبعاد — ورقةُ المنصّة لا ورقةُ المحلّ.

    كانت تُطبع بقالب فاتورة المبيعات نفسه: قالبٌ يقرأ `$order` — أصنافًا
    وفرعًا وزبونًا وطريقة دفع — والمتحكّم يمرّر `$invoice`. فكلّ ضغطةٍ على
    «عرض» أو «تحميل» في شاشة الفواتير كانت ٥٠٠، لا رسالةَ تقول لماذا.

    وهي تقول ما تقوله هذه الورقة فعلًا: أبعاد بائعة، والمحلّ مشترٍ، وسطرٌ
    واحد هو الباقة. ولا تُسمّى «فاتورة ضريبية» ولا تُحسب فيها ضريبةٌ لم
    تُخزَّن: الرقم في العمود هو الرقم على الورقة.
--}}
@extends('pdf.layout')

@php
    $money = fn ($v) => number_format((float) $v, 3) . ' ' . __('ر.ع');
    $paid = ($invoice->status ?? '') === 'مدفوعة';

    /* ترويسةُ المنصّة من إعداداتها — انظر PdfController::platformInvoice */
    $business = [
        'name' => $platform['company'] ?: $platform['app_name'],
        'phone' => $platform['phone'],
        'email' => $platform['email'],
        'address' => $platform['website'],
    ];
@endphp

@section('title', __('فاتورة اشتراك'))

@section('meta')
    <div>{{ __('رقم الفاتورة') }}: <strong dir="ltr">{{ $invoice->number }}</strong></div>
    <div><span class="k">{{ __('التاريخ') }}:</span> <span dir="ltr">{{ optional($invoice->issued_at)->format('Y-m-d') ?: '—' }}</span></div>
    {{-- حالة السداد تُقرأ من بعيد: ورقةٌ غير مدفوعة تُرسَل للمطالبة --}}
    <div style="margin-top:4pt">
        <span class="pill {{ $paid ? 'income' : 'expense' }} b">{{ __($invoice->status ?: 'غير مدفوعة') }}</span>
    </div>
@endsection

@section('body')
    <table class="grid" style="margin-bottom:12pt">
        <tr>
            <td style="width:50%; border-bottom:none; vertical-align:top">
                <div class="faint small">{{ __('المُصدِر') }}</div>
                <div class="b">{{ $platform['company'] ?: $platform['app_name'] }}</div>
                <div class="muted small">{{ __('منصة أبعاد لإدارة المحلات') }}</div>
            </td>
            <td style="border-bottom:none; vertical-align:top">
                <div class="faint small">{{ __('المشترك') }}</div>
                <div class="b">{{ $invoice->business->name ?? '—' }}</div>
                @if ($invoice->business?->type)
                    <div class="muted small">{{ __($invoice->business->type) }}</div>
                @endif
                @if ($invoice->business?->phone)
                    <div class="muted small">{{ __('هاتف') }}: <span dir="ltr">{{ $invoice->business->phone }}</span></div>
                @endif
            </td>
        </tr>
    </table>

    <table class="grid">
        <thead>
            <tr>
                <th>{{ __('البيان') }}</th>
                <th class="amt" style="width:28%">{{ __('المبلغ') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ __('اشتراك') }}@if ($invoice->plan?->name) — {{ __($invoice->plan->name) }}@endif</td>
                <td class="amt">{{ $money($invoice->amount) }}</td>
            </tr>
        </tbody>
        <tfoot>
            <tr>
                <td>{{ __('الإجمالي') }}</td>
                <td class="amt">{{ $money($invoice->amount) }}</td>
            </tr>
        </tfoot>
    </table>
@endsection

@section('foot')
    {{ __('هذه الفاتورة صادرة عن منصة أبعاد مقابل اشتراك البرنامج.') }}
@endsection
