import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface TabItem {
    key: string;
    label: string;
    /**
     * نقطة تنبيه على التبويب — للأخطاء التي تقع في جزء غير معروض.
     *
     * بدونها يقع خطأ التحقّق في تبويب مطويّ فلا يراه المستخدم، ويبدو الحفظ
     * كأنه لم يستجب.
     */
    alert?: boolean;
    /**
     * أيقونة قبل النصّ — اختيارية.
     *
     * لمبدّلات العرض (شبكي/جدول) حيث الشكل نفسه هو المعنى. وبدونها كان
     * المبدّل يُرسم بيده خارج المكوّن نسخةً من صفوفه، فتتبدّل التبويبات هنا
     * ويبقى هو على ما كان.
     */
    icon?: LucideIcon;
    /**
     * عددٌ خافت بجانب النصّ — حين يفيد عددُ ما في التبويب قبل فتحه.
     *
     * الصفر لا يُعرض: «العناوين ٠» سطرٌ يشغل مكانًا ليقول لا شيء.
     */
    count?: number;
    /**
     * نقطةٌ ملوّنة قبل النصّ — لونها كما يُمرَّر (‏`statusDot` مصدرها).
     *
     * لتبويبات الحالة: اللون يُقرأ قبل النصّ، فيُعرف موضع «المتأخّر» من
     * «المدفوع» بنظرةٍ لا بقراءة. وهي تُمرَّر ولا تُشتقّ هنا: هذا مكوّنٌ عامٌّ
     * لا يعرف الحالات، ومَن يعرفها يُمرّر لونها.
     */
    dot?: string;
}

interface Props {
    tabs: TabItem[];
    current: string;
    onChange: (key: string) => void;
    className?: string;
    /**
     * عنصرٌ في آخر الشريط — عدّاد أو ما شابه، يُدفع إلى الحافة.
     *
     * بدونه كان مبدّل العرض في المنتجات يُرسم بيده ليضع عدد المنتجات في
     * طرفه، فبقي خارج المكوّن.
     */
    trailing?: ReactNode;
}

/**
 * تبويبات داخل الصفحة — بديل x-data="{ tab: … }" في القوالب.
 *
 * تُميَّز عن SectionTabs: تلك تنقل بين مسارات، وهذه تبدّل جزءًا من الصفحة
 * نفسها. والشكل واحد عمدًا فلا يشعر المستخدم بفارق بين النوعين — انظر
 * SectionTabs لِمَ صار شكلًا واحدًا بلا خيار.
 *
 * أزرار داخل role="tablist" لا روابط: لا وجهة تُفتح في تبويب جديد.
 */
export default function Tabs({ tabs, current, onChange, className, trailing }: Props) {
    const t = useTranslate();

    return (
        <div
            role="tablist"
            className={cn(
                // مُلاصقٌ للحافة كـSectionTabs. كان الحشو `px-4` افتراضًا فتجاوزته
                // ثلاثٌ من خمس صفحات بـ`px-0` — والافتراض الذي يُتجاوَز أكثر ممّا
                // يُقبل ليس افتراضًا. ومن يضعه داخل بطاقة يطلب `px-4` صراحةً.
                'flex items-center gap-1 overflow-x-auto border-b border-[var(--ui-border,#e8e8e8)]',
                className,
            )}
        >
            {tabs.map((tab) => {
                const active = tab.key === current;
                const Icon = tab.icon;

                return (
                    <button
                        key={tab.key}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        onClick={() => onChange(tab.key)}
                        className={cn(
                            'inline-flex items-center justify-center gap-2 whitespace-nowrap text-sm font-medium transition-colors',
                            // ‏-mb-px يرفع حدّ التبويب فوق حدّ الشريط فيحلّ محلّه،
                            // ولولاه لظهر خطّان متجاوران تحت النشط
                            '-mb-px border-b-2 px-4 py-3',
                            // النشط يتبدّل لونًا لا حجمًا — فلا يقفز ما تحته
                            active
                                ? 'border-[#111] text-[#111]'
                                : 'border-transparent text-[#6b7280] hover:text-[#374151]',
                        )}
                    >
                        {Icon && <Icon className="size-4 shrink-0" />}
                        {tab.dot && (
                            <span
                                aria-hidden
                                className="size-1.5 shrink-0 rounded-full"
                                style={{ backgroundColor: tab.dot }}
                            />
                        )}
                        {t(tab.label)}
                        {!! tab.count && (
                            <span className="text-[12px] text-[#9ca3af]">{number(tab.count)}</span>
                        )}
                        {tab.alert && (
                            <span
                                aria-label={t('يحتاج تصحيحًا')}
                                className="size-1.5 shrink-0 rounded-full bg-[#dc2626]"
                            />
                        )}
                    </button>
                );
            })}

            {trailing && <div className="ms-auto ps-4">{trailing}</div>}
        </div>
    );
}
