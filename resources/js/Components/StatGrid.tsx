import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import { Check, Plus, RotateCcw, SlidersHorizontal, X } from 'lucide-react';
import StatCard, { type Stat } from '@/Components/StatCard';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';

/** بطاقة اختيارية من مقاييس التقارير */
export interface CatalogStat extends Stat {
    key: string;
    /** قسمها في التقارير — البطاقة سؤال، وقسمها الجواب المفصَّل */
    url?: string | null;
}

interface Props {
    stats: Stat[];
    storageKey: string;
    /** ما يمكن إضافته: يُعرض في وضع التخصيص ولا يظهر ما لم يُختر */
    catalog?: CatalogStat[];
}

/**
 * شبكة المؤشرات القابلة للتخصيص.
 *
 * لكل تاجر أرقامه التي تهمّه: مقهى لا يعنيه «العملاء المتعثّرون»، ومتجر
 * جملة لا يعنيه «متوسط الطلب». فيخفي ما لا يريد، ويضيف ما يريد من مقاييس
 * التقارير — لا قائمة ثابتة يرضى بها كما هي.
 *
 * الحفظ في localStorage عن قصد لا في الخادم: هذا تفضيل عرض لا بيانات
 * عمل — ولا يستحق طلب شبكة عند كل نقرة. التسمية هي المفتاح لأن ترتيب
 * البطاقات قد يتغيّر بين الإصدارات بينما تسمياتها ثابتة.
 */
export default function StatGrid({ stats, storageKey, catalog = [] }: Props) {
    const t = useTranslate();
    const key = `abaad:stats:${storageKey}:hidden`;
    const addedKey = `abaad:stats:${storageKey}:added`;

    const [editing, setEditing] = useState(false);
    const [hidden, setHidden] = useState<string[]>([]);
    const [added, setAdded] = useState<string[]>([]);

    // القراءة بعد التركيب: localStorage غير متاح أثناء التصيير الأول
    useEffect(() => {
        const read = (k: string): string[] => {
            try {
                const raw = localStorage.getItem(k);
                return raw ? (JSON.parse(raw) as string[]) : [];
            } catch {
                // تخزين معطّل أو قيمة تالفة — نعود إلى الافتراضي
                return [];
            }
        };
        setHidden(read(key));
        setAdded(read(addedKey));
    }, [key, addedKey]);

    const write = (k: string, next: string[], set: (v: string[]) => void) => {
        set(next);
        try {
            localStorage.setItem(k, JSON.stringify(next));
        } catch {
            // وضع التصفّح الخاص قد يمنع الكتابة — الاختيار يبقى لهذه الجلسة
        }
    };

    /**
     * العودة إلى الافتراضي — بضغطة، لا بطاقةً بطاقة.
     *
     * من أخفى ستّ بطاقاتٍ وأراد ردَّها كان يضغط ستّ مرّات على أسمائها في شريط
     * «أضف»، ولا شيء يقول له إنّ الافتراضيّ شيءٌ يُستعاد. والحفظ في المتصفّح
     * وحده — لا سبيل إلى ردّه من الخادم، فالسبيل زرٌّ هنا.
     */
    const customised = hidden.length > 0 || added.length > 0;

    const restore = () => {
        write(key, [], setHidden);
        write(addedKey, [], setAdded);
    };

    // بطاقة أُزيلت من الخادم لا يجوز أن تبقى عالقة في قائمة المخفيّات
    const labels = stats.map((s) => s.label);
    const hiddenNow = hidden.filter((l) => labels.includes(l));
    const catalogKeys = catalog.map((c) => c.key);
    const addedNow = added.filter((k) => catalogKeys.includes(k));

    const visible = stats.filter((s) => !hiddenNow.includes(s.label));
    const extras = catalog.filter((c) => addedNow.includes(c.key));
    const available = catalog.filter((c) => !addedNow.includes(c.key));

    interface Shown {
        stat: Stat;
        id: string;
        url?: string | null;
        remove: () => void;
    }

    const shown: Shown[] = [
        ...visible.map((s) => ({ stat: s, id: s.label, remove: () => write(key, [...hiddenNow, s.label], setHidden) })),
        ...extras.map((c) => ({
            stat: c as Stat,
            id: c.key,
            url: c.url,
            remove: () => write(addedKey, addedNow.filter((k) => k !== c.key), setAdded),
        })),
    ];

    return (
        <div>
            <div className="mb-3 flex flex-wrap items-center justify-end gap-2">
                {editing && (
                    <div className="me-auto flex flex-wrap items-center gap-1.5">
                        <span className="text-[12px] text-[#9ca3af]">{t('أضف:')}</span>
                        {hiddenNow.length === 0 && available.length === 0 ? (
                            <span className="text-[12px] text-[#c4c4c4]">{t('لا شيء')}</span>
                        ) : (
                            <>
                                {/* المخفيّة تعود، والاختيارية تُضاف — كلاهما «إضافة» عند التاجر */}
                                {hiddenNow.map((label) => (
                                    <button
                                        key={label}
                                        type="button"
                                        onClick={() => write(key, hiddenNow.filter((l) => l !== label), setHidden)}
                                        className="inline-flex items-center gap-1 rounded-full bg-[#f2f2f0] px-2.5 py-1 text-[12px] text-[#6b7280] transition-colors hover:bg-[#e8e8e6]"
                                    >
                                        <Plus className="size-3" />
                                        {label}
                                    </button>
                                ))}
                                {available.map((c) => (
                                    <button
                                        key={c.key}
                                        type="button"
                                        onClick={() => write(addedKey, [...addedNow, c.key], setAdded)}
                                        className="inline-flex items-center gap-1 rounded-full border border-dashed border-[#d1d5db] px-2.5 py-1 text-[12px] text-[#6b7280] transition-colors hover:border-[#111] hover:text-[#111]"
                                    >
                                        <Plus className="size-3" />
                                        {c.label}
                                    </button>
                                ))}
                            </>
                        )}
                    </div>
                )}
                {editing && customised && (
                    <Button type="button" variant="outline" size="sm" onClick={restore}>
                        <RotateCcw />
                        {t('إعادة الافتراضي')}
                    </Button>
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
                {shown.map((item, i) => {
                    const card = <StatCard stat={item.stat} index={i} />;

                    return (
                        <div key={item.id} className="relative">
                            {/* البطاقة المضافة تقود إلى قسمها — ما لم نكن نخصّص،
                                فالنقر حينها يعني الحذف لا التنقّل */}
                            {item.url && !editing ? (
                                <Link href={item.url} className="block">
                                    {card}
                                </Link>
                            ) : (
                                card
                            )}
                            {editing && (
                                <button
                                    type="button"
                                    onClick={item.remove}
                                    title={t('إخفاء البطاقة')}
                                    className="absolute end-2 top-2 z-10 flex size-6 items-center justify-center rounded-full bg-[#111] text-white transition-opacity hover:opacity-80"
                                >
                                    <X className="size-3.5" />
                                    <span className="sr-only">{t('إخفاء البطاقة')}</span>
                                </button>
                            )}
                        </div>
                    );
                })}
            </div>

            {shown.length === 0 && (
                <p className="rounded-[14px] border border-dashed border-[var(--ui-border,#e8e8e8)] py-10 text-center text-sm text-[#9ca3af]">
                    {t('كل البطاقات مخفية — اضغط «تخصيص» ثمّ «إعادة الافتراضي».')}
                </p>
            )}
        </div>
    );
}
