<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'تسجيل الدخول' }} — Abad POS</title>
    <link rel="stylesheet" href="{{ asset('fonts/ibm-plex-arabic.css') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gradient-to-bl from-primary-50 via-gray-50 to-secondary-50">
    <div class="min-h-screen flex items-center justify-center p-4">
        {{ $slot }}
    </div>
    <x-toasts />
</body>
</html>
