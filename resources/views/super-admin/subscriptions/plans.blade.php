<x-layouts::super-admin :title="__('الباقات')">
    <x-page-header
        :title="__('الباقات')"
        :subtitle="__('إدارة باقات الاشتراك والأسعار والمزايا المتاحة للشركات')"
        :breadcrumbs="[__('الرئيسية') => route('super-admin.dashboard'), __('الاشتراكات') => route('super-admin.subscriptions.index'), __('الباقات') => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="refresh-cw" :href="route('super-admin.subscriptions.index')">{{ __('الاشتراكات') }}</x-button>
            <x-button variant="primary" size="md" icon="plus" @click="$dispatch('open-modal','add-plan')">{{ __('باقة جديدة') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- نافذة إضافة باقة --}}
    <x-modal name="add-plan" :title="__('إضافة باقة اشتراك جديدة')">
        <form id="add-plan-form" method="POST" action="{{ route('super-admin.plans.store') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm text-gray-600 mb-1">{{ __('اسم الباقة') }} <span class="text-danger-500">*</span></label>
                <input type="text" name="name" required placeholder="{{ __('مثال: الباقة الاحترافية') }}" class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">{{ __('السعر الشهري (ر.ع)') }} <span class="text-danger-500">*</span></label>
                    <input type="number" step="0.001" min="0" name="monthly_price" required class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">{{ __('السعر السنوي (ر.ع)') }} <span class="text-danger-500">*</span></label>
                    <input type="number" step="0.001" min="0" name="yearly_price" required class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
                </div>
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">{{ __('اللون') }}</label>
                <x-select name="color" selected="primary" :options="['primary' => __('أساسي'), 'secondary' => __('ثانوي')]" />
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">{{ __('المزايا (ميزة في كل سطر)') }}</label>
                <textarea name="features" rows="4" placeholder="{{ __('عدد فروع غير محدود') }}&#10;{{ __('تقارير متقدمة') }}&#10;{{ __('دعم فني على مدار الساعة') }}" class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200"></textarea>
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" name="is_popular" value="1" class="rounded border-gray-300 text-primary-600 focus:ring-primary-200" /> {{ __('باقة مميّزة (الأكثر شيوعًا)') }}
            </label>
        </form>
        <x-slot:footer>
            <x-button variant="light" @click="$dispatch('close-modal')">{{ __('إلغاء') }}</x-button>
            <button type="submit" form="add-plan-form" class="bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-full px-4 py-2">{{ __('إضافة الباقة') }}</button>
        </x-slot:footer>
    </x-modal>

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
                        {{ __('الأكثر شيوعًا') }}
                    </span>
                @endif

                <div class="flex items-center gap-3 mb-4">
                    <span class="w-12 h-12 rounded-xl flex items-center justify-center {{ $iconMap[$c] ?? 'bg-primary-50 text-primary-600' }}">
                        <x-icon name="layers" class="w-6 h-6" />
                    </span>
                    <h3 class="text-lg font-bold text-gray-800">{{ __($plan['name']) }}</h3>
                </div>

                <div class="mb-1">
                    <span class="text-3xl font-bold {{ $priceMap[$c] ?? 'text-primary-600' }}">{{ \App\Support\Demo::money($plan['monthly']) }}</span>
                    <span class="text-sm text-gray-400">{{ __('/ شهريًا') }}</span>
                </div>
                <p class="text-sm text-gray-500 mb-5">{{ __('أو :amount سنويًا', ['amount' => \App\Support\Demo::money($plan['yearly'])]) }}</p>

                <ul class="space-y-3 mb-6 flex-1">
                    @foreach ($plan['features'] as $feature)
                        <li class="flex items-center gap-2 text-sm text-gray-600">
                            <span class="shrink-0 w-5 h-5 rounded-full flex items-center justify-center {{ $iconMap[$c] ?? 'bg-primary-50 text-primary-600' }}">
                                <x-icon name="check" class="w-3.5 h-3.5" />
                            </span>
                            {{ __($feature) }}
                        </li>
                    @endforeach
                </ul>

                <x-button
                    :variant="$plan['popular'] ? 'primary' : 'outline'"
                    size="md"
                    icon="pencil"
                    class="w-full"
                    x-on:click="$dispatch('open-modal','edit-plan')"
                >{{ __('تعديل الباقة') }}</x-button>
            </div>
        @endforeach
    </div>

    {{-- نافذة تعديل الباقة --}}
    <x-modal name="edit-plan" :title="__('تعديل مزايا الباقة')" maxWidth="max-w-2xl">
        <form class="space-y-4">
            <x-input :label="__('اسم الباقة')" name="plan_name" value="الباقة الاحترافية" :required="true" />

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-input :label="__('السعر الشهري (ر.ع)')" name="monthly" type="number" value="24.900" />
                <x-input :label="__('السعر السنوي (ر.ع)')" name="yearly" type="number" value="249.000" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <x-input :label="__('عدد الفروع')" name="branches" type="number" value="3" />
                <x-input :label="__('عدد الموظفين')" name="employees" type="number" value="15" />
                <x-input :label="__('عدد المنتجات')" name="products" type="number" value="1000" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-select :label="__('مستوى التقارير')" name="reports" :options="['أساسية' => __('تقارير أساسية'), 'متقدمة' => __('تقارير متقدمة'), 'مؤسسية' => __('تقارير مؤسسية')]" selected="متقدمة" />
                <x-select :label="__('مستوى الدعم')" name="support" :options="['بريد' => __('دعم بالبريد'), 'ساعات-العمل' => __('دعم بساعات العمل'), '24/7' => __('دعم على مدار الساعة')]" selected="24/7" />
            </div>

            <div class="space-y-3 pt-2">
                <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                    <span class="text-sm font-medium text-gray-700">{{ __('صلاحيات مخصصة') }}</span>
                    <input type="checkbox" checked class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" />
                </label>
                <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                    <span class="text-sm font-medium text-gray-700">{{ __('وصول API') }}</span>
                    <input type="checkbox" class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" />
                </label>
                <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                    <span class="text-sm font-medium text-gray-700">{{ __('وسم "الأكثر شيوعًا"') }}</span>
                    <input type="checkbox" checked class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" />
                </label>
            </div>
        </form>

        <x-slot:footer>
            <x-button variant="outline" size="md" x-on:click="$dispatch('close-modal')">{{ __('إلغاء') }}</x-button>
            <x-button
                variant="primary"
                size="md"
                icon="save"
                x-on:click="$store.toasts.add('{{ __('تم حفظ تعديلات الباقة بنجاح') }}', 'success'); $dispatch('close-modal')"
            >{{ __('حفظ التغييرات') }}</x-button>
        </x-slot:footer>
    </x-modal>
</x-layouts::super-admin>
