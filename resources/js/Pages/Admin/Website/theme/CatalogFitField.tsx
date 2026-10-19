import Field from '@/Components/Field';
import { fitOf } from '@/Components/StoreImageField';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ListProduct } from './ProductListField';

/**
 * طريقةُ عرض صور المنتجات والفئات — في بطاقات المتجر وصفحة المنتج والسلّة.
 *
 * «الصورة كاملة» تُري صورةَ المنتج كلَّها بنسبتها في إطار البطاقة نفسِه — طوليّةً
 * كانت أو عرضيّة — على لون الواجهة الهادئ. و«ملء الإطار» تملأ البطاقةَ وتقصّ ما
 * زاد من الطرفين، وهي ما كانت عليه المتاجرُ كلُّها: فالفراغُ هو هي، ولا يتبدّل
 * متجرٌ قائمٌ حتّى يختار صاحبُه.
 *
 * والمعاينةُ صورُ منتجاته هو في إطار البطاقة المربّع — لا صورةٌ مثالٌ تُخفي ما
 * سيُقصّ من صوره.
 */
export default function CatalogFitField({
    label,
    hint,
    value,
    products,
    onChange,
    error,
}: {
    label: string;
    hint?: string;
    value: string;
    products: ListProduct[];
    onChange: (value: string) => void;
    error?: string;
}) {
    const t = useTranslate();
    const shown = fitOf(value);
    const samples = products.filter((p) => p.image).slice(0, 3);

    return (
        <Field label={label} hint={hint} error={error}>
            <div role="radiogroup" aria-label={t(label)} className="grid gap-3 sm:grid-cols-2" data-testid="catalogfit">
                {([
                    ['contain', 'الصورة كاملة', 'بلا قصٍّ ولا مطّ — بنسبتها في البطاقة'],
                    ['cover', 'ملء الإطار', 'تملأ البطاقة وتُقصّ أطرافُها'],
                ] as const).map(([key, title, sub]) => (
                    <button
                        key={key}
                        type="button"
                        role="radio"
                        aria-checked={shown === key}
                        data-testid={`catalogfit-${key}`}
                        onClick={() => onChange(key)}
                        className={cn(
                            'flex flex-col gap-2 rounded-[12px] border p-3 text-start transition-colors',
                            shown === key ? 'border-[#111] bg-white ring-1 ring-[#111]' : 'border-[var(--ui-border,#e8e8e8)] bg-white hover:bg-[#f9fafb]',
                        )}
                    >
                        <span className="text-[13px] font-medium text-[#111]">{t(title)}</span>
                        <span className="text-[11px] text-[#6b7280]">{t(sub)}</span>
                        {samples.length > 0 && (
                            <span className="flex gap-1.5" aria-hidden="true">
                                {samples.map((p) => (
                                    <span key={p.id} className="flex aspect-square flex-1 items-center justify-center overflow-hidden rounded-[8px] bg-[#efe8de]">
                                        <img src={p.image ?? ''} alt="" className="block size-full" style={{ objectFit: key }} data-fit={key} />
                                    </span>
                                ))}
                            </span>
                        )}
                    </button>
                ))}
            </div>
        </Field>
    );
}
