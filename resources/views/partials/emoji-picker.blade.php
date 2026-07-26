{{--
    منتقي إيموجي شامل (رموز آيفون) مع بحث — يُستخدم في الإضافات والأقسام.
    يُمرَّر إليه:
      $model : تعبير Alpine الذي يحمل الرمز المختار (مثل 'icon' أو 'sel.icon')
    يجب أن يكون $model معرّفًا في نطاق x-data محيط.
--}}
@php
    $emojiGroups = \App\Support\Emojis::groups();
    $jsGroups = [];
    foreach ($emojiGroups as $label => $items) {
        $jsGroups[__($label)] = array_map(fn ($it) => ['e' => $it[0], 'k' => mb_strtolower($it[1])], $items);
    }
    $model = $model ?? 'icon';
@endphp

@once
    <script>window.EMOJI_GROUPS = @json($jsGroups, JSON_UNESCAPED_UNICODE);</script>
@endonce

<div x-data="{
        q: '',
        groups: window.EMOJI_GROUPS || {},
        filt(items) { const t = this.q.trim().toLowerCase(); return t === '' ? items : items.filter(it => it.k.includes(t)); },
        any() { return Object.values(this.groups).some(items => this.filt(items).length); }
     }">
    <label class="block text-sm font-medium text-gray-700 mb-1.5">{{ __('الأيقونة') }}</label>
    <input type="hidden" name="icon" x-model="{{ $model }}" />

    <div class="flex items-center gap-2 mb-2">
        <span class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center text-2xl leading-none shrink-0" x-text="{{ $model }} || '🎁'"></span>
        <p class="text-xs text-gray-400">{{ __('اختر رمزًا (إيموجي) أو ابحث') }}</p>
    </div>

    {{-- بحث عن إيموجي --}}
    <div class="relative mb-2">
        <span class="absolute inset-y-0 right-3 flex items-center text-gray-400 pointer-events-none">
            <x-icon name="search" class="w-4 h-4" />
        </span>
        <input type="text" x-model="q" placeholder="{{ __('ابحث عن إيموجي...') }}" autocomplete="off"
               class="w-full rounded-xl border border-gray-200 pr-9 pl-8 py-2 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-200 focus:outline-none" />
        <button type="button" x-show="q" @click="q = ''" x-cloak
                class="absolute inset-y-0 left-2 flex items-center text-gray-400 hover:text-gray-600">
            <x-icon name="x" class="w-4 h-4" />
        </button>
    </div>

    <div class="max-h-52 overflow-y-auto border border-gray-200 rounded-xl p-2 space-y-3">
        <template x-for="(items, label) in groups" :key="label">
            <div x-show="filt(items).length">
                <div class="text-[11px] font-medium text-gray-400 mb-1 px-1" x-show="q === ''" x-text="label"></div>
                <div class="grid grid-cols-8 sm:grid-cols-10 gap-1">
                    <template x-for="em in filt(items)" :key="em.e">
                        <button type="button" @click="{{ $model }} = em.e"
                            :class="{{ $model }} === em.e ? 'bg-primary-50 ring-2 ring-primary-300' : 'hover:bg-gray-100'"
                            class="text-xl leading-none aspect-square rounded flex items-center justify-center transition"
                            x-text="em.e"></button>
                    </template>
                </div>
            </div>
        </template>
        <p x-show="!any()" x-cloak class="py-6 text-center text-xs text-gray-400">{{ __('لا نتائج') }}</p>
    </div>
</div>
