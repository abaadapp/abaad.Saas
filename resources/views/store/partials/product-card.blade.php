{{--
    بطاقة الصنف — شكلان من قالبٍ واحد.

    الشبكة والقائمة اختيارُ التاجر في «التصميم»، وقالبان منفصلان يعنيان
    حقلًا يُضاف في أحدهما ويُنسى في الآخر.
--}}
@php $href = route('store.product', [$business, $product]); @endphp

@if($layout === 'list')
    <a href="{{ $href }}" class="flex items-center gap-4 rounded-2xl border border-[#e8e8e8] bg-white p-3 transition hover:border-[var(--store-accent)]">
        @include('store.partials.thumb', ['class' => 'size-20 shrink-0 rounded-xl object-cover'])
        <span class="min-w-0 flex-1">
            <span class="block truncate font-bold">{{ $product->name }}</span>
            @if($product->description)
                <span class="mt-1 block truncate text-[13px] text-[#6b7280]">{{ $product->description }}</span>
            @endif
        </span>
        @if($showPrices)
            <span class="shrink-0 font-bold text-[var(--store-accent)]">
                @include('store.partials.price')
            </span>
        @endif
    </a>
@else
    <a href="{{ $href }}" class="group overflow-hidden rounded-2xl border border-[#e8e8e8] bg-white transition hover:border-[var(--store-accent)]">
        <span class="block aspect-square overflow-hidden bg-[#fafafa]">
            @include('store.partials.thumb', ['class' => 'size-full object-cover transition duration-300 group-hover:scale-105'])
        </span>
        <span class="block p-4">
            <span class="block truncate font-bold">{{ $product->name }}</span>
            @if($product->category)
                <span class="mt-0.5 block truncate text-[12px] text-[#9ca3af]">{{ $product->category->name }}</span>
            @endif
            @if($showPrices)
                <span class="mt-2 block font-bold text-[var(--store-accent)]">
                    @include('store.partials.price')
                </span>
            @endif
        </span>
    </a>
@endif
