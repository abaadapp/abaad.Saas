import { useState } from 'react';
import { Info } from 'lucide-react';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';

/**
 * وصفُ الصنف للكاشير — زرٌّ صغير على بطاقته يفتح نافذة.
 *
 * لا يُكتب الوصفُ على البطاقة نفسِها: شبكةُ الصندوق تُقرأ بلمحة، ووصفٌ طويلٌ
 * تحت كلّ صنفٍ يُضاعف طولها ويدفع الأسعار عن العين. ولا يُضاف إلى السلّة ولا
 * إلى الفاتورة ولا الإيصال: هو للكاشير يقرؤه، لا للزبون يُطبع له. ولا يختلط
 * بملاحظة الزبون ولا بتعليمات التجهيز — تلك في السلّة، وهذا على الصنف.
 *
 * والنصُّ يصل من الخادم بلغة الشاشة، و`null` حين لم يُفتح للصندوق
 * (`ProductDescription::atPos`) — فلا زرَّ بلا وصف.
 */
export default function ProductDescriptionButton({ name, text }: { name: string; text?: string | null }) {
    const t = useTranslate();
    const [open, setOpen] = useState(false);

    if (!text) return null;

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                aria-label={t('وصف المنتج')}
                title={t('وصف المنتج')}
                data-testid="pos-product-description"
                className="absolute start-2 top-2 z-10 flex size-7 items-center justify-center rounded-full bg-white/90 text-gray-700 shadow-sm transition-colors hover:bg-white"
            >
                <Info className="size-4" />
            </button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent data-testid="pos-product-description-dialog">
                    <DialogHeader>
                        <DialogTitle>{name}</DialogTitle>
                    </DialogHeader>
                    <p dir="auto" className="max-h-[50vh] overflow-y-auto whitespace-pre-line px-5 pb-5 text-sm leading-relaxed text-gray-700">
                        {text}
                    </p>
                </DialogContent>
            </Dialog>
        </>
    );
}
