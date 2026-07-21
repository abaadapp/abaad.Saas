<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? __('لوحة التحكم') }} — Abad POS</title>
    <link rel="stylesheet" href="{{ asset('fonts/ibm-plex-arabic.css') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-[#f7f8f9]" x-data>
    @php
        $menu = [
            ['label' => __('الرئيسية'), 'icon' => 'layout-dashboard', 'route' => 'super-admin.dashboard'],
            ['heading' => __('الإدارة')],
            ['label' => __('الشركات'), 'icon' => 'building-2', 'route' => 'super-admin.businesses.index'],
            ['label' => __('محلات الورود'), 'icon' => 'flower', 'route' => 'super-admin.flower-shops.index'],
            ['label' => __('الاشتراكات'), 'icon' => 'refresh-cw', 'route' => 'super-admin.subscriptions.index'],
            ['label' => __('الباقات'), 'icon' => 'layers', 'route' => 'super-admin.subscriptions.plans'],
            ['label' => __('المستخدمون'), 'icon' => 'users', 'route' => 'super-admin.users.index'],
            ['heading' => __('أخرى')],
            ['label' => __('التقارير'), 'icon' => 'bar-chart-3', 'route' => 'super-admin.reports.index'],
            ['label' => __('سجل النشاط'), 'icon' => 'history', 'route' => 'super-admin.activity.index'],
            ['label' => __('الإعدادات'), 'icon' => 'settings', 'route' => 'super-admin.settings.index'],
        ];
    @endphp

    <x-sidebar :menu="$menu" brand="Abad POS" :subtitle="__('لوحة إدارة المنصة')" accent="primary" />

    <div class="lg:mr-72 flex flex-col min-h-screen">
        <x-topbar :title="$title ?? __('لوحة التحكم')" :user="__('مدير المنصة')" role="Super Admin" />
        <main class="flex-1 p-4 lg:p-6">
            {{ $slot }}
        </main>
    </div>

    <x-toasts />
</body>
</html>
