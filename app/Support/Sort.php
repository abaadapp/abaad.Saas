<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * ترتيب القوائم المُرقَّمة على الخادم.
 *
 * القائمة المُرقَّمة لا تُرتَّب في المتصفّح: الصفحة تحمل خمسةً وعشرين صفًّا من
 * أربعمئة، وترتيبُها يرتّب الخمسة والعشرين وحدها فيبقى الأغلى ثمنًا في صفحةٍ
 * لم تُفتح. فالترتيب حيث البيانات كلّها.
 *
 * وعمودٌ مسموحٌ لا أيّ عمود: `sort` يأتي من الرابط، ووضعُه في `orderBy` كما
 * جاء يجعل الرابط يسمّي أعمدة القاعدة — يُقرأ منها ما لم يُقصد أن يُقرأ،
 * ويُكشف وجودُ العمود من عدمه بفرق الاستجابة.
 *
 * والمفاتيح المسموحة تُرسَل إلى الواجهة (`keys`) فتُبنى منها قائمةُ الترتيب:
 * مصدرٌ واحد يقرّر، فلا يعرض الزرُّ عمودًا لا يرتّبه الخادم. وهذا عين ما
 * كُسر قبلُ: رأسُ العمود كان يُضغط ويقلب سهمه ولا يتحرّك صفّ.
 */
class Sort
{
    /**
     * يطبّق ترتيب الرابط إن كان مسموحًا، وإلا فالترتيب الافتراضي.
     *
     * @param  array<string, string|string[]>  $allowed  مفتاح العمود في الواجهة → عمود القاعدة (أو أعمدة)
     * @param  callable(Builder): void  $default  ترتيب القائمة حين لا يُطلب ترتيب
     */
    public static function apply(Builder $query, Request $request, array $allowed, callable $default): void
    {
        $key = (string) $request->query('sort', '');

        if ($key === '' || ! isset($allowed[$key])) {
            $default($query);

            return;
        }

        // ما لم يكن `asc` صراحةً فهو تنازليّ: أوّل ما يُسأل عنه في القوائم
        // هو الأكبر والأحدث، لا الأصغر والأقدم
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        foreach ((array) $allowed[$key] as $column) {
            $query->orderBy($column, $dir);
        }
    }

    /**
     * المفاتيح كما تقرؤها الواجهة.
     *
     * @param  array<string, string|string[]>  $allowed
     * @return string[]
     */
    public static function keys(array $allowed): array
    {
        return array_keys($allowed);
    }

    /** معاملا الترتيب ليُعادا إلى الواجهة ضمن `filters` */
    public static function params(Request $request, array $allowed): array
    {
        $key = (string) $request->query('sort', '');

        return [
            'sort' => isset($allowed[$key]) ? $key : null,
            'dir' => $request->query('dir') === 'asc' ? 'asc' : 'desc',
        ];
    }
}
