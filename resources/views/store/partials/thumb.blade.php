{{--
    صورة الصنف — أو لوحٌ هادئ بحرف اسمه حين لا صورة.

    ومربّعٌ يقول «لا صورة» أصدق من صورةٍ عشوائية تقول شيئًا آخر: الزبون يطلب
    ما رأى. انظر Storefront::image.
--}}
@php $src = \App\Support\Storefront::image($product); @endphp

@if($src)
    <img src="{{ $src }}" alt="{{ $product->name }}" loading="lazy" class="{{ $class }}">
@else
    <span class="{{ $class }} flex items-center justify-center bg-[var(--store-soft)] text-[var(--store-accent)]">
        <span class="text-[22px] font-bold opacity-60">{{ mb_substr($product->name, 0, 1) }}</span>
    </span>
@endif
