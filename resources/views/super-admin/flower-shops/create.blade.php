<x-layouts::super-admin title="إضافة محل ورود">

    <x-page-header title="إضافة محل ورود" subtitle="أدخل بيانات محل الورود الجديد"
        :breadcrumbs="['الرئيسية' => route('super-admin.dashboard'), 'محلات الورود' => route('super-admin.flower-shops.index'), 'إضافة محل' => '#']">
    </x-page-header>

    <form method="POST" action="{{ route('super-admin.flower-shops.store') }}" enctype="multipart/form-data" class="space-y-6">
        @csrf
        {{-- بيانات المحل --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <div class="flex items-center gap-2 mb-5">
                <span class="w-9 h-9 rounded-xl bg-secondary-50 text-secondary-600 flex items-center justify-center">
                    <x-icon name="flower" class="w-5 h-5" />
                </span>
                <h3 class="font-bold text-gray-800">بيانات المحل</h3>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <x-input label="اسم المحل" name="name" placeholder="مثال: زهرة مسقط" :required="true" />
                <x-input label="اسم المالك" name="owner" placeholder="الاسم الكامل" :required="true" />
                <x-select label="المدينة" name="city" placeholder="اختر المدينة..."
                    :options="['مسقط' => 'مسقط', 'صلالة' => 'صلالة', 'صحار' => 'صحار', 'نزوى' => 'نزوى', 'صور' => 'صور']" />
                <x-input label="رقم الهاتف" name="phone" type="tel" icon="phone" placeholder="+968 9xxxxxxx" />
                <x-input label="البريد الإلكتروني" name="email" type="email" icon="mail" placeholder="info@example.com" />
                <x-input label="عدد الفروع" name="branches" type="number" value="1" placeholder="عدد الفروع" />
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
                <x-select label="الباقة" name="plan" placeholder="اختر الباقة..."
                    :options="['أساسية' => 'أساسية', 'احترافية' => 'احترافية', 'مؤسسات' => 'مؤسسات']" />
                <x-select label="حالة الحساب" name="status" placeholder="اختر الحالة..." selected="نشط"
                    :options="['نشط' => 'نشط', 'منتهي' => 'منتهي']" />
                <x-input label="تاريخ البداية" name="start" type="date" />
                <x-input label="تاريخ الانتهاء" name="end" type="date" />
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
            <label class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-gray-200 rounded-2xl p-8 text-center cursor-pointer hover:border-secondary-300 hover:bg-secondary-50/30 transition">
                <span class="w-12 h-12 rounded-xl bg-gray-50 text-gray-400 flex items-center justify-center">
                    <x-icon name="upload" class="w-6 h-6" />
                </span>
                <span class="text-sm font-medium text-gray-700">اسحب الشعار هنا أو انقر للرفع</span>
                <span class="text-xs text-gray-400">PNG أو JPG بحد أقصى 2 ميجابايت</span>
                <input type="file" name="logo" class="hidden" accept="image/*" />
            </label>
        </div>

        {{-- أزرار الحفظ --}}
        <div class="flex items-center justify-end gap-3">
            <x-button variant="outline" :href="route('super-admin.flower-shops.index')">إلغاء</x-button>
            <x-button variant="secondary" type="submit" icon="check">حفظ المحل</x-button>
        </div>
    </form>

</x-layouts::super-admin>
