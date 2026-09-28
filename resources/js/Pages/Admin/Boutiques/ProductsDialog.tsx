import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import {
    Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle,
} from '@/Components/ui/dialog';
import { useAsciiDigits } from '@/lib/numerals';
import { useTranslate } from '@/lib/i18n';

export interface PickRow {
    id: number;
    name: string;
    sku: string | null;
    /** أين هو الآن — أو `null` إن لم يكن عند أحد */
    boutique_id: number | null;
    boutique_name: string | null;
}

interface Props {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    boutiqueId: number;
    /** الشهرُ المفتوح — يُحمل مع البحث لئلّا يرتدّ الكشفُ إلى شهر الخادم */
    period: string;
    catalog: { rows: PickRow[]; more: boolean };
    q: string;
}

/**
 * اختيارُ أصنافٍ تُسنَد إلى بوتيك — من شاشته لا من بطاقة كلّ صنف.
 *
 * ═══ ولا يُنقل صنفٌ من بوتيكٍ إلى بوتيكٍ من هنا ═══
 *
 * الصنفُ الذي عند غيره يُعرض ويُقال أين هو، ولا يُختار. ونقلُه خطوتان
 * يراهما صاحبُهما: يُنزع من الأوّل ثمّ يُسنَد إلى الثاني — لا ضغطةٌ واحدةٌ
 * تنقص كشفَ بوتيكٍ لم يُسأل. والخادمُ يردّ ذلك أيضًا (`attach`)، فالشاشةُ
 * تشرح والخادمُ يمنع.
 *
 * ═══ والبحثُ في الخادم ═══
 *
 * الكتالوجُ يكبر، فلا يُرسل كلُّه في كلّ فتحة. يُرشَّح هناك ويعود مقطوعًا
 * عند حدٍّ يُقال أنّه قُطع — بإعادة تحميلٍ جزئيّة (`only`) لا بمسارٍ جديد.
 */
export default function ProductsDialog({
    open, onOpenChange, boutiqueId, period, catalog, q,
}: Props) {
    const t = useTranslate();
    const searchRef = useAsciiDigits<HTMLInputElement>();
    const [query, setQuery] = useState(q);
    const [picked, setPicked] = useState<number[]>([]);
    const [saving, setSaving] = useState(false);

    // والنافذةُ تُفتح نظيفةً: اختيارُ فتحةٍ سابقة لا يُحفظ ليُرسل في غيرها
    useEffect(() => {
        if (open) setPicked([]);
    }, [open]);

    /*
     * والبحثُ يُمهَل — كما تفعل `DataTable`: طلبٌ لكلّ حرفٍ يُغرق الخادم
     * ويجعل الحقل يتلعثم. و`only` تجلب الكتالوجَ وحدَه فلا يُعاد بناءُ كشفٍ
     * كاملٍ لأجل حرف.
     */
    const first = useRef(true);
    useEffect(() => {
        if (!open) return;
        if (first.current) {
            first.current = false;

            return;
        }

        const id = setTimeout(() => {
            router.get(route('admin.boutiques.show', boutiqueId), { period, q: query }, {
                only: ['catalog', 'q'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 350);

        return () => clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query, open]);

    const toggle = (id: number) =>
        setPicked((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));

    const save = () => {
        setSaving(true);
        router.post(route('admin.boutiques.attach', boutiqueId), { product_ids: picked }, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onFinish: () => setSaving(false),
        });
    };

    /** أين هذا الصنف: حرٌّ، أو عند هذا البوتيك، أو عند غيره */
    const where = (r: PickRow): 'free' | 'mine' | 'other' =>
        r.boutique_id === null ? 'free' : r.boutique_id === boutiqueId ? 'mine' : 'other';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('إضافة منتجات')}</DialogTitle>
                </DialogHeader>

                <div className="space-y-3 px-5 pb-1">
                    <div className="flex items-center gap-2 rounded-[10px] border border-[var(--ui-border,#e8e8e8)] px-3 py-2">
                        <Search className="size-4 shrink-0 text-[#9ca3af]" />
                        <input
                            ref={searchRef}
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder={t('ابحث باسم المنتج أو رمزه…')}
                            aria-label={t('ابحث باسم المنتج أو رمزه…')}
                            className="w-full bg-transparent text-[13px] outline-none"
                        />
                    </div>

                    <div className="max-h-72 overflow-y-auto rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-1">
                        {catalog.rows.length === 0 ? (
                            <p className="p-6 text-center text-[13px] text-[#9ca3af]">{t('لا نتائج')}</p>
                        ) : (
                            catalog.rows.map((r) => {
                                const at = where(r);
                                const shut = at !== 'free';

                                return (
                                    <label
                                        key={r.id}
                                        className={
                                            'flex items-center gap-2 rounded-[8px] px-2 py-2 '
                                            + (shut ? 'opacity-60' : 'cursor-pointer hover:bg-[#f7f7f5]')
                                        }
                                    >
                                        <input
                                            type="checkbox"
                                            disabled={shut}
                                            checked={at === 'mine' || picked.includes(r.id)}
                                            onChange={() => toggle(r.id)}
                                            aria-label={r.name}
                                        />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-[13px] text-[#111]">{r.name}</span>
                                            {r.sku && (
                                                <span className="block truncate text-[12px] text-[#9ca3af]">
                                                    {r.sku}
                                                </span>
                                            )}
                                        </span>
                                        {/* وأين هو الآن يُقال في صفّه — لا في رسالةٍ بعد الحفظ */}
                                        {at === 'mine' && <Badge variant="success">{t('في هذا البوتيك')}</Badge>}
                                        {at === 'other' && (
                                            <Badge variant="neutral">
                                                {t('مرتبط بـ :name', { name: r.boutique_name ?? '—' })}
                                            </Badge>
                                        )}
                                    </label>
                                );
                            })
                        )}
                    </div>

                    {catalog.more && (
                        <p className="text-[12px] text-[#9ca3af]">
                            {t('تُعرض أوّل ٥٠ نتيجة — اكتب للبحث عن غيرها.')}
                        </p>
                    )}
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                        {t('إلغاء')}
                    </Button>
                    <Button type="button" disabled={picked.length === 0} loading={saving} onClick={save}>
                        {picked.length > 0 ? t('إضافة :n', { n: String(picked.length) }) : t('إضافة')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
