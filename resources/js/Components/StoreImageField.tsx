import { router } from '@inertiajs/react';
import { ImagePlus, Loader2, Upload, X } from 'lucide-react';
import { useRef, useState } from 'react';

import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';

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
 */
export default function StoreImageField({
    label,
    hint,
    value,
    onChange,
}: {
    label: string;
    hint?: string;
    value: string;
    onChange: (url: string) => void;
}) {
    const t = useTranslate();
    const input = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const pick = (file: File | undefined) => {
        if (! file) return;

        setBusy(true);
        setError(null);

        router.post(
            route('admin.marketing.store.image'),
            { image: file },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    const url = (page.props as unknown as { flash?: { uploaded?: string | null } }).flash?.uploaded;
                    if (url) onChange(url);
                },
                onError: (errors) => setError(Object.values(errors)[0] ?? t('تعذّر رفع الصورة')),
                onFinish: () => {
                    setBusy(false);
                    if (input.current) input.current.value = '';
                },
            },
        );
    };

    return (
        <Field label={label} hint={hint} error={error ?? undefined}>
            <div className="flex items-center gap-3">
                {value ? (
                    <img src={value} alt="" className="size-16 shrink-0 rounded-[10px] border border-[var(--ui-border,#e8e8e8)] object-cover" />
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

                <div className="flex min-w-0 flex-1 items-center gap-2">
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
        </Field>
    );
}
