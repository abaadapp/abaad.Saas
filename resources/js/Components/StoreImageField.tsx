import { router, usePage } from '@inertiajs/react';
import { ImagePlus, Loader2, Upload, X } from 'lucide-react';
import { useRef, useState } from 'react';

import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * حقلُ صورةٍ في إعدادات المتجر — يرفع ويعيد رابطًا.
 *
 * ═══ ولمَ رابطٌ لا ملفّ في النموذج ═══
 *
 * نموذجُ المتجر يُرسَل JSON ويُحفظ بضغطةٍ واحدة. وإدخالُ ملفٍّ فيه يقلبه
 * `multipart` كلَّه من أجل حقلين — وقد كُتب درسُه في هذا المستودع مرّتين:
 * `FormData` تكتب `null` نصًّا فارغًا، فيمحو الحفظُ أعمدةً لم تُمسّ.
 *
 * فالرفعُ ببابه (`marketing.store.image`)، وما يعود رابطٌ يُحفظ نصًّا.
 *
 * ═══ والمعاينةُ بإطار موضعها وطريقةِ عرضها ═══
 *
 * الصورةُ تُرى هنا في إطارٍ بنسبة موضعها في المتجر (`ratio`) وبطريقة عرضها:
 * «الصورة كاملة» تُريها كلَّها بنسبتها على لونٍ هادئ، و«ملء الإطار» تقصّ ما
 * زاد من طرفيها — فيرى صاحبُ المتجر قبل أن يحفظ ما سيراه زبونُه. وكلُّ صورةٍ
 * تُرفع من جديد تبدأ «كاملة» (`onFitChange`)، وما رُفع قبلُ يبقى كما كان.
 *
 * ═══ والكبيرةُ تُصغَّر قبل أن تُرسَل ═══
 *
 * صورةُ هاتفٍ حديث تتجاوز حدَّ الخادم (`uploadMax`)، وما يتجاوزه لا يبلغ الخادمَ
 * أصلًا فيُردّ بخطأٍ لا يقول شيئًا. فتُصغَّر هنا بنسبتها حتّى يبلغ ضلعُها الأطول
 * `MAX_SIDE`، ويُصحّح المتصفّحُ اتّجاهَها. وما تعذّر تصغيرُه يُرسَل كما هو،
 * والخادمُ يقول ما يقبل. وما يصل يُفحص هناك من محتواه لا من امتداده.
 */

/** أطولُ ضلعٍ يُرسَل — كما في `StoreImageUpload::MAX_SIDE` */
export const MAX_SIDE = 2560;

/** وما يُصغَّر قبل أن يُرسَل: صورٌ يفكّها المتصفّح ويعيد كتابتها */
const SHRINKABLE = ['image/jpeg', 'image/png', 'image/webp'];

export type ImageFit = 'contain' | 'cover';

/** طريقةُ العرض كما تُرسم — والفراغُ «ملء الإطار» كما كانت الصورُ قبل أن يُسأل */
export function fitOf(raw: string | undefined): ImageFit {
    return raw === 'contain' ? 'contain' : 'cover';
}

/** أيُصغَّر هذا الملفُّ قبل أن يُرسل؟ — كبيرٌ على الحدّ، ومن صيغةٍ يعيد المتصفّحُ كتابتها */
export function needsShrink(file: Pick<File, 'size' | 'type'>, max: number): boolean {
    return file.size > max && SHRINKABLE.includes(file.type);
}

/** مقاسٌ بنسبته حتّى يبلغ ضلعُه الأطول `side` — ولا يُكبَّر الصغير */
export function scaled(w: number, h: number, side = MAX_SIDE): { w: number; h: number } {
    const long = Math.max(w, h);

    if (long <= side) return { w, h };

    const k = side / long;

    return { w: Math.max(1, Math.round(w * k)), h: Math.max(1, Math.round(h * k)) };
}

/** شكلُ الصورة من أبعادها — يُقال إلى جانب المعاينة */
export function shapeOf(w: number, h: number): 'square' | 'portrait' | 'landscape' {
    if (Math.abs(w - h) / Math.max(w, h) < 0.04) return 'square';

    return h > w ? 'portrait' : 'landscape';
}

/**
 * الملفُّ مصغَّرًا بنسبته — أو الأصلُ إن تعذّر.
 *
 * و`createImageBitmap` بـ`from-image` يُدير الصورةَ كما يقول رأسُها، فيُكتب
 * ما يُرسَل مُدارًا بلا رأس. وPNG يبقى PNG (فيه الشفافيّة)، وما سواه JPEG.
 */
async function shrink(file: File): Promise<File> {
    if (typeof createImageBitmap !== 'function' || typeof document === 'undefined') return file;

    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const size = scaled(bitmap.width, bitmap.height);
        const canvas = document.createElement('canvas');
        canvas.width = size.w;
        canvas.height = size.h;
        const ctx = canvas.getContext('2d');

        if (! ctx) return file;

        ctx.drawImage(bitmap, 0, 0, size.w, size.h);
        bitmap.close?.();

        const type = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
        const blob = await new Promise<Blob | null>((done) => canvas.toBlob(done, type, 0.88));

        if (! blob) return file;

        const name = file.name.replace(/\.[^.]+$/, '') + (type === 'image/png' ? '.png' : '.jpg');

        return new File([blob], name, { type });
    } catch {
        return file;
    }
}

export default function StoreImageField({
    label,
    hint,
    value,
    onChange,
    fit,
    onFitChange,
    ratio = '1 / 1',
    name,
}: {
    label: string;
    hint?: string;
    value: string;
    onChange: (url: string) => void;
    /** طريقةُ عرضها المحفوظة — وبلا `onFitChange` لا يُسأل عنها هنا */
    fit?: string;
    onFitChange?: (fit: ImageFit) => void;
    /** نسبةُ إطارها في المتجر — `4 / 5` لبطاقة الشريط، `5 / 4` للواجهة */
    ratio?: string;
    /** بادئةُ معرّفات الاختبار — لحقلين في صفٍّ واحد */
    name?: string;
}) {
    const t = useTranslate();
    const input = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [meta, setMeta] = useState<{ w: number; h: number } | null>(null);
    const max = Number((usePage().props as { uploadMax?: number }).uploadMax ?? 10 * 1024 * 1024);
    const mb = (max / 1024 / 1024).toFixed(1).replace(/\.0$/, '');
    const shown = fitOf(fit);
    const id = name ?? 'image';

    const send = (file: File) => {
        if (file.size > max) {
            setBusy(false);
            setError(t('الصورة أكبر من :mb ميغابايت — صغّرها ثمّ ارفعها.', { mb }));
            if (input.current) input.current.value = '';

            return;
        }

        router.post(
            route('admin.marketing.store.image'),
            { image: file },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    const flash = (page.props as unknown as { flash?: { uploaded?: string | null; uploaded_meta?: { w: number; h: number } | null } }).flash;
                    const url = flash?.uploaded;

                    if (url) {
                        onChange(url);
                        setMeta(flash?.uploaded_meta ?? null);
                        // وكلُّ صورةٍ جديدةٍ تبدأ «كاملة» — لا يُقصّ منها شيءٌ حتّى يختار
                        onFitChange?.('contain');
                    }
                },
                onError: (errors) => setError(Object.values(errors)[0] ?? t('تعذّر رفع الصورة')),
                onFinish: () => {
                    setBusy(false);
                    if (input.current) input.current.value = '';
                },
            },
        );
    };

    const pick = (file: File | undefined) => {
        if (! file) return;

        setBusy(true);
        setError(null);

        // وما يسعه الحدُّ يُرسَل في الحال كما هو — والكبيرُ يُصغَّر أوّلًا
        if (needsShrink(file, max)) {
            void shrink(file).then(send);
        } else {
            send(file);
        }
    };

    const shapes = { square: 'مربّعة', portrait: 'طوليّة', landscape: 'عرضيّة' } as const;

    return (
        <Field label={label} hint={hint} error={error ?? undefined}>
            <div className="space-y-2">
                <div className="flex items-center gap-3">
                    {value ? (
                        <span
                            className="flex w-20 shrink-0 items-center justify-center overflow-hidden rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-[#efe8de]"
                            style={{ aspectRatio: ratio }}
                            data-testid={`${id}-frame`}
                        >
                            <img
                                src={value}
                                alt=""
                                className="block size-full"
                                style={{ objectFit: shown }}
                                data-fit={shown}
                            />
                        </span>
                    ) : (
                        <span className="flex size-16 shrink-0 items-center justify-center rounded-[10px] border border-dashed border-[var(--ui-border,#e8e8e8)] text-[#9ca3af]">
                            <ImagePlus className="size-5" />
                        </span>
                    )}

                    {/*
                        ═══ والزرُّ يُرسم ولا يُترك للمتصفّح ═══

                        `<input type=file>` يرسمه المتصفّحُ بنفسه: زرٌّ مكتوبٌ
                        «Choose File» وبجانبه «No file chosen» — **بالإنجليزية
                        مهما كانت لغةُ اللوحة**، ولا تبدّله `file:` التي لا تمسّ
                        إلّا الزرّ. فكانت لوحةٌ عربيّةٌ كلُّها تحمل سطرًا
                        إنجليزيًّا واحدًا في موضعٍ يرفع فيه التاجرُ صورةَ متجره.

                        فالحقلُ يُخفى ويُنادى من زرٍّ من نظام التصميم نفسِه،
                        واسمُ الملفّ المختار يُقال بالعربية.
                    */}
                    <input
                        ref={input}
                        type="file"
                        accept="image/*"
                        aria-label={t(label)}
                        disabled={busy}
                        onChange={(e) => pick(e.target.files?.[0])}
                        className="sr-only"
                    />

                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                        <div className="flex min-w-0 items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={busy}
                                onClick={() => input.current?.click()}
                            >
                                <Upload />
                                {t(value ? 'استبدل الصورة' : 'اختر صورة')}
                            </Button>
                            <span className="min-w-0 truncate text-[12px] text-[#9ca3af]">
                                {t(value ? 'صورةٌ مرفوعة' : 'لم تُختر صورة بعد')}
                            </span>
                        </div>
                        {value && meta && (
                            <span className="text-[11px] text-[#6b7280]" data-testid={`${id}-meta`}>
                                <bdi dir="ltr">{meta.w} × {meta.h}</bdi> · {t(shapes[shapeOf(meta.w, meta.h)])}
                            </span>
                        )}
                    </div>

                    {busy && <Loader2 className="size-4 shrink-0 animate-spin text-[#6b7280]" />}

                    {/* والحذفُ يُفرّغ الحقل ولا يمسّ الملفّ — نسخةٌ على القرص أرخصُ من صورةٍ ضاعت */}
                    {value && ! busy && (
                        <button
                            type="button"
                            aria-label={`${t('أزل')} ${t(label)}`}
                            onClick={() => onChange('')}
                            className="shrink-0 rounded-md p-1.5 text-[#6b7280] hover:bg-[#f3f4f6]"
                        >
                            <X className="size-4" />
                        </button>
                    )}
                </div>

                {/* وطريقةُ العرض زرّان لا قائمة — والمختارُ يُرى في الإطار إلى جانبه فورًا */}
                {value && onFitChange && (
                    <div role="radiogroup" aria-label={t('طريقة عرض الصورة')} className="flex gap-2" data-testid={`${id}-fit`}>
                        {([
                            ['contain', 'الصورة كاملة', 'بلا قصّ — بنسبتها'],
                            ['cover', 'ملء الإطار', 'تُقصّ أطرافها'],
                        ] as const).map(([key, text, sub]) => (
                            <button
                                key={key}
                                type="button"
                                role="radio"
                                aria-checked={shown === key}
                                data-testid={`${id}-fit-${key}`}
                                onClick={() => onFitChange(key)}
                                className={cn(
                                    'min-h-10 flex-1 rounded-[10px] border px-3 py-1.5 text-start text-[12px]',
                                    shown === key ? 'border-[#111] bg-[#111] text-white' : 'border-[var(--ui-border,#e8e8e8)] bg-white text-[#111]',
                                )}
                            >
                                <span className="block font-medium">{t(text)}</span>
                                <span className={cn('block text-[11px]', shown === key ? 'text-white/70' : 'text-[#9ca3af]')}>{t(sub)}</span>
                            </button>
                        ))}
                    </div>
                )}
            </div>
        </Field>
    );
}
