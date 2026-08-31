import { useEffect, useState } from 'react';
import { Check } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Label } from '@/Components/ui/label';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Addon, Product } from '@/types/models';
import type { CartAddon } from '@/hooks/usePosCart';

interface Props {
    product: Product | null;
    addons: Addon[];
    money: (value: number) => string;
    onClose: () => void;
    onConfirm: (choice: { variantId: number | null; variantName: string | null; price: number; addons: CartAddon[] }) => void;
}

/**
 * اختيار المقاس والإضافات قبل دخول السلّة.
 *
 * ولا تُفتح لكلّ منتج: بيعةُ المارّ يجب أن تبقى نقرةً واحدة. منتجٌ بلا
 * مقاسات وبلا إضافاتٍ مسموحة يدخل السلّة مباشرةً كما كان — ونافذةٌ تقفز عند
 * كلّ ضغطة تجعل الكاشير يغلقها بلا قراءة، فتفقد الإضافات معناها كلَّه.
 *
 * والسعر المعروض هنا للقراءة: الخادم يُعيد حسابه من القاعدة عند الدفع.
 */
export default function ItemOptionsDialog({ product, addons, money, onClose, onConfirm }: Props) {
    const t = useTranslate();
    const [variantId, setVariantId] = useState<number | null>(null);
    const [picked, setPicked] = useState<Record<number, number>>({});

    const variants = product?.variants ?? [];

    // أوّل مقاسٍ مختارٌ سلفًا: المنتج ذو المقاسات لا يُباع بلا مقاس، وتركُ
    // الاختيار فارغًا يجعل الكاشير يضغط «إضافة» فيُرفض بلا سبب ظاهر
    useEffect(() => {
        setVariantId(variants.length > 0 ? variants[0].id : null);
        setPicked({});
    }, [product?.id]);

    if (!product) {
        return null;
    }

    const allowed = addons.filter(
        (a) => a.active && (product.addon_ids == null || product.addon_ids.includes(a.id)),
    );

    const variant = variants.find((v) => v.id === variantId) ?? null;
    const basePrice = variant ? variant.price : product.price;
    const addonsTotal = Object.entries(picked).reduce((sum, [id, qty]) => {
        const addon = allowed.find((a) => a.id === Number(id));

        return sum + (addon ? addon.price * qty : 0);
    }, 0);

    const bump = (id: number, delta: number) =>
        setPicked((prev) => {
            const next = { ...prev };
            const value = (next[id] ?? 0) + delta;
            if (value <= 0) {
                delete next[id];
            } else {
                next[id] = value;
            }

            return next;
        });

    const confirm = () =>
        onConfirm({
            variantId: variant?.id ?? null,
            variantName: variant?.label ?? null,
            price: basePrice,
            addons: Object.entries(picked).map(([id, qty]) => {
                const addon = allowed.find((a) => a.id === Number(id))!;

                return { addon_id: addon.id, name: addon.label, price: addon.price, qty };
            }),
        });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            {/* سقفٌ للارتفاع وجسمٌ يمرّر: قائمةُ إضافاتٍ طويلة كانت تُخرج
                الزرّ خارج الشاشة على الجوّال */}
            <DialogContent className="flex max-h-[90dvh] max-w-md flex-col">
                <DialogHeader className="shrink-0 px-5 pt-5">
                    <DialogTitle>{product.label}</DialogTitle>
                    <DialogDescription>{t('اختر المقاس والإضافات')}</DialogDescription>
                </DialogHeader>

                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 pb-4">
                    {variants.length > 0 && (
                        <div>
                            <Label className="mb-2 block" required>
                                {t('المقاس')}
                            </Label>
                            <div className="grid grid-cols-2 gap-2">
                                {variants.map((v) => (
                                    <button
                                        key={v.id}
                                        type="button"
                                        onClick={() => setVariantId(v.id)}
                                        className={cn(
                                            'rounded-[12px] border px-3 py-3 text-start transition-colors',
                                            v.id === variantId
                                                ? 'border-gray-900 bg-gray-900 text-white'
                                                : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50',
                                        )}
                                    >
                                        <span className="block text-sm font-medium">{v.label}</span>
                                        <span className="block text-xs opacity-80">{money(v.price)}</span>
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    {allowed.length > 0 && (
                        <div>
                            <Label className="mb-2 block">{t('إضافات اختيارية')}</Label>
                            <ul className="space-y-2">
                                {allowed.map((a) => {
                                    const qty = picked[a.id] ?? 0;

                                    return (
                                        <li
                                            key={a.id}
                                            className="flex items-center justify-between gap-3 rounded-[12px] border border-gray-100 px-3 py-2"
                                        >
                                            <span className="min-w-0 text-sm text-gray-700">
                                                <span className="truncate">{a.label}</span>
                                                <span className="ms-2 text-xs font-bold text-[#7c3aed]">
                                                    +{money(a.price)}
                                                </span>
                                            </span>
                                            <span className="flex shrink-0 items-center gap-2">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="icon"
                                                    aria-label={t('إنقاص')}
                                                    disabled={qty === 0}
                                                    onClick={() => bump(a.id, -1)}
                                                >
                                                    −
                                                </Button>
                                                <span className="w-6 text-center text-sm font-semibold tabular-nums">
                                                    {qty}
                                                </span>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="icon"
                                                    aria-label={t('زيادة')}
                                                    onClick={() => bump(a.id, 1)}
                                                >
                                                    +
                                                </Button>
                                            </span>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    )}
                </div>

                <div className="shrink-0 border-t border-gray-100 px-5 pb-5 pt-4">
                    <div className="mb-3 flex items-center justify-between text-sm">
                        <span className="text-gray-500">{t('الإجمالي')}</span>
                        <span className="text-base font-bold text-gray-900">{money(basePrice + addonsTotal)}</span>
                    </div>
                    <Button type="button" size="lg" className="w-full" onClick={confirm}>
                        <Check />
                        {t('إضافة للسلة')}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
