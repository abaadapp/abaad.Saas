<x-layouts::admin :title="'تعديل: ' . $employee->name">
    <x-page-header
        title="تعديل بيانات الموظف"
        :subtitle="'تحديث بيانات: ' . $employee->name"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'الموظفون' => route('admin.employees.index'), $employee->name => route('admin.employees.show', $employee->id), 'تعديل' => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="arrow-right" :href="route('admin.employees.show', $employee->id)">رجوع</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- بيانات الموظف --}}
        <form method="POST" action="{{ route('admin.employees.update', $employee->id) }}" class="lg:col-span-2 space-y-6">
            @csrf
            @method('PUT')
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6 flex items-center gap-2">
                    <x-icon name="user" class="w-5 h-5 text-primary-600" /> البيانات الأساسية
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-input label="الاسم الكامل" name="name" :value="old('name', $employee->name)" icon="user" :required="true" />
                    <x-select label="الوظيفة / الدور" name="role" :selected="old('role', $employee->role)" :required="true" :options="[
                        'manager' => 'مدير',
                        'cashier' => 'كاشير',
                        'sales' => 'موظف مبيعات',
                        'accountant' => 'محاسب',
                        'inventory' => 'مسؤول مخزون',
                        'delivery' => 'مندوب توصيل',
                    ]" />
                    <x-select label="الفرع" name="branch" :selected="old('branch', $employee->branch)" :options="[
                        'الفرع الرئيسي' => 'الفرع الرئيسي - مسقط',
                        'فرع الخوير' => 'فرع الخوير',
                        'فرع روي' => 'فرع روي',
                        'فرع صحار' => 'فرع صحار',
                    ]" />
                    <x-input label="رقم الهاتف" name="phone" type="tel" :value="old('phone', $employee->phone)" icon="phone" />
                    <div class="sm:col-span-2">
                        <x-input label="البريد الإلكتروني" name="email" type="email" :value="old('email', $employee->email)" icon="mail" :required="true" />
                    </div>
                </div>
                @error('email')<p class="mt-2 text-xs text-danger-500">{{ $message }}</p>@enderror
                @error('name')<p class="mt-2 text-xs text-danger-500">{{ $message }}</p>@enderror
                <div class="mt-6 flex justify-end gap-2">
                    <x-button variant="outline" size="md" :href="route('admin.employees.show', $employee->id)">إلغاء</x-button>
                    <x-button variant="primary" size="md" type="submit" icon="save">حفظ التغييرات</x-button>
                </div>
            </div>
        </form>

        {{-- إجراءات الحساب --}}
        <div class="lg:col-span-1 space-y-6">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 text-center">
                <img src="{{ $employee->avatar }}" alt="{{ $employee->name }}" class="w-20 h-20 rounded-full object-cover mx-auto ring-4 ring-primary-50" />
                <h3 class="mt-3 font-bold text-gray-800">{{ $employee->name }}</h3>
                <div class="mt-2"><x-badge :text="$employee->status" /></div>
            </div>

            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-3">
                <h3 class="text-sm font-bold text-gray-800 mb-1">إجراءات الحساب</h3>

                {{-- تفعيل / تعطيل --}}
                <form method="POST" action="{{ route('admin.employees.toggle', $employee->id) }}">
                    @csrf
                    @if ($employee->status === 'نشط')
                        <x-button variant="outline" size="md" type="submit" icon="lock" class="w-full">تعطيل الحساب</x-button>
                    @else
                        <x-button variant="success" size="md" type="submit" icon="lock-open" class="w-full">تفعيل الحساب</x-button>
                    @endif
                </form>

                {{-- إعادة تعيين كلمة المرور --}}
                <form method="POST" action="{{ route('admin.employees.resetPassword', $employee->id) }}"
                      @submit="if(!confirm('سيتم توليد كلمة مرور مؤقتة جديدة لهذا الموظف. متابعة؟')) $event.preventDefault()">
                    @csrf
                    <x-button variant="light" size="md" type="submit" icon="key-round" class="w-full">إعادة تعيين كلمة المرور</x-button>
                </form>
                <p class="text-xs text-gray-400">ستظهر كلمة المرور المؤقتة في إشعار بعد التعيين.</p>
            </div>
        </div>
    </div>
</x-layouts::admin>
