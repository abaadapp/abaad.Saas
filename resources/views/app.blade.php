<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'en' ? 'ltr' : 'rtl' }}">
<head>
    <meta charset="utf-8">
    {{-- viewport-fit=cover كي تصير env(safe-area-inset-*) قيمًا حقيقية على الآيباد --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- واجهةُ النظام لا تُفهرَس: أبعاد في البحث هي abaadapp.om. ومتاجرُ التجّار لا تمرّ من هنا — قوالبُها مستقلّة (store/*، site/*) --}}
    <meta name="robots" content="noindex, nofollow">

    <title inertia>{{ config('app.name', 'Abad POS') }}</title>

    @routes
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="font-sans antialiased">
    @inertia
</body>
</html>
