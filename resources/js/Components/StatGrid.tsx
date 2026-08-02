import { useEffect, useState } from 'react';
import { Check, Plus, SlidersHorizontal, X } from 'lucide-react';
import StatCard, { type Stat } from '@/Components/StatCard';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';

/**
 * شبكة المؤشرات القابلة للتخصيص.
 *
 * لكل تاجر أرقامه التي تهمّه: مقهى لا يعنيه «العملاء المتعثّرون»، ومتجر
 * جملة لا يعنيه «متوسط الطلب». فيخفي ما لا يريد ويبقى ما يريد.
 *
 * الحفظ في localStorage عن قصد لا في الخادم: هذا تفضيل عرض لا بيانات
 * عمل — ولا يستحق طلب شبكة عند كل نقرة. التسمية هي المفتاح لأن ترتيب
 * البطاقات قد يتغيّر بين الإصدارات بينما تسمياتها ثابتة.
 */
export default function StatGrid({ stats, storageKey }: { stats: Stat[]; storageKey: string }) {
    const t = useTranslate();
    const key = `abaad:stats:${storageKey}:hidden`;

    const [editing, setEditing] = useState(false);
    const [hidden, setHidden] = useState<string[]>([]);

    // القراءة بعد التركيب: localStorage غير متاح أثناء التصيير الأول
    useEffect(() => {
        try {
            const raw = localStorage.getItem(key);
            if (raw) setHidden(JSON.parse(raw) as string[]);
        } catch {
            // تخزين معطّل أو قيمة تالفة — نعرض الكل
        }
    }, [key]);

    const persist = (next: string[]) => {
        setHidden(next);
        try {
            localStorage.setItem(key, JSON.stringify(next));
        } catch {
            // وضع التصفّح الخاص قد يمنع الكتابة — الإخفاء يبقى لهذه الجلسة
        }
    };

    // بطاقة أُزيلت من الخادم لا يجوز أن تبقى عالقة في قائمة المخفيّات
    const labels = stats.map((s) => s.label);
    const hiddenNow = hidden.filter((l) => labels.includes(l));
    const visible = stats.filter((s) => !hiddenNow.includes(s.label));

    return (
        <div>
            <div className="mb-3 flex flex-wrap items-center justify-end gap-2">
                {editing && (
                    <div className="me-auto flex flex-wrap items-center gap-1.5">
                        <span className="text-[12px] text-[#9ca3af]">{t('المخفية:')}</span>
                        {hiddenNow.length === 0 ? (
                            <span className="text-[12px] text-[#c4c4c4]">{t('لا شيء')}</span>
                        ) : (
                            hiddenNow.map((label) => (
                                <button
                                    key={label}
                                    type="button"
                                    onClick={() => persist(hiddenNow.filter((l) => l !== label))}
                                    className="inline-flex items-center gap-1 rounded-full bg-[#f2f2f0] px-2.5 py-1 text-[12px] text-[#6b7280] transition-colors hover:bg-[#e8e8e6]"
                                >
                                    <Plus className="size-3" />
                                    {label}
                                </button>
                            ))
                        )}
                    </div>
                )}
                <Button
                    type="button"
                    variant={editing ? 'primary' : 'outline'}
                    size="sm"
                    onClick={() => setEditing(!editing)}
                >
                    {editing ? <Check /> : <SlidersHorizontal />}
                    {t(editing ? 'تم' : 'تخصيص')}
                </Button>
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {visible.map((stat, i) => (
                    <div key={stat.label} className="relative">
                        <StatCard stat={stat} index={i} />
                        {editing && (
                            <button
                                type="button"
                                onClick={() => persist([...hiddenNow, stat.label])}
                                title={t('إخفاء البطاقة')}
                                className="absolute top-2 z-10 flex size-6 items-center justify-center rounded-full bg-[#111] text-white transition-opacity hover:opacity-80 end-2"
                            >
                                <X className="size-3.5" />
                                <span className="sr-only">{t('إخفاء البطاقة')}</span>
                            </button>
                        )}
                    </div>
                ))}
            </div>

            {visible.length === 0 && (
                <p className="rounded-[14px] border border-dashed border-[var(--ui-border,#e8e8e8)] py-10 text-center text-sm text-[#9ca3af]">
                    {t('كل البطاقات مخفية — اضغط «تخصيص» لإظهارها.')}
                </p>
            )}
        </div>
    );
}
