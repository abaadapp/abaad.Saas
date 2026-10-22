import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';

/**
 * قطعُ البوتيك في طلبٍ يُلغى — يُسأل عن كلّ قطعةٍ وكمّيّتها على حدة.
 *
 * عادت إلى الرفّ، أو لم تعد، وما بقي «لم يُتحقَّق منه». ولا يُفترض أنّ
 * القطع كلَّها حالٌ واحدة: فضّةٌ عادت وعطرٌ تلف في باقةٍ واحدة. وما عاد
 * يُعكس مستحقُّه، وما لم يعد يبقى للبوتيك وخسارتُه على المتجر — يحسبه
 * الخادم (`BoutiqueDues::cancel`)، والشاشةُ تسأل فقط.
 */

export type BoutiquePiece = {
    id: number;
    product: string;
    boutique: string | null;
    quantity: number;
    unit_price: number;
    source: string;
    /** `null` لقطعةٍ حيّة في طلبٍ قائم، و`lost` أو `unverified` لما بقي بعد الإلغاء */
    state: string | null;
};

type Answer = Record<number, { returned: number; lost: number }>;

const clamp = (n: number, max: number) => Math.max(0, Math.min(max, Math.floor(Number.isFinite(n) ? n : 0)));

/** نافذةُ الإلغاء: لكلّ قطعةٍ كم عاد وكم لم يعد — والباقي لم يُتحقَّق منه */
export function CancelWithPieces({ open, pieces, onClose, onConfirm }: {
    open: boolean;
    pieces: BoutiquePiece[];
    onClose: () => void;
    onConfirm: (answer: Answer) => void;
}) {
    const t = useTranslate();
    const [answer, setAnswer] = useState<Answer>({});

    const set = (p: BoutiquePiece, key: 'returned' | 'lost', raw: string) =>
        setAnswer((a) => {
            const cur = a[p.id] ?? { returned: 0, lost: 0 };
            const other = key === 'returned' ? cur.lost : cur.returned;
            return { ...a, [p.id]: { ...cur, [key]: clamp(Number(raw), p.quantity - other) } };
        });

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent data-testid="cancel-boutique-pieces">
                <DialogHeader>
                    <DialogTitle>{t('إلغاء الطلب — قطع البوتيك')}</DialogTitle>
                </DialogHeader>
                <div className="px-5 pb-5">
                <p className="text-[13px] text-[#6b7280]">
                    {t('ما عاد إلى الرفّ يُعكس مستحقُّه. وما لم يعد أو لم يُتحقَّق منه يبقى للبوتيك وخسارتُه على المتجر.')}
                </p>
                <ul className="mt-3 space-y-3">
                    {pieces.map((p) => {
                        const a = answer[p.id] ?? { returned: 0, lost: 0 };
                        return (
                            <li key={p.id} className="rounded-[10px] border border-[var(--ui-border,#e8e8e8)] p-3" data-testid="cancel-piece">
                                <p className="text-sm font-medium">{p.product} × {p.quantity} <span className="text-[#6b7280]">— {p.boutique}</span></p>
                                <div className="mt-2 grid grid-cols-3 gap-2 text-[12px]">
                                    <label>{t('عادت')}
                                        <Input type="number" min="0" max={p.quantity} dir="ltr" value={String(a.returned)}
                                            onChange={(e) => set(p, 'returned', e.target.value)} aria-label={t('عادت')} />
                                    </label>
                                    <label>{t('لم تعد')}
                                        <Input type="number" min="0" max={p.quantity} dir="ltr" value={String(a.lost)}
                                            onChange={(e) => set(p, 'lost', e.target.value)} aria-label={t('لم تعد')} />
                                    </label>
                                    <p className="self-end pb-2" data-testid="cancel-piece-open">
                                        {t('لم يُتحقَّق منها')}: {p.quantity - a.returned - a.lost}
                                    </p>
                                </div>
                            </li>
                        );
                    })}
                </ul>
                <div className="mt-4 flex justify-end gap-2">
                    <Button variant="ghost" onClick={onClose}>{t('رجوع')}</Button>
                    <Button variant="danger" onClick={() => onConfirm(answer)} data-testid="cancel-confirm">{t('ألغِ الطلب')}</Button>
                </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

/** بعد الإلغاء: قطعٌ بقي مستحقُّها — عادت الآن، أو تأكّد أنّها لن تعود */
export function KeptPieces({ orderNumber, pieces }: { orderNumber: string; pieces: BoutiquePiece[] }) {
    const t = useTranslate();
    const [back, setBack] = useState<Record<number, string>>({});
    const kept = pieces.filter((p) => p.state !== null);
    if (kept.length === 0) return null;

    const post = (action: 'returned' | 'lost', id: number, qty: number) =>
        router.post(route('admin.orders.boutique-pieces', orderNumber), { action, pieces: { [id]: qty } }, { preserveScroll: true });

    return (
        <Card className="p-4" data-testid="kept-boutique-pieces">
            <p className="mb-2 text-sm font-semibold text-[#111]">{t('قطع بوتيك بقي مستحقُّها بعد الإلغاء')}</p>
            <ul className="space-y-2 text-sm">
                {kept.map((p) => (
                    <li key={p.id} className="flex flex-wrap items-center justify-between gap-2">
                        <span>
                            {p.product} × {p.quantity} — {p.boutique}
                            <span className="text-[#6b7280]"> ({p.state === 'lost' ? t('لم تعد') : t('لم يُتحقَّق منها')})</span>
                        </span>
                        <span className="flex items-center gap-2">
                            <Input className="w-16" type="number" min="1" max={p.quantity} dir="ltr"
                                value={back[p.id] ?? String(p.quantity)} aria-label={t('كم عاد')}
                                onChange={(e) => setBack((b) => ({ ...b, [p.id]: e.target.value }))} />
                            <Button size="sm" variant="outline" onClick={() => post('returned', p.id, clamp(Number(back[p.id] ?? p.quantity), p.quantity))}>
                                {t('عادت إلى الرفّ')}
                            </Button>
                            {p.state === 'unverified' && (
                                <Button size="sm" variant="ghost" onClick={() => post('lost', p.id, p.quantity)}>{t('لن تعود')}</Button>
                            )}
                        </span>
                    </li>
                ))}
            </ul>
        </Card>
    );
}
