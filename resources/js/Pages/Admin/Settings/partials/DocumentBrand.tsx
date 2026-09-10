import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { ImagePlus, Trash2 } from 'lucide-react';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type Brand = {
    primary: string;
    accent: string;
    logo: string | null;
    cover: string | null;
};

/**
 * هويّةُ الأوراق — الشعارُ والغلافُ واللون.
 *
 * ═══ وما لا يُعرض هنا مقصودٌ غيابُه ═══
 *
 * لا مسافاتٍ ولا خطوطٍ ولا بنيةَ جدولٍ ولا هوامش. التاجرُ يملك الهويّة،
 * وأبعادُ تملك الجودة — ومن يُعطى ثلاثين مقبضًا يُخرج ورقةً لا تشبه شيئًا
 * ويرسلها باسمه، واسمُ أبعاد عليها أيضًا.
 *
 * ولا سحبَ ولا إفلات: صانعُ صفحاتٍ يعني أنّ كلّ متجرٍ يبني تخطيطَه بنفسه،
 * وأنّ ترقيةَ التصميم بعد سنةٍ لا تبلغ أحدًا.
 *
 * ═══ وثلاثةُ أبوابٍ لا واحد ═══
 *
 * الشعارُ يُكتب في عمود `businesses`، والغلافُ في الإعدادات، واللونان
 * كذلك — ولكلٍّ بابُه. وبابٌ واحد يحمل ملفَّين وحقلين بـ`multipart` يجعل
 * الأعلامَ تصل نصوصًا فتنكسر مصادقتُها؛ وهو العطبُ الذي فُصل لأجله بابُ
 * الشعار أصلًا.
 */
export default function DocumentBrand({ brand, onChange }: { brand: Brand; onChange: () => void }) {
    const t = useTranslate();

    const [primary, setPrimary] = useState(brand.primary);
    const [accent, setAccent] = useState(brand.accent);
    const [busy, setBusy] = useState(false);

    const logoInput = useRef<HTMLInputElement>(null);
    const coverInput = useRef<HTMLInputElement>(null);

    /*
     * والحفظُ عند ترك الحقل لا عند كلّ حركةٍ للمنتقي.
     *
     * منتقي الألوان يُطلق `change` عشرات المرّات وأنت تسحب المؤشّر — فطلبٌ
     * لكلٍّ منها يعني مئةَ كتابةٍ في القاعدة لاختيارِ لونٍ واحد.
     */
    const saveBrand = (next: { primary?: string; accent?: string }) => {
        router.post(
            route('admin.settings.documents.brand'),
            { primary, accent, ...next },
            { preserveScroll: true, preserveState: true, onSuccess: onChange },
        );
    };

    const upload = (route_: string, field: string, file: File | null, remove = false) => {
        const data = new FormData();
        if (file) data.append(field, file);
        if (remove) data.append('remove', '1');

        setBusy(true);
        router.post(route_, data, {
            preserveScroll: true,
            onSuccess: onChange,
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Card className="p-5">
            <h3 className="mb-1 font-bold text-[#111]">{t('هوية الأوراق')}</h3>
            <p className="mb-4 text-[12px] text-[#9ca3af]">
                {t('تُطبَّق على كل أوراق متجرك — لا على هذا القالب وحده.')}
            </p>

            {/* ————— الشعار والغلاف ————— */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <ImageSlot
                    label={t('الشعار')}
                    hint={t('يظهر في ترويسة كل ورقة')}
                    src={brand.logo}
                    aspect="aspect-[3/2]"
                    busy={busy}
                    onPick={() => logoInput.current?.click()}
                    onRemove={() => upload(route('admin.settings.logo'), 'logo', null, true)}
                />
                <ImageSlot
                    label={t('صورة الغلاف')}
                    hint={t('شريط أعلى المستند — يُترك فارغًا فتبقى الورقة أبسط')}
                    src={brand.cover}
                    aspect="aspect-[16/6]"
                    busy={busy}
                    onPick={() => coverInput.current?.click()}
                    onRemove={() => upload(route('admin.settings.documents.cover'), 'cover', null, true)}
                />
            </div>

            <input
                ref={logoInput}
                type="file"
                accept="image/*"
                className="hidden"
                onChange={(e) => {
                    const f = e.target.files?.[0];
                    if (f) upload(route('admin.settings.logo'), 'logo', f);
                    e.target.value = '';
                }}
            />
            <input
                ref={coverInput}
                type="file"
                accept="image/*"
                className="hidden"
                onChange={(e) => {
                    const f = e.target.files?.[0];
                    if (f) upload(route('admin.settings.documents.cover'), 'cover', f);
                    e.target.value = '';
                }}
            />

            {/* ————— اللونان ————— */}
            <div className="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="اللون الأساسي" hint="يلوّن العنوان والإجمالي ورأس الجدول">
                    <ColorField
                        value={primary}
                        onChange={setPrimary}
                        onCommit={(v) => saveBrand({ primary: v })}
                    />
                </Field>

                <Field label="لون اللمسة" hint="يُترك فارغًا فيُشتقّ من الأساسي">
                    <ColorField
                        value={accent || primary}
                        onChange={setAccent}
                        onCommit={(v) => saveBrand({ accent: v })}
                        onClear={accent ? () => { setAccent(''); saveBrand({ accent: '' }); } : undefined}
                    />
                </Field>
            </div>
        </Card>
    );
}

/** خانةُ صورةٍ — معاينتُها وزرّاها */
function ImageSlot({
    label, hint, src, aspect, busy, onPick, onRemove,
}: {
    label: string; hint: string; src: string | null; aspect: string;
    busy: boolean; onPick: () => void; onRemove: () => void;
}) {
    const t = useTranslate();

    return (
        <div className="min-w-0">
            <div className="mb-1.5 text-[13px] font-medium text-[#374151]">{label}</div>

            <button
                type="button"
                onClick={onPick}
                disabled={busy}
                className={cn(
                    aspect,
                    'flex w-full items-center justify-center overflow-hidden rounded-[10px]',
                    'border border-dashed border-[#e5e7eb] bg-[#fafafa]',
                    'hover:border-[#d1d5db] disabled:opacity-50',
                )}
            >
                {src ? (
                    <img src={src} alt="" className="size-full object-contain" />
                ) : (
                    <span className="flex flex-col items-center gap-1 text-[#9ca3af]">
                        <ImagePlus className="size-5" />
                        <span className="text-[11px]">{t('اختر صورة')}</span>
                    </span>
                )}
            </button>

            <div className="mt-1.5 flex items-center justify-between gap-2">
                <p className="text-[11px] leading-tight text-[#9ca3af]">{hint}</p>
                {src && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={onRemove}
                        disabled={busy}
                        className="shrink-0 text-[#b91c1c]"
                    >
                        <Trash2 className="size-3.5" />
                    </Button>
                )}
            </div>
        </div>
    );
}

/**
 * منتقي لون — مربّعٌ ونصُّه معًا.
 *
 * والنصُّ مكتوبٌ لا للزينة: التاجرُ الذي يملك دليلَ علامةٍ يلصق `#7C3AED`
 * ولا يبحث عنه بالمؤشّر في مربّعٍ متدرّج.
 */
function ColorField({
    value, onChange, onCommit, onClear,
}: {
    value: string;
    onChange: (v: string) => void;
    onCommit: (v: string) => void;
    onClear?: () => void;
}) {
    const t = useTranslate();

    return (
        <div className="flex items-center gap-2">
            <input
                type="color"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                onBlur={(e) => onCommit(e.target.value)}
                className="size-9 shrink-0 cursor-pointer rounded-[8px] border border-[#e5e7eb] bg-white p-0.5"
            />
            {/*
                والحقلُ من `Input` لا `<input>` عاريًا.

                هو الذي يركّب حارسَ الأرقام (`useAsciiDigits`): من يكتب
                `#٧c٣aed` بلوحةٍ عربيّة يخرج له لونٌ لا يُقرأ — والحقلُ
                يردّه إلى `#7c3aed` قبل أن يصل الخادم.
            */}
            <Input
                type="text"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                onBlur={(e) => onCommit(e.target.value)}
                dir="ltr"
                spellCheck={false}
                className="font-mono text-[12px]"
            />
            {onClear && (
                <Button type="button" variant="ghost" size="sm" onClick={onClear} className="shrink-0 text-[11px]">
                    {t('اشتقاق')}
                </Button>
            )}
        </div>
    );
}
