@props([
    'label' => null,
    'name' => null,
    'options' => [],       // ['value' => 'label', ...] أو قائمة بسيطة
    'selected' => null,
    'placeholder' => null,
    'required' => false,
    'submit' => false,     // إرسال النموذج تلقائيًا عند الاختيار
    'onSelect' => null,    // تعبير JS يُنفَّذ عند الاختيار (المتغيّر value متاح)
    'icon' => null,        // أيقونة اختيارية يمين الزر
])

@php
    $isList = is_array($options) && array_is_list($options);
    // قائمة مرتّبة [{v,l}] لضمان بقاء الترتيب مهما كان نوع المفاتيح
    $opts = [];
    foreach ($options as $k => $v) {
        $opts[] = ['v' => (string) ($isList ? $v : $k), 'l' => (string) $v];
    }
    $selVal = $selected !== null ? (string) $selected : '';
    $selLabel = $placeholder;
    foreach ($opts as $o) {
        if ($o['v'] === $selVal) { $selLabel = $o['l']; break; }
    }
    if ($selLabel === null && count($opts)) { $selLabel = $opts[0]['l']; $selVal = $opts[0]['v']; }
    $onSel = trim((string) $onSelect);
    $tail = ($onSel !== '' ? ' ' . rtrim($onSel, ';') . ';' : '')
        . ($submit ? ' $nextTick(() => $refs.field.form?.requestSubmit?.());' : '');
@endphp

<div
    x-data="{
        open: false,
        invalid: false,
        value: @js($selVal),
        label: @js($selLabel),
        options: @js($opts),
        init() {
            const form = this.$refs.field?.form;
            if (form && @js((bool) $required)) {
                form.addEventListener('submit', (e) => {
                    if (!String(this.value).length) {
                        e.preventDefault();
                        this.invalid = true;
                        this.$el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                });
            }
        },
    }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    class="relative"
>
    @if ($label)
        <label class="block text-sm font-medium text-gray-700 mb-1.5">{{ $label }}</label>
    @endif

    <input type="hidden" @if ($name) name="{{ $name }}" @endif :value="value" x-ref="field" />

    <button type="button" @click="open = !open"
        {{ $attributes->merge(['class' => 'w-full flex items-center justify-between gap-2 rounded-xl border bg-white px-3 py-2.5 text-sm text-gray-800 hover:border-gray-400 focus:ring-2 focus:ring-primary-200 focus:outline-none transition']) }}
        :class="invalid ? 'border-danger-400 ring-2 ring-danger-100' : (open ? 'border-primary-500 ring-2 ring-primary-200' : 'border-gray-300')"
    >
        <span class="flex items-center gap-2 min-w-0">
            @if ($icon)<x-icon :name="$icon" class="w-4 h-4 text-gray-400 shrink-0" />@endif
            <span class="truncate" :class="!value && '{{ $placeholder ? 'text-gray-400' : '' }}'" x-text="label"></span>
        </span>
        <span class="text-gray-400 transition-transform shrink-0" :class="open && 'rotate-180'">
            <x-icon name="chevron-down" class="w-4 h-4" />
        </span>
    </button>

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 -translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-100"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 -translate-y-1"
         class="absolute z-30 mt-1.5 w-full rounded-xl border border-gray-100 bg-white shadow-lg ring-1 ring-black/5 py-1.5 max-h-64 overflow-auto">
        @if ($placeholder)
            <button type="button"
                @click="value = ''; label = @js($placeholder); open = false; invalid = false;{{ $tail }}"
                class="w-full text-right px-3 py-2 text-sm text-gray-500 hover:bg-gray-50 transition-colors"
                :class="!value && 'bg-primary-50/60 text-primary-600 font-semibold'">
                {{ $placeholder }}
            </button>
        @endif
        <template x-for="o in options" :key="o.v">
            <button type="button"
                @click="value = o.v; label = o.l; open = false; invalid = false;{{ $tail }}"
                class="w-full text-right px-3 py-2 text-sm flex items-center justify-between gap-2 transition-colors"
                :class="String(o.v) === String(value) ? 'bg-primary-50/60 text-primary-600 font-semibold' : 'text-gray-700 hover:bg-gray-50'">
                <span class="truncate" x-text="o.l"></span>
                <span x-show="String(o.v) === String(value)" class="text-primary-500 shrink-0">
                    <x-icon name="check" class="w-4 h-4" />
                </span>
            </button>
        </template>
    </div>
</div>
