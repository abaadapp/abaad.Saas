import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { ChevronDown, Plus, Search, Settings2, Trash2, Upload, X } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import Toggle from '@/Components/Toggle';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface FieldSpec {
    key: string;
    label: string;
    type: string;
    hint: string | null;
    advanced: boolean;
    options: { value: string; label: string }[] | null;
    min: number | null;
    max: number | null;
    item: Omit<FieldSpec, 'options' | 'min' | 'item'>[] | null;
}

export interface PickerProduct {
    id: number;
    name: string;
    price: number;
    image: string | null;
}

interface Props {
    schema: FieldSpec[];
    data: Record<string, unknown>;
    onChange: (data: Record<string, unknown>) => void;
    networks: { value: string; label: string }[];
    products: PickerProduct[];
}

/**
 * نموذج القسم — يُرسَم من وصفه لا من كودٍ لكلّ نوع.
 *
 * مكتبةُ الأقسام في الخادم تصف حقولَ كلّ قسم (انظر `Sections::schema`)، وهذا
 * الملفّ يرسم أيّ وصفٍ يصله. فقسمٌ جديد لا يحتاج شاشةً جديدة هنا — يحتاج
 * صفًّا في المكتبة.
 *
 * وما لا يحتاجه أحدٌ في اليوم الأوّل مطويٌّ تحت «إعدادات متقدّمة»: المحاذاةُ
 * والارتفاع وعددُ الأعمدة. وعرضُها مع العنوان والصورة يجعل نموذجًا من ثلاثة
 * حقول يُقرأ كاستمارة، فيغلقه من فتحه.
 */
export default function SectionForm({ schema, data, onChange, networks, products }: Props) {
    const t = useTranslate();
    const [advanced, setAdvanced] = useState(false);

    const set = (key: string, value: unknown) => onChange({ ...data, [key]: value });

    const basic = schema.filter((f) => !f.advanced);
    const extra = schema.filter((f) => f.advanced);

    return (
        <div className="space-y-4">
            {basic.map((f) => (
                <Row key={f.key} spec={f} value={data[f.key]} set={set} networks={networks} products={products} />
            ))}

            {extra.length > 0 && (
                <div className="border-t border-[var(--ui-border,#e8e8e8)] pt-3">
                    <button
                        type="button"
                        onClick={() => setAdvanced((v) => !v)}
                        className="flex w-full items-center gap-2 text-[13px] font-medium text-[#6b7280] hover:text-[#111]"
                    >
                        <Settings2 className="size-4" />
                        {t('إعدادات متقدّمة')}
                        <ChevronDown className={cn('ms-auto size-4 transition-transform', advanced && 'rotate-180')} />
                    </button>

                    {advanced && (
                        <div className="mt-4 space-y-4">
                            {extra.map((f) => (
                                <Row
                                    key={f.key}
                                    spec={f}
                                    value={data[f.key]}
                                    set={set}
                                    networks={networks}
                                    products={products}
                                />
                            ))}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

/* ============================ حقلٌ واحد ============================ */

function Row({
    spec,
    value,
    set,
    networks,
    products,
}: {
    spec: FieldSpec;
    value: unknown;
    set: (key: string, value: unknown) => void;
    networks: { value: string; label: string }[];
    products: PickerProduct[];
}) {
    switch (spec.type) {
        case 'toggle':
            return (
                <Toggle
                    on={value === true}
                    label={spec.label}
                    hint={spec.hint ?? undefined}
                    onChange={(v) => set(spec.key, v)}
                />
            );

        case 'textarea':
            return (
                <Field label={spec.label} hint={spec.hint ?? undefined}>
                    <textarea
                        rows={4}
                        maxLength={spec.max ?? undefined}
                        value={String(value ?? '')}
                        onChange={(e) => set(spec.key, e.target.value)}
                        className="w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2.5 text-sm leading-7 outline-none transition-colors focus:border-[#111]"
                    />
                </Field>
            );

        case 'select':
            return (
                <Field label={spec.label} hint={spec.hint ?? undefined}>
                    <Select
                        value={String(value ?? '')}
                        onChange={(e) => set(spec.key, e.target.value)}
                        options={spec.options ?? []}
                    />
                </Field>
            );

        case 'number':
            return (
                <Field label={spec.label} hint={spec.hint ?? undefined}>
                    <Input
                        type="number"
                        dir="ltr"
                        min={spec.min ?? undefined}
                        max={spec.max ?? undefined}
                        value={String(value ?? '')}
                        onChange={(e) => set(spec.key, e.target.value)}
                    />
                </Field>
            );

        case 'date':
            return (
                <Field label={spec.label} hint={spec.hint ?? undefined}>
                    <Input
                        type="date"
                        dir="ltr"
                        value={String(value ?? '')}
                        onChange={(e) => set(spec.key, e.target.value)}
                    />
                </Field>
            );

        case 'image':
            return <ImageRow spec={spec} value={String(value ?? '')} set={set} />;

        case 'list':
            return <ListRow spec={spec} value={Array.isArray(value) ? value : []} set={set} />;

        case 'social':
            return (
                <SocialRow
                    spec={spec}
                    value={Array.isArray(value) ? (value as { network: string; value: string }[]) : []}
                    set={set}
                    networks={networks}
                />
            );

        case 'products':
            return (
                <ProductsRow
                    spec={spec}
                    value={Array.isArray(value) ? (value as number[]) : []}
                    set={set}
                    products={products}
                />
            );

        default:
            return (
                <Field label={spec.label} hint={spec.hint ?? undefined}>
                    <Input
                        dir={spec.type === 'link' ? 'ltr' : undefined}
                        maxLength={spec.max ?? undefined}
                        value={String(value ?? '')}
                        onChange={(e) => set(spec.key, e.target.value)}
                        placeholder={spec.type === 'link' ? '/shop' : undefined}
                    />
                </Field>
            );
    }
}

/* ============================ الصورة ============================ */

/**
 * الصورة: رفعٌ أو رابط.
 *
 * والرفع يمرّ بالخادم فيردّ رابطًا — فالحقل يحمل رابطًا في الحالين، ولا يعرف
 * قارئُ الموقع من أين جاء.
 */
function ImageRow({
    spec,
    value,
    set,
}: {
    spec: FieldSpec;
    value: string;
    set: (key: string, value: unknown) => void;
}) {
    const t = useTranslate();
    const input = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);

    const upload = (file: File) => {
        setBusy(true);
        router.post(
            route('admin.website.media'),
            { image: file },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    const url = (page.props.flash as { uploaded?: string } | undefined)?.uploaded;
                    if (url) set(spec.key, url);
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <Field label={spec.label} hint={spec.hint ?? undefined}>
            <div className="flex items-start gap-3">
                <span className="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa]">
                    {value ? (
                        <img src={value} alt="" className="size-full object-cover" />
                    ) : (
                        <Upload className="size-4 text-[#d1d5db]" />
                    )}
                </span>

                <div className="min-w-0 flex-1 space-y-2">
                    <Input
                        dir="ltr"
                        value={value}
                        onChange={(e) => set(spec.key, e.target.value)}
                        placeholder="https://…"
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="button" variant="outline" size="sm" loading={busy} onClick={() => input.current?.click()}>
                            <Upload />
                            {t('ارفع صورة')}
                        </Button>
                        {value && (
                            <Button type="button" variant="ghost" size="sm" onClick={() => set(spec.key, '')}>
                                <X />
                                {t('أزل')}
                            </Button>
                        )}
                    </div>
                    <input
                        ref={input}
                        type="file"
                        accept="image/*"
                        className="hidden"
                        onChange={(e) => {
                            const file = e.target.files?.[0];
                            if (file) upload(file);
                            e.target.value = '';
                        }}
                    />
                </div>
            </div>
        </Field>
    );
}

/* ============================ القوائم ============================ */

function ListRow({
    spec,
    value,
    set,
}: {
    spec: FieldSpec;
    value: unknown[];
    set: (key: string, value: unknown) => void;
}) {
    const t = useTranslate();
    const item = spec.item ?? [];

    const blank = () => Object.fromEntries(item.map((f) => [f.key, f.type === 'toggle' ? false : '']));

    const patch = (i: number, key: string, v: unknown) =>
        set(
            spec.key,
            value.map((row, j) => (j === i ? { ...(row as object), [key]: v } : row)),
        );

    const move = (i: number, delta: number) => {
        const next = [...value];
        const target = i + delta;
        if (target < 0 || target >= next.length) return;
        [next[i], next[target]] = [next[target], next[i]];
        set(spec.key, next);
    };

    return (
        <div className="space-y-2">
            <p className="text-sm font-medium text-[#111]">{spec.label}</p>
            {spec.hint && <p className="text-[12px] text-[#9ca3af]">{spec.hint}</p>}

            {value.map((row, i) => (
                <div key={i} className="space-y-3 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-3">
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-[12px] text-[#9ca3af]">
                            {t('عنصر')} {i + 1}
                        </span>
                        <div className="flex items-center gap-1">
                            <Button type="button" variant="ghost" size="icon" aria-label={t('لأعلى')} onClick={() => move(i, -1)}>
                                <ChevronDown className="rotate-180" />
                            </Button>
                            <Button type="button" variant="ghost" size="icon" aria-label={t('لأسفل')} onClick={() => move(i, 1)}>
                                <ChevronDown />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="text-[#b91c1c]"
                                aria-label={t('حذف')}
                                onClick={() => set(spec.key, value.filter((_, j) => j !== i))}
                            >
                                <Trash2 />
                            </Button>
                        </div>
                    </div>

                    {item.map((f) => (
                        <Row
                            key={f.key}
                            spec={{ ...f, options: null, min: null, item: null } as FieldSpec}
                            value={(row as Record<string, unknown>)[f.key]}
                            set={(k, v) => patch(i, k, v)}
                            networks={[]}
                            products={[]}
                        />
                    ))}
                </div>
            ))}

            <Button type="button" variant="outline" size="sm" onClick={() => set(spec.key, [...value, blank()])}>
                <Plus />
                {t('أضف')}
            </Button>
        </div>
    );
}

/* ======================= حسابات التواصل ======================= */

/**
 * حسابٌ يُضاف لا ستّ خاناتٍ فارغة.
 *
 * عرضُ كلّ الشبكات دائمًا يجعل التاجر يمرّ على ستّة حقول ليملأ واحدًا، ويترك
 * خمسةً فارغة تُرسل مع كلّ حفظ. فيختار ما يملك ويضيفه.
 */
function SocialRow({
    spec,
    value,
    set,
    networks,
}: {
    spec: FieldSpec;
    value: { network: string; value: string }[];
    set: (key: string, value: unknown) => void;
    networks: { value: string; label: string }[];
}) {
    const t = useTranslate();
    const used = value.map((a) => a.network);
    const free = networks.filter((n) => !used.includes(n.value));

    return (
        <div className="space-y-2">
            <p className="text-sm font-medium text-[#111]">{spec.label}</p>
            {spec.hint && <p className="text-[12px] text-[#9ca3af]">{spec.hint}</p>}

            {value.map((a, i) => (
                <div key={a.network} className="flex items-center gap-2">
                    <Badge variant="neutral">
                        {networks.find((n) => n.value === a.network)?.label ?? a.network}
                    </Badge>
                    <Input
                        dir="ltr"
                        value={a.value}
                        placeholder="username"
                        onChange={(e) =>
                            set(
                                spec.key,
                                value.map((row, j) => (j === i ? { ...row, value: e.target.value } : row)),
                            )
                        }
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="text-[#b91c1c]"
                        aria-label={t('حذف')}
                        onClick={() => set(spec.key, value.filter((_, j) => j !== i))}
                    >
                        <Trash2 />
                    </Button>
                </div>
            ))}

            {free.length > 0 && (
                <Select
                    placeholder={t('+ أضف حساب تواصل')}
                    value=""
                    options={free}
                    onChange={(e) =>
                        e.target.value && set(spec.key, [...value, { network: e.target.value, value: '' }])
                    }
                />
            )}
        </div>
    );
}

/* ========================= منتقي المنتجات ========================= */

function ProductsRow({
    spec,
    value,
    set,
    products,
}: {
    spec: FieldSpec;
    value: number[];
    set: (key: string, value: unknown) => void;
    products: PickerProduct[];
}) {
    const t = useTranslate();
    const [q, setQ] = useState('');

    const chosen = value.map((id) => products.find((p) => p.id === id)).filter(Boolean) as PickerProduct[];
    const found = q.trim()
        ? products.filter((p) => !value.includes(p.id) && p.name.includes(q.trim())).slice(0, 8)
        : [];

    return (
        <div className="space-y-2">
            <p className="text-sm font-medium text-[#111]">{spec.label}</p>
            {spec.hint && <p className="text-[12px] text-[#9ca3af]">{spec.hint}</p>}

            {chosen.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {chosen.map((p) => (
                        <span
                            key={p.id}
                            className="inline-flex items-center gap-2 rounded-full border border-[var(--ui-border,#e8e8e8)] py-1 pe-2 ps-3 text-[13px]"
                        >
                            {p.name}
                            <button
                                type="button"
                                aria-label={t('أزل')}
                                onClick={() => set(spec.key, value.filter((id) => id !== p.id))}
                                className="text-[#9ca3af] hover:text-[#b91c1c]"
                            >
                                <X className="size-3.5" />
                            </button>
                        </span>
                    ))}
                </div>
            )}

            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 size-4 -translate-y-1/2 text-[#9ca3af] end-3" />
                <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('ابحث عن منتج…')} />
            </div>

            {found.map((p) => (
                <button
                    key={p.id}
                    type="button"
                    onClick={() => {
                        set(spec.key, [...value, p.id]);
                        setQ('');
                    }}
                    className="flex w-full items-center gap-3 rounded-[10px] px-3 py-2 text-start text-[13px] hover:bg-[#fafafa]"
                >
                    <span className="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-md bg-[#f5f5f5]">
                        {p.image && <img src={p.image} alt="" className="size-full object-cover" />}
                    </span>
                    <span className="min-w-0 flex-1 truncate">{p.name}</span>
                    <Plus className="size-4 shrink-0 text-[#9ca3af]" />
                </button>
            ))}
        </div>
    );
}
