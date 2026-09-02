@extends('store.layout', ['title' => $product->name.' — '.$business->name])

@section('content')

<div class="mx-auto max-w-6xl px-4 py-10">

    <a href="{{ route('store.home', $business) }}" class="text-[13px] text-[#6b7280] hover:text-[var(--store-accent)]">
        ← {{ __('كل المنتجات') }}
    </a>

    <div class="mt-6 grid gap-10 lg:grid-cols-2">
        <div>
            @include('store.partials.thumb', [
                'class' => 'aspect-square w-full rounded-2xl border border-[#e8e8e8] object-cover',
            ])

            @if($product->images->isNotEmpty())
                <div class="mt-3 grid grid-cols-4 gap-3">
                    @foreach($product->images as $image)
                        <img src="{{ $image->url }}" alt="" loading="lazy"
                             class="aspect-square w-full rounded-xl border border-[#e8e8e8] object-cover">
                    @endforeach
                </div>
            @endif
        </div>

        <div>
            @if($product->category)
                <p class="text-[13px] text-[#9ca3af]">{{ $product->category->name }}</p>
            @endif

            <h1 class="mt-1 text-[26px] font-extrabold leading-tight">{{ $product->name }}</h1>

            @if($showPrices)
                {{-- الصيغة الكتليّة لا السطريّة: تلك تُغلق عند أوّل «)» فتبتلع ما بعدها --}}
                @php $variants = $product->variants->where('active', true); @endphp

                <p class="mt-4 text-[24px] font-bold text-[var(--store-accent)]">
                    @include('store.partials.price')
                </p>

                @if($variants->isEmpty() && $product->discountRate() > 0)
                    {{-- السعر قبل الخصم مشطوبًا — والخصم يُقرأ من الصنف نفسه لا من إعدادٍ عامّ --}}
                    <p class="mt-1 text-[14px] text-[#9ca3af] line-through">
                        {{ \App\Support\Storefront::money((float) $product->price, $currency) }}
                    </p>
                @endif

                @if($variants->isNotEmpty())
                    {{--
                        المقاسات بأسعارها — لا «من كذا» وحدها.

                        الزبون يسأل عن الفرق بين الوسط والكبير قبل أن يطلب،
                        وسؤالُه في محادثةٍ يجعل نصفَ من يسأل لا يُكمل.
                    --}}
                    <ul class="mt-5 divide-y divide-[#f0f0f0] rounded-2xl border border-[#e8e8e8]">
                        @foreach($variants as $variant)
                            <li class="flex items-center justify-between gap-3 px-4 py-3 text-[14px]">
                                <span>{{ $variant->name }}</span>
                                <span class="font-bold text-[var(--store-accent)]">
                                    {{ \App\Support\Storefront::money((float) $variant->price, $currency) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @else
                <p class="mt-4 text-[15px] text-[#6b7280]">{{ __('السعر عند الطلب') }}</p>
            @endif

            @if($product->description)
                <p class="mt-5 whitespace-pre-line text-[15px] leading-relaxed text-[#374151]">{{ $product->description }}</p>
            @endif

            @if($allowOrders && $whatsapp)
                @php
                    /*
                     * الرسالة تحمل اسم الصنف ورابطه — لا «أريد أن أطلب» وحدها.
                     *
                     * التاجر يستقبل عشرات المحادثات، ورسالةٌ بلا اسمٍ تبدأ
                     * بسؤاله «أيّ منتج؟» — وهو سؤالٌ يُفقد نصف الطلبات.
                     */
                    $message = rawurlencode(
                        __('السلام عليكم، أريد طلب: :product', ['product' => $product->name])
                        ."\n".route('store.product', [$business, $product])
                    );
                @endphp
                <a href="https://wa.me/{{ $whatsapp }}?text={{ $message }}" target="_blank" rel="noopener"
                   class="mt-7 inline-block rounded-full bg-[var(--store-accent)] px-8 py-3.5 font-bold text-[var(--store-on-accent)] hover:opacity-90">
                    {{ __('اطلب عبر واتساب') }}
                </a>
            @elseif($whatsapp)
                <a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener"
                   class="mt-7 inline-block rounded-full border border-[#e8e8e8] px-8 py-3.5 font-bold hover:border-[var(--store-accent)]">
                    {{ __('استفسر عن المنتج') }}
                </a>
            @endif
        </div>
    </div>
</div>

@endsection
