{{-- تنبيه من جلسة السيرفر (بعد الحفظ/التعديل) --}}
@if (session('toast'))
    <div x-data x-init="$nextTick(() => $store.toasts.add(@js(session('toast')['msg'] ?? 'تم بنجاح'), @js(session('toast')['type'] ?? 'success')))"></div>
@endif

{{-- حاوية التنبيهات العائمة (Toasts) — تعتمد على $store.toasts --}}
<div class="fixed top-4 left-4 z-[60] flex flex-col gap-2 w-80 max-w-[calc(100vw-2rem)]" x-data>
    <template x-for="toast in $store.toasts.items" :key="toast.id">
        <div
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 -translate-x-4"
            x-transition:enter-end="opacity-100 translate-x-0"
            class="flex items-start gap-3 rounded-xl bg-white shadow-lg border border-gray-100 p-4"
            :class="{
                'border-r-4 !border-r-green-500': toast.type === 'success',
                'border-r-4 !border-r-red-500': toast.type === 'error',
                'border-r-4 !border-r-amber-500': toast.type === 'warning',
                'border-r-4 !border-r-blue-500': toast.type === 'info',
            }"
        >
            <div class="shrink-0 mt-0.5">
                <template x-if="toast.type === 'success'"><i data-lucide="circle-check" class="w-5 h-5 text-green-500"></i></template>
                <template x-if="toast.type === 'error'"><i data-lucide="circle-x" class="w-5 h-5 text-red-500"></i></template>
                <template x-if="toast.type === 'warning'"><i data-lucide="alert-triangle" class="w-5 h-5 text-amber-500"></i></template>
                <template x-if="toast.type === 'info'"><i data-lucide="info" class="w-5 h-5 text-blue-500"></i></template>
            </div>
            <p class="flex-1 text-sm text-gray-700" x-text="toast.message"></p>
            <button type="button" @click="$store.toasts.remove(toast.id)" class="shrink-0 text-gray-300 hover:text-gray-500">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
    </template>
</div>
