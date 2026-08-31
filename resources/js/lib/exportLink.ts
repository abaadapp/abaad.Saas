/**
 * يُلحق برابط التصدير ما تنظر إليه الشاشة الآن.
 *
 * زرّ «تصدير» يقف بجانب المُرشِّحات، فمن ضغطه ينتظر ما أمامه. وكان الرابط
 * يخرج عاريًا: يُرشِّح التاجر مصروفات سبتمبر ويصدّر، فيفتح ملفًّا فيه ثلاث
 * سنوات — ولا يعرف أنّه غير ما طلب إلا إن عدّ الصفوف. ويُرشِّح الفواتير
 * الملغاة فلا يجد ملغاةً واحدة.
 *
 * والصفحة ورقمُها لا يُنقلان: الملفّ ليس مرقَّمًا، وحملُ `page=3` إليه يُوهم
 * أنّ له صفحات.
 *
 * وما في الرابط أولى بما فيه: مسارٌ كُتب له `range` صراحةً يعرف ما يريد.
 */
export function withFilters(url: string): string {
    if (typeof window === 'undefined') return url;

    const here = new URLSearchParams(window.location.search);
    here.delete('page');
    here.delete('per_page');
    if (![...here].length) return url;

    const [base, own = ''] = url.split('?');
    const merged = new URLSearchParams(own);
    here.forEach((value, key) => {
        if (!merged.has(key)) merged.append(key, value);
    });

    return `${base}?${merged.toString()}`;
}
