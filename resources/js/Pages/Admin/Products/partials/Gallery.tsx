import { useEffect, useRef, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, ImageOff, ImagePlus, Star, Trash2 } from 'lucide-react';
import { needsShrink, shrink } from '@/Components/StoreImageField';
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

/**
 * حدودُ الرفع بالكيلوبايت كما يسمح بها الخادمُ فعلًا — تأتي منه فلا
 * يفترق ما تعِد به الشاشةُ عمّا يقبله الباب. انظر `ProductImages::uploadLimits`.
 */
export interface UploadLimits {
    perFile: number;
    batch: number;
}

/** صورةٌ اختيرت في شاشة الإضافة ولم تُحفظ بعد — ملفُّها ورابطُ معاينتها */
export interface DraftImage {
    file: File;
    url: string;
}

type Translate = ReturnType<typeof useTranslate>;

const mb = (kb: number) => Math.round((kb / 1024) * 10) / 10;

/** أطولُ ضلعٍ تُحفظ به صورةُ المنتج — كما في `ProductImages::MAX_SIDE` */
export const PHOTO_SIDE = 1600;

/**
 * الصورُ مُعدّةً للإرسال: الثقيلةُ تُصغَّر هنا قبل أن تُرفع.
 *
 * حدُّ الملفّ على الخادم اليوم اثنا ميغابايت، وصورةُ الهاتف ثلاثةٌ أو أربعة —
 * فلو أُرسلت كما هي لَرُدّت أكثرُ صور التجّار. فتُصغَّر بنسبتها إلى ضلعِ
 * الحفظ نفسه (`PHOTO_SIDE`)، ولا يُرى منها على الخادم أكثرُ من ذلك أصلًا.
 *
 * والحدُّ لكلّ صورةٍ نصيبُها من الطلب لا حدُّ الملفّ وحده: خمسٌ كلٌّ منها
 * دون اثنين قد تتجاوز حدَّ الطلب مجتمعةً. وما دون النصيب يُرسل كما هو —
 * والخادمُ يضغطه — فلا يُضغط ما لا حاجة إلى ضغطه مرّتين.
 *
 * وما تعذّر تصغيرُه (صيغةٌ لا يفكّها المتصفّح) يبقى كما هو، فيقول
 * `pickProblem` إنّه ثقيلٌ باسمه.
 */
export async function prepared(files: File[], limits: UploadLimits, max: number): Promise<File[]> {
    const share = Math.min(limits.perFile, Math.floor(limits.batch / Math.max(1, max))) * 1024;

    return Promise.all(files.map((f) => (needsShrink(f, share) ? shrink(f, PHOTO_SIDE) : f)));
}

/**
 * ما يمنع هذه الدفعة قبل أن تُرسل — أو `null`.
 *
 * وقاعدةٌ واحدة للبابين — المعرضِ في التعديل والمسوّدةِ في الإضافة — كي لا
 * يقبل أحدُهما ما يردّه الآخر.
 *
 * والعددُ أوّلًا: الخادمُ يردّ السادسة (`ProductImages::assertRoom`)، وردُّها
 * بعد أن رُفعت الدفعةُ كلُّها على شبكة هاتفٍ انتظارٌ بلا ثمرة.
 *
 * والحجمُ بعده — لا لتوفير الوقت وحده. PHP يطرح ما تجاوز
 * `upload_max_filesize` قبل أن تصل لارافيل، فلا يرى التاجر رسالةَ رفض. وما
 * تجاوز `post_max_size` أسوأ: يُلقى الطلبُ كلُّه بما فيه رمزُ الحماية، فتظهر
 * «انتهت صلاحية الصفحة» بدل سببٍ مفهوم. والرفضُ من هنا يقول أيُّ ملفٍّ ثقيل،
 * باسمه.
 *
 * و`heldBytes` ما ينتظر الإرسالَ معها: في الإضافة تُرسل الصورُ كلُّها في
 * حفظةٍ واحدة، فتُقاس الجديدةُ مع ما اختير قبلها.
 */
export function pickProblem(
    chosen: File[],
    { have, max, limits, heldBytes = 0, draft = false }: {
        have: number;
        max: number;
        limits: UploadLimits;
        heldBytes?: number;
        draft?: boolean;
    },
    t: Translate,
): string | null {
    const room = max - have;

    if (room <= 0) {
        return t('بلغتَ الحدّ الأقصى — احذف صورةً لتضيف غيرها.');
    }

    if (chosen.length > room) {
        return t('اخترتَ :got صور، والمتبقّي :room من :max.', { got: chosen.length, room, max });
    }

    const heavy = chosen.filter((f) => f.size > limits.perFile * 1024);

    if (heavy.length > 0) {
        return t('صورةٌ أثقل من :n ميغابايت لا تُرفع: :names', {
            n: mb(limits.perFile),
            names: heavy.map((f) => f.name).join('، '),
        });
    }

    /*
     * ومجموعُ الدفعة يُقاس كذلك: ثلاثُ صورٍ كلٌّ منها دون الحدّ قد تتجاوز
     * `post_max_size` مجتمعةً — وعندها يُلقى الطلبُ كلُّه بلا رسالة.
     *
     * ففي المعرض يُقال: ارفعها على دفعات. وفي الإضافة الحفظةُ واحدة — فيُقال
     * ما يُفعل بدلها: صورةٌ أقلّ أو أخفّ، أو تُضاف البقيّةُ بعد الحفظ.
     */
    const total = heldBytes + chosen.reduce((sum, f) => sum + f.size, 0);

    if (total > limits.batch * 1024) {
        const values = { got: mb(Math.round(total / 1024)), n: mb(limits.batch) };

        return draft
            ? t('مجموع الصور :got ميغابايت، وأقصى ما يُحفظ مرّةً واحدة :n — احذف صورةً أو اختر أخفّ منها، أو أضف الباقي بعد الحفظ.', values)
            : t('مجموع الصور المختارة :got ميغابايت، والحدّ :n — ارفعها على دفعات.', values);
    }

    return null;
}

/**
 * ما يُقال تحت الصور حين لا يُضاف شيء.
 *
 * والصنفُ القديمُ فوق السقف يُقال له ما يقع: صورُه باقيةٌ كلُّها ولم يُحذف
 * منها شيء — وإلّا ظنّ أنّ السقف الجديد سيأخذ منها، أو أنّ الزرّ تعطّل.
 */
function capNotice(count: number, max: number, t: Translate): string | null {
    if (count > max) {
        return t('لهذا المنتج :have صور، أكثر من الحدّ الحالي (:max). تبقى كلّها كما هي، ولا تُضاف صورةٌ جديدة حتى تحذف ما يُنزل عددها دون الحدّ.', { have: count, max });
    }

    return count >= max ? t('بلغتَ الحدّ الأقصى — احذف صورةً لتضيف غيرها.') : null;
}

interface FrameProps {
    count: number;
    max: number;
    busy: boolean;
    addLabel: string;
    onPick: (files: File[]) => void;
    /** سطرٌ تحت الصور — يقول ما يُضاف، أو لماذا لا يُضاف شيء */
    notice?: string | null;
    error?: string | null;
    children: ReactNode;
}

/**
 * إطارُ المعرض — العنوانُ والعدّاد والمربّعات وزرُّ الإضافة.
 *
 * واحدٌ للبابين: المعرضُ في التعديل يرفع ويحذف بطلباته، والمسوّدةُ في الإضافة
 * تحفظ ما اختير حتى يُحفظ المنتج. والشكلُ واحد كي لا يتعلّم التاجرُ شاشتين
 * للشيء نفسه.
 *
 * والعدّادُ من اليسار إلى اليمين: «٣ / ٥» في سطرٍ عربيّ يُقلب فيُقرأ «٥ / ٣».
 */
function Frame({ count, max, busy, addLabel, onPick, notice, error, children }: FrameProps) {
    const t = useTranslate();

    return (
        <Card className="p-6">
            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 className="font-bold text-[#111]">{t('صور المنتج')}</h3>
                    <p className="mt-1 text-[13px] text-[#6b7280]">
                        {t('الصورة الرئيسية تظهر في قائمة المنتجات، والصور الإضافية تظهر داخل صفحة المنتج.')}
                    </p>
                </div>
                <span className="shrink-0 text-[12px] text-[#9ca3af]" data-testid="gallery-count" dir="ltr">
                    {count} / {max}
                </span>
            </div>

            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                {children}

                {/* والزرّ يختفي عند السقف بدل أن يقبل الملفّ ثمّ يردّه: رفضٌ
                    بعد انتظار رفعِ أربعة ميغابايت أسوأ من زرٍّ لا يُعرض */}
                {count < max && (
                    <label
                        className={cn(
                            'flex aspect-square cursor-pointer flex-col items-center justify-center gap-2 rounded-[12px] border-2 border-dashed border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] text-[#9ca3af] transition-colors hover:border-[#8b5cf6]',
                            busy && 'pointer-events-none opacity-60',
                        )}
                    >
                        <ImagePlus className="size-7" />
                        <span className="px-2 text-center text-[12px]">{addLabel}</span>
                        <input
                            type="file"
                            hidden
                            multiple
                            accept="image/*"
                            data-testid="gallery-input"
                            onChange={(e) => {
                                const files = Array.from(e.target.files ?? []);
                                // الحقل يُفرَّغ وإلّا لم يُطلق اختيارُ الملفّ نفسه حدثًا ثانيًا
                                e.target.value = '';
                                if (files.length > 0) onPick(files);
                            }}
                        />
                    </label>
                )}
            </div>

            {notice && <p className="mt-3 text-[12px] text-[#9ca3af]">{notice}</p>}

            {error && <p className="mt-3 text-[12px] text-[#b91c1c]" role="alert">{error}</p>}
        </Card>
    );
}

interface TileProps {
    url: string;
    main: boolean;
    placeholder?: boolean;
    busy: boolean;
    onPromote: () => void;
    onRemove: () => void;
    /** تقديمُها خطوةً وتأخيرُها — يُترك لما لا يتحرّك في ذلك الاتّجاه */
    onEarlier?: () => void;
    onLater?: () => void;
}

/** مربّعُ صورةٍ واحدة — بعلامة الرئيسية وزرَّي الترقية والحذف، وسهمَي الترتيب */
function Tile({ url, main, placeholder = false, busy, onPromote, onRemove, onEarlier, onLater }: TileProps) {
    const t = useTranslate();
    const reveal = 'opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100 max-md:opacity-100';

    return (
        <div
            data-testid="gallery-tile"
            data-main={main ? 'true' : 'false'}
            className={cn(
                'group relative aspect-square overflow-hidden rounded-[12px] border bg-[#fafafa]',
                main && ! placeholder
                    ? 'border-[#8b5cf6] ring-2 ring-[#8b5cf6]/20'
                    : 'border-[var(--ui-border,#e8e8e8)]',
            )}
        >
            {/*
                والبديلُ لا صورةَ له.

                كان `url` رابطًا عشوائيًّا من `picsum.photos` يُعرض
                صورةً للمنتج — فيرى التاجر شيئًا لا يبيعه معلَّمًا
                «بديل مؤقّت». وصار الفراغُ فراغًا: مربّعٌ ساكنٌ
                يقول ما هو.
            */}
            {placeholder ? (
                <span className="flex size-full items-center justify-center text-3xl text-[#d4d4d8]">
                    <ImageOff className="size-8" />
                </span>
            ) : (
                <img src={url} alt="" className="size-full object-cover" />
            )}

            {main && (
                <span
                    className={cn(
                        'absolute start-2 top-2 flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium text-white',
                        placeholder ? 'bg-[#9ca3af]' : 'bg-[#8b5cf6]',
                    )}
                >
                    <Star className="size-3" />
                    {placeholder ? t('بديل مؤقّت') : t('الرئيسية')}
                </span>
            )}

            {/*
                وسهما الترتيب في الزاوية المقابلة لعلامة الرئيسية — لا في
                شريط الأزرار: مربّعٌ بعرض نصف هاتف لا يتّسع لأربعة أزرار.

                والسهمُ يشير إلى حيث تذهب الصورة: «قبلها» يمينٌ في العربيّة
                ويسارٌ في الإنجليزيّة (`ltr:rotate-180`).
            */}
            {(onEarlier || onLater) && (
                <div className={cn('absolute end-2 top-2 flex gap-1', reveal)}>
                    {onEarlier && (
                        <Button
                            type="button"
                            size="icon-sm"
                            variant="outline"
                            disabled={busy}
                            onClick={onEarlier}
                            title={t('قدّمها')}
                            aria-label={t('قدّمها')}
                            data-testid="gallery-earlier"
                        >
                            <ChevronRight className="ltr:rotate-180" />
                        </Button>
                    )}
                    {onLater && (
                        <Button
                            type="button"
                            size="icon-sm"
                            variant="outline"
                            disabled={busy}
                            onClick={onLater}
                            title={t('أخّرها')}
                            aria-label={t('أخّرها')}
                            data-testid="gallery-later"
                        >
                            <ChevronLeft className="ltr:rotate-180" />
                        </Button>
                    )}
                </div>
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
                    'absolute inset-x-0 bottom-0 flex justify-center gap-1.5 bg-black/50 p-2',
                    reveal,
                    placeholder && 'hidden',
                )}
            >
                {! main && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={busy}
                        onClick={onPromote}
                        title={t('اجعلها الرئيسية')}
                        aria-label={t('اجعلها الرئيسية')}
                    >
                        <Star className="size-4" />
                    </Button>
                )}
                <Button
                    type="button"
                    size="sm"
                    variant="danger"
                    disabled={busy}
                    onClick={onRemove}
                    title={t('حذف الصورة')}
                    aria-label={t('حذف الصورة')}
                >
                    <Trash2 className="size-4" />
                </Button>
            </div>
        </div>
    );
}

interface Props {
    productId: number | string;
    images: GalleryImage[];
    max: number;
    limits: UploadLimits;
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
export default function Gallery({ productId, images, max, limits }: Props) {
    const t = useTranslate();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    /** الصورة المرشّحة للحذف — والحذف لا يقع بضغطةٍ واحدة */
    const [confirming, setConfirming] = useState<GalleryImage | null>(null);

    /*
     * ما يملكه التاجر فعلًا — لا ما يُعرض.
     *
     * منتجٌ بلا صورةٍ يحمل بديل النظام في عموده، فيُعرض ويُعدّ لو قيس بالطول:
     * «١ / ٥» لمنتجٍ لا صورةَ له، وزرُّ حذفٍ لملفٍّ لا وجود له.
     */
    const stored = images.filter((i) => ! i.placeholder);

    const upload = async (picked: File[]) => {
        setBusy(true);
        const chosen = await prepared(picked, limits, max);
        const problem = pickProblem(chosen, { have: stored.length, max, limits }, t);

        if (problem) {
            setError(problem);
            setBusy(false);

            return;
        }

        const data = new FormData();
        chosen.forEach((f) => data.append('images[]', f));

        setError(null);
        router.post(route('admin.products.images.store', productId), data, {
            forceFormData: true,
            preserveScroll: true,
            onError: (errors) => setError(errors.images ?? Object.values(errors)[0] ?? null),
            onFinish: () => setBusy(false),
        });
    };

    /*
     * الترتيبُ لصور المعرض وحدها — ما بعد الرئيسية. والرئيسيةُ تُبدَّل
     * بالنجمة: تقديمُ صورةٍ إلى موضعها يعني ترقيتَها، وذاك بابُه.
     */
    const gallery = images.filter((i) => i.id !== null);

    const move = (at: number, by: -1 | 1) => {
        const ids = gallery.map((i) => i.id as number);
        [ids[at], ids[at + by]] = [ids[at + by], ids[at]];

        setBusy(true);
        setError(null);
        router.put(route('admin.products.images.order', productId), { ids }, {
            preserveScroll: true,
            onError: (errors) => setError(errors.images ?? Object.values(errors)[0] ?? null),
            onFinish: () => setBusy(false),
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
        <>
            <Frame
                count={stored.length}
                max={max}
                busy={busy}
                addLabel={stored.length === 0 ? t('أضف صورة') : t('أضف صورة أخرى')}
                onPick={upload}
                notice={capNotice(stored.length, max, t)}
                error={error}
            >
                {images.map((image) => {
                    const at = gallery.indexOf(image);

                    return (
                        <Tile
                            key={image.id ?? 'main'}
                            url={image.url}
                            main={image.main}
                            placeholder={image.placeholder}
                            busy={busy}
                            onPromote={() => promote(image)}
                            onRemove={() => setConfirming(image)}
                            onEarlier={at > 0 ? () => move(at, -1) : undefined}
                            onLater={at >= 0 && at < gallery.length - 1 ? () => move(at, 1) : undefined}
                        />
                    );
                })}
            </Frame>

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
        </>
    );
}

/**
 * روابطُ معاينة المسوّدة تُطلق حين تُغلق الشاشة.
 *
 * كلُّ رابطٍ يُبقي ملفَّه في ذاكرة المتصفّح. ومن اختار خمس صورٍ بأربعة
 * ميغابايت ثمّ خرج، يترك عشرين ميغابايت لا يُشير إليها شيء حتى تُغلق النافذة.
 */
export function useDraftUrlsReleased(drafts: DraftImage[]) {
    const latest = useRef(drafts);
    latest.current = drafts;
    useEffect(() => () => latest.current.forEach((d) => URL.revokeObjectURL(d.url)), []);
}

interface DraftProps {
    /** ما اختير بترتيبه — الأولى هي الرئيسية */
    drafts: DraftImage[];
    max: number;
    limits: UploadLimits;
    onChange: (next: DraftImage[]) => void;
    /** ما ردّه الخادم عن الصور — يُعرض تحتها لا في قسمٍ آخر */
    error?: string | null;
}

/**
 * صورُ المنتج في شاشة الإضافة — تُختار وتُرتَّب هنا، وتُرسل مع المنتج.
 *
 * كانت الإضافةُ تقبل صورةً واحدة، ويُقال للتاجر «احفظ أوّلًا ثمّ أضف
 * البقيّة» — خطوتان وشاشتان لصنفٍ واحد. فصار المعرضُ نفسُه هنا قبل الحفظ:
 * يختار حتى السقف دفعةً أو دفعات، ويرى ما اختار، ويحذف ما لا يريد، ويختار
 * الرئيسية — ولا يُرفع شيءٌ حتى يحفظ.
 *
 * والترتيبُ هو القاعدة: الأولى رئيسيةٌ (`image`) والباقي معرضٌ (`images`)
 * — كما يحفظها الخادم. واختيارُ الرئيسية تبديلٌ كما في المعرض: المختارةُ
 * تصعد، والرئيسيةُ القديمة تنزل مكانها.
 *
 * والحذفُ هنا بلا سؤال: صورةٌ لم تُحفظ تُختار ثانيةً بضغطة، وسؤالٌ عنها
 * يُبطئ من يرتّب خمسَ صور.
 *
 * والمسوّدةُ يملكها النموذجُ لا هذا المكوّن: قسمُ الصور يُطوى حين يُفتح
 * غيرُه، ولو مُحيت معه لَفقد التاجرُ ما اختاره بالتنقّل بين الأقسام. فروابطُ
 * المعاينة تُطلق حين يُغلق النموذج (`useDraftUrlsReleased`) لا حين يُطوى هذا.
 */
export function DraftGallery({ drafts, max, limits, onChange, error }: DraftProps) {
    const t = useTranslate();
    const [problem, setProblem] = useState<string | null>(null);
    /** الصورُ تُصغَّر — والزرّ ينتظر كي لا تُختار دفعةٌ فوق دفعةٍ لم تُعدّ */
    const [working, setWorking] = useState(false);

    const pick = async (picked: File[]) => {
        setWorking(true);
        const chosen = await prepared(picked, limits, max);
        setWorking(false);

        const heldBytes = drafts.reduce((sum, d) => sum + d.file.size, 0);
        const reason = pickProblem(chosen, { have: drafts.length, max, limits, heldBytes, draft: true }, t);

        setProblem(reason);
        if (reason) return;

        onChange([...drafts, ...chosen.map((file) => ({ file, url: URL.createObjectURL(file) }))]);
    };

    const promote = (index: number) => {
        const next = [...drafts];
        [next[0], next[index]] = [next[index], next[0]];
        onChange(next);
    };

    /* والترتيبُ يمرّ بالأولى أيضًا: ما قُدِّم إليها صار الرئيسية — الأولى هي الرئيسية */
    const move = (index: number, by: -1 | 1) => {
        const next = [...drafts];
        [next[index], next[index + by]] = [next[index + by], next[index]];
        onChange(next);
    };

    const remove = (index: number) => {
        URL.revokeObjectURL(drafts[index].url);
        setProblem(null);
        onChange(drafts.filter((_, i) => i !== index));
    };

    return (
        <Frame
            count={drafts.length}
            max={max}
            busy={working}
            addLabel={working ? t('تجهيز الصور…') : drafts.length === 0 ? t('اختر صورة أو أكثر') : t('أضف صورة أخرى')}
            onPick={pick}
            notice={
                drafts.length >= max
                    ? capNotice(drafts.length, max, t)
                    : t('حتى :max صور، تُحفظ مع المنتج. الأولى هي الرئيسية — بدّلها بالنجمة، ورتّب الصور بالسهمين.', { max })
            }
            error={problem ?? error}
        >
            {drafts.map((draft, i) => (
                <Tile
                    key={draft.url}
                    url={draft.url}
                    main={i === 0}
                    busy={working}
                    onPromote={() => promote(i)}
                    onRemove={() => remove(i)}
                    onEarlier={i > 0 ? () => move(i, -1) : undefined}
                    onLater={i < drafts.length - 1 ? () => move(i, 1) : undefined}
                />
            ))}
        </Frame>
    );
}
