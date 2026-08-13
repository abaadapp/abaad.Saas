import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';

interface Props {
    id: number;
    field: 'price' | 'quantity';
    value: number;
    /** ما يُعرض حين لا يكون الحقل قيد التحرير */
    display: string;
    className?: string;
}

/**
 * خليةٌ تُحرَّر في مكانها.
 *
 * جردُ عشرين صنفًا كان أربعين نقرة: فتحُ صفحة الصنف، تعديل، حفظ، رجوع —
 * لكلٍّ منها. والرقم هنا يُنقر فيصير حقلًا، ويُحفظ بـEnter أو بمغادرته.
 *
 * ولا يُرسَل شيء إن لم تتغيّر القيمة: نقرةٌ بالخطأ ثم خروجٌ لا تكتب سطرًا في
 * سجلّ النشاط ولا تُحدّث الصفحة.
 */
export default function QuickCell({ id, field, value, display, className }: Props) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(String(value));
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (editing) {
            input.current?.focus();
            input.current?.select();
        }
    }, [editing]);

    // القيمة قد تتغيّر من الخادم (تغذية المخزون الحيّة) بينما الحقل مغلق
    useEffect(() => {
        if (!editing) setDraft(String(value));
    }, [value, editing]);

    const commit = () => {
        setEditing(false);
        const next = Number(draft);

        if (!Number.isFinite(next) || next < 0 || next === value) {
            setDraft(String(value));

            return;
        }

        router.patch(route('admin.products.quick', id), { [field]: next }, {
            preserveScroll: true,
            preserveState: true,
            onError: () => setDraft(String(value)),
        });
    };

    if (!editing) {
        return (
            <button
                type="button"
                onClick={() => setEditing(true)}
                className={cn(
                    'rounded-[6px] px-1.5 py-0.5 tabular-nums transition-colors hover:bg-[#f2f2f0]',
                    className,
                )}
            >
                {display}
            </button>
        );
    }

    return (
        <input
            ref={input}
            type="number"
            dir="ltr"
            step={field === 'price' ? '0.001' : '1'}
            min="0"
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            onBlur={commit}
            onKeyDown={(e) => {
                if (e.key === 'Enter') commit();
                if (e.key === 'Escape') {
                    setDraft(String(value));
                    setEditing(false);
                }
            }}
            className="w-24 rounded-[6px] border border-[#111] px-1.5 py-0.5 text-end tabular-nums outline-none"
        />
    );
}
