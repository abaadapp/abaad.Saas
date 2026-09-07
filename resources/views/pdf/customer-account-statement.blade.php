@extends('pdf.layout')

@section('title', __('كشف حساب'))

@section('meta')
    <div>{{ __('العميل:') }} {{ $customer->legal_name ?: $customer->name }}</div>
    <div>{{ __('المدة:') }} <span dir="ltr">{{ $from }} — {{ $to }}</span></div>
@endsection

@section('body')
    <table class="grid">
        <thead>
            <tr>
                <th>{{ __('التاريخ') }}</th>
                <th>{{ __('البيان') }}</th>
                <th>{{ __('المرجع') }}</th>
                <th class="num">{{ __('مدين') }}</th>
                <th class="num">{{ __('دائن') }}</th>
                <th class="num">{{ __('الرصيد') }}</th>
            </tr>
        </thead>
        <tbody>
            {{-- الرصيدُ الافتتاحيّ سطرٌ صريح: كشفٌ يبدأ من صفرٍ وهو ليس صفرًا
                 يجعل العميل يحتجّ على رقمٍ لا يفهم من أين جاء --}}
            <tr>
                <td colspan="5"><strong>{{ __('رصيد ما قبل المدة') }}</strong></td>
                <td class="num"><strong>{{ number_format($statement['opening'], 3) }}</strong></td>
            </tr>
            @forelse ($statement['rows'] as $row)
                <tr>
                    <td dir="ltr">{{ $row['at'] ?? '—' }}</td>
                    <td>{{ $row['kind'] }}</td>
                    <td dir="ltr">{{ $row['ref'] }}</td>
                    <td class="num">{{ $row['debit'] > 0 ? number_format($row['debit'], 3) : '—' }}</td>
                    <td class="num">{{ $row['credit'] > 0 ? number_format($row['credit'], 3) : '—' }}</td>
                    <td class="num">{{ number_format($row['balance'], 3) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">{{ __('لا حركة في هذه المدة') }}</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5">{{ __('الرصيد المستحق') }}</td>
                <td class="num">{{ number_format($statement['closing'], 3) }}</td>
            </tr>
        </tfoot>
    </table>
@endsection
