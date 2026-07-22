<x-layouts::admin :title="__('إضافة قسم')">

    <x-page-header :title="__('إضافة قسم')" :subtitle="__('أنشئ قسمًا جديدًا لتنظيم منتجاتك')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الأقسام') => route('admin.categories.index'), __('إضافة قسم') => '#']" />

    <form method="POST" action="{{ route('admin.categories.store') }}" class="max-w-2xl">
        @csrf
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-4">
            <x-input :label="__('اسم القسم')" name="name" :placeholder="__('مثال: باقات ورد')" :required="true" />

            <div>
                <label for="description" class="block text-sm font-medium text-gray-700 mb-1.5">{{ __('الوصف') }}</label>
                <textarea id="description" name="description" rows="3" placeholder="{{ __('وصف مختصر للقسم...') }}"
                    class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-800 placeholder-gray-400 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none transition"></textarea>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-select :label="__('الأيقونة')" name="icon" :options="['flower' => __('زهرة'), 'gift' => __('هدية'), 'party-popper' => __('مناسبات'), 'candy' => __('شوكولاتة'), 'sprout' => __('نبتة'), 'sparkles' => __('تنسيق')]" :placeholder="__('اختر أيقونة')" />
                <x-select :label="__('القسم الأب')" name="parent" :options="collect(\App\Support\Demo::categories())->pluck('name', 'id')->toArray()" :placeholder="__('بدون (قسم رئيسي)')" />
            </div>

            @php $palette = ['#7c3aed', '#8b5cf6', '#6366f1', '#3b82f6', '#0ea5e9', '#06b6d4', '#14b8a6', '#10b981', '#22c55e', '#84cc16', '#eab308', '#f59e0b', '#f97316', '#ef4444', '#e11d48', '#db2777', '#d946ef', '#a855f7', '#64748b', '#78716c']; @endphp
            <div x-data="{ color: '{{ $palette[0] }}' }">
                <label class="block text-sm font-medium text-gray-700 mb-1.5">{{ __('اللون') }}</label>
                <input type="hidden" name="color" x-model="color" />
                <div class="flex flex-wrap items-center gap-2">
                    @foreach ($palette as $hex)
                        <button type="button" @click="color = '{{ $hex }}'"
                            class="w-9 h-9 rounded-full ring-2 ring-offset-2 transition"
                            :class="color === '{{ $hex }}' ? 'ring-gray-800' : 'ring-transparent'"
                            style="background: {{ $hex }}"></button>
                    @endforeach
                    <input type="color" x-model="color" title="{{ __('لون مخصص') }}"
                        class="w-9 h-9 rounded-full cursor-pointer border border-gray-200 bg-transparent p-0.5" />
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3 mt-5">
            <x-button variant="primary" type="submit" icon="check">{{ __('حفظ القسم') }}</x-button>
            <x-button variant="outline" type="button" :href="route('admin.categories.index')">{{ __('إلغاء') }}</x-button>
        </div>
    </form>

</x-layouts::admin>
