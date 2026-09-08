import { useEffect, useMemo, useRef, useState } from 'react';
import { Check, ChevronDown, Plus, X } from 'lucide-react';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { fold } from '@/lib/pages';
import { cn } from '@/lib/utils';

/**
 * اختَر من قائمةٍ أو اكتب ما ليس فيها — قائمةٌ مرسومةٌ في الصفحة.
 *
 * ═══ ولماذا لا `datalist` ═══
 *
 * القائمةُ الأصليّة يرسمها نظامُ التشغيل: نافذةٌ داكنةٌ ضيّقة تطفو فوق الحقل
 * فتحجبه، لا تحترم عرضَه ولا خطَّه ولا اتجاهَ الواجهة، ولا سهمَ فيها يقول
 * إنّ ثمّةَ قائمةً أصلًا — فمن لم يعرف أنّها هناك كتب بيده أو ترك الحقلَ
 * فارغًا. وهي القائمةُ نفسُها التي رُفعت من مرشّحات التقارير.
 *
 * ═══ والقائمةُ لا تحدّ ═══
 *
 * ما يُكتب ولا يُطابق شيئًا يُعرض زرًّا يقول «أضف "كذا"». و`onAdd` تُبقيه في
 * قائمة الشاشة، والخادمُ يعيده في الفتحة التالية لأنّ قوائم هذا النظام
 * تُقرأ ممّا استُعمل فعلًا لا من كلماتٍ مكتوبةٍ في الشاشة.
 *
 * ═══ وما يُضاف يُرفع ═══
 *
 * `onDelete` — حين تُمرَّر — تضع بجانب كلّ خيارٍ زرَّ رفعٍ من القائمة. وقائمةٌ
 * يُضاف إليها ولا يُرفع منها تمتلئ بأخطاء الكتابة ولا تنقص أبدًا.
 *
 * ═══ وواحدٌ لا اثنان ═══
 *
 * يخدم وحدةَ الشراء وتصنيفَ الأصل. وحقلان يقولان الشيء نفسه يفترقان يومًا:
 * يُصلَح أحدُهما ويبقى الآخر على عطبه.
 */
export default function ComboBox({
    value,
    options,
    onPick,
    onAdd,
    onDelete,
    label,
    placeholder,
}: {
    value: string;
    options: string[];
    onPick: (value: string) => void;
    /** ما يُكتب ولا يُطابق شيئًا — يبقى في قائمة هذه الشاشة */
    onAdd: (value: string) => void;
    /** رفعُ خيارٍ من القائمة — بلا هذه لا يُرسم زرُّ الرفع أصلًا */
    onDelete?: (value: string) => void;
    /** اسمُ الحقل لقارئ الشاشة — وهو ما يُطلب به الزرُّ في الاختبارات */
    label: string;
    /** ما يقوله الزرُّ قبل أن يُختار شيء */
    placeholder: string;
}) {
    const t = useTranslate();
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const box = useRef<HTMLDivElement>(null);
    const search = useRef<HTMLInputElement>(null);

    useEffect(() => {
        const away = (e: MouseEvent) => {
            if (box.current && !box.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', away);

        return () => document.removeEventListener('mousedown', away);
    }, []);

    // ‏والمطابقة تُهمل الهمزةَ وشكلَ الرقم — انظر `fold`
    const typed = q.trim();
    const hits = useMemo(() => {
        const needle = fold(typed);

        return needle === '' ? options : options.filter((o) => fold(o).includes(needle));
    }, [options, typed]);

    const exact = hits.some((o) => fold(o) === fold(typed));

    // ‏وما يصلها مقلَّمٌ سلفًا: `typed` تقصّ المسافات، وما في القائمة نظيفٌ من مصدره
    const commit = (picked: string) => {
        onAdd(picked);
        onPick(picked);
        setQ('');
        setOpen(false);
    };

    return (
        <div ref={box} className="relative">
            <button
                type="button"
                aria-label={t(label)}
                aria-expanded={open}
                onClick={() => {
                    setOpen((v) => !v);
                    setQ('');
                    // ‏والكتابةُ تبدأ فورًا: من فتحها ليكتب جديدًا لا يبحث عن الحقل
                    window.setTimeout(() => search.current?.focus(), 0);
                }}
                className={cn(
                    'flex h-10 w-full items-center justify-between gap-2 rounded-[10px] pointer-coarse:h-11',
                    'border border-[var(--ui-border,#e8e8e8)] bg-white px-3 text-start text-sm text-[#111]',
                    'transition-[border-color,box-shadow] outline-none',
                    'focus:border-[#d1d5db] focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)]',
                )}
            >
                <span className={cn('min-w-0 truncate', value === '' && 'text-[#9ca3af]')}>
                    {value === '' ? t(placeholder) : value}
                </span>
                <ChevronDown className="size-4 shrink-0 text-[#6b7280]" />
            </button>

            {open && (
                <div className="absolute z-20 mt-1 w-full min-w-[11rem] rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white p-1 shadow-lg">
                    <Input
                        ref={search}
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                // ‏وحقلُ بحثٍ فارغٌ لا يختار شيئًا: يُغلق ويترك المكتوبَ كما هو
                                if (typed === '') setOpen(false);
                                else commit(hits[0] ?? typed);
                            }

                            if (e.key === 'Escape') {
                                e.preventDefault();
                                setOpen(false);
                            }
                        }}
                        placeholder={t('ابحث أو اكتب جديدًا')}
                        aria-label={t('ابحث أو اكتب جديدًا')}
                        className="h-9 text-[13px]"
                    />

                    <div className="mt-1 max-h-48 overflow-y-auto">
                        {/*
                            وزرٌّ داخل زرّ لا يصحّ في HTML: الصفُّ حاويةٌ فيها
                            زرُّ الاختيار وزرُّ الرفع، لا زرٌّ يبتلع الآخر.
                        */}
                        {hits.map((o) => (
                            <div key={o} className="flex items-center gap-1 rounded-[8px] hover:bg-[#f7f7f5]">
                                <button
                                    type="button"
                                    className="flex min-w-0 flex-1 items-center justify-between gap-2 rounded-[8px] px-2 py-1.5 text-start text-[13px]"
                                    onClick={() => commit(o)}
                                >
                                    <span className="min-w-0 truncate">{o}</span>
                                    {o === value && <Check className="size-4 shrink-0 text-[#5b21b6]" />}
                                </button>

                                {onDelete && (
                                    <button
                                        type="button"
                                        aria-label={t('احذف «:value»', { value: o })}
                                        className="shrink-0 rounded-[6px] p-1.5 text-[#9ca3af] hover:bg-[#fee2e2] hover:text-[#b91c1c]"
                                        onClick={() => onDelete(o)}
                                    >
                                        <X className="size-3.5" />
                                    </button>
                                )}
                            </div>
                        ))}
                    </div>

                    {typed !== '' && !exact && (
                        <button
                            type="button"
                            className="mt-1 flex w-full items-center gap-1.5 rounded-[8px] border-t border-[var(--ui-border,#e8e8e8)] px-2 py-2 text-start text-[13px] text-[#5b21b6]"
                            onClick={() => commit(typed)}
                        >
                            <Plus className="size-4 shrink-0" />
                            <span className="min-w-0 truncate">{t('أضف «:value»', { value: typed })}</span>
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
