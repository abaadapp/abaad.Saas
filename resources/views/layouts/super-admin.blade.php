<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'لوحة التحكم' }} — Abad POS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100">
    @php
        $menu = [
            ['label' => 'الرئيسية', 'icon' => 'layout-dashboard', 'route' => 'super-admin.dashboard'],
            ['heading' => 'الإدارة'],
            ['label' => 'الشركات', 'icon' => 'building-2', 'route' => 'super-admin.businesses.index'],
            ['label' => 'محلات الورود', 'icon' => 'flower', 'route' => 'super-admin.flower-shops.index'],
            ['label' => 'الاشتراكات', 'icon' => 'refresh-cw', 'route' => 'super-admin.subscriptions.index'],
            ['label' => 'الباقات', 'icon' => 'layers', 'route' => 'super-admin.subscriptions.plans'],
            ['label' => 'المستخدمون', 'icon' => 'users', 'route' => 'super-admin.users.index'],
            ['heading' => 'أخرى'],
            ['label' => 'التقارير', 'icon' => 'bar-chart-3', 'route' => 'super-admin.reports.index'],
            ['label' => 'سجل النشاط', 'icon' => 'history', 'route' => 'super-admin.activity.index'],
            ['label' => 'الإعدادات', 'icon' => 'settings', 'route' => 'super-admin.settings.index'],
        ];
    @endphp

    <x-sidebar :menu="$menu" brand="Abad POS" subtitle="لوحة إدارة المنصة" accent="primary" />

    <div class="lg:mr-72 flex flex-col min-h-screen">
        <x-topbar :title="$title ?? 'لوحة التحكم'" user="مدير المنصة" role="Super Admin" />
        <main class="flex-1 p-4 lg:p-6">
            {{ $slot }}
        </main>
    </div>

    <x-toasts />
</body>
</html>
