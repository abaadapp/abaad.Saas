<x-layouts::auth :title="__('دخول الموظف')">
    <div class="w-full max-w-sm">
        {{-- الشعار --}}
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-primary-600 text-white shadow-lg shadow-primary-600/30 mb-4">
                <x-icon name="scan-barcode" class="w-9 h-9" />
            </div>
            <h1 class="text-2xl font-bold text-gray-800">{{ __('دخول الموظف') }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ __('أدخل رمز الدخول المكوّن من ٤ أرقام') }}</p>
        </div>

        <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-6 sm:p-8"
             x-data="pinPad('{{ route('pin.attempt') }}', @js(csrf_token()))">

            @if ($errors->any())
                <div class="mb-5 flex items-start gap-2 rounded-xl bg-danger-50 border border-danger-500/30 p-3 text-sm text-danger-600">
                    <x-icon name="circle-x" class="w-5 h-5 shrink-0 mt-0.5" />
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            {{-- نقاط عرض الرمز --}}
            <div class="flex items-center justify-center gap-4 mb-8" :class="shake && 'animate-[shake_0.4s]'">
                <template x-for="i in 4" :key="i">
                    <span class="w-4 h-4 rounded-full border-2 transition-colors"
                          :class="pin.length >= i ? 'bg-primary-600 border-primary-600' : 'border-gray-300'"></span>
                </template>
            </div>

            {{-- لوحة الأرقام --}}
            <div class="grid grid-cols-3 gap-3">
                <template x-for="n in [1,2,3,4,5,6,7,8,9]" :key="n">
                    <button type="button" @click="push(n)"
                            class="h-16 rounded-2xl bg-gray-50 hover:bg-primary-50 active:scale-95 text-2xl font-bold text-gray-800 transition"
                            x-text="n"></button>
                </template>
                <button type="button" @click="clearAll()"
                        class="h-16 rounded-2xl bg-gray-50 hover:bg-gray-100 active:scale-95 text-sm font-medium text-gray-500 transition">
                    {{ __('مسح') }}
                </button>
                <button type="button" @click="push(0)"
                        class="h-16 rounded-2xl bg-gray-50 hover:bg-primary-50 active:scale-95 text-2xl font-bold text-gray-800 transition">0</button>
                <button type="button" @click="backspace()"
                        class="h-16 rounded-2xl bg-gray-50 hover:bg-gray-100 active:scale-95 flex items-center justify-center text-gray-500 transition">
                    <x-icon name="delete" class="w-6 h-6" />
                </button>
            </div>

            {{-- نموذج مخفي للإرسال --}}
            <form x-ref="form" method="POST" action="{{ route('pin.attempt') }}" class="hidden">
                @csrf
                <input type="hidden" name="pin" x-model="pin" />
            </form>

            <div x-show="loading" x-cloak class="mt-6 flex items-center justify-center gap-2 text-sm text-gray-500">
                <x-icon name="loader-circle" class="w-4 h-4 animate-spin" /> {{ __('جارٍ الدخول…') }}
            </div>
        </div>

        {{-- الرجوع للدخول بالبريد --}}
        <div class="text-center mt-6">
            <a href="{{ route('login') }}" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-primary-600 transition">
                <x-icon name="mail" class="w-4 h-4" /> {{ __('الدخول بالبريد وكلمة المرور') }}
            </a>
        </div>
    </div>

    @push('scripts')
        <style>@keyframes shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-8px)}75%{transform:translateX(8px)}}</style>
        <script>
            function pinPad(action, token) {
                return {
                    pin: '',
                    loading: false,
                    shake: false,
                    push(n) {
                        if (this.loading || this.pin.length >= 4) return;
                        this.pin += String(n);
                        if (this.pin.length === 4) this.submit();
                    },
                    backspace() { if (!this.loading) this.pin = this.pin.slice(0, -1); },
                    clearAll() { if (!this.loading) this.pin = ''; },
                    submit() {
                        this.loading = true;
                        this.$nextTick(() => this.$refs.form.submit());
                    },
                    init() {
                        // دعم لوحة المفاتيح الفعلية
                        window.addEventListener('keydown', (e) => {
                            if (e.key >= '0' && e.key <= '9') this.push(Number(e.key));
                            else if (e.key === 'Backspace') this.backspace();
                        });
                    },
                }
            }
        </script>
    @endpush
</x-layouts::auth>
