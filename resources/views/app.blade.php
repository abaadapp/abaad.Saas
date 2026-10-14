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

    {{-- علامةُ أبعاد في التبويب — من مسارها في Components/Logo.tsx. وتحت /brand/abaad/ لا في الجذر: نطاقاتُ المتاجر تخدم public/ نفسَه، والمتصفّحُ يطلب /favicon.ico و/apple-touch-icon.png من الجذر لكلّ صفحةٍ لا تُعلن أيقونتها، فيلبس متجرُ التاجر شعارَ أبعاد --}}
    <link rel="icon" href="/brand/abaad/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/brand/abaad/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/brand/abaad/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/brand/abaad/favicon-192x192.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/brand/abaad/apple-touch-icon.png">

    @routes
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="font-sans antialiased">
    @inertia
</body>
</html>
