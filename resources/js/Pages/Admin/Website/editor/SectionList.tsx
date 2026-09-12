import { useEffect, useRef, useState } from 'react';
import { Copy, Eye, EyeOff, GripVertical, MoreHorizontal, Plus, Trash2 } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type EditorSection, keyOf } from './types';

interface Props {
    pageTitle: string;
    globals: EditorSection[];
    sections: EditorSection[];
    activeKey: string | null;
    onOpen: (key: string) => void;
    onReorder: (order: number[]) => void;
    onToggle: (section: EditorSection) => void;
    onDuplicate: (section: EditorSection) => void;
    onDelete: (section: EditorSection) => void;
    onAdd: () => void;
}

/**
 * أقسامُ الصفحة — قائمةٌ ترتيبُها ترتيبُ الموقع.
 *
 * وهي الطريق الثاني لا الأوّل: الأوّلُ ضغطُ القسم في المعاينة. لكنّها تبقى
 * لأنّ ثلاثةَ أفعالٍ لا موضعَ لها في المعاينة — الترتيبُ والإخفاءُ والحذف —
 * ولأنّ من أخفى قسمًا يبحث عنه حيث يبحث عن الأشياء الغائبة: في قائمة.
 *
 * ═══ والترتيبُ بمقبضٍ واحدٍ يعمل بالفأرة وبلوحة المفاتيح ═══
 *
 * السحبُ وحده يُقصي من لا فأرةَ له، وزرّا «أعلى» و«أسفل» في كلّ صفٍّ يجعلان
 * الصفَّ أربعةَ مقابض. فالمقبضُ واحد: يُسحَب بالفأرة، ويُركَّز عليه فيُحرَّك
 * بالسهمين. وهو زرٌّ حقيقيّ لا `div`، فيصله التركيز بلا حيلة.
 *
 * ولا «نسخ» ولا «حذف» ظاهرين في كلّ صف: أربعةُ أزرارٍ في صفٍّ اسمُه ثلاثُ
 * كلمات تجعل القائمة ضوضاء. الإخفاءُ ظاهرٌ لأنّه يُستعمل كلّ يوم، والباقي
 * خلف «⋯».
 */
export default function SectionList({
    pageTitle,
    globals,
    sections,
    activeKey,
    onOpen,
    onReorder,
    onToggle,
    onDuplicate,
    onDelete,
    onAdd,
}: Props) {
    const t = useTranslate();

    /** الصفُّ الممسوك الآن — رقمُه في الترتيب المعروض */
    const [dragging, setDragging] = useState<number | null>(null);
    /** ترتيبٌ مؤقّت يُرى أثناء السحب قبل أن يصل الخادم */
    const [order, setOrder] = useState<number[] | null>(null);
    const moved = useRef(false);

    const shown = order
        ? (order.map((id) => sections.find((s) => s.id === id)).filter(Boolean) as EditorSection[])
        : sections;

    /*
     * والترتيبُ المؤقّت يبقى حتى يصل الترتيبُ من الخادم.
     *
     * لو مُحي عند الإرسال لَعادت القائمةُ إلى ترتيبها القديم ثمّ قفزت إلى
     * الجديد حين يردّ الخادم — ومن حرّك قسمًا يرى حركتَه تُلغى ثمّ تقع. وهذه
     * الحمولةُ الجديدة هي علامةُ الوصول: `sections` تُستبدل فيُمحى المؤقّت.
     */
    useEffect(() => setOrder(null), [sections]);

    const commit = (list: EditorSection[]) => {
        setDragging(null);

        if (!moved.current) return;

        moved.current = false;
        onReorder(list.map((s) => s.id));
    };

    const shift = (from: number, to: number) => {
        if (to < 0 || to >= shown.length || from === to) return shown;

        const next = [...shown];
        const [row] = next.splice(from, 1);

        next.splice(to, 0, row);
        moved.current = true;
        setOrder(next.map((s) => s.id));

        return next;
    };

    return (
        <div className="space-y-5">
            {/* ===== ما يظهر في كلّ صفحة ===== */}
            {globals.length > 0 && (
                <section>
                    <h3 className="mb-2 px-1 text-[11px] font-semibold tracking-wide text-[#9ca3af]">
                        {t('يظهر في جميع الصفحات')}
                    </h3>
                    <ul className="space-y-1">
                        {globals.map((section, i) => {
                            const key = keyOf(section, i);

                            return (
                                <li key={section.id}>
                                    <button
                                        type="button"
                                        onClick={() => onOpen(key)}
                                        aria-current={activeKey === key}
                                        className={cn(
                                            'flex w-full items-center gap-2.5 rounded-[10px] px-2.5 py-2 text-start text-[13px] transition-colors',
                                            activeKey === key
                                                ? 'bg-[#111] font-semibold text-white'
                                                : 'text-[#374151] hover:bg-[#f3f4f6]',
                                        )}
                                    >
                                        <span className="min-w-0 flex-1 truncate">{section.label}</span>
                                        {!section.visible && (
                                            <EyeOff className="size-3.5 shrink-0 opacity-60" />
                                        )}
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </section>
            )}

            {/* ===== أقسام الصفحة المفتوحة ===== */}
            <section>
                <div className="mb-2 flex items-center justify-between gap-2 px-1">
                    <h3 className="min-w-0 truncate text-[11px] font-semibold tracking-wide text-[#9ca3af]">
                        {t('أقسام')} «{pageTitle}»
                    </h3>
                    <Button size="sm" variant="ghost" onClick={onAdd}>
                        <Plus />
                        {t('قسم')}
                    </Button>
                </div>

                {shown.length === 0 ? (
                    <p className="rounded-[10px] bg-[#f9fafb] px-3 py-6 text-center text-[13px] leading-6 text-[#9ca3af]">
                        {t('لا أقسام في هذه الصفحة — أضف أوّل قسم')}
                    </p>
                ) : (
                    <ul className="space-y-1">
                        {shown.map((section, i) => {
                            const key = keyOf(section, i);
                            const on = activeKey === key;

                            return (
                                <li
                                    key={section.id}
                                    onDragOver={(e) => {
                                        if (dragging === null || dragging === i) return;
                                        e.preventDefault();
                                        shift(dragging, i);
                                        setDragging(i);
                                    }}
                                    className={cn(
                                        'group flex items-center gap-1 rounded-[10px] pe-1 transition-colors',
                                        on ? 'bg-[#111] text-white' : 'text-[#374151] hover:bg-[#f3f4f6]',
                                        dragging === i && 'opacity-50',
                                        !section.visible && !on && 'text-[#9ca3af]',
                                    )}
                                >
                                    <button
                                        type="button"
                                        draggable
                                        onDragStart={() => {
                                            moved.current = false;
                                            setDragging(i);
                                        }}
                                        onDragEnd={() => commit(shown)}
                                        onKeyDown={(e) => {
                                            const delta =
                                                e.key === 'ArrowUp' ? -1 : e.key === 'ArrowDown' ? 1 : 0;

                                            if (!delta) return;

                                            e.preventDefault();
                                            commit(shift(i, i + delta));
                                        }}
                                        aria-label={t('أعد ترتيب :name — بسهمي أعلى وأسفل', {
                                            name: section.label,
                                        })}
                                        className={cn(
                                            'shrink-0 cursor-grab rounded-s-[10px] py-2.5 ps-1.5 pe-0.5 opacity-40 transition-opacity hover:opacity-100 focus-visible:opacity-100',
                                            on && 'opacity-70',
                                        )}
                                    >
                                        <GripVertical className="size-4" />
                                    </button>

                                    <button
                                        type="button"
                                        onClick={() => onOpen(key)}
                                        aria-current={on}
                                        className="min-w-0 flex-1 py-2 text-start"
                                    >
                                        <span
                                            className={cn(
                                                'block truncate text-[13px]',
                                                on && 'font-semibold',
                                            )}
                                        >
                                            {section.label}
                                        </span>
                                    </button>

                                    <button
                                        type="button"
                                        onClick={() => onToggle(section)}
                                        aria-label={t(section.visible ? 'إخفاء' : 'إظهار')}
                                        className={cn(
                                            'shrink-0 rounded-[8px] p-1.5 transition-colors',
                                            on ? 'hover:bg-white/15' : 'hover:bg-[#e5e7eb]',
                                        )}
                                    >
                                        {section.visible ? (
                                            <Eye className="size-4 opacity-45" />
                                        ) : (
                                            <EyeOff className="size-4" />
                                        )}
                                    </button>

                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <button
                                                type="button"
                                                aria-label={t('المزيد')}
                                                className={cn(
                                                    'shrink-0 rounded-[8px] p-1.5 transition-colors',
                                                    on ? 'hover:bg-white/15' : 'hover:bg-[#e5e7eb]',
                                                )}
                                            >
                                                <MoreHorizontal className="size-4 opacity-45" />
                                            </button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem onSelect={() => onDuplicate(section)}>
                                                <Copy className="size-4" />
                                                {t('نسخ القسم')}
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                onSelect={() => onDelete(section)}
                                                className="text-[#b91c1c]"
                                            >
                                                <Trash2 className="size-4" />
                                                {t('حذف القسم')}
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>
        </div>
    );
}
