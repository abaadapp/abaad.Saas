<x-layouts::admin :title="__('إضافة منتج')">

    <x-page-header :title="__('إضافة منتج')" :subtitle="__('أضف منتجًا جديدًا إلى متجرك')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المنتجات') => route('admin.products.index'), __('إضافة منتج') => '#']" />

    <form method="POST" action="{{ route('admin.products.store') }}" enctype="multipart/form-data" x-data="{ active: true, imgUrl: '' }" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        @csrf

        <div class="lg:col-span-2 space-y-6">
            {{-- المعلومات الأساسية --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-4">{{ __('المعلومات الأساسية') }}</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <x-input :label="__('اسم المنتج')" name="name" :placeholder="__('مثال: باقة ورد أحمر')" :required="true" />
                    </div>
                    <div class="md:col-span-2">
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-1.5">{{ __('الوصف') }}</label>
                        <textarea id="description" name="description" rows="4" placeholder="{{ __('اكتب وصفًا مختصرًا للمنتج...') }}"
                            class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-800 placeholder-gray-400 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none transition"></textarea>
                    </div>
                    <x-select :label="__('القسم')" name="category_id" :options="collect(\App\Support\Demo::categories())->pluck('name', 'id')->toArray()" :placeholder="__('اختر القسم')" />
                    <x-input :label="__('رمز المنتج SKU')" name="sku" placeholder="FLW-0001" />
                    <x-input :label="__('الباركود')" name="barcode" placeholder="628..." icon="scan-barcode" />
                </div>
            </div>

            {{-- التسعير --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-4">{{ __('التسعير') }}</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-input :label="__('سعر البيع (ر.ع)')" name="price" type="number" placeholder="0.000" :required="true" />
                    <x-input :label="__('سعر التكلفة (ر.ع)')" name="cost" type="number" placeholder="0.000" />
                    <x-select :label="__('الضريبة')" name="tax" :options="['0' => __('بدون ضريبة'), '5' => '5%', '10' => '10%']" selected="5" />
                    <x-input :label="__('الخصم (%)')" name="discount" type="number" placeholder="0" />
                </div>
            </div>

            {{-- المخزون --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-4">{{ __('المخزون') }}</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-input :label="__('الكمية المتوفرة')" name="quantity" type="number" placeholder="0" />
                    <x-input :label="__('حد التنبيه')" name="alert_qty" type="number" placeholder="10" :hint="__('تنبيه عند انخفاض الكمية عن هذا الحد')" />
                </div>
            </div>

            {{-- الصور --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-4">{{ __('صورة المنتج') }}</h3>
                <label class="relative block aspect-video rounded-xl border-2 border-dashed border-gray-200 hover:border-primary-400 bg-gray-50 overflow-hidden cursor-pointer transition">
                    <template x-if="imgUrl">
                        <img :src="imgUrl" class="absolute inset-0 w-full h-full object-cover" alt="{{ __('معاينة') }}" />
                    </template>
                    <div class="absolute inset-0 flex flex-col items-center justify-center gap-2 text-gray-400" :class="imgUrl ? 'bg-black/30 text-white opacity-0 hover:opacity-100 transition' : ''">
                        <x-icon name="image-plus" class="w-8 h-8" />
                        <span class="text-xs" x-text="imgUrl ? @js(__('تغيير الصورة')) : @js(__('اسحب صورة أو انقر للرفع (حتى 4MB)'))"></span>
                    </div>
                    <input type="file" name="image" class="hidden" accept="image/*"
                           @change="imgUrl = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : ''" />
                </label>
                @error('image')<p class="mt-2 text-xs text-danger-500">{{ $message }}</p>@enderror
            </div>
        </div>

        {{-- العمود الجانبي --}}
        <div class="space-y-6">
            {{-- الحالة --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-4">{{ __('حالة المنتج') }}</h3>
                <input type="hidden" name="active" :value="active ? 1 : 0" />
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-800">{{ __('تفعيل المنتج') }}</p>
                        <p class="text-xs text-gray-400 mt-0.5">{{ __('إظهار المنتج في نقطة البيع والمتجر') }}</p>
                    </div>
                    <button type="button" @click="active = !active"
                        :class="active ? 'bg-primary-600' : 'bg-gray-300'"
                        class="relative w-12 h-6 rounded-full transition-colors shrink-0">
                        <span :class="active ? 'translate-x-0' : 'translate-x-6'"
                            class="absolute top-0.5 right-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform"></span>
                    </button>
                </div>
            </div>

            {{-- أزرار الحفظ --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-3">
                <x-button variant="primary" type="submit" icon="check" class="w-full">{{ __('حفظ المنتج') }}</x-button>
                <x-button variant="outline" type="button" :href="route('admin.products.index')" class="w-full">{{ __('إلغاء') }}</x-button>
            </div>
        </div>

    </form>

</x-layouts::admin>
