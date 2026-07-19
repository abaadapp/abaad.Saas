<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'لوحة النشاط' }} — Abad POS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100">
    @php
        $menu = [
            ['label' => 'الرئيسية', 'icon' => 'layout-dashboard', 'route' => 'admin.dashboard'],
            ['heading' => 'المتجر'],
            ['label' => 'المنتجات', 'icon' => 'package', 'route' => 'admin.products.index'],
            ['label' => 'التصنيفات', 'icon' => 'tags', 'route' => 'admin.categories.index'],
            ['label' => 'الطلبات', 'icon' => 'shopping-cart', 'route' => 'admin.orders.index'],
            ['label' => 'المرتجعات', 'icon' => 'undo-2', 'route' => 'admin.returns.index'],
            ['label' => 'العملاء', 'icon' => 'users', 'route' => 'admin.customers.index'],
            ['label' => 'المواعيد', 'icon' => 'calendar', 'route' => 'admin.appointments.index'],
            ['label' => 'التسويق والكوبونات', 'icon' => 'megaphone', 'route' => 'admin.marketing.index'],
            ['heading' => 'الإدارة'],
            ['label' => 'الموظفون', 'icon' => 'user-cog', 'route' => 'admin.employees.index'],
            ['label' => 'المخزون', 'icon' => 'boxes', 'route' => 'admin.inventory.index'],
            ['label' => 'المورّدون', 'icon' => 'truck', 'route' => 'admin.suppliers.index'],
            ['label' => 'أوامر الشراء', 'icon' => 'clipboard-list', 'route' => 'admin.purchases.index'],
            ['label' => 'المالية', 'icon' => 'wallet', 'route' => 'admin.finance.index'],
            ['label' => 'المصروفات', 'icon' => 'arrow-down-circle', 'route' => 'admin.expenses.index'],
            ['label' => 'ضريبة القيمة المضافة', 'icon' => 'landmark', 'route' => 'admin.vat.index'],
            ['label' => 'التقارير', 'icon' => 'bar-chart-3', 'route' => 'admin.reports.index'],
            ['label' => 'تحليلات متقدمة', 'icon' => 'chart-line', 'route' => 'admin.analytics.index'],
            ['label' => 'الربحية', 'icon' => 'trending-up', 'route' => 'admin.profitability.index'],
            ['label' => 'سجل النشاط', 'icon' => 'history', 'route' => 'admin.activity.index'],
            ['label' => 'الإعدادات', 'icon' => 'settings', 'route' => 'admin.settings.index'],
            ['heading' => 'نقطة البيع'],
            ['label' => 'فتح نقطة البيع', 'icon' => 'store', 'route' => 'pos.index'],
        ];
    @endphp

    <x-sidebar :menu="$menu" brand="زهرة مسقط" subtitle="لوحة صاحب النشاط" accent="secondary" />

    <div class="lg:mr-72 flex flex-col min-h-screen">
        <x-topbar :title="$title ?? 'لوحة النشاط'" user="صاحب النشاط" role="مدير" avatar="https://picsum.photos/seed/adminuser/80/80" />
        <main class="flex-1 p-4 lg:p-6">
            {{ $slot }}
        </main>
    </div>

    <x-toasts />

    {{-- إشعارات المتصفح للطلبات الجديدة (استطلاع) --}}
    <div x-data x-init="window.startOrderAlerts('{{ route('admin.notifications.feed') }}')"></div>
</body>
</html>
