{{--
    الموقع تحت الصيانة — بهويّة المتجر لا بهويّة أبعاد.

    زائرٌ يرى صفحةً بيضاء عليها «تحت الصيانة» لا يعرف أنّه وصل إلى المكان
    الصحيح، فيبحث عن عنوانٍ آخر أو ينصرف. فالاسمُ واللونُ يُرسمان معها.

    وهي ٥٠٣ لا ٢٠٠ — انظر StorefrontController::built.
--}}
@php
    $tokens = $doc['tokens'] ?? [];
    $primary = $tokens['primary'] ?? '#111111';
@endphp
<!DOCTYPE html>
<html lang="{{ $doc['locale'] ?? 'ar' }}" dir="{{ $doc['dir'] ?? 'rtl' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $doc['name'] }}</title>
    <meta name="theme-color" content="{{ $primary }}">
    {{-- ولا تُفهرَس حالُ لحظة: الصيانةُ تمضي والفهرسُ يبقى --}}
    <meta name="robots" content="noindex">
</head>
<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#fafafa;font:16px/1.7 system-ui,sans-serif;color:#111">
    <main style="max-width:26rem;padding:2rem 1.5rem;text-align:center">
        @if (! empty($doc['brand']['logo']))
            <img src="{{ $doc['brand']['logo'] }}" alt="{{ $doc['name'] }}" style="max-width:120px;height:auto;margin:0 auto 1.5rem">
        @endif

        <h1 style="margin:0 0 .5rem;font-size:1.375rem;color:{{ $primary }}">{{ $doc['name'] }}</h1>
        <p style="margin:0;color:#6b7280">{{ $doc['message'] }}</p>

        {{-- ووسيلةُ التواصل تبقى: من استعجل يصل إلى صاحب المحلّ --}}
        @if (! empty($doc['brand']['whatsapp']))
            <p style="margin:1.5rem 0 0">
                <a href="https://wa.me/{{ preg_replace('/\D+/', '', $doc['brand']['whatsapp']) }}"
                   style="color:{{ $primary }}" dir="ltr">{{ $doc['brand']['whatsapp'] }}</a>
            </p>
        @elseif (! empty($doc['brand']['phone']))
            <p style="margin:1.5rem 0 0">
                <a href="tel:{{ $doc['brand']['phone'] }}" style="color:{{ $primary }}" dir="ltr">{{ $doc['brand']['phone'] }}</a>
            </p>
        @endif
    </main>
</body>
</html>
