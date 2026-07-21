<x-layouts::admin :title="__('تعديل: :name', ['name' => $employee->name])">
    <x-page-header
        :title="__('تعديل بيانات الموظف')"
        :subtitle="__('تحديث بيانات: :name', ['name' => $employee->name])"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الموظفون') => route('admin.employees.index'), $employee->name => route('admin.employees.show', $employee->id), __('تعديل') => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="arrow-right" :href="route('admin.employees.show', $employee->id)">{{ __('رجوع') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- بيانات الموظف --}}
        <form method="POST" action="{{ route('admin.employees.update', $employee->id) }}" class="lg:col-span-2 space-y-6">
            @csrf
            @method('PUT')
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6 flex items-center gap-2">
                    <x-icon name="user" class="w-5 h-5 text-primary-600" /> {{ __('البيانات الأساسية') }}
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-input :label="__('الاسم الكامل')" name="name" :value="old('name', $employee->name)" icon="user" :required="true" />
                    <x-select :label="__('الوظيفة / الدور')" name="job_title" :placeholder="__('اختر الوظيفة...')" :required="true"
                        :options="\App\Models\JobTitle::where('business_id', \App\Support\Demo::bid())->orderBy('name')->get()->mapWithKeys(fn ($jt) => [$jt->name => __($jt->name)])->toArray()" :selected="old('job_title', $employee->job_title)" />
                    <x-select :label="__('الفرع')" name="branch"
                        :options="collect(\App\Support\Demo::branches())->pluck('name', 'name')->toArray()" :selected="old('branch', $employee->branch)" />
                    <x-input :label="__('رقم الهاتف')" name="phone" type="tel" :value="old('phone', $employee->phone)" icon="phone" />
                    <div class="sm:col-span-2">
                        <x-input :label="__('البريد الإلكتروني')" name="email" type="email" :value="old('email', $employee->email)" icon="mail" :required="true" />
                    </div>
                </div>
                @error('email')<p class="mt-2 text-xs text-danger-500">{{ $message }}</p>@enderror
                @error('name')<p class="mt-2 text-xs text-danger-500">{{ $message }}</p>@enderror
                <div class="mt-6 flex justify-end gap-2">
                    <x-button variant="outline" size="md" :href="route('admin.employees.show', $employee->id)">{{ __('إلغاء') }}</x-button>
                    <x-button variant="primary" size="md" type="submit" icon="save">{{ __('حفظ التغييرات') }}</x-button>
                </div>
            </div>
        </form>

        {{-- إجراءات الحساب --}}
        <div class="lg:col-span-1 space-y-6">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 text-center">
                <img src="{{ $employee->avatar }}" alt="{{ $employee->name }}" class="w-20 h-20 rounded-full object-cover mx-auto ring-4 ring-primary-50" />
                <h3 class="mt-3 font-bold text-gray-800">{{ $employee->name }}</h3>
                <div class="mt-2"><x-badge :text="__($employee->status)" /></div>
            </div>

            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-3">
                <h3 class="text-sm font-bold text-gray-800 mb-1">{{ __('إجراءات الحساب') }}</h3>

                {{-- تفعيل / تعطيل --}}
                <form method="POST" action="{{ route('admin.employees.toggle', $employee->id) }}">
                    @csrf
                    @if ($employee->status === 'نشط')
                        <x-button variant="outline" size="md" type="submit" icon="lock" class="w-full">{{ __('تعطيل الحساب') }}</x-button>
                    @else
                        <x-button variant="success" size="md" type="submit" icon="lock-open" class="w-full">{{ __('تفعيل الحساب') }}</x-button>
                    @endif
                </form>

                {{-- إعادة تعيين كلمة المرور --}}
                @php $resetPasswordConfirm = __('سيتم توليد كلمة مرور مؤقتة جديدة لهذا الموظف. متابعة؟'); @endphp
                <form method="POST" action="{{ route('admin.employees.resetPassword', $employee->id) }}"
                      @submit="if(!confirm(@js($resetPasswordConfirm))) $event.preventDefault()">
                    @csrf
                    <x-button variant="light" size="md" type="submit" icon="key-round" class="w-full">{{ __('إعادة تعيين كلمة المرور') }}</x-button>
                </form>
                <p class="text-xs text-gray-400">{{ __('ستظهر كلمة المرور المؤقتة في إشعار بعد التعيين.') }}</p>
            </div>
        </div>
    </div>
</x-layouts::admin>
