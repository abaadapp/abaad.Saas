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
}

interface Props {
    tabs: TabItem[];
    current: string;
    onChange: (key: string) => void;
    className?: string;
    /**
     * `underline` خطٌّ تحت التبويب النشط — الافتراضي، ويطابق SectionTabs.
     * `segmented` شريط مقسَّم: حاوية رمادية والنشط بطاقة بيضاء بعرض متساوٍ.
     *
     * الأخير أوضح حين تكون التبويبات خطوات في نموذج واحد لا وجهات منفصلة.
     */
    variant?: 'underline' | 'segmented';
}

/**
 * تبويبات داخل الصفحة — بديل x-data="{ tab: … }" في القوالب.
 *
 * تُميَّز عن SectionTabs: تلك تنقل بين مسارات، وهذه تبدّل جزءًا من الصفحة
 * نفسها. المظهر واحد عمدًا فلا يشعر المستخدم بفارق بين النوعين.
 *
 * أزرار داخل role="tablist" لا روابط: لا وجهة تُفتح في تبويب جديد.
 */
export default function Tabs({ tabs, current, onChange, className, variant = 'underline' }: Props) {
    const t = useTranslate();
    const segmented = variant === 'segmented';

    return (
        <div
            role="tablist"
            className={cn(
                segmented
                    ? // التبويبات لا تنكمش دون نصّها، فتنزلق أفقيًا على الشاشات
                      // الضيّقة بدل أن تتكدّس أحرفًا مقصوصة
                      'flex w-full items-center gap-1 overflow-x-auto rounded-[14px] bg-[#f4f4f2] p-1.5'
                    : 'flex items-center gap-1 overflow-x-auto border-b border-[var(--ui-border,#e8e8e8)] px-4',
                className,
            )}
        >
            {tabs.map((tab) => {
                const active = tab.key === current;

                return (
                    <button
                        key={tab.key}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        onClick={() => onChange(tab.key)}
                        className={cn(
                            'inline-flex items-center justify-center gap-2 whitespace-nowrap text-sm font-medium transition-colors',
                            // النشط يتبدّل لونًا وظلًّا فقط — لا حدًّا ولا حجمًا،
                            // فلا يقفز الشريط ولا ما تحته عند التنقّل
                            segmented
                                ? cn(
                                      'flex-1 rounded-[10px] px-4 py-2.5',
                                      active
                                          ? 'bg-white text-[#111] shadow-[0_1px_3px_rgba(0,0,0,0.08)]'
                                          : 'text-[#6b7280] hover:text-[#374151]',
                                  )
                                : cn(
                                      '-mb-px border-b-2 px-4 py-3',
                                      active
                                          ? 'border-[#111] text-[#111]'
                                          : 'border-transparent text-[#6b7280] hover:text-[#374151]',
                                  ),
                        )}
                    >
                        {t(tab.label)}
                        {tab.alert && (
                            <span
                                aria-label={t('يحتاج تصحيحًا')}
                                className="size-1.5 shrink-0 rounded-full bg-[#dc2626]"
                            />
                        )}
                    </button>
                );
            })}
        </div>
    );
}
