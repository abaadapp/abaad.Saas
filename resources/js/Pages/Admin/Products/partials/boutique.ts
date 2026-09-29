/** بوتيكٌ خيارًا في قائمة — كما يرسله `Boutiques::options` */
export interface BoutiqueOption {
    value: number;
    label: string;
    active: boolean;
}

/**
 * أينتقل الصنفُ من بوتيكٍ إلى آخر؟ — فيُسأل صاحبُه قبل الحفظ.
 *
 * والسؤالُ للنقل وحده: ربطُ صنفٍ لم يكن لأحد، أو فكُّه إلى «بدون بوتيك»،
 * لا يأخذ من أحدٍ شيئًا. أمّا النقلُ فيجعل ما يُباع غدًا لغير صاحبه اليوم
 * — وما بِيع أمس يبقى على لقطته لا يتبعه.
 *
 * @returns اسما البوتيكين — أو `null` إن لم يكن نقلًا
 */
export function boutiqueMove(
    from: string,
    to: string,
    options: BoutiqueOption[],
): { from: string; to: string } | null {
    if (from === '' || to === '' || from === to) return null;

    const name = (id: string) => options.find((o) => String(o.value) === id)?.label ?? id;

    return { from: name(from), to: name(to) };
}
