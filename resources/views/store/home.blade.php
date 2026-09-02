@extends('store.layout')

@section('content')

{{-- الواجهة: غلافٌ رفعه التاجر، أو لونُه وحده حين لم يرفع --}}
<section class="relative overflow-hidden {{ $cover ? '' : 'bg-[var(--store-accent)]' }}">
    @if($cover)
        <img src="{{ $cover }}" alt="" class="absolute inset-0 size-full object-cover">
        <span class="absolute inset-0 bg-black/45"></span>
    @endif

    <div class="relative mx-auto max-w-6xl px-4 py-16 text-center sm:py-24">
        <h1 class="text-[28px] font-extrabold leading-tight text-white sm:text-[40px]"
            @if(! $cover) style="color: var(--store-on-accent)" @endif>
            {{ $site['site_hero_title'] ?: $business->name }}
        </h1>

        @if($site['site_hero_note'] ?: $site['site_tagline'])
            <p class="mx-auto mt-3 max-w-xl text-[15px] text-white/85 sm:text-[17px]"
               @if(! $cover) style="color: var(--store-on-accent); opacity: .8" @endif>
                {{ $site['site_hero_note'] ?: $site['site_tagline'] }}
            </p>
        @endif

        @if($allowOrders && $whatsapp)
            <a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener"
               class="mt-7 inline-block rounded-full bg-white px-7 py-3 font-bold text-[#111827] shadow-sm hover:opacity-90">
                {{ __('اطلب عبر واتساب') }}
            </a>
        @endif
    </div>
</section>

<div class="mx-auto max-w-6xl px-4 py-12">

    {{-- الأقسام — تظهر حين تكون أكثر من واحد، فقسمٌ واحد ليس تصفية --}}
    @if($site['site_show_categories'] === '1' && $categories->count() > 1)
        <nav class="mb-8 flex flex-wrap gap-2">
            <a href="{{ route('store.home', $business) }}"
               class="rounded-full border px-4 py-2 text-[13px] {{ $activeCategory ? 'border-[#e8e8e8] bg-white text-[#374151]' : 'border-transparent bg-[var(--store-accent)] text-[var(--store-on-accent)]' }}">
                {{ __('الكل') }}
            </a>
            @foreach($categories as $category)
                <a href="{{ route('store.home', [$business, 'category' => $category->id]) }}"
                   class="rounded-full border px-4 py-2 text-[13px] {{ $activeCategory === $category->id ? 'border-transparent bg-[var(--store-accent)] text-[var(--store-on-accent)]' : 'border-[#e8e8e8] bg-white text-[#374151] hover:border-[var(--store-accent)]' }}">
                    {{ $category->name }}
                </a>
            @endforeach
        </nav>
    @endif

    {{--
        البحث يظهر حين يكون في المتجر ما يُبحث فيه.

        وحقلُ بحثٍ فوق ستّة أصناف زينةٌ تشغل مكانًا؛ وفوق مئتين هو الطريق
        الوحيد إلى صنفٍ بعينه. والحدُّ على المعروض كلِّه لا على هذه الصفحة:
        الصفحة الثانية فيها أربعةٌ وعشرون كالأولى.
    --}}
    @if($products->total() > \App\Support\Storefront::PER_PAGE || $search !== '')
        <form method="get" action="{{ route('store.home', $business) }}" class="mb-8">
            @if($activeCategory)
                <input type="hidden" name="category" value="{{ $activeCategory }}">
            @endif
            <input
                type="search" name="q" value="{{ $search }}"
                placeholder="{{ __('ابحث في المتجر') }}"
                class="w-full rounded-full border border-[#e8e8e8] bg-white px-5 py-3 text-[14px] outline-none focus:border-[var(--store-accent)]"
            >
        </form>
    @endif

    @if($products->isEmpty())
        <div class="rounded-2xl border border-dashed border-[#e8e8e8] px-6 py-16 text-center">
            <p class="font-bold text-[#374151]">
                {{ $search !== '' ? __('لا يوجد ما يطابق بحثك') : __('لا توجد منتجات معروضة بعد') }}
            </p>
            @if($preview && $search === '')
                {{-- التاجر وحده يقرأ هذا: الزائر لا يهمّه أين مقبض العرض --}}
                <p class="mt-2 text-[13px] text-[#6b7280]">
                    {{ __('اختر ما يظهر هنا من «الإعدادات ← إعدادات الموقع ← المنتجات».') }}
                </p>
            @endif
        </div>
    @else
        <div class="{{ $site['site_layout'] === 'list' ? 'space-y-3' : 'grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4' }}">
            @foreach($products as $product)
                @include('store.partials.product-card', ['layout' => $site['site_layout']])
            @endforeach
        </div>

        @if($products->hasPages())
            <div class="mt-10">{{ $products->links() }}</div>
        @endif
    @endif

    @if($site['site_show_about'] === '1' && $site['site_about'])
        <section class="mt-16 rounded-2xl bg-[var(--store-soft)] p-8">
            <h2 class="font-bold">{{ __('من نحن') }}</h2>
            <p class="mt-3 whitespace-pre-line text-[14px] leading-relaxed text-[#374151]">{{ $site['site_about'] }}</p>
        </section>
    @endif
</div>

@endsection
