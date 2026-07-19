<x-layouts::super-admin title="الباقات">
    <x-page-header
        title="الباقات"
        subtitle="إدارة باقات الاشتراك والأسعار والمزايا المتاحة للشركات"
        :breadcrumbs="['الرئيسية' => route('super-admin.dashboard'), 'الاشتراكات' => route('super-admin.subscriptions.index'), 'الباقات' => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="refresh-cw" :href="route('super-admin.subscriptions.index')">الاشتراكات</x-button>
            <x-button variant="primary" size="md" icon="plus">باقة جديدة</x-button>
        </x-slot:actions>
    </x-page-header>

    @php
        $ringMap = [
            'primary' => 'ring-primary-500',
            'secondary' => 'ring-secondary-500',
        ];
        $badgeMap = [
            'primary' => 'bg-primary-600',
            'secondary' => 'bg-secondary-600',
        ];
        $iconMap = [
            'primary' => 'bg-primary-50 text-primary-600',
            'secondary' => 'bg-secondary-50 text-secondary-600',
        ];
        $priceMap = [
            'primary' => 'text-primary-600',
            'secondary' => 'text-secondary-600',
        ];
    @endphp

    {{-- شبكة الباقات --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @foreach (\App\Support\Demo::plans() as $plan)
            @php $c = $plan['color']; @endphp
            <div class="relative bg-white rounded-2xl border border-gray-100 p-6 shadow-sm flex flex-col {{ $plan['popular'] ? 'ring-2 ' . ($ringMap[$c] ?? 'ring-primary-500') . ' shadow-lg' : '' }}">
                @if ($plan['popular'])
                    <span class="absolute -top-3 right-6 inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold text-white {{ $badgeMap[$c] ?? 'bg-primary-600' }}">
                        <x-icon name="star" class="w-3.5 h-3.5" />
                        الأكثر شيوعًا
                    </span>
                @endif

                <div class="flex items-center gap-3 mb-4">
                    <span class="w-12 h-12 rounded-xl flex items-center justify-center {{ $iconMap[$c] ?? 'bg-primary-50 text-primary-600' }}">
                        <x-icon name="layers" class="w-6 h-6" />
                    </span>
                    <h3 class="text-lg font-bold text-gray-800">{{ $plan['name'] }}</h3>
                </div>

                <div class="mb-1">
                    <span class="text-3xl font-bold {{ $priceMap[$c] ?? 'text-primary-600' }}">{{ \App\Support\Demo::money($plan['monthly']) }}</span>
                    <span class="text-sm text-gray-400">/ شهريًا</span>
                </div>
                <p class="text-sm text-gray-500 mb-5">أو {{ \App\Support\Demo::money($plan['yearly']) }} سنويًا</p>

                <ul class="space-y-3 mb-6 flex-1">
                    @foreach ($plan['features'] as $feature)
                        <li class="flex items-center gap-2 text-sm text-gray-600">
                            <span class="shrink-0 w-5 h-5 rounded-full flex items-center justify-center {{ $iconMap[$c] ?? 'bg-primary-50 text-primary-600' }}">
                                <x-icon name="check" class="w-3.5 h-3.5" />
                            </span>
                            {{ $feature }}
                        </li>
                    @endforeach
                </ul>

                <x-button
                    :variant="$plan['popular'] ? 'primary' : 'outline'"
                    size="md"
                    icon="pencil"
                    class="w-full"
                    x-on:click="$dispatch('open-modal','edit-plan')"
                >تعديل الباقة</x-button>
            </div>
        @endforeach
    </div>

    {{-- نافذة تعديل الباقة --}}
    <x-modal name="edit-plan" title="تعديل مزايا الباقة" maxWidth="max-w-2xl">
        <form class="space-y-4">
            <x-input label="اسم الباقة" name="plan_name" value="الباقة الاحترافية" :required="true" />

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-input label="السعر الشهري (ر.ع)" name="monthly" type="number" value="24.900" />
                <x-input label="السعر السنوي (ر.ع)" name="yearly" type="number" value="249.000" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <x-input label="عدد الفروع" name="branches" type="number" value="3" />
                <x-input label="عدد الموظفين" name="employees" type="number" value="15" />
                <x-input label="عدد المنتجات" name="products" type="number" value="1000" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-select label="مستوى التقارير" name="reports" :options="['أساسية' => 'تقارير أساسية', 'متقدمة' => 'تقارير متقدمة', 'مؤسسية' => 'تقارير مؤسسية']" selected="متقدمة" />
                <x-select label="مستوى الدعم" name="support" :options="['بريد' => 'دعم بالبريد', 'ساعات-العمل' => 'دعم بساعات العمل', '24/7' => 'دعم على مدار الساعة']" selected="24/7" />
            </div>

            <div class="space-y-3 pt-2">
                <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                    <span class="text-sm font-medium text-gray-700">صلاحيات مخصصة</span>
                    <input type="checkbox" checked class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" />
                </label>
                <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                    <span class="text-sm font-medium text-gray-700">وصول API</span>
                    <input type="checkbox" class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" />
                </label>
                <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                    <span class="text-sm font-medium text-gray-700">وسم "الأكثر شيوعًا"</span>
                    <input type="checkbox" checked class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" />
                </label>
            </div>
        </form>

        <x-slot:footer>
            <x-button variant="outline" size="md" x-on:click="$dispatch('close-modal')">إلغاء</x-button>
            <x-button
                variant="primary"
                size="md"
                icon="save"
                x-on:click="$store.toasts.add('تم حفظ تعديلات الباقة بنجاح', 'success'); $dispatch('close-modal')"
            >حفظ التغييرات</x-button>
        </x-slot:footer>
    </x-modal>
</x-layouts::super-admin>
