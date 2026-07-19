<x-layouts::super-admin title="تعديل محل الورود">

    @php $shop = \App\Support\Demo::flowerShop(request()->route('id')); @endphp

    <x-page-header title="تعديل محل الورود" :subtitle="'تعديل بيانات: ' . $shop['name']"
        :breadcrumbs="['الرئيسية' => route('super-admin.dashboard'), 'محلات الورود' => route('super-admin.flower-shops.index'), $shop['name'] => '#']">
    </x-page-header>

    <form class="space-y-6">
        {{-- بيانات المحل --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <div class="flex items-center gap-2 mb-5">
                <span class="w-9 h-9 rounded-xl bg-secondary-50 text-secondary-600 flex items-center justify-center">
                    <x-icon name="flower" class="w-5 h-5" />
                </span>
                <h3 class="font-bold text-gray-800">بيانات المحل</h3>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <x-input label="اسم المحل" name="name" :value="$shop['name']" :required="true" />
                <x-input label="اسم المالك" name="owner" :value="$shop['owner']" :required="true" />
                <x-select label="المدينة" name="city" :selected="$shop['city']"
                    :options="['مسقط' => 'مسقط', 'صلالة' => 'صلالة', 'صحار' => 'صحار', 'نزوى' => 'نزوى', 'صور' => 'صور']" />
                <x-input label="رقم الهاتف" name="phone" type="tel" icon="phone" value="+968 91234567" />
                <x-input label="البريد الإلكتروني" name="email" type="email" icon="mail" value="info@flower.com" />
                <x-input label="عدد الفروع" name="branches" type="number" :value="$shop['branches']" />
            </div>
        </div>

        {{-- الاشتراك --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <div class="flex items-center gap-2 mb-5">
                <span class="w-9 h-9 rounded-xl bg-info-50 text-info-600 flex items-center justify-center">
                    <x-icon name="layers" class="w-5 h-5" />
                </span>
                <h3 class="font-bold text-gray-800">الاشتراك والباقة</h3>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <x-select label="الباقة" name="plan" :selected="$shop['plan']"
                    :options="['أساسية' => 'أساسية', 'احترافية' => 'احترافية', 'مؤسسات' => 'مؤسسات']" />
                <x-select label="حالة الحساب" name="status" :selected="$shop['status']"
                    :options="['نشط' => 'نشط', 'منتهي' => 'منتهي']" />
                <x-input label="تاريخ البداية" name="start" type="date" value="2025-01-01" />
                <x-input label="تاريخ الانتهاء" name="end" type="date" value="2026-01-01" />
            </div>
        </div>

        {{-- رفع الشعار --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <div class="flex items-center gap-2 mb-5">
                <span class="w-9 h-9 rounded-xl bg-warning-50 text-warning-600 flex items-center justify-center">
                    <x-icon name="image" class="w-5 h-5" />
                </span>
                <h3 class="font-bold text-gray-800">شعار المحل</h3>
            </div>
            <div class="flex items-center gap-5">
                <img src="{{ $shop['logo'] }}" alt="{{ $shop['name'] }}" class="w-20 h-20 rounded-2xl object-cover border border-gray-100" />
                <label class="flex-1 flex flex-col items-center justify-center gap-2 border-2 border-dashed border-gray-200 rounded-2xl p-6 text-center cursor-pointer hover:border-secondary-300 hover:bg-secondary-50/30 transition">
                    <span class="w-11 h-11 rounded-xl bg-gray-50 text-gray-400 flex items-center justify-center">
                        <x-icon name="upload" class="w-5 h-5" />
                    </span>
                    <span class="text-sm font-medium text-gray-700">تغيير الشعار</span>
                    <span class="text-xs text-gray-400">PNG أو JPG بحد أقصى 2 ميجابايت</span>
                    <input type="file" name="logo" class="hidden" accept="image/*" />
                </label>
            </div>
        </div>

        {{-- أزرار الحفظ --}}
        <div class="flex items-center justify-end gap-3">
            <x-button variant="outline" :href="route('super-admin.flower-shops.index')">إلغاء</x-button>
            <x-button variant="secondary" type="button" icon="check" @click="$store.toasts.add('تم حفظ التعديلات بنجاح')">حفظ التعديلات</x-button>
        </div>
    </form>

</x-layouts::super-admin>
