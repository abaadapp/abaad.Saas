{{--
    هيكل صفحات المتجر — الترويسة والتذييل واللون.

    لون المتجر يدخل من هنا متغيّرًا لا صنفًا: أصناف تيلويند تُبنى وقت البناء،
    ولونٌ يختاره التاجر بعد البناء لا صنف له. فالقوالب تكتب
    `bg-[var(--store-accent)]` ويُملأ المتغيّر بما اختاره كلُّ تاجر.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? $business->name }}</title>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit(strip_tags($site['site_about'] ?: $site['site_tagline']), 160) }}">

    {{-- متجرٌ غير منشور لا يُفهرَس: صاحبه وحده يراه، ومحرّكات البحث ليست صاحبه --}}
    @unless($preview ?? false)
        <meta property="og:title" content="{{ $business->name }}">
        <meta property="og:description" content="{{ \Illuminate\Support\Str::limit(strip_tags($site['site_about'] ?: $site['site_tagline']), 160) }}">
        @if($logo)<meta property="og:image" content="{{ $logo }}">@endif
    @else
        <meta name="robots" content="noindex, nofollow">
    @endunless

    @vite(['resources/css/app.css'])

    <style>
        :root {
            --store-accent: {{ $theme['accent'] }};
            --store-on-accent: {{ $theme['on_accent'] }};
            --store-soft: {{ $theme['soft'] }};
        }
    </style>
</head>
<body class="min-h-screen bg-white font-sans text-[#111827] antialiased">

@if($preview ?? false)
    {{-- شريطٌ يقول للتاجر إنّ ما يراه لا يراه غيره — قبل أن يرسل الرابط لأحد --}}
    <div class="bg-[#111827] px-4 py-2 text-center text-[13px] text-white">
        {{ __('هذه معاينة — متجرك غير منشور، ولا يفتحه أحد سواك.') }}
    </div>
@endif

<header class="sticky top-0 z-20 border-b border-[#e8e8e8] bg-white/95 backdrop-blur">
    <div class="mx-auto flex max-w-6xl items-center gap-4 px-4 py-3">
        <a href="{{ route('store.home', $business) }}" class="flex min-w-0 items-center gap-3">
            @if($logo)
                <img src="{{ $logo }}" alt="{{ $business->name }}" class="h-9 w-auto max-w-[150px] object-contain">
            @else
                <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-[var(--store-accent)] text-[15px] font-bold text-[var(--store-on-accent)]">
                    {{ mb_substr($business->name, 0, 1) }}
                </span>
            @endif
            <span class="min-w-0">
                <span class="block truncate font-bold leading-tight">{{ $business->name }}</span>
                @if($site['site_tagline'])
                    <span class="block truncate text-[12px] text-[#6b7280]">{{ $site['site_tagline'] }}</span>
                @endif
            </span>
        </a>

        <div class="ms-auto flex items-center gap-2">
            @if($whatsapp)
                <a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener"
                   class="rounded-full bg-[var(--store-soft)] px-4 py-2 text-[13px] font-medium text-[var(--store-accent)] hover:opacity-80">
                    {{ __('تواصل معنا') }}
                </a>
            @endif
        </div>
    </div>
</header>

<main>
    @yield('content')
</main>

<footer class="mt-16 border-t border-[#e8e8e8] bg-[#fafafa]">
    <div class="mx-auto max-w-6xl px-4 py-10">
        <div class="grid gap-8 sm:grid-cols-2">
            <div>
                <h3 class="font-bold">{{ $business->name }}</h3>
                @if($site['site_tagline'])
                    <p class="mt-1 text-[13px] text-[#6b7280]">{{ $site['site_tagline'] }}</p>
                @endif
            </div>

            <div class="text-[13px] text-[#374151] sm:text-end">
                @if($business->phone)<p>{{ $business->phone }}</p>@endif
                @if($business->address)<p class="mt-1 text-[#6b7280]">{{ $business->address }}</p>@endif

                <div class="mt-3 flex gap-2 sm:justify-end">
                    @if($whatsapp)
                        <a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener"
                           class="rounded-full border border-[#e8e8e8] bg-white px-3 py-1.5 hover:border-[var(--store-accent)]">{{ __('واتساب') }}</a>
                    @endif
                    @if($site['site_instagram'])
                        <a href="https://instagram.com/{{ ltrim($site['site_instagram'], '@') }}" target="_blank" rel="noopener"
                           class="rounded-full border border-[#e8e8e8] bg-white px-3 py-1.5 hover:border-[var(--store-accent)]">{{ __('إنستغرام') }}</a>
                    @endif
                </div>
            </div>
        </div>

        <p class="mt-8 border-t border-[#e8e8e8] pt-5 text-[12px] text-[#9ca3af]">
            © {{ date('Y') }} {{ $business->name }}
        </p>
    </div>
</footer>

</body>
</html>
