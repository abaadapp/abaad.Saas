{{--
    السعر كما يُقرأ — ومداه حين يكون للصنف مقاسات.

    «من ١٢٫٥٠٠» أصدق من رقمٍ لا يدفعه أحد: ذو المقاسات لا يُباع بسعر عموده.
    انظر Storefront::price.
--}}
@php $p = \App\Support\Storefront::price($product); @endphp

@if($p['variable'])
    {{ __('من') }} {{ \App\Support\Storefront::money($p['from'], $currency) }}
@else
    {{ \App\Support\Storefront::money($p['from'], $currency) }}
@endif
