import { useState } from 'react';
import { csrfHeaders } from '@/lib/csrf';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { addonsLayoutOf, type AddonsLayout } from '@/lib/addonsLayout';

/*
 * «شريط أفقي» لا «شريط» وحدها: مفتاحُ «شريط» في المعجم شريطُ التغليف
 * (Ribbon) — فيُقرأ في الواجهة الإنجليزيّة اسمَ منتجٍ لا طريقةَ عرض.
 */
const OPTIONS: { value: AddonsLayout; label: string }[] = [
    { value: 'bar', label: 'شريط أفقي' },
    { value: 'section', label: 'قسم كامل' },
];

/**
 * طريقةُ عرض الإضافات في نقطة البيع — إعدادٌ للنشاط كلِّه، لا لهذا المنتج.
 *
 * ═══ ولمَ يُحفظ بطلبٍ مستقلّ ═══
 *
 * يجاور «إضافات مع كلّ المنتجات» لأنّه عنها، لكنّه ليس من نموذج المنتج:
 * لو رُكّب على «حفظ» لَحُفظ منتجٌ نصفُ مكتوب لأجل تبديل عرض، أو ضاع
 * التبديلُ لأنّ صاحبه لم يضغط «حفظ». فيُحفظ بضغطته، ولا يمسّ النموذج.
 *
 * ولصاحب النشاط وحده: لا يُرسم لغيره، والخادمُ يردّ غيرَه بـ403 قبل أيّ
 * كتابة — الإخفاءُ هنا راحةٌ لا حارس.
 */
export default function AddonsLayoutSwitch({ initial, canChange }: { initial: unknown; canChange: boolean }) {
    const t = useTranslate();
    const [layout, setLayout] = useState<AddonsLayout>(addonsLayoutOf(initial));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (!canChange) return null;

    const choose = async (next: AddonsLayout) => {
        if (next === layout || saving) return;

        const before = layout;
        setLayout(next);
        setSaving(true);
        setError(null);

        try {
            const res = await fetch(route('admin.products.addons.display'), {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
                body: JSON.stringify({ layout: next }),
            });

            if (!res.ok) throw new Error('http');

            const body = await res.json();
            setLayout(addonsLayoutOf(body?.layout));
        } catch {
            // وما لم يُحفظ لا يُعرض محفوظًا: يعود الاختيارُ إلى ما كان
            setLayout(before);
            setError(t('تعذّر حفظ طريقة العرض'));
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="mt-3" data-testid="addons-layout-switch">
            <p className="mb-1.5 text-[13px] font-medium text-[#4b4b4b]">{t('طريقة عرض الإضافات في نقطة البيع')}</p>
            <div role="radiogroup" aria-label={t('طريقة عرض الإضافات في نقطة البيع')} className="inline-flex rounded-[10px] border border-[#e8e8e8] p-0.5">
                {OPTIONS.map((o) => (
                    <button
                        key={o.value}
                        type="button"
                        role="radio"
                        aria-checked={layout === o.value}
                        disabled={saving}
                        onClick={() => void choose(o.value)}
                        className={cn(
                            'rounded-[8px] px-3 py-1.5 text-[13px] transition-colors disabled:opacity-60',
                            layout === o.value ? 'bg-[#111] text-white' : 'text-[#4b4b4b] hover:bg-[#f3f3f1]',
                        )}
                    >
                        {t(o.label)}
                    </button>
                ))}
            </div>
            <p className="mt-1 text-[12px] text-[#9ca3af]">{t('يطبّق على نقطة البيع في جميع أجهزة النشاط.')}</p>
            {error && (
                <p role="alert" className="mt-1 text-[12px] text-[#b91c1c]">
                    {error}
                </p>
            )}
        </div>
    );
}
