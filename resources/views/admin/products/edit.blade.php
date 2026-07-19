<x-layouts::admin title="تعديل المنتج">

    @php $product = \App\Support\Demo::product(request()->route('id')); @endphp

    <x-page-header title="تعديل المنتج" subtitle="تعديل بيانات: {{ $product['name'] }}"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'المنتجات' => route('admin.products.index'), 'تعديل المنتج' => '#']" />

    <form method="POST" action="{{ route('admin.products.update', $product['id']) }}" enctype="multipart/form-data" x-data="{ active: {{ $product['active'] ? 'true' : 'false' }}, imgUrl: '{{ $product['image'] }}' }" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        @csrf
        @method('PUT')

        <div class="lg:col-span-2 space-y-6">
            {{-- المعلومات الأساسية --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-4">المعلومات الأساسية</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <x-input label="اسم المنتج" name="name" :value="$product['name']" :required="true" />
                    </div>
                    <div class="md:col-span-2">
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-1.5">الوصف</label>
                        <textarea id="description" name="description" rows="4"
                            class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-800 placeholder-gray-400 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none transition">باقة أنيقة من الورود الطبيعية الطازجة مناسبة لجميع المناسبات، منسّقة بعناية بأيدي خبراء التنسيق لدينا.</textarea>
                    </div>
                    <x-select label="التصنيف" name="category_id" :options="collect(\App\Support\Demo::categories())->pluck('name', 'id')->toArray()" :selected="collect(\App\Support\Demo::categories())->firstWhere('name', $product['cat'])['id'] ?? ''" />
                    <x-input label="رمز المنتج SKU" name="sku" :value="$product['sku']" />
                    <x-input label="الباركود" name="barcode" :value="$product['barcode']" icon="scan-barcode" />
                </div>
            </div>

            {{-- التسعير --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-4">التسعير</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-input label="سعر البيع (ر.ع)" name="price" type="number" :value="$product['price']" :required="true" />
                    <x-input label="سعر التكلفة (ر.ع)" name="cost" type="number" :value="$product['cost']" />
                    <x-select label="الضريبة" name="tax" :options="['0' => 'بدون ضريبة', '5' => '5%', '10' => '10%']" :selected="(string) $product['tax']" />
                    <x-input label="الخصم (%)" name="discount" type="number" :value="$product['discount']" />
                </div>
            </div>

            {{-- المخزون --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-4">المخزون</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-input label="الكمية المتوفرة" name="quantity" type="number" :value="$product['qty']" />
                    <x-input label="حد التنبيه" name="alert_qty" type="number" :value="$product['alert']" hint="تنبيه عند انخفاض الكمية عن هذا الحد" />
                </div>
            </div>

            {{-- الصور --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-4">صورة المنتج</h3>
                <label class="relative block aspect-video rounded-xl border-2 border-dashed border-gray-200 hover:border-primary-400 bg-gray-50 overflow-hidden cursor-pointer transition">
                    <img :src="imgUrl" class="absolute inset-0 w-full h-full object-cover" alt="{{ $product['name'] }}" x-show="imgUrl" />
                    <div class="absolute inset-0 flex flex-col items-center justify-center gap-2 text-gray-400" :class="imgUrl ? 'bg-black/30 text-white opacity-0 hover:opacity-100 transition' : ''">
                        <x-icon name="image-plus" class="w-8 h-8" />
                        <span class="text-xs">تغيير الصورة</span>
                    </div>
                    <input type="file" name="image" class="hidden" accept="image/*"
                           @change="imgUrl = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : imgUrl" />
                </label>
                @error('image')<p class="mt-2 text-xs text-danger-500">{{ $message }}</p>@enderror
            </div>
        </div>

        {{-- العمود الجانبي --}}
        <div class="space-y-6">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-4">حالة المنتج</h3>
                <input type="hidden" name="active" :value="active ? 1 : 0" />
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-800">تفعيل المنتج</p>
                        <p class="text-xs text-gray-400 mt-0.5">إظهار المنتج في نقطة البيع والمتجر</p>
                    </div>
                    <button type="button" @click="active = !active"
                        :class="active ? 'bg-primary-600' : 'bg-gray-300'"
                        class="relative w-12 h-6 rounded-full transition-colors shrink-0">
                        <span :class="active ? 'translate-x-0' : 'translate-x-6'"
                            class="absolute top-0.5 right-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform"></span>
                    </button>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-3">
                <x-button variant="primary" type="submit" icon="check" class="w-full">حفظ التغييرات</x-button>
                <x-button variant="outline" type="button" :href="route('admin.products.index')" class="w-full">إلغاء</x-button>
            </div>
        </div>

    </form>

</x-layouts::admin>
