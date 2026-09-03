@extends('pdf.layout')

@section('title', __('قائمة الموردين'))

@section('meta')
    <div>{{ __('تاريخ الإصدار:') }} {{ $generatedAt }}</div>
    <div>{{ __('العدد: :n مورّد', ['n' => $suppliers->count()]) }}</div>
@endsection

@section('body')
    <table class="grid">
        <thead>
            <tr>
                <th style="width:4%;">#</th>
                <th>{{ __('الاسم') }}</th>
                <th>{{ __('الهاتف') }}</th>
                <th>{{ __('البريد') }}</th>
                <th>{{ __('مسؤول التواصل') }}</th>
                <th style="text-align:left;">{{ __('أوامر الشراء') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($suppliers as $i => $s)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $s->name }}</td>
                    <td>{{ $s->phone ?: '—' }}</td>
                    <td>{{ $s->email ?: '—' }}</td>
                    <td>{{ $s->contact_person ?: '—' }}</td>
                    <td style="text-align:left;">{{ $s->purchase_orders_count }}</td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center; color:#9ca3af; padding:18px;">{{ __('لا موردين بعد') }}</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection

@section('foot')
    <div class="c">{{ __('صدر من نظام أبعاد') }} — {{ $generatedAt }}</div>
@endsection
