<x-layouts::super-admin :title="__('تعديل محل الورود')">

    @php $shop = \App\Support\Demo::flowerShop(request()->route('id')); @endphp

    <x-page-header :title="__('تعديل محل الورود')" :subtitle="__('تعديل بيانات: :name', ['name' => $shop['name']])"
        :breadcrumbs="[__('الرئيسية') => route('super-admin.dashboard'), __('محلات الورود') => route('super-admin.flower-shops.index'), $shop['name'] => '#']">
    </x-page-header>

    <form method="POST" action="{{ route('super-admin.flower-shops.update', $shop['id']) }}" enctype="multipart/form-data" class="space-y-6">
        @csrf
        @method('PUT')
        {{-- بيانات المحل --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <div class="flex items-center gap-2 mb-5">
                <span class="w-9 h-9 rounded-xl bg-secondary-50 text-secondary-600 flex items-center justify-center">
                    <x-icon name="flower" class="w-5 h-5" />
                </span>
                <h3 class="font-bold text-gray-800">{{ __('بيانات المحل') }}</h3>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <x-input :label="__('اسم المحل')" name="name" :value="$shop['name']" :required="true" />
                <x-input :label="__('اسم المالك')" name="owner" :value="$shop['owner']" :required="true" />
                <x-select :label="__('المدينة')" name="city" :selected="$shop['city']"
                    :options="['مسقط' => __('مسقط'), 'صلالة' => __('صلالة'), 'صحار' => __('صحار'), 'نزوى' => __('نزوى'), 'صور' => __('صور')]" />
                <x-input :label="__('رقم الهاتف')" name="phone" type="tel" icon="phone" value="+968 91234567" />
                <x-input :label="__('البريد الإلكتروني')" name="email" type="email" icon="mail" value="info@flower.com" />
                <x-input :label="__('عدد الفروع')" name="branches" type="number" :value="$shop['branches']" />
            </div>
        </div>

        {{-- الاشتراك --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <div class="flex items-center gap-2 mb-5">
                <span class="w-9 h-9 rounded-xl bg-info-50 text-info-600 flex items-center justify-center">
                    <x-icon name="layers" class="w-5 h-5" />
                </span>
                <h3 class="font-bold text-gray-800">{{ __('الاشتراك والباقة') }}</h3>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <x-select :label="__('الباقة')" name="plan" :selected="$shop['plan']"
                    :options="['أساسية' => 'أساسية', 'احترافية' => 'احترافية', 'مؤسسات' => 'مؤسسات']" />
                <x-select :label="__('حالة الحساب')" name="status" :selected="$shop['status']"
                    :options="['نشط' => __('نشط'), 'منتهي' => __('منتهي')]" />
                <x-input :label="__('تاريخ البداية')" name="start" type="date" value="2025-01-01" />
                <x-input :label="__('تاريخ الانتهاء')" name="end" type="date" value="2026-01-01" />
            </div>
        </div>

        {{-- رفع الشعار --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <div class="flex items-center gap-2 mb-5">
                <span class="w-9 h-9 rounded-xl bg-warning-50 text-warning-600 flex items-center justify-center">
                    <x-icon name="image" class="w-5 h-5" />
                </span>
                <h3 class="font-bold text-gray-800">{{ __('شعار المحل') }}</h3>
            </div>
            <div class="flex items-center gap-5">
                <img src="{{ $shop['logo'] }}" alt="{{ $shop['name'] }}" class="w-20 h-20 rounded-2xl object-cover border border-gray-100" />
                <label class="flex-1 flex flex-col items-center justify-center gap-2 border-2 border-dashed border-gray-200 rounded-2xl p-6 text-center cursor-pointer hover:border-secondary-300 hover:bg-secondary-50/30 transition">
                    <span class="w-11 h-11 rounded-xl bg-gray-50 text-gray-400 flex items-center justify-center">
                        <x-icon name="upload" class="w-5 h-5" />
                    </span>
                    <span class="text-sm font-medium text-gray-700">{{ __('تغيير الشعار') }}</span>
                    <span class="text-xs text-gray-400">{{ __('PNG أو JPG بحد أقصى 2 ميجابايت') }}</span>
                    <input type="file" name="logo" class="hidden" accept="image/*" />
                </label>
            </div>
        </div>

        {{-- أزرار الحفظ --}}
        <div class="flex items-center justify-end gap-3">
            <x-button variant="outline" :href="route('super-admin.flower-shops.index')">{{ __('إلغاء') }}</x-button>
            <x-button variant="secondary" type="submit" icon="check">{{ __('حفظ التعديلات') }}</x-button>
        </div>
    </form>

</x-layouts::super-admin>
