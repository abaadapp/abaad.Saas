import { usePage } from '@inertiajs/react';
import { ImagePlus, Loader2, Upload, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { csrfHeaders } from '@/lib/csrf';
import { useTranslate } from '@/lib/i18n';
import { uploads } from '@/lib/uploads';
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
 *
 * ═══ وما يعود من الرفع يُكتب في آخر ما في الشاشة — لا في نسختها يومَ بدأ ═══
 *
 * الرفعُ يعود بعد ثوانٍ، وكان يكتب الرابطَ بدالّة الرسم الذي بدأ فيه: صفحةٌ
 * مخصّصةٌ تبني الصفحاتِ كلَّها من نسختها يومئذ، فيمحو ما كُتب أثناء الرفع. ثمّ
 * تُكتب «الصورة كاملة» بالنسخة نفسِها التي لا صورةَ فيها — فتُمحى الصورةُ في
 * اللحظة التي وصلت فيها، وتصير الوحدةُ فارغةً تُطرح عند الحفظ (حادثة RIBBON،
 * ٢٦ أكتوبر: الصورةُ على القرص ولا تذكرها صفحة). فالرابطُ يُكتب بآخر دالّةٍ رُسمت
 * (`latest`)، والطريقةُ بعد أن تصل الصورةُ قيمتَها (`fitAfter`).
 *
 * ═══ والرفعُ طلبٌ وحده لا زيارةُ Inertia ═══
 *
 * زيارةُ Inertia تقطع ما قبلها: رفعٌ يقطع حفظًا جاريًا، وحفظٌ يقطع رفعًا فيُكتب
 * الملفُّ على القرص ولا يعود رابطُه. فالرفعُ `fetch` بـJSON، وكلُّ فشلٍ يُقال
 * بسببه (الحجم، الجلسة، الاتّصال) ويبقى الحقلُ يُعاد منه. ويُعدّ ما يُرفع
 * (`uploads`) فينتظره الحفظ.
 */

/** ما يُقال عن رفعٍ لم يتمّ — بسببه، لا «حدث خطأ» */
export function uploadFailure(status: number, body: unknown, mb: string, t: (s: string, v?: Record<string, string>) => string): string {
    const errors = (body as { errors?: Record<string, string[] | string> } | null)?.errors;
    const first = errors ? Object.values(errors)[0] : undefined;
    const said = Array.isArray(first) ? first[0] : first;

    if (status === 422 && said) return said;
    if (status === 413) return t('الصورة أكبر ممّا يقبله الخادم (:mb ميغابايت) — صغّرها ثمّ ارفعها.', { mb });
    if (status === 419 || status === 401) return t('انتهت جلستك — حدّث الصفحة وادخل من جديد ثمّ ارفع الصورة. تعديلاتك محفوظة على هذا الجهاز.');
    if (status === 403) return t('لا تملك صلاحيّة رفع الصور هنا.');

    return t('تعذّر رفع الصورة — أعد المحاولة.');
}

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
 *
 * و`side` لمن يحفظ بضلعٍ أقصر — صورُ المنتج تُحفظ بـ١٦٠٠ (`ProductImages::MAX_SIDE`).
 */
export async function shrink(file: File, side = MAX_SIDE): Promise<File> {
    if (typeof createImageBitmap !== 'function' || typeof document === 'undefined') return file;

    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const size = scaled(bitmap.width, bitmap.height, side);
        const canvas = document.createElement('canvas');
        canvas.width = size.w;
        canvas.height = size.h;
        const ctx = canvas.getContext('2d');

        if (! ctx) return file;

        ctx.drawImage(bitmap, 0, 0, size.w, size.h);
        bitmap.close?.();

        const type = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
        const blob = await new Promise<Blob | null>((done) => canvas.toBlob(done, type, 0.88));
        // ويُفرَغ الإطارُ في الحال: صورةُ هاتفٍ بعد أخرى تملأ ذاكرةَ Safari وChrome على iPhone فتُغلق الصفحة
        canvas.width = 0;
        canvas.height = 0;

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

    // آخرُ ما رُسم — فما يعود من الرفع يُكتب في الشاشة كما هي الآن
    const latest = useRef({ onChange, onFitChange });
    latest.current = { onChange, onFitChange };
    const mounted = useRef(true);
    useEffect(() => {
        mounted.current = true;

        return () => {
            mounted.current = false;
        };
    }, []);

    // وكلُّ صورةٍ جديدةٍ تبدأ «كاملة» — تُكتب بعد أن تصل الصورةُ قيمتَها، لا من النسخة التي لا صورةَ فيها
    const [fitAfter, setFitAfter] = useState<string | null>(null);
    useEffect(() => {
        if (fitAfter !== null && value === fitAfter) {
            setFitAfter(null);
            latest.current.onFitChange?.('contain');
        }
    }, [value, fitAfter]);

    const send = async (file: File) => {
        const done = () => {
            setBusy(false);
            if (input.current) input.current.value = '';
        };

        if (file.size > max) {
            done();
            setError(t('الصورة أكبر من :mb ميغابايت — صغّرها ثمّ ارفعها.', { mb }));

            return;
        }

        const finished = uploads.begin();
        try {
            const body = new FormData();
            body.append('image', file);
            const res = await fetch(route('admin.marketing.store.image'), {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...csrfHeaders() },
            });
            const data = (await res.json().catch(() => null)) as { url?: string; meta?: { w: number; h: number } | null } | null;

            // حقلٌ أُزيل أثناء الرفع لا يُكتب فيه — ولا في وحدةٍ حلّت محلَّه
            if (! mounted.current) return;

            if (res.ok && data?.url) {
                latest.current.onChange(data.url);
                setMeta(data.meta ?? null);
                if (latest.current.onFitChange) setFitAfter(data.url);

                return;
            }

            setError(uploadFailure(res.status, data, mb, t));
        } catch {
            if (mounted.current) setError(t('تعذّر الاتصال — تحقّق من الإنترنت ثمّ أعد المحاولة. لم يضع شيءٌ ممّا كتبت.'));
        } finally {
            finished();
            if (mounted.current) done();
        }
    };

    const pick = (file: File | undefined) => {
        if (! file) return;

        setBusy(true);
        setError(null);

        // وما يسعه الحدُّ يُرسَل في الحال كما هو — والكبيرُ يُصغَّر أوّلًا
        if (needsShrink(file, max)) {
            void shrink(file).then(send);
        } else {
            void send(file);
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
