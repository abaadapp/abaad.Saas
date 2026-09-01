import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { ImagePlus, Star, Trash2 } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface GalleryImage {
    /** null للرئيسية: ليست صفًّا في جدول الصور — وهو ما يميّزها */
    id: number | null;
    url: string;
    main: boolean;
    /** بديلُ النظام: صورةٌ تُعرض ولا يملكها أحد — لا تُحذف ولا تُحسب */
    placeholder?: boolean;
}

interface Props {
    productId: number | string;
    images: GalleryImage[];
    max: number;
    /** أقصى حجمٍ للصورة الواحدة بالكيلوبايت — يأتي من الخادم فلا يفترق الحدّان */
    maxKb: number;
}

/**
 * معرض صور المنتج — الرئيسية وما معها.
 *
 * وكلُّ فعلٍ هنا طلبٌ صغير بمساره: رفعٌ، وترقية، وحذف. لا يمرّ شيءٌ منها
 * بنموذج المنتج — ذاك يرسل السعر والكمية والوصف في كلّ حفظ ويكتب الكمية
 * مطلقةً، فمن بدّل صورةً بحفظه كتب فوق كلّ ما تغيّر بينه وبين فتحه الشاشة.
 *
 * والصفحة تُعاد قراءتها بعد كلّ فعل (`preserveScroll`)، فما تراه هو ما في
 * القاعدة لا ما خمّنته الشاشة.
 */
export default function Gallery({ productId, images, max, maxKb }: Props) {
    const t = useTranslate();
    const input = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    /** الصورة المرشّحة للحذف — والحذف لا يقع بضغطةٍ واحدة */
    const [confirming, setConfirming] = useState<GalleryImage | null>(null);

    /*
     * ما يملكه التاجر فعلًا — لا ما يُعرض.
     *
     * منتجٌ بلا صورةٍ يحمل بديل النظام في عموده، فيُعرض ويُعدّ لو قيس بالطول:
     * «١ / ٨» لمنتجٍ لا صورةَ له، وزرُّ حذفٍ لملفٍّ لا وجود له.
     */
    const stored = images.filter((i) => ! i.placeholder);
    const full = stored.length >= max;

    const upload = (files: FileList | null) => {
        if (! files || files.length === 0) return;

        const chosen = Array.from(files);

        /*
         * الحجم يُقاس هنا قبل الإرسال — لا لتوفير الوقت وحده.
         *
         * PHP يطرح ما تجاوز `upload_max_filesize` قبل أن تصل لارافيل، فلا
         * يرى التاجر رسالةَ رفض. وما تجاوز `post_max_size` أسوأ: يُلقى الطلبُ
         * كلُّه بما فيه رمزُ الحماية، فتظهر «انتهت صلاحية الصفحة» بدل سببٍ
         * مفهوم. والرفضُ من هنا يقول أيُّ ملفٍّ ثقيل، باسمه.
         */
        const heavy = chosen.filter((f) => f.size > maxKb * 1024);

        if (heavy.length > 0) {
            setError(
                t('صورةٌ أثقل من :n ميغابايت لا تُرفع: :names', {
                    n: Math.round(maxKb / 1024),
                    names: heavy.map((f) => f.name).join('، '),
                }),
            );
            if (input.current) input.current.value = '';

            return;
        }

        const data = new FormData();
        chosen.forEach((f) => data.append('images[]', f));

        setBusy(true);
        setError(null);
        router.post(route('admin.products.images.store', productId), data, {
            forceFormData: true,
            preserveScroll: true,
            onError: (errors) => setError(errors.images ?? Object.values(errors)[0] ?? null),
            onFinish: () => {
                setBusy(false);
                // الحقل يُفرَّغ وإلّا لم يُطلق اختيارُ الملفّ نفسه حدثًا ثانيًا
                if (input.current) input.current.value = '';
            },
        });
    };

    const promote = (image: GalleryImage) => {
        if (image.id === null) return;
        setBusy(true);
        router.post(
            route('admin.products.images.promote', [productId, image.id]),
            {},
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    const remove = (image: GalleryImage) => {
        setBusy(true);
        setConfirming(null);
        const url = image.id === null
            ? route('admin.products.images.destroyMain', productId)
            : route('admin.products.images.destroy', [productId, image.id]);

        router.delete(url, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    return (
        <Card className="p-6">
            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 className="font-bold text-[#111]">{t('صور المنتج')}</h3>
                    <p className="mt-1 text-[13px] text-[#6b7280]">
                        {t('الرئيسية هي التي تظهر في نقطة البيع والقائمة والفاتورة — والباقي يُعرض في صفحة المنتج.')}
                    </p>
                </div>
                <span className="shrink-0 text-[12px] text-[#9ca3af]">
                    {stored.length} / {max}
                </span>
            </div>

            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                {images.map((image) => (
                    <div
                        key={image.id ?? 'main'}
                        className={cn(
                            'group relative aspect-square overflow-hidden rounded-[12px] border bg-[#fafafa]',
                            image.main && ! image.placeholder
                                ? 'border-[#8b5cf6] ring-2 ring-[#8b5cf6]/20'
                                : 'border-[var(--ui-border,#e8e8e8)]',
                        )}
                    >
                        <img src={image.url} alt="" className="size-full object-cover" />

                        {image.main && (
                            <span
                                className={cn(
                                    'absolute start-2 top-2 flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium text-white',
                                    image.placeholder ? 'bg-[#9ca3af]' : 'bg-[#8b5cf6]',
                                )}
                            >
                                <Star className="size-3" />
                                {image.placeholder ? t('بديل مؤقّت') : t('الرئيسية')}
                            </span>
                        )}

                        {/*
                            الأزرار تظهر عند المرور — والشاشة اللمسية لا تمرّ،
                            فتبقى ظاهرةً عليها دائمًا (focus-within يُبقيها
                            لمن يتنقّل بلوحة المفاتيح كذلك).
                        */}
                        {/* والبديل لا زرَّ له: لا يُحذف ما ليس بملفّ، ولا
                            تُرقّى صورةٌ هي رئيسيةٌ أصلًا */}
                        <div
                            className={cn(
                                'absolute inset-x-0 bottom-0 flex justify-center gap-1.5 bg-black/50 p-2 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100 max-md:opacity-100',
                                image.placeholder && 'hidden',
                            )}
                        >
                            {! image.main && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => promote(image)}
                                    title={t('اجعلها الرئيسية')}
                                >
                                    <Star className="size-4" />
                                </Button>
                            )}
                            <Button
                                type="button"
                                size="sm"
                                variant="danger"
                                disabled={busy}
                                onClick={() => setConfirming(image)}
                                title={t('حذف الصورة')}
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        </div>
                    </div>
                ))}

                {/* والزرّ يختفي عند السقف بدل أن يقبل الملفّ ثمّ يردّه: رفضٌ
                    بعد انتظار رفعِ أربعة ميغابايت أسوأ من زرٍّ لا يُعرض */}
                {! full && (
                    <label
                        className={cn(
                            'flex aspect-square cursor-pointer flex-col items-center justify-center gap-2 rounded-[12px] border-2 border-dashed border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] text-[#9ca3af] transition-colors hover:border-[#8b5cf6]',
                            busy && 'pointer-events-none opacity-60',
                        )}
                    >
                        <ImagePlus className="size-7" />
                        <span className="px-2 text-center text-[12px]">
                            {stored.length === 0 ? t('أضف صورة') : t('أضف صورة أخرى')}
                        </span>
                        <input
                            ref={input}
                            type="file"
                            hidden
                            multiple
                            accept="image/*"
                            onChange={(e) => upload(e.target.files)}
                        />
                    </label>
                )}
            </div>

            {full && (
                <p className="mt-3 text-[12px] text-[#9ca3af]">
                    {t('بلغتَ الحدّ الأقصى — احذف صورةً لتضيف غيرها.')}
                </p>
            )}

            {error && <p className="mt-3 text-[12px] text-[#b91c1c]">{error}</p>}

            <Dialog open={!! confirming} onOpenChange={(open) => ! open && setConfirming(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{t('حذف الصورة')}</DialogTitle>
                        <DialogDescription>
                            {/* والخلافة تُقال قبل الحذف لا بعده: من يحذف الرئيسية
                                يريد أن يعرف ماذا سيظهر مكانها */}
                            {confirming?.main
                                ? stored.length > 1
                                    ? t('ستصير الصورة التالية هي الرئيسية. لن تتأثّر بقيّة بيانات المنتج.')
                                    : t('لن تبقى للمنتج صورة. لن تتأثّر بقيّة بيانات المنتج.')
                                : t('تُحذف هذه الصورة وحدها. لن تتأثّر بقيّة بيانات المنتج.')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => setConfirming(null)}>
                            {t('إلغاء')}
                        </Button>
                        <Button
                            type="button"
                            variant="danger"
                            loading={busy}
                            onClick={() => confirming && remove(confirming)}
                        >
                            <Trash2 />
                            {t('حذف')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
