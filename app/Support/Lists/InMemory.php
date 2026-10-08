<?php

namespace App\Support\Lists;

use Collator;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * قائمةٌ تُبنى صفوفُها في PHP (مخزونٌ بكمّيّات الفروع، مورّدون بعدّاداتهم)
 * وتُرشَّح وتُرتَّب وتُرقَّم على الخادم.
 *
 * كانت هذه الشاشات ترسل الصفوف كلَّها ويرشّحها المتصفّح: البحثُ وعلاماتُ
 * التبويب حالةٌ في الصفحة لا في الرابط، فلا يبلغها زرُّ التصدير — من بحث
 * عن «ورد» ثمّ صدّر خرج بالمخزن كلّه. فصار الترشيحُ هنا، مرّةً واحدة،
 * والشاشةُ ترقّم ما رُشّح والتصديرُ يقرؤه كلَّه.
 */
final class InMemory
{
    /**
     * بحثٌ نصّيٌّ كما كان يبحث الجدول: الكلمةُ داخل الحقول، بلا اعتبارٍ لحالة الأحرف.
     *
     * @param  callable(array): string  $haystack
     */
    public static function search(array $rows, ?string $term, callable $haystack): array
    {
        $needle = mb_strtolower(trim((string) $term));

        if ($needle === '') {
            return $rows;
        }

        return array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower($haystack($r)), $needle)));
    }

    /**
     * ترتيبُ الرابط (`sort` و`dir`) إن كان مسموحًا — الأرقامُ بقيمتها والنصوصُ بالعربيّة.
     *
     * @param  array<string, callable(array): mixed>  $keys
     */
    public static function sort(array $rows, Request $request, array $keys): array
    {
        $key = (string) $request->query('sort', '');

        if (! isset($keys[$key])) {
            return $rows;
        }

        $value = $keys[$key];
        $dir = $request->query('dir') === 'asc' ? 1 : -1;
        $collator = class_exists(Collator::class) ? new Collator('ar') : null;

        usort($rows, function ($a, $b) use ($value, $dir, $collator) {
            $va = $value($a);
            $vb = $value($b);
            $cmp = is_numeric($va) && is_numeric($vb)
                ? $va <=> $vb
                : ($collator ? $collator->compare((string) $va, (string) $vb) : strcmp((string) $va, (string) $vb));

            return $cmp * $dir;
        });

        return $rows;
    }

    /** صفحةُ الجدول من الصفوف المرشَّحة — بشكل `paginate()` نفسه */
    public static function page(array $rows, Request $request, int $perPage): LengthAwarePaginator
    {
        $page = max(1, (int) $request->query('page', 1));

        return (new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * $perPage, $perPage),
            count($rows),
            $perPage,
            $page,
            ['path' => $request->url(), 'pageName' => 'page'],
        ))->withQueryString();
    }
}
