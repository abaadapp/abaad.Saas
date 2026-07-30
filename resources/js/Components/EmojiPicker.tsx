import { useMemo, useState } from 'react';
import { Search } from 'lucide-react';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface EmojiItem {
    e: string;
    k: string;
}
export type EmojiGroups = Record<string, EmojiItem[]>;

interface Props {
    value: string;
    onChange: (emoji: string) => void;
    groups: EmojiGroups;
    label?: string;
    fallback?: string;
}

/**
 * منتقي إيموجي بالبحث — بديل partials/emoji-picker.
 *
 * المجموعات تأتي من App\Support\Emojis عبر الخادم لا من مصفوفة مكرّرة هنا،
 * فتبقى مصدرًا واحدًا للرموز وكلماتها المفتاحية بالعربية والإنجليزية.
 */
export default function EmojiPicker({
    value,
    onChange,
    groups,
    label = 'الأيقونة',
    fallback = '🎁',
}: Props) {
    const t = useTranslate();
    const [q, setQ] = useState('');

    const filtered = useMemo(() => {
        const needle = q.trim().toLowerCase();
        if (!needle) return groups;
        const out: EmojiGroups = {};
        Object.entries(groups).forEach(([name, items]) => {
            const hits = items.filter((it) => it.k.includes(needle));
            if (hits.length) out[name] = hits;
        });
        return out;
    }, [q, groups]);

    const empty = Object.keys(filtered).length === 0;

    return (
        <div className="space-y-1.5">
            <span className="block text-[13px] font-medium text-[#4b4b4b]">{t(label)}</span>

            <div className="mb-2 flex items-center gap-2">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-[#f2f2f0] text-[22px] leading-none">
                    {value || fallback}
                </span>
                <div className="relative flex-1">
                    <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-[#9ca3af]" />
                    <Input
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder={t('ابحث عن رمز…')}
                        className="ps-9"
                    />
                </div>
            </div>

            <div className="max-h-56 overflow-y-auto rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-2">
                {empty ? (
                    <p className="py-6 text-center text-[13px] text-[#9ca3af]">{t('لا نتائج مطابقة')}</p>
                ) : (
                    Object.entries(filtered).map(([name, items]) => (
                        <div key={name} className="mb-2 last:mb-0">
                            <p className="mb-1 px-1 text-[11px] font-semibold text-[#9ca3af]">{name}</p>
                            <div className="flex flex-wrap gap-1">
                                {items.map((it) => (
                                    <button
                                        key={it.e}
                                        type="button"
                                        onClick={() => onChange(it.e)}
                                        title={it.k}
                                        className={cn(
                                            'flex size-9 items-center justify-center rounded-[8px] text-xl leading-none transition-colors hover:bg-[#f2f2f0]',
                                            value === it.e && 'bg-[#111]/5 ring-1 ring-[#111]',
                                        )}
                                    >
                                        {it.e}
                                    </button>
                                ))}
                            </div>
                        </div>
                    ))
                )}
            </div>
        </div>
    );
}
