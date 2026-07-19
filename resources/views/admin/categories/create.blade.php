<x-layouts::admin title="إضافة تصنيف">

    <x-page-header title="إضافة تصنيف" subtitle="أنشئ تصنيفًا جديدًا لتنظيم منتجاتك"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'التصنيفات' => route('admin.categories.index'), 'إضافة تصنيف' => '#']" />

    <form method="POST" action="{{ route('admin.categories.store') }}" class="max-w-2xl">
        @csrf
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-4">
            <x-input label="اسم التصنيف" name="name" placeholder="مثال: باقات ورد" :required="true" />

            <div>
                <label for="description" class="block text-sm font-medium text-gray-700 mb-1.5">الوصف</label>
                <textarea id="description" name="description" rows="3" placeholder="وصف مختصر للتصنيف..."
                    class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-800 placeholder-gray-400 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none transition"></textarea>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-select label="الأيقونة" name="icon" :options="['flower' => 'زهرة', 'gift' => 'هدية', 'party-popper' => 'مناسبات', 'candy' => 'شوكولاتة', 'sprout' => 'نبتة', 'sparkles' => 'تنسيق']" placeholder="اختر أيقونة" />
                <x-select label="التصنيف الأب" name="parent" :options="collect(\App\Support\Demo::categories())->pluck('name', 'id')->toArray()" placeholder="بدون (تصنيف رئيسي)" />
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">اللون</label>
                <div class="flex items-center gap-3">
                    @foreach (['primary' => '#7c3aed', 'secondary' => '#db2777', 'success' => '#10b981', 'warning' => '#f59e0b', 'info' => '#3b82f6'] as $key => $hex)
                        <label class="cursor-pointer">
                            <input type="radio" name="color" value="{{ $key }}" class="sr-only peer" @checked($loop->first) />
                            <span class="block w-9 h-9 rounded-full ring-2 ring-transparent peer-checked:ring-gray-800 peer-checked:ring-offset-2 transition" style="background: {{ $hex }}"></span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3 mt-5">
            <x-button variant="primary" type="submit" icon="check">حفظ التصنيف</x-button>
            <x-button variant="outline" type="button" :href="route('admin.categories.index')">إلغاء</x-button>
        </div>
    </form>

</x-layouts::admin>
