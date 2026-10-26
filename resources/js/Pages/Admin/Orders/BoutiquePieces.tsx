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

/** بندُ بوتيكٍ حملته ورقةُ تسوية — مرتجعُه إشعارٌ مرتبطٌ بها (`BoutiqueReturns`) */
export type SheetLine = { id: number; name: string; quantity: number; sheet: string | null };

type LineAnswer = Record<number, { sellable: number; damaged: number }>;

const clamp = (n: number, max: number) => Math.max(0, Math.min(max, Math.floor(Number.isFinite(n) ? n : 0)));

/**
 * نافذةُ الإلغاء: لكلّ قطعةٍ كم عاد وكم لم يعد — والباقي لم يُتحقَّق منه.
 *
 * وبنودُ البوتيك التي حملتها ورقةٌ يُسأل عنها كذلك: كم عاد سليمًا وكم تالفًا،
 * والباقي لم يُستلم بعد فيبقى خارجَ الرفّ. ومَن يتحمّل حصّتَها يحسمه الخادم
 * بشرط الاتّفاقية أو يعرضه للمراجعة — الشاشةُ تسأل فقط.
 */
export function CancelWithPieces({ open, pieces, lines = [], onClose, onConfirm }: {
    open: boolean;
    pieces: BoutiquePiece[];
    lines?: SheetLine[];
    onClose: () => void;
    onConfirm: (answer: Answer, lineAnswer: LineAnswer) => void;
}) {
    const t = useTranslate();
    const [answer, setAnswer] = useState<Answer>({});
    const [lineAnswer, setLineAnswer] = useState<LineAnswer>({});

    const setLine = (l: SheetLine, key: 'sellable' | 'damaged', raw: string) =>
        setLineAnswer((a) => {
            const cur = a[l.id] ?? { sellable: 0, damaged: 0 };
            const other = key === 'sellable' ? cur.damaged : cur.sellable;
            return { ...a, [l.id]: { ...cur, [key]: clamp(Number(raw), l.quantity - other) } };
        });

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
                {pieces.length > 0 && (
                    <p className="text-[13px] text-[#6b7280]">
                        {t('ما عاد إلى الرفّ يُعكس مستحقُّه. وما لم يعد أو لم يُتحقَّق منه يبقى للبوتيك وخسارتُه على المتجر.')}
                    </p>
                )}
                {lines.length > 0 && (
                    <>
                        <p className="mt-3 text-[13px] text-[#6b7280]" data-testid="cancel-sheet-lines-hint">
                            {t('بنود في ورقة تسوية صدرت: لا تُعدَّل الورقة، ويُكتب إشعار مرتجع مرتبط بها. وما لم يُستلم يبقى خارج الرفّ حتى يُستلم.')}
                        </p>
                        <ul className="mt-3 space-y-3">
                            {lines.map((l) => {
                                const a = lineAnswer[l.id] ?? { sellable: 0, damaged: 0 };
                                return (
                                    <li key={l.id} className="rounded-[10px] border border-[var(--ui-border,#e8e8e8)] p-3" data-testid="cancel-sheet-line">
                                        <p className="text-sm font-medium">{l.name} × {l.quantity} <span className="text-[#6b7280]">— {t('ورقة :sheet', { sheet: l.sheet ?? '—' })}</span></p>
                                        <div className="mt-2 grid grid-cols-3 gap-2 text-[12px]">
                                            <label>{t('عادت سليمة')}
                                                <Input type="number" min="0" max={l.quantity} dir="ltr" value={String(a.sellable)}
                                                    onChange={(e) => setLine(l, 'sellable', e.target.value)} aria-label={t('عادت سليمة')} />
                                            </label>
                                            <label>{t('تالفة أو لم تعد')}
                                                <Input type="number" min="0" max={l.quantity} dir="ltr" value={String(a.damaged)}
                                                    onChange={(e) => setLine(l, 'damaged', e.target.value)} aria-label={t('تالفة أو لم تعد')} />
                                            </label>
                                            <p className="self-end pb-2" data-testid="cancel-sheet-line-open">
                                                {t('لم تُستلم بعد')}: {l.quantity - a.sellable - a.damaged}
                                            </p>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    </>
                )}
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
                    <Button variant="danger" onClick={() => onConfirm(answer, lineAnswer)} data-testid="cancel-confirm">{t('ألغِ الطلب')}</Button>
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

/** بعد المرتجع: قطعٌ من بنودٍ حملتها ورقةٌ لم تُستلم — كم عاد سليمًا وكم تالفًا */
export function AwaitingReturns({ orderNumber, notes }: { orderNumber: string; notes: SheetLine[] }) {
    const t = useTranslate();
    const [answer, setAnswer] = useState<LineAnswer>({});
    if (notes.length === 0) return null;

    const set = (n: SheetLine, key: 'sellable' | 'damaged', raw: string) =>
        setAnswer((a) => {
            const cur = a[n.id] ?? { sellable: 0, damaged: 0 };
            const other = key === 'sellable' ? cur.damaged : cur.sellable;
            return { ...a, [n.id]: { ...cur, [key]: clamp(Number(raw), n.quantity - other) } };
        });

    return (
        <Card className="p-4" data-testid="awaiting-boutique-returns">
            <p className="mb-2 text-sm font-semibold text-[#111]">{t('قطع بوتيك مرتجعة لم تُستلم بعد')}</p>
            <ul className="space-y-2 text-sm">
                {notes.map((n) => {
                    const a = answer[n.id] ?? { sellable: 0, damaged: 0 };
                    return (
                        <li key={n.id} className="flex flex-wrap items-center justify-between gap-2">
                            <span>{n.name} × {n.quantity} <span className="text-[#6b7280]">— {t('ورقة :sheet', { sheet: n.sheet ?? '—' })}</span></span>
                            <span className="flex items-center gap-2">
                                <Input className="w-16" type="number" min="0" max={n.quantity} dir="ltr" value={String(a.sellable)}
                                    aria-label={t('عادت سليمة')} onChange={(e) => set(n, 'sellable', e.target.value)} />
                                <Input className="w-16" type="number" min="0" max={n.quantity} dir="ltr" value={String(a.damaged)}
                                    aria-label={t('تالفة أو لم تعد')} onChange={(e) => set(n, 'damaged', e.target.value)} />
                                <Button size="sm" variant="outline" disabled={a.sellable + a.damaged === 0}
                                    onClick={() => router.post(route('admin.orders.boutique-returns', orderNumber),
                                        { note: n.id, sellable: a.sellable, damaged: a.damaged }, { preserveScroll: true })}>
                                    {t('سجّل الاستلام')}
                                </Button>
                            </span>
                        </li>
                    );
                })}
            </ul>
        </Card>
    );
}
