<x-layouts::admin :title="__('النسخ الاحتياطي')">
    <x-page-header
        :title="__('النسخ الاحتياطي والاستعادة')"
        :subtitle="__('نزّل نسخة كاملة من بيانات متجرك أو استعِدها عند الحاجة')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الإعدادات') => route('admin.settings.index'), __('النسخ الاحتياطي') => '#']"
    />

    <div class="max-w-2xl space-y-6">
        {{-- تنزيل نسخة احتياطية --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <div class="flex items-center gap-2 mb-2">
                <span class="w-9 h-9 rounded-xl bg-primary-50 text-primary-600 flex items-center justify-center"><x-icon name="download" class="w-5 h-5" /></span>
                <h3 class="text-lg font-bold text-gray-800">{{ __('تنزيل نسخة احتياطية') }}</h3>
            </div>
            <p class="text-sm text-gray-500 mb-5">{{ __('يشمل الملف كامل بيانات متجرك: المنتجات، التصنيفات، العملاء، الطلبات، المصروفات، المعاملات، حركات المخزون، والإعدادات — بصيغة JSON.') }}</p>
            <x-button variant="primary" size="md" icon="database-backup" :href="route('admin.backup.download')">{{ __('تنزيل النسخة الآن') }}</x-button>
        </div>

        {{-- استعادة من نسخة --}}
        <div class="bg-white rounded-2xl border border-danger-100 shadow-sm p-6">
            <div class="flex items-center gap-2 mb-2">
                <span class="w-9 h-9 rounded-xl bg-danger-50 text-danger-600 flex items-center justify-center"><x-icon name="database" class="w-5 h-5" /></span>
                <h3 class="text-lg font-bold text-gray-800">{{ __('استعادة من نسخة احتياطية') }}</h3>
            </div>
            <div class="flex items-start gap-2 bg-danger-50 text-danger-700 text-sm rounded-xl p-3 mb-5">
                <x-icon name="alert-triangle" class="w-5 h-5 shrink-0 mt-0.5" />
                <span>{{ __('تحذير: ستحل بيانات النسخة محل بيانات متجرك الحالية بالكامل. لا يمكن التراجع — نوصي بتنزيل نسخة حديثة أولًا.') }}</span>
            </div>
            <form method="POST" action="{{ route('admin.backup.restore') }}" enctype="multipart/form-data"
                  x-data="{ fileName: '' }" @submit="if(!fileName){ $event.preventDefault(); $store.toasts.add(@js(__('اختر ملف النسخة أولًا')),'warning'); }">
                @csrf
                <label class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-gray-200 rounded-2xl p-6 text-center cursor-pointer hover:border-danger-300 transition">
                    <x-icon name="upload-cloud" class="w-8 h-8 text-gray-400" />
                    <span class="text-sm font-medium text-gray-700" x-text="fileName || @js(__('اختر ملف النسخة الاحتياطية (JSON)'))"></span>
                    <input type="file" name="backup" accept=".json,application/json" class="hidden"
                           @change="fileName = $event.target.files[0]?.name || ''" />
                </label>
                @error('backup')<p class="mt-2 text-xs text-danger-500">{{ $message }}</p>@enderror
                <div class="mt-5 flex justify-end">
                    <x-button variant="danger" size="md" icon="database" type="submit">{{ __('استعادة البيانات') }}</x-button>
                </div>
            </form>
        </div>
    </div>
</x-layouts::admin>
