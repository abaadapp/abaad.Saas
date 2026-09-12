import type { ReactNode } from 'react';

import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type MetaCell = {
    label: string;
    value: ReactNode;
    /** قيمةٌ تُقرأ من اليسار — تاريخٌ أو رقمٌ أو مرجعٌ لاتينيّ */
    ltr?: boolean;
    /** خليّةٌ تأخذ السطر كلَّه — ملاحظةٌ أو عنوان */
    wide?: boolean;
};

/**
 * شريطُ تعريف المستند — ما يُعرَف به في نظرة.
 *
 * ═══ ولمَ أعمدةٌ لا قائمةُ عنوانٍ وقيمة ═══
 *
 * كانت شاشاتُ المستندات تصفّ حقولَها سطرًا سطرًا: عنوانٌ في طرف السطر وقيمةٌ
 * في الطرف الآخر، وبينهما فراغٌ بعرض راحة اليد. فثمانيةُ حقولٍ قصيرة —
 * تاريخٌ ومورّدٌ وفرعٌ ومرجع، لا سطرَ فيها يتجاوز عشرين حرفًا — تشغل ثمانيةَ
 * أسطرٍ ونصفُ كلٍّ منها خالٍ، وتعجز العينُ أن تلتقطها دفعةً.
 *
 * فهي أعمدةٌ متجاورة، عنوانٌ صغيرٌ فوق قيمته — كما يفعل شريطُ التعريف على
 * الورقة نفسِها (`documents/v1/partials/meta`). والشاشةُ والورقةُ تقولان
 * الشيءَ بالشكل نفسِه، فلا يُعاد تعلّمُ القراءة بينهما.
 *
 * ولا خليّةَ خاوية: حقلٌ بلا قيمةٍ يُحذف من القائمة عند بنائها، وخانةٌ فارغة
 * تحت «مرجع المورّد» تُقرأ حقلًا نُسي.
 */
export default function DocumentMeta({
    cells,
    className,
}: {
    cells: (MetaCell | false | null | undefined)[];
    className?: string;
}) {
    const t = useTranslate();
    const shown = cells.filter((c): c is MetaCell => Boolean(c));

    if (shown.length === 0) {
        return null;
    }

    return (
        <Card asChild className={cn('p-4 sm:p-5', className)}>
            <dl className="grid grid-cols-2 gap-x-4 gap-y-4 sm:grid-cols-3 lg:grid-cols-4">
                {shown.map((cell, i) => (
                    <div key={i} className={cn('min-w-0', cell.wide && 'col-span-2 sm:col-span-3 lg:col-span-4')}>
                        <dt className="text-[11px] tracking-[0.06em] text-[#9ca3af]">{t(cell.label)}</dt>
                        <dd
                            dir={cell.ltr ? 'ltr' : undefined}
                            className={cn(
                                'mt-1 text-[13px] leading-relaxed text-[#111]',
                                /* واتّجاهُ القيمة يُصحّح ترتيبَ حروفها، ومحاذاتُها تتبع الورقة */
                                cell.ltr && 'text-start',
                                cell.wide ? 'whitespace-pre-line' : 'truncate',
                            )}
                        >
                            {cell.value}
                        </dd>
                    </div>
                ))}
            </dl>
        </Card>
    );
}
