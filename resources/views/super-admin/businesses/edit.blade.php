<x-layouts::super-admin :title="__('تعديل الشركة')">

    @php $b = \App\Support\Demo::business(request()->route('id')); @endphp

    <x-page-header :title="__('تعديل الشركة')" :subtitle="__('تعديل بيانات: :name', ['name' => $b['name']])"
        :breadcrumbs="[__('الرئيسية') => route('super-admin.dashboard'), __('الشركات') => route('super-admin.businesses.index'), $b['name'] => '#']">
    </x-page-header>

    <form method="POST" action="{{ route('super-admin.businesses.update', $b['id']) }}" enctype="multipart/form-data" x-data="{ logoUrl: '{{ $b['logo'] }}' }" class="space-y-6">
        @csrf
        @method('PUT')
        {{-- بيانات الشركة --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <div class="flex items-center gap-2 mb-5">
                <span class="w-9 h-9 rounded-xl bg-primary-50 text-primary-600 flex items-center justify-center">
                    <x-icon name="building-2" class="w-5 h-5" />
                </span>
                <h3 class="font-bold text-gray-800">{{ __('بيانات الشركة') }}</h3>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <x-input :label="__('اسم الشركة')" name="name" :value="$b['name']" :required="true" />
                <x-select :label="__('نوع النشاط')" name="type" :selected="$b['type']"
                    :options="['محل ورود' => __('محل ورود'), 'مطعم' => __('مطعم'), 'كافيه' => __('كافيه'), 'بقالة' => __('بقالة'), 'صيدلية' => __('صيدلية'), 'متجر ملابس' => __('متجر ملابس')]" />
                <x-input :label="__('الدولة')" name="country" :value="$b['country']" />
                <x-select :label="__('المدينة')" name="city" :selected="$b['city']"
                    :options="['مسقط' => __('مسقط'), 'صلالة' => __('صلالة'), 'صحار' => __('صحار'), 'نزوى' => __('نزوى'), 'صور' => __('صور')]" />
                <div class="md:col-span-2">
                    <x-input :label="__('العنوان')" name="address" value="حي القرم، شارع السلطان قابوس" />
                </div>
            </div>
        </div>

        {{-- بيانات المالك --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <div class="flex items-center gap-2 mb-5">
                <span class="w-9 h-9 rounded-xl bg-secondary-50 text-secondary-600 flex items-center justify-center">
                    <x-icon name="user" class="w-5 h-5" />
                </span>
                <h3 class="font-bold text-gray-800">{{ __('بيانات المالك') }}</h3>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <x-input :label="__('اسم المالك')" name="owner_name" :value="$b['owner']" :required="true" />
                <x-input :label="__('رقم الهاتف')" name="phone" type="tel" icon="phone" :value="$b['phone']" />
                <div class="md:col-span-2">
                    <x-input :label="__('البريد الإلكتروني')" name="email" type="email" icon="mail" :value="$b['email']" />
                </div>
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
                <x-select :label="__('الباقة')" name="plan_id" :placeholder="__('اختر الباقة...')">
                    @foreach (\App\Models\Plan::pluck('name', 'id')->toArray() as $planId => $planName)
                        <option value="{{ $planId }}" @selected($b['plan'] === $planName)>{{ __($planName) }}</option>
                    @endforeach
                </x-select>
                <x-select :label="__('حالة الحساب')" name="status" :selected="$b['status']"
                    :options="['نشط' => __('نشط'), 'منتهي' => __('منتهي'), 'معطل' => __('معطل')]" />
                <x-input :label="__('تاريخ البداية')" name="starts_at" type="date" value="2025-01-01" />
                <x-input :label="__('تاريخ الانتهاء')" name="ends_at" type="date" value="2026-01-01" />
            </div>
        </div>

        {{-- رفع الشعار --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <div class="flex items-center gap-2 mb-5">
                <span class="w-9 h-9 rounded-xl bg-warning-50 text-warning-600 flex items-center justify-center">
                    <x-icon name="image" class="w-5 h-5" />
                </span>
                <h3 class="font-bold text-gray-800">{{ __('شعار الشركة') }}</h3>
            </div>
            <div class="flex items-center gap-5">
                <img :src="logoUrl" alt="{{ $b['name'] }}" class="w-20 h-20 rounded-2xl object-cover border border-gray-100" />
                <label class="flex-1 flex flex-col items-center justify-center gap-2 border-2 border-dashed border-gray-200 rounded-2xl p-6 text-center cursor-pointer hover:border-primary-300 hover:bg-primary-50/30 transition">
                    <span class="w-11 h-11 rounded-xl bg-gray-50 text-gray-400 flex items-center justify-center">
                        <x-icon name="upload" class="w-5 h-5" />
                    </span>
                    <span class="text-sm font-medium text-gray-700">{{ __('تغيير الشعار') }}</span>
                    <span class="text-xs text-gray-400">{{ __('PNG أو JPG بحد أقصى 2 ميجابايت') }}</span>
                    <input type="file" name="logo" class="hidden" accept="image/*"
                           @change="logoUrl = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : logoUrl" />
                </label>
            </div>
            @error('logo')<p class="mt-2 text-xs text-danger-500">{{ $message }}</p>@enderror
        </div>

        {{-- أزرار الحفظ --}}
        <div class="flex items-center justify-end gap-3">
            <x-button variant="outline" :href="route('super-admin.businesses.index')">{{ __('إلغاء') }}</x-button>
            <x-button variant="primary" type="submit" icon="check">{{ __('حفظ التعديلات') }}</x-button>
        </div>
    </form>

</x-layouts::super-admin>
