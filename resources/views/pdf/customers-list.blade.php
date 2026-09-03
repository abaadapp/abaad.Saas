@extends('pdf.layout')

@section('title', __('قائمة العملاء'))

@section('meta')
    <div>{{ __('تاريخ الإصدار:') }} {{ $generatedAt }}</div>
    <div>{{ __('العدد: :n عميل', ['n' => $customers->count()]) }}</div>
@endsection

@section('body')
    <table class="grid">
        <thead>
            <tr>
                <th style="width:4%;">#</th>
                <th>{{ __('الاسم') }}</th>
                <th>{{ __('الهاتف') }}</th>
                <th>{{ __('البريد') }}</th>
                <th>{{ __('الفرع') }}</th>
                <th style="text-align:left;">{{ __('النقاط') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($customers as $i => $c)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $c->name }}</td>
                    <td dir="ltr">{{ $c->phone ?: '—' }}</td>
                    <td dir="ltr">{{ $c->email ?: '—' }}</td>
                    <td>{{ $c->branch?->name ?? '—' }}</td>
                    <td style="text-align:left;">{{ $c->points }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">{{ __('لا يوجد عملاء.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection

@section('foot')
    <div class="c">{{ __('قائمة عملاء آلية عبر نظام Abad POS') }} — {{ $generatedAt }}</div>
@endsection
