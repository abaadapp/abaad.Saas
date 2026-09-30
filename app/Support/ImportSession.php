<?php

namespace App\Support;

/**
 * ملفُّ استيرادٍ معلَّقٌ في الجلسة — مختومٌ بمتجره ومن رفعه.
 *
 * ═══ ما كان يقع ═══
 *
 * الاستيرادُ يُرفع ثمّ يُعايَن ثمّ يُؤكَّد، والملفُّ بينها في الجلسة — ومعه
 * رقمُ الفرع الذي يُكتب عليه المخزون. والجلسةُ لا تعرف متجرًا: مديرُ المنصّة
 * رفع ملفًّا وهو في متجر A، ثمّ خرج ودخل B، فأكّده — فكُتبت منتجاتُ B
 * ومخزونُها على فرعٍ يملكه A، ورأى B في المعاينة اسمَ فرعِ A.
 *
 * `TenantSwitch` يفرّغ الجلسةَ عند التبدّل. وهذا حارسٌ ثانٍ لا يعتمد عليه:
 * ملفٌّ خُتم لمتجرٍ لا يُقرأ لغيره — يُمحى ويُعامَل كأن لا ملفّ.
 */
final class ImportSession
{
    /** @param  array<string, mixed>  $payload */
    public static function put(string $key, array $payload): void
    {
        session()->put($key, ['business_id' => Demo::bid(), 'user_id' => auth()->id()] + $payload);
    }

    /** @return array<string, mixed>|null ملفُّ هذا المتجر ومن رفعه — أو لا شيء */
    public static function get(string $key): ?array
    {
        $payload = session($key);

        if (! is_array($payload)) {
            return null;
        }

        $mine = (int) ($payload['business_id'] ?? 0) === Demo::bid()
            && (int) ($payload['user_id'] ?? 0) === (int) auth()->id();

        if (! $mine) {
            session()->forget($key);

            return null;
        }

        return $payload;
    }
}
