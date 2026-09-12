import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';

/**
 * بابٌ قبل الشاشة — حين لا يعمل شيءٌ ممّا وراءه بعد.
 *
 * ═══ لماذا ═══
 *
 * الشاشةُ التي تُفتح على كلّ مقابضها قبل أن يكتمل شرطُها تكذب: حقولٌ تُملأ
 * ولا تُقرأ، ومفاتيحُ تُرفع ولا تُرسل حرفًا، وقائمةٌ فارغة تُقرأ عطبًا.
 * فيقرأ التاجر عشرين سطرًا ليعرف أنّ لا شيء منها يعمل، ثمّ يبحث عن الخطوة
 * الأولى بين البقيّة.
 *
 * فالباب بابٌ: علامةٌ واسمٌ وسطرٌ يقول ما وراءه، **وفعلٌ واحد**. وما وراءه
 * يُعرض بعده.
 *
 * وأربعُ شاشاتٍ كانت تكتبه بيدها حرفًا حرفًا — بابُ الأداة، و«إشعارات
 * واتساب»، و«الظهور في البحث»، و«المتجر» — فاختلفت المقاسات والمسافات في
 * شيءٍ واحد. وهذا بيتُه.
 */
export default function Gate({
    mark,
    icon: Icon,
    title,
    description,
    action,
    note,
}: {
    /**
     * علامةُ الأداة كما ترسمها `BrandMarks` — شعارٌ حقيقيّ يُعرف قبل أن
     * يُقرأ اسمُه. وتُقدَّم على `icon`: أداةٌ لها شعارٌ لا تُرسم بأيقونةٍ عامّة.
     */
    mark?: ReactNode;
    /** وما ليس أداةً — «موقعك مُطفأ»، «لا منتجات» — أيقونةٌ في إطارٍ محايد */
    icon?: LucideIcon;
    title: string;
    description?: string;
    /** الفعلُ الواحد: زرٌّ يربط أو رابطٌ يقود إلى حيث يُكمَل الشرط */
    action?: ReactNode;
    /** سطرٌ تحت الفعل — ما ينتظره التاجر ممّن سواه */
    note?: ReactNode;
}) {
    const t = useTranslate();

    return (
        <Card className="mx-auto flex max-w-xl flex-col items-center px-6 py-16 text-center">
            {mark ?? (Icon && (
                <span className="flex size-20 items-center justify-center rounded-[24px] bg-[#f3f4f6] text-[#6b7280]">
                    <Icon className="size-9" />
                </span>
            ))}

            <h2 className="mt-6 text-[20px] font-bold text-[#111]">{t(title)}</h2>
            {description && (
                <p className="mt-2 max-w-sm text-[14px] leading-relaxed text-[#6b7280]">{t(description)}</p>
            )}

            {action && <div className="mt-8">{action}</div>}
            {note && <p className="mt-4 max-w-sm text-[12px] text-[#b45309]">{note}</p>}
        </Card>
    );
}
