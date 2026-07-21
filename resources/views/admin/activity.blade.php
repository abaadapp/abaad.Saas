<x-layouts::admin :title="__('سجل النشاط')">
    @php
        $actionLabels = [
            'created' => __('إضافة'), 'updated' => __('تعديل'), 'deleted' => __('حذف'), 'login' => __('دخول'),
            'logout' => __('خروج'), 'checkout' => __('بيع'), 'status' => __('تغيير حالة'), 'settings' => __('إعدادات'),
            'hold' => __('تعليق'), 'shift' => __('وردية'),
        ];
        $colorMap = [
            'success' => 'bg-success-50 text-success-600', 'info' => 'bg-info-50 text-info-600',
            'danger' => 'bg-danger-50 text-danger-600', 'warning' => 'bg-warning-50 text-warning-600',
            'primary' => 'bg-primary-50 text-primary-600', 'gray' => 'bg-gray-100 text-gray-500',
        ];
    @endphp

    <x-page-header :title="__('سجل النشاط')" :subtitle="__('سجلّ كامل بكل العمليات التي تمّت على النظام')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('سجل النشاط') => '#']" />

    <form method="GET" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <div class="md:col-span-2">
                <x-input name="q" :placeholder="__('بحث في الوصف أو المستخدم...')" icon="search" value="{{ $filters['q'] ?? '' }}" />
            </div>
            <x-select name="action" :options="['created'=>__('إضافة'),'updated'=>__('تعديل'),'deleted'=>__('حذف'),'checkout'=>__('بيع'),'status'=>__('تغيير حالة'),'login'=>__('دخول'),'settings'=>__('إعدادات')]" :placeholder="__('كل العمليات')" selected="{{ $filters['action'] ?? '' }}" />
            <div class="flex items-center gap-2">
                <x-button type="submit" icon="filter">{{ __('تصفية') }}</x-button>
                <x-button variant="outline" :href="url()->current()">{{ __('تفريغ') }}</x-button>
            </div>
        </div>
    </form>

    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm">
        @forelse ($logs as $log)
            <div class="flex items-start gap-3 px-5 py-4 {{ ! $loop->last ? 'border-b border-gray-50' : '' }}">
                <span class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 {{ $colorMap[$log['color']] ?? $colorMap['primary'] }}">
                    <x-icon :name="$log['icon']" class="w-5 h-5" />
                </span>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <p class="font-medium text-gray-800 text-sm">{{ $log['user'] }}</p>
                        <x-badge :type="$log['color']">{{ $actionLabels[$log['action']] ?? __($log['action']) }}</x-badge>
                    </div>
                    <p class="text-sm text-gray-600 mt-0.5">{{ $log['description'] }}</p>
                    <p class="text-xs text-gray-400 mt-1">{{ $log['ago'] }} · {{ $log['time'] }} · IP {{ $log['ip'] ?? '—' }}</p>
                </div>
            </div>
        @empty
            <x-empty-state icon="history" :title="__('لا يوجد نشاط بعد')" :message="__('ستظهر هنا كل العمليات التي تُجرى على النظام.')" />
        @endforelse

        @if ($logs->total())
            <div class="px-5 py-4 border-t border-gray-100">
                <x-pagination :paginator="$logs" />
            </div>
        @endif
    </div>
</x-layouts::admin>
